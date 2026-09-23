<?php

namespace Tests\Feature;

use App\Exceptions\TryonException;
use App\Models\ClothesItem;
use App\Models\TryonGarment;
use App\Models\TryonTask;
use App\Models\User;
use App\Services\ImageStorage;
use App\Services\Tryon\TryonProvider;
use App\Services\Tryon\TryonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
