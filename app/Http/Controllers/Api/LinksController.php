<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Links;
use Illuminate\Http\JsonResponse;

/**
 * 链接表（2026-10）
 *
 *   GET /api/links —— 小程序启动拉一次，存本地，之后「我的 → 反馈建议」等外链都从这儿取
 *
 * **免登录**：「我的」页未登录也能看到反馈建议菜单，不能等 token。
 * 出参：{ links: {key: url, ...}, version: md5, count: n }
 *
 * 拿不到（接口挂了/没网）时小程序用本地缓存；缓存也没有就是空表 ——
 * 对应菜单**不显示**（见 pages/mine/mine.wxml），不会出现点了没反应的入口。
 */
class LinksController extends Controller
{
    public function __construct(private readonly Links $links)
    {
    }

    /** GET /api/links */
    public function index(): JsonResponse
    {
        $links = $this->links->all();

        return response()->json([
            'code' => 0,
            'msg'  => 'success',
            'data' => [
                'links'   => $links,
                'version' => $this->links->version(),
                'count'   => count($links),
            ],
        ]);
    }
}
