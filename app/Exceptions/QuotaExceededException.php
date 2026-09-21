<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 额度用完（衣物总余额 / 今日件数）
 *
 * kind 是给小程序判断用的机器可读类型（ITEM_LIMIT / DAILY_LIMIT），
 * message 是直接能给用户看的中文；外层控制器把它转成 {code, msg} 返回。
 * apiCode 没用 0/1/2 —— 那几个已经被「成功」「上传失败」「开发模式登录已关闭」占了。
 */
class QuotaExceededException extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    /** 给小程序的状态码：4001 总额度用完 / 4002 今天录满了 */
    public function apiCode(): int
    {
        return $this->kind === 'ITEM_LIMIT' ? 4001 : 4002;
    }
}
