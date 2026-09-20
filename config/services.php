<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 阿里云 OSS（图片存储，2026-09 二期第一步）
    |--------------------------------------------------------------------------
    | 方案 A：小程序 → 本后端 → OSS（服务端中转）
    | **不填 OSS_ACCESS_KEY_ID 时自动落本地 public 盘**（storage/app/public），
    | 本地开发不用先申请密钥；线上填上这几个 env 就自动切 OSS，代码不用改。
    |
    | endpoint 写域名、不带协议：oss-cn-hangzhou.aliyuncs.com
    | domain 可选：绑定的自定义域名 / CDN（https://img.example.com），填了就用它拼图片地址
    */
    'oss' => [
        'access_key_id'     => env('OSS_ACCESS_KEY_ID'),
        'access_key_secret' => env('OSS_ACCESS_KEY_SECRET'),
        'endpoint'          => env('OSS_ENDPOINT'),
        'bucket'            => env('OSS_BUCKET'),
        'domain'            => env('OSS_DOMAIN'),
        'ssl'               => env('OSS_SSL', true),
    ],

];
