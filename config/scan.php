<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 穿搭照识别（2026-10）
    |--------------------------------------------------------------------------
    |
    | 链路：拍一张全身穿搭照 →（传给我们 OSS 拿公网 https 地址）→ 通义千问 VL 认衣服
    |       → 按模型给的框把每件裁出来当它的照片 → 用户逐件确认 → 走现有批量入库
    |
    | 为什么不塞进 config/tryon.php：识别（读图理解）和试穿 / 洗白底（图像生成）是两回事，
    | 开关、限额、模型各管各的，混在一起以后改一个动另一个。API Key 是同一把 DASHSCOPE_API_KEY。
    |
    | 两条铁律（跟洗白底一样，踩过别改）：
    |  1 **图必须是公网 https 直链**：百炼只吃这种，本地盘/临时地址会被数据检查拒掉
    |  2 识别**不落库、不扣衣架**：只有用户在确认页点「加入衣橱」才走 /clothes/sync 落库
    |
    */

    // 总开关：先发代码不生效，配好 key 再打开（避免线上误触发花钱的调用）
    'enabled' => env('SCAN_ENABLED', false),

    // 阿里云百炼（通义千问 VL）的 API Key —— 跟试穿/洗白底共用
    'api_key' => env('DASHSCOPE_API_KEY', ''),

    // 识别模型：默认 qwen-vl-plus（便宜快、认品类够用）
    // 觉得不准就改 .env 的 SCAN_MODEL=qwen-vl-max（贵一些、稳一些）—— 不用发版
    'model' => env('SCAN_MODEL', 'qwen-vl-plus'),

    // 每人每天识别几次（失败不计次，见 ScanService：调用前先扣，失败回滚）
    'daily_limit' => (int) env('SCAN_DAILY_LIMIT', 5),

    // 一次最多认几件（防一张图认出一堆、一次占掉一堆衣架）
    'max_items' => (int) env('SCAN_MAX_ITEMS', 8),

    // 要不要按模型给的框把每件衣物裁出来当它的照片
    // 关掉 = 认出来的衣物先没有照片（列表用「品类图标 + 颜色块」显示，想拍再补）
    'crop' => env('SCAN_CROP', true),

    // 裁剪时四周留的余量（相对框尺寸的比例）：模型的框常贴着衣服边，留一点更像商品图
    'crop_padding' => (float) env('SCAN_CROP_PADDING', 0.06),

    // 裁剪图最长边上限（px）：够看花色就行，省流量省对象存储
    'crop_max_side' => (int) env('SCAN_CROP_MAX_SIDE', 900),

    // 单次调用超时（识别是同步的，用户在前端看着转圈）
    'timeout_seconds' => (int) env('SCAN_TIMEOUT', 90),

    /*
    | 提示词：模型必须**只吐 JSON**，栏位和取值都收紧到小程序那套常量表里
    | （category/sub/colors 的 key 见 ScanService 的常量；写自由文本会被服务端扔掉）
    */
    'prompt' => <<<'TXT'
你是服装识别助手。用户给你一张穿着衣服的人物照片，请识别**人物身上穿着的**每一件衣物。

只输出一个 JSON 对象，不要任何解释文字，不要 markdown 代码块。格式：
{"items":[{"name":"白T","category":"top","sub":"tshirt","colors":["white"],"box":[180,400,430,760]}]}

字段规则：
- name：简短中文名，不超过 12 个字，别堆颜色和材质形容词（好的例子：白T、牛仔裤、黑色短靴；坏的例子：白色纯棉圆领短袖T恤）
- category 只能取：top(上装) bottom(下装) outer(外套) dress(裙装) set(套装) shoes(鞋) sockhat(袜帽) acc(配饰)
- sub 必须与 category 匹配，只能取：
  top: tshirt shirt knit hoodie
  bottom: jeans slacks casual shorts
  outer: jacket coat down trench
  dress: onepiece skirt overalls
  set: suit sport two jumpsuit
  shoes: sneaker leather boots sandal
  sockhat: socks hat scarf gloves
  acc: bag jewel belt glasses
- colors 只能取（可多选，最多 3 个）：black white gray beige brown navy blue green red pink yellow purple
- box：这件衣物在图中的位置 [左上x, 左上y, 右下x, 右下y]，用**绝对像素坐标**：
  这张图宽 {w} 像素、高 {h} 像素，所以 x 取 0~{w} 之间的整数、y 取 0~{h} 之间的整数
  （别用 0-1000 那种归一化数字，别用百分比；框尽量贴紧这件衣物本身）

其他要求：
- 只认人物身上的衣物；背景、家具、路人、手里拿的东西都不算
- 同一件衣物只输出**一条**：一双鞋、一副手套这种成对的，合成一条别拆成左右两只
- 拿不准的、被挡住大半的、看不清是什么的，直接不要输出
- 最多输出 8 件
TXT,
];
