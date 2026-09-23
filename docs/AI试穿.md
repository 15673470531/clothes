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

## 八、还没做（P3 之后）

- 小程序侧：入口（衣物详情/衣橱长按）、生成中动效、结果页、设为搭配封面、错误兜底
- 会员门：`users` 加 `is_member` / `member_until` + Filament 后台手动开通
- 多虚拟模特、试穿历史、批量生成
- 2D 人台（`pages/dressup`）作为"免费快速拼搭层"保留：AI 挂了也能兜底
