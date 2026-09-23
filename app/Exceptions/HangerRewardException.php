<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 赚衣架被拦（今天已经领过了 / 这个奖励没开）
 *
 * 跟 QuotaExceededException 分开：那个是"衣架不够用"，这个是"奖励领不了"，
 * 前端面对的文案和后续动作都不一样（一个去买/删，一个是等明天）。
 */
class HangerRewardException extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    /** 给小程序的状态码：4008 今天已经领过了 / 4009 这个奖励没开 */
    public function apiCode(): int
    {
        return $this->kind === 'ALREADY' ? 4008 : 4009;
    }
}
