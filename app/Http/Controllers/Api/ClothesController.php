<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClothesItem;
use App\Models\ClothesOutfit;
use App\Models\ClothesWearLog;
use App\Services\ImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 衣物 / 搭配 / 日历 数据同步（2026-09 二期第二步：数据上云）
 *
 * 只有两个接口，**不搞每个动作一个接口**：
 *   GET  /api/clothes/sync   拉全量（进页面/登录后一次）
 *   POST /api/clothes/sync   批量推（小程序自己攒的「待推队列」，一次请求全推上去）
 *
 * 为什么这么设计：小程序是「本机 storage 当缓存 + 后台同步」，弱网下要能把攒的改动
 * 一次推完（跟照片补传 img-sync 一个套路）；每条记录一个接口的话，离线攒了 20 条改动
 * 就得发 20 个请求，还得各自重试。合并策略由小程序侧决定：**本机待推的改动优先，其余以云端为准**。
 *
 * 多端（开发者工具 + 真机）同时改同一条时后推的赢（last-write-wins，用户 2026-09 拍板）。
 */
class ClothesController extends Controller
{
    public function __construct(private readonly ImageStorage $storage) {}

    /**
     * GET /api/clothes/sync
     * 拉这个用户的全部数据 + 被删掉的 id（软删留痕，别的设备据此清掉本地那几条）
     */
    public function pull(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;

        $items = ClothesItem::where('user_id', $uid)
            ->orderByDesc('client_created_at')->orderByDesc('id')->get();

        $outfits = ClothesOutfit::where('user_id', $uid)
            ->orderByDesc('client_created_at')->orderByDesc('id')->get();

        $wears = [];
        ClothesWearLog::where('user_id', $uid)->where('outfit_client_id', '!=', '')->get()
            ->each(function (ClothesWearLog $w) use (&$wears) {
                $wears[$w->date->format('Y-m-d')] = $w->outfit_client_id;
            });

        return $this->ok([
            'items'   => $items->map(fn (ClothesItem $i) => $this->itemOut($i))->values(),
            'outfits' => $outfits->map(fn (ClothesOutfit $o) => $this->outfitOut($o))->values(),
            'wears'   => (object) $wears,
            'deleted' => [
                'items'   => ClothesItem::onlyTrashed()->where('user_id', $uid)->pluck('client_id')->values(),
                'outfits' => ClothesOutfit::onlyTrashed()->where('user_id', $uid)->pluck('client_id')->values(),
            ],
        ]);
    }

    /**
     * POST /api/clothes/sync
     * 批量推：{ items:[...], outfits:[...], wears:{'YYYY-MM-DD': 搭配id}, deleted:{items:[],outfits:[]} }
     *
     * 一个事务写库；OSS 上的旧图（换了图 / 删了记录）**等事务提交后**再删，
     * 免得事务回滚了图却已经没了。
     */
    public function push(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;

        $data = $request->validate([
            'items'             => 'array',
            'items.*.id'        => 'required|string|max:32',
            'items.*.name'      => 'nullable|string|max:64',
            'items.*.category'  => 'nullable|string|max:16',
            'items.*.sub'       => 'nullable|string|max:24',
            'items.*.colors'    => 'array',
            'items.*.seasons'   => 'array',
            'items.*.occasions' => 'array',
            'items.*.imageUrl'  => 'nullable|string|max:255',
            'items.*.createdAt' => 'nullable|integer',

            'outfits'              => 'array',
            'outfits.*.id'         => 'required|string|max:32',
            'outfits.*.name'       => 'nullable|string|max:64',
            'outfits.*.nameAuto'   => 'nullable|boolean',
            'outfits.*.occasions'  => 'array',
            'outfits.*.itemIds'    => 'array',
            'outfits.*.slots'      => 'array',
            'outfits.*.coverUrl'   => 'nullable|string|max:255',
            'outfits.*.createdAt'  => 'nullable|integer',

            'wears'            => 'array',
            'deleted'          => 'array',
            'deleted.items'    => 'array',
            'deleted.outfits'  => 'array',
        ]);

        $counts = ['items' => 0, 'outfits' => 0, 'wears' => 0, 'deletedItems' => 0, 'deletedOutfits' => 0];
        $staleUrls = [];   // 事务提交后再删的 OSS 对象

        DB::transaction(function () use ($data, $uid, &$counts, &$staleUrls) {
            foreach ($data['items'] ?? [] as $row) {
                $staleUrls = array_merge($staleUrls, $this->upsertItem($uid, $row));
                $counts['items']++;
            }

            foreach ($data['outfits'] ?? [] as $row) {
                $staleUrls = array_merge($staleUrls, $this->upsertOutfit($uid, $row));
                $counts['outfits']++;
            }

            foreach ($data['wears'] ?? [] as $date => $outfitId) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                    continue;
                }
                $this->putWear($uid, (string) $date, (string) $outfitId);
                $counts['wears']++;
            }

            foreach ($data['deleted']['items'] ?? [] as $cid) {
                $staleUrls = array_merge($staleUrls, $this->deleteItem($uid, (string) $cid));
                $counts['deletedItems']++;
            }

            foreach ($data['deleted']['outfits'] ?? [] as $cid) {
                $staleUrls = array_merge($staleUrls, $this->deleteOutfit($uid, (string) $cid));
                $counts['deletedOutfits']++;
            }
        });

        foreach (array_unique($staleUrls) as $url) {
            $this->storage->deleteByUrl($url);
        }

        return $this->ok(['counts' => $counts]);
    }

    // ===== 内部 =====

    /**
     * 写一条衣物（按 user_id + client_id upsert）
     * @return array 需要清理的旧图地址（换了图才非空）
     */
    private function upsertItem(int $uid, array $row): array
    {
        $item = ClothesItem::withTrashed()->firstOrNew(['user_id' => $uid, 'client_id' => $row['id']]);
        $oldUrl = (string) $item->image_url;
        $newUrl = (string) ($row['imageUrl'] ?? '');

        $item->fill([
            'name'              => $this->str($row['name'] ?? '', 64),
            'category'          => $this->str($row['category'] ?? '', 16),
            'sub'               => $this->str($row['sub'] ?? '', 24),
            'colors'            => $this->strList($row['colors'] ?? [], 16),
            'seasons'           => $this->strList($row['seasons'] ?? [], 8),
            'occasions'         => $this->strList($row['occasions'] ?? [], 8),
            'image_url'         => $newUrl,
            'client_created_at' => (int) ($row['createdAt'] ?? 0),
        ]);
        // 之前被删过又被推上来的，算复活（用户在多端操作时可能出现）
        $item->deleted_at = null;
        $item->save();

        return ($oldUrl !== '' && $oldUrl !== $newUrl) ? [$oldUrl] : [];
    }

    /**
     * 写一条搭配（按 user_id + client_id upsert）
     * @return array 需要清理的旧封面地址
     */
    private function upsertOutfit(int $uid, array $row): array
    {
        $outfit = ClothesOutfit::withTrashed()->firstOrNew(['user_id' => $uid, 'client_id' => $row['id']]);
        $oldUrl = (string) $outfit->cover_url;
        $newUrl = (string) ($row['coverUrl'] ?? '');

        $slots = [];
        foreach (array_slice((array) ($row['slots'] ?? []), 0, 9) as $s) {
            $slots[] = is_string($s) && $s !== '' ? $s : null;
        }

        $outfit->fill([
            'name'              => $this->str($row['name'] ?? '', 64),
            'name_auto'         => (bool) ($row['nameAuto'] ?? false),
            'occasions'         => $this->strList($row['occasions'] ?? [], 8),
            'item_ids'          => $this->strList($row['itemIds'] ?? [], 30),
            'slots'             => $slots,
            'cover_url'         => $newUrl,
            'client_created_at' => (int) ($row['createdAt'] ?? 0),
        ]);
        $outfit->deleted_at = null;
        $outfit->save();

        return ($oldUrl !== '' && $oldUrl !== $newUrl) ? [$oldUrl] : [];
    }

    /** 记 / 清某天的穿搭（outfitId 为空串 = 清除这天） */
    private function putWear(int $uid, string $date, string $outfitId): void
    {
        if ($outfitId === '') {
            ClothesWearLog::where('user_id', $uid)->where('date', $date)->delete();

            return;
        }

        ClothesWearLog::updateOrCreate(
            ['user_id' => $uid, 'date' => $date],
            ['outfit_client_id' => $this->str($outfitId, 32)]
        );
    }

    /** 删衣物（软删留痕）+ 返回它的图地址待清理 */
    private function deleteItem(int $uid, string $cid): array
    {
        $item = ClothesItem::where('user_id', $uid)->where('client_id', $cid)->first();
        if (!$item) {
            return [];
        }

        $url = (string) $item->image_url;
        $item->delete();

        return $url !== '' ? [$url] : [];
    }

    /** 删搭配（软删留痕）+ 返回它的封面地址待清理 */
    private function deleteOutfit(int $uid, string $cid): array
    {
        $outfit = ClothesOutfit::where('user_id', $uid)->where('client_id', $cid)->first();
        if (!$outfit) {
            return [];
        }

        $url = (string) $outfit->cover_url;
        $outfit->delete();

        // 搭配被删：那天的日历记录跟着清掉，免得日历上留一条「这套已删除」
        ClothesWearLog::where('user_id', $uid)->where('outfit_client_id', $cid)->delete();

        return $url !== '' ? [$url] : [];
    }

    /** 衣物 → 接口出参（字段名跟小程序本机那份保持一致，前端不用做映射） */
    private function itemOut(ClothesItem $i): array
    {
        return [
            'id'        => $i->client_id,
            'name'      => (string) $i->name,
            'category'  => (string) $i->category,
            'sub'       => (string) $i->sub,
            'colors'    => $i->colors ?: [],
            'seasons'   => $i->seasons ?: [],
            'occasions' => $i->occasions ?: [],
            'imageUrl'  => $this->storage->out($i->image_url),
            'createdAt' => (int) $i->client_created_at,
        ];
    }

    private function outfitOut(ClothesOutfit $o): array
    {
        return [
            'id'         => $o->client_id,
            'name'       => (string) $o->name,
            'nameAuto'   => (bool) $o->name_auto,
            'occasions'  => $o->occasions ?: [],
            'itemIds'    => $o->item_ids ?: [],
            'slots'      => $o->slots ?: [],
            'coverUrl'   => $this->storage->out($o->cover_url),
            'createdAt'  => (int) $o->client_created_at,
        ];
    }

    /** 截断 + 转字符串（用户传的内容一律不当路径/不当长度用） */
    private function str($v, int $max): string
    {
        return mb_substr(trim((string) $v), 0, $max);
    }

    /** 字符串数组：过滤空值、去重、限量 */
    private function strList($v, int $max): array
    {
        $out = [];
        foreach (array_slice((array) $v, 0, $max) as $x) {
            if (is_string($x) && $x !== '') {
                $out[] = mb_substr($x, 0, 32);
            }
        }

        return array_values(array_unique($out));
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['code' => 0, 'msg' => 'success', 'data' => $data]);
    }
}
