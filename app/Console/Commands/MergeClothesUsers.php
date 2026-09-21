<?php

namespace App\Console\Commands;

use App\Models\ClothesItem;
use App\Models\ClothesOutfit;
use App\Models\ClothesWearLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 合并「衣物数据散落在多个账号」的用户（2026-09）
 *
 * 背景：线上曾把 APP_ENV 配成 local，登录走了开发模式降级（openid = dev_ + md5(code)），
 * 而**真机的 wx.login code 每次都变** → 每登录一次就多一个用户，衣服散在一堆号里，
 * 「我的」页显示的 ID 每次都不一样。这个命令把这些号的数据并到一个主号上。
 *
 * 用法（**默认只读，什么都不改**）：
 *   php artisan users:merge-clothes                              # 列出每个用户的数据量
 *   php artisan users:merge-clothes --from=3,5,7 --to=9          # 把 3/5/7 的数据迁到 9
 *   php artisan users:merge-clothes --from=3,5,7 --to=9 --delete-empty   # 迁完把空号删掉
 *
 * 冲突口径（三张表的唯一键是 (user_id, client_id) / (user_id, date)）：
 * 目标号已有同 id 的记录时**保留目标的、跳过迁出的**（不覆盖用户现在能看到的数据），
 * 跳过了多少条会在最后打出来。
 */
class MergeClothesUsers extends Command
{
    protected $signature = 'users:merge-clothes
                            {--from= : 要迁出的用户 id，逗号分隔}
                            {--to= : 迁到哪个用户 id}
                            {--delete-empty : 迁完之后，把名下已经没有数据的迁出用户删掉（含 token）}';

    protected $description = '把散落在多个账号里的衣物/搭配/日历迁到一个主号（默认只列出，带 --from/--to 才迁）';

    public function handle(): int
    {
        $from = $this->parseIds((string) $this->option('from'));
        $to = (int) $this->option('to');

        // 不带参数 = 只读报告
        if (empty($from) || $to <= 0) {
            $this->report();
            $this->newLine();
            $this->line('只列出来了，没改任何数据。要迁移：--from=3,5,7 --to=9（先想好哪个号当主号）');

            return self::SUCCESS;
        }

        if (in_array($to, $from, true)) {
            $this->error('--to 不能出现在 --from 里：' . $to);

            return self::FAILURE;
        }

        $target = User::find($to);
        if (! $target) {
            $this->error("目标用户 #{$to} 不存在，先不带参数跑一次看看有哪些号");

            return self::FAILURE;
        }

        $sources = User::whereIn('id', $from)->get();
        if ($sources->isEmpty()) {
            $this->error('--from 里的用户一个都不存在：' . implode(',', $from));

            return self::FAILURE;
        }

        $this->line('迁入：#' . $target->id . '  ' . ($target->openid ?: '(无 openid)') . '  name=' . $target->name);
        $this->line('迁出：' . $sources->map(fn (User $u) => '#' . $u->id)->implode(' '));
        $this->newLine();

        $stat = ['items' => 0, 'itemsSkip' => 0, 'outfits' => 0, 'outfitsSkip' => 0, 'logs' => 0, 'logsSkip' => 0];

        DB::transaction(function () use ($sources, $target, &$stat) {
            $ids = $sources->pluck('id')->all();

            // 衣物
            foreach (ClothesItem::withTrashed()->whereIn('user_id', $ids)->get() as $row) {
                if ($this->exists(ClothesItem::withTrashed(), $target->id, $row->client_id)) {
                    $stat['itemsSkip']++;
                    continue;
                }
                $row->user_id = $target->id;
                $row->save();
                $stat['items']++;
            }

            // 搭配
            foreach (ClothesOutfit::withTrashed()->whereIn('user_id', $ids)->get() as $row) {
                if ($this->exists(ClothesOutfit::withTrashed(), $target->id, $row->client_id)) {
                    $stat['outfitsSkip']++;
                    continue;
                }
                $row->user_id = $target->id;
                $row->save();
                $stat['outfits']++;
            }

            // 日历：唯一键是 (user_id, date)
            foreach (ClothesWearLog::whereIn('user_id', $ids)->get() as $row) {
                $dup = ClothesWearLog::where('user_id', $target->id)->where('date', $row->date)->exists();
                if ($dup) {
                    $stat['logsSkip']++;
                    continue;
                }
                $row->user_id = $target->id;
                $row->save();
                $stat['logs']++;
            }

            // 目标号缺昵称/头像时，从迁出号里补一个（不覆盖目标已有的）
            foreach ($sources as $u) {
                if (empty($target->nickname) && ! empty($u->nickname)) {
                    $target->nickname = $u->nickname;
                }
                if (empty($target->avatar_url) && ! empty($u->avatar_url)) {
                    $target->avatar_url = $u->avatar_url;
                }
            }
            $target->save();
        });

        $this->table(['迁移项', '成功', '跳过（目标已有同 id）'], [
            ['衣物', $stat['items'], $stat['itemsSkip']],
            ['搭配', $stat['outfits'], $stat['outfitsSkip']],
            ['日历', $stat['logs'], $stat['logsSkip']],
        ]);

        if ($this->option('delete-empty')) {
            $this->deleteEmpty($sources, $target);
        } else {
            $this->line('迁出号先留着没删（要删就再加 --delete-empty）');
        }

        $this->newLine();
        $this->info('迁完了。让用户在小程序里重新登录一次，就能看到合并后的数据。');

        return self::SUCCESS;
    }

    /** 只读报告：每个用户有多少数据 */
    private function report(): void
    {
        $hasLastLogin = Schema::hasColumn('users', 'last_login_at');
        $rows = [];

        foreach (User::orderBy('id')->get() as $u) {
            $items = ClothesItem::where('user_id', $u->id)->count();
            $outfits = ClothesOutfit::where('user_id', $u->id)->count();
            $logs = ClothesWearLog::where('user_id', $u->id)->count();

            $rows[] = [
                '#' . $u->id,
                $u->openid ?: '(无)',
                str_starts_with((string) $u->openid, 'dev_') ? '开发模式' : '微信',
                $items,
                $outfits,
                $logs,
                $hasLastLogin ? (string) ($u->last_login_at ?: '-') : '-',
            ];
        }

        $this->table(['id', 'openid', '来源', '衣物', '搭配', '日历', '最后登录'], $rows);
        $this->line('「来源=开发模式」的那些就是每次登录新建出来的号（数据可能散在它们里面）。');
    }

    /** 迁完之后，把名下没有任何数据的迁出号删掉 */
    private function deleteEmpty($sources, User $target): void
    {
        $deleted = [];

        foreach ($sources as $u) {
            if ($u->id === $target->id) {
                continue;
            }
            $left = ClothesItem::withTrashed()->where('user_id', $u->id)->count()
                + ClothesOutfit::withTrashed()->where('user_id', $u->id)->count()
                + ClothesWearLog::where('user_id', $u->id)->count();

            if ($left > 0) {
                $this->warn('#' . $u->id . ' 名下还剩 ' . $left . ' 条（撞 id 跳过的那些），没删');
                continue;
            }

            $u->tokens()->delete();
            $u->delete();
            $deleted[] = '#' . $u->id;
        }

        $this->line($deleted ? '已删除空号：' . implode(' ', $deleted) : '没有可删的空号');
    }

    /** 目标号里是不是已经有这个 client_id / date 的记录 */
    private function exists($query, int $userId, string $clientId): bool
    {
        return $query->where('user_id', $userId)->where('client_id', $clientId)->exists();
    }

    /** "3,5,7" → [3,5,7]（去重、去掉非数字） */
    private function parseIds(string $raw): array
    {
        $ids = array_filter(array_map(fn ($v) => (int) trim($v), explode(',', $raw)));

        return array_values(array_unique($ids));
    }
}
