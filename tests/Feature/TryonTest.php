<?php

namespace Tests\Feature;

use App\Exceptions\TryonException;
use App\Jobs\RunTryon;
use App\Models\ClothesItem;
use App\Models\ClothesOutfit;
use App\Models\TryonGarment;
use App\Models\TryonTask;
use App\Models\User;
use App\Services\ImageStorage;
use App\Services\Tryon\TryonProvider;
use App\Services\Tryon\TryonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AI 试穿（2026-09 · P2）
 *
 * 被测：App\Services\Tryon\TryonService + TryonController + RunTryon 任务
 *
 * 这一层要保证的是**钱和体验**：缓存能不能挡住重复调用（省钱）、失败能不能如实报（体验）、
 * 闸门（开关/会员/品类/照片/次数）有没有拦住不该发生的调用。
 * 供应商与存储都换成假实现 —— 测试不碰网络、不碰 OSS，也不花钱。
 *
 * 覆盖：提交建任务并进队列、命中缓存秒回、归一化图复用、失败落 failed + 抛给队列重试、
 *       五个闸门、三个接口的出参与权限（只能查自己的任务）。
 */
class TryonTest extends TestCase
{
    use RefreshDatabase;

    private FakeTryonProvider $provider;
    private FakeImageStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // 队列一律拦下来：测试环境默认是 sync 驱动，不拦的话 request() 会当场把任务跑完，
        // 断言就不确定了。需要"跑一遍"的用例显式调 service()->run()。
        Queue::fake();

        // 结果图是"从远程下载再转存我们存储"的，这一步也得拦下来（假供应商给的地址是假的）
        Http::fake(['*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        // 功能全开、别开会员门（会员门单独一个用例），每天 3 次方便测限额
        config([
            'tryon.enabled'      => true,
            'tryon.api_key'      => 'test-key',
            'tryon.model_image'  => 'https://cdn.example.com/tryon/model.jpg',
            'tryon.model_key'    => 'model-a',
            'tryon.member_only'  => false,
            'tryon.daily_limit'  => 3,
        ]);

        $this->provider = new FakeTryonProvider();
        $this->storage  = new FakeImageStorage();
        $this->app->instance(TryonProvider::class, $this->provider);
        $this->app->instance(ImageStorage::class, $this->storage);
    }

    /** 造一件带照片的上装 */
    private function makeItem(User $u, string $cid = 'i1', string $category = 'top', string $imageUrl = 'https://cdn.example.com/clothes/1.jpg'): ClothesItem
    {
        return ClothesItem::create([
            'user_id'           => $u->id,
            'client_id'         => $cid,
            'name'              => '白T',
            'category'          => $category,
            'sub'               => '',
            'colors'            => [],
            'seasons'           => [],
            'occasions'         => [],
            'image_url'         => $imageUrl,
            'client_created_at' => 1758000000000,
        ]);
    }

    private function service(): TryonService
    {
        return $this->app->make(TryonService::class);
    }

    /**
     * 场景：提交一次试穿
     * 预期：建一条 pending 任务、把任务投进队列（页面拿 task_id 轮询），当次不调用供应商
     */
    public function test_submit_creates_task_and_queues_job(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $this->makeItem($u);

        $r = $this->service()->request($u, ClothesItem::first());

        $this->assertFalse($r['cached']);
        $this->assertSame(TryonTask::STATUS_PENDING, $r['task']->status);
        $this->assertSame('top', $r['task']->slot);
        $this->assertSame(0, $this->provider->tryOnCalls, '提交时不该真的调用供应商');
        Queue::assertPushed(RunTryon::class);
    }

    /**
     * 场景：队列里跑完一次生成
     * 预期：归一化 + 试穿各调一次，结果与归一化图都存进我们 OSS，任务变 done
     */
    public function test_run_normalizes_then_stores_result(): void
    {
        $u = User::factory()->create();
        $item = $this->makeItem($u);
        $task = $this->service()->request($u, $item)['task'];

        $this->service()->run($task->id);

        $task->refresh();
        $this->assertSame(TryonTask::STATUS_DONE, $task->status);
        $this->assertSame(1, $this->provider->normalizeCalls);
        $this->assertSame(1, $this->provider->tryOnCalls);
        $this->assertStringContainsString('https://cdn.example.com/oss/', $task->result_url, '结果要转存我们自己的存储');
        $this->assertStringContainsString('https://cdn.example.com/oss/', $task->normalized_url, '白底图也要存下来复用');
        $this->assertGreaterThan(0, $task->cost_ms);
    }

    /**
     * 场景：同一件衣服第二次试穿（第二次进来的人 / 用户再点一次）
     * 预期：命中缓存直接拿到上次的结果，供应商一次都不再调（这是省钱的关键）
     */
    public function test_second_request_hits_cache_without_calling_provider(): void
    {
        $u = User::factory()->create();
        $item = $this->makeItem($u);
        $first = $this->service()->request($u, $item)['task'];
        $this->service()->run($first->id);

        $second = $this->service()->request($u, $item);

        $this->assertTrue($second['cached']);
        $this->assertSame($first->refresh()->result_url, $second['task']->result_url);
        $this->assertSame(1, $this->provider->tryOnCalls, '命中缓存后不该再调用供应商');
        $this->assertSame(1, TryonTask::count(), '命中缓存不该新建任务');
    }

    /**
     * 场景：用户换了这件衣服的照片（地址变了）
     * 预期：缓存失效（source_hash 变了）→ 重新生成，不拿旧结果糊弄
     */
    public function test_changed_photo_invalidates_cache(): void
    {
        $u = User::factory()->create();
        $item = $this->makeItem($u);
        $first = $this->service()->request($u, $item)['task'];
        $this->service()->run($first->id);

        $item->update(['image_url' => 'https://cdn.example.com/clothes/2.jpg']);   // 换了照片
        $again = $this->service()->request($u, $item->refresh());

        $this->assertFalse($again['cached']);
        $this->assertSame(2, TryonTask::count());
    }

    /**
     * 场景：同一件衣服的第二次生成（缓存已失效但白底图还在）
     * 预期：归一化不重复洗 —— 复用已有 normalized_url（那一步最慢也最贵）
     */
    public function test_normalized_image_is_reused(): void
    {
        $u = User::factory()->create();
        $item = $this->makeItem($u);
        $first = $this->service()->request($u, $item)['task'];
        $this->service()->run($first->id);

        // 手工再建一条同源任务（模拟"结果缓存被清掉、但白底图还在"）
        $again = TryonTask::create([
            'user_id' => $u->id, 'item_id' => $item->client_id, 'model_key' => 'model-a',
            'source_url' => $item->image_url, 'source_hash' => md5($item->image_url),
            'slot' => 'top', 'garments' => ['top' => $item->client_id],
            'status' => TryonTask::STATUS_PENDING,
        ]);
        $this->service()->run($again->id);

        $this->assertSame(1, $this->provider->normalizeCalls, '白底图应该复用，不该再洗一次');
        $this->assertSame(2, $this->provider->tryOnCalls);
    }

    /**
     * 场景：供应商失败（限流/识别不出来）
     * 预期：任务落 failed 且记下技术原因；异常继续抛出去，让队列按 tries/backoff 重试
     */
    public function test_provider_failure_marks_failed_and_rethrows(): void
    {
        $u = User::factory()->create();
        $item = $this->makeItem($u);
        $task = $this->service()->request($u, $item)['task'];

        $this->provider->failWith = new TryonException(4008, '试穿调用失败：Throttling.RateQuota 限流');

        $thrown = false;
        try {
            $this->service()->run($task->id);
        } catch (TryonException $e) {
            $thrown = true;
        }

        $task->refresh();
        $this->assertTrue($thrown, '要抛给队列，才能触发重试');
        $this->assertSame(TryonTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('Throttling', $task->error);
        $this->assertStringContainsString('排队', $task->friendlyError(), '给用户看的应该是人话');
    }

    /**
     * 场景：已经出图的任务被重复投递（队列重试/用户连点）
     * 预期：直接返回，不重复花钱
     */
    public function test_done_task_is_not_regenerated(): void
    {
        $u = User::factory()->create();
        $task = $this->service()->request($u, $this->makeItem($u))['task'];
        $this->service()->run($task->id);

        $this->service()->run($task->id);   // 再来一次

        $this->assertSame(1, $this->provider->tryOnCalls, '已经出图的任务不该再调供应商');
    }

    /**
     * 场景：五个闸门
     * 预期：功能没开 4007 / 非会员 4003 / 品类不支持 4005（鞋配饰没有槽位）/ 没照片 4004 / 次数用完 4006
     */
    public function test_gates(): void
    {
        $u = User::factory()->create();
        $item = $this->makeItem($u);
        $svc  = $this->service();

        config(['tryon.enabled' => false]);
        $this->assertSame(4007, $this->codeOf(function () use ($svc, $u, $item) { $svc->request($u, $item); }));
        config(['tryon.enabled' => true]);

        config(['tryon.member_only' => true]);
        $this->assertSame(4003, $this->codeOf(function () use ($svc, $u, $item) { $svc->request($u, $item); }));
        config(['tryon.member_only' => false]);

        $shoes = $this->makeItem($u, 'i-shoes', 'shoes');
        $this->assertSame(4005, $this->codeOf(function () use ($svc, $u, $shoes) { $svc->request($u, $shoes); }));

        $noPhoto = $this->makeItem($u, 'i-nopic', 'top', '');
        $this->assertSame(4004, $this->codeOf(function () use ($svc, $u, $noPhoto) { $svc->request($u, $noPhoto); }));

        // 今天已经用满 3 次
        foreach (range(1, 3) as $i) {
            $this->service()->request($u, $this->makeItem($u, 'i-x' . $i));
        }
        $this->assertSame(4006, $this->codeOf(function () use ($svc, $u, $item) { $svc->request($u, $item); }));
    }

    /**
     * 场景：三个接口
     * 预期：quota 出参齐全；提交返回 task；轮询只认自己的任务（别人的查不到）
     */
    public function test_api_endpoints(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $item = $this->makeItem($u);
        Sanctum::actingAs($u);

        $this->getJson('/api/tryon/quota')->assertOk()->assertJson([
            'code' => 0,
            'data' => ['enabled' => true, 'memberOnly' => false, 'isMember' => false, 'leftToday' => 3, 'dailyLimit' => 3],
        ]);

        $res = $this->postJson('/api/tryon', ['itemId' => $item->client_id]);
        $res->assertOk()->assertJson(['code' => 0, 'data' => ['cached' => false]]);
        $taskId = $res->json('data.task.id');
        $this->assertNotEmpty($taskId);

        $this->getJson('/api/tryon/' . $taskId)->assertOk()->assertJson(['code' => 0, 'data' => ['task' => ['status' => 'pending']]]);

        // 换个人来查：查不到（任务属于原主）
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/tryon/' . $taskId)->assertOk()->assertJson(['code' => 4004]);
    }

    /**
     * 场景：没开会员门时，接口里那个"提交"也会正常走通（回归用）
     * 预期：提交成功、次数从 3 掉到 2
     */
    public function test_left_today_counts_submissions(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        Sanctum::actingAs($u);
        $this->makeItem($u, 'i9');

        $this->postJson('/api/tryon', ['itemId' => 'i9'])->assertOk();
        $this->assertSame(2, $this->getJson('/api/tryon/quota')->json('data.leftToday'));
    }

    /** 造一套搭配（item_ids 的顺序就是用户在页面上看到的顺序） */
    private function makeOutfit(User $u, string $cid, array $itemIds): ClothesOutfit
    {
        return ClothesOutfit::create([
            'user_id' => $u->id, 'client_id' => $cid, 'name' => '通勤', 'name_auto' => false,
            'occasions' => [], 'item_ids' => $itemIds, 'slots' => [], 'cover_url' => '',
            'client_created_at' => 1758000000000,
        ]);
    }

    /**
     * 场景：整套试穿（分享方案的一1 二1：按搭配顺序自动取第一件上装 + 第一件下装）
     * 预期：上装=搭配里第一个上装（不是衣橱里第一件）、下装同理；鞋被跳过；一次调用两件都给
     */
    public function test_outfit_picks_first_top_and_bottom_in_outfit_order(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $this->makeItem($u, 't1', 'top', 'https://cdn.example.com/clothes/t1.jpg');
        $this->makeItem($u, 't2', 'top', 'https://cdn.example.com/clothes/t2.jpg');
        $this->makeItem($u, 'b1', 'bottom', 'https://cdn.example.com/clothes/b1.jpg');
        $this->makeItem($u, 's1', 'shoes', 'https://cdn.example.com/clothes/s1.jpg');

        // 顺序：鞋 → 第二件上衣 → 裤子 → 第一件上衣
        $outfit = $this->makeOutfit($u, 'o1', ['s1', 't2', 'b1', 't1']);
        $task = $this->service()->request($u, null, $outfit)['task'];

        $this->assertSame(['top' => 't2', 'bottom' => 'b1'], $task->garments, '按搭配里的顺序取第一件上装/下装，鞋不参与');
        $this->assertSame('both', $task->slot);

        $this->service()->run($task->id);
        $this->assertSame(2, $this->provider->normalizeCalls, '两件衣服各洗一次');
        // 洗的必须是 t2/b1 这两张（不是 t1，也不是被跳过的鞋）
        $this->assertStringContainsString('t2.jpg', implode(' ', $this->provider->normalizedInputs));
        $this->assertStringContainsString('b1.jpg', implode(' ', $this->provider->normalizedInputs));
        $this->assertStringNotContainsString('t1.jpg', implode(' ', $this->provider->normalizedInputs));
        $this->assertStringNotContainsString('s1.jpg', implode(' ', $this->provider->normalizedInputs));
        $this->assertNotEmpty($this->provider->lastTryOn['top']);
        $this->assertNotEmpty($this->provider->lastTryOn['bottom']);
    }

    /**
     * 场景：同一套搭配第二次试穿
     * 预期：整个结果复用（缓存键是"这套 + 这个模特 + 这两张图"），供应商不再被调用
     */
    public function test_outfit_result_is_cached(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $this->makeItem($u, 't1', 'top');
        $this->makeItem($u, 'b1', 'bottom');
        $outfit = $this->makeOutfit($u, 'o1', ['t1', 'b1']);

        $first = $this->service()->request($u, null, $outfit)['task'];
        $this->service()->run($first->id);

        $second = $this->service()->request($u, null, $outfit);

        $this->assertTrue($second['cached']);
        $this->assertSame($first->refresh()->result_url, $second['task']->result_url);
        $this->assertSame(1, $this->provider->tryOnCalls, '整套命中缓存后不该再调用供应商');
        $this->assertSame(1, TryonTask::count());
    }

    /**
     * 场景：两套不同的搭配，用了同一件上装
     * 预期：那件上装的白底图只洗一次（归一化缓存在"衣物"上，不在"搭配"上）
     */
    public function test_garment_normalization_shared_across_outfits(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $this->makeItem($u, 't1', 'top');
        $this->makeItem($u, 'b1', 'bottom');
        $this->makeItem($u, 'b2', 'bottom');

        $a = $this->service()->request($u, null, $this->makeOutfit($u, 'o1', ['t1', 'b1']))['task'];
        $this->service()->run($a->id);
        $b = $this->service()->request($u, null, $this->makeOutfit($u, 'o2', ['t1', 'b2']))['task'];
        $this->service()->run($b->id);

        $this->assertSame(3, $this->provider->normalizeCalls, 't1 复用，b1/b2 各洗一次 = 共 3 次');
        $this->assertSame(3, TryonGarment::count(), '衣物表里应有 t1/b1/b2 三行（t1 复用只有一行）');
        $this->assertSame(2, $this->provider->tryOnCalls, '两套搭配各出一次图');
    }

    /**
     * 场景：这套搭配里只有上装（没裤子）
     * 预期：照常出图，只传上装槽位（不硬填一个下装）
     */
    public function test_outfit_with_only_top(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $this->makeItem($u, 't1', 'top');
        $outfit = $this->makeOutfit($u, 'o1', ['t1']);
        $task = $this->service()->request($u, null, $outfit)['task'];

        $this->assertSame('top', $task->slot);
        $this->service()->run($task->id);

        $this->assertNotEmpty($this->provider->lastTryOn['top']);
        $this->assertEmpty($this->provider->lastTryOn['bottom'], '没有下装就不该传下装槽位');
    }

    /**
     * 场景：这套搭配里全是鞋和配饰（没有能试的）
     * 预期：直接挡掉 4005，不建任务、不花钱
     */
    public function test_outfit_without_wearable_items_is_rejected(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $this->makeItem($u, 's1', 'shoes');
        $outfit = $this->makeOutfit($u, 'o1', ['s1']);

        $code = $this->codeOf(function () use ($u, $outfit) {
            $this->service()->request($u, null, $outfit);
        });

        $this->assertSame(4005, $code);
        $this->assertSame(0, TryonTask::count(), '挡掉时不该留下任务');
    }

    /**
     * 场景：整套试穿的接口（前端传 outfitId）
     * 预期：返回任务且带上两件衣物；传了不存在/别人的搭配 → 4004
     */
    public function test_api_accepts_outfit_id(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $this->makeItem($u, 't1', 'top');
        $this->makeItem($u, 'b1', 'bottom');
        $this->makeOutfit($u, 'o1', ['t1', 'b1']);
        Sanctum::actingAs($u);

        $res = $this->postJson('/api/tryon', ['outfitId' => 'o1']);
        $res->assertOk()->assertJson(['code' => 0]);
        $this->assertSame(['top' => 't1', 'bottom' => 'b1'], $res->json('data.task.garments'));

        $this->postJson('/api/tryon', ['outfitId' => 'nope'])->assertOk()->assertJson(['code' => 4004]);
        $this->postJson('/api/tryon', [])->assertOk()->assertJson(['code' => 4004]);
    }

    /** 跑一段会抛业务异常的代码，把业务码取出来 */
    private function codeOf(callable $fn): int
    {
        try {
            $fn();
        } catch (TryonException $e) {
            return $e->apiCode();
        }

        return 0;
    }
}

/** 假供应商：只记调用次数，不碰网络 */
class FakeTryonProvider implements TryonProvider
{
    public int $normalizeCalls = 0;
    public int $tryOnCalls = 0;
    public array $normalizedInputs = [];   // 洗了哪几张原图（断言用）
    public ?TryonException $failWith = null;

    public function normalize(string $imageUrl): string
    {
        $this->normalizeCalls++;
        $this->normalizedInputs[] = $imageUrl;
        $this->boom();

        return 'https://dashscope.example.com/tmp/normalized-' . $this->normalizeCalls . '.png';
    }

    public ?array $lastTryOn = null;

    public function tryOn(string $personUrl, ?string $topUrl, ?string $bottomUrl): string
    {
        $this->tryOnCalls++;
        $this->lastTryOn = ['top' => $topUrl, 'bottom' => $bottomUrl];
        $this->boom();

        return 'https://dashscope.example.com/tmp/result-' . $this->tryOnCalls . '.jpg';
    }

    private function boom(): void
    {
        if (!empty($this->failWith)) {
            throw $this->failWith;
        }
    }
}

/** 假存储：不碰 OSS，返回固定形状的 https 地址 */
class FakeImageStorage extends ImageStorage
{
    public function out(?string $url): string
    {
        return (string) $url;
    }

    public function putBinary(string $contents, string $ext, string $dir): array
    {
        $key = $dir . '/202609/fake.' . $ext;

        return ['path' => $key, 'url' => 'https://cdn.example.com/oss/' . $key, 'driver' => 'oss'];
    }
}
