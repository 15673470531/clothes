<?php

namespace App\Jobs;

use App\Services\NormalizeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * 自动洗一件衣物的白底图（2026-09 · 用户要的"保存后自动跑"）
 *
 * 为什么走队列：出图 15~20 秒，用户保存完就得能走人；一次录 9 张要串行跑两三分钟，
 * 更不能卡在保存请求里。队列基建已在（试穿在用），单独一个 normalize 队列，
 * 免得把试穿任务堵在后面。
 *
 * 重试：2 次（间隔 30 秒）。**失败不计费也不扣次数**，所以重试是安全的；
 * 重试仍失败 → 状态落 failed，前端显示「生成失败，可手动重试」。
 * 注意 NormalizeService::runQueued() 里对"已经洗好同一张原图"直接返回，重复投递不会重复花钱。
 */
class RunNormalize implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public array $backoff = [30];
    public int $timeout  = 180;

    public function __construct(
        private readonly int $userId,
        private readonly string $itemId,
    ) {
        $this->onQueue((string) config('tryon.normalize_queue', 'normalize'));
    }

    public function handle(NormalizeService $service): void
    {
        $service->runQueued($this->userId, $this->itemId);
    }

    /** 两次都没成：把这件衣物钉在失败态（前端显示可手动重试） */
    public function failed(Throwable $e): void
    {
        $service = app(NormalizeService::class);
        $service->markFailed($this->userId, $this->itemId, $e->getMessage());
    }
}
