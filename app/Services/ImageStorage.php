<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OSS\Core\OssException;
use OSS\OssClient;
use RuntimeException;

/**
 * 图片存储（2026-09 二期第一步：图片上云）
 *
 * 方案 A：小程序 → 本后端 → OSS（服务端中转，跟已有的头像上传同一条路）
 *
 *  - **配了** OSS_ACCESS_KEY_ID / SECRET / ENDPOINT / BUCKET → 传 OSS
 *  - **没配** → 落本地 public 盘（storage/app/public/...），返回 APP_URL/storage/... 的地址
 *    这样本地开发不用先申请密钥，线上把 env 填上就自动切 OSS，调用方一行都不用改
 *
 * 目录规范：{dir}/{Ym}/{随机名}.{ext}
 *   - dir 由调用方拼好并做白名单限定（clothes/{userId}、avatar/{userId}、outfit/{userId}），
 *     **不要把用户传进来的字符串直接当路径**
 *   - 随机名（不用原名）：避免中文/特殊字符，也避免同名覆盖 + 让别人猜不到地址
 *
 * 返回统一是 ['path' => 对象键, 'url' => 可直接给 <image src> 的地址, 'driver' => 'oss'|'local']
 */
class ImageStorage
{
    /** 现在用的是哪种驱动（给接口回包/排查用） */
    public function driver(): string
    {
        return $this->isOss() ? 'oss' : 'local';
    }

    public function isOss(): bool
    {
        $c = config('services.oss', []);

        return !empty($c['access_key_id']) && !empty($c['access_key_secret'])
            && !empty($c['endpoint']) && !empty($c['bucket']);
    }

    /**
     * 存一张图，返回 ['path' => ..., 'url' => ..., 'driver' => ...]
     *
     * @param  string  $dir  目录（调用方拼好，如 clothes/12）
     */
    public function put(UploadedFile $file, string $dir): array
    {
        $dir = trim($dir, '/');
        $key = $dir . '/' . date('Ym') . '/' . Str::random(26) . '.' . $this->ext($file);

        if (!$this->isOss()) {
            // 本地兜底：落到 storage/app/public（跟头像原来一样），URL 走 APP_URL/storage/...
            $path = Storage::disk('public')->putFileAs($dir . '/' . date('Ym'), $file, basename($key));

            return [
                'path'   => $path,
                // 按「当前请求的域名」拼，不用 APP_URL（线上 .env 里 APP_URL 常是 localhost，
                // 那样回给手机的就是 http://localhost/... ，手机上必然加载不出图）
                'url'    => $this->baseUrl() . '/storage/' . ltrim($path, '/'),
                'driver' => 'local',
            ];
        }

        try {
            // uploadFile 走分片上传，大图（几 MB）比 putObject 稳
            $this->client()->uploadFile(config('services.oss.bucket'), $key, $file->getRealPath());
        } catch (OssException $e) {
            // 不静默回退本地：传 OSS 失败要让调用方知道（小程序那边会保留本地图、下次补传）
            Log::error('[oss] 上传失败', ['key' => $key, 'err' => $e->getMessage()]);
            throw new RuntimeException('上传到 OSS 失败：' . $e->getMessage(), 0, $e);
        }

        return ['path' => $key, 'url' => $this->url($key), 'driver' => 'oss'];
    }

    /**
     * 出参用：把「库里的图片地址」规范化成「当前请求域名下的地址」
     *
     *  - 本地兜底盘的图（.../storage/xxx）：主机名重写成当前请求的域名
     *    （历史数据里存的是 APP_URL=localhost 那种地址，这里统一纠回来，不用改库）
     *  - OSS 的图：地址本身就是绝对的（跟域名无关），原样返回
     */
    public function out(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $marker = '/storage/';
        $pos = strpos($url, $marker);
        if ($pos === false) {
            return $url;
        }

        return $this->baseUrl() . $marker . ltrim(substr($url, $pos + strlen($marker)), '/');
    }

    /**
     * 当前请求的 scheme://host（没有请求上下文时退回 APP_URL，比如 artisan / 队列里）
     * 走反代时以 X-Forwarded-Proto 为准，避免 https 站点被拼成 http
     */
    private function baseUrl(): string
    {
        $r = request();
        if (!$r) {
            return rtrim((string) config('app.url'), '/');
        }

        $scheme = $r->header('X-Forwarded-Proto') ?: $r->getScheme();

        return $scheme . '://' . $r->getHttpHost();
    }

    /**
     * 按 URL 删掉一张图（换头像/换照片时清旧的，别堆垃圾）
     * 解析不出来就静默跳过（老数据可能是别的地址，删不掉不影响业务）
     */
    public function deleteByUrl(?string $url): void
    {
        $key = $this->keyFromUrl($url);
        if ($key === null) {
            return;
        }

        try {
            if ($key['driver'] === 'local') {
                Storage::disk('public')->delete($key['key']);
            } else {
                $this->client()->deleteObject(config('services.oss.bucket'), $key['key']);
            }
        } catch (\Throwable $e) {
            Log::warning('[storage] 删除旧图失败', ['url' => $url, 'err' => $e->getMessage()]);
        }
    }

    /**
     * URL → 存储驱动（'oss' | 'local'；空地址返回空串）
     *
     * 出参给小程序挂牌「照片存哪了」用：配了 OSS 的图是 oss，没配时落服务器本地盘的是 local。
     * 判定复用 keyFromUrl（认 /storage/ 前缀和 OSS 域名），不用再写一套。
     */
    public function driverOf(?string $url): string
    {
        return $this->keyFromUrl($url)['driver'] ?? '';
    }

    /**
     * 对象键 → 可访问 URL（配了自定义域名/CDN 就用它）
     *
     * 两种写法都容忍：
     *  - OSS_ENDPOINT 只写域名（oss-cn-beijing.aliyuncs.com），也被容忍写成带 bucket 前缀的
     *    「gq-clothes.oss-cn-beijing.aliyuncs.com」（阿里云控制台上那个外网访问地址就是这个样子）
     *  - OSS_DOMAIN 不带 http(s):// 也能用（自动按 OSS_SSL 补 https）
     */
    public function url(string $key): string
    {
        $key = ltrim($key, '/');
        $scheme = filter_var(config('services.oss.ssl', true), FILTER_VALIDATE_BOOLEAN) ? 'https' : 'http';

        $domain = trim((string) config('services.oss.domain'));
        if ($domain !== '') {
            $domain = rtrim($domain, '/');
            if (!preg_match('#^https?://#i', $domain)) {
                $domain = $scheme . '://' . $domain;
            }

            return $domain . '/' . $key;
        }

        return $scheme . '://' . config('services.oss.bucket') . '.' . $this->endpoint() . '/' . $key;
    }

    /**
     * 规范化后的 endpoint（不带协议、不带 bucket 前缀、不带路径）
     * 阿里云控制台给的「外网访问地址」形如 {bucket}.oss-cn-beijing.aliyuncs.com，
     * 直接塞进 OSS_ENDPOINT 也能跑（SDK 要的是纯域名 oss-cn-beijing.aliyuncs.com）
     */
    private function endpoint(): string
    {
        $ep = preg_replace('#^https?://#', '', trim((string) config('services.oss.endpoint')));
        $ep = strtok($ep, '/');                                   // 去掉可能带的路径
        $bucket = trim((string) config('services.oss.bucket'));
        if ($bucket !== '' && strpos($ep, $bucket . '.') === 0) {  // 去掉 bucket 前缀
            $ep = substr($ep, strlen($bucket) + 1);
        }

        return $ep;
    }

    /**
     * URL → ['driver' => 'oss'|'local', 'key' => 对象键]
     * 能认这三种：本地 /storage/... 、自定义域名 https://img.xxx/clothes/... 、OSS 默认域名
     */
    private function keyFromUrl(?string $url): ?array
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        // 本地（APP_URL/storage/xxx）
        $marker = '/storage/';
        $pos = strpos($url, $marker);
        if ($pos !== false) {
            return ['driver' => 'local', 'key' => substr($url, $pos + strlen($marker))];
        }

        // 自定义域名
        $domain = trim((string) config('services.oss.domain'));
        if ($domain !== '' && str_starts_with($url, rtrim($domain, '/'))) {
            return ['driver' => 'oss', 'key' => ltrim(substr($url, strlen(rtrim($domain, '/'))), '/')];
        }

        // OSS 默认域名 https://{bucket}.{endpoint}/{key}
        if (preg_match('#^https?://[^/]+/(.+)$#', $url, $m)) {
            return ['driver' => 'oss', 'key' => $m[1]];
        }

        return null;
    }

    private function client(): OssClient
    {
        $c = config('services.oss');

        // endpoint 走规范化（SDK 要的是纯域名，不能带 bucket 前缀/协议/路径）
        return new OssClient($c['access_key_id'], $c['access_key_secret'], $this->endpoint());
    }

    /** 扩展名：优先按文件真实类型猜（用户改过名的假后缀也能纠回来） */
    private function ext(UploadedFile $file): string
    {
        $ext = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic'], true) ? $ext : 'jpg';
    }
}
