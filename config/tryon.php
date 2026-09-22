<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI 试穿（2026-09 · P2）
    |--------------------------------------------------------------------------
    |
    | 链路：用户衣物照片 →（归一化：洗成白底商品图）→（试穿：虚拟模特穿上）→ 结果图存我们 OSS
    |
    | 两个关键约束（都是实测撞出来的，别改）：
    |  1 接口只吃"公网 https 直链"：阿里云临时空间那种 oss:// 地址会被数据检查拒掉，
    |    所以归一化后的图我们也存进自己 OSS，拿到长期有效的 https 地址再喂下一环。
    |  2 试衣只有"上装 / 下装"两个槽位：鞋、配饰没有槽位，传进去会把鞋当上衣罩在身上
    |    （实测翻车），所以 slot 映射表里**不要**给鞋和配饰留位置。
    |
    */

    // 总开关：先发代码不生效，配好 key + 模特图再打开（避免线上误触发花钱的调用）
    'enabled' => env('TRYON_ENABLED', false),

    // 阿里云百炼（通义万相）的 API Key
    'api_key' => env('DASHSCOPE_API_KEY', ''),

    // 虚拟模特图：必须是**我们自己的公网 https 地址**（用 php artisan tryon:model 上传）
    'model_image' => env('TRYON_MODEL_IMAGE', ''),

    // 模特标识：以后加多个模特就用它区分（缓存键里也带着它）
    'model_key' => env('TRYON_MODEL_KEY', 'model-a'),

    // 只给会员用（先默认关；打开前需要给 users 加 is_member 列，见文档）
    'member_only' => env('TRYON_MEMBER_ONLY', false),

    // 每人每天最多生成几张（缓存命中不计数）
    'daily_limit' => (int) env('TRYON_DAILY_LIMIT', 10),

    // 模型名（阿里云百炼）
    'tryon_model' => env('TRYON_MODEL', 'aitryon'),
    'edit_model' => env('TRYON_EDIT_MODEL', 'qwen-image-edit'),

    // 异步任务的轮询节奏
    'poll_interval_ms' => 2000,
    'timeout_seconds' => 180,

    // 衣物品类 → 试穿槽位（鞋/配饰故意不给槽位 = 不支持）
    'slots' => [
        'top'    => 'top',
        'outer'  => 'top',      // 外套当上装传（实测可行）
        'bottom' => 'bottom',
        'dress'  => 'bottom',   // 连衣裙走"下装"槽位（实测它自己会整身穿上）
    ],

    // 归一化的指令（qwen-image-edit）：抠出来 + 白底 + 保持原样
    'normalize_prompt' => '把这张照片里的这件衣服抠出来，正面平铺放在纯白色背景上；'
        . '保持衣服的颜色、版型、材质、图案和所有细节完全不变；去掉背景里的地板、杂物、阴影。'
        . '只输出这一件衣服的商品图。',

];
