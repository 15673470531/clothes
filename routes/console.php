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
| 示例（按需放开）：
| Schedule::command('xxx:sync')->dailyAt('08:00');
| Schedule::call(fn () => \Illuminate\Support\Facades\Log::info('heartbeat'))->hourly();
|
*/
