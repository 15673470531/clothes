<?php

namespace Tests\Feature;

use App\Models\ClothesItem;
use App\Models\ClothesOutfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 管理端下钻：某个人的衣物 / 搭配明细（2026-09）
 *
 * 背景：用户名单上每个人带「N 件衣物 / N 套搭配」，点进去要看 TA 具体录了什么。
 * 被测：App\Http\Controllers\Api\AdminController::userItems / userOutfits
 *
 * 覆盖：
 *  - 条数口径跟名单上的数字一致（软删的不算）、按录入时间倒序、只出这个人的数据
 *  - 搭配的「件数 / 缺N」怎么算、没封面时用第一件衣物兜底
 *  - 非管理员 403、用户不存在给业务错误码、分页
 */
class AdminUserContentTest extends TestCase
{
    use RefreshDatabase;

    /** 造一件衣物（只给展示用得到的字段，字段名对齐接口出参口径） */
    private function makeItem(User $u, string $cid, array $attrs = []): ClothesItem
    {
        return ClothesItem::create(array_merge([
            'user_id'           => $u->id,
            'client_id'         => $cid,
            'name'              => '白T',
            'category'          => 'top',
            'sub'               => 'tshirt',
            'colors'            => ['white'],
            'seasons'           => ['夏'],
            'occasions'         => ['休闲'],
            'image_url'         => '',
            'client_created_at' => 1758000000000,
        ], $attrs));
    }

    /** 造一套搭配 */
    private function makeOutfit(User $u, string $cid, array $attrs = []): ClothesOutfit
    {
        return ClothesOutfit::create(array_merge([
            'user_id'           => $u->id,
            'client_id'         => $cid,
            'name'              => '通勤一套',
            'name_auto'         => false,
            'occasions'         => ['通勤'],
            'item_ids'          => [],
            'slots'             => [],
            'cover_url'         => '',
            'client_created_at' => 1758000000000,
        ], $attrs));
    }

    /** 用管理员身份登录（下钻接口只有管理员能调） */
    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * 场景：目标用户录了 3 件（其中 1 件已软删）+ 另一个用户也录了 1 件
     * 预期：total 只算这个人的 2 件（软删不算，别人的也不算），按录入时间倒序
     */
    public function test_item_list_counts_like_the_user_list(): void
    {
        $this->actingAsAdmin();
        $u    = User::factory()->create(['is_admin' => false]);
        $else = User::factory()->create(['is_admin' => false]);

        $this->makeItem($u, 'i1', ['client_created_at' => 1758000000000]);
        $this->makeItem($u, 'i2', ['client_created_at' => 1758100000000]);
        $this->makeItem($u, 'i3', ['client_created_at' => 1758200000000])->delete();   // 软删
        $this->makeItem($else, 'i9');

        $res = $this->getJson('/api/admin/users/' . $u->id . '/items');

        $res->assertOk()->assertJson(['code' => 0]);
        $this->assertSame(2, $res->json('data.total'), '软删的、别人的都不该算进来');
        $this->assertSame(['i2', 'i1'], array_column($res->json('data.list'), 'id'), '按录入时间倒序');
        $this->assertSame($u->id, $res->json('data.user.id'), '顶部要能看到看的是谁');
    }

    /**
     * 场景：衣物带照片地址，且后端返回了 imageStorage
     * 预期：出参给全渲染要用的字段（品类/颜色/时间），照片地址经过规范化、带上存储位置
     */
    public function test_item_payload_has_render_fields(): void
    {
        $this->actingAsAdmin();
        $u = User::factory()->create(['is_admin' => false]);
        $this->makeItem($u, 'i1', ['image_url' => 'https://example.com/storage/clothes/202609/a.jpg']);

        $row = $this->getJson('/api/admin/users/' . $u->id . '/items')->json('data.list.0');

        $this->assertSame('top', $row['category']);
        $this->assertSame(['white'], $row['colors']);
        $this->assertSame('local', $row['imageStorage'], '落本地盘的图要标 local（小程序据此挂黄点）');
        $this->assertStringContainsString('/storage/clothes/202609/a.jpg', $row['imageUrl']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['createdAt'], '列表上的日期');
    }

    /**
     * 场景：这件衣物的封面已经被自动洗成白底图（normalized_url == image_url），原图另存一份
     * 预期：出参带上原图地址 + 白底图地址 + isWhite=true —— 管理端据此挂「原图」角标、点开对比
     */
    public function test_item_payload_keeps_original_photo_after_white_wash(): void
    {
        $this->actingAsAdmin();
        $u = User::factory()->create(['is_admin' => false]);
        $this->makeItem($u, 'i1', [
            'image_url'          => 'https://example.com/storage/clothes/202609/white.jpg',
            'normalized_url'     => 'https://example.com/storage/clothes/202609/white.jpg',
            'original_image_url' => 'https://example.com/storage/clothes/202609/orig.jpg',
        ]);

        $row = $this->getJson('/api/admin/users/' . $u->id . '/items')->json('data.list.0');

        $this->assertTrue($row['isWhite'], '封面是白底图 → isWhite=true（前端才会挂角标）');
        $this->assertStringContainsString('orig.jpg', $row['originalImageUrl'], '原图地址要给出');
        $this->assertStringContainsString('white.jpg', $row['normalizedUrl']);
    }

    /**
     * 场景：没洗过白底的衣物（白底图 / 原图两列都空）
     * 预期：isWhite=false、两个地址都是空串 —— 前端不挂角标，也不弹对比层
     */
    public function test_item_payload_without_white_wash_has_no_compare(): void
    {
        $this->actingAsAdmin();
        $u = User::factory()->create(['is_admin' => false]);
        $this->makeItem($u, 'i1', ['image_url' => 'https://example.com/storage/clothes/202609/a.jpg']);

        $row = $this->getJson('/api/admin/users/' . $u->id . '/items')->json('data.list.0');

        $this->assertFalse($row['isWhite']);
        $this->assertSame('', $row['originalImageUrl'], '没洗过就没有原图可分');
        $this->assertSame('', $row['normalizedUrl']);
    }

    /**
     * 场景：搭配引用了 3 件衣物，其中 1 件已经被删掉
     * 预期：count=2、missing=1；没封面时给第一件还在的衣物当缩略（不留空白格子）
     */
    public function test_outfit_reports_count_missing_and_thumb(): void
    {
        $this->actingAsAdmin();
        $u = User::factory()->create(['is_admin' => false]);

        $this->makeItem($u, 'i1', ['colors' => ['navy'], 'image_url' => 'https://example.com/storage/clothes/202609/b.jpg']);
        $this->makeItem($u, 'i2');
        $this->makeItem($u, 'i3')->delete();                                   // 被删掉的那件
        $this->makeOutfit($u, 'o1', ['item_ids' => ['i1', 'i2', 'i3']]);

        $row = $this->getJson('/api/admin/users/' . $u->id . '/outfits')->json('data.list.0');

        $this->assertSame(2, $row['count'], '件数只算还在的衣物');
        $this->assertSame(1, $row['missing'], '缺 1 件要如实报出来');
        $this->assertSame(['navy'], $row['thumb']['colors'], '没封面时用第一件衣物的颜色块兜底');
        $this->assertStringContainsString('b.jpg', $row['thumb']['imageUrl']);
    }

    /**
     * 场景：搭配已经生成了封面
     * 预期：封面地址照常返回，前端优先用封面（thumb 仍在，不影响）
     */
    public function test_outfit_returns_cover(): void
    {
        $this->actingAsAdmin();
        $u = User::factory()->create(['is_admin' => false]);
        $this->makeItem($u, 'i1');
        $this->makeOutfit($u, 'o1', ['item_ids' => ['i1'], 'cover_url' => 'https://img.example.com/outfit/o1.png']);

        $row = $this->getJson('/api/admin/users/' . $u->id . '/outfits')->json('data.list.0');

        $this->assertSame('https://img.example.com/outfit/o1.png', $row['coverUrl']);
        $this->assertSame('oss', $row['coverStorage']);
    }

    /**
     * 场景：搭配列表分页
     * 预期：per_page=2 时只回 2 条、lastPage=2、total 仍是 3（前端上滑加载靠这两个数）
     */
    public function test_outfit_list_paginates(): void
    {
        $this->actingAsAdmin();
        $u = User::factory()->create(['is_admin' => false]);
        foreach (['o1', 'o2', 'o3'] as $i => $cid) {
            $this->makeOutfit($u, $cid, ['client_created_at' => 1758000000000 + $i * 1000]);
        }

        $res = $this->getJson('/api/admin/users/' . $u->id . '/outfits?page=1&per_page=2');

        $this->assertCount(2, $res->json('data.list'));
        $this->assertSame(3, $res->json('data.total'));
        $this->assertSame(2, $res->json('data.lastPage'));
        $this->assertSame(['o3', 'o2'], array_column($res->json('data.list'), 'id'));
    }

    /**
     * 场景：普通用户（非管理员）直接调下钻接口
     * 预期：403 —— 入口在小程序里藏了，但拦人必须在后端
     */
    public function test_non_admin_is_forbidden(): void
    {
        $u = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create(['is_admin' => false]);
        Sanctum::actingAs($u);

        $this->getJson('/api/admin/users/' . $other->id . '/items')->assertStatus(403);
        $this->getJson('/api/admin/users/' . $other->id . '/outfits')->assertStatus(403);
    }

    /**
     * 场景：用户 id 不存在（比如名单是旧的、人已经删了）
     * 预期：业务错误码 404 + 中文提示，而不是 500
     */
    public function test_unknown_user_returns_business_error(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson('/api/admin/users/999999/items');

        $res->assertOk()->assertJson(['code' => 404]);
        $this->assertStringContainsString('不在了', $res->json('msg'));
    }

    /**
     * 场景：这个人一件衣物都没录（空号）
     * 预期：正常返回空列表（total=0），前端显示空态而不是报错
     */
    public function test_empty_user_returns_empty_list(): void
    {
        $this->actingAsAdmin();
        $u = User::factory()->create(['is_admin' => false]);

        $res = $this->getJson('/api/admin/users/' . $u->id . '/items');

        $res->assertOk()->assertJson(['code' => 0]);
        $this->assertSame(0, $res->json('data.total'));
        $this->assertSame([], $res->json('data.list'));
    }

    /**
     * 场景：用户名单（GET /api/admin/users）里带衣架两个数（2026-09 用户要的）
     *
     * 预期：可用 = users.item_quota；总数 = 余额 + 已占用（衣物 + 搭配，软删的不算），
     *       跟「我的」页那张衣架卡一个口径（Quota::summary）
     */
    public function test_user_list_shows_hanger_numbers(): void
    {
        $this->actingAsAdmin();
        $u = User::factory()->create(['item_quota' => 20, 'is_admin' => false]);

        $this->makeItem($u, 'i1');
        $this->makeItem($u, 'i2');
        $this->makeItem($u, 'i3')->delete();   // 软删：不算占用（跟退还衣架的口径一致）
        $this->makeOutfit($u, 'o1');

        // 名单按 id 倒序兜底，刚建的这个号在第一行
        $row = $this->getJson('/api/admin/users')->assertOk()->json('data.list.0');

        $this->assertSame($u->id, $row['id'], '第一行就是刚建的号');
        $this->assertSame(20, $row['hangerTotal'], '可用 = 余额');
        $this->assertSame(23, $row['hangerTotalLimit'], '总数 = 20 + 2 件衣物 + 1 套搭配');
        $this->assertSame(2, $row['itemCount'], '件数口径跟衣架一致（软删不算）');
        $this->assertSame(1, $row['outfitCount']);
    }
}
