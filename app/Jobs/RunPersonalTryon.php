<?php

namespace App\Jobs;

use App\Http\Controllers\Api\PersonalTryonController;
use App\Models\User;
use App\Services\Tryon\TryonProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RunPersonalTryon implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 1;

    public int $timeout = 280;

    public function __construct(public int $taskId) {}

    public function handle(TryonProvider $provider): void
    {
        $claimed = DB::table('personal_tryons')->where('id', $this->taskId)->where('status', 'pending')->whereNull('deleted_at')->update(['status' => 'running', 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $task = DB::table('personal_tryons')->where('id', $this->taskId)->first();
        try {
            if (! config('tryon.personal_enabled')) {
                throw new \RuntimeException('disabled');
            }
            $portrait = DB::table('personal_portraits')->where('id', $task->portrait_id)->whereNull('deleted_at')->firstOrFail();
            // 直接使用单件衣物照片，不在个人试穿中额外触发付费洗图。
            $remote = $provider->tryOn(PersonalTryonController::mediaUrl('portrait', $portrait->id), $task->garment_url, null);
            if (parse_url($remote, PHP_URL_SCHEME) !== 'https') {
                throw new \RuntimeException('invalid result');
            }
            $res = Http::timeout(60)->get($remote);
            $bytes = $res->body();
            if (! $res->successful() || strlen($bytes) > 20 * 1024 * 1024 || ! @getimagesizefromstring($bytes)) {
                throw new \RuntimeException('invalid image');
            }
            DB::transaction(function () use ($task, $bytes) {
                User::whereKey($task->user_id)->lockForUpdate()->firstOrFail();
                $current = DB::table('personal_tryons')->where('id', $task->id)->whereNull('deleted_at')->where('status', 'running')->first();
                $portrait = DB::table('personal_portraits')->where('id', $task->portrait_id)->whereNull('deleted_at')->first();
                if (! $current || ! $portrait) {
                    return;
                }
                $path = 'personal-tryon/results/'.Str::uuid().'.png';
                if (! Storage::disk('local')->put($path, $bytes)) {
                    throw new \RuntimeException('storage failed');
                }
                try {
                    DB::table('personal_tryons')->where('id', $task->id)->update(['status' => 'done', 'result_path' => $path, 'updated_at' => now()]);
                } catch (Throwable $e) {
                    Storage::disk('local')->delete($path);
                    throw $e;
                }
            });
        } catch (Throwable $e) {
            $this->failed($e);
        }
    }

    public function failed(?Throwable $e): void
    {
        DB::table('personal_tryons')->where('id', $this->taskId)->whereIn('status', ['pending', 'running'])->update(['status' => 'failed', 'error' => '生成未成功，本次不扣次数，请换张清晰照片重试', 'updated_at' => now()]);
    }
}
