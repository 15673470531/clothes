<?php

namespace Tests\Feature;

use App\Models\HangerReward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 赚衣架测试（2026-09：每日签到 +1 / 分享群或好友 +2 / 新用户每月免费领取 +50）
 *
 * 背景：「我的」页衣架卡右侧的「获取更多」进的是一个真能干活的页面 ——
 * 每天签到领 1 个衣架、分享给群或好友领 2 个（每天各 1 次），
 * 另外每月可以自己点一下「领取」拿 50 个（新用户每月免费领取）。
 * 被测：App\Services\HangerReward + HangerRewardController 的几个接口。
 *
 * 口径：
 *   - 奖励**加到总额**（users.item_quota），不动今日额度
 *   - **受衣架总数上限约束**（config('quota.item_max')，200）：到顶就不再累加，
 *     提示语会说"已到上限"；免费额度 item_quota（100）只是进货量，不是天花板
 *   - 数字（+1 / +2 / +50 / 每天几次）**只从 config('quota.reward_*') 读**，用例里改配置验证；
 *     用例断言也一律读配置（改数字不用回来改测试）
 *   - 同一天/同一月同一种奖励领过就 4008，余额不动、流水不重复
 *
 * 注意：用例里把余额和配置都设小，不造真实数据。
 *
 * 2026-09 用户改过口径：原来有个「每月系统赠送」（按月自动到账、不用点），
 * 现在页面上用的是「新用户每月免费领取」（按月 1 次，但**要用户点一下**）。
 * 老口径的配置默认 0（停用）但代码还在，所以它那几条用例也留着 —— 万一要退回自动发，有一层保护。
 */
class HangerRewardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 造一个余额可控的用户并登录
     *
     * 默认**关掉老口径的每月系统赠送**：它会在拉额度/进赚衣架页时自动 +50，干扰签到/分享的余额断言。
     * 每月赠送的用例自己把配置设回来再断言。
     * （新的「新用户每月免费领取」是手动的，不进页面就领不到，不会干扰断言）
     */
    private function userWith(int $itemQuota = 3, int $dailyQuota = 5): User
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
     * 场景：进「获取更多」页先问一次状态
     *
     * 预期：签到/分享都能领、各给几个由后端下发（默认 2 / 4），并带上最新衣架余额
     */
    public function test_status_reports_today_state(): void
    {
        $this->userWith(3);

        $res = $this->getJson('/api/hanger/reward')->assertOk()->assertJson(['code' => 0]);

        $data = $res->json('data');
        $this->assertSame((int) config('quota.reward_checkin'), $data['checkin']['amount'], '签到一次几个（来自配置）');
        $this->assertSame((int) config('quota.reward_share'), $data['share']['amount'], '分享一次几个（来自配置）');
        $this->assertTrue($data['checkin']['canClaim'], '还没签到 → 能领');
        $this->assertTrue($data['share']['canClaim'], '还没分享 → 能领');
        $this->assertSame(1, $data['checkin']['leftToday'], '每天各 1 次');
        $this->assertSame(3, $data['hanger']['hangerTotal'], '带上最新衣架余额');
    }

    /**
     * 场景：每日签到
     *
     * 预期：总额加上签到那点衣架，**今日额度一个都不动**（奖励是资产，不是当日速率）
     */
    public function test_checkin_adds_total_and_keeps_daily(): void
    {
        $checkin = (int) config('quota.reward_checkin');
        $user = $this->userWith(3, 5);

        $res = $this->postJson('/api/hanger/checkin')->assertOk()->assertJson(['code' => 0]);

        $user->refresh();
        $this->assertSame(3 + $checkin, $user->item_quota, '签到加到总额：3 + ' . $checkin);
        $this->assertSame(5, $user->daily_quota, '今日额度不受影响');
        $this->assertSame(3 + $checkin, $res->json('data.hanger.hangerTotal'), '出参直接带最新余额（前端不用再问一次）');
        $this->assertFalse($res->json('data.checkin.canClaim'), '领完就领不了了');
        $this->assertSame(1, HangerReward::query()->where('type', 'checkin')->count(), '只记一条流水');
    }

    /**
     * 场景：同一天连点两次签到
     *
     * 预期：第二次 code=4008、余额不再增加、流水还是一条（防重复领）
     */
    public function test_checkin_twice_is_blocked(): void
    {
        $user = $this->userWith(3);
        $checkin = (int) config('quota.reward_checkin');

        $this->postJson('/api/hanger/checkin')->assertOk()->assertJson(['code' => 0]);
        $this->postJson('/api/hanger/checkin')->assertOk()
            ->assertJson(['code' => 4008])
            ->assertJsonPath('msg', '今天已经签到过了，明天再来');

        $user->refresh();
        $this->assertSame(3 + $checkin, $user->item_quota, '第二次不再加');
        $this->assertSame(1, HangerReward::query()->where('type', 'checkin')->count(), '流水不重复');
    }

    /**
     * 场景：分享群或者好友
     *
     * 预期：第一次能领到（跟签到互不影响，同一天两个都能领）；第二次 4008
     */
    public function test_share_adds_hanger_once_a_day(): void
    {
        $user = $this->userWith(3, 5);

        $share = (int) config('quota.reward_share');
        $res = $this->postJson('/api/hanger/share')->assertOk()->assertJson(['code' => 0]);
        $this->assertSame(3 + $share, $res->json('data.hanger.hangerTotal'), '分享加' . $share . '个');

        $this->postJson('/api/hanger/share')->assertOk()->assertJson(['code' => 4008]);

        $user->refresh();
        $this->assertSame(3 + $share, $user->item_quota, '重复分享不再加');
        $this->assertSame(0, HangerReward::query()->where('type', 'checkin')->count(), '分享不会顺带把签到也领了');
    }

    /**
     * 场景：同一天签到之后再分享
     *
     * 预期：两个都能领到（一天最多 签到 + 分享 两笔）
     */
    public function test_checkin_and_share_are_independent(): void
    {
        $user = $this->userWith(0);

        $this->postJson('/api/hanger/checkin')->assertJson(['code' => 0]);
        $res = $this->postJson('/api/hanger/share')->assertJson(['code' => 0]);

        $this->assertSame(
            (int) config('quota.reward_checkin') + (int) config('quota.reward_share'),
            $res->json('data.hanger.hangerTotal'),
            '签到 + 分享'
        );
        $user->refresh();
        $this->assertSame((int) config('quota.reward_checkin') + (int) config('quota.reward_share'), $user->item_quota);
    }

    /**
     * 场景：余额已经到免费额度（config 里的 100）时还能不能签到
     *
     * 预期：能，余额变成 102 —— 奖励**不受免费总量上限约束**（上限只是"免费进货量"）；
     *       分母 = 余额 + 已占用，所以也跟着变成 102，不会出现「102 / 100」这种怪数
     */
    public function test_reward_can_exceed_free_limit(): void
    {
        $limit = (int) config('quota.item_quota');
        $user = $this->userWith($limit);

        $checkin = (int) config('quota.reward_checkin');
        $res = $this->postJson('/api/hanger/checkin')->assertOk()->assertJson(['code' => 0]);

        $user->refresh();
        $this->assertSame($limit + $checkin, $user->item_quota, '免费额度不是天花板，照加');
        $this->assertSame($limit + $checkin, $res->json('data.hanger.hangerTotalLimit'), '分母 = 余额 + 已占用');
    }

    /**
     * 场景：奖励数字改了（后端改配置）
     *
     * 预期：接口下发的数字和实际加的数都跟着变，小程序一个字不用改
     */
    public function test_amounts_come_from_config(): void
    {
        config(['quota.reward_checkin' => 7, 'quota.reward_share' => 20]);
        $user = $this->userWith(0);

        $status = $this->getJson('/api/hanger/reward')->json('data');
        $this->assertSame(7, $status['checkin']['amount'], '下发读配置');
        $this->assertSame(20, $status['share']['amount']);

        $res = $this->postJson('/api/hanger/checkin')->assertJson(['code' => 0]);
        $this->assertSame(7, $res->json('data.hanger.hangerTotal'), '实际加的数也读配置');
        $user->refresh();
        $this->assertSame(7, $user->item_quota);
    }

    /**
     * 场景：把"每天几次"改大（以后想把分享做成每天 3 次，只改配置不发版）
     *
     * 预期：能连领 3 次，第 4 次才 4008
     */
    public function test_daily_times_come_from_config(): void
    {
        config(['quota.reward_share_daily' => 3]);
        $user = $this->userWith(0);

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/hanger/share')->assertOk()->assertJson(['code' => 0]);
        }
        $this->postJson('/api/hanger/share')->assertOk()->assertJson(['code' => 4008]);

        $user->refresh();
        $this->assertSame(3 * (int) config('quota.reward_share'), $user->item_quota, '3 次 × 每次几个');
        $this->assertSame(3, HangerReward::query()->where('type', 'share')->count(), '三条流水（seq 1/2/3）');
    }

    /**
     * 场景：把奖励关掉（数字设成 0，比如活动下线）
     *
     * 预期：code=4009「这个奖励暂时没开」，余额不动
     */
    public function test_reward_closed_when_config_is_zero(): void
    {
        config(['quota.reward_checkin' => 0]);
        $user = $this->userWith(3);

        $this->postJson('/api/hanger/checkin')->assertOk()
            ->assertJson(['code' => 4009])
            ->assertJsonPath('msg', '这个奖励暂时没开');

        $user->refresh();
        $this->assertSame(3, $user->item_quota, '关掉就不给');
    }

    /**
     * 场景：没登录调这三个接口
     *
     * 预期：401（都挂在 auth:sanctum 下面，签到领衣架不能匿名刷）
     */
    public function test_requires_login(): void
    {
        $this->getJson('/api/hanger/reward')->assertUnauthorized();
        $this->postJson('/api/hanger/checkin')->assertUnauthorized();
        $this->postJson('/api/hanger/share')->assertUnauthorized();
    }

    /**
     * 场景：昨天领过、今天再领
     *
     * 预期：能领（按日期算，不是"领过一次就永远领不了"）
     */
    public function test_can_claim_again_next_day(): void
    {
        $checkin = (int) config('quota.reward_checkin');
        $user = $this->userWith(0);

        // 昨天的流水（直接造一条，跳过"今天"的限制）
        HangerReward::create([
            'user_id'     => $user->id,
            'type'        => 'checkin',
            'reward_date' => today()->subDay()->toDateString(),
            'seq'         => 1,
            'amount'      => 2,   // 历史数字，跟现在配置几个无关
        ]);

        $this->postJson('/api/hanger/checkin')->assertOk()->assertJson(['code' => 0]);

        $user->refresh();
        $this->assertSame($checkin, $user->item_quota, '今天照样能领');
    }

    /* ------------------------------------------------------------------
     * 老口径：「每月系统赠送」（自动到账、不用点）
     * 2026-09 已被「新用户每月免费领取」取代，配置默认 0 = 停用。
     * 代码和下面这几条用例都留着，万一要退回自动发还有保护。
     * ------------------------------------------------------------------ */

    /**
     * 场景：每月系统赠送（老口径）
     *
     * 预期：打开「我的」页拉额度时**自动到账**（惰性发，不依赖定时任务），
     *       再拉一次不会重复给；流水记在本月 1 号
     */
    public function test_monthly_grant_is_automatic_and_once(): void
    {
        $user = $this->userWith(3, 5);
        config(['quota.reward_monthly' => 50]);       // userWith 默认关掉，这里显式打开
        $amount = 50;

        $this->getJson('/api/user/quota')->assertOk()->assertJson(['code' => 0]);
        $user->refresh();
        $this->assertSame(3 + $amount, $user->item_quota, '拉额度时自动送本月赠送');
        $this->assertSame(5, $user->daily_quota, '只加总额，今日额度不动');

        // 再拉一次：同一自然月里只发一次
        $this->getJson('/api/user/quota')->assertOk();
        $user->refresh();
        $this->assertSame(3 + $amount, $user->item_quota, '同月不重复发');

        $row = HangerReward::query()->where('type', 'monthly')->first();
        $this->assertSame(today()->startOfMonth()->toDateString(), $row->reward_date->toDateString(), '记在本月 1 号');
        $this->assertSame(1, HangerReward::query()->where('type', 'monthly')->count(), '一个月只有一条流水');
    }

    /**
     * 场景：赚衣架页读状态（GET /api/hanger/reward）
     *
     * 预期：也会顺手补发本月赠送；那一行的标题/说明/状态由后端拼好下发
     */
    public function test_monthly_row_is_backend_composed(): void
    {
        $user = $this->userWith(0);
        config(['quota.reward_monthly' => 50]);       // userWith 默认关掉，这里显式打开
        $amount = 50;

        $d = $this->getJson('/api/hanger/reward')->assertOk()->json('data');

        $user->refresh();
        $this->assertSame($amount, $user->item_quota, '进赚衣架页也会自动到账');
        $this->assertTrue($d['monthly']['granted'], '标记已发');
        $this->assertSame($amount, $d['monthly']['amount']);
        $this->assertSame('month', $d['monthly']['period'], '周期是月');
        $this->assertSame('每月系统赠送', $d['monthly']['title'], '标题后端给');
        $this->assertSame('每个月自动送 +' . $amount . ' 个衣架，不用领', $d['monthly']['desc'], '说明里数字后端拼');
        $this->assertSame('本月已到账', $d['monthly']['stateText'], '状态文字后端给');
    }

    /**
     * 场景：上个月发过、这个月第一次打开
     *
     * 预期：能再发一次（按自然月算，不是"发过就永远没有"）
     */
    public function test_monthly_grants_again_in_a_new_month(): void
    {
        $user = $this->userWith(0);
        config(['quota.reward_monthly' => 50]);       // userWith 默认关掉，这里显式打开
        $amount = 50;

        // 上月 1 号的流水
        HangerReward::create([
            'user_id'     => $user->id,
            'type'        => 'monthly',
            'reward_date' => today()->startOfMonth()->subMonth()->toDateString(),
            'seq'         => 1,
            'amount'      => $amount,
        ]);

        $this->getJson('/api/user/quota')->assertOk();
        $user->refresh();
        $this->assertSame($amount, $user->item_quota, '新的一月照发');
        $this->assertSame(2, HangerReward::query()->where('type', 'monthly')->count(), '两个月两条流水');
    }

    /**
     * 场景：把每月赠送关掉（配置 0）
     *
     * 预期：不送，接口出参里那一行是"没开"的状态（前端据此不显示这一行）
     */
    public function test_monthly_can_be_turned_off(): void
    {
        config(['quota.reward_monthly' => 0]);
        $user = $this->userWith(3);

        $d = $this->getJson('/api/hanger/reward')->assertOk()->json('data');
        $user->refresh();

        $this->assertSame(3, $user->item_quota, '关掉就不送');
        $this->assertSame(0, $d['monthly']['amount']);
        $this->assertFalse($d['monthly']['granted']);
        $this->assertSame('', $d['monthly']['stateText']);
        $this->assertSame(0, HangerReward::query()->where('type', 'monthly')->count(), '不落流水');
    }

    /**
     * 场景：每月赠送的数字改了（后端改配置）
     *
     * 预期：送出去的数和那一行文案里的数字都跟着变，小程序一个字不用改
     */
    public function test_monthly_amount_comes_from_config(): void
    {
        // 注意顺序：userWith 里会把每月赠送关掉，所以配置必须在它之后设
        $user = $this->userWith(0);
        config(['quota.reward_monthly' => 80]);

        $d = $this->getJson('/api/hanger/reward')->assertOk()->json('data');
        $user->refresh();

        $this->assertSame(80, $user->item_quota, '按配置送 80');
        $this->assertSame('每个月自动送 +80 个衣架，不用领', $d['monthly']['desc'], '文案里的数字也跟着变');
    }

    /* ------------------------------------------------------------------
     * 现在的口径：「新用户每月免费领取」（每月 1 次，**点了才给**）
     * 2026-09 用户把"系统每月自动送"改成"用户每月点一下领"，取代了上面那套。
     * ------------------------------------------------------------------ */

    /**
     * 场景：进赚衣架页看「新用户每月免费领取」那一行
     *
     * 预期：标题/说明/按钮/周期都由后端下发；还没领 → canClaim=true、没有状态文字；
     *       **光进页面不加衣架**（跟老口径最大的区别：老口径一进页面就自动到账）
     */
    public function test_newcomer_row_is_backend_composed(): void
    {
        $user = $this->userWith(0);

        $d = $this->getJson('/api/hanger/reward')->assertOk()->json('data');

        $this->assertSame(50, $d['newcomer']['amount'], '一次 50（来自配置）');
        $this->assertSame('month', $d['newcomer']['period'], '周期是月');
        $this->assertTrue($d['newcomer']['canClaim'], '还没领 → 能领');
        $this->assertFalse($d['newcomer']['granted']);
        $this->assertSame('新用户每月免费领取', $d['newcomer']['title'], '标题后端给');
        $this->assertSame('每月免费领 +50 个衣架，点一下就到账', $d['newcomer']['desc'], '说明里的数字后端拼');
        $this->assertSame('领取', $d['newcomer']['btnText'], '按钮文字后端给');
        $this->assertSame('', $d['newcomer']['stateText'], '还没领 → 没有状态文字');

        $user->refresh();
        $this->assertSame(0, $user->item_quota, '不点就不给：光进页面不加衣架');
    }

    /**
     * 场景：点「领取」
     *
     * 预期：总额 +50（今日额度一个都不动）、流水一条、那一行变成"本月已领取"、toast 由后端拼
     */
    public function test_newcomer_claim_adds_hanger_once_a_month(): void
    {
        $user = $this->userWith(3, 5);

        $res = $this->postJson('/api/hanger/newcomer')->assertOk()->assertJson(['code' => 0]);

        $user->refresh();
        $this->assertSame(53, $user->item_quota, '领取 +50 加到总额');
        $this->assertSame(5, $user->daily_quota, '今日额度不动');
        $this->assertSame(1, HangerReward::query()->where('type', 'newcomer')->count(), '一条流水');

        $d = $res->json('data');
        $this->assertTrue($d['newcomer']['granted']);
        $this->assertFalse($d['newcomer']['canClaim'], '这个月不能再领');
        $this->assertSame('本月已领取', $d['newcomer']['stateText']);
        $this->assertSame('领取成功 +50 个衣架', $d['toast'], '提示语后端拼好');
    }

    /**
     * 场景：这个月再点一次
     *
     * 预期：4008，余额和流水都不变（防重复领）
     */
    public function test_newcomer_claim_twice_is_blocked(): void
    {
        $user = $this->userWith(0);

        $this->postJson('/api/hanger/newcomer')->assertOk()->assertJson(['code' => 0]);
        $this->postJson('/api/hanger/newcomer')->assertOk()
            ->assertJson(['code' => 4008])
            ->assertJsonPath('msg', '这个月的免费衣架已经领过了，下个月再来');

        $user->refresh();
        $this->assertSame(50, $user->item_quota, '第二次不再加');
        $this->assertSame(1, HangerReward::query()->where('type', 'newcomer')->count(), '流水不重复');
    }

    /**
     * 场景：上个月领过、这个月第一次打开
     *
     * 预期：按钮又是「领取」（按月算，不是"领过就永远没有"）
     */
    public function test_newcomer_can_claim_again_in_a_new_month(): void
    {
        $user = $this->userWith(0);

        // 上月领过的流水（直接造一条，跳过"本月"的限制）
        HangerReward::create([
            'user_id'     => $user->id,
            'type'        => 'newcomer',
            'reward_date' => today()->startOfMonth()->subMonth()->toDateString(),
            'seq'         => 1,
            'amount'      => 50,
        ]);

        $d = $this->getJson('/api/hanger/reward')->assertOk()->json('data');
        $this->assertTrue($d['newcomer']['canClaim'], '新的一月又能领');

        $this->postJson('/api/hanger/newcomer')->assertOk()->assertJson(['code' => 0]);
        $user->refresh();
        $this->assertSame(50, $user->item_quota, '新的一月再发一次');
        $this->assertSame(2, HangerReward::query()->where('type', 'newcomer')->count(), '两个月两条流水');
    }

    /**
     * 场景：把这一行关掉（配置 0）
     *
     * 预期：接口不显示这一行（amount=0、没有标题 → 前端不渲染），点了回 4009，也不落流水
     */
    public function test_newcomer_can_be_turned_off(): void
    {
        config(['quota.reward_newcomer' => 0]);
        $user = $this->userWith(3);

        $d = $this->getJson('/api/hanger/reward')->assertOk()->json('data');

        $this->assertSame(0, $d['newcomer']['amount']);
        $this->assertSame('', $d['newcomer']['title'], '没标题 → 小程序不显示这一行');
        $this->assertFalse($d['newcomer']['canClaim']);

        $this->postJson('/api/hanger/newcomer')->assertOk()
            ->assertJson(['code' => 4009])
            ->assertJsonPath('msg', '这个奖励暂时没开');

        $user->refresh();
        $this->assertSame(3, $user->item_quota, '关掉就不给');
        $this->assertSame(0, HangerReward::query()->where('type', 'newcomer')->count(), '不落流水');
    }

    /**
     * 场景：数字改了（后端改配置）
     *
     * 预期：发的数和那一行文案里的数字都跟着变，小程序一个字不用改
     */
    public function test_newcomer_amount_comes_from_config(): void
    {
        config(['quota.reward_newcomer' => 80]);
        $user = $this->userWith(0);

        $d = $this->getJson('/api/hanger/reward')->assertOk()->json('data');
        $this->assertSame(80, $d['newcomer']['amount']);
        $this->assertSame('每月免费领 +80 个衣架，点一下就到账', $d['newcomer']['desc'], '文案里的数字也跟着变');

        $this->postJson('/api/hanger/newcomer')->assertOk()->assertJson(['code' => 0]);
        $user->refresh();
        $this->assertSame(80, $user->item_quota, '按配置发 80');
    }

    /**
     * 场景：没登录就点领取
     *
     * 预期：401（挂在 auth:sanctum 下面，不能匿名刷衣架）
     */
    public function test_newcomer_requires_login(): void
    {
        $this->postJson('/api/hanger/newcomer')->assertUnauthorized();
    }

    /* ------------------------------------------------------------------
     * 衣架总数上限（config('quota.item_max')，2026-09 用户定：200）
     * 奖励加到 200 就不再累加；退款（删掉东西还回来）不受它管
     * ------------------------------------------------------------------ */

    /**
     * 场景：余额已经顶到上限（200）时又去签到
     *
     * 预期：接口照样 code=0、也照样记流水（今天这一次就用掉了），但**余额一个都不加**，
     *       提示语换成"已到上限"那句 —— 不能骗用户说 +2
     */
    public function test_reward_stops_at_total_cap(): void
    {
        $max = (int) config('quota.item_max');
        $user = $this->userWith($max, 10);

        $res = $this->postJson('/api/hanger/checkin')->assertOk()->assertJson(['code' => 0]);

        $user->refresh();
        $this->assertSame($max, $user->item_quota, '到顶就不加了');
        $this->assertSame('衣架已经到上限 ' . $max . ' 个了，这次的先不累加', $res->json('data.toast'));
        $this->assertSame(1, HangerReward::query()->where('type', 'checkin')->count(), '流水照记（今天这条已经用掉了）');
        $this->assertSame($max, $res->json('data.hanger.hangerTotal'), '出参里的余额也是上限值');
    }

    /**
     * 场景：快满时领奖励（余额 197、分享一次 4 个）
     *
     * 预期：只加"还装得下的"那部分（+3 到 200），提示语报的是**实际加到的 3** 而不是配置里的 4
     */
    public function test_reward_partially_added_when_near_cap(): void
    {
        $max = (int) config('quota.item_max');
        $share = (int) config('quota.reward_share');
        $user = $this->userWith($max - $share + 1, 10);   // 只差 1 个到顶

        $res = $this->postJson('/api/hanger/share')->assertOk()->assertJson(['code' => 0]);

        $user->refresh();
        $this->assertSame($max, $user->item_quota, '只装得下 1 个');
        $this->assertSame('分享成功 +1 个衣架', $res->json('data.toast'), '提示语报实际加到的数');
    }

    /**
     * 场景：上限管不管"删掉东西退还"
     *
     * 预期：不管 —— 余额顶到 200、挂一件变 199，删掉又回到 200（那是用户自己的东西，不是白赚）
     */
    public function test_cap_does_not_block_refund(): void
    {
        $max = (int) config('quota.item_max');
        $user = $this->userWith($max, 10);

        $this->postJson('/api/clothes/sync', ['items' => [
            ['id' => 'i1', 'name' => '测试衣物', 'category' => 'top', 'sub' => '', 'colors' => [],
             'seasons' => [], 'occasions' => [], 'imageUrl' => '', 'createdAt' => 1758000000000],
        ]])->assertJson(['code' => 0]);
        $this->assertSame($max - 1, $user->refresh()->item_quota, '挂一件占掉 1 个');

        $this->postJson('/api/clothes/sync', ['deleted' => ['items' => ['i1']]])->assertJson(['code' => 0]);
        $this->assertSame($max, $user->refresh()->item_quota, '删掉还回来，不看上限');
    }
}
