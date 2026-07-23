# 顾客浏览器鉴权迁移与发布正本

状态：**业主已于 2026-07-23 批准裁决项 1–6，并授权本任务所需的提交、推送、staging 发布与同范围缺陷修复。最终 API/H5 候选已在 staging 进入 D1 readiness：Cookie 开、legacy 迁移关、TTL 覆盖未设置。首次 `nezha-auditor GATE` 的 5 项缺口及二次 GATE 发现的 Telegram callback Origin 阻断均已关闭；第三次 GATE 为 `CONDITIONAL PASS`，没有剩余代码级 production blocker。production 未部署，真实社交登录、真机 Safari、业务连续性亲测与业主最终点头仍是硬门。**

## 1. 已批准目标

- 顾客 H5 最终使用 `api.nezha.am` 的 host-only `HttpOnly; Secure; SameSite=Strict` Cookie。
- JavaScript 不读取 Cookie 会话标识；CSRF 使用独立随机 token，请求头为 `X-CSRF-Token`。
- Cookie 会话空闲期 30 天、绝对期 90 天，每位顾客最多 5 个活动会话。
- Cookie 稳定前不设置 TTL 覆盖，继续使用 Passport 的 `P1Y`；稳定并经业主确认后只对**新签 token**改为 7 天。
- 不批量吊销现存 493 个 Passport token。活跃顾客逐会话迁移并在 Cookie 二次确认后吊销当前旧 token；未活跃 token 按原 `exp` 自然到期。
- 未重新认证的迁移会话绝对到期不得晚于 `min(旧 token expires_at, 迁移时刻 + 90 天)`。
- Apple 生产登录当前保持关闭；只在受控环境开启并由业主完成真 Apple 验收。

## 2. 严格边界

- 本次只改顾客端 Passport 鉴权。
- 商家端 `vendor.api` / `auth_token` 与骑手端 `dm.api` / `auth_token` 不改、不迁移、不改变 TTL。
- 不改支付、退款、资金、L1 合规机制。
- 不执行批量 token 吊销。
- staging migration、配置、commit、push 与发布按本任务授权连续执行；production migration、配置与发布仍受真实社交登录/真机 Safari、`nezha-auditor GATE` 和业主最终点头约束。

## 3. 双栈迁移协议

### 3.1 新登录

1. 服务器沿用现有响应中的 Passport token，保证旧 H5/原生客户端兼容。
2. 功能开关开启时，成功登录响应同时设置 Cookie 会话。
3. 新 H5 先用 Cookie 请求 `GET /api/v1/auth/session` 取得 CSRF token。
4. 新 H5 再调用 `POST /api/v1/auth/session/confirm-migration`；服务器吊销刚签发的兼容 Passport token。
5. D2 客户端在 Cookie 探测和吊销确认期间把兼容 token 保留为恢复桥；只有服务器明确返回 `confirmed=true` 才删除。Cookie 被拒、确认返回 false 或响应丢失时继续保留 Bearer，避免把顾客误登出。正常成功链路完成确认后不再保留该 token。

### 3.2 现存 `localStorage.token`

1. H5 用现存 Bearer 调用 `POST /api/v1/auth/session/migrate`。
2. API 设置 Cookie，会话绝对期按旧 token 剩余寿命和 90 天上限取较短者。
3. H5 使用 Cookie + CSRF 调用确认接口。
4. API 确认 Cookie 已完成第二次往返后吊销该旧 token。
5. 只有确认成功，H5 才删除 `localStorage.token`。

第一响应丢失、网络失败或 Cookie 被浏览器拒绝时，旧 token 保留，现有登录不会被迁移过程误登出。旧 H5 同时带 Bearer 与 Cookie 时，API 优先验证 Bearer，保持旧客户端无 CSRF 头的契约。

同一旧 token 的迁移由 `legacy_access_token_id` 唯一索引与顾客行锁保证单胜者。两个首次迁移并发时，一个创建会话，另一个返回 409；同浏览器随后可读取共享 Cookie，未成功确认前旧 Bearer 始终保留。

## 4. 社交登录路径

- Google：第一段 `google/redirect-login` 只返回一次性短码，不设置登录 Cookie；最终 `social/exchange` 返回 token 时才设置 Cookie。
- Apple：现有直接登录响应设置 Cookie；生产开关仍保持关闭，受控开启后验证。
- Telegram：OIDC exchange、link/complete 等实际返回 token 的成功响应设置 Cookie。来自 Telegram 的 OIDC callback 是跨站顶层 GET，因此只保留外层限流、callback 专用限流及 state / nonce / PKCE / 一次性 attempt 校验，不套用仅允许 H5 fetch 的 `customer.login-origin`；start、exchange 和 link 端点继续受该 Origin 门保护。
- Email、手机号、手动密码和注册：统一通过同一 token 签发服务，并只在外部 2xx JSON 真正返回该 token 时设置 Cookie。

任何模拟、fixture、渲染成功或接口桩都不能代替真实 Google / Apple / Telegram 验收。

## 5. 发布顺序

### 阶段 A：代码就位但保持惰性

- API 部署代码，保持：
  - `NEZHA_CUSTOMER_BROWSER_SESSION_ENABLED=false`
  - `NEZHA_CUSTOMER_LEGACY_TOKEN_TTL_DAYS` 未设置
- 此阶段不访问新会话表、不改变 CORS 凭据模式、不改变现有 token TTL。

### 阶段 B：数据库准备

- 先在可丢弃、具有代表性的 MySQL 5.7 环境执行 migration 并验证索引、外键、字符集和回滚。
- 经单独授权后再在目标环境执行 migration。
- migration 完成不等于启用 Cookie。

### 阶段 C：API 双栈启用

- 配置精确 H5 Origin：
  - `NEZHA_CUSTOMER_BROWSER_ALLOWED_ORIGINS=https://nezha.am,https://www.nezha.am`
- 设置 `NEZHA_CUSTOMER_BROWSER_SESSION_ENABLED=true`。
- 保持 TTL 覆盖未设置，兼容 token 继续走 Passport `P1Y`。
- 只有精确顾客 H5 Origin 获得 credentialed CORS；其它 Origin 继续现网 wildcard/no-credentials，避免误伤商家/骑手。
- 只为精确顾客 H5 Origin 且显式携带 `X-Nezha-Customer-Cookie: 1` 能力头的新 H5 签发 Cookie；旧 H5 与无 Origin 原生客户端继续只收现有 Bearer，不产生无用浏览器会话。
- 每个使用 Cookie 鉴权的请求都必须同时携带精确可信 `Origin` 与 `X-Nezha-Customer-Cookie: 1`；因此跨站顶层 GET、不可信同站子域和缺能力头请求在解析 Cookie 前即失败。非安全方法还必须额外通过 `X-CSRF-Token`。
- 验证带凭据 CORS、Cookie 属性、CSRF 拒绝、Bearer 兼容和旧 H5 不回归。

### 阶段 D1：H5 Cookie readiness

- H5 构建配置：
  - `NEXT_PUBLIC_CUSTOMER_COOKIE_AUTH=true`
  - `NEXT_PUBLIC_CUSTOMER_COOKIE_MIGRATION=false`
- 新 H5 开始发送 Cookie/CSRF 并验证服务端会话，但继续保留和签发兼容 Bearer，不删除 `localStorage.token`。
- 这一过渡阶段让已打开的旧 H5 标签页继续工作；尚未完成降低暴露的最终目标。

### 阶段 D2：H5 迁移与清除

- 经 readiness 观察与业主确认后，构建配置 `NEXT_PUBLIC_CUSTOMER_COOKIE_MIGRATION=true`。
- 验证冷启动迁移、网络失败保留 Bearer、多标签页同步、过期边界和 bfcache 恢复。
- 观察 Cookie 会话创建、旧 token 个别吊销和登录/401 指标。
- D2 会使尚未刷新到 readiness 版本的长期旧标签页失去已吊销 Bearer；需在切换前明确告知并把“旧标签页刷新后恢复”列入验收。

### 阶段 E：缩短新签 token

- Cookie 路径稳定、真实验收完成、`nezha-auditor GATE` 复核且业主再次点头后，设置：
  - `NEZHA_CUSTOMER_LEGACY_TOKEN_TTL_DAYS=7`
- 该配置只影响此后新签的 Passport personal access token。
- 现存未迁移 token 的 JWT `exp` 不变，不会造成全站集中登出。

## 6. 验收证据

### 可自动验证

- 2026-07-23 隔离 MySQL 5.7.44：建表与 legacy 唯一索引 migration 均完成 `up → down → up`；确认 InnoDB、`utf8mb4_unicode_ci`、全部索引及 `users` 外键 `ON DELETE CASCADE`。
- 同一 MySQL 5.7.44 实例：5 会话上限、迁移绝对期取旧 token 剩余寿命、确认后清除 legacy 关联、CSRF 和登出吊销均通过；实例已停机。
- 两个独立 PHP 进程在 MySQL 5.7.44 同时迁移同一旧 token，实测结果为 `winner, conflict`，活动 pending 会话严格为 1。
- H5 相关鉴权源测试 14 项通过；readiness 与 migration 两组编译时开关均完成 Next.js 15.5.21 production build，21 个静态页面生成成功。
- API 最终候选的顾客浏览器会话套件为 13 tests / 95 assertions 通过；与 Telegram service / OIDC client 合跑为 25 tests / 179 assertions 通过。相对基线的 22 个 PHP 文件均通过 `php -l`，最终 Telegram route / test 通过 Pint；测试仅保留现有 PHPUnit 配置与缺测试 `business_settings` 的 warning。
- 新 Cookie：host-only、`HttpOnly`、`Secure`、`SameSite=Strict`、Path `/`。
- 数据库只保存 Cookie token 的 SHA-256 hash；CSRF token 加密保存。
- 新 Cookie 会话的 idle / absolute 到期时间分别符合 30 / 90 天。
- 迁移会话绝对到期不晚于旧 token 到期时间。
- 第 6 个活动会话只吊销最旧活动会话，活动数保持 5。
- `POST /customer/logout` 后 Cookie 会话 `revoked_at` 非空；Bearer 路径仍验证 `oauth_access_tokens.revoked=1`。
- 两阶段迁移仅在 Cookie 确认后吊销旧 token。
- 服务层双标签共享 Cookie 时 CSRF 稳定；页面级多标签迁移、登出与 bfcache 表现仍须业主亲测。
- 改为 7 天后，DB 实测**新签** token 的 `expires_at`；旧 token 的 `expires_at` 不变。

### 必须由业主亲测，未亲测不得标绿

- ⬜ 真 Google 登录
- ⬜ 真 Apple 登录
- ⬜ 真 Telegram 登录
- ⬜ 真机 iOS Safari，包括 provider App 返回
- ⬜ 登录前游客购物车与登录后返回页
- ⬜ 多标签页迁移、登出、30 天空闲边界与 90 天绝对边界的业务表现

第三次 `nezha-auditor GATE` 已为 `CONDITIONAL PASS`：允许继续 staging D1 与上述真实路径验收，但在亲测完成并由业主明确点头前，不允许 D2、7 天 TTL 或 production。

## 7. 回滚

1. 优先关闭 H5 `NEXT_PUBLIC_CUSTOMER_COOKIE_AUTH` 并回到上一 H5 SHA。
2. 关闭 API `NEZHA_CUSTOMER_BROWSER_SESSION_ENABLED`，恢复原 wildcard/no-credentials CORS 与纯 Bearer 路径。
3. API 回到 `nzdeploy` 上一个 SHA；不在紧急回滚中删除会话表。
4. 若已把新签 TTL 改为 7 天，可移除 TTL 覆盖恢复 Passport `P1Y`，但**已经按 7 天签发的 token 不会变长**；需接受这些顾客在原 `exp` 到点后重新登录。
5. 不以回滚为理由批量吊销现存 token，也不回退私钥最小权限。

## 8. 环境变量

| 变量 | 默认值 | 用途 |
|---|---:|---|
| `NEZHA_CUSTOMER_BROWSER_SESSION_ENABLED` | `false` | API Cookie 双栈总开关 |
| `NEZHA_CUSTOMER_BROWSER_ALLOWED_ORIGINS` | `https://nezha.am,https://www.nezha.am` | 精确 CORS / 登录 Origin |
| `NEZHA_CUSTOMER_BROWSER_COOKIE` | `__Host-nezha_customer_session` | host-only Cookie 名 |
| `NEZHA_CUSTOMER_BROWSER_IDLE_DAYS` | `30` | 空闲期 |
| `NEZHA_CUSTOMER_BROWSER_ABSOLUTE_DAYS` | `90` | 绝对期 |
| `NEZHA_CUSTOMER_BROWSER_MAX_SESSIONS` | `5` | 单顾客活动会话上限 |
| `NEZHA_CUSTOMER_BROWSER_TOUCH_MINUTES` | `5` | 活跃时间写库节流 |
| `NEZHA_CUSTOMER_LEGACY_TOKEN_TTL_DAYS` | 未设置（Passport `P1Y`） | 仅新签 Passport token TTL 覆盖 |
| `NEXT_PUBLIC_CUSTOMER_COOKIE_AUTH` | 未设置 / `false` | H5 Cookie 模式构建开关 |
| `NEXT_PUBLIC_CUSTOMER_COOKIE_MIGRATION` | 未设置 / `false` | H5 吊销并清除 legacy token 的独立切换开关 |

## 9. 2026-07-23 staging readiness 证据

- API 最终候选：`bef9b5c646aef08964f131ad5bd439abb257f9d4`；9016 的真实进程 cwd、`current` symlink 与该 release 一致，previous 为上一修复候选 `326acb2838a69f22dd1df1032fe387c360136942`。
- H5 修复版候选：`1fee62c9c7e77e523a0640a0a313c55bc25be77c`；BUILD_ID `8NavPObXtkkscTF9dWWgv`，3002 `/home` 为 200。staging nginx 继续由 Basic Auth 保护，未认证访问为 401 且带 `WWW-Authenticate`。
- staging 开关：API Cookie=true，允许 Origin 仅 `https://staging.nezha.am`；H5 Cookie=true、Migration=false；legacy token TTL override 为 null。
- 当前 H5 `_app` 编译产物中的鉴权模块实测内联为 Cookie mode=`true`、migration mode=`false`，包含 `withCredentials`、`X-Nezha-Customer-Cookie`，并且只在确认结果为真时清除恢复 token；不是只检查 env 文本。
- 目标 staging MySQL 两个 migration 均为 Ran；表为 InnoDB / `utf8mb4_unicode_ci`，legacy token 索引为 unique，`users` 外键 `ON DELETE CASCADE`。
- 可信 Origin 预检返回精确 ACAO 与 `Access-Control-Allow-Credentials: true`；不可信 Origin 维持 `*` 且无 credentials。未登录 session 返回 `authenticated=false` 且无 `Set-Cookie`；无 Cookie 会话的危险 POST 返回 401。
- staging MySQL 事务内实测：migrate 200；Cookie 为 host-only / Secure / HttpOnly / SameSite=Strict；跨站 Origin、不可信同站子域、无 Origin 顶层导航、缺能力头 4 组 Cookie GET 均为 401 且顾客数据不变；缺/错 CSRF 均为 419 且 legacy token 未吊销。
- 同一事务后半段实测：正确 CSRF confirm 200 后 `oauth_access_tokens.revoked=1` 且 legacy link 清除；旧 Bearer 再迁移为 401；Cookie logout 200 后 session 已吊销。商家/骑手登录请求不再返回 `customer_login_origin_rejected`，其 customer Origin 中间件边界测试也通过。
- 最终 staging nginx 实测：无 Origin、`Sec-Fetch-Site: cross-site` 的 Telegram callback 能进入控制器，并 302 到 `https://staging.nezha.am/auth/telegram?error=invalid_request`；同条件攻击者 Origin 调用 Telegram start 仍为 403。缺 state、错误 state、nonce / PKCE 与重复 exchange code 的自动验证继续失败；真实 Telegram 成功链路仍须业主亲测。
- 第三次 `nezha-auditor GATE` 为 `CONDITIONAL PASS`：上次 5 项和 Telegram callback blocker 均已关闭，没有新的代码级 blocker；真实社交登录、真机 Safari、游客购物车/返回页、多标签及到期边界仍为 production 条件。
- 上述事务在 finally 中回滚；复核 `customer_browser_sessions=0`、测试名 Passport token=0，没有残留测试凭据或会话。
- Chrome 页面验收被 staging Basic Auth 凭据阻断；没有绕过入口或读取浏览器密码。真 Google、真 Apple、真 Telegram、真机 iOS Safari 与页面级多标签行为仍为“需业主亲测”，不得标绿。
- production API/H5 分别仍为 `4e64e7d…` / `51b02e8…`，均未加载本候选；production 现存 Passport token 未批量吊销。`storage/oauth-private.key` 独立加固保持 mode 600、owner `www:www`。
