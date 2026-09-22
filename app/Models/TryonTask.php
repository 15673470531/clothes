<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI 试穿任务（2026-09 · P2）
 *
 * 一行 = 一次"把某件衣服穿到虚拟模特身上"的请求，同时兼作缓存：
 *   - status = done 且 source_hash 一致的旧行 → 直接复用它的 result_url（不再调用接口、不花钱）
 *   - normalized_url 也可以跨任务复用（同一件衣服只洗一次白底图）
 *
 * 出参一律走 out()，字段名对齐小程序（前端不用做映射）。
 */
class TryonTask extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';

    protected $table = 'tryon_tasks';

    protected $fillable = [
        'user_id', 'item_id', 'model_key', 'source_url', 'source_hash', 'slot', 'garments',
        'status', 'normalized_url', 'result_url', 'provider', 'attempts', 'cost_ms', 'error',
    ];

    protected $casts = [
        'garments' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDone(): bool
    {
        return $this->status == self::STATUS_DONE && !empty($this->result_url);
    }

    /** 接口出参（小程序直接拿这个渲染） */
    public function out(): array
    {
        return [
            'id'         => $this->id,
            'itemId'     => $this->item_id,
            'modelKey'   => $this->model_key,
            'status'     => $this->status,
            'resultUrl'  => (string) $this->result_url,
            'garments'   => (array) ($this->garments ?: []),
            // 失败给用户看的是友好文案，技术细节只留在 error 里给后台排查
            'error'      => $this->status == self::STATUS_FAILED ? $this->friendlyError() : '',
            'createdAt'  => $this->created_at ? $this->created_at->format('Y-m-d H:i') : '',
        ];
    }

    /** 失败原因翻成人话（技术原因只进日志/后台，不甩给用户） */
    public function friendlyError(): string
    {
        $e = (string) $this->error;

        if (str_contains($e, 'Throttling') || str_contains($e, 'rate limit')) {
            return '现在排队的人有点多，过一会儿再试';
        }
        if (str_contains($e, 'DataInspection') || str_contains($e, 'media')) {
            return '这件衣服的照片没识别出来，换张平铺图再试';
        }
        if (str_contains($e, 'timeout') || str_contains($e, 'TIMEOUT')) {
            return '生成超时了，再试一次';
        }

        return '这次没生成成功，再试一次';
    }
}
