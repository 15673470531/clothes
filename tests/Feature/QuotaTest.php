<?php

namespace Tests\Feature;

use App\Models\ClothesItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 额度测试（2026-09 第二版：用户表上的余额）
 *
 * 背景：照片进 OSS 要花钱，所以每个人能录的衣物是有限的。额度做成用户表上的两个余额：
 *   users.item_quota    还能录几件（成功录一件减 1）
 *   users.daily_quota   今天还能录几件（每天重置）
 * 被测：App\Services\Quota + ClothesController::push 的扣减逻辑 + quota:reset-daily 命令
 *
 * 覆盖：够就扣、不够就整批拦（4001/4002）、编辑不扣、跨天自动补满、每天重置命令。
 * 注意：用例里直接把余额设小，不去造 200 条数据。
 */
class QuotaTest extends TestCase
{
    use RefreshDatabase;

    /** 造一条衣物 payload（接口只认这些字段） */
    private function itemPayload(string $id): array
    {
        return [
            'id'        => $id,
            'name'      => '测试衣物',
            'category'  => 'top',
            'sub'       => '',
            'colors'    => [],
            'seasons'   => [],
            'occasions' => [],
            'imageUrl'  => '',
            'createdAt' => 1758000000000,
        ];
    }

    /** 造一个余额可控的用户并登录 */
    private function userWith(int $itemQuota, int $dailyQuota = 50): User
    {
        $user = User::factory()->create([
            'item_quota'       => $itemQuota,
            'daily_quota'      => $dailyQuota,
            'daily_reset_date' => today()->toDateString(),
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * 额度够：录一件扣一件（两个余额都减 1）
     *
     * 预期：code=0，item_quota 3→2，daily_quota 2→1
     */
    public function test_saving_item_consumes_quota(): void
    {
        $user = $this->userWith(3, 2);

        $this->postJson('/api/clothes/sync', ['items' => [$this->itemPayload('i1')]])
            ->assertOk()->assertJson(['code' => 0]);

        $user->refresh();
        $this->assertSame(2, $user->item_quota);
        $this->assertSame(1, $user->daily_quota);
    }

    /**
     * 总额度不够：整批拦下，一条都不写，余额也不动
     *
     * 预期：只剩 1 件额度、这次要录 2 件 → code=4001 + 中文提示；库 0 条、余额还是 1
     */
    public function test_item_quota_blocks_whole_batch(): void
    {
        $user = $this->userWith(1);

        $res = $this->postJson('/api/clothes/sync', ['items' => [$this->itemPayload('i1'), $this->itemPayload('i2')]]);
        $res->assertOk()->assertJson(['code' => 4001]);
        $this->assertStringContainsString('还能再录 1 件', $res->json('msg'));

        $this->assertSame(0, ClothesItem::where('user_id', $user->id)->count(), '被拦下的批次不许写库');
        $this->assertSame(1, $user->refresh()->item_quota, '拦下了就不该扣额度');
    }

    /**
     * 今日额度不够：也是整批拦（code=4002），跟总额度分开提示
     *
     * 预期：总额度充足、今天只剩 0 件 → 4002
     */
    public function test_daily_quota_blocks(): void
    {
        $user = $this->userWith(100, 0);

        $res = $this->postJson('/api/clothes/sync', ['items' => [$this->itemPayload('i1')]]);
        $res->assertOk()->assertJson(['code' => 4002]);
        $this->assertStringContainsString('今天', $res->json('msg'));
        $this->assertSame(100, $user->refresh()->item_quota, '今日额度不足时不许扣总额度');
    }

    /**
     * 编辑已有衣物不扣额度（否则改一次少一件，没法用）
     *
     * 预期：余额 0 也能改名字，且余额不变
     */
    public function test_editing_existing_item_does_not_consume(): void
    {
        $user = $this->userWith(2);
        $this->postJson('/api/clothes/sync', ['items' => [$this->itemPayload('i1')]])->assertJson(['code' => 0]);

        $user->update(['item_quota' => 0, 'daily_quota' => 0]);

        $edit = $this->itemPayload('i1');
        $edit['name'] = '改个名字';
        $this->postJson('/api/clothes/sync', ['items' => [$edit]])->assertOk()->assertJson(['code' => 0]);

        $this->assertSame('改个名字', ClothesItem::where('client_id', 'i1')->value('name'));
        $this->assertSame(0, $user->refresh()->item_quota, '编辑不该扣额度');
    }

    /**
     * 跨天兜底：daily_reset_date 不是今天就自动补满今日额度
     * （定时任务万一没跑，用户第二天也不会被卡死）
     *
     * 预期：昨天用完 0 件 → 今天录一件成功，daily_quota 变成 49
     */
    public function test_daily_quota_auto_resets_next_day(): void
    {
        $user = $this->userWith(10, 0);
        $user->update(['daily_reset_date' => today()->subDay()->toDateString()]);

        $this->postJson('/api/clothes/sync', ['items' => [$this->itemPayload('i1')]])
            ->assertOk()->assertJson(['code' => 0]);

        $user->refresh();
        $this->assertSame(49, $user->daily_quota, '应视为新的一天、先补满 50 再扣 1');
        $this->assertSame(today()->toDateString(), $user->daily_reset_date->toDateString());
    }

    /**
     * 每天重置命令：把所有人的今日额度重置回默认值，总额度不动
     *
     * 预期：两个人 daily_quota 都回 50；item_quota 保持各自的值
     */
    public function test_reset_daily_command(): void
    {
        $a = User::factory()->create(['item_quota' => 7, 'daily_quota' => 0, 'daily_reset_date' => today()->subDay()->toDateString()]);
        $b = User::factory()->create(['item_quota' => 3, 'daily_quota' => 1, 'daily_reset_date' => today()->toDateString()]);

        $this->artisan('quota:reset-daily')
            ->assertSuccessful();

        $this->assertSame(50, $a->refresh()->daily_quota);
        $this->assertSame(50, $b->refresh()->daily_quota, '当天已重置过的也会被再刷一遍，结果一样，不影响');
        $this->assertSame(7, $a->item_quota, '总额度是用户的资产，重置每日额度不能动它');
        $this->assertSame(3, $b->item_quota);
    }
}
