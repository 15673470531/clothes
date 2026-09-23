<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Quota;
use Illuminate\Console\Command;

/**
 * 每天重置「今日可录件数」（quota:reset-daily）
 *
 * 为什么要单独跑：每天的 50 件上限是靠 users.daily_quota 这个余额，
 * 早上不重置就一直是 0，用户什么都录不了。
 *
 * 注意：
 *  - 只重置每日额度，**不动 item_quota**（总余额是用户自己的资产，删一件也不会加回来）
 *  - Quota::refreshDaily 里还做了一道「惰性重置」，就算这个定时任务没跑起来，
 *    用户第二天录东西时也会自动补满今天的额度，不会卡死
 *  - 依赖服务器的 cron 或容器跑 `php artisan schedule:run`（见 routes/console.php）
 */
class ResetDailyQuota extends Command
{
    protected $signature = 'quota:reset-daily {--dry-run : 只看会重置多少人，不写库}';

    protected $description = '每天把用户的「今日可录件数」重置回默认值（默认 50）';

    public function handle(Quota $quota): int
    {
        $fresh = $quota->dailyDefault();
        $today = today()->toDateString();

        // 按**日期**比，别按字符串比：sqlite 会把 date 列存成 'Y-m-d 00:00:00'，
        // 直接 `!= '2026-09-24'` 会把"今天已经重置过"的人也挑进来（MySQL 没问题，测试跑 sqlite 时挂）
        $query = User::where(function ($q) use ($today) {
            $q->whereNull('daily_reset_date')->orWhereDate('daily_reset_date', '!=', $today);
        });

        if ($this->option('dry-run')) {
            $this->info("今天（{$today}）需要重置的用户：" . $query->count() . ' 人，重置成 ' . $fresh . ' 件');

            return self::SUCCESS;
        }

        // 一次 UPDATE 搞定，别一个个 save（用户多了会慢）
        $n = $query->update(['daily_quota' => $fresh, 'daily_reset_date' => $today]);

        $this->info("已重置 {$n} 人的今日额度为 {$fresh} 件（{$today}）");

        return self::SUCCESS;
    }
}
