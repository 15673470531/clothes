<?php

use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\NormalizeController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\TryonController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ClothesController;
use App\Http\Controllers\Api\HangerRewardController;
use App\Http\Controllers\Api\TextsController;
use App\Http\Controllers\Api\AssetsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Prefix: /api
| 统一响应格式：{"code": 0, "msg": "success", "data": ...}
| code = 0 成功，非 0 为业务失败（前端据此判断，不依赖 HTTP 状态码）
|
*/

// 登录不需要 token
Route::post('user/login', [UserController::class, 'login']);

// 文案表（2026-09）：小程序启动拉一次，所有提示语都从这儿取。
// **也免登录** —— 登录前/未登录那几句提示（"先登录微信"之类）也要有文案
Route::get('texts', [TextsController::class, 'index']);

// 插画资源表（2026-09 用户要的）：空态/关于页的插画 URL，图片在 OSS 上（换图不发版）。
// 同样**免登录** —— 还没登录、还没录东西时的空态插画也得显示
Route::get('assets', [AssetsController::class, 'index']);


// 以下接口需要 Bearer Token
Route::middleware('auth:sanctum')->group(function () {
    Route::post('usage/events', [\App\Http\Controllers\Api\UsageController::class, 'collect'])->middleware('throttle:60,1');
    Route::get('admin/usage', [\App\Http\Controllers\Api\UsageController::class, 'report']);

    // 用户信息
    Route::get('user/info',         [UserController::class, 'info']);
    Route::get('user/quota',        [UserController::class, 'quota']);   // 免费额度用量
    Route::post('user/update-name', [UserController::class, 'updateName']);
    Route::post('user/avatar',      [UserController::class, 'uploadAvatar']);   // 头像上传（multipart）
    Route::post('user/bind-phone',  [UserController::class, 'bindPhone']);
    Route::post('user/logout',      [UserController::class, 'logout']);

    // 意见反馈
    Route::post('feedback/submit',  [FeedbackController::class, 'store']);

    // 图片上传（衣物照片 / 搭配封面；头像有单独的 user/avatar）
    Route::post('upload/image',     [UploadController::class, 'image']);

    // 洗白底（归一化）：记录衣物页底部那个入口；**同步**接口，15~20 秒出图
    Route::get('items/normalize/status', [NormalizeController::class, 'status']);
    Route::post('items/normalize',       [NormalizeController::class, 'store']);

    // 衣物 / 搭配 / 日历 数据同步（二期第二步：数据上云，存 MySQL）
    // 只有两个接口：拉全量 + 批量推（小程序自己攒「待推队列」，弱网下一次推完）
    Route::get('clothes/sync',      [ClothesController::class, 'pull']);
    Route::post('clothes/sync',     [ClothesController::class, 'push']);

    // 管理端（只有 is_admin 能用；入口在小程序「我的」页，仅管理员可见）
    // 校验在 AdminController::authorizeAdmin，非管理员 403
    Route::prefix('admin')->group(function () {
        Route::get('stats', [AdminController::class, 'stats']);   // 统计卡片（口径全在后端）
        Route::get('users', [AdminController::class, 'users']);  // 用户列表（搜索/筛选/排序/分页）
        // 下钻：点名单里某个人的「N 件衣物 / N 套搭配」看他的具体内容（只读）
        Route::get('users/{id}/items',   [AdminController::class, 'userItems']);
        Route::get('users/{id}/outfits', [AdminController::class, 'userOutfits']);
    });

    // 赚衣架（2026-09）：「我的」页衣架卡点哪都进「获取更多」→ 签到 / 分享群或好友 / 每月免费领取
    // 三个接口出参形状一样（都是当日状态 + 最新衣架余额），领完直接刷新界面
    Route::get('hanger/reward',   [HangerRewardController::class, 'status']);
    Route::post('hanger/checkin', [HangerRewardController::class, 'checkin']);
    Route::post('hanger/share',   [HangerRewardController::class, 'share']);
    // 新用户每月免费领取（2026-09 取代"每月系统赠送"：每月 1 次，要点一下才到账）
    Route::post('hanger/newcomer', [HangerRewardController::class, 'newcomer']);

    // AI 试穿（2026-09 · P2）
    // 提交与查询分开：生成要 20~35 秒，页面拿 task_id 自己轮询
    Route::get('tryon/quota', [TryonController::class, 'quota']);   // 入口状态（开关/会员/今天还剩几次）
    Route::post('tryon',      [TryonController::class, 'store']);   // 提交（命中缓存会秒回）
    Route::get('tryon/{id}',  [TryonController::class, 'show']);    // 轮询任务

    // 业务模块接口按模块分组加在这里，例如：
    // Route::get('xxx/list',   [XxxController::class, 'index']);
    // Route::post('xxx/create', [XxxController::class, 'store']);
});
