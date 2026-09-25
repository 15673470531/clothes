# AI 试穿（P2 后端 · 2026-09）

把用户衣橱里的一件衣服"穿到虚拟模特身上"，出一张真人效果的图。

> ## ⏸ 当前状态：已隐藏（2026-09-22，用户要求先收起，后面再优化）
>
> 后端总开关关着（`.env` 里 `TRYON_ENABLED=false`），所以：
>   - 小程序里**看不到任何入口**（搭配预览页底部的「试穿」按钮由这个开关驱动，前端不需要改代码）
>   - 接口 `/api/tryon/quota` 返回 `enabled:false`；`POST /api/tryon` 返回 **4007 试穿功能还没开放**（不建表也能跑，休眠状态零风险）
>
> **代码、数据、素材全部保留**（试穿页、后端服务、两张表、OSS 上的模特图/白底图/结果图），以后接着优化。
>
> 想恢复：`.env` 改 `TRYON_ENABLED=true` → `php artisan config:clear` → 确认 worker 在跑
> （`docker compose up -d worker`）。三步，小程序不用重新发版。

## 一、链路

```
小程序提交 {itemId}
   ↓
后端建任务（tryon_tasks, pending）+ 进队列            ← 接口立刻返回 task_id，不阻塞
   ↓
队列 worker：
   ① 归一化  qwen-image-edit    实拍图 → 白底商品图（原图直出会翻车，必须洗）
   ② 试穿    aitryon            虚拟模特 + 白底图 → 结果图
   ③ 转存    我们的 OSS         阿里云的临时地址会过期，结果和白底图都存自己 OSS
   ↓
小程序轮询 GET /api/tryon/{id} → done + resultUrl
```

实测耗时：归一化 17 秒、试穿 5~10 秒，**一次完整生成 26~27 秒**（含两件衣服时更长）。

## 二、省钱的三层缓存（这是这个功能能不能跑得起的关键）

| 缓存 | 键 | 命中效果 |
|---|---|---|
| 结果缓存 | `user_id + item_id + model_key + source_hash` | 秒回上次的图，**供应商一次都不调**（实测 5 ms） |
| 白底图缓存 | `user_id + item_id + source_hash`（表 `tryon_garments`） | 跳过最贵的归一化那一步；单件/整套共用，一件衣服只洗一次 |
| 重复投递保护 | 任务已 `done` | 队列重试/用户连点直接返回，不重复花钱 |

`source_hash` = 提交时照片地址的 md5。**用户换了照片 → 缓存自动失效**，不用人工清。
虚拟模特固定，所以"同一件衣服"的结果对所有人生效（别人试过你就不用再花一次钱）。

## 三、接口

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/tryon/quota` | 入口状态：开关 / 是否会员 / 今天还剩几次 / 模特标识 |
| POST | `/api/tryon` | 单件 `{itemId}`；**整套搭配 `{outfitId}`**；命中缓存时 `data.cached=true` 并直接带结果 |
| GET | `/api/tryon/{id}` | 轮询任务（只能查自己的） |

整套（P3）：传 `outfitId` 时后端自己从这条搭配里**按顺序取第一件上装 + 第一件下装**（没照片的跳过、
鞋和配饰不参与），**一次调用把两件都传进去**。只传一件的话接口会给模特自己配另一件（实测：单传上衣配上黑牛仔裤），
那就不是用户的搭配了。整套的缓存键 = `o<搭配id>` + 两件衣物图地址的 md5 → 同一套 + 同一模特，第二次几毫秒出图。

业务码（`{code, msg}` 那套）：

| 码 | 含义 |
|---|---|
| 4003 | 非会员（只有 `TRYON_MEMBER_ONLY=true` 时会出现） |
| 4004 | 这件衣物没照片 / 找不到这件衣物 |
| 4005 | 这个品类不支持试穿（**鞋、配饰没有槽位**，传进去会把鞋当上衣） |
| 4006 | 今天的次数用完了 |
| 4007 | 功能没开放（开关没开 / key 或模特图没配） |
| 4008 | 生成失败（供应商侧，前端显示友好文案，技术原因在 `tryon_tasks.error`） |

## 四、配置（.env）

```ini
DASHSCOPE_API_KEY=sk-xxx                  # 阿里云百炼 key
TRYON_ENABLED=true                        # 总开关（先发代码不生效，配好再开）
TRYON_MODEL_IMAGE=https://...png          # 虚拟模特图（我们 OSS 上的公网 https）
TRYON_MEMBER_ONLY=false                   # 只给会员（默认关；打开前要给 users 加 is_member 列）
TRYON_DAILY_LIMIT=10                      # 每人每天最多生成几张（缓存命中不计数）
```

## 五、部署清单（重要）

按顺序做，**后端先发、小程序后发**（老版本小程序不调新接口，零影响）：

1. `php artisan migrate --force` —— 建 `tryon_tasks`（纯新增表，不动老表）
2. `.env` 补上面四项 + `php artisan config:clear`
3. 一次性：`php artisan tryon:model <本地模特图路径>` —— 把模特图传到我们 OSS，把打印出来的地址写进 `TRYON_MODEL_IMAGE`
4. **队列 worker 必须跑**（项目里第一个用队列的功能）：
   - Docker：`docker compose up -d worker`（已在 `docker-compose.yml` 里加了 `worker` 服务）
   - 非 Docker：`php artisan queue:work --tries=3 --timeout=300 --sleep=2`（用 supervisor 常驻）
   - ⚠️ worker 不在跑 → 任务永远停在 `pending`，页面一直转圈
   - 改了 PHP 代码要重启 worker（`docker compose restart worker`；改了环境变量要用 `up -d --force-recreate worker`）
5. 验收：提一件衣服，看 `tryon_tasks` 从 pending → running → done，`result_url` 能打开

## 六、实现要点与坑（实测记录）

- **接口只吃公网 https 直链**：阿里云临时空间的 `oss://` 地址会被数据检查拒掉
  （报 `InvalidParameter.DataInspection / The media format is not supported`，很有误导性）
- **aitryon 提交必须带 `X-DashScope-Async: enable`**，否则报"不支持同步调用"
- **连续提交会 429 限流**（`Throttling.RateQuota`）→ Provider 里做了指数退避 + 队列再重试 3 次
- **试衣只有上装 / 下装两个槽位**：鞋、配饰传进去会被当成上衣罩在身上（实测翻车），所以
  `config/tryon.php` 的 `slots` 表里故意不给它们留位置，接口直接 4005 挡掉
- **一次要传整套**：只传上装的话，接口会自己配一条它认为搭调的裤子。要做"整套效果"就上装+下装一起提交
- 换供应商 / 以后接自建 GPU：只改 `AppServiceProvider` 里 `TryonProvider` 那一行绑定
- **改了 PHP 代码必须重启 worker**：`queue:work` 是常驻进程，类加载在内存里，不重启就一直跑旧代码。
  这次真踩到了：加了「整套传两件」之后没重启，结果图里只有上装（下身是接口自己配的黑牛仔）。
  重启命令 `docker compose up -d --force-recreate worker`（改 env 只能 recreate，`restart` 不重读 env）

## 七、小程序侧（P3 · 2026-09）

入口：搭配预览页（`pages/outfit-view`）底部第三个按钮「试穿」——只有后端开关开着、已登录、
今天还有次数才显示（免得点进去是死路）；草稿搭配不给试（还没上云，后端不知道它）。

新页 `pages/tryon/tryon`：整屏「正在生成上身效果」遮罩（不可关）→ 每 3 秒轮询 → 出图；
失败给人话（用后端的 msg）+ 「再试一次」。结果页两个按钮：
- **保存到相册**：结果图在后端 OSS，先 `wx.downloadFile` 到本机再存
- **设为封面**：下载到本机 → 走**既有封面链路**（`cloud.saveOutfit`：传 OSS + 推云端），
  封面地址的归属跟平时「完成」出的封面一样，后端换封面时的清理逻辑不用为它开特例

⚠️ **要把 OSS 域名加进「downloadFile 合法域名」**（小程序后台 → 开发设置 → 服务器域名）：
`gq-clothes.oss-cn-beijing.aliyuncs.com`。`<image>` 显示不受限，但 `wx.downloadFile`
（保存到相册 / 设为封面都要它）会校验域名；开发者工具里勾「不校验合法域名」可先跑通。

## 八、复跑与换模型（`tryon:normalize`）

归一化的效果 = 模型 + 提示词，这两个是要反复试的。命令 `tryon:normalize` 就是"拿真图、真调用一次、结果并排看"：

```bash
# 先看不花钱的：把要调的模型/提示词/图片打出来，一个请求都不发
docker compose exec app php artisan tryon:normalize storage/app/jeans.jpg --dry-run

# 真跑（默认读 config：qwen-image-edit + normalize_prompt）；会先传到我们 OSS 换公网 https
docker compose exec app php artisan tryon:normalize storage/app/jeans.jpg

# 多模型横向对比 + 从文件读提示词 + 洗完接着试穿
docker compose exec app php artisan tryon:normalize storage/app/jeans.jpg \
    --model=qwen-image-edit --model=qwen-image-edit-plus \
    --prompt-file=storage/prompt.txt --with-tryon --slot=bottom

# 直接给公网图（别人已经洗好/线上的图）
docker compose exec app php artisan tryon:normalize https://gq-clothes.oss-cn-beijing.aliyuncs.com/xxx.jpg
```

参数一览：

| 参数 | 说明 |
|---|---|
| `--model=` | 图像编辑模型，**可给多个**做对比（默认 `config('tryon.edit_model')`） |
| `--prompt=` / `--prompt-file=` | 提示词；优先级 文件 > 命令行 > 配置（中文长句建议用文件，省得转义） |
| `--out=` | 输出目录（默认 `storage/app/tryon-test/年月日_时分秒`） |
| `--with-tryon` | 洗完之后接着跑一次试衣；`--tryon-model=` 换试衣模型、`--slot=top\|bottom` 选槽位 |
| `--url-only` | 只打印结果 URL，不下载 |
| `--dry-run` | 只打印将要发生的事，不发任何请求（不花钱） |

跑完输出目录里有：原图、每个模型的结果图、`result.json`（耗时/URL/失败原因/花费）、
`index.html`（**自包含对比页**，双击打开，项目根目录下 `open storage/app/tryon-test/<目录>/index.html`）。

⚠️ 不带 `--dry-run` 就是真调用：成功一张计一张（结尾按官方原价估算，免费额度内不花钱）。
只想做不花钱的回归验证：`php artisan test --filter=TryonNormalizeCommandTest`（9 条用例，全走 Http 桩）。

### 归一化模型选型（2026-09-24 实测，用 `tryon:normalize` 跑的）

同一批实拍图（灰蓝牛仔裤 + 白长袖T）、同一条生产提示词，只换模型：

| 模型 | 单价（元/张） | 耗时 | 结果 |
|---|---|---|---|
| `qwen-image-edit`（现用） | 0.30 | 19.5s | ✅ 干净可用 |
| **`qwen-image-edit-plus`** | **0.20** | **9.4s** | ✅ **最干净 + 最快 + 最便宜**（白T 那件 8.7s 也验证过） |
| `qwen-image-edit-max` | 0.50 | 23.3s | ✅ 可用，只是贵 |
| `wan2.7-image-pro` | 0.50 | 15s | ✅ **商品图质感最好**（细节/纹理最像真拍摄；万相旗舰，同族定位「图像生成与编辑」，支持主体一致性/交互式编辑） |
| `wan2.7-image` | 0.20 | 11.2s | ✅ 可用，细节比 pro 少一点，价格跟 `-plus` 一样 |
| `qwen-image-3.0` | 0.20（含 0.02 输入） | 54.7s | ❌ **不能做保真归一化** |

`qwen-image-3.0` 为什么不行（两次都试过）：它是**生成型**模型（官方定位是海报 / 网页 / 界面 / 文字渲染，支持 4.5k 指令 + 小字渲染）——
1. 用生产提示词：把这条裤子**重绘成另一条**（颜色变深蓝、腰头换掉、水洗纹重来），跟用户那件不是一个东西；
2. 换成"只换背景、主体像素级不变"的提示词：保留了原图摊在地砖上的角度，几何还崩了一块 —— 比原图更没法看；
3. 另外两点：慢（52~55 秒，输出 2K 横图）、偶发 `InternalError.Algo`（算法侧瞬时错误，重跑一次就好，我们队列本来就有重试）。

**万相系也能用，而且不用改代码**：`wan2.7-image-pro` / `wan2.7-image` 走的是**同一条接口**
（`/services/aigc/multimodal-generation/generation`，就是我们 `normalize()` 用的那条），
所以 `php artisan tryon:normalize 图 --model=wan2.7-image-pro` 直接就能跑、也能直接写进 `TRYON_EDIT_MODEL`。
⚠️ 它输出的是 6.7MB 级别的大 PNG，存 OSS 前建议压一道（我们入库流程本来就压）。

**结论**：性价比换 **`qwen-image-edit-plus`**；想要最好的商品图质感就用 **`wan2.7-image-pro`**（0.5 元，2.5 倍价钱） —— 改 `.env` 的 `TRYON_EDIT_MODEL=qwen-image-edit-plus` + `php artisan config:clear`，
**代码 / 队列 / 小程序都不用动**（这条链路的意义就在这儿）。

关于"`qwen-image-edit` 10 月要下线"：阿里云帮助文档的**模型价格表会给已下线模型明确标注**
（例如 `deepseek-r1-distill-llama-8b | 已下线 | 推荐使用…作为替代模型`），而 `qwen-image-edit` 那一行**没有任何下线标注**；
同族仍在架的还有 `-plus`(0.2) / `-max`(0.5) / `qwen-image-2.0`(0.2) / `qwen-image-2.0-pro`(0.5)。
所以就算真到那天，切换成本 = 一行配置（本节的表格就是备胎清单）。

对比页（桌面）：`/Users/guoqing/Desktop/clothes-插图预览/归一化模型选型.html`

**加新模型时**：命令不用改 —— 模型名直接 `--model=新模型名` 传；如果想让它成为默认，改
`.env` 的 `TRYON_EDIT_MODEL`（或 `config/tryon.php` 的 `edit_model`）。**换供应商**（不只换模型）也只改
`AppServiceProvider` 里 `TryonProvider` 那一行绑定，命令和业务代码都不用动。

价格参考（官方原价 · 华北2北京 · 2026-09 查，元/张）：
`qwen-image-edit` 0.30（免费 100 张）、`qwen-image-edit-plus` 0.20、`qwen-image-edit-max` 0.50、
`aitryon` 0.20（免费 400 张）、`aitryon-plus` 0.50。免费额度有效期 90 天（自开通百炼/模型发布较晚者起算），
**失败不计费也不扣额度**。

## 九、洗白底（记录衣物页 · 2026-09 · **已改成自动跑**）

试穿链路里的「归一化」被单独拎出来给用户用了：记录衣物页那一块「自动洗白底」。
用户 2026-09-24 拍板的口径是**默认打开、保存衣物后自动跑、跑完自动把封面换成白底图**，
所以它现在是「自动为主、手动兜底」：

| 项 | 口径 |
|---|---|
| 自动还是手动 | **保存衣物后自动入队**（新增衣物 / 换了照片才洗；改名字、换分类不洗） |
| 开关 | `NORMALIZE_ENABLED`（默认 **true**，前端入口 + 自动流程一起受它管）；`NORMALIZE_AUTO`（默认 true，只关"自动跑"，手动还能用） |
| 模型 | `wan2.7-image`（`TRYON_NORMALIZE_MODEL` 可改；`TRYON_EDIT_MODEL` 只是兜底老名字） |
| 每人每天 | **10 张**（`NORMALIZE_DAILY_LIMIT`，改配置即生效，不用发版） |
| 队列 | 单独一条 `normalize`（`NORMALIZE_QUEUE`）→ worker 必须监听：`queue:work --queue=normalize,default` |
| 计次规则 | **只有真烧了一次模型调用才计**：命中缓存不计、失败不计 |
| 缓存 | 同一件衣物 + 同一张原图只洗一次（复用 `tryon_garments` 表，试穿链路共用） |
| 接口 | `GET /api/items/normalize/status`（多回一个 `auto`）、`POST /api/items/normalize`（手动，**同步** 15~20 秒，带 `force` 可跳过缓存重洗） |
| 业务码 | 4004 没照片 / 找不到 · 4008 生成失败 · 4009 功能没开 · 4010 今天次数用完 |
| 存哪 | `clothes_items.original_image_url`（原图）/ `normalized_url`（白底图）/ `normalized_source`（原图 md5，换照片自动作废）/ `normalize_status`（queued·running·done·failed·skipped）/ `normalize_auto`（这件要不要自动洗）/ `cover_choice`（`orig` = 用户明确要用原图，自动洗完**不覆盖**封面） |

自动流程（一句话）：`push` 保存 → 事务提交后按"照片变了没"入队 → `RunNormalize` job 调
`NormalizeService::runQueued()` → 洗完写白底图 + 状态 done + **自动把 `image_url` 换成白底图**
（`cover_choice='orig'` 时只存不换）→ 列表顶部那行「N 件白底图正在生成」跟着归零。

几个必须守住的点（都有测试钉着，见 `tests/Feature/NormalizeTest.php`，22 条）：

1. **别重复花钱**：洗过一次再点/再投递，直接给缓存（不调供应商、不计次）；试穿链路发现这件衣物已经有
   白底图也直接复用（`TryonService::normalized` 里那段判断）；`runQueued()` 开头还有一道幂等。
2. **原图不能删**：用户把白底图设成封面后 `image_url` 会变成白底图，旧的 `image_url`（原图）还活在
   `original_image_url` 里 —— `ClothesController::upsertItem` 只有在"这张图没人引用"时才删 OSS 对象。
3. **老版本小程序不能把白底图推没**：`push` 里按"请求里有没有 `originalImageUrl`/`normalizedUrl` 这个键"
   决定改不改，不看值是不是空串（旧客户端压根不发这两个字段）。
4. **白底图要压**：wan2.7 出的是 6~7MB 的 2K PNG，服务端用 GD 压到长边 1600、转 JPEG（压不动就原样存，
   绝不让压图把功能弄挂）。
5. **今天 10 张用完了不硬洗**：这件标 `skipped`，**第二天打开衣橱时惰性补洗**（`pull` 里调 `topUp()`，
   节流 5 分钟）——跟「每月赠送」一个套路，不用定时任务也不会漏。`failed` 不自动重试（可能是个洗不动的图）。
5.5 **老衣物不主动回补洗**（用户 2026-09 拍板，方案 A）：`NORMALIZE_AUTO_BACKFILL` **默认 false** ——
   惰性补洗只捡 `skipped`（当天没排上的）和卡了 30 分钟以上的 `queued`（worker 没跑/任务丢了，重排一次），
   **不捡状态为空的老衣物**。否则一发版，所有老用户"没点任何东西"也会按每天上限被洗（真花钱）。
   老衣物想洗有两个明确动作：① 在这件上把「自动洗白底」从关改成开（`push` 会入队）② 手动「重新生成一张」。
6. **只比"照片本身"**：`upsertItem` 用 `original_image_url ?: image_url` 的前后指纹判断要不要洗 ——
   只比 `image_url` 会把「把封面切成白底图」也当成换图，白洗一次（0.2 元）。

前端（`pages/item-edit`）那块现在是三段：**勾选行**（这件要不要自动洗，默认开）+ **说明行**
（用户点名要的：会自动跑、每天几张、原图留着；数字由后端下发）+ **状态行**
（排队中 / 生成中 / 已生成 · 点开看对比换封面 / 失败 · 点它重试）。衣橱列表**不放格子角标**，
改成顶部一行总提示「N 件白底图正在生成，好了会自动换上」（用户 2026-09 选的方案 6：角标只存在十几秒，
一闪而过看不见，而且跟「照片存哪了」的圆点挤在同一角容易混淆）；有活干的时候页面每 7 秒补拉一次数据
（最多 12 轮，洗完自己停），用户不用手动刷新就能看到换上白底图。

上线顺序：**后端先发**（迁移 `2026_09_24_000003` 加 3 列 → 接口 → worker 换成
`--queue=normalize,default` 并 **`--force-recreate` 重启**）→ 小程序后发。
线上 `.env`：`NORMALIZE_ENABLED=true`、`NORMALIZE_AUTO=true`、`NORMALIZE_DAILY_LIMIT=10`。
一键全停：`NORMALIZE_ENABLED=false` + `config:clear`（入口和自动流程一起停）。

⚠️ **跑测试的坑（血泪）**：本地 `docker compose exec app php artisan test` 时，compose 注入的
`DB_CONNECTION=mysql` 优先级高于 phpunit.xml，测试会连**开发库**并 `migrate:fresh` 把它清空
（2026-09 真发生了）。现在 `tests/TestCase.php` 在启动前强制改成内存 sqlite，从根上隔离；
但换新项目/新容器时记得检查这一条。

## 十、还没做（P3 之后）

- 小程序侧：入口（衣物详情/衣橱长按）、生成中动效、结果页、设为搭配封面、错误兜底
- 会员门：`users` 加 `is_member` / `member_until` + Filament 后台手动开通
- 多虚拟模特、试穿历史、批量生成
- 2D 人台（`pages/dressup`）作为"免费快速拼搭层"保留：AI 挂了也能兜底
