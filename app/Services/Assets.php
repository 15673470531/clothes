<?php

namespace App\Services;

/**
 * 插画资源表（2026-09 用户要的：给小程序加宣传语和插画）
 *
 * 跟文案表（App\Services\Texts）一个套路，只是内容是**图片 URL**：
 *   1 **内置默认**（这个文件的 DEFAULTS）—— 图片都在 OSS 上（gq-clothes 这个桶）
 *   2 **服务器上的文件**（storage/app/assets.json）—— 想换图/临时撤掉某张，改这个文件即可，
 *      **立刻生效、不用发小程序版本**；只写想改的那几条
 *   3 接口 GET /api/assets 下发合并后的结果（带一个 version 值，内容一变版本就变）
 *
 * 口径：
 *   - 值是**公网可访问的 https 图片地址**（小程序 <image> 直接加载）；写成空字符串 = **这一张不显示**
 *     （页面会退回纯文字空态，不会出现破图）
 *   - 图片放 OSS 而不是小程序包内，是为了"换图不发版"；分享卡片那张图例外（见下）
 *
 * 2026-09 这几张图的由来：AI 生成（DashScope wanx）的扁平插画，配色跟 app 主题一致
 * （深紫 #5D4C7C / 浅紫 #C9BFE0 / 暖白 #FBF8F4 / 珊瑚橙 #E8A87C），透明背景，
 * 生成脚本和后处理见 docs/插画与宣传语.md。
 *
 * 注意：**分享卡片的封面图不在这个表里** —— 转发时要立刻能拿到图，所以它放在小程序包内
 * （`images/share-cover.jpg`），不进这个接口。
 */
class Assets
{
    /**
     * 服务器上的可写文件（改它立刻生效）
     *
     * ⚠️ key 里带点（`empty.wardrobe` 这种）：PHP 端取值用 `$assets->get('empty.wardrobe')`，
     * 或把 `all()` 整个取出按下标取 —— **别用 Laravel 的点语法**
     * （`data_get($data, 'items.empty.wardrobe')` / `->json('data.items.empty.wardrobe')`），
     * 那会被当成三级路径、永远取到 null。测试里踩过一次，见 AssetsTest。
     */
    public const FILE = 'app/assets.json';

    /**
     * 全量默认（key => 图片 URL）
     *
     * key 命名口径：`页面.用途`，方便一眼看出用在哪
     */
    public const DEFAULTS = [
        // 衣橱页：一件衣物都没有时的空态插画
        'empty.wardrobe' => 'https://gq-clothes.oss-cn-beijing.aliyuncs.com/assets/202609/if9YFozVp4TykOqFE3nVZAKhTh.webp',
        // 穿搭页：一套搭配都没有时的空态插画
        'empty.outfit'   => 'https://gq-clothes.oss-cn-beijing.aliyuncs.com/assets/202609/YqVBftx46ni5EQnapJLyoCOrrd.webp',
        // 日历页：某天还没记搭配时的空态插画（宽幅）
        'empty.calendar' => 'https://gq-clothes.oss-cn-beijing.aliyuncs.com/assets/202609/KFZaPYCRYDdUj6irWfzTAu34UW.webp',
        // 关于页顶部横幅插画
        'about.hero'     => 'https://gq-clothes.oss-cn-beijing.aliyuncs.com/assets/202609/c4gtwj0nrSHLr1fcQTbp9zB6fi.webp',
    ];

    /**
     * 当前生效的资源表 = 默认值 + 服务器文件里的覆盖项
     *
     * 每次请求都读文件（很小），所以服务器上改完**立刻生效**，不用重启。
     * 空字符串是**有意义的值**（= 故意不显示这张图），所以这里不能用 array_filter 掉空值。
     */
    public function all(): array
    {
        return array_merge(self::DEFAULTS, $this->overrides());
    }

    /** 只取文件里的覆盖项（没文件/解析失败 → 空数组） */
    public function overrides(): array
    {
        $path = storage_path(self::FILE);
        if (!is_file($path)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json)) {
            return [];
        }

        // 只认字符串值，防止有人写了嵌套结构把前端搞挂
        return array_filter($json, static fn ($v) => is_string($v));
    }

    /** 取一张图的 URL（没有/被关掉 → 空串，调用方据此不显示） */
    public function get(string $key): string
    {
        return (string) ($this->all()[$key] ?? '');
    }

    /** 内容指纹（内容一变就变）：小程序可以拿它判断要不要更新本地缓存 */
    public function version(): string
    {
        return substr(md5(json_encode($this->all())), 0, 12);
    }

    /** 把当前生效的全量资源表写到 storage/app/assets.json（给你在服务器上改） */
    public function export(): int
    {
        $path = storage_path(self::FILE);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($this->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return count($this->all());
    }
}
