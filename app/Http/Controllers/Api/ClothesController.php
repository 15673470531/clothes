<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClothesItem;
use App\Models\ClothesOutfit;
use App\Models\ClothesWearLog;
use App\Models\User;
use App\Exceptions\QuotaExceededException;
use App\Services\ImageStorage;
use App\Services\NormalizeService;
use App\Services\Quota;
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
    public function __construct(
        private readonly ImageStorage $storage,
        private readonly Quota $quota,
        private readonly NormalizeService $normalize,
    ) {}

    /**
     * GET /api/clothes/sync
     * 拉这个用户的全部数据 + 被删掉的 id（软删留痕，别的设备据此清掉本地那几条）
     */
    public function pull(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;

        // 洗白底「惰性补洗」（2026-09）：进衣橱顺手把"开着自动、还没洗上"的丢进队列。
        // 放在这里的原因跟「每月赠送」一样 —— 不用定时任务也不会漏；
        // 失败绝不能连累拉数据，所以整块包了 try。
        try {
            $this->normalize->topUp($request->user());
        } catch (\Throwable $e) {
            Log::warning('[normalize] 惰性补洗失败（不影响拉取）', ['err' => mb_substr($e->getMessage(), 0, 200)]);
        }

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
            'items.*.details' => 'sometimes|array:size,brand,price,notes',
            'items.*.details.size' => 'nullable|string|max:24',
            'items.*.details.brand' => 'nullable|string|max:64',
            'items.*.details.price' => ['nullable', 'regex:/^(0|[1-9]\d{0,6})(\.\d{1,2})?$/'],
            'items.*.details.notes' => 'nullable|string|max:500',
            'items.*.name'      => 'nullable|string|max:64',
            'items.*.category'  => 'nullable|string|max:16',
            'items.*.sub'       => 'nullable|string|max:24',
            'items.*.colors'    => 'array',
            'items.*.seasons'   => 'array',
            'items.*.occasions' => 'array',
            'items.*.imageUrl'  => 'nullable|string|max:255',
            // 洗白底那套（可选，老版本小程序不发这两个字段）
            'items.*.originalImageUrl' => 'nullable|string|max:255',
            'items.*.normalizedUrl'    => 'nullable|string|max:255',
            // 自动洗白底（可选，老版本小程序不发）：这件要不要自动洗 + 展示图选的是哪张
            'items.*.normalizeAuto'    => 'nullable|boolean',
            'items.*.coverChoice'      => 'nullable|string|in:,orig,white',
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
        $washIds = [];     // 事务提交后再入队的"要洗白底的衣物"（新增 / 换了照片）

        // 顺序很重要（2026-09 起：衣架）：
        //   ① 先删 —— 删掉一件衣物/一套搭配，那个衣架就腾出来了（总额 +1）
        //   ② 再结算 —— 扣这批里**新增的**衣物 + 新增的搭配（各占一个衣架）
        //   ③ 最后写库
        // 先还再扣，「删一件再加一件」在同一批里就能走通；衣架不够时整批不写库（不做半个批次）。
        // 额度在事务里算，并且先把用户行 lockForUpdate 锁住，免得同一秒两批请求各扣一次。
        try {
            DB::transaction(function () use ($data, $uid, &$counts, &$staleUrls, &$washIds) {
                $freed = 0;   // 这一批腾出来的衣架数

                foreach ($data['deleted']['items'] ?? [] as $cid) {
                    $del = $this->deleteItem($uid, (string) $cid);
                    $staleUrls = array_merge($staleUrls, $del['urls']);
                    if ($del['freed']) {
                        $freed++;
                    }
                    $counts['deletedItems']++;
                }

                foreach ($data['deleted']['outfits'] ?? [] as $cid) {
                    $del = $this->deleteOutfit($uid, (string) $cid);
                    $staleUrls = array_merge($staleUrls, $del['urls']);
                    if ($del['freed']) {
                        $freed++;
                    }
                    $counts['deletedOutfits']++;
                }

                $this->settleQuota(
                    $uid,
                    array_column($data['items'] ?? [], 'id'),
                    array_column($data['outfits'] ?? [], 'id'),
                    $freed
                );

                foreach ($data['items'] ?? [] as $row) {
                    $up = $this->upsertItem($uid, $row);
                    $staleUrls = array_merge($staleUrls, $up['urls']);
                    // 新增衣物 / 换了照片 → 待会儿丢进洗白底队列（改名字、换分类不洗）
                    if ($up['wash']) {
                        $washIds[] = (string) $row['id'];
                    }
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
            });
        } catch (QuotaExceededException $e) {
            // 额度不够：整个请求不写库（不做「半个批次」），小程序会弹对应提示
            return response()->json(['code' => $e->apiCode(), 'msg' => $e->getMessage(), 'data' => null]);
        }

        foreach (array_unique($staleUrls) as $url) {
            $this->storage->deleteByUrl($url);
        }

        // 洗白底自动跑（2026-09 用户定：保存后自动洗、洗好自动换封面）
        // 放在事务外：入队失败绝不能让已经保存好的衣物回滚；钱也只花在真正要洗的件上
        $queued = 0;
        if (!empty($washIds)) {
            try {
                $rows = ClothesItem::where('user_id', $uid)->whereIn('client_id', $washIds)->get()->all();
                $queued = $this->normalize->queue($request->user(), $rows);
            } catch (\Throwable $e) {
                Log::warning('[normalize] 入队失败（衣物已保存好了）', ['err' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        return $this->ok(['counts' => $counts, 'normalizeQueued' => $queued]);
    }

    // ===== 内部 =====

    /**
     * 结算衣架：先把删掉的还回来（refund），再扣这批里**新增的**衣物 + 新增的搭配
     *
     * 口径（2026-09 用户定，改过一次又改回来了，别再来回改）：
     *  - 一个衣架 = 云端挂着的一样东西：**新增**才占（衣物 1 个、搭配 1 个）
     *  - **改了内容不占**：换搭配里的衣物只是改了字段；重出封面 / 换衣物照片都是"换一张"，
     *    旧图会被 deleteByUrl 删掉（见 push 末尾的 $staleUrls），云端总数没变 → 不占
     *  - 删除会还（只还总额，每日不回补，见 Quota::refund）
     * 这样账是平的：余额永远 = 上限 − 云端现在挂着的（衣物 + 搭配）数量
     *
     * 必须在事务里、并且已经锁住用户行（lockForUpdate）——否则同一秒两批请求会各扣一次。
     * 不够就抛 QuotaExceededException，由 push() 转成 {code:4001/4002, msg}，整批不写库。
     */
    private function settleQuota(int $uid, array $itemIds, array $outfitIds, int $refund): void
    {
        $user = User::query()->lockForUpdate()->find($uid);
        if (!$user) {
            return;
        }

        // 先还：这一批删掉的
        if ($refund > 0) {
            $this->quota->refund($user, $refund);
        }

        // 再扣：只有库里还没有的（软删过的会被当成"复活"，重新占一个衣架）
        $newItems = count(array_diff(
            array_unique($itemIds),
            ClothesItem::where('user_id', $uid)->pluck('client_id')->all()
        ));
        $newOutfits = count(array_diff(
            array_unique($outfitIds),
            ClothesOutfit::where('user_id', $uid)->pluck('client_id')->all()
        ));

        $this->quota->consume($user, $newItems + $newOutfits);
    }

    /**
     * 写一条衣物（按 user_id + client_id upsert）
     * @return array 需要清理的旧图地址（换了图才非空）
     */
    /**
     * 写一件衣物（按 user_id + client_id upsert）
     *
     * @return array{urls: array, wash: bool} urls = 要清理的旧图；wash = 这次要不要自动洗白底
     */
    private function upsertItem(int $uid, array $row): array
    {
        $item = ClothesItem::withTrashed()->firstOrNew(['user_id' => $uid, 'client_id' => $row['id']]);
        // 老版本不传 details 时保留；显式传空对象则清空选填属性。
        if (array_key_exists('details', $row)) {
            $item->details = $row['details'];
        }
        $oldUrl = (string) $item->image_url;
        $newUrl = (string) ($row['imageUrl'] ?? '');
        // 改之前的"照片本身"（原图优先）：判断这次是不是换了照片，
        // 只比 image_url 会把「把封面切成白底图」也当成换图 → 白洗一次（花钱）
        $srcBefore = trim((string) ($item->original_image_url ?: $item->image_url));
        $autoBefore = (bool) $item->normalize_auto;

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

        // 洗白底那两列（2026-09）：**只看请求里有没有这个键**，不看值是不是空串 ——
        // 老版本小程序压根不发这两个字段，如果按"空串 = 清空"处理，一推就会把已有的白底图抹掉。
        //  - originalImageUrl：原图（设为封面后 image_url 是白底图，原图靠它留着）
        //  - normalizedUrl：白底图；normalized_source 由服务端算（对应哪张原图，换照片自动作废）
        if (array_key_exists('originalImageUrl', $row)) {
            $item->original_image_url = $this->str($row['originalImageUrl'], 255);
        }
        if (array_key_exists('normalizedUrl', $row)) {
            $normalized = $this->str($row['normalizedUrl'], 255);
            $item->normalized_url    = $normalized;
            $item->normalized_source = empty($normalized)
                ? ''
                : md5($item->original_image_url ?: $newUrl);
        }

        // 自动洗白底的两个字段（2026-09）：老版本小程序不发这两个键 → 保持原样
        if (array_key_exists('normalizeAuto', $row)) {
            $item->normalize_auto = (bool) $row['normalizeAuto'];
        }
        if (array_key_exists('coverChoice', $row)) {
            // 'orig' = 用户明确要用原图 → 自动洗好之后**不覆盖**封面（白底图照样留着）
            $item->cover_choice = $this->str($row['coverChoice'], 8) === 'orig' ? 'orig' : '';
        }

        $item->save();

        // 这次要不要洗白底：换的是"照片本身"才洗（新衣物也算）
        $srcAfter = trim((string) ($item->original_image_url ?: $item->image_url));
        $wash = ($srcAfter !== '' && $srcAfter !== $srcBefore);

        // 另外：用户在这件上把「自动洗白底」从**关**改成**开** = 明示要洗（老衣物想洗就走这个动作）。
        // 没这一条，老衣物（照片没换）永远进不了队列 —— 因为回补默认是关的。
        // 反过来说明：从开改成关不会触发任何洗，也不会清掉已有的白底图。
        if ($srcAfter !== '' && !empty($item->normalize_auto) && !$autoBefore) {
            $wash = true;
        }

        if ($oldUrl === '' || $oldUrl === $newUrl) {
            return ['urls' => [], 'wash' => $wash];
        }

        // 换图了要不要删旧对象：**只有这张图现在没人引用**才删
        // （把白底图设成封面时，旧 image_url 是原图，但它还活在 original_image_url 里 → 不能删，
        //   否则用户点「切回原图」就是一张 404）
        $stillUsed = in_array($oldUrl, array_filter([
            (string) $item->original_image_url,
            (string) $item->normalized_url,
        ]), true);

        return ['urls' => $stillUsed ? [] : [$oldUrl], 'wash' => $wash];
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

    /**
     * 删衣物（软删留痕）+ 返回它的图地址待清理 + 这次删是否腾出一个衣架
     *
     * freed 只在「这次真的从没删变成已删」时为 true —— 重复推同一个已删 id 不会反复还衣架
     * （软删过的查不到，直接返回 false）。
     *
     * @return array{urls: array, freed: bool}
     */
    private function deleteItem(int $uid, string $cid): array
    {
        $item = ClothesItem::where('user_id', $uid)->where('client_id', $cid)->first();
        if (!$item) {
            return ['urls' => [], 'freed' => false];
        }

        $url = (string) $item->image_url;
        $item->delete();

        return ['urls' => $url !== '' ? [$url] : [], 'freed' => true];
    }

    /**
     * 删搭配（软删留痕）+ 返回它的封面地址待清理 + 这次删是否腾出一个衣架
     * @return array{urls: array, freed: bool}
     */
    private function deleteOutfit(int $uid, string $cid): array
    {
        $outfit = ClothesOutfit::where('user_id', $uid)->where('client_id', $cid)->first();
        if (!$outfit) {
            return ['urls' => [], 'freed' => false];
        }

        $url = (string) $outfit->cover_url;
        $outfit->delete();

        // 搭配被删：那天的日历记录跟着清掉，免得日历上留一条「这套已删除」
        ClothesWearLog::where('user_id', $uid)->where('outfit_client_id', $cid)->delete();

        return ['urls' => $url !== '' ? [$url] : [], 'freed' => true];
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
            'details'   => $i->details ?: (object) [],
            'seasons'   => $i->seasons ?: [],
            'occasions' => $i->occasions ?: [],
            'imageUrl'  => $this->storage->out($i->image_url),
            // 洗白底（2026-09）：原图 / 白底图 两个地址 + 当前展示图是不是白底图
            'originalImageUrl' => $this->storage->out($i->original_image_url),
            'normalizedUrl'    => $this->storage->out($i->normalized_url),
            'isWhite'          => !empty($i->normalized_url) && (string) $i->image_url === (string) $i->normalized_url,
            // 自动洗白底（2026-09）：状态（''/queued/running/done/failed/skipped）+ 这件自己的自动勾选 + 用户选过哪张当封面
            'normalizeStatus'  => (string) $i->normalize_status,
            'normalizeAuto'    => (bool) $i->normalize_auto,
            'coverChoice'      => (string) $i->cover_choice,
            // 这张图存在哪：oss = 对象存储，local = 服务器本地盘（小程序格子右下角挂牌用）
            'imageStorage' => $this->storage->driverOf($i->image_url),
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
            'coverStorage' => $this->storage->driverOf($o->cover_url),
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
