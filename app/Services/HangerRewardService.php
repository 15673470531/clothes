<?php

namespace App\Services;

use App\Exceptions\HangerRewardException;
use App\Models\HangerReward as HangerRewardLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 赚衣架（2026-09 用户定：每日签到 +1、分享群或好友 +2、新用户每月免费领取 +50）
 *
 * 「我的」页衣架卡右侧的「获取更多」进的就是这里。不做"功能介绍"那种没用的页，
 * 直接给真能拿到衣架的动作。
 *
 * 三条口径：
 *   1 **奖励加到总额**（users.item_quota），不动今日额度 —— 赚来的是资产
 *   2 **受衣架总数上限约束**（config('quota.item_max')，2026-09 用户定 200）：
 *      余额到 200 就不再累加，少给的部分直接丢（提示语会说"已到上限"，不会报虚数）；
 *      免费额度（config('quota.item_quota')）只是"免费进货量"，不是天花板
 *   3 数字（+1 / +2 / +50 / 每天几次）**只从 config('quota.reward_*') 来**，
 *      文案只从文案表（app/Services/Texts.php 或 storage/app/texts.json）来，
 *      改这两样都不用发小程序版本
 *
 * 周期（2026-09 抽出来的）：
 *   - day   ：按自然日，一个人一天一条流水（签到、分享）
 *   - month ：按自然月，一个人一个月一条流水（新用户每月免费领取、老口径的每月赠送）
 *   流水的 reward_date 分别记"今天"和"本月 1 号"，配合 hanger_rewards 上的
 *   unique(user_id, type, reward_date, seq) —— 同一天/同一月只可能有一条。
 *
 * 发放方式（2026-09 用户改过口径，这里是"现在的"）：
 *   - 签到 / 分享 / **新用户每月免费领取**：用户主动点（POST）才发 —— 页面上的三个按钮都走 claim()
 *   - 每月系统赠送（老口径，已停用）：惰性自动发，见 grantMonthly()
 *     2026-09 用户把"系统每月自动送"改成"用户每月点一下领"，所以它默认配置 0（关掉）；
 *     代码留着，把它设成 >0 就能退回自动发（唯一的调用点是拉额度/进赚衣架页那两个接口）
 *
 * 分享为什么"点一下就算"：微信从 2021 年起不再返回"是否真的转发成功"，
 * 小程序拿不到结果，所以只能按"点了分享按钮"发奖励（用户 2026-09 选的口径）。
 */
class HangerRewardService
{
    /**
     * 每种奖励：一次给几个（config key）/ 一个周期能领几次 / 周期
     *
     * times 写成整数就是不从配置读（按月的一次性奖励固定一个月一次）
     */
    private const RULES = [
        HangerRewardLog::TYPE_CHECKIN  => ['amount' => 'quota.reward_checkin',  'times' => 'quota.reward_checkin_daily', 'period' => 'day'],
        HangerRewardLog::TYPE_SHARE    => ['amount' => 'quota.reward_share',    'times' => 'quota.reward_share_daily',   'period' => 'day'],
        // 新用户每月免费领取：按月 1 次，但**要点**（用户 2026-09 把"自动送"改成"手动领"）
        HangerRewardLog::TYPE_NEWCOMER => ['amount' => 'quota.reward_newcomer', 'times' => 1,                            'period' => 'month'],
        // 老口径的每月系统赠送：配置默认 0（停用），留着退路
        HangerRewardLog::TYPE_MONTHLY  => ['amount' => 'quota.reward_monthly',  'times' => 1,                            'period' => 'month'],
    ];

    /** 领不到时的说明文案（给用户看的，直接透给前端） */
    private const ALREADY_MSG = [
        HangerRewardLog::TYPE_CHECKIN  => '今天已经签到过了，明天再来',
        HangerRewardLog::TYPE_SHARE    => '今天的分享奖励已经领过了，明天再来',
        HangerRewardLog::TYPE_NEWCOMER => '这个月的免费衣架已经领过了，下个月再来',
        HangerRewardLog::TYPE_MONTHLY  => '本月的赠送已经到账了',
    ];

    public function __construct(
        private readonly Quota $quota,
        private readonly Texts $texts
    ) {
    }

    /** 这种奖励一次给几个 */
    public function amountOf(string $type): int
    {
        return $this->rule($type)['amount'];
    }

    /** 这种奖励一个周期最多领几次 */
    public function timesOf(string $type): int
    {
        return $this->rule($type)['times'];
    }

    /**
     * 每月系统赠送（老口径：没发过就补发，惰性、不用点）
     *
     * **2026-09 已被「新用户每月免费领取」取代**：用户定的新口径是"每月自己点一下领取"，
     * 所以 config('quota.reward_monthly') 默认 0 → 这个方法直接 return，什么都不做。
     * 留着它是为了留退路：把配置设成 >0 就恢复"自动到账"，不用改代码。
     *
     * 为什么当年用惰性而不是定时任务：定时任务要靠服务器 cron / schedule:run 跑起来，
     * 万一没跑，用户就白丢一个月的赠送。「按自然月 + 唯一键」天然幂等：
     * 不管用户什么时候打开，只要本月没发过就补一次，发过就不再发。
     * 调用点：GET /api/user/quota（「我的」页）和 GET /api/hanger/reward（赚衣架页）。
     */
    public function grantMonthly(User $user): void
    {
        $amount = $this->amountOf(HangerRewardLog::TYPE_MONTHLY);
        if ($amount <= 0) {
            return;   // 配置成 0 = 关掉
        }
        if ($this->countInPeriod($user, HangerRewardLog::TYPE_MONTHLY) > 0) {
            return;   // 本月已经发过
        }

        try {
            $this->claim($user, HangerRewardLog::TYPE_MONTHLY);
        } catch (HangerRewardException $e) {
            // 并发下另一个请求先发掉了：唯一索引/事务挡住，当作已发，不算错误
        }
    }

    /**
     * 当前状态（页面进来自查：领过没、还能领几次、各给几个）
     *
     * 出参只给数字和布尔，文案由小程序拼（项目一贯口径：WXML 不做拼接）；
     * 例外是「每月赠送」那一行 —— 标题/说明/状态都由后端拼好（用户定的"能拼就后端拼"）。
     */
    public function status(User $user): array
    {
        $out = [];
        foreach (array_keys(self::RULES) as $type) {
            $times = $this->timesOf($type);
            $done  = $this->countInPeriod($user, $type);
            $amount = $this->amountOf($type);

            $row = [
                'amount'      => $amount,
                'timesPerDay' => $times,          // 兼容前端既有字段名（周期内次数）
                'doneToday'   => $done,
                'leftToday'   => max(0, $times - $done),
                'canClaim'    => $times > 0 && $amount > 0 && $done < $times,
                'period'      => $this->rule($type)['period'],
                'periodKey'   => $this->periodKey($type),
            ];

            // 「新用户每月免费领取」和老的「每月系统赠送」：整行文案后端拼好（前端只显示，不自己拼）
            // 文案 key 按类型取：reward.newcomer_* / reward.monthly_*
            // 关掉（配置 0）时**一个字都不给** —— 前端按"没标题就不渲染这一行"处理，别给半截文案
            if ($type === HangerRewardLog::TYPE_NEWCOMER || $type === HangerRewardLog::TYPE_MONTHLY) {
                $row['title']     = $amount > 0 ? $this->texts->get("reward.{$type}_title") : '';
                $row['desc']      = $amount > 0 ? $this->texts->get("reward.{$type}_desc", ['n' => $amount]) : '';
                $row['btnText']   = $amount > 0 ? $this->texts->get("reward.{$type}_btn") : '';
                $row['stateText'] = $done > 0 ? $this->texts->get("reward.{$type}_done") : '';
                $row['granted']   = $done > 0;
            }

            $out[$type] = $row;
        }

        // 顺手把最新衣架余额带上：领完直接刷新卡片，不用前端再发一次请求
        $out['hanger'] = $this->quota->summary($user);

        return $out;
    }

    /**
     * 领一次奖励（签到 / 分享 / 每月赠送都走这里）
     *
     * @throws HangerRewardException 本周期领过了（4008）/ 这个奖励没开（4009）
     */
    public function claim(User $user, string $type): array
    {
        $rule   = $this->rule($type);
        $times  = $rule['times'];
        $amount = $rule['amount'];
        if ($times <= 0 || $amount <= 0) {
            throw new HangerRewardException('CLOSED', '这个奖励暂时没开');
        }

        $date = $this->periodKey($type);
        $added = 0;

        DB::transaction(function () use ($user, $type, $date, $times, $amount, &$added) {
            // 锁住用户行再数次数：同一秒连点两下时，第二个请求会等第一个提交完，
            // 数出来的就是"已经领过"（不靠唯一索引报错兜底，报错只是最后一道保险）
            $locked = User::whereKey($user->id)->lockForUpdate()->first();
            if (empty($locked)) {
                throw new HangerRewardException('CLOSED', '这个用户不在了');
            }

            $done = $this->countInPeriod($locked, $type);
            if ($done >= $times) {
                throw new HangerRewardException('ALREADY', self::ALREADY_MSG[$type]);
            }

            HangerRewardLog::create([
                'user_id'     => $locked->id,
                'type'        => $type,
                'reward_date' => $date,
                'seq'         => $done + 1,
                'amount'      => $amount,
            ]);

            // 实际加了多少：余额到衣架总数上限（config('quota.item_max')）时会少于 amount，甚至 0
            $added = $this->quota->addTotal($locked, $amount);
        });

        $status = $this->status($user->refresh());

        // 提示语**由后端拼好**（用户 2026-09 定：能拼就后端拼），前端拿到直接显示，不自己拼。
        // 数字用"实际加到的"那个 —— 到上限时只加了 2 就说 +2，一个没加就说"已到上限"，别报虚数
        $status['toast'] = $added > 0
            ? $this->texts->get($this->toastKey($type), ['n' => $added])
            : $this->texts->get('reward.capped', ['max' => $this->quota->itemMax()]);

        return $status;
    }

    /** 这个周期里领过几次（日类型 = 今天，月类型 = 本月） */
    private function countInPeriod(User $user, string $type): int
    {
        return HangerRewardLog::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->where('reward_date', $this->periodKey($type))
            ->count();
    }

    /**
     * 这个周期的"记账日期"：日类型 = 今天，月类型 = 本月 1 号
     *
     * 用 1 号而不是"发放当天"：同一个月不管哪天发，记的都是同一天 → 一个月只会有一条流水
     * （哪怕用户 15 号才第一次打开，补发出来也记在 1 号）。
     */
    private function periodKey(string $type): string
    {
        return $this->rule($type)['period'] === 'month'
            ? today()->startOfMonth()->toDateString()
            : today()->toDateString();
    }

    /** 领到后的提示语 key */
    private function toastKey(string $type): string
    {
        return match ($type) {
            HangerRewardLog::TYPE_SHARE    => 'reward.share_ok',
            HangerRewardLog::TYPE_NEWCOMER => 'reward.newcomer_ok',
            HangerRewardLog::TYPE_MONTHLY  => 'reward.monthly_ok',
            default                        => 'reward.checkin_ok',
        };
    }

    /** 读配置：数量 / 周期内次数 / 周期（数字只从 config 来） */
    private function rule(string $type): array
    {
        if (!isset(self::RULES[$type])) {
            throw new HangerRewardException('CLOSED', '没有这种奖励');
        }

        $cfg = self::RULES[$type];

        return [
            'amount' => max(0, (int) config($cfg['amount'], 0)),
            'times'  => is_int($cfg['times']) ? max(0, $cfg['times']) : max(0, (int) config($cfg['times'], 0)),
            'period' => $cfg['period'],
        ];
    }
}
