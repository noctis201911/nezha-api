# 哪吒外卖 (nezha.am) —— 从零拉起系统手册

换机/换人重建整套系统的步骤。配套快照见后端 repo 的 [`ops/`](../ops/README.md)。

- **后端**：Laravel (PHP 8.2)，目录 `/www/wwwroot/api.nezha.am`，域名 https://api.nezha.am
- **前端**：Next.js，目录 `/www/wwwroot/nezha.am`，PM2 进程 `nezha-web`，端口 3000，域名 https://nezha.am
- **DB**：MySQL（仅监听 127.0.0.1）
- **面板**：宝塔(BT) 负责 nginx/站点/SSL/部分计划任务

> 🔴 **凭证不在 repo**：真实 `.env`、htpasswd hash、`/root/.nezha/backup.key`、GitHub 部署 key、
> Firebase 服务账号 JSON、`src/utils/staticCredentials.js`（前端 Google 登录）—— 全部从密码管理器/旧机恢复，
> **绝不从 git 拿**。本手册只指到 `.env.example` 模板，键名见模板，值另填。

---

## 0. 前置：服务器基础环境
- 宝塔面板（提供 nginx、PHP 8.2、MySQL、SSL 证书管理）
- PHP 8.2 + composer；Node.js（与现网一致的 LTS）+ npm + PM2（`npm i -g pm2`）
- 安全基线（见 CLAUDE.md/「服务器」）：SSH 仅 key 登录、fail2ban、API 限速 60/min/IP、FTP 关闭、MySQL 仅本机

---

## 1. 克隆两个 repo
GitHub（private，SSH 部署 key 在服务器 `/root/.ssh/github_nezha`）：
```bash
# 后端
git clone git@github.com:noctis201911/nezha-api.git    /www/wwwroot/api.nezha.am
# 前端
git clone git@github.com:noctis201911/nezha-frontend.git /www/wwwroot/nezha.am
```
> 若用现成的 `github_nezha` key，先配 `~/.ssh/config` 或 `GIT_SSH_COMMAND='ssh -i /root/.ssh/github_nezha'`。

---

## 2. 后端 (Laravel)
```bash
cd /www/wwwroot/api.nezha.am
composer install --no-dev --optimize-autoloader

# 填 .env：以 .env.example 为模板，按键名填真实值（DB / APP_KEY / MAIL / PUSHER 等）
cp .env.example .env
# 1) 编辑 .env 填值（DB_*、SESSION_COOKIE 沿用旧值见模板注释）
# 2) 若无 APP_KEY： php artisan key:generate
php artisan migrate            # 首次建表（StackFood 初装可能用其安装流程，见 APP_INSTALL）
php artisan storage:link
php artisan config:cache && php artisan route:cache
chown -R www:www storage bootstrap/cache
```
> PII 静态加密（L1-7）：DB 表空间加密已在 MySQL 层启用；新机需确认 MySQL 开启了表空间加密能力。
> OAuth 私钥 `storage/oauth-*.key` 不在 repo（gitignore），首次用 `php artisan passport:keys` 或从旧机恢复。

---

## 3. 前端 (Next.js)
```bash
cd /www/wwwroot/nezha.am
npm ci          # 或 npm install

# 填环境：以 .env.example 为模板新建 .env.production（线上）
cp .env.example .env.production   # 按键名填真实值（NEXT_PUBLIC_BASE_URL、Firebase、Google Map key）
# ⚠️ src/utils/staticCredentials.js 不在 repo 也不可被部署覆盖：从旧机/密码管理器恢复真实 Google client_id

npm run build   # ~5 分钟
```

### 用 PM2 起前端
`ecosystem.config.js` 已在前端 repo（已 git 跟踪）：fork 模式、`next start -p 3000`、cwd=该目录、max_memory_restart 800M。
```bash
cd /www/wwwroot/nezha.am
pm2 start ecosystem.config.js
pm2 restart nezha-web && pm2 flush nezha-web
pm2 save        # 固化进程列表
pm2 startup     # 设置开机自启（按提示执行输出的命令）
```
日常改前端：`改 src → npm run build → pm2 restart nezha-web → pm2 flush nezha-web`
报错日志：`/root/.pm2/logs/nezha-web-error-0.log`

---

## 4. nginx
两站点在宝塔建站（生成主 server 块 + SSL + 前端反代 proxy 配置）。额外的扩展配置是快照，
见 [`ops/README.md`](../ops/README.md)，重建：
```bash
S=/www/server/panel/vhost/nginx/extension
mkdir -p $S/api.nezha.am $S/nezha.am
cp ops/nginx/api.nezha.am/00_no_intercept.conf $S/api.nezha.am/
cp ops/nginx/api.nezha.am/cors_429.conf        $S/api.nezha.am/
cp ops/nginx/api.nezha.am/security_headers.conf $S/api.nezha.am/
cp ops/nginx/api.nezha.am/site_total.conf      $S/api.nezha.am/
cp ops/nginx/nezha.am/*.conf                    $S/nezha.am/
# admin Basic Auth：先建 htpasswd，再放 conf（见 ops/.../admin_basicauth.conf.example 头注释）
htpasswd -c /www/server/nginx/conf/.htpasswd_nezha_admin admin   # 用密码管理器里的密码
#  然后据 .example 写回真实 admin_basicauth.conf
nginx -t && nginx -s reload
```
> 依赖：`security_headers.conf` 的 `limit_req zone=api_req` 需主 nginx.conf 的 http 块先有
> `limit_req_zone $binary_remote_addr zone=api_req:10m rate=60r/m;`（限速 60/min/IP）。

---

## 5. 计划任务 (crontab)
见 [`ops/crontab.txt`](../ops/crontab.txt)（逐行注释）。**别整文件覆盖**——第 1 条是宝塔托管。
`crontab -e` 手工录入：
- 代理看门狗（每 3 分钟）
- **Laravel 调度**：`* * * * * cd /www/wwwroot/api.nezha.am && /usr/bin/php artisan schedule:run >> /dev/null 2>&1`
- **每日加密备份**：`17 4 * * * /root/nezha-backup/nezha-encrypted-backup.sh >> /root/nezha-backup/backup.log 2>&1`

### 加密备份（见 ops/backup/）
```bash
mkdir -p /root/nezha-backup /root/.nezha
cp ops/backup/nezha-encrypted-backup.sh /root/nezha-backup/ && chmod +x /root/nezha-backup/nezha-encrypted-backup.sh
# 🔑 /root/.nezha/backup.key 从密码管理器恢复（丢了旧备份无法解密）；新机若新建 key，旧加密备份作废
```

---

## 6. 验证上线
- 后端：`curl -I https://api.nezha.am/...`（JSON 接口带 CORS 头、限速 429 带 CORS）
- 前端：浏览器开 https://nezha.am ，Playwright 真机截图 + console 无 error（见 CLAUDE.md「前端验证铁律」）
- 后台：https://api.nezha.am/login/admin （会先弹 nginx Basic Auth，再 Laravel 登录）
- 商家端：https://api.nezha.am/login/restaurant

---

## 附：部署辅助
- 本机 `nz.js`（ssh2 helper）：`node nz.js run "<cmd>"` / `node nz.js get <remote> <local>` / `node nz.js putfile <local> <remote>`
- 运营手册 `ADMIN_GUIDE.md`、商户手册 `MERCHANT_GUIDE.md`、合规文档 `docs/compliance/`、不变量 `INVARIANTS.md` 均在后端 repo
