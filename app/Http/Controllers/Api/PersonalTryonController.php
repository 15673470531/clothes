<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunPersonalTryon;
use App\Models\ClothesItem;
use App\Models\User;
use App\Services\ImageStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class PersonalTryonController extends Controller
{
    public static function allowed($user): bool
    {
        return app()->environment('local') || (bool) $user->is_admin || in_array((string) $user->id, config('tryon.personal_test_users', []), true);
    }

    public static function enabled(): bool
    {
        return (bool) config('tryon.personal_enabled') && (bool) config('tryon.api_key') && config('queue.default') !== 'sync' && parse_url(config('app.url'), PHP_URL_SCHEME) === 'https';
    }

    private function gate(Request $r): void
    {
        abort_unless(self::allowed($r->user()), 403, '试穿正在内测');
    }

    private function ok($data)
    {
        return response()->json(['code' => 0, 'msg' => 'success', 'data' => $data]);
    }

    public static function mediaUrl(string $kind, int $id): string
    {
        return URL::temporarySignedRoute('personal-tryon.media', now()->addMinutes(30), ['kind' => $kind, 'id' => $id]);
    }

    private function taskOut($t): array
    {
        return ['id' => $t->id, 'portraitId' => $t->portrait_id, 'itemId' => $t->item_id, 'name' => $t->item_name, 'status' => $t->status,
            'resultUrl' => $t->result_path ? self::mediaUrl('result', $t->id) : '',
            'portraitUrl' => self::mediaUrl('portrait', $t->portrait_id), 'error' => $t->error, 'createdAt' => $t->created_at];
    }

    public function index(Request $r)
    {
        if (! self::allowed($r->user())) {
            return $this->ok(['allowed' => false]);
        }
        if ($r->boolean('capability')) {
            return $this->ok(['allowed' => true, 'enabled' => self::enabled()]);
        }
        // worker 异常退出的任务不会无限占用额度；超过20分钟不再接收其结果。
        DB::table('personal_tryons')->where('user_id', $r->user()->id)->whereIn('status', ['pending', 'running'])->where('updated_at', '<', now()->subMinutes(20))->update(['status' => 'failed', 'error' => '任务等待超时，请重新生成', 'updated_at' => now()]);
        $portraits = DB::table('personal_portraits')->where('user_id', $r->user()->id)->whereNull('deleted_at')->orderByDesc('id')->get()->map(fn ($p) => ['id' => $p->id, 'url' => self::mediaUrl('portrait', $p->id)]);
        $tasks = DB::table('personal_tryons')->where('user_id', $r->user()->id)->whereNull('deleted_at')->orderByDesc('id')->limit(30)->get()->map(fn ($t) => $this->taskOut($t));
        $enabled = self::enabled();

        return $this->ok(['allowed' => true, 'enabled' => $enabled, 'portraits' => $portraits, 'tasks' => $tasks, 'leftToday' => $this->left($r->user()->id)]);
    }

    private function left(int $uid): int
    {
        $used = DB::table('personal_tryons')->where('user_id', $uid)->where('created_at', '>=', today())->whereIn('status', ['pending', 'running', 'done'])->count();

        return max(0, (int) config('tryon.personal_daily_limit', 3) - $used);
    }

    public function upload(Request $r)
    {
        $this->gate($r);
        $r->validate(['photo' => 'required|image|mimes:jpeg,png|dimensions:min_width=256,min_height=256,max_width=8000,max_height=8000|max:10240']);
        $id = DB::transaction(function () use ($r) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('personal_portraits')->where('user_id', $r->user()->id)->whereNull('deleted_at')->count() >= 5, 422, '最多保存5张个人照片，请先删除不用的照片');
            $path = $r->file('photo')->store('personal-tryon/portraits', 'local');
            abort_unless($path, 500, '照片保存失败');
            try {
                return DB::table('personal_portraits')->insertGetId(['user_id' => $r->user()->id, 'path' => $path, 'created_at' => now(), 'updated_at' => now()]);
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($path);
                throw $e;
            }
        });

        return $this->ok(['id' => $id, 'url' => self::mediaUrl('portrait', $id)]);
    }

    public function create(Request $r)
    {
        $this->gate($r);
        abort_unless(self::enabled(), 422, '试穿服务尚未开启，请先配置服务和后台队列');
        $d = $r->validate(['portraitId' => 'required|integer', 'itemId' => 'required|string|max:64']);
        $task = DB::transaction(function () use ($r, $d) {
            $uid = $r->user()->id;
            User::whereKey($uid)->lockForUpdate()->firstOrFail();
            DB::table('personal_portraits')->where('user_id', $uid)->where('id', $d['portraitId'])->whereNull('deleted_at')->firstOrFail();
            $item = ClothesItem::where('user_id', $uid)->where('client_id', $d['itemId'])->firstOrFail();
            abort_unless($item->category === 'top', 422, '第一版仅支持单件上衣');
            abort_unless($item->image_url, 422, '请先等待衣物照片上传成功');
            $url = app(ImageStorage::class)->out($item->image_url);
            $existing = DB::table('personal_tryons')->where('user_id', $uid)->whereIn('status', ['pending', 'running'])->whereNull('deleted_at')->first();
            if ($existing) {
                abort_unless($existing->portrait_id == $d['portraitId'] && $existing->item_id === $d['itemId'] && $existing->garment_url === $url, 422, '已有试穿正在生成，请等待完成');

                return $existing;
            }
            abort_if($this->left($uid) <= 0, 422, '今天的试穿次数已用完，明天再来');
            $id = DB::table('personal_tryons')->insertGetId(['user_id' => $uid, 'portrait_id' => $d['portraitId'], 'item_id' => $item->client_id, 'garment_url' => $url, 'item_name' => $item->name ?: '上衣', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            RunPersonalTryon::dispatch($id)->afterCommit();

            return DB::table('personal_tryons')->where('id', $id)->first();
        });

        return $this->ok(['task' => $this->taskOut($task)]);
    }

    public function delete(Request $r, string $kind, int $id)
    {
        $this->gate($r);
        abort_unless(in_array($kind, ['portrait', 'result']), 404);
        DB::transaction(function () use ($r, $kind, $id) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $table = $kind === 'portrait' ? 'personal_portraits' : 'personal_tryons';
            $row = DB::table($table)->where('user_id', $r->user()->id)->where('id', $id)->whereNull('deleted_at')->firstOrFail();
            if ($kind === 'portrait') {
                $tasks = DB::table('personal_tryons')->where('portrait_id', $id)->get();
                foreach ($tasks as $t) {
                    if ($t->result_path) {
                        Storage::disk('local')->delete($t->result_path);
                    }
                }
                DB::table('personal_tryons')->where('portrait_id', $id)->update(['deleted_at' => now()]);
                Storage::disk('local')->delete($row->path);
            } elseif ($row->result_path) {
                Storage::disk('local')->delete($row->result_path);
            }
            DB::table($table)->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        });

        return $this->ok(null);
    }

    public function media(Request $r, string $kind, int $id)
    {
        abort_unless(in_array($kind, ['portrait', 'result']), 404);
        $row = DB::table($kind === 'portrait' ? 'personal_portraits' : 'personal_tryons')->where('id', $id)->whereNull('deleted_at')->firstOrFail();
        $path = $kind === 'portrait' ? $row->path : $row->result_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path),['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }
}
