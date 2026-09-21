<?php

namespace App\Services;

use App\Exceptions\QuotaExceededException;
use App\Models\User;

/**
 * 额度（2026-09 第二版：用户表上的余额，不再实时数记录）
 *
 * 为什么改成余额：
 *  - 以后开会员/套餐，直接改 users.item_quota（用 Filament 后台点两下），不用动代码
 *  - 判断变成读两个数字，比 count 一堆记录快也好懂
 *
 * 字段：
 *   users.item_quota        一共还能录几件（成功录一件减 1）
 *   users.daily_quota       今天还能录几件（每天重置回 config('quota.daily_quota')）
 *   users.daily_reset_date  上次重置的日期
 *
 * 判定只在一处：新增衣物（ClothesController::push）。**编辑已有衣物不扣**
 * （否则改一次少一件，那就没法用了）。
 */
class Quota
{
    /** 新用户的衣物总额度 */
    public function itemDefault(): int
    {
        return max(0, (int) config('quota.item_quota', 200));
    }

    /** 每天最多录几件（重置值） */
    public function dailyDefault(): int
    {
        return max(0, (int) config('quota.daily_quota', 50));
    }

    /**
     * 惰性重置：发现「上次重置日期」不是今天，就先把今天的额度填满
     *
     * 为什么要有这一层：定时任务要靠服务器的 cron 或容器跑 schedule:run，
     * 万一没跑起来，用户第二天会发现额度还是 0、什么都录不了。
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
     * 扣额度（在事务里调用，并且先把用户行 lockForUpdate 锁住，避免并发两批都通过）
     * 用完两种额度都不够时抛 QuotaExceededException，外层转成 {code, msg}
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
                    ? "还能再录 {$items} 件，这次要录 {$count} 件；少录几件、或删掉不穿的再录"
                    : '可上传的衣物数量用完了；删掉不穿的，或升级套餐后再录'
            );
        }

        $daily = (int) $user->daily_quota;
        if ($daily < $count) {
            throw new QuotaExceededException(
                'DAILY_LIMIT',
                $daily > 0
                    ? "今天还能录 {$daily} 件（每天上限 {$this->dailyDefault()} 件），明天再来"
                    : '今天录衣服的额度用完了，明天再来'
            );
        }

        $user->item_quota = $items - $count;
        $user->daily_quota = $daily - $count;
        $user->save();
    }

    /** 显示用量（「我的」页 / 录入前的提示都读它；顺手做一次惰性重置） */
    public function summary(User $user): array
    {
        $user = $this->refreshDaily($user);
        if ($user->isDirty()) {
            $user->save();
        }

        return [
            'itemQuota'       => (int) $user->item_quota,
            'dailyQuota'      => (int) $user->daily_quota,
            'dailyQuotaLimit' => $this->dailyDefault(),
        ];
    }
}
