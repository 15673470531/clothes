<?php

namespace App\Jobs;

use App\Models\TryonTask;
use App\Services\Tryon\TryonService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * 跑一次 AI 试穿（2026-09 · P2）
 *
 * 为什么要队列：归一化 + 试穿加起来 20~35 秒，不能卡在小程序那个请求里。
 * 页面提交后拿到 task_id，自己轮询 GET /api/tryon/{id}。
 *
 * 重试策略：接口会 429 限流、也会偶发失败，所以给 3 次机会（间隔 15s / 45s）。
 * 注意 service->run() 里对"已经出图的任务"直接返回，重复投递不会重复花钱。
 */
class RunTryon implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public array $backoff = [15, 45];
    public int $timeout  = 300;

    public function __construct(private readonly int $taskId)
    {
    }

    public function handle(TryonService $service): void
    {
        $service->run($this->taskId);
    }

    /** 三次都没成：把任务钉在失败态（前端显示友好文案，技术原因留在 error） */
    public function failed(Throwable $e): void
    {
        TryonTask::where('id', $this->taskId)
            ->where('status', '!=', TryonTask::STATUS_DONE)
            ->update([
                'status' => TryonTask::STATUS_FAILED,
                'error'  => mb_substr($e->getMessage(), 0, 240),
            ]);
    }
}
