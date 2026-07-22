# ops/ —— 运维/部署配置快照

本目录把原本散落在服务器、**不在 git 里**的运维配置（nginx 扩展配置、crontab、备份脚本）
做成**快照**纳入 repo，目的：换机/换人时能照着重建整套系统。

> ⚠️ **这是快照，不是软链，也不是线上活动文件。** 改线上配置后请回来同步这里；
> 反过来改这里不会影响线上，必须手工 `cp` 回对应路径再 `nginx -t && nginx -s reload`。

> 🔴 **密钥红线**：本目录**不含任何密码 / hash / 密钥 / token / 真实 .env 值**。
> 凭证类（htpasswd hash、backup.key、GitHub key、Firebase JSON）一律存密码管理器/服务器本地，**永不入库**。

完整"从零拉起系统"步骤见 [`docs/SETUP.md`](../docs/SETUP.md)。

---

## 1. nginx 扩展配置（`ops/nginx/`）

线上活动目录（宝塔结构，非 git）：
`/www/server/panel/vhost/nginx/extension/<站点>/`

宝塔会把同站点 extension 目录下所有 `*.conf` include 进该站 server 块。重建时把对应文件 `cp` 回去，
然后 `nginx -t && nginx -s reload`。

### 后端站点 api.nezha.am（`ops/nginx/api.nezha.am/`）
| 文件 | 线上路径 | 作用 |
|---|---|---|
| `00_no_intercept.conf` | …/extension/api.nezha.am/00_no_intercept.conf | server 级 `fastcgi_intercept_errors off`。API 是纯 JSON，关掉拦截避免 nginx 用 HTML 错误页替换 Laravel 的 404/502 JSON 并丢掉 CORS（否则前端拿到 Network Error 而非真实状态码）。 |
| `cors_429.conf` | …/extension/api.nezha.am/cors_429.conf | 命名 location `@rate_limit_response`：限速触发 429 时重声明全部安全头+CORS+JSON 错误体（命名 location 不继承 server 级 add_header）。 |
| `security_headers.conf` | …/extension/api.nezha.am/security_headers.conf | HSTS / X-Content-Type-Options / X-Frame-Options(DENY) / Referrer-Policy + 限速 `limit_req zone=api_req burst=300`，429 路由到 `@rate_limit_response`。 |
| `site_total.conf` | …/extension/api.nezha.am/site_total.conf | 宝塔流量统计 access_log（写 unix socket，tag=1）。 |
| `admin_basicauth.conf.example` | …/extension/api.nezha.am/admin_basicauth.conf | **模板，非真实文件**。后台 /admin、/login/admin 加 nginx Basic Auth。真实凭证在独立 htpasswd 文件 `/www/server/nginx/conf/.htpasswd_nezha_admin`（存密码管理器，不入库）。重建步骤见该 .example 文件头注释。 |

> 依赖：`security_headers.conf` 里的 `limit_req zone=api_req` 需要 http 块里先定义
> `limit_req_zone ... zone=api_req:10m rate=60r/m;`（宝塔全局/主 nginx.conf 内，不在本 repo）。
> 另外全局 http 块设了 `fastcgi_intercept_errors on`，`00_no_intercept.conf` 在 server 级把它关掉。

### 前端站点 nezha.am（`ops/nginx/nezha.am/`）
| 文件 | 线上路径 | 作用 |
|---|---|---|
| `nezha-next-cache.conf` | …/extension/nezha.am/nezha-next-cache.conf | 用更高优先级前缀 location 单独接管 `/_next/static`（永久 immutable 缓存）和 `/_next/image`（1天缓存）反代到 :3000，避免被 catch-all 的 no-cache 拖慢图片优化器。HTML 仍 no-cache（防历史上的 nginx 404 缓存陷阱重现）。 |
| `security_headers.conf` | …/extension/nezha.am/security_headers.conf | HSTS / nosniff / X-Frame-Options(SAMEORIGIN) / Referrer-Policy / Permissions-Policy(geolocation=self)。 |
| `site_total.conf` | …/extension/nezha.am/site_total.conf | 宝塔流量统计 access_log（tag=2）。 |

> 前端反代主体（catch-all `location ^~ /` → 127.0.0.1:3000）在宝塔的 proxy 配置里
> （`/www/server/panel/vhost/nginx/proxy/nezha.am/*.conf`），由宝塔反代向导生成，不在本 repo。
> 该 proxy 文件的 `location` 及其中的静态文件 `if` 块已经声明其它 `add_header`，按 Nginx
> 继承规则会遮蔽 server/extension 级的同类配置。因此线上必须在这两个上下文同时重声明
> `Permissions-Policy "camera=(), microphone=(), geolocation=(self)" always;`。宝塔重建反代后要
> 重新核对；每次修改先备份活动文件，执行 `nginx -t && nginx -s reload`，并验证 308、200、404
> 响应均携带该头。

### 重建一站 nginx 的通用步骤
```bash
S=/www/server/panel/vhost/nginx/extension
mkdir -p $S/api.nezha.am $S/nezha.am
cp ops/nginx/api.nezha.am/*.conf $S/api.nezha.am/      # admin_basicauth 见下
cp ops/nginx/nezha.am/*.conf     $S/nezha.am/
# admin_basicauth: 先建 htpasswd 再放 conf（见 admin_basicauth.conf.example 头注释）
nginx -t && nginx -s reload
```

---

## 2. crontab（`ops/crontab.txt`）

`crontab -l`（root）的快照。**不要整文件覆盖恢复**——第 1 条是宝塔托管的 hash 任务，
换机重装宝塔后由面板自己重建。审阅后用 `crontab -e` 手工录入需要的行。
四条任务的含义见文件内逐行注释（代理看门狗 / Laravel schedule:run / 每日加密备份 / 宝塔托管）。

---

## 3. 加密数据库备份脚本（`ops/backup/nezha-encrypted-backup.sh`）

线上路径：`/root/nezha-backup/nezha-encrypted-backup.sh`，由 crontab 第 4 条每日 04:17 调用。

流程：从 Laravel config 读 DB 连接 → `mysqldump`（single-transaction）→ `gzip` →
`openssl aes-256-cbc -pbkdf2` 加密 → 写 `/www/backup/database/nezha-enc/`，保留最近 14 份。

一轮产 **三份**加密件，都写进 `/www/backup/database/nezha-enc/`，再由同一轮的
`rclone copy` 整目录推 Cloudflare R2（异地保留 30 天）：

| 前缀 | 内容 | 本地保留 |
|---|---|---|
| `<库名>-<时间戳>-<token>.sql.gz.enc` | 全库 mysqldump（含 PITR 位点） | 14 份 |
| `nezha-files-<时间戳>-<token>.tar.gz.enc` | `storage/app` + `.env` | 14 份 |
| `nezha-opsnap-<时间戳>-<token>.tar.gz.enc` | **运维还原点目录** `/root/nzimg/backup` | 14 份 |

> 🔴 **运维还原点目录 = 改生产数据/图片前留的快照**（整行 JSON + 原文 + 逐张 SHA-256 + 还原器）。
> 由来：2026-07-22 压缩攻略配图并直接写生产 `nezha_guides.body_md`，还原点只躺在 `/root/nzimg/backup`——
> 不在 git、不在 `storage/app`、不在任何异地副本，那块盘一清这次写入就再也回不去了。
> **以后所有"改生产数据前的还原点"都放这个目录，就自动进异地备份**；放别处＝不在覆盖面内。
> 三个参数在脚本头部：`OPSNAP_PARENT` / `OPSNAP_NAME` / `OPSNAP_KEEP` / `OPSNAP_MAX_MB`。
> 目录不存在＝跳过（不是错误）；超过 `OPSNAP_MAX_MB`（默认 512MB）＝拒绝归档并在 `backup.log`
> 留一行 `OPSNAP BACKUP FAILED`，防有人往里丢大件把备份窗口和 R2 撑爆。
> 看门狗 `nzwatch.sh` 第 6e 段：目录存在但归档缺失或 >26h 未更新即邮件告警。
>
> 还原：`openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/root/.nezha/backup.key -in nezha-opsnap-<...>.tar.gz.enc | tar -xzf - -C /root/nzimg`
> （成员前缀是 `backup/`，解到 `/root/nzimg` 即原样还原出 `/root/nzimg/backup/...`；
> 想先看内容把 `-xzf` 换成 `-tzf`）。攻略正文的实际回写走 `ops/_guides_body_md_restore.php`。

> 🔑 **加密密钥不在脚本、也不在 repo**：`/root/.nezha/backup.key`（服务器本地，务必备份到密码管理器，丢了备份无法解密）。
> 解密示例：`openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/root/.nezha/backup.key -in <文件>.sql.gz.enc | gunzip > out.sql`

重建：把脚本 `cp` 回 `/root/nezha-backup/`，`chmod +x`，确保 `/root/.nezha/backup.key` 存在（从密码管理器恢复或新建一把并重新全量备份），再加回 crontab 第 4 条。

## 4. 攻略正文 `?v=` 写入的还原器（`ops/_guides_body_md_restore.php`）

2026-07-22 攻略配图压缩上产时，给 `nezha_guides.body_md` 里 14 处 `/static/guides/*.jpg`
追加了缓存串 `?v=20260722g`（不加就会被 CF 缓存 30 天发旧图，且**不报错**）。本脚本是那次写入的还原路径。

四道保险：① 库名硬断言 ② 事务 + `lockForUpdate` 行锁 ③ **前置条件守卫**——剥掉 token 后必须与备份
`body_md` 逐字节相等，否则判定有第三方写入、整体 ABORT 一行不改 ④ 回写后整行 SHA-256 必须 == 备份原值，
否则 rollback。与 `_demo_announcement_rollback.php` 同形状（都是"只在当前值仍是我写的那个值时才允许动"）。

```bash
cd /www/wwwroot/api-deploy/current
php ops/_guides_body_md_restore.php verify  <备份目录>              # 只读对账
php ops/_guides_body_md_restore.php restore <备份目录> --i-mean-it  # 真还原
php ops/_guides_body_md_restore.php rehearse       <备份目录>       # 临时表正向演练
php ops/_guides_body_md_restore.php rehearse-guard <备份目录>       # 临时表反向演练(证明守卫会响)
```

上线前跑过正反双演练（载体＝MySQL `TEMPORARY TABLE`，会话级、其它连接不可见、生产表全程只读）：
正向 7/7 行整行 SHA-256 精确回到原值；反向（混入第三方改动）守卫按预期拦下且回滚后零改动。

> 🔴 **脚本在库里，备份数据不在**：`<备份目录>`（7 行整行 json + `body_md` 原文 + SHA-256）含生产正文，
> **不入 git**。当前只存在于服务器 `/root/nzimg/backup/guides_db_20260722153226`，
> **不在仓库备份轮转、也不在异地备份覆盖面内** —— 那块盘一清，还原就只剩脚本没有数据。
> 待业主定：纳入现有 R2 轮转，还是另置。
