<?php

namespace App\Console\Commands;

use App\Services\ImageStorage;
use Illuminate\Console\Command;

/**
 * 上传虚拟模特图（2026-09 · AI 试穿）
 *
 * 试穿接口要求模特图是**公网 https 直链**，而且要长期有效（阿里云临时地址会过期），
 * 所以虚拟模特图必须放在我们自己的 OSS 上。这个命令就是干这个的：
 * 传完打印地址，把它写进 .env 的 TRYON_MODEL_IMAGE 即可。
 *
 * 用法：
 *   docker compose exec app php artisan tryon:model storage/tryon-model.jpg
 */
class TryonModelImage extends Command
{
    protected $signature = 'tryon:model {file : 本地图片路径（全身、正面站立、背景干净的模特图）}';

    protected $description = '把虚拟模特图传到我们 OSS，并打印要写进 .env 的 TRYON_MODEL_IMAGE';

    public function handle(ImageStorage $storage): int
    {
        $file = (string) $this->argument('file');

        if (!is_file($file)) {
            $this->error('找不到文件：' . $file);

            return self::FAILURE;
        }

        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $put = $storage->putBinary((string) file_get_contents($file), $ext ?: 'jpg', 'tryon/model');

        $this->info('上传完成，把下面这行写进 .env：');
        $this->line('TRYON_MODEL_IMAGE=' . $put['url']);
        $this->line('（存在哪：' . $put['driver'] . '，对象键：' . $put['path'] . '）');

        return self::SUCCESS;
    }
}
