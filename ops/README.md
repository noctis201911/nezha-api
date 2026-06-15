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

> 🔑 **加密密钥不在脚本、也不在 repo**：`/root/.nezha/backup.key`（服务器本地，务必备份到密码管理器，丢了备份无法解密）。
> 解密示例：`openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/root/.nezha/backup.key -in <文件>.sql.gz.enc | gunzip > out.sql`

重建：把脚本 `cp` 回 `/root/nezha-backup/`，`chmod +x`，确保 `/root/.nezha/backup.key` 存在（从密码管理器恢复或新建一把并重新全量备份），再加回 crontab 第 4 条。
