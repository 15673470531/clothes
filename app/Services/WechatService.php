<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 微信基础能力：access_token 获取
 * 与 UserController 手机号接口共用同一缓存 key，不重复请求
 */
class WechatService
{
    /**
     * 小程序 appid，在 .env 配置 WECHAT_APPID
     * 不要写死在代码里——新项目复制本骨架时极易漏改，导致串用别的项目的微信账号
     */
    public static function appid(): string
    {
        return (string) env('WECHAT_APPID', '');
    }

    /**
     * 获取微信 access_token（缓存 110 分钟，避免超 2 小时有效期）
     */
    public static function accessToken(): ?string
    {
        return Cache::remember('wechat_access_token', 110, function () {
            $appid = self::appid();
            $secret = env('WECHAT_APPSECRET');
            if (!$appid || !$secret) {
                return null;
            }

            $res = Http::get('https://api.weixin.qq.com/cgi-bin/token', [
                'appid'      => $appid,
                'secret'     => $secret,
                'grant_type' => 'client_credential',
            ])->json();

            return $res['access_token'] ?? null;
        });
    }
}
