<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\TryonException;
use App\Http\Controllers\Controller;
use App\Models\ClothesItem;
use App\Models\ClothesOutfit;
use App\Models\TryonTask;
use App\Services\Tryon\TryonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI 试穿（2026-09 · P2）
 *
 * 三个接口，**提交与查询分开**（生成要 20~35 秒，不可能同步等）：
 *   GET  /api/tryon/quota  入口状态（开关 / 会员 / 今天还剩几次）
 *   POST /api/tryon        提交：命中缓存秒回结果，否则建任务 + 进队列
 *   GET  /api/tryon/{id}   轮询任务（前端每 2~3 秒问一次）
 *
 * 业务码沿用 {code, msg} 那套：4003 非会员 / 4004 没照片 / 4005 品类不支持 / 4006 次数用完 / 4007 没开放
 */
class TryonController extends Controller
{
    public function __construct(private readonly TryonService $tryon)
    {
    }

    /** GET /api/tryon/quota —— 前端据此决定入口显不显示、还剩几次 */
    public function quota(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok([
            'enabled'    => $this->tryon->enabled(),
            'memberOnly' => (bool) config('tryon.member_only'),
            'isMember'   => !empty($user->getAttribute('is_member')),
            'leftToday'  => $this->tryon->leftToday((int) $user->id),
            'dailyLimit' => (int) config('tryon.daily_limit'),
            'modelKey'   => (string) config('tryon.model_key'),
        ]);
    }

    /**
     * POST /api/tryon {itemId} 或 {outfitId} —— 提交一次试穿
     *
     * 单件传 itemId；整套搭配传 outfitId（后端自己取这套里第一件上装 + 第一件下装，
     * 一次调用出整套效果）。两者都给的话以 itemId 为准。
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'itemId'   => 'nullable|string|max:32',
            'outfitId' => 'nullable|string|max:32',
        ]);
        $user = $request->user();

        // 只能试自己的（client_id 是小程序侧的 id，按 user 限定）
        $item   = null;
        $outfit = null;

        if (!empty($data['itemId'])) {
            $item = ClothesItem::where('user_id', $user->id)->where('client_id', $data['itemId'])->first();
            if (empty($item)) {
                return $this->fail(4004, '这件衣物不在了');
            }
        } elseif (!empty($data['outfitId'])) {
            $outfit = ClothesOutfit::where('user_id', $user->id)->where('client_id', $data['outfitId'])->first();
            if (empty($outfit)) {
                return $this->fail(4004, '这套搭配不在了');
            }
        } else {
            return $this->fail(4004, '没说要试哪件衣服或哪套搭配');
        }

        try {
            $r = $this->tryon->request($user, $item, $outfit);
        } catch (TryonException $e) {
            return $this->fail($e->apiCode(), $e->getMessage());
        }

        return $this->ok([
            'cached' => (bool) $r['cached'],
            'task'   => $r['task']->out(),
        ]);
    }

    /** GET /api/tryon/{id} —— 轮询（只能查自己的任务） */
    public function show(Request $request, int $id): JsonResponse
    {
        $task = TryonTask::where('user_id', $request->user()->id)->where('id', $id)->first();
        if (empty($task)) {
            return $this->fail(4004, '这次生成记录不在了');
        }

        return $this->ok(['task' => $task->out()]);
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
