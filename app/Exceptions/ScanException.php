<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 穿搭照识别失败（2026-10）
 *
 * 跟 TryonException / QuotaExceededException 一个套路：带一个业务码，控制器把它转成
 * {code, msg} —— 前端按 code 判情况，不看 HTTP 状态码。
 *
 * 码位约定（接在洗白底那两个后面，**不复用 4009/4010**，免得前端分不清是哪件事的次数用完了）：
 *   4020 识别功能没开（服务端开关，正常流程走不到，前端靠 status 接口藏入口）
 *   4021 今天识别的次数用完了
 *   4022 照片有问题（读不到 / 不是图片）
 *   4023 一件都没认出来（让用户重拍一张全身照）
 *   4024 识别失败（供应商侧问题）
 */
class ScanException extends RuntimeException
{
    public function __construct(
        private readonly int $apiCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function apiCode(): int
    {
        return $this->apiCode;
    }
}
