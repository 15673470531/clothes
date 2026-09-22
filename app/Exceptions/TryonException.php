<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * AI 试穿失败（2026-09 · P2）
 *
 * 跟 QuotaExceededException 一个套路：带一个业务码，控制器把它转成
 * {code, msg} —— 前端按 code 判情况，不看 HTTP 状态码。
 *
 * 码位约定（接在额度那两个后面）：
 *   4003 只给会员，非会员
 *   4004 这件衣物没有照片（没照片没法试穿）
 *   4005 这个品类不支持试穿（鞋、配饰）
 *   4006 今天生成次数用完了
 *   4007 试穿功能没开
 *   4008 生成失败（供应商侧问题）
 */
class TryonException extends RuntimeException
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
