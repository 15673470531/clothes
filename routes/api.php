<?php

use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ClothesController;
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

// 以下接口需要 Bearer Token
Route::middleware('auth:sanctum')->group(function () {

    // 用户信息
    Route::get('user/info',         [UserController::class, 'info']);
    Route::post('user/update-name', [UserController::class, 'updateName']);
    Route::post('user/avatar',      [UserController::class, 'uploadAvatar']);   // 头像上传（multipart）
    Route::post('user/bind-phone',  [UserController::class, 'bindPhone']);
    Route::post('user/logout',      [UserController::class, 'logout']);

    // 意见反馈
    Route::post('feedback/submit',  [FeedbackController::class, 'store']);

    // 图片上传（衣物照片 / 搭配封面；头像有单独的 user/avatar）
    Route::post('upload/image',     [UploadController::class, 'image']);

    // 衣物 / 搭配 / 日历 数据同步（二期第二步：数据上云，存 MySQL）
    // 只有两个接口：拉全量 + 批量推（小程序自己攒「待推队列」，弱网下一次推完）
    Route::get('clothes/sync',      [ClothesController::class, 'pull']);
    Route::post('clothes/sync',     [ClothesController::class, 'push']);

    // 业务模块接口按模块分组加在这里，例如：
    // Route::get('xxx/list',   [XxxController::class, 'index']);
    // Route::post('xxx/create', [XxxController::class, 'store']);
});
