<?php

namespace Tests\Feature;

use App\Exceptions\TryonException;
use App\Jobs\RunNormalize;
use App\Models\ClothesItem;
use App\Models\TryonGarment;
use App\Models\TryonTask;
use App\Models\User;
use App\Services\ImageStorage;
use App\Services\NormalizeService;
use App\Services\Tryon\TryonProvider;
use App\Services\Tryon\TryonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 洗白底（归一化）· 记录衣物页那个入口（2026-09）
 *
 * 被测：App\Services\NormalizeService + NormalizeController + ClothesController::push/itemOut
 *       里跟「原图 / 白底图」有关的那部分
 *
 * 这一层要守住的是**钱和原图**：
 *   ① 钱了：同一件衣服同一张原图只洗一次（缓存命中不调供应商也不计次）；每天免费次数用完就拦；
 *      供应商失败不扣次数
 *   ② 原图：把白底图设成封面后，原图必须还在（而且**不能**被 OSS 清理逻辑删掉）
 *   ③ 不能连坐：老版本小程序不发这两个字段时，已有白底图不能被一推就抹掉
 *
 * 供应商和存储都换假实现：不碰网络、不碰 OSS、一分钱不花。
 */
class NormalizeTest extends TestCase
{
    use RefreshDatabase;

    private FakeNormalizeProvider $provider;
    private FakeWashStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // 功能开着、每天 5 次（用例里要改就自己 config）；衣架额度给够，别在这儿测衣架
        config([
            'tryon.normalize_enabled'     => true,
            'tryon.normalize_daily_limit' => 5,
            'tryon.normalize_model'       => 'wan2.7-image',
            'tryon.api_key'               => 'test-key',
            'tryon.model_image'           => 'https://cdn.example.com/tryon/model.jpg',
            'tryon.model_key'             => 'model-a',
            'tryon.enabled'               => true,
            'tryon.member_only'           => false,
            'quota.reward_monthly'        => 0,
        ]);

        // 白底图是「从阿里云下载再转存我们存储」的，那一步也得桩掉（假供应商给的地址不存在）
        Http::fake(['*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/png'])]);

        $this->provider = new FakeNormalizeProvider();
        $this->storage  = new FakeWashStorage();

        $this->app->instance(TryonProvider::class, $this->provider);
        $this->app->instance(ImageStorage::class, $this->storage);
    }

    /** 造一个用户（衣架够用）+ 一件带照片的衣物，并登录 */
    private function userWithItem(string $imageUrl = 'https://cdn.example.com/clothes/1.jpg', string $cid = 'i1'): array
    {
        $user = User::factory()->create([
            'item_quota'       => 50,
            'daily_quota'      => 50,
            'daily_reset_date' => today()->toDateString(),
        ]);
        Sanctum::actingAs($user);

        $item = ClothesItem::create([
            'user_id'           => $user->id,
            'client_id'         => $cid,
            'name'              => '牛仔裤',
            'category'          => 'bottom',
            'sub'               => '',
            'colors'            => [],
            'seasons'           => [],
            'occasions'         => [],
            'image_url'         => $imageUrl,
            'client_created_at' => 1758000000000,
        ]);

        return [$user, $item];
    }

    /** 调一次洗白底接口 */
    private function wash(User $user, string $itemId, string $imageUrl)
    {
        Sanctum::actingAs($user->refresh());

        return $this->postJson('/api/items/normalize', ['itemId' => $itemId, 'imageUrl' => $imageUrl]);
    }

    /**
     * 场景：总开关关着
     * 预期：4010 之前先拦 4009，一次供应商都不调（线上默认就是关的，先发代码不花钱）
     */
    public function test_disabled_feature_is_rejected(): void
    {
        config(['tryon.normalize_enabled' => false]);
        [$user, $item] = $this->userWithItem();

        $res = $this->postJson('/api/items/normalize', ['itemId' => $item->client_id, 'imageUrl' => $item->image_url]);

        $res->assertOk()->assertJson(['code' => 4009]);
        $this->assertSame(0, $this->provider->normalizeCalls);
    }

    /**
     * 场景：进页面问一次入口状态
     * 预期：开关 + 今天还剩几次（前端据此决定入口显不显示、显示还剩几次）
     */
    public function test_status_reports_switch_and_left_today(): void
    {
        [$user] = $this->userWithItem();

        $this->getJson('/api/items/normalize/status')
            ->assertOk()
            ->assertJson(['code' => 0])
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.leftToday', 5)
            ->assertJsonPath('data.dailyLimit', 5);

        $this->assertSame(today()->toDateString(), (string) $user->refresh()->normalize_date, '问状态也要把当天计数初始化好');
    }

    /**
     * 场景：第一次洗一张
     * 预期：调一次供应商、白底图存进我们 OSS、衣物上记下白底图 + 原图 + 原图指纹、计数 +1
     */
    public function test_first_wash_calls_provider_and_records_photo(): void
    {
        [$user, $item] = $this->userWithItem();

        $res = $this->wash($user, $item->client_id, $item->image_url);

        $res->assertOk()->assertJson(['code' => 0]);
        $this->assertSame(1, $this->provider->normalizeCalls);
        $this->assertSame(1, $this->storage->puts, '白底图要转存到我们 OSS（阿里云的地址会过期）');
        $this->assertStringContainsString('https://cdn.example.com/oss/clothes/', (string) $res->json('data.normalizedUrl'));
        $this->assertFalse((bool) $res->json('data.cached'));
        $this->assertSame(4, (int) $res->json('data.leftToday'));

        $fresh = $item->refresh();
        $this->assertSame((string) $res->json('data.normalizedUrl'), (string) $fresh->normalized_url);
        $this->assertSame(md5($item->image_url), (string) $fresh->normalized_source);
        $this->assertSame($item->image_url, (string) $fresh->original_image_url, '顺手把原图记下来');
        $this->assertSame($item->image_url, (string) $fresh->image_url, '没选当封面时，展示图仍然是原图');

        $this->assertSame(1, (int) $user->refresh()->normalize_used);
        $this->assertDatabaseHas('tryon_garments', [
            'user_id' => $user->id, 'item_id' => $item->client_id, 'source_hash' => md5($item->image_url),
        ]);
    }

    /**
     * 场景：同一张照片再点一次
     * 预期：命中缓存秒回，**不调供应商、不扣次数**（用户来回点也不花钱）
     */
    public function test_second_wash_of_same_photo_is_free(): void
    {
        [$user, $item] = $this->userWithItem();

        $first  = $this->wash($user, $item->client_id, $item->image_url);
        $second = $this->wash($user, $item->client_id, $item->image_url);

        $second->assertOk()->assertJson(['code' => 0]);
        $this->assertTrue((bool) $second->json('data.cached'));
        $this->assertSame($first->json('data.normalizedUrl'), $second->json('data.normalizedUrl'));
        $this->assertSame(1, $this->provider->normalizeCalls, '第二次不该再调供应商');
        $this->assertSame(1, (int) $user->refresh()->normalize_used, '命中缓存不算次数');
        $this->assertSame(4, (int) $second->json('data.leftToday'));
    }

    /**
     * 场景：今天的免费次数用完，又换了一张新照片来洗
     * 预期：4010 拦住（缓存命中那条路不受影响，见上一条）
     */
    public function test_daily_limit_blocks_new_washes(): void
    {
        config(['tryon.normalize_daily_limit' => 1]);
        [$user, $item] = $this->userWithItem();

        $this->wash($user, $item->client_id, $item->image_url)->assertJson(['code' => 0]);

        // 换了张照片（指纹变了 → 走不到缓存）
        $res = $this->wash($user, $item->client_id, 'https://cdn.example.com/clothes/2.jpg');

        $res->assertOk()->assertJson(['code' => 4010]);
        $this->assertSame(1, $this->provider->normalizeCalls, '被拦时一次都不该调');
    }

    /**
     * 场景：管理员来洗（is_admin）
     * 预期：**不限次数**、也不占用计数（客服/自己试效果用）；状态里带 unlimited 标记给前端
     */
    public function test_admin_is_not_limited(): void
    {
        config(['tryon.normalize_daily_limit' => 1]);
        [$user, $item] = $this->userWithItem();
        $user->is_admin = 1;
        $user->save();

        $this->getJson('/api/items/normalize/status')->assertOk()
            ->assertJsonPath('data.unlimited', true);

        // 限额 1，但管理员连洗 3 张不同的原图都该通过
        for ($i = 1; $i <= 3; $i++) {
            $this->wash($user, $item->client_id, 'https://cdn.example.com/clothes/admin-' . $i . '.jpg')
                ->assertOk()
                ->assertJsonPath('code', 0)
                ->assertJsonPath('data.unlimited', true);
        }

        $this->assertSame(3, $this->provider->normalizeCalls);
        $this->assertSame(0, (int) $user->refresh()->normalize_used, '管理员不占免费次数');
    }

    /**
     * 场景：用户换了照片再洗
     * 预期：指纹变了 → 重新洗（旧的不会被复用），次数 +1
     */
    public function test_changing_photo_triggers_another_wash(): void
    {
        [$user, $item] = $this->userWithItem();

        $this->wash($user, $item->client_id, $item->image_url);

        $newUrl = 'https://cdn.example.com/clothes/2.jpg';
        $res = $this->wash($user, $item->client_id, $newUrl);

        $this->assertFalse((bool) $res->json('data.cached'));
        $this->assertSame(2, $this->provider->normalizeCalls);
        $this->assertSame(2, (int) $user->refresh()->normalize_used);
        $this->assertSame(md5($newUrl), (string) $item->refresh()->normalized_source);
    }

    /**
     * 场景：没照片 / 这件衣物不是自己的
     * 预期：4004（找不到就是找不到，不能帮别人洗）
     */
    public function test_missing_photo_or_someone_elses_item(): void
    {
        [$user, $item] = $this->userWithItem();

        $res = $this->postJson('/api/items/normalize', ['itemId' => $item->client_id, 'imageUrl' => '']);
        $res->assertOk()->assertJson(['code' => 4004]);

        // 别人的衣物：client_id 存在但 user_id 不是我的
        $other = User::factory()->create();
        ClothesItem::create([
            'user_id' => $other->id, 'client_id' => 'x1', 'name' => '别人的', 'category' => 'top',
            'image_url' => 'https://cdn.example.com/clothes/other.jpg', 'client_created_at' => 1758000000000,
        ]);

        $this->wash($user, 'x1', 'https://cdn.example.com/clothes/other.jpg')
            ->assertOk()->assertJson(['code' => 4004]);

        $this->assertSame(0, $this->provider->normalizeCalls);
    }

    /**
     * 场景：供应商那边失败（限流 / 图片不合规 / 超时）
     * 预期：4008 抛给用户，**不扣次数**、衣物上不留半截数据（失败不计费，但也不该占额度）
     */
    public function test_provider_failure_does_not_consume_quota(): void
    {
        [$user, $item] = $this->userWithItem();
        $this->provider->failWith = new TryonException(4008, '归一化调用失败：Throttling.RateQuota');

        $res = $this->wash($user, $item->client_id, $item->image_url);

        $res->assertOk()->assertJson(['code' => 4008]);
        $this->assertSame(0, (int) $user->refresh()->normalize_used, '失败不算次数');
        $this->assertSame('', (string) $item->refresh()->normalized_url);
        $this->assertSame(0, TryonGarment::count(), '失败不写缓存行');
        $this->assertNull($res->json('data'), '失败时 data 是 null');
    }

    /**
     * 场景：用户选了「用这张当封面」后保存（imageUrl 换成白底图、原图写进 originalImageUrl）
     * 预期：两个地址都存下来；**原图不能被 OSS 清理逻辑删掉**（否则「切回原图」就是 404）
     */
    public function test_white_as_cover_keeps_original_image(): void
    {
        [$user, $item] = $this->userWithItem();
        $white = $this->wash($user, $item->client_id, $item->image_url)->json('data.normalizedUrl');

        Sanctum::actingAs($user->refresh());
        $this->postJson('/api/clothes/sync', ['items' => [[
            'id' => $item->client_id, 'name' => '牛仔裤', 'category' => 'bottom',
            'sub' => '', 'colors' => [], 'seasons' => [], 'occasions' => [],
            'imageUrl' => $white, 'originalImageUrl' => $item->image_url, 'normalizedUrl' => $white,
            'createdAt' => 1758000000000,
        ]]])->assertOk()->assertJson(['code' => 0]);

        $fresh = $item->refresh();
        $this->assertSame($white, (string) $fresh->image_url, '展示图换成白底图了');
        $this->assertSame('https://cdn.example.com/clothes/1.jpg', (string) $fresh->original_image_url, '原图还在');
        $this->assertNotContains('https://cdn.example.com/clothes/1.jpg', $this->storage->deleted, '原图不能被删');

        // 出参要给小程序：两个地址 + 「现在用的是白底图」
        $this->getJson('/api/clothes/sync')->assertOk()
            ->assertJsonPath('data.items.0.isWhite', true)
            ->assertJsonPath('data.items.0.originalImageUrl', 'https://cdn.example.com/clothes/1.jpg');
    }

    /**
     * 场景：用户点了「切回原图」再保存
     * 预期：展示图回到原图，白底图仍保留（下次还能切回来），白底图那张不会被误删
     */
    public function test_switching_back_to_original_keeps_white(): void
    {
        [$user, $item] = $this->userWithItem();
        $white = $this->wash($user, $item->client_id, $item->image_url)->json('data.normalizedUrl');
        $orig  = $item->image_url;

        Sanctum::actingAs($user->refresh());
        $push = function (string $imageUrl, string $original, string $normalized) use ($item) {
            return $this->postJson('/api/clothes/sync', ['items' => [[
                'id' => $item->client_id, 'name' => '牛仔裤', 'category' => 'bottom',
                'sub' => '', 'colors' => [], 'seasons' => [], 'occasions' => [],
                'imageUrl' => $imageUrl, 'originalImageUrl' => $original, 'normalizedUrl' => $normalized,
                'createdAt' => 1758000000000,
            ]]]);
        };

        $push($white, $orig, $white)->assertOk();
        $push($orig, $orig, $white)->assertOk();

        $fresh = $item->refresh();
        $this->assertSame($orig, (string) $fresh->image_url);
        $this->assertSame($white, (string) $fresh->normalized_url, '白底图还留着');
        $this->assertNotContains($white, $this->storage->deleted, '切回原图不能把白底图删了');
    }

    /**
     * 场景：老版本小程序推数据（请求里压根没有这两个字段）
     * 预期：已有白底图，不能被"一推就空"抹掉（部署顺序上小程序会晚于后端）
     */
    public function test_old_client_push_does_not_wipe_normalized(): void
    {
        [$user, $item] = $this->userWithItem();
        $white = $this->wash($user, $item->client_id, $item->image_url)->json('data.normalizedUrl');

        Sanctum::actingAs($user->refresh());
        $this->postJson('/api/clothes/sync', ['items' => [[
            'id' => $item->client_id, 'name' => '牛仔裤改名了', 'category' => 'bottom',
            'sub' => '', 'colors' => [], 'seasons' => [], 'occasions' => [],
            'imageUrl' => $item->image_url, 'createdAt' => 1758000000000,
        ]]])->assertOk();

        $fresh = $item->refresh();
        $this->assertSame($white, (string) $fresh->normalized_url);
        $this->assertNotSame('', (string) $fresh->normalized_source);
        $this->assertSame('牛仔裤改名了', (string) $fresh->name);
    }

    /**
     * 场景：衣物已经有白底图（用户洗过 / 甚至设为封面），这时去试穿
     * 预期：试穿直接用这张白底图，**不再调归一化**（一次省 0.2 元）
     */
    public function test_tryon_reuses_existing_normalized_image(): void
    {
        [$user, $item] = $this->userWithItem();
        $white = $this->wash($user, $item->client_id, $item->image_url)->json('data.normalizedUrl');

        // 用户把白底图设成了封面：image_url = 白底图
        $item->refresh();
        $item->image_url = $white;
        $item->save();

        $callsBefore = $this->provider->normalizeCalls;

        $task = TryonTask::create([
            'user_id' => $user->id, 'item_id' => $item->client_id, 'model_key' => 'model-a',
            'source_url' => $white, 'source_hash' => md5($white), 'slot' => 'top',
            'garments' => ['top' => $item->client_id],
            'status' => TryonTask::STATUS_PENDING, 'provider' => 'dashscope',
        ]);

        $this->app->make(TryonService::class)->run($task->id);

        $this->assertSame($callsBefore, $this->provider->normalizeCalls, '不该再洗一遍');
        $this->assertSame(1, $this->provider->tryOnCalls);
        $this->assertSame(TryonTask::STATUS_DONE, (string) $task->refresh()->status);
        $this->assertSame($white, (string) $task->normalized_url);
    }

    // ===== 自动流程（2026-09 用户拍板：默认打开、保存后自动跑、跑完自动换封面）=====

    /**
     * 保存一件**新衣物**（带照片）→ 自动入队，且不影响保存本身
     *
     * 预期：接口 200、normalizeQueued=1、库里这件状态是 queued、派了 RunNormalize
     */
    public function test_saving_new_item_queues_auto_normalize(): void
    {
        Queue::fake();
        $this->userWithItem();

        $this->postJson('/api/clothes/sync', ['items' => [[
            'id'               => 'i2',
            'name'             => '白衬衫',
            'category'         => 'top',
            'imageUrl'         => 'https://cdn.example.com/clothes/2.jpg',
            'originalImageUrl' => 'https://cdn.example.com/clothes/2.jpg',
            'normalizeAuto'    => true,
            'createdAt'        => 1758000001000,
        ]]])->assertOk()->assertJsonPath('data.normalizeQueued', 1);

        Queue::assertPushed(RunNormalize::class, 1);
        $this->assertSame('queued', (string) ClothesItem::where('client_id', 'i2')->value('normalize_status'));
    }

    /**
     * 用户在记录页关掉「自动洗白底」→ 保存后不入队（不进队列就不会花钱）
     */
    public function test_item_with_auto_off_is_not_queued(): void
    {
        Queue::fake();
        $this->userWithItem();

        $this->postJson('/api/clothes/sync', ['items' => [[
            'id'               => 'i3',
            'name'             => '外套',
            'category'         => 'top',
            'imageUrl'         => 'https://cdn.example.com/clothes/3.jpg',
            'originalImageUrl' => 'https://cdn.example.com/clothes/3.jpg',
            'normalizeAuto'    => false,
            'createdAt'        => 1758000002000,
        ]]])->assertOk()->assertJsonPath('data.normalizeQueued', 0);

        Queue::assertNotPushed(RunNormalize::class);
    }

    /**
     * 只改名字 / 分类（照片没换）→ 不入队
     *
     * 这条守的是钱：编辑已有衣物不该触发重洗（一张 0.2 元）。
     */
    public function test_editing_name_only_does_not_queue(): void
    {
        Queue::fake();
        [, $item] = $this->userWithItem();
        $item->forceFill(['original_image_url' => $item->image_url])->save();

        $this->postJson('/api/clothes/sync', ['items' => [[
            'id'               => 'i1',
            'name'             => '换了个名字',
            'category'         => 'bottom',
            'imageUrl'         => $item->image_url,
            'originalImageUrl' => $item->original_image_url,
            'createdAt'        => 1758000000000,
        ]]])->assertOk()->assertJsonPath('data.normalizeQueued', 0);

        Queue::assertNotPushed(RunNormalize::class);
    }

    /**
     * 队列里跑完一件：白底图入库 + **展示图自动换成白底图** + 状态 done + 计一次数
     */
    public function test_queued_run_swaps_cover_to_white_and_counts(): void
    {
        [$user, $item] = $this->userWithItem();
        $item->forceFill(['original_image_url' => $item->image_url, 'normalize_auto' => true])->save();

        app(NormalizeService::class)->runQueued((int) $user->id, 'i1');

        $item->refresh();
        $this->assertSame('done', (string) $item->normalize_status);
        $this->assertNotEmpty($item->normalized_url);
        $this->assertSame((string) $item->normalized_url, (string) $item->image_url);   // 自动换封面
        $this->assertSame(1, $this->provider->normalizeCalls);
        $this->assertSame(1, (int) $user->fresh()->normalize_used);
    }

    /**
     * 用户明确选过「用原图」→ 白底图照样生成并留着，但**不覆盖**封面
     *
     * 用户 2026-09 原话：不覆盖，但是自动洗完之后的这个图需要保留。
     */
    public function test_cover_choice_orig_keeps_original_but_stores_white(): void
    {
        [$user, $item] = $this->userWithItem();
        $item->forceFill([
            'original_image_url' => $item->image_url,
            'normalize_auto'     => true,
            'cover_choice'       => 'orig',
        ])->save();

        app(NormalizeService::class)->runQueued((int) $user->id, 'i1');

        $item->refresh();
        $this->assertSame('done', (string) $item->normalize_status);
        $this->assertNotEmpty($item->normalized_url);                                          // 白底图留着了
        $this->assertSame('https://cdn.example.com/clothes/1.jpg', (string) $item->image_url);  // 封面还是原图
    }

    /**
     * 今天 10 张用完了 → 这件标 skipped（不报错、不重试烧钱），第二天惰性补洗
     */
    public function test_quota_exhausted_marks_skipped(): void
    {
        [$user, $item] = $this->userWithItem();
        $user->forceFill(['normalize_used' => 5, 'normalize_date' => today()->toDateString()])->save();
        $item->forceFill(['original_image_url' => $item->image_url, 'normalize_auto' => true])->save();

        app(NormalizeService::class)->runQueued((int) $user->id, 'i1');

        $item->refresh();
        $this->assertSame('skipped', (string) $item->normalize_status);
        $this->assertSame(0, $this->provider->normalizeCalls);              // 一分钱没花
    }

    /**
     * 幂等：同一张原图重复投递（最常见的浪费）→ 第二次直接 done，不调供应商
     */
    public function test_repeat_dispatch_is_idempotent(): void
    {
        [$user, $item] = $this->userWithItem();
        $item->forceFill(['original_image_url' => $item->image_url, 'normalize_auto' => true])->save();

        $svc = app(NormalizeService::class);
        $svc->runQueued((int) $user->id, 'i1');
        $svc->runQueued((int) $user->id, 'i1');

        $this->assertSame(1, $this->provider->normalizeCalls);
        $this->assertSame(1, (int) $user->fresh()->normalize_used);
    }

    /**
     * 惰性补洗：进衣橱（GET sync）会把"当天没排上（skipped）"的接着洗，5 分钟内不重复扫
     */
    public function test_pull_tops_up_skipped_items_with_throttle(): void
    {
        Queue::fake();
        [$user, $item] = $this->userWithItem();
        $item->forceFill([
            'original_image_url' => $item->image_url,
            'normalize_auto'     => true,
            'normalize_status'   => 'skipped',      // 昨天额度用完没洗上
        ])->save();

        $this->getJson('/api/clothes/sync')->assertOk();
        Queue::assertPushed(RunNormalize::class, 1);

        // 5 分钟节流：再拉一次不再重复排队
        Queue::fake();
        $this->getJson('/api/clothes/sync')->assertOk();
        Queue::assertNotPushed(RunNormalize::class);
    }

    /**
     * ★ A 方案（用户 2026-09 拍板）：**老衣物不主动回补洗**
     *
     * 老衣物状态是空串（从没洗过），回补开关默认关 → 进衣橱不该把它排进队列。
     * 这条守的是钱：一发版不能让用户"没点任何东西也在花钱"。
     */
    public function test_old_items_are_not_backfilled_by_default(): void
    {
        Queue::fake();
        [$user, $item] = $this->userWithItem();
        $item->forceFill(['original_image_url' => $item->image_url, 'normalize_auto' => true, 'normalize_status' => ''])->save();

        $this->getJson('/api/clothes/sync')->assertOk();

        Queue::assertNotPushed(RunNormalize::class);
    }

    /** 回补开关打开时才会捡老衣物（留一个开关的用例，默认关） */
    public function test_backfill_switch_turns_old_items_on(): void
    {
        Queue::fake();
        config(['tryon.normalize_auto_backfill' => true]);

        [$user, $item] = $this->userWithItem();
        $item->forceFill(['original_image_url' => $item->image_url, 'normalize_auto' => true, 'normalize_status' => ''])->save();

        $this->getJson('/api/clothes/sync')->assertOk();

        Queue::assertPushed(RunNormalize::class, 1);
    }

    /**
     * 老衣物想洗也有明确的动作：用户在这件上把「自动洗白底」从关改成开 → 入队
     */
    public function test_turning_auto_on_for_old_item_queues_it(): void
    {
        Queue::fake();
        [$user, $item] = $this->userWithItem();
        $item->forceFill([
            'original_image_url' => $item->image_url,
            'normalize_auto'     => false,     // 原来是关的
            'normalize_status'   => '',
        ])->save();

        $this->postJson('/api/clothes/sync', ['items' => [[
            'id'               => 'i1',
            'name'             => '牛仔裤',
            'category'         => 'bottom',
            'imageUrl'         => $item->image_url,
            'originalImageUrl' => $item->original_image_url,
            'normalizeAuto'    => true,        // 用户打开了
            'createdAt'        => 1758000000000,
        ]]])->assertOk()->assertJsonPath('data.normalizeQueued', 1);

        Queue::assertPushed(RunNormalize::class, 1);
    }

    /**
     * 卡在 queued 太久（worker 没跑 / 队列被清）→ 进衣橱时重排一次，不让那行提示一直挂着
     */
    public function test_stuck_queued_items_are_requeued(): void
    {
        Queue::fake();
        [$user, $item] = $this->userWithItem();
        $item->forceFill([
            'original_image_url' => $item->image_url,
            'normalize_auto'     => true,
            'normalize_status'   => 'queued',
        ])->save();
        // 假装这是 40 分钟前卡住的（updated_at 手动往回拨）
        ClothesItem::where('id', $item->id)->update(['updated_at' => now()->subMinutes(40)]);

        $this->getJson('/api/clothes/sync')->assertOk();

        Queue::assertPushed(RunNormalize::class, 1);
    }

    /**
     * 手动「重新生成一张」带 force → 跳过缓存真的重洗一次（会花钱计次，这是用户要的）
     */
    public function test_manual_force_rewashes(): void
    {
        [$user, $item] = $this->userWithItem();
        $item->forceFill(['original_image_url' => $item->image_url, 'normalize_auto' => true])->save();

        $svc = app(NormalizeService::class);
        $svc->normalize($user, 'i1', (string) $item->image_url);              // 第一次：真洗
        $svc->normalize($user, 'i1', (string) $item->image_url);              // 第二次：命中缓存
        $svc->normalize($user, 'i1', (string) $item->image_url, true);        // 第三次：force 重洗

        $this->assertSame(2, $this->provider->normalizeCalls);
    }
}

/** 假供应商：只记调用次数，不碰网络 */
class FakeNormalizeProvider implements TryonProvider
{
    public int $normalizeCalls = 0;
    public int $tryOnCalls = 0;
    public ?TryonException $failWith = null;

    public function normalize(string $imageUrl): string
    {
        $this->normalizeCalls++;
        if (!empty($this->failWith)) {
            throw $this->failWith;
        }

        return 'https://dashscope.example.com/tmp/norm-' . $this->normalizeCalls . '.png';
    }

    public function tryOn(string $personUrl, ?string $topUrl, ?string $bottomUrl): string
    {
        $this->tryOnCalls++;

        return 'https://dashscope.example.com/tmp/result-' . $this->tryOnCalls . '.jpg';
    }
}

/**
 * 假存储：不碰 OSS；把「删了哪些图」记下来（验证原图没被删）
 *
 * 类名跟别处的假存储错开（PHPUnit 会把同级目录的测试文件全加载，重名会 fatal）
 */
class FakeWashStorage extends ImageStorage
{
    public int $puts = 0;
    public array $deleted = [];

    public function out(?string $url): string
    {
        return (string) $url;
    }

    public function putBinary(string $contents, string $ext, string $dir): array
    {
        $this->puts++;
        $key = $dir . '/202609/norm' . $this->puts . '.' . $ext;

        return ['path' => $key, 'url' => 'https://cdn.example.com/oss/' . $key, 'driver' => 'oss'];
    }

    public function deleteByUrl(?string $url): void
    {
        $this->deleted[] = (string) $url;
    }
}
