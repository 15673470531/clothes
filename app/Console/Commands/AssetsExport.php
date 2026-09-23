<?php

namespace App\Console\Commands;

use App\Services\Assets;
use Illuminate\Console\Command;

/**
 * 把当前生效的插画资源表导出到 storage/app/assets.json
 *
 * 用途：线上想换图/临时撤掉某张插画时，先跑一次这个把全量表落成文件，之后就在那个文件上改
 * —— **改完立刻生效**（接口每次读文件），不用重启、不用发小程序版本。
 *
 *   php artisan assets:export
 *
 * 撤掉某张 = 把它的值改成空字符串（"key": ""）；空值不会被过滤掉，是有意义的值。
 */
class AssetsExport extends Command
{
    protected $signature = 'assets:export';

    protected $description = '把插画资源表导出到 storage/app/assets.json（之后改这个文件即可，立刻生效）';

    public function handle(Assets $assets): int
    {
        $count = $assets->export();

        $this->info('已写入 ' . storage_path(Assets::FILE) . "（{$count} 条）");
        $this->line('改这个文件里的 URL 即可覆盖内置地址；值写成 "" 就不显示那张插画。');

        return self::SUCCESS;
    }
}
