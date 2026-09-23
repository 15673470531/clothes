<?php

namespace Tests\Feature;

use App\Models\ClothesItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 衣架测试（2026-09 第三版：额度 = 衣架）
 *
 * 背景：照片进 OSS 要花钱，所以一个人能挂的东西是有限的。
 * **一个衣架挂一样东西：新增一件衣物占 1 个，新增一套搭配也占 1 个**（共用同一个架子）。
 *   users.item_quota    总共还剩几个衣架（新增 -1；**删掉不想要的 +1（只还总额）**）
 *   users.daily_quota   今天还能挂几个（每天重置）
 * 被测：App\Services\Quota + ClothesController::push 的结算逻辑 + quota:reset-daily 命令
 *
 * 覆盖：够就扣、不够就整批拦（4001/4002）、编辑不扣、穿搭也占衣架、删除退还（只还总额）、
 *       重复删不叠加、同一批"删一件再加一件"、跨天自动补满、每天重置命令、接口出参兼容旧字段。
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

    /**
     * 造一个余额可控的用户并登录
     *
     * 注意：这里**关掉每月系统赠送**（config 设 0）—— 拉额度接口会惰性补发 +50，
     * 会让余额断言全部偏移 50，那些用例测的是衣架机制，不是每月赠送。
     * 每月赠送本身在 HangerRewardTest 里单独测。
     /**
      * 造一个余额可控的用户并登录
      *
      * 注意：这里**关掉每月系统赠送**（config 设 0）—— 拉额度接口会惰性补发 +50，
      * 会让余额断言整体偏 50。那些用例测的是衣架机制，不是每月赠送（后者在 HangerRewardTest 里单独测）。
      */
     private function userWith(int $itemQuota, int $dailyQuota = 50): User
     {
         config(['quota.reward_monthly' => 0]);

         $user = User::factory()->create([
             'item_quota'       => $itemQuota,
            'daily_quota'      => $dailyQuota,
            'daily_reset_date' => today()->toDateString(),
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * 读接口下发的衣架数字（GET /api/user/quota 的 data）
     *
     * 为什么要先重新 actingAs：`Sanctum::actingAs` 会把用户实例缓存在 guard 里，
     * 而同步接口是在事务里另起一条查询（lockForUpdate）改库的 —— 不换实例的话，
     * 下面读到的还是改动**之前**的余额（真机上每个请求各自从库里取用户，不存在这个问题，纯测试环境的事）
     */
    private function quotaOf(User $user): array
    {
        Sanctum::actingAs($user->refresh());

        return $this->getJson('/api/user/quota')->json('data');
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
        $this->assertStringContainsString('还剩 1 个', $res->json('msg'), '提示要按衣架口径说话');
        $this->assertStringContainsString('衣架', $res->json('msg'));

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
        $this->assertSame((int) config('quota.daily_quota') - 1, $user->daily_quota, '应视为新的一天、先补满再扣 1');
        $this->assertSame(today()->toDateString(), $user->daily_reset_date->toDateString());
    }

    /**
     * 场景：免费额度的数字来源
     * 预期：config('quota.*') 就是新用户拿到的免费衣架数（**100 / 100**，2026-09 用户定稿），
     *       建号时按它初始化（UserController::login），列默认值也由迁移同步成同一个数
     */
    public function test_free_hanger_amount_comes_from_config(): void
    {
        $this->assertSame(100, (int) config('quota.item_quota'), '免费总数');
        $this->assertSame(100, (int) config('quota.daily_quota'), '每天上限');
    }

    /** 造一个搭配 payload（接口只认这些字段） */
    private function outfitPayload(string $id, array $itemIds = []): array
    {
        return [
            'id'        => $id,
            'name'      => '测试搭配',
            'nameAuto'  => false,
            'occasions' => [],
            'itemIds'   => $itemIds,
            'slots'     => [],
            'coverUrl'  => '',
            'createdAt' => 1758000000000,
        ];
    }

    /**
     * 场景：新增一套搭配
     * 预期：也占一个衣架（总额、今日各减 1）—— 穿搭以前完全不限，2026-09 起要占
     */
    public function test_outfit_consumes_one_hanger(): void
    {
        $user = $this->userWith(3, 2);

        $this->postJson('/api/clothes/sync', ['outfits' => [$this->outfitPayload('o1')]])
            ->assertOk()->assertJson(['code' => 0]);

        $user->refresh();
        $this->assertSame(2, $user->item_quota, '新增一套搭配占一个衣架');
        $this->assertSame(1, $user->daily_quota);
    }

    /**
     * 场景：编辑已有搭配（改名 / 换衣物 / 重出封面）
     * 预期：**不占**衣架
     *
     * 为什么编辑不占（2026-09 用户把口径纠正回这里）：
     * 换衣物只是改字段；重出封面、换照片都是"换一张"——旧图由 push 末尾的 deleteByUrl 删掉，
     * 云端挂着的总数没变，成本没涨。所以只有"新增"占、"删除"还，账才是平的。
     */
    public function test_editing_outfit_name_only_does_not_consume(): void
    {
        $user = $this->userWith(2);
        $this->postJson('/api/clothes/sync', ['outfits' => [$this->outfitPayload('o1')]])->assertJson(['code' => 0]);
        $this->assertSame(1, $user->refresh()->item_quota);

        $edit = $this->outfitPayload('o1');
        $edit['name'] = '改个名字';
        $this->postJson('/api/clothes/sync', ['outfits' => [$edit]])->assertOk()->assertJson(['code' => 0]);

        $this->assertSame(1, $user->refresh()->item_quota, '只改名字不占');
    }

    /**
     * 场景：换衣物 + 重出封面（点「完成」时都会发生）
     * 预期：**不占** —— 旧封面会被删掉，云端总数没变（用户 2026-09 纠正的口径）
     */
    public function test_outfit_change_items_and_cover_does_not_consume(): void
    {
        $user = $this->userWith(3, 10);
        $first = $this->outfitPayload('o1', ['i1']);
        $first['coverUrl'] = 'https://oss.example.com/outfit/o1-cover-1.jpg';
        $this->postJson('/api/clothes/sync', ['outfits' => [$first]]);
        $this->assertSame(2, $user->refresh()->item_quota);

        // 换了衣物 + 又出一张新封面
        $edit = $this->outfitPayload('o1', ['i1', 'i2']);
        $edit['coverUrl'] = 'https://oss.example.com/outfit/o1-cover-2.jpg';
        $this->postJson('/api/clothes/sync', ['outfits' => [$edit]])->assertOk()->assertJson(['code' => 0]);

        $this->assertSame(2, $user->refresh()->item_quota, '编辑不占：旧的封面会被删掉，云端没多东西');
    }

    /**
     * 场景：后台同步把同一条搭配原样再推一遍（内容没变）
     * 预期：不占 —— 否则每次拉取/补传都扣一个衣架，人会炸
     */
    public function test_outfit_same_content_pushed_again_does_not_consume(): void
    {
        $user = $this->userWith(3, 10);
        $row = $this->outfitPayload('o1', ['i1']);
        $row['coverUrl'] = 'https://oss.example.com/outfit/o1-cover.jpg';

        $this->postJson('/api/clothes/sync', ['outfits' => [$row]]);
        $this->assertSame(2, $user->refresh()->item_quota);

        foreach (range(1, 3) as $i) {
            $this->postJson('/api/clothes/sync', ['outfits' => [$row]])->assertOk()->assertJson(['code' => 0]);
        }

        $this->assertSame(2, $user->refresh()->item_quota, '内容没变，推多少遍都不占');
    }

    /**
     * 场景：给已有衣物换照片 / 把同一张再推一遍
     * 预期：都不占 —— 换照片是"换一张"（旧图会被删），推同一张是内容没变
     */
    public function test_item_photo_change_does_not_consume(): void
    {
        $user = $this->userWith(3, 10);
        $first = $this->itemPayload('i1');
        $first['imageUrl'] = 'https://oss.example.com/clothes/i1-a.jpg';
        $this->postJson('/api/clothes/sync', ['items' => [$first]]);
        $this->assertSame(2, $user->refresh()->item_quota);

        $again = $this->itemPayload('i1');
        $again['imageUrl'] = 'https://oss.example.com/clothes/i1-a.jpg';   // 同一张再推一遍
        $this->postJson('/api/clothes/sync', ['items' => [$again]])->assertJson(['code' => 0]);
        $this->assertSame(2, $user->refresh()->item_quota, '照片没变不占');

        $again['imageUrl'] = 'https://oss.example.com/clothes/i1-b.jpg';   // 换了照片
        $this->postJson('/api/clothes/sync', ['items' => [$again]])->assertJson(['code' => 0]);
        $this->assertSame(2, $user->refresh()->item_quota, '换照片也不占：旧图会被删掉');
    }

    /**
     * 场景：删掉一件衣物
     * 预期：衣架还回总额（+1），**今日不回补**（每日是速率限制，不然"删了再加"能无限循环）
     */
    public function test_deleting_item_refunds_total_only(): void
    {
        $user = $this->userWith(3, 3);
        $this->postJson('/api/clothes/sync', ['items' => [$this->itemPayload('i1')]])->assertJson(['code' => 0]);
        $user->refresh();
        $this->assertSame(2, $user->item_quota);
        $this->assertSame(2, $user->daily_quota);

        $this->postJson('/api/clothes/sync', ['deleted' => ['items' => ['i1']]])
            ->assertOk()->assertJson(['code' => 0]);

        $user->refresh();
        $this->assertSame(3, $user->item_quota, '删掉衣物：衣架回到架子上');
        $this->assertSame(2, $user->daily_quota, '今日额度不回补（防"删了再加"无限循环）');
    }

    /**
     * 场景：删掉一套搭配 + 删一件衣物
     * 预期：各还一个衣架（总额 +2）
     */
    public function test_deleting_outfit_also_refunds(): void
    {
        $user = $this->userWith(4, 4);
        $this->postJson('/api/clothes/sync', [
            'items'   => [$this->itemPayload('i1')],
            'outfits' => [$this->outfitPayload('o1')],
        ])->assertOk()->assertJson(['code' => 0]);
        $this->assertSame(2, $user->refresh()->item_quota, '一件衣物 + 一套搭配 = 2 个衣架');

        $this->postJson('/api/clothes/sync', ['deleted' => ['items' => ['i1'], 'outfits' => ['o1']]])
            ->assertOk()->assertJson(['code' => 0]);

        $this->assertSame(4, $user->refresh()->item_quota, '两个衣架都回到架子上');
    }

    /**
     * 场景：同一条衣物被重复推「已删除」（客户端重发、或多端同步都会出现）
     * 预期：只还一次衣架 —— 不能靠重复删把衣架刷出来
     */
    public function test_repeated_delete_does_not_refund_twice(): void
    {
        $user = $this->userWith(3, 3);
        $this->postJson('/api/clothes/sync', ['items' => [$this->itemPayload('i1')]])->assertJson(['code' => 0]);

        $this->postJson('/api/clothes/sync', ['deleted' => ['items' => ['i1']]])->assertJson(['code' => 0]);
        $this->postJson('/api/clothes/sync', ['deleted' => ['items' => ['i1']]])->assertJson(['code' => 0]);
        $this->postJson('/api/clothes/sync', ['deleted' => ['items' => ['i1']]])->assertJson(['code' => 0]);

        $this->assertSame(3, $user->refresh()->item_quota, '重复删不叠加（每次只还真的从没删变已删的那一次）');
    }

    /**
     * 场景：**总额**衣架用完时，同一批里"删一件 + 加一件"
     * 预期：先还再扣，能顺利通过（这是"删除退还"最实际的用途）
     * 注意：今日衣架要留余地 —— 删除只退还总额，不退还今日（下一条用例专门钉这个口径）
     */
    public function test_delete_and_add_in_one_batch(): void
    {
        $user = $this->userWith(1, 10);
        $this->postJson('/api/clothes/sync', ['items' => [$this->itemPayload('i-old')]])->assertJson(['code' => 0]);
        $this->assertSame(0, $user->refresh()->item_quota, '先用掉最后一个衣架');

        $this->postJson('/api/clothes/sync', [
            'items'   => [$this->itemPayload('i-new')],
            'deleted' => ['items' => ['i-old']],
        ])->assertOk()->assertJson(['code' => 0]);

        $this->assertSame(0, $user->refresh()->item_quota, '删一个加一个：总额还是 0，没被拦');
        $this->assertSame(1, ClothesItem::where('user_id', $user->id)->count(), '新的那件写进去了');
    }

    /**
     * 场景：今天的衣架用完了，删掉一件再加一件
     * 预期：仍然被拦（4002）—— 每日是"今天最多挂几个"的速率限制，
     * 删了不回补，否则「加满 → 全删 → 再加满」可以无限循环，限制形同虚设
     */
    public function test_delete_does_not_refund_daily_so_no_unlimited_loop(): void
    {
        $user = $this->userWith(50, 1);
        $this->postJson('/api/clothes/sync', ['items' => [$this->itemPayload('i-old')]])->assertJson(['code' => 0]);
        $this->assertSame(0, $user->refresh()->daily_quota, '今天的衣架用完了');

        $res = $this->postJson('/api/clothes/sync', [
            'items'   => [$this->itemPayload('i-new')],
            'deleted' => ['items' => ['i-old']],
        ]);
        $res->assertOk()->assertJson(['code' => 4002]);
        $this->assertStringContainsString('今天的衣架用完了', $res->json('msg'));

        $user->refresh();
        $this->assertSame(49, $user->item_quota, '整批回滚：连"还衣架"也一起回滚，总额保持原样');
        $this->assertSame(0, $user->daily_quota, '今日不还（防"删了再加"无限循环）');
        $this->assertSame(1, ClothesItem::where('user_id', $user->id)->count(), '被拦下时那件旧的也没被删掉');
    }

    /**
     * 场景：一批里同时新增衣物和搭配，衣架不够
     * 预期：整批拦下（code=4001），什么都不写 —— 不做"写一半"
     */
    public function test_batch_items_and_outfits_share_one_pool(): void
    {
        $user = $this->userWith(1, 10);

        $res = $this->postJson('/api/clothes/sync', [
            'items'   => [$this->itemPayload('i1')],
            'outfits' => [$this->outfitPayload('o1')],
        ]);
        $res->assertOk()->assertJson(['code' => 4001]);

        $user->refresh();
        $this->assertSame(1, $user->item_quota, '拦下了就不该扣');
        $this->assertSame(0, ClothesItem::where('user_id', $user->id)->count());
        $this->assertSame(0, \App\Models\ClothesOutfit::where('user_id', $user->id)->count());
    }

    /**
     * 场景：删过的衣物再推上来（复活）
     * 预期：重新占一个衣架（删的时候还回去了，一进一出抵平）
     */
    public function test_resurrected_item_occupies_hanger_again(): void
    {
        $user = $this->userWith(3, 10);
        $this->postJson('/api/clothes/sync', ['items' => [$this->itemPayload('i1')]])->assertJson(['code' => 0]);
        $this->postJson('/api/clothes/sync', ['deleted' => ['items' => ['i1']]])->assertJson(['code' => 0]);
        $this->assertSame(3, $user->refresh()->item_quota, '删完回到 3');

        $this->postJson('/api/clothes/sync', ['items' => [$this->itemPayload('i1')]])->assertJson(['code' => 0]);
        $this->assertSame(2, $user->refresh()->item_quota, '复活要重新占一个衣架');
    }

    /**
     * 场景：「我的」页顶部那块衣架卡片读的接口
     * 预期：出参有衣架口径（今日/总共 + 分母），**旧字段也还在**（老版本小程序照样能读）；
     *       分母 = 余额 + 已占用，所以挂了几件之后分母还是建号送的那个数
     */
    public function test_quota_endpoint_exposes_hanger_fields(): void
    {
        $free = (int) config('quota.item_quota');
        $user = $this->userWith($free, 3);

        // 挂 3 件衣物：余额 100 → 97，占用 3
        foreach (['i1', 'i2', 'i3'] as $id) {
            $this->postJson('/api/clothes/sync', ['items' => [$this->itemPayload($id)]])
                ->assertJson(['code' => 0]);
        }

        $data = $this->quotaOf($user);
        $res  = $this->getJson('/api/user/quota');   // 再读一次原始响应，验证旧字段也在

        $res->assertOk()->assertJson(['code' => 0]);
        $this->assertSame($free - 3, $data['hangerTotal'], '可用 = 余额');
        $this->assertSame(0, $data['hangerDaily']);
        $this->assertSame($free, $data['hangerTotalLimit'], '分母 = 余额 + 已占用 = 100');
        $this->assertSame((int) config('quota.daily_quota'), $data['hangerDailyLimit']);
        // 兼容旧字段
        $res->assertJsonPath('data.itemQuota', $free - 3);
        $res->assertJsonPath('data.dailyQuota', 0);
    }

    /**
     * 场景：分母 = 账号总资产（余额 + 已占用）
     * 预期：挂衣物、挂搭配、删掉，**分母一直不变**（只有左边那个"可用"在动）
     *       —— 这就是 2026-09 用户改这一版的起因：别显示成「162 / 162」两个数一起减
     */
    public function test_total_limit_does_not_drop_when_adding(): void
    {
        $free = (int) config('quota.item_quota');
        $user = $this->userWith($free, 10);

        $q1 = $this->quotaOf($user);
        $this->assertSame($free, $q1['hangerTotal']);
        $this->assertSame($free, $q1['hangerTotalLimit'], '还没挂东西：可用 = 总数');

        $this->postJson('/api/clothes/sync', ['items' => [$this->itemPayload('i1')]])->assertJson(['code' => 0]);
        $q2 = $this->quotaOf($user);
        $this->assertSame($free - 1, $q2['hangerTotal'], '可用 -1');
        $this->assertSame($free, $q2['hangerTotalLimit'], '总数不变（两个数不再一起减）');

        // 搭配也占一个衣架，分母照样不动
        $this->postJson('/api/clothes/sync', ['outfits' => [$this->outfitPayload('o1')]])->assertJson(['code' => 0]);
        $q3 = $this->quotaOf($user);
        $this->assertSame($free - 2, $q3['hangerTotal']);
        $this->assertSame($free, $q3['hangerTotalLimit']);

        // 删掉一件：余额还回来，分母还是不变（余额 +1、占用 -1）
        $this->postJson('/api/clothes/sync', ['deleted' => ['items' => ['i1']]])->assertJson(['code' => 0]);
        $q4 = $this->quotaOf($user);
        $this->assertSame($free - 1, $q4['hangerTotal'], '删掉：可用 +1');
        $this->assertSame($free, $q4['hangerTotalLimit'], '总数还是不变');
    }

    /**
     * 场景：客服在后台把衣架余额加过（开会员）
     * 预期：分母 = 余额 + 已占用，加量后分母跟着涨，不会出现「102 / 100」这种怪数
     */
    public function test_total_limit_follows_topped_up_balance(): void
    {
        // 建号送 100，客服又加了 300 → 这个账号一共 400 个衣架；先挂 2 件
        $over = (int) config('quota.item_quota') + 300;
        $user = $this->userWith($over, 10);

        foreach (['i1', 'i2'] as $id) {
            $this->postJson('/api/clothes/sync', ['items' => [$this->itemPayload($id)]])->assertJson(['code' => 0]);
        }

        $data = $this->quotaOf($user);
        $this->assertSame($over - 2, $data['hangerTotal'], '余额减了 2');
        $this->assertSame($over, $data['hangerTotalLimit'], '分母 = 余额 + 已占用，还是 400');
    }

    /**
     * 每天重置命令：把「今天还没重置过」的人的今日额度重置回默认值，总额度不动
     *
     * 预期：a（昨天的日期）回满 100；b（日期已是今天，说明已被惰性重置过）**命令不碰它**，
     *       保持它当前的值；两人的 item_quota 各自不变
     */
    public function test_reset_daily_command(): void
    {
        $a = User::factory()->create(['item_quota' => 7, 'daily_quota' => 0, 'daily_reset_date' => today()->subDay()->toDateString()]);
        $b = User::factory()->create(['item_quota' => 3, 'daily_quota' => 1, 'daily_reset_date' => today()->toDateString()]);

        $this->artisan('quota:reset-daily')
            ->assertSuccessful();

        $full = (int) config('quota.daily_quota');
        $this->assertSame($full, $a->refresh()->daily_quota);
        // 命令只挑 daily_reset_date != 今天 的人（今天已重置过的再刷一遍没有意义，也会把用户今天用掉的额度又还回去）
        $this->assertSame(1, $b->refresh()->daily_quota, '今天已重置过的不再重刷');
        $this->assertSame(7, $a->item_quota, '总额度是用户的资产，重置每日额度不能动它');
        $this->assertSame(3, $b->item_quota);
    }
}
