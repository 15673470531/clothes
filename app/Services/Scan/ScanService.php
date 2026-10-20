<?php

namespace App\Services\Scan;

use App\Exceptions\ScanException;
use App\Models\User;
use App\Services\ImageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 穿搭照识别（2026-10）
 *
 * 一次请求做四件事：
 *   1 原图传 OSS 拿公网 https 地址（百炼只吃这种，见 config/scan.php 的说明）
 *   2 通义千问 VL 认衣服 → 只吐 JSON
 *   3 把模型的自由文本**收敛到小程序那套常量表**（category/sub/colors 的 key），
 *     对不上的一律丢掉 / 清空 —— 脏值一旦入库，筛选和统计就全乱了
 *   4 按模型给的框把每件裁出来，走 ImageStorage::putBinary 存 OSS，当这件衣物的照片
 *
 * 三条口径（用户 2026-10 拍板）：
 *   - **不落库、不扣衣架**：这里只返回数据，用户点「加入衣橱」才走 /clothes/sync（那边统一结算）
 *   - 每天 5 次：调用**前**先扣（调用本身就花钱），调用/解析失败回滚（不让用户白扣）
 *   - 认出来的衣物**默认不自动洗白底**（省钱），前端把 normalizeAuto=false 一起提交
 *
 * ⚠️ 常量表跟小程序 utils/mock.js 的 CATEGORIES / SUBCATEGORIES / COLORS **一一对应**。
 *    改一边必须改另一边，否则模型认出来的品类会被这里丢掉。
 */
class ScanService
{
    private const BASE = 'https://dashscope.aliyuncs.com/api/v1';

    /** 一级品类 → 二级（key 跟 utils/mock.js 完全一致） */
    private const SUBS = [
        'top'     => ['tshirt', 'shirt', 'knit', 'hoodie'],
        'bottom'  => ['jeans', 'slacks', 'casual', 'shorts'],
        'outer'   => ['jacket', 'coat', 'down', 'trench'],
        'dress'   => ['onepiece', 'skirt', 'overalls'],
        'set'     => ['suit', 'sport', 'two', 'jumpsuit'],
        'shoes'   => ['sneaker', 'leather', 'boots', 'sandal'],
        'sockhat' => ['socks', 'hat', 'scarf', 'gloves'],
        'acc'     => ['bag', 'jewel', 'belt', 'glasses'],
    ];

    /** 颜色 key（跟 utils/mock.js 的 COLORS 一致） */
    private const COLORS = [
        'black', 'white', 'gray', 'beige', 'brown', 'navy',
        'blue', 'green', 'red', 'pink', 'yellow', 'purple',
    ];

    // 一级品类就是上面 SUBS 的键（认不出来的直接丢，别硬塞到一个相近的品类里）
    // ⚠️ 别写成 `private const CATEGORIES = array_keys(self::SUBS)` —— PHP 常量表达式不允许调用函数
    public function __construct(private readonly ImageStorage $storage)
    {
    }

    /** 功能是否可用（开关 + key 都齐了才算开） */
    public function enabled(): bool
    {
        return (bool) config('scan.enabled') && !empty(config('scan.api_key'));
    }

    /** 入口状态（前端据此决定入口显不显示、要不要先提示还剩几次） */
    public function status(User $user): array
    {
        // 管理员不限次数（跟洗白底一个口径）：前端据此不再拦人
        $unlimited = !empty($user->is_admin);

        return [
            'enabled'    => $this->enabled(),
            'leftToday'  => $this->leftToday((int) $user->id),
            'dailyLimit' => $this->limit(),
            'maxItems'   => $this->maxItems(),
            'unlimited'  => $unlimited,
        ];
    }

    /** 今天还能识别几次 */
    public function leftToday(int $userId): int
    {
        return max(0, $this->limit() - $this->usedToday($userId));
    }

    /**
     * 识别一张穿搭照
     *
     * @return array{sourceUrl: string, count: int, truncated: int, leftToday: int, dailyLimit: int, items: array}
     */
    public function recognize(User $user, UploadedFile $file): array
    {
        if (!$this->enabled()) {
            throw new ScanException(4020, '识别功能还没开，先用「拍照 / 相册」一件件记吧');
        }

        $uid = (int) $user->id;
        $limit = $this->limit();
        // 管理员不限次数（方便自己试；跟洗白底一个口径）
        if (empty($user->is_admin) && $limit > 0 && $this->usedToday($uid) >= $limit) {
            throw new ScanException(4021, '今天识别次数用完了（每天 ' . $limit . ' 次），明天再来');
        }

        // 先把二进制读出来：裁剪要用它，而且 put() 之后临时文件的口径就说不准了
        $binary = (string) @file_get_contents($file->getRealPath());
        if (empty($binary)) {
            throw new ScanException(4022, '这张照片读不到，重新选一张');
        }
        $info = @getimagesizefromstring($binary);
        if (empty($info[0]) || empty($info[1])) {
            throw new ScanException(4022, '这不是一张能识别的图片，重新选一张');
        }

        // 原图先上云：百炼只吃公网 https 直链（临时地址会被数据检查拒掉，洗白底那儿踩过）
        $sourceUrl = '';
        try {
            $put = $this->storage->put($file, 'scan/' . $uid);
            $sourceUrl = (string) ($put['url'] ?? '');
        } catch (\Throwable $e) {
            Log::error('[scan] 原图上传失败', ['err' => mb_substr($e->getMessage(), 0, 200)]);
        }
        if (empty($sourceUrl)) {
            throw new ScanException(4024, '照片没传到云上，检查下网络再试');
        }

        $this->addUse($uid);   // 调用前先扣：这次调用已经要花钱了

        try {
            $raw   = $this->callVision($sourceUrl, (int) $info[0], (int) $info[1]);
            $items = $this->parseItems($raw);
        } catch (\Throwable $e) {
            // 调用失败 / 返回读不懂：回滚次数，不让用户为系统问题买单
            $this->releaseUse($uid);

            if ($e instanceof ScanException) {
                throw $e;
            }

            Log::error('[scan] 识别失败', ['err' => mb_substr($e->getMessage(), 0, 300)]);
            throw new ScanException(4024, '识别没成功，稍后再试');
        }

        if (empty($items)) {
            // 钱花了但一件没认出来：**照样计次**（调用确实发生了），但话要说清楚是重拍
            throw new ScanException(4023, '这张图没认出衣物，换一张全身清楚的正面照试试');
        }

        $max       = $this->maxItems();
        $truncated = max(0, count($items) - $max);
        $wantCrop  = (bool) config('scan.crop', true);

        $out = [];
        foreach (array_slice($items, 0, $max) as $it) {
            $imageUrl = '';
            if ($wantCrop && !empty($it['box'])) {
                $imageUrl = $this->cropAndStore($binary, $it['box'], $uid);
            }
            unset($it['box']);          // 框是内部用的，不外传（P1 要做「拖框微调」再加回来）
            $it['imageUrl'] = $imageUrl;
            $out[] = $it;
        }

        return [
            'sourceUrl'  => $sourceUrl,   // 「用整张穿搭照」兜底 + 以后可重裁
            'count'      => count($out),
            'truncated'  => $truncated,
            'leftToday'  => $this->leftToday($uid),
            'dailyLimit' => $limit,
            'items'      => $out,
        ];
    }

    // ===== 内部：模型调用 =====

    /**
     * 调百炼多模态生成（就是洗白底那个接口，换模型），返回模型原文
     *
     * @param int $w 图宽、$h 图高 —— 要写进提示词，让模型用**绝对像素**给框
     *            （2026-10 实测：光说"0-1000 归一化"它不听，鞋/裤子那种框会给出 1312 这种超过 1000 的数）
     */
    private function callVision(string $imageUrl, int $w, int $h): string
    {
        $body = [
            'model' => (string) config('scan.model'),
            'input' => [
                'messages' => [[
                    'role'    => 'user',
                    'content' => [
                        ['image' => $imageUrl],
                        ['text'  => str_replace(
                            ['{w}', '{h}'],
                            [(string) $w, (string) $h],
                            (string) config('scan.prompt')
                        )],
                    ],
                ]],
            ],
            'parameters' => (object) [],
        ];

        $tries = 3;

        for ($i = 1; $i <= $tries; $i++) {
            $res = Http::withToken((string) config('scan.api_key'))
                ->timeout((int) config('scan.timeout_seconds', 90))
                ->post(self::BASE . '/services/aigc/multimodal-generation/generation', $body);

            if ($res->successful()) {
                return $this->textOf((array) $res->json());
            }

            $code = (string) ($res->json('code') ?? '');
            $msg  = (string) ($res->json('message') ?? $res->body());

            // 连续提交会 429（Throttling.RateQuota）→ 指数退避再试
            if ($res->status() == 429 || str_contains($code, 'Throttling')) {
                Log::warning('[scan] 被限流，稍后重试', ['try' => $i]);
                sleep($i * 3);
                continue;
            }

            // 图被数据检查拒了：多半是地址不是公网直链（本地盘驱动会有这个问题）
            Log::error('[scan] 识别调用失败', ['code' => $code, 'msg' => mb_substr($msg, 0, 300)]);
            throw new ScanException(4024, str_contains($msg, 'DataInspection')
                ? '这张照片没通过云端检查，换一张再试'
                : '识别服务暂时不可用，稍后再试');
        }

        throw new ScanException(4024, '识别服务忙，稍后再试');
    }

    /** 从多模态出参里取文本（content 可能是字符串，也可能是 [{text:...}]） */
    private function textOf(array $data): string
    {
        $content = $data['output']['choices'][0]['message']['content'] ?? '';

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            foreach ($content as $part) {
                if (is_array($part) && !empty($part['text'])) {
                    return (string) $part['text'];
                }
                if (is_string($part) && $part !== '') {
                    return $part;
                }
            }
        }

        return '';
    }

    /** 模型原文 → 干净的 items 数组（每项都是收敛过的 key） */
    private function parseItems(string $raw): array
    {
        // 模型偶尔裹一层 ```json，或前后带一句废话 → 抓第一个 { 到最后一个 }
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $json = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (!is_array($json)) {
            return [];
        }

        $rows = $json['items'] ?? $json;
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        // 兜底 40 条：模型抽风吐几百条时别把后面的裁剪拖死（真正上限在 recognize 里按 max_items 截）
        foreach (array_slice($rows, 0, 40) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $it = $this->normalizeItem($row);
            if (!empty($it)) {
                $out[] = $it;
            }
        }

        return $out;
    }

    /**
     * 一件衣物的字段收敛：品类不在表里就**整件丢掉**（宁可少一件，不要一件脏数据）
     *
     * @return array 空数组 = 这件不要
     */
    private function normalizeItem(array $row): array
    {
        $cat = trim((string) ($row['category'] ?? ''));
        if (!isset(self::SUBS[$cat])) {
            return [];
        }

        // 二级跟一级对不上就清空（前端会显示成「未选细分」，用户自己点一下就行）
        $sub = trim((string) ($row['sub'] ?? ''));
        if ($sub !== '' && !in_array($sub, self::SUBS[$cat], true)) {
            $sub = '';
        }

        $colors = [];
        foreach (array_slice((array) ($row['colors'] ?? []), 0, 6) as $c) {
            $c = strtolower(trim((string) $c));
            if (in_array($c, self::COLORS, true) && !in_array($c, $colors, true)) {
                $colors[] = $c;
            }
        }

        $box   = [];
        $valid = true;
        foreach (array_slice((array) ($row['box'] ?? []), 0, 4) as $v) {
            if (!is_numeric($v)) {
                $valid = false;
                break;
            }
            $box[] = (float) $v;
        }

        return [
            'name'     => mb_substr(trim((string) ($row['name'] ?? '')), 0, 24),
            'category' => $cat,
            'sub'      => $sub,
            'colors'   => $colors,
            'box'      => $valid && count($box) == 4 ? $box : [],
        ];
    }

    // ===== 内部：裁剪 =====

    /**
     * 模型给的框 → 像素坐标（**两种坐标制都认**）
     *
     * 2026-10 实测：提示词里写明「用绝对像素、图是 768x1344」时它照做；
     * 但只写「0-1000 归一化」它会给 [305,1228,471,1312] 这种绝对像素值（比 1000 大）。
     * 所以这里按数值自己判，别只信提示词：
     *  - 有坐标 > 图片长边   → 不可能是绝对像素，按 0-1000 归一化算
     *  - 全部 ≤ 1000 且图比 1000 大 → 只能是归一化
     *  - 其余 → 绝对像素
     *
     * @param array $box [x1,y1,x2,y2]
     * @return array [x1,y1,x2,y2]（像素，未裁剪到图内 —— 外面会 clamp）
     */
    private function boxToPixels(array $box, int $w, int $h): array
    {
        $max = max(array_map('floatval', $box));
        $normalized = ($max > max($w, $h)) || ($max <= 1000 && max($w, $h) > 1000);

        if (!$normalized) {
            return [(float) $box[0], (float) $box[1], (float) $box[2], (float) $box[3]];
        }

        return [
            (float) $box[0] / 1000 * $w,
            (float) $box[1] / 1000 * $h,
            (float) $box[2] / 1000 * $w,
            (float) $box[3] / 1000 * $h,
        ];
    }

    /**
     * 按归一化框（0-1000）从原图裁一块，存进 OSS，返回地址；裁不了返回空串
     *
     * 裁不了就返回空串（这件衣物先没照片，列表用「品类图标 + 颜色块」显示）
     * —— 绝不返回一张糊的 / 黑的图糊弄用户。
     */
    private function cropAndStore(string $binary, array $box, int $uid): string
    {
        $src = @imagecreatefromstring($binary);
        if (!$src) {
            return '';
        }

        $w = imagesx($src);
        $h = imagesy($src);

        [$x1, $y1, $x2, $y2] = $this->boxToPixels($box, $w, $h);

        // 模型偶尔把左上右下写反，先摆正
        if ($x2 < $x1) { [$x1, $x2] = [$x2, $x1]; }
        if ($y2 < $y1) { [$y1, $y2] = [$y2, $y1]; }

        // 四周留余量（框常贴着衣服边，留一点更像商品图）
        $pad = max(0, (float) config('scan.crop_padding', 0.06));
        $padX = ($x2 - $x1) * $pad;
        $padY = ($y2 - $y1) * $pad;

        $x1 = (int) max(0, floor($x1 - $padX));
        $y1 = (int) max(0, floor($y1 - $padY));
        $x2 = (int) min($w, ceil($x2 + $padX));
        $y2 = (int) min($h, ceil($y2 + $padY));

        $cw = $x2 - $x1;
        $ch = $y2 - $y1;

        // 太小 / 太窄的框直接放弃（多半是模型给的噪声框）；占满整图的框也放弃（那是"没找到这件"）
        // 阈值放到 0.4%：鞋、包、腰带在全身照里本来就只有这么大，卡太紧会把它们全丢掉
        if ($cw < 20 || $ch < 20 || $cw * $ch < $w * $h * 0.004 || $cw * $ch > $w * $h * 0.92) {
            imagedestroy($src);
            return '';
        }

        $dst = imagecreatetruecolor($cw, $ch);
        // 白底：源图带透明通道时（png）不铺白会变成黑块
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, $x1, $y1, $cw, $ch, $cw, $ch);
        imagedestroy($src);

        // 缩到最长边上限内（够看花色就行，省流量省存储）
        $maxSide = max(200, (int) config('scan.crop_max_side', 900));
        if (max($cw, $ch) > $maxSide) {
            $scale = $maxSide / max($cw, $ch);
            $nw = max(1, (int) round($cw * $scale));
            $nh = max(1, (int) round($ch * $scale));
            $small = imagecreatetruecolor($nw, $nh);
            imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
            imagecopyresampled($small, $dst, 0, 0, 0, 0, $nw, $nh, $cw, $ch);
            imagedestroy($dst);
            $dst = $small;
        }

        ob_start();
        imagejpeg($dst, null, 88);
        $jpeg = (string) ob_get_clean();
        imagedestroy($dst);

        if (empty($jpeg)) {
            return '';
        }

        try {
            $put = $this->storage->putBinary($jpeg, 'jpg', 'scan/' . $uid);

            return (string) ($put['url'] ?? '');
        } catch (\Throwable $e) {
            Log::warning('[scan] 裁剪图上传失败', ['err' => mb_substr($e->getMessage(), 0, 200)]);

            return '';
        }
    }

    // ===== 内部：次数 =====

    private function maxItems(): int
    {
        return max(1, (int) config('scan.max_items', 8));
    }

    private function limit(): int
    {
        return max(0, (int) config('scan.daily_limit', 5));
    }

    /**
     * 记今天的用量（按天分键，跨天自动归零）
     *
     * 用 Cache 不建表：就是个每天归零的计数器，不值得为它加一张表 + 一次迁移。
     * ⚠️ 必须先 put 一个带过期时间的 0 —— 直接 increment 一个不存在的键，
     *    database 驱动会建一条**永不过期**的记录，跨天不清零。
     */
    private function addUse(int $userId): void
    {
        $key = $this->usedKey($userId);

        if (!Cache::has($key)) {
            Cache::put($key, 0, now()->endOfDay()->addMinutes(5));
        }

        Cache::increment($key);
    }

    /** 回滚一次（调用/解析失败时用）：不让系统问题吃掉用户的次数 */
    private function releaseUse(int $userId): void
    {
        $key = $this->usedKey($userId);

        if ((int) Cache::get($key, 0) > 0) {
            Cache::decrement($key);
        }
    }

    private function usedToday(int $userId): int
    {
        return (int) Cache::get($this->usedKey($userId), 0);
    }

    private function usedKey(int $userId): string
    {
        return 'scan:used:' . $userId . ':' . date('Ymd');
    }
}
