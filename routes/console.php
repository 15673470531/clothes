<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| 定时任务
|--------------------------------------------------------------------------
|
| 在服务器/容器里靠 crontab 每分钟执行 `php artisan schedule:run` 触发。
| 本地查看已注册任务：docker compose exec app php artisan schedule:list
| 手动执行单个任务：  docker compose exec app php artisan <命令名>
|
*/

// 每天 00:05 把「今日可录件数」重置回 50（额度是用户表上的余额，不重置第二天就是 0）
// 手动跑：docker compose exec app php artisan quota:reset-daily
// 只看会重置多少人：docker compose exec app php artisan quota:reset-daily --dry-run
Schedule::command('quota:reset-daily')->dailyAt('00:05');
