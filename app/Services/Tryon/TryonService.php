<?php

namespace App\Services\Tryon;

use App\Exceptions\TryonException;
use App\Jobs\RunTryon;
use App\Models\ClothesItem;
use App\Models\ClothesOutfit;
use App\Models\TryonGarment;
use App\Models\TryonTask;
use App\Models\User;
use App\Services\ImageStorage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI 试穿（2026-09 · P2 单件 / P3 整套搭配）
 *
 * 链路：衣物照片 →（归一化：洗成白底商品图）→（aitryon：虚拟模特穿上，上装+下装可一次给）→ 结果转存我们 OSS
 *
 * 两条入口走同一套下游：
 *   - 单件（itemId）：衣橱里的一件衣服
 *   - 整套（outfitId）：一条搭配 —— 自动取这套里第一件上装 + 第一件下装，**一次调用**出整套效果
 *     （只传一件时接口会自己配另一件，那就不是用户的搭配了，所以必须两件一起给）
 *
 * 省钱的三层缓存（这是功能能不能跑得起的关键）：
 *   1 结果缓存：键 = (user_id, 试穿对象, model_key, source_hash)；整套的 source_hash = 两张衣物图地址的 md5
 *      → 同一个人 + 同一套 + 同一个模特，第二次直接复用，供应商一次都不调
 *   2 归一化缓存：tryon_garments，一件衣服只洗一次（那一步 15~20 秒、最贵），单件/整套共用
 *   3 重复投递保护：run() 对已 done 的任务直接返回（队列重试/用户连点不重复花钱）
 *
 * 换照片 → source_hash 变 → 缓存自动失效，不用人工清。
 */
class TryonService
{
    public function __construct(
        private readonly TryonProvider $provider,
        private readonly ImageStorage $storage,
    ) {
    }

    /** 功能是否可用（开关 + key + 模特图都齐了才算开） */
    public function enabled(): bool
    {
        return (bool) config('tryon.enabled')
            && !empty(config('tryon.api_key'))
            && !empty(config('tryon.model_image'));
    }

    /** 今天还剩几次（缓存命中不占次数） */
    public function leftToday(int $userId): int
    {
        $limit = (int) config('tryon.daily_limit', 10);
        $used  = TryonTask::where('user_id', $userId)->where('created_at', '>=', today())->count();

        return max(0, $limit - $used);
    }

    /** 单件衣物该穿到哪个槽位（配置表说了算；空串 = 这个品类不支持） */
    public function slotOf(ClothesItem $item): string
    {
        return (string) config('tryon.slots.' . $item->category, '');
    }

    /**
     * 把"要试什么"归一成统一形状：单件和整套都变成 {top, bottom} 两件 + 一个缓存键
     *
     * @return array{key: string, single: ?ClothesItem, top: ?ClothesItem, bottom: ?ClothesItem, sourceHash: string}
     */
    public function plan(User $user, ?ClothesItem $item, ?ClothesOutfit $outfit): array
    {
        $top    = null;
        $bottom = null;

        if (!empty($item)) {
            $slot   = $this->slotOf($item);
            $top    = $slot == 'top' ? $item : null;
            $bottom = $slot == 'bottom' ? $item : null;
            $key    = 'i' . $item->client_id;
        } elseif (!empty($outfit)) {
            [$top, $bottom] = $this->pickGarments($user, $outfit);
            $key            = 'o' . $outfit->client_id;
        } else {
            throw new TryonException(4004, '没说要试哪件衣服或哪套搭配');
        }

        // 缓存指纹：参与试穿的那几张图的地址（换照片 → 指纹变 → 不拿旧结果糊弄）
        $urls = [];
        foreach ([$top, $bottom] as $g) {
            if (!empty($g) && !empty($g->image_url)) {
                $urls[] = (string) $g->image_url;
            }
        }

        return [
            'key'        => $key,
            // 单件试穿时才有的原物件（用来单独报"这件没照片"，整套没照片的会在挑衣服时跳过）
            'single'     => $item,
            'top'        => $top,
            'bottom'     => $bottom,
            'sourceHash' => empty($urls) ? '' : md5(implode('|', $urls)),
        ];
    }

    /**
     * 提交一次试穿（命中缓存秒回结果）
     * @return array{task: TryonTask, cached: bool}
     */
    public function request(User $user, ?ClothesItem $item = null, ?ClothesOutfit $outfit = null): array
    {
        $plan = $this->plan($user, $item, $outfit);
        $this->assertCanRequest($user, $plan);

        $modelKey = (string) config('tryon.model_key');

        $hit = $this->cached((int) $user->id, $plan['key'], $modelKey, $plan['sourceHash']);
        if (!empty($hit)) {
            // 命中缓存：直接把上次的结果给它，一秒出图、零成本
            return ['task' => $hit, 'cached' => true];
        }

        $garments = [];
        foreach (['top' => $plan['top'], 'bottom' => $plan['bottom']] as $slot => $g) {
            if (!empty($g)) {
                $garments[$slot] = (string) $g->client_id;
            }
        }

        $task = TryonTask::create([
            'user_id'     => (int) $user->id,
            'item_id'     => $plan['key'],
            'model_key'   => $modelKey,
            'source_url'  => $this->storage->out($plan['top']->image_url ?? ($plan['bottom']->image_url ?? '')),
            'source_hash' => $plan['sourceHash'],
            'slot'        => $this->slotName($garments),
            'garments'    => $garments,
            'status'      => TryonTask::STATUS_PENDING,
            'provider'    => 'dashscope',
        ]);

        // 交给队列（生成要 20~35 秒，不能卡在请求里）；页面拿 task_id 轮询
        RunTryon::dispatch($task->id);

        return ['task' => $task, 'cached' => false];
    }

    /** 队列里真正干活：归一化（各自可复用）→ 试穿 → 结果转存 OSS */
    public function run(int $taskId): void
    {
        $task = TryonTask::find($taskId);
        if (empty($task) || $task->isDone()) {
            return;   // 没这条 / 已经出图了（重复投递时不重复花钱）
        }

        $start = microtime(true);
        $task->update(['status' => TryonTask::STATUS_RUNNING, 'attempts' => $task->attempts + 1]);

        try {
            $urls = $this->garmentUrls($task);
            if (empty($urls['top']) && empty($urls['bottom'])) {
                throw new TryonException(4005, '这套里没有能试穿的衣服了');
            }

            $topUrl    = empty($urls['top']) ? null : $this->normalized((int) $task->user_id, (string) $urls['top']['id'], (string) $urls['top']['url']);
            $bottomUrl = empty($urls['bottom']) ? null : $this->normalized((int) $task->user_id, (string) $urls['bottom']['id'], (string) $urls['bottom']['url']);

            $remote = $this->provider->tryOn((string) config('tryon.model_image'), $topUrl, $bottomUrl);
            $stored = $this->store($remote);

            $task->update([
                'status'         => TryonTask::STATUS_DONE,
                'normalized_url' => (string) ($topUrl ?? $bottomUrl),
                'result_url'     => $stored,
                'cost_ms'        => (int) round((microtime(true) - $start) * 1000),
                'error'          => '',
            ]);
        } catch (Throwable $e) {
            Log::error('[tryon] 生成失败', ['task' => $task->id, 'err' => $e->getMessage()]);

            $task->update([
                'status'  => TryonTask::STATUS_FAILED,
                'error'   => mb_substr($e->getMessage(), 0, 240),
                'cost_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);

            throw $e;   // 抛出去让队列按 tries/backoff 重试
        }
    }

    /** 同一个试穿对象、同一批图、同一个模特：有没有现成的结果 */
    public function cached(int $userId, string $key, string $modelKey, string $sourceHash): ?TryonTask
    {
        return TryonTask::where('user_id', $userId)
            ->where('item_id', $key)
            ->where('model_key', $modelKey)
            ->where('source_hash', $sourceHash)
            ->where('status', TryonTask::STATUS_DONE)
            ->where('result_url', '!=', '')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 这套搭配里挑出要试的两件：**按搭配里的顺序取第一件上装 + 第一件下装**
     * （二1：自动选，零交互。没照片的不算 —— 试不了）
     * @return array{0: ?ClothesItem, 1: ?ClothesItem}
     */
    private function pickGarments(User $user, ClothesOutfit $outfit): array
    {
        $ids = array_values(array_filter((array) $outfit->item_ids, function ($v) {
            return !empty($v);
        }));
        if (empty($ids)) {
            return [null, null];
        }

        // 按搭配里的顺序遍历（item_ids 的顺序就是用户在页面上看到的顺序）
        $items = ClothesItem::where('user_id', $user->id)->whereIn('client_id', $ids)->get()->keyBy('client_id');

        $top    = null;
        $bottom = null;
        foreach ($ids as $cid) {
            $it = $items->get($cid);
            if (empty($it) || empty($it->image_url)) {
                continue;
            }

            $slot = $this->slotOf($it);
            if ($slot == 'top' && empty($top)) {
                $top = $it;
            }
            if ($slot == 'bottom' && empty($bottom)) {
                $bottom = $it;
            }
        }

        return [$top, $bottom];
    }

    /** 任务里记的那几件 → 当前的图地址（队列执行时重新取，避免用到过期地址） */
    private function garmentUrls(TryonTask $task): array
    {
        $out = ['top' => null, 'bottom' => null];

        foreach (['top', 'bottom'] as $slot) {
            $cid = (string) (((array) $task->garments)[$slot] ?? '');
            if (empty($cid)) {
                continue;
            }

            $item = ClothesItem::where('user_id', $task->user_id)->where('client_id', $cid)->first();
            if (empty($item) || empty($item->image_url)) {
                continue;   // 用户中途删了这件 / 把照片清了，就当这件不试
            }

            $out[$slot] = ['id' => (string) $item->client_id, 'url' => $this->storage->out($item->image_url)];
        }

        return $out;
    }

    /**
     * 归一化：把实拍衣物图洗成白底商品图（tryon_garments 里按"一件衣服 + 一张原图"缓存）
     * 单件试穿和整套试穿共用这份缓存
     */
    private function normalized(int $userId, string $itemId, string $sourceUrl): string
    {
        $hash = md5($sourceUrl);

        // 这件衣物是不是已经有白底图了（用户在记录页洗过 / 把白底图设成了展示图）
        //  → 直接用，别再花钱洗一遍；顺手补上缓存行，后面按（衣物 + 原图）查也能命中
        $item = ClothesItem::where('user_id', $userId)->where('client_id', $itemId)->first();
        if (!empty($item) && !empty($item->normalized_url)
            && ((string) $item->image_url === (string) $item->normalized_url || (string) $item->normalized_source === $hash)) {
            TryonGarment::updateOrCreate(
                ['user_id' => $userId, 'item_id' => $itemId, 'source_hash' => $hash],
                ['normalized_url' => (string) $item->normalized_url]
            );

            return (string) $item->normalized_url;
        }

        $hit = TryonGarment::where('user_id', $userId)
            ->where('item_id', $itemId)
            ->where('source_hash', $hash)
            ->first();

        if (!empty($hit) && !empty($hit->normalized_url)) {
            return (string) $hit->normalized_url;
        }

        $url = $this->store($this->provider->normalize($sourceUrl));

        TryonGarment::updateOrCreate(
            ['user_id' => $userId, 'item_id' => $itemId, 'source_hash' => $hash],
            ['normalized_url' => $url]
        );

        return $url;
    }

    /** 把远程图下载后转存进我们 OSS，返回长期可用的 https 地址 */
    private function store(string $remoteUrl): string
    {
        $res = Http::timeout(120)->get($remoteUrl);
        if (!$res->successful()) {
            throw new TryonException(4008, '结果图下载失败：HTTP ' . $res->status());
        }

        $ext = str_contains(strtolower($remoteUrl), '.png') ? 'png' : 'jpg';
        $put = $this->storage->putBinary($res->body(), $ext, 'tryon');

        return (string) $put['url'];
    }

    /** slot 列的可读值：只试上装=top，只试下装=bottom，整套=both */
    private function slotName(array $garments): string
    {
        if (!empty($garments['top']) && !empty($garments['bottom'])) {
            return 'both';
        }

        return !empty($garments['top']) ? 'top' : 'bottom';
    }

    /**
     * 能不能试（五个闸门，顺序从"最省事"到"最费事"）
     *  4007 功能没开 → 4003 非会员 → 4005 品类/这套没有能试的衣服 → 4004 没照片 → 4006 今天次数用完
     */
    public function assertCanRequest(User $user, array $plan): void
    {
        if (!$this->enabled()) {
            throw new TryonException(4007, '试穿功能还没开放');
        }

        if (config('tryon.member_only') && empty($user->getAttribute('is_member'))) {
            throw new TryonException(4003, '试穿还没开放，有需要可以联系客服');
        }

        if (empty($plan['top']) && empty($plan['bottom'])) {
            throw new TryonException(4005, '这一类还不支持试穿（目前支持上装、外套、下装、连衣裙）');
        }

        // 单件：没照片没法试（整套的没照片衣物在挑衣服那步已经被跳过）
        if (!empty($plan['single']) && empty($plan['single']->image_url)) {
            throw new TryonException(4004, '这件衣物还没有照片，先补张照片再试穿');
        }

        if ($this->leftToday((int) $user->id) <= 0) {
            throw new TryonException(4006, '今天的试穿次数用完了，明天再来');
        }
    }
}
