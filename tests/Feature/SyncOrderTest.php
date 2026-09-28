<?php

namespace Tests\Feature;

use App\Models\ClothesItem;
use App\Models\ClothesOutfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 列表顺序 + 「编辑别把排序键冲掉」的回归测试（2026-09-26 用户实测反馈）
 *
 * 背景：衣橱 / 穿搭列表都是按 `client_created_at`（小程序那边的录入时间，毫秒）**倒序**排的
 * （见 ClothesController::pull）。用户反馈："我把最新的重新编辑后，又不在第一位了"。
 *
 * 根因：编辑页提交的 payload 是自己拼的（pages/item-edit 的 validate() 里没有 createdAt），
 * 老代码在 upsertItem 里**无条件**写 `(int) ($row['createdAt'] ?? 0)` → 编辑一次就把它冲成 0
 * → 那件掉到列表最后。
 *
 * 这个文件守住三件事：
 *   ① 编辑（请求不带 / 带 0 的 createdAt）→ client_created_at 原样保留
 *   ② 请求带了有效时间 → 按它写（新增记录要用）
 *   ③ 列表顺序 = client_created_at 倒序（衣橱、穿搭一个口径）
 */
class SyncOrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 登录一个用户，返回 [user, 一件衣物，一套搭配]
     *
     * ⚠️ 名字不能叫 seed()：Laravel 的 TestCase 里有同名的 public 方法，private 覆盖它会 fatal
     */
    private function seedRows(): array
    {
        $user = User::factory()->create(['is_admin' => false]);
        Sanctum::actingAs($user);

        $item = ClothesItem::create([
            'user_id'           => $user->id,
            'client_id'         => 'i1',
            'name'              => '牛仔裤',
            'category'          => 'bottom',
            'colors'            => ['navy'],
            'image_url'         => 'https://cdn.example.com/clothes/1.jpg',
            'client_created_at' => 1758000000000,
        ]);

        $outfit = ClothesOutfit::create([
            'user_id'           => $user->id,
            'client_id'         => 'o1',
            'name'              => '通勤一套',
            'item_ids'          => ['i1'],
            'cover_url'         => 'https://cdn.example.com/outfits/1.jpg',
            'client_created_at' => 1758100000000,
        ]);

        return [$user, $item, $outfit];
    }

    /**
     * 场景：编辑已有衣物（换个名字），请求里**不带** createdAt —— 记录页的载荷本来就不带
     * 预期：client_created_at 一个字都不变（变了这件就会掉到列表最后）
     */
    public function test_editing_item_without_created_at_keeps_it(): void
    {
        [$user, $item] = $this->seedRows();

        $this->postJson('/api/clothes/sync', ['items' => [[
            'id'       => 'i1',
            'name'     => '换了个名字',
            'category' => 'bottom',
            'imageUrl' => $item->image_url,
            'createdAt' => 0,                 // ← 前端补不到时会发 0
        ]]])->assertOk()->assertJson(['code' => 0]);

        $item->refresh();
        $this->assertSame('换了个名字', $item->name, '编辑本身要生效');
        $this->assertSame(1758000000000, (int) $item->client_created_at, '录入时间不能被冲成 0');
    }

    /**
     * 场景：老版本小程序编辑时干脆不带这个键
     * 预期：同样保留原值（不是"缺键就写 0"）
     */
    public function test_editing_item_without_the_key_keeps_it(): void
    {
        [$user, $item] = $this->seedRows();

        $this->postJson('/api/clothes/sync', ['items' => [[
            'id'       => 'i1',
            'name'     => '只改名字',
            'category' => 'bottom',
            'imageUrl' => $item->image_url,
        ]]])->assertOk();

        $this->assertSame(1758000000000, (int) $item->refresh()->client_created_at);
    }

    /**
     * 场景：新增衣物时带了有效录入时间
     * 预期：就按它写（列表顺序靠它；新记录不写就永远是 0、排最后）
     */
    public function test_new_item_uses_the_given_created_at(): void
    {
        $this->seedRows();

        $this->postJson('/api/clothes/sync', ['items' => [[
            'id'        => 'i2',
            'name'      => '新衬衫',
            'category'  => 'top',
            'imageUrl'  => 'https://cdn.example.com/clothes/2.jpg',
            'createdAt' => 1758200000000,
        ]]])->assertOk();

        $this->assertSame(1758200000000, (int) ClothesItem::where('client_id', 'i2')->first()->client_created_at);
    }

    /**
     * 场景：编辑已有搭配（换个名字 / 换几件衣物）
     * 预期：搭配的 client_created_at 也不变（穿搭列表同一个排序键）
     */
    public function test_editing_outfit_keeps_created_at(): void
    {
        [$user, , $outfit] = $this->seedRows();

        $this->postJson('/api/clothes/sync', ['outfits' => [[
            'id'        => 'o1',
            'name'      => '改名了',
            'itemIds'   => ['i1'],
            'coverUrl'  => $outfit->cover_url,
            'createdAt' => 0,
        ]]])->assertOk();

        $outfit->refresh();
        $this->assertSame('改名了', $outfit->name);
        $this->assertSame(1758100000000, (int) $outfit->client_created_at);
    }

    /**
     * 场景：库里有三件不同录入时间的衣物 + 三套搭配
     * 预期：pull 回来都是**新的在前**（衣橱与穿搭同一个口径，前端不再排序）
     */
    public function test_pull_returns_newest_first(): void
    {
        [$user] = $this->seedRows();

        foreach ([['i2', 1758300000000], ['i3', 1758200000000]] as [$cid, $ts]) {
            ClothesItem::create([
                'user_id' => $user->id, 'client_id' => $cid, 'category' => 'top',
                'image_url' => 'https://cdn.example.com/clothes/' . $cid . '.jpg',
                'client_created_at' => $ts,
            ]);
        }
        foreach ([['o2', 1758300000000], ['o3', 1758000000000]] as [$cid, $ts]) {
            ClothesOutfit::create([
                'user_id' => $user->id, 'client_id' => $cid, 'item_ids' => [],
                'client_created_at' => $ts,
            ]);
        }

        $res = $this->getJson('/api/clothes/sync')->assertOk()->json('data');

        $this->assertSame(['i2', 'i3', 'i1'], array_column($res['items'], 'id'), '衣物：录入时间倒序');
        $this->assertSame(['o2', 'o1', 'o3'], array_column($res['outfits'], 'id'), '搭配：同一口径');
    }
}
