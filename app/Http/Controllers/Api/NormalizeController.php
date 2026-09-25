<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TryonException;
use App\Http\Controllers\Controller;
use App\Services\NormalizeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 洗白底（归一化）· 记录衣物页底部那个入口（2026-09）
 *
 * 两个接口：
 *   GET  /api/items/normalize/status   入口状态（开关 / 今天还剩几次）—— 前端据此决定入口显不显示
 *   POST /api/items/normalize          洗一张（**同步**，15~20 秒出图）
 *
 * 为什么同步不走队列：用户在记录页盯着按钮，转圈等他更能接受；走队列就得常驻 worker，
 * 而试穿现在是隐藏状态，不想为这个功能再拉一个运行时依赖。
 *
 * 业务码沿用 {code,msg} 那套：4004 没照片 / 4008 生成失败 / 4009 功能没开 / 4010 今天次数用完
 */
class NormalizeController extends Controller
{
    public function __construct(private readonly NormalizeService $normalize)
    {
    }

    /** GET /api/items/normalize/status */
    public function status(Request $request): JsonResponse
    {
        return $this->ok($this->normalize->status($request->user()));
    }

    /**
     * POST /api/items/normalize {itemId?, imageUrl}
     *
     * itemId 是已有衣物的 client_id；新增流程里衣物还没保存，可以不传（后端用固定 key 进缓存，
     * 保存时把 normalizedUrl 一起提交上来即可）。
     * imageUrl 必须是**我们 OSS 的公网 https 直链**（小程序负责先把本机照片传上去）。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'itemId'   => 'nullable|string|max:32',
            // 空地址不在这里拦：交给 NormalizeService 出业务码 4004（"这件衣物还没有照片，先拍一张再洗"），
            // 比 422 的参数错误更像人话（2026-09）
            'imageUrl' => 'nullable|string|max:255',
            // true = 用户点了「重新生成一张」：跳过缓存真重洗（会花钱、会计次）
            'force'    => 'nullable|boolean',
        ]);

        try {
            $result = $this->normalize->normalize(
                $request->user(),
                (string) ($data['itemId'] ?? ''),
                (string) $data['imageUrl'],
                (bool) ($data['force'] ?? false)
            );
        } catch (TryonException $e) {
            return $this->fail($e->apiCode(), $e->getMessage());
        }

        return $this->ok($result);
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['code' => 0, 'msg' => 'success', 'data' => $data]);
    }

    private function fail(int $code, string $msg): JsonResponse
    {
        return response()->json(['code' => $code, 'msg' => $msg, 'data' => null]);
    }
}
