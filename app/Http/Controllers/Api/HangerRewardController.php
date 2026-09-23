<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\HangerRewardException;
use App\Http\Controllers\Controller;
use App\Models\HangerReward;
use App\Services\HangerRewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 赚衣架（2026-09 用户定：「我的」页衣架卡右侧「获取更多」）
 *
 *   GET  /api/hanger/reward    今天的状态（签到/分享/新用户每月免费领取各领过没、各给几个、衣架余额）
 *   POST /api/hanger/checkin   每日签到
 *   POST /api/hanger/share     分享给好友
 *   POST /api/hanger/newcomer  新用户每月免费领取（2026-09 取代"每月系统赠送"：要点一下才到账）
 *
 * 业务码：4008 已经领过了 / 4009 这个奖励没开
 *
 * 四个接口**出参形状一样**（都是 status），前端领完直接用返回的数据刷新界面，
 * 不用再发一次 GET；数字全部由后端下发，前端一个都不写死。
 */
class HangerRewardController extends Controller
{
    public function __construct(private readonly HangerRewardService $reward)
    {
    }

    /** GET /api/hanger/reward —— 进页面先问一次：签到/分享领过没、本月免费领取领过没 */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        // 老口径的「每月系统赠送」：配置默认 0（已停用），设 >0 就退回"自动到账"（见 Service::grantMonthly）
        $this->reward->grantMonthly($user);

        return $this->ok($this->reward->status($user));
    }

    /** POST /api/hanger/checkin —— 每日签到（一次 +2，每天 1 次） */
    public function checkin(Request $request): JsonResponse
    {
        return $this->claim($request, HangerReward::TYPE_CHECKIN);
    }

    /**
     * POST /api/hanger/newcomer —— 新用户每月免费领取（一次 +50，每月 1 次，**点了才给**）
     *
     * 2026-09 用户改的口径：原来是"每月系统自动送"，现在要用户自己点「领取」。
     * 这个月没点就没了（不补发、不累计），下个月按钮又变回「领取」。
     */
    public function newcomer(Request $request): JsonResponse
    {
        return $this->claim($request, HangerReward::TYPE_NEWCOMER);
    }

    /**
     * POST /api/hanger/share —— 分享给好友（一次 +10，每天 1 次）
     *
     * 小程序那边是 `<button open-type="share">`：点一下弹转发面板就算领到
     * （微信不再返回"是否真的转发成功"，只能这么算，见 Service 里的说明）。
     */
    public function share(Request $request): JsonResponse
    {
        return $this->claim($request, HangerReward::TYPE_SHARE);
    }

    /** 两种奖励领的流程一样，只有类型不同 */
    private function claim(Request $request, string $type): JsonResponse
    {
        try {
            $data = $this->reward->claim($request->user(), $type);
        } catch (HangerRewardException $e) {
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
