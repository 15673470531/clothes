# clothes — 通用基础 Laravel 项目

微信小程序后端骨架。新项目从这里复制，避免每次重搭环境。

技术栈：Laravel 12 + Filament 5（后台）+ Sanctum（Token 鉴权）+ MySQL 8 + nginx/php-fpm，全 Docker。

## 一、已包含

- 微信小程序登录（本地 `dev_xxx` 免校验）、手机号绑定、退出登录
- Filament 后台（路径 `/admin`），带用户管理
- 统一 API 响应格式：`{"code": 0, "msg": "success", "data": ...}`，`code = 0` 成功
- Docker 三件套：nginx + php-fpm + adminer，本地额外起 mysql + phpMyAdmin
- 本地/线上环境隔离（`docker-compose.override.yml`，本地绝不会连到线上库）
- 定时任务骨架（`routes/console.php`）、订阅消息服务、access_token 缓存
- 用户表已带 `openid / nickname / avatar_url / is_admin / phone / last_login_at / last_active_at`

## 二、不包含（复制后自己加）

- 业务表与业务代码（借条、记账等全部已剥离，migration 只留 9 个通用表）
- 任何密钥：`.env`、`.env.local`、SSL 私钥都不入库，`docker/ssl/` 是空目录

## 三、本地启动

```
cp .env.example .env
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

访问：后台 http://localhost:8090/admin ，phpMyAdmin http://localhost:18091

`worker` 是队列进程（AI 试穿靠它生成，没有端口）。**AI 试穿相关的部署、配置、坑见
[docs/AI试穿.md](docs/AI试穿.md)**。

衣架额度规则见 [docs/衣架规则.md](docs/衣架规则.md)；小程序里所有提示语（toast/弹窗）
由后端下发、改文案不用发版，见 [docs/文案.md](docs/文案.md)。

## 四、端口

| 服务 | 端口 |
| --- | --- |
| nginx | 8090 / 9443 |
| MySQL | 3312 |
| adminer | 18090 |
| phpMyAdmin | 18091 |

其他项目在用（凭记忆整理，复制新端口前用 `lsof -nP -iTCP -sTCP:LISTEN` 复核，避免撞车）：
money 8083/8443/3307/18080/18081，lvyou 8084/3308/18082，car 8085/3309，question 8086/3310，aiStudy 8088

## 五、复制成新项目的清单（关键）

复制本目录并改名后，以下 6 处必改，否则会跟别的项目撞端口、撞库：

1. `docker-compose.yml` — `container_name` 的 `clothes-*` 前缀
2. `docker-compose.yml` — network 名 `clothes-network`
3. `docker-compose.override.yml` — `container_name`、`MYSQL_DATABASE/USER/PASSWORD`、volume 名 `clothes-mysql-data`
4. `docker-compose.override.yml` — nginx 端口、mysql 端口、phpmyadmin 端口
5. `docker/mysql/init.sql` — 库名
6. `docker/nginx/default.conf` — 生产域名 `example.com` 与证书文件名

另外 `.env` 必须重新填：`APP_NAME`、`DB_DATABASE/USERNAME/PASSWORD`、`WECHAT_APPID`、`WECHAT_APPSECRET`。

## 六、编码约定

- 数据库查询/更新写在 Repository，不写在 Model；不写裸 SQL 字符串
- 空值判断用 `empty()`，比较运算符尽量用 `==` / `!=`
- 单元测试必须写中文说明注释（类顶部 + 每个 test 方法）
- 微信 appid/secret 一律走 `.env`，禁止硬编码（曾因硬编码 appid 导致新项目串用旧账号）

## 七、安全红线

- `.env`、SSL 私钥永不入库
- 本地开发一律走 `docker-compose.override.yml`，不连线上数据库
- 生产 nginx 的域名和证书名必须替换，不要沿用模板里的 `example.com`
