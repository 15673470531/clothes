<?php

namespace App\Console\Commands;

use App\Services\Texts;
use Illuminate\Console\Command;

/**
 * 把当前生效的全量文案导出到 storage/app/texts.json
 *
 * 用途：线上想改提示语时，先跑一次这个把全量文案落成文件，之后就在那个文件上改
 * —— **改完立刻生效**（接口每次读文件），不用重启、不用发小程序版本。
 *
 *   php artisan texts:export
 */
class TextsExport extends Command
{
    protected $signature = 'texts:export';

    protected $description = '把文案表导出到 storage/app/texts.json（之后改这个文件即可，立刻生效）';

    public function handle(Texts $texts): int
    {
        $count = $texts->export();

        $this->info('已写入 ' . storage_path(Texts::FILE) . "（{$count} 条）");
        $this->line('改这个文件里的条目即可覆盖内置文案，保存后立刻生效（小程序重开一次生效）。');

        return self::SUCCESS;
    }
}
