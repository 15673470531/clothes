<?php

namespace App\Console\Commands;

use App\Services\Links;
use Illuminate\Console\Command;

/**
 * 把当前生效的全量链接导出到 storage/app/links.json
 *
 * 用途：线上想换「反馈建议」那篇公众号文章时，先跑一次这个把全量链接落成文件，
 * 之后就在那个文件上改 —— **改完立刻生效**（接口每次读文件），不用重启、不用发小程序版本。
 *
 *   php artisan links:export
 */
class LinksExport extends Command
{
    protected $signature = 'links:export';

    protected $description = '把链接表导出到 storage/app/links.json（之后改这个文件即可，立刻生效）';

    public function handle(Links $links): int
    {
        $count = $links->export();

        $this->info('已写入 ' . storage_path(Links::FILE) . "（{$count} 条）");
        $this->line('改这个文件里的条目即可覆盖内置链接，保存后立刻生效（小程序重开一次生效）。');
        $this->line('⚠️ 链接必须是**已和小程序关联**的公众号文章（mp.weixin.qq.com），否则 web-view 打不开。');

        return self::SUCCESS;
    }
}
