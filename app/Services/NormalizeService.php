<?php

namespace App\Services;

use App\Exceptions\TryonException;
use App\Models\ClothesItem;
use App\Models\TryonGarment;
use App\Models\User;
use App\Services\Tryon\TryonProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 洗白底（归一化）· 记录衣物页那个按钮的后端（2026-09）
 *
 * 干的事：把用户实拍的衣服照片，按 config('tryon.normalize_model')（默认 wan2.7-image）
 * 洗成「白底、正面、平铺、干净」的商品图，存进我们 OSS，把地址回去；由用户决定要不要
 * 拿它当这件衣物的展示图（image_url）—— **原图一列永远保留**。
 *
 * 三个必须守住的规矩（都是钱和口碑）：
 *  1 **不重复洗**：同一件衣物 + 同一张原图只洗一次。三层挡：
 *    ① 衣物行上已有 normalized_url 且 normalized_source 跟当前原图对得上 → 直接给
 *    ② tryon_garments 那张缓存表（单件试穿 / 整套试穿 / 这个按钮共用）
 *    ③ 用户换了照片 → source_hash 变了，自然重新洗（旧的不会再被用）
 *  2 **命中缓存 / 失败都不算次数**：每天 N 次免费说的是"真烧了一次模型调用"
 *  3 **失败不留痕**：抛异常给控制器转成 {code,msg}，计数不动、库里不写半截数据
 *
 * 为什么同步（不排队）：出图 15~20 秒，用户在记录页盯着按钮，转圈等他更能接受；
 * 走队列就得常驻 worker（试穿现在是隐藏状态，不想为这个功能再拉一个依赖）。
 */
class NormalizeService
{
    /** 还没保存的衣物（用户还在记录页里）用一个固定 item_id 进缓存，省得同一张图洗两遍 */
    private const PENDING = 'pending';

    /** 白底图存哪个目录（跟衣物照片同一个目录，它随时可能变成这件衣物的展示图） */
    private const DIR = 'clothes';

    /** 服务端压图：长边超过这个值就缩（wan2.7 出的图 6~7MB，直接用会拖慢衣橱和试穿上传） */
    private const MAX_SIDE = 1600;

    public function __construct(
        private readonly ImageStorage $storage,
        private readonly TryonProvider $provider,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('tryon.normalize_enabled');
    }

    /** 入口状态：前端据此决定这个入口显不显示、还剩几次（管理员不限次数） */
    public function status(User $user): array
    {
        $this->refreshDaily($user);
        $unlimited = $this->isAdmin($user);

        return [
            'enabled'    => $this->enabled(),
            'leftToday'  => $unlimited ? $this->limit() : $this->leftToday($user),
            'dailyLimit' => $this->limit(),
            // 管理员不限次数：前端据此不再拦人，也不用显示"还剩几次"
            'unlimited'  => $unlimited,
        ];
    }

    /**
     * 洗一张
     *
     * @param string $itemId    已有衣物的 client_id；记录页里还没保存的可以传空串
     * @param string $sourceUrl 要洗的原图（必须是我们 OSS 的公网 https 直链）
     * @return array{normalizedUrl:string,sourceUrl:string,cached:bool,leftToday:int}
     */
    public function normalize(User $user, string $itemId, string $sourceUrl): array
    {
        if (!$this->enabled()) {
            throw new TryonException(4009, '洗白底的功能还没开放');
        }

        $sourceUrl = trim($sourceUrl);
        if (empty($sourceUrl)) {
            throw new TryonException(4004, '这件衣物还没有照片，先拍一张再洗');
        }

        $hash = md5($sourceUrl);
        $item = empty($itemId)
            ? null
            : ClothesItem::where('user_id', (int) $user->id)->where('client_id', $itemId)->first();

        // 传了 itemId 却查不到（删了 / 是别人的）→ 直接拒。
        // 不能当成"还没保存的新衣物"往下走：那就变成谁都能拿别人的 itemId 洗图了。
        if (!empty($itemId) && empty($item)) {
            throw new TryonException(4004, '这件衣物不在了，存一下再洗');
        }

        // ① 这件衣物已经洗过同一张原图：直接给，不花钱也不计次
        if (!empty($item) && !empty($item->normalized_url) && (string) $item->normalized_source === $hash) {
            return $this->result((string) $item->normalized_url, $sourceUrl, true, $user);
        }

        $cacheKey = empty($item) ? self::PENDING : (string) $item->client_id;

        // ② 洗图缓存（一件衣服 + 一张原图一行；单件/整套试穿也共用这张表）
        $hit = TryonGarment::where('user_id', (int) $user->id)
            ->where('item_id', $cacheKey)
            ->where('source_hash', $hash)
            ->first();

        if (!empty($hit) && !empty($hit->normalized_url)) {
            $this->remember($item, (string) $hit->normalized_url, $hash, $sourceUrl);

            return $this->result((string) $hit->normalized_url, $sourceUrl, true, $user);
        }

        // ③ 每天免费次数（只在真洗之前才扣，缓存命中上面已经返回了）
        //    管理员不限次数，也不占用计数（客服/自己试效果用）
        $this->refreshDaily($user);
        if (!$this->isAdmin($user) && (int) $user->normalize_used >= $this->limit()) {
            throw new TryonException(4010, '今天洗白底的次数用完了（每天 ' . $this->limit() . ' 次），明天再来');
        }

        // ④ 真洗（15~20 秒），洗完转存我们 OSS（阿里云给的是带签名的临时地址，会过期）
        $url = $this->store($this->provider->normalize($sourceUrl));

        TryonGarment::updateOrCreate(
            ['user_id' => (int) $user->id, 'item_id' => $cacheKey, 'source_hash' => $hash],
            ['normalized_url' => $url]
        );

        $this->remember($item, $url, $hash, $sourceUrl);

        if (!$this->isAdmin($user)) {
            $user->normalize_used = (int) $user->normalize_used + 1;
            $user->save();
        }

        return $this->result($url, $sourceUrl, false, $user);
    }

    // ===== 内部 =====

    private function result(string $url, string $sourceUrl, bool $cached, User $user): array
    {
        return [
            'normalizedUrl' => $url,
            'sourceUrl'     => $sourceUrl,
            'cached'        => $cached,
            'leftToday'     => $this->isAdmin($user) ? $this->limit() : $this->leftToday($user),
            'dailyLimit'    => $this->limit(),
            'unlimited'     => $this->isAdmin($user),
        ];
    }

    /**
     * 把白底图记到这件衣物上（还没保存的衣物就没有这一步）
     *
     * 顺手补原图：用户先洗白底、再保存，保存时小程序也会带 originalImageUrl，
     * 但「编辑已有衣物」走了这条分支时原图列可能还是空的，这里补上最稳。
     */
    private function remember(?ClothesItem $item, string $url, string $hash, string $sourceUrl): void
    {
        if (empty($item)) {
            return;
        }

        if ((string) $item->normalized_url === $url && (string) $item->normalized_source === $hash) {
            return;
        }

        $item->normalized_url    = $url;
        $item->normalized_source = $hash;
        if (empty($item->original_image_url)) {
            $item->original_image_url = $sourceUrl;
        }
        $item->save();
    }

    /** 下载远程结果 → 压一道 → 存自己 OSS */
    private function store(string $remoteUrl): string
    {
        $res = Http::timeout(120)->get($remoteUrl);
        if (!$res->successful()) {
            throw new TryonException(4008, '白底图下载失败：HTTP ' . $res->status());
        }

        $binary = $this->shrink($res->body());

        return (string) $this->storage->putBinary($binary, 'jpg', self::DIR)['url'];
    }

    /**
     * 服务端压图（GD）
     *
     * 为什么要压：wan2.7-image 出的是 6~7MB 的 2K PNG，它随时会变成衣橱里的展示图，
     * 也还要喂给试穿模型 —— 不压的话衣橱加载和上传都慢得明显。
     * 压不动（格式不认 / 没 GD）就原样返回，**绝不让压图把功能弄挂**。
     */
    private function shrink(string $binary): string
    {
        if (!function_exists('imagecreatefromstring') || empty($binary)) {
            return $binary;
        }

        $raw = @imagecreatefromstring($binary);
        if (!$raw) {
            return $binary;
        }

        $w = imagesx($raw);
        $h = imagesy($raw);
        $scale = min(1, self::MAX_SIDE / max($w, $h));

        $canvas = $raw;
        if ($scale < 1) {
            $canvas = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
            // 白底图放大缩小都铺白底，避免出现透明边
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $raw, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $w, $h);
        }

        ob_start();
        imagejpeg($canvas, null, 88);
        $out = (string) ob_get_clean();

        imagedestroy($canvas);
        if ($canvas !== $raw) {
            imagedestroy($raw);
        }

        if (empty($out)) {
            Log::warning('[normalize] 压图产出为空，用原图');

            return $binary;
        }

        return $out;
    }

    /** 惰性按天重置（跟 Quota 的每日额度一个套路：哪天忘了跑定时任务也不会卡住） */
    private function refreshDaily(User $user): void
    {
        $today = date('Y-m-d');

        if ((string) $user->normalize_date !== $today) {
            $user->normalize_used = 0;
            $user->normalize_date = $today;
            $user->save();
        }
    }

    /** 管理员：不限次数（is_admin，跟管理端下钻页同一个标记） */
    private function isAdmin(User $user): bool
    {
        return !empty($user->is_admin);
    }

    private function leftToday(User $user): int
    {
        return max(0, $this->limit() - (int) $user->normalize_used);
    }

    private function limit(): int
    {
        return max(0, (int) config('tryon.normalize_daily_limit'));
    }
}
