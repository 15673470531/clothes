<?php

namespace App\Services;

use App\Exceptions\QuotaExceededException;
use App\Models\User;

/**
 * 衣架（2026-09 第三版：把额度讲成"衣架"，用户听得懂）
 *
 * 一个衣架 = 能挂一样东西：**新增一件衣物占 1 个，新增一套搭配也占 1 个**（共用同一个架子）。
 * 衣架数是 users 表上的余额：
 *   users.item_quota        总共还剩几个衣架（新增时减 1；**删掉不想要的会还回来 1 个**）
 *   users.daily_quota       今天还能挂几个（每天重置回 config('quota.daily_quota')）
 *   users.daily_reset_date  上次重置的日期
 *
 * 为什么是余额而不是"实时数现有条数"（2026-09 用户定的）：
 * 删除要"实时还"，但那也只在删除动作发生的当下 +1 —— 余额制同样能做到，
 * 而且不用每次去 count 一堆记录，后台开会员直接改数字就行（Filament 里那两个框）。
 *
 * 扣减只有一个入口：ClothesController::push 里新增的条数（编辑已有衣物/搭配不扣）。
 * 退还也只有一个入口：同一批里的 deleted（**真的是从没删变已删**才算，重复删不叠加）。
 */
class Quota
{
    /** 新用户的衣架总数（默认 100，2026-09 用户定稿） */
    public function totalDefault(): int
    {
        return max(0, (int) config('quota.item_quota', 100));
    }

    /** 每天最多挂几个（重置值） */
    public function dailyDefault(): int
    {
        return max(0, (int) config('quota.daily_quota', 100));
    }

    /**
     * 惰性重置：发现「上次重置日期」不是今天，就先把今天的衣架填满
     *
     * 为什么要有这一层：定时任务要靠服务器的 cron 或容器跑 schedule:run，
     * 万一没跑起来，用户第二天会发现今天的衣架还是 0、什么都挂不了。
     * 这里兜一道，定时任务只是「顺手重置 + 批量改 daily_reset_date」，不是唯一出路。
     */
    public function refreshDaily(User $user): User
    {
        $today = today()->toDateString();
        $last = $user->daily_reset_date ? $user->daily_reset_date->toDateString() : '';
        if ($last !== $today) {
            $user->daily_quota = $this->dailyDefault();
            $user->daily_reset_date = $today;
        }

        return $user;
    }

    /**
     * 扣衣架（在事务里调用，并且先把用户行 lockForUpdate 锁住，避免并发两批都通过）
     * 两种衣架有一个不够就抛 QuotaExceededException，外层转成 {code, msg}
     */
    public function consume(User $user, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $user = $this->refreshDaily($user);

        $items = (int) $user->item_quota;
        if ($items < $count) {
            throw new QuotaExceededException(
                'ITEM_LIMIT',
                $items > 0
                    ? "衣架不够了：还剩 {$items} 个，这次要占用 {$count} 个；删掉不想要的能腾出衣架，或联系客服开通会员"
                    : '衣架用完了；删掉不想要的能腾出衣架，也可以联系客服开通会员'
            );
        }

        $daily = (int) $user->daily_quota;
        if ($daily < $count) {
            throw new QuotaExceededException(
                'DAILY_LIMIT',
                // 只报"今天还剩几个"，不报每日上限（2026-09 用户定：界面不显示每日数量）
                $daily > 0
                    ? "今天还能挂 {$daily} 个衣架，明天再来"
                    : '今天的衣架用完了，明天再来；想今天继续挂可以联系客服开通会员'
            );
        }

        $user->item_quota = $items - $count;
        $user->daily_quota = $daily - $count;
        $user->save();
    }

    /**
     * 还衣架：删掉一件衣物或一套搭配，那个衣架就回到架子上
     *
     * **只还总额，不还每日** —— 每日是速率限制（今天最多挂 50 个），
     * 如果也还，就变成「加满 50 → 删 50 → 再加 50」可以无限循环，限制形同虚设。
     *
     * 不会越还越多：只有「这次真的把一条从没删变成已删」才还 1 个（见 ClothesController），
     * 而那条记录当初也只扣过 1 个，一进一出正好抵平。
     */
    public function refund(User $user, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $user = $this->refreshDaily($user);
        $user->item_quota = (int) $user->item_quota + $count;
        $user->save();
    }

    /**
     * 加衣架（每日签到 / 分享好友的奖励）
     *
     * 跟 refund() 的区别：refund 是"删掉东西把架子还回来"，这个是"白赚"。
     * 两者都只动总额、不动每日。
     *
     * **不受免费上限约束**：余额可以超过 config('quota.item_quota')。
     * 那个上限只是"免费进货量"，不是天花板；奖励和客服加量都能越过去
     * （接口分母取 max(免费标准, 余额)，所以显示不会出现"510 / 500"这种怪数）。
     */
    public function addTotal(User $user, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $user = $this->refreshDaily($user);
        $user->item_quota = (int) $user->item_quota + $count;
        $user->save();
    }

    /**
     * 显示用量（「我的」页顶部那块衣架卡片读它；顺手做一次惰性重置）
     *
     * 出参是 **hanger 口径**；同时保留旧的 itemQuota/dailyQuota 字段 ——
     * 老版本小程序还在读它们，后端先发、小程序后发，中间不能炸。
     */
    public function summary(User $user): array
    {
        $user = $this->refreshDaily($user);
        if ($user->isDirty()) {
            $user->save();
        }

        $total = (int) $user->item_quota;
        $daily = (int) $user->daily_quota;

        return [
            // 衣架口径（新）：今日剩几个 / 总共剩几个，都带上「分母」方便画进度
            'hangerTotal'      => $total,
            // 分母取 max(默认值, 当前余额)：客服在后台把余额加过之后，分母跟着走，不会出现「250/200」这种怪数
            'hangerTotalLimit' => max($this->totalDefault(), $total),
            'hangerDaily'      => $daily,
            'hangerDailyLimit' => $this->dailyDefault(),

            // 旧字段（老版本小程序兼容，别删）
            'itemQuota'       => $total,
            'dailyQuota'      => $daily,
            'dailyQuotaLimit' => $this->dailyDefault(),
        ];
    }
}
