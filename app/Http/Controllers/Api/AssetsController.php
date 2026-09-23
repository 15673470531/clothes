<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Assets;
use Illuminate\Http\JsonResponse;

/**
 * 插画资源表（2026-09 用户要的：给小程序加宣传语和插画）
 *
 *   GET /api/assets —— 小程序启动拉一次，存本地，之后空态/关于页的插画都从这儿取 URL
 *
 * **免登录**：空态（还没登录、还没录东西）也要显示插画，不能等 token。
 * 出参：{ items: {key: url, ...}, version: md5, count: n }
 *   version 是内容指纹：一样就说明没换过图，小程序端可以跳过写缓存。
 *
 * 拿不到（接口挂了/没网）时小程序用本地缓存，缓存也没有就**不显示插画** ——
 * 空态退回纯文字，不会出现破图。图片本身在 OSS 上（换图不用发版，见 App\Services\Assets）。
 */
class AssetsController extends Controller
{
    public function __construct(private readonly Assets $assets)
    {
    }

    /** GET /api/assets */
    public function index(): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'msg'  => 'success',
            'data' => [
                'items'   => $this->assets->all(),
                'version' => $this->assets->version(),
                'count'   => count($this->assets->all()),
            ],
        ]);
    }
}
