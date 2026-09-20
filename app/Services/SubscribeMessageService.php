<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 微信订阅消息（一次性订阅）
 *
 * 通用流程：
 *  1. 前端在关键动作时 wx.requestSubscribeMessage 请求授权
 *  2. 授权结果上报后端，记录可用额度（一张额度只能发一条）
 *  3. 定时任务或业务事件消费额度，调本服务发送
 *
 * 具体业务的额度落库、防重、扫描逻辑属于各项目自己的业务代码，
 * 本服务只负责「拿 access_token + 发一条订阅消息」这两件事。
 */
class SubscribeMessageService
{
    /**
     * 发送一条订阅消息
     *
     * @param  string  $openid      接收人 openid
     * @param  string  $templateId  模板 ID（业务侧配置在 .env）
     * @param  array   $data        模板字段，如 ['thing1' => ['value' => '午饭'], 'time3' => ['value' => '2026-01-01 12:00']]
     * @param  string  $page        点击消息跳转的小程序页面，如 'pages/index/index'
     * @return bool                 是否发送成功
     */
    public static function send(string $openid, string $templateId, array $data, string $page = ''): bool
    {
        if (empty($openid) || empty($templateId)) {
            return false;
        }

        $token = WechatService::accessToken();
        if (!$token) {
            return false;
        }

        $payload = [
            'touser'      => $openid,
            'template_id' => $templateId,
            'data'        => $data,
            // 测试阶段可配 trial（体验版）/ developer（开发版），上线后保持 formal
            'miniprogram_state' => env('WECHAT_MSG_STATE', 'formal'),
        ];
        if ($page) {
            $payload['page'] = $page;
        }

        $res = Http::post("https://api.weixin.qq.com/cgi-bin/message/subscribe/send?access_token={$token}", $payload)->json();

        return empty($res['errcode']);
    }
}
