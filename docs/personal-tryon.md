# 个人试穿内测（首版）

入口：我的 → 我的试穿；已保存衣物 → 试穿这件；已保存搭配 → 试穿搭配中的上衣。

首版限定单件上衣（category=top），从衣橱或搭配里选择。要拍一件新衣服，先在衣橱录入并等待照片上传。支持用户上传正面全身或半身 JPG/PNG（10MB以内，256–8000px），最多5张。生成前明确确认人像使用与阿里云处理。历史显示最近30条，失败可重新提交，成功结果可对比原图、保存本地或删除。此版不覆盖搭配封面，不开放整套/裤子试穿，不提供尺码判断。

## 部署

1. `php artisan migrate --force`。
2. 已有 `DASHSCOPE_API_KEY` 与 `TRYON_MODEL` 配置复用；新增 `PERSONAL_TRYON_ENABLED=true`、`PERSONAL_TRYON_TEST_USERS=账号ID,账号ID`、`PERSONAL_TRYON_DAILY_LIMIT=3`。默认个人试穿关闭，旧 TRYON_ENABLED 不受影响。
3. APP_URL 必须为阿里云和微信都可访问的真实 HTTPS 后端地址（不是 localhost/私网地址）。个人照片通过该地址的签名接口临时读取。反向代理需正确保留 Host/HTTPS，否则签名校验失败。不得缓存 `/api/personal-tryon/media/*`。
4. app 与 worker 必须共享 `storage/app/private`，保留私有目录；不要创建 public 链接。开启数据库或 Redis 队列（sync 不允许提交）。worker 监听 default，`--timeout=300`；该 Job tries=1，避免失败自动重复付费。更新代码后重启 worker。queue retry_after 应大于 worker timeout（项目已有配置需核对）。
5. 配置缓存更新后重新构建，并将 APP_URL 对应域名加入微信 request/uploadFile/downloadFile 合法域名。
6. 小程序 app.js 中的 baseUrl 要指向部署新版后端的地址。本地页面调试可手动使用现有 localhost 注释配置；真正 AI 生成必须公网可达。

开发版显示入口，后端独立鉴权：local 环境的登录账号、管理员、明确配置的测试账号可访问。线上普通账号不能通过伪造开发模式获得接口权限。capability 查询不会返回人像/历史。

## 数据与次数

人像、结果都存私有盘。每次访问生成30分钟签名链接，删除照片或结果会撤销旧链接。删除人像同时删除关联结果，运行中的任务不会重新保存已删除内容。数据库保留任务元数据用于额度核算；成功后删除记录不返还次数。失败不计次，排队/生成中预占一次；每账号同一时间仅允许一个任务，重复点击相同输入返回同一任务。超过20分钟无状态更新会标失败，后到结果丢弃。

衣架额度不变。供应商费用可能仍按请求产生，失败不扣用户次数不等于供应商不计费。删除本项目照片无法撤回已经提交给供应商的处理请求。

## 验证

`php artisan test --filter='PersonalTryonTest|TryonTest'` 使用模拟供应商与 sqlite 内存数据库，无真实人像外发、不产生模型费用。覆盖鉴权、私有链接、输入隔离、重复提交、失败返还、删除不返还成功次数、删除期间生成不复活。

本地 APP_URL 为 localhost 时只能验证管理/页面及模拟任务，不能声称已经实测 AI 效果。上线灰度后需用授权人像验证生成质量与耗时。
