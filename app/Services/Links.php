<?php

namespace App\Services;

/**
 * 链接表（2026-10）
 *
 * 跟文案表（App\Services\Texts）、插画表（App\Services\Assets）**同一个套路**，
 * 只是内容是**外链 URL**：
 *   1 **内置默认**（这个文件的 DEFAULTS）
 *   2 **服务器上的文件**（storage/app/links.json）—— 你在服务器上改这一个文件，**立刻生效**
 *      （接口每次读文件，不用重启、不用发小程序版本）。只写想改的那几条，没写的用默认
 *   3 接口 GET /api/links 下发，小程序启动拉一次存本地
 *
 * 为什么留空不算错：小程序拿到空串就**不显示那个菜单**（见 pages/mine/mine.wxml），
 * 少一行入口，总比点进去白屏强。
 *
 * ⚠️ web-view 的硬限制（官方文档 web-view.src 原文）：
 *   「可打开关联的公众号的文章，其它网页需登录小程序管理后台配置业务域名」
 *   「个人类型的小程序暂不支持使用」
 *   所以 mine.feedback_article 只能是**已和小程序关联**的公众号发的文章；
 *   换成别人的文章、或换成普通网页（没配业务域名），进去就是白屏。
 *
 * 改链接：`php artisan links:export` 把当前生效的全量链接写到 storage/app/links.json，
 * 之后就在那个文件上改。
 */
class Links
{
    /** 服务器上的可写文件（改它立刻生效） */
    public const FILE = 'app/links.json';

    /**
     * 全量默认链接
     *
     * 约定：key 用「页面.用途」的写法，跟文案表的 key 风格一致；
     *      默认一律空串 —— 没配就是没这个入口，不猜、不放占位链接。
     */
    public const DEFAULTS = [
        // 「我的 → 反馈建议」点进去的那篇公众号文章（必须出自**已关联**的公众号）
        'mine.feedback_article' => '',
    ];

    /**
     * 当前生效的链接 = 默认值 + 服务器文件里的覆盖项
     *
     * 文件坏了/格式不对就退回默认值，绝不让链接问题把小程序搞崩。
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

    /** 取一条（后端内部拼链接用） */
    public function get(string $key): string
    {
        return (string) ($this->all()[$key] ?? '');
    }

    /** 内容指纹（小程序端据此判断要不要写缓存） */
    public function version(): string
    {
        return md5(json_encode($this->all()));
    }

    /** 把当前生效的全量链接写到 storage/app/links.json（给你在服务器上改） */
    public function export(): int
    {
        $path = storage_path(self::FILE);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $all = $this->all();

        file_put_contents(
            $path,
            json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return count($all);
    }
}
