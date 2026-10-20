<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ScanException;
use App\Http\Controllers\Controller;
use App\Services\Scan\ScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 穿搭照识别（2026-10）
 *
 * 两个接口：
 *   GET  /api/items/recognize/status   入口状态（开关 / 今天还剩几次 / 一次最多几件）
 *   POST /api/items/recognize          multipart: file=<穿搭照>  → 识别出的衣物列表
 *
 * 为什么同步不走队列：用户在衣橱页点完就在等结果，转圈 10 秒能接受；走队列要常驻 worker，
 * 而这个功能一天就那么几次。识别**不落库、不扣衣架**——用户点「加入衣橱」才真的建衣物。
 *
 * 业务码：4020 功能没开 / 4021 今天次数用完 / 4022 照片有问题 / 4023 没认出衣物 / 4024 识别失败
 */
class ScanController extends Controller
{
    public function __construct(private readonly ScanService $scan)
    {
    }

    /** GET /api/items/recognize/status */
    public function status(Request $request): JsonResponse
    {
        return $this->ok($this->scan->status($request->user()));
    }

    /**
     * POST /api/items/recognize  multipart(file)
     *
     * 收 multipart 不收 imageUrl：小程序手上就是本地照片，让它先传给通用上传接口
     * 再发一次识别请求是白多一跳；这里自己 put 上 OSS，顺手还能拿二进制去裁剪。
     */
    public function recognize(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|image|max:20480',
        ], [
            'file.required' => '没有收到照片',
            'file.image'    => '只能上传图片',
            'file.max'      => '照片不能超过 20MB',
        ]);

        try {
            $data = $this->scan->recognize($request->user(), $request->file('file'));
        } catch (ScanException $e) {
            return $this->fail($e->apiCode(), $e->getMessage());
        }

        return $this->ok($data);
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
