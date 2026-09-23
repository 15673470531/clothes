# AI 试穿 · 上线清单（2026-09）

> 打勾用。顺序：**先备份 → 后端 → 小程序 → 验证**。每步都写清「为什么」，出问题能倒回来。

## ⏸ 前置说明：现在这个功能是"隐藏"状态

2026-09-22 用户决定先收起这个功能（后面再优化），所以生产 `.env` 里 `TRYON_ENABLED=false`，
小程序不发入口。**这份清单先在需要真正上线时再用**；到时候第 1 节第 3 步的 `.env` 才改成 `true`。

现在这个状态即使把后端代码发上去，也是休眠的：接口返回 4007，不占资源、不花钱，
连迁移都可以晚点再跑（关着的时候不会碰那两张表）。

## 0. 发之前先确认（10 分钟，一次性的）

- [ ] 阿里云百炼的 key 可用（账号已开通 `aitryon` 试衣权限）
- [ ] 手头有一张**虚拟模特图**（全身、正面站立、背景干净、基础款打底；一张就够，永久复用）
- [ ] 后端服务器上 OSS 配置是好的（`OSS_*` 那几个值）—— 试穿链路里所有图都存我们自己 OSS，
      而且**接口只吃公网 https 直链**（阿里云临时空间那种 `oss://` 地址会被它拒掉）
- [ ] 确认生产是 base `docker-compose.yml`（`docker-compose.override.yml` 是本地用的，已在 .gitignore 里，生产不会带上）

## 1. 后端（先发，老版本小程序不受影响）

- [ ] **发代码**（无新 composer 依赖，不用 `composer install`，不用重建 app 容器）
- [ ] **传虚拟模特**（一次性，必须在 .env 的 OSS 配好之后做）：
      `docker compose exec app php artisan tryon:model /path/to/model.jpg`
      → 记下它打印的 `TRYON_MODEL_IMAGE=https://...`
- [ ] **改生产 `.env`**（5 行）：

```ini
DASHSCOPE_API_KEY=sk-xxx                 # 百炼 key
TRYON_ENABLED=true                       # 总开关
TRYON_MODEL_IMAGE=https://.../model.jpg  # 上一步打印的地址
TRYON_DAILY_LIMIT=10                     # 每人每天最多生成几次（缓存命中不计数）
TRYON_MEMBER_ONLY=false                  # 会员门先关（打开前要加 users.is_member 列）
```

- [ ] **建表**：`docker compose exec app php artisan migrate --force`
      （两个迁移：`tryon_tasks`、`tryon_garments` + `tryon_tasks.garments` 列。**纯新增，不动任何老表**）
- [ ] **清配置缓存**：`docker compose exec app php artisan config:clear`
      （用了 route:cache 的话再来一条 `route:clear`）
- [ ] **拉起队列 worker**（项目里第一个用队列的功能，这一步漏了整套功能就是坏的）：

```bash
docker compose up -d worker          # base compose 里已经加好 worker 服务
docker compose ps                    # 要看到 clothes-worker 是 Up
docker compose logs -f worker        # 盯着看有没有 Processing jobs
```

  - 非 Docker 部署：用 supervisor 常驻 `php artisan queue:work --tries=3 --timeout=300 --sleep=2`
  - **worker 的 `DB_*` 必须跟 app 一致**（base compose 里两边写的是同一套值 ✓）；
    不一致会报 `select * from cache ... 超时` 这类看不懂的错误，实际是连不上库
  - ⚠️ **worker 是常驻进程：以后改了 PHP 代码要 `docker compose restart worker`**
    （改环境变量要 `up -d --force-recreate worker`，`restart` 不重读 env）

## 2. 验证（发完立刻做）

- [ ] 服务器上手动跑一遍全链路（不用等小程序）：

```bash
docker compose exec app php artisan tinker --execute='
$svc = app(App\Services\Tryon\TryonService::class);
echo "开关: " . var_export($svc->enabled(), true) . " | 今天还剩: " . $svc->leftToday(3) . PHP_EOL;
$u = App\Models\User::find(3);
$o = App\Models\ClothesOutfit::where("user_id", 3)->whereNotNull("item_ids")->first();
$r = $svc->request($u, null, $o);
echo "task=" . $r["task"]->id . " cached=" . var_export($r["cached"], true) . PHP_EOL;'
```

  然后 `tryon_tasks` 里那条任务应该从 `pending` → `running` → `done`（30~50 秒），`result_url` 能打开

- [ ] 小程序点一次「试穿」（搭配预览页底部）：出图 → 保存到相册 → 设为封面，三样都试一遍
- [ ] 同一套再点一次「试穿」：应该**几毫秒**直接出图（缓存命中，不花钱）

## 3. 小程序端（后端验证过了再发）

- [ ] 微信后台 → 开发管理 → 开发设置 → 服务器域名 → **downloadFile 合法域名**加
      `gq-clothes.oss-cn-beijing.aliyuncs.com`
      （「保存到相册」「设为封面」都要 `wx.downloadFile`；只显示图不受影响。开发时可勾「不校验合法域名」先跑）
- [ ] 上传体验版 → 真机走一遍（上面第 2 节那三项）
- [ ] 正式发布

## 4. 影响面 / 怎么回滚

| 问题 | 回答 |
| --- | --- |
| 影响线上老用户吗 | 后端先发时零影响：老版本小程序不调新接口 |
| 小程序先发会怎样 | 按钮只在「开关开 + 已登录 + 今天还有次数」时出现，否则不显示 |
| 老数据会不会被改 | 不会。两个迁移都是新增表/新增列；删掉的 2D 人台不涉及任何数据 |
| 怎么回滚 | `.env` 改 `TRYON_ENABLED=false` → `php artisan config:clear` → 重启 worker。表留着没副作用，小程序按钮自动消失 |
| 出图失败怎么办 | 任务里 `error` 是技术原因（限流/识别不出来/超时），用户看到的是一句人话；可以重试 |

## 5. 花钱的口径（怕超支看这里）

- 一次「整套」生成 = 洗图 2 次（首次；每件衣服各一次）+ 试穿 1 次
- **同一套 + 同一个虚拟模特，第二次是 0 次调用**（结果缓存）；同一件衣服在别的搭配里复用洗好的白底图
- 上限：每人每天 `TRYON_DAILY_LIMIT` 次（默认 10），缓存命中不计数
- 单价以百炼控制台账单为准（本次没有按量计费数据，不估）

## 6. 上线后的已知限制（先给用户/客服说清楚，免得当 bug）

- 只有上装 + 下装能试；**鞋、包、配饰不参与**（试衣接口没有槽位，传进去会把鞋当上衣）
- 第一次生成 30~50 秒（要洗图），之后同一套秒回
- 图案/logo 会轻微漂移，手和侧身偶有畸变 —— 当前技术普遍水平，按「A 级观感像」验收
- 搭配里的鞋/包不出现在真人图上，接口会自己配一双它认为搭的鞋

## 6.5 提示语文案表（2026-09，跟试穿无关，但同一批发版）

- [ ] 跟着代码一起发即可：**没有新依赖、没有迁移**（只是多了一个只读接口 `GET /api/texts`）
- [ ] 想以后能直接在服务器上改文案，发完跑一次：

```bash
docker compose exec app php artisan texts:export    # 生成 storage/app/texts.json
```

      之后就在 `storage/app/texts.json` 里改提示语，**保存即生效**（不用重启、不用发版），
      只写要改的那几条就行。详见 [docs/文案.md](文案.md)
- [ ] 换文案后让用户**重开一次小程序**才会看到（小程序启动时拉一次文案）

- [ ] 顺带确认衣架相关的配置（都在 `.env`，改完 `config:clear`）：

```ini
QUOTA_ITEM=100                   # 新用户免费送多少衣架（总数；建号时给一次，改它只影响之后建的新号）
QUOTA_DAILY=100                  # 每天最多挂几个
QUOTA_REWARD_CHECKIN=2           # 每日签到送几个
QUOTA_REWARD_SHARE=10            # 分享好友送几个
QUOTA_REWARD_NEWCOMER=50         # 新用户每月免费领取（按月 1 次，**要用户点一下领取**；0 = 关掉那一行）
QUOTA_REWARD_MONTHLY=0           # 每月系统赠送（老口径：自动到账。已被上面取代，0 = 关闭）
```

## 7. 日常运维

```bash
docker compose logs -f worker              # 看队列
docker compose restart worker              # 改了 PHP 代码之后
docker compose up -d --force-recreate worker   # 改了 .env 之后
```

- 失败的任务在 `tryon_tasks.status=failed` + `error`；彻底失败（重试 3 次都没成）也进 `failed_jobs`
- 想手动重跑某条任务：`php artisan tinker` 里 `app(App\Services\Tryon\TryonService::class)->run(<task_id>)`
- 想让某套搭配重新生成：把它下面那条 done 的 `tryon_tasks` 置 `status='failed'`（缓存就失效了），
  或在页面上换一下这件衣服的照片（`source_hash` 变了，缓存自动失效）
