<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Texts;
use Illuminate\Http\JsonResponse;

/**
 * 文案表（2026-09）
 *
 *   GET /api/texts —— 小程序启动拉一次，存本地，之后所有提示语都从这儿取
 *
 * **免登录**：登录页/未登录时的提示也要有文案，不能等 token。
 * 出参：{ texts: {key: 文案, ...}, version: md5 }
 *   version 是内容的指纹：一样就说明没改过，小程序端可以跳过写缓存。
 *
 * 小程序那边有一份同样的默认文案（utils/texts.js）：接口挂了/没网时用本地的，
 * 所以"网络异常""登录失效"这类提示照样有话说（后端都连不上了，文案不可能来自后端）。
 */
class TextsController extends Controller
{
    public function __construct(private readonly Texts $texts)
    {
    }

    /** GET /api/texts */
    public function index(): JsonResponse
    {
        $texts = $this->texts->all();

        return response()->json([
            'code' => 0,
            'msg'  => 'success',
            'data' => [
                'texts'   => $texts,
                'version' => md5(json_encode($texts)),
                'count'   => count($texts),
            ],
        ]);
    }
}
