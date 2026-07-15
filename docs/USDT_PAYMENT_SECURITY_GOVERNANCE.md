# 哪吒 USDT 收款地址与仿冒风险治理方案

状态：**production backport 代码、migration、CSP Report-Only 与有效网络状态初始化已于 2026-07-15 发布；两个业务功能闸仍为 `0`，尚未创建真实 reviewer 或绑定其 TOTP，也未进行真实资金/订单消费链验收，因此治理能力仍处于 dormant、未全量启用状态。**

核对日期：2026-07-15（Europe/Berlin）

适用仓库：后端 `/www/wwwroot/api.nezha.am`、前端 `/www/wwwroot/nezha.am`

## 0. 2026-07-15 production rollout 当前事实

本节是当前状态唯一入口；下文保留的 2026-07-14 基线、候选和“未部署”表述是制定方案时的历史快照，若与本节冲突，以本节和生产只读核对为准。

### 0.1 已完成

- API production backport 已先整合线上 `dea5dd11` 邮件租户边界 hotfix，发布目标为 `363dedf674c29aa020d2bb472bebc09bc576b4ba`；当前 release 为 `/www/wwwroot/api-deploy/releases/20260715-061306-363dedf`，回滚锚点为 `/www/wwwroot/api-deploy/releases/20260715-042928-dea5dd1`。
- Web 发布目标为 `2c614b960f128dcfb0dcdbcf9cb63cf9302bc628`；当前 release 为 `/www/wwwroot/web-deploy/releases/20260715-061421-2c614b9`，运行时 BUILD_ID `GIVFyeKyIoHEeGBFVRhFc`，回滚锚点为 `/www/wwwroot/web-deploy/releases/20260714-101004-2f81803`。
- API 集成目标回归为 80 tests / 529 assertions；V3 reviewer 页 8/64、CSP 端点 8/161、生产 hotfix 隔离回归 5/51 均通过。原候选 `926173fe` 在同一依赖环境也是 80/529，因此旧交接中的 80/626 是已核实的统计漂移，不把它误报成回归下降。Web 的 payment CSP TAP 4/4、social-login SDK 1/1、付款地址脚本和 production build 均通过。
- 2026-07-15 06:12 UTC 已生成并完成本机解密测试及 R2 同名对象核验的生产备份：数据库密文 SHA-256 `05dcc657aadd3360c2c63fda0bc8f24bdc73efa5e83f32e3bf619a992941cd50`，文件密文 SHA-256 `5133d92f14731db4bf07c37538fac9a59381da0b0ecb3ef38bbc25a8f587b281`。本文不记录备份密钥。
- migration batch 196 已完成；凭据、网络状态、变更和事件表均存在且启用 MySQL `ENCRYPTION="Y"`。两个业务设置键唯一存在且均为 `0`：`nezha_payment_address_credential_status=0`、`nezha_payment_address_change_status=0`。
- 18 个“商户 × 网络”组合完成 dry-run、apply、复核 dry-run 与独立对账：3 个有效组合已初始化为 `active`、version 1，15 个无效或空地址组合未伪造状态。有效组合是商户 6 的 TRC20/BEP20、商户 12 的 TRC20；商户 7–11 的 TRC20 无效，商户 7–14 的 BEP20 均为空，商户 13–14 的 TRC20 为空。
- production 公开面已用真实 Chromium 验收：`/home` 与 `/checkout` 在 1440×1024、390×844 均无横向溢出，结算空态可返回首页，控制台 0 error；首页只有既有 Firebase 通知权限未授予 warning。`/checkout` 200，Report-Only CSP 已生效且移除 `'unsafe-eval'`，报告地址为 API 的脱敏 CSP endpoint；现行 enforce CSP 未在本轮收紧。
- 发布后 API/Web health、PM2、队列、MySQL/Redis/PHP-FPM/nginx 与 COD=off 均复核正常；后台无 HTTP Basic 时仍返回 401。

### 0.2 仍未完成，禁止误报为全量启用

- 生产只有 1 个已启用 TOTP 的 superadmin，没有独立 reviewer。尚未取得 reviewer 的真实姓名、登录邮箱、电话、初始密码安全交付对象、TOTP 绑定人在场窗口、恢复码托管人及现有 nginx HTTP Basic 的受控交付方式；不得编造身份、二维码、secret 或恢复码。
- 商户 7–11 的 TRC20 当前无效。它们现时均非 active，不会立即打断真实订单；但以后重新激活商户并开启 credential 闸时会 fail-closed。业主必须先确认接受该行为，或协调商户把真实地址改为有效值。
- 过去 90 天共有 44 笔订单、32 条仍保留 payment_info 的线下付款记录，但真实 USDT 凭证提交为 0；credential 表为 0 行，新 endpoint 上线后请求为 0。当前只能得到低流量代理，尚没有真实日签发量，不能把行大小估算或支付宝线下单数量冒充容量验收。
- production 凭据“消费”只能由登录顾客对真实订单提交真实 USDT 付款凭证触发。不得在未实际付款时点击“已付款”，不得擅自创建假订单/假 proof。业主需在真实小额付款、明确授权可识别且可回滚的 production 测试数据、或接受 production 消费链保持未验证三者中作出裁决。
- 真实申请管理员、独立 reviewer、商户 owner、已登录顾客、不同于当前值的商户自有有效地址、通知实际接收人和在场事故联系人尚未具备。队列成功只表示任务执行，不等于邮件/Telegram/push 已送达；必须由真实接收人确认。
- 因上述硬门槛，V3 reviewer production 页面、申请→商户确认→不同管理员批准/驳回、credential 消费/订单回显、通知送达、真实日签发容量和两个闸的顺序开启尚未验收。当前没有地址变更、订单写入、资金动作或 reviewer 账户写入。

### 0.3 下一执行顺序与异常回退

1. 业主补齐真实 reviewer/TOTP/HTTP Basic 安全交付和受控测试对象，并裁决商户 7–11 无效 TRC20 与 production 真实消费验收方式。
2. 创建独立 reviewer，现场绑定强制 TOTP 并交付恢复码；先完成 dormant 后台 V3 UI 与权限隔离浏览器验收。
3. 仅在真实对象就绪后开启 credential 闸，观察签发、复用、消费、过期、订单回显、容量和通知；凭据链稳定后再开启 change 闸并执行申请、商户确认、独立 reviewer 批准/驳回。
4. 任一步异常先关闭 change 闸，再按影响关闭 credential 闸并清理 business settings cache；需要代码回退时把 API/Web `current` 切回 0.1 所列锚点。不得逆向 migration、删表、删除凭据/状态/审计行或写回旧地址。

## A. 方案制定阶段的完成定义与历史边界

以下内容记录 2026-07-14 制定方案时的边界，不是 2026-07-15 production 当前状态。

本文完成时应同时满足：

1. 当前生产 Git、实际发布包、脏工作树、现行地址写入路径、CSP 与公开 DNS 已重新核对；
2. 地址变更状态机的角色、状态、不可绕过规则、失败态、测试、发布与回滚方案明确；
3. CSP、DMARC、DNSSEC、仿冒举报均有分阶段影响、验证、停点和回滚；
4. 本文只形成可执行方案，不把“写了方案”误报为“风险已消除”。

本轮明确不做：

- 不部署、不运行迁移、不翻任何功能闸；
- 不改 DNS、nginx 或生产配置；正式 UI 只进入隔离分支，不接入生产发布包；
- 不接 Binance、WalletConnect，不要求顾客连接钱包、签名或授权 USDT；
- 不恢复复制金额按钮，不增加无意义确认或“锁定”步骤；
- 不清理、重置或覆盖两个生产仓库的脏工作树。

## 1. 重新核对后的真实基线

### 1.1 Git 与运行版本

| 对象 | 2026-07-14 实测 | 结论 |
|---|---|---|
| 后端实际发布包 | `/www/wwwroot/api-deploy/releases/20260714-063310-75c6e4c` | 运行版本为 `75c6e4c` |
| 后端生产 Git 工作目录 | HEAD `5042b39`，`origin/main` `75c6e4c`，87 项脏状态 | 工作目录不是运行版本，禁止直接在此开发或据此打包 |
| 前端实际发布包 | `/www/wwwroot/web-deploy/releases/20260714-061958-9b42195`，BUILD_ID `j93g2gqfkxjjK_foe_D0W` | 运行版本为 `9b42195` |
| 前端生产 Git 工作目录 | HEAD `fb14214`，`origin/main` `9b42195`，11 项脏状态 | 工作目录不是运行版本，禁止直接在此开发或据此打包 |
| 地址凭据候选 | `codex/payment-address-credential-20260713`，HEAD/远端均为 `936be838`，工作树干净 | 未部署、未迁移、未翻闸；已落后当前 main，不能原样发布或盲目 rebase |
| 本方案分支 | `codex/usdt-security-governance-20260714`，基于 `75c6e4c` | 只写版本化方案，隔离生产脏树 |

这构成交接漂移：交接中的提交号只能定位历史候选，不能代表当前运行版本。

### 1.2 当前资金地址写入与凭据候选

- 当前管理员写入入口仍是 `routes/admin.php` 的 `update-payment-info/{restaurant}`，最终进入 `app/Http/Controllers/Admin/VendorController.php::updatePaymentInfo`。
- 该路径目前只做 `nullable|string|max` 一类基础校验，随后直接覆盖 `payee_name`、`usdt_address`、`usdt_bep20_address`、`usdt_network` 并保存。
- 地址变更后的通知属于保存后动作；通知失败不会阻止地址已经生效。
- 候选 `936be838` 已提供默认关闭的支付地址凭据：随机公开标识、秘密哈希、加密地址快照、网络/商家/用户/订单绑定、过期与单次消费。它解决“订单应使用哪个版本地址”的证据问题，但没有地址变更审批、异常时的网络暂停与凭据撤销、旧写入口封堵或追加式审计。
- 生产数据库只存在 1 个管理员且已启用 TOTP。正常地址变更若要求“不同管理员复核”，当前组织条件下将无法完成；正确处理是先建立第二个独立管理员并启用 TOTP，不能退化为自批。

### 1.3 当前 CSP、邮件与公开报告入口

- 顾客站当前强制 CSP 仍允许 `script-src 'unsafe-inline' 'unsafe-eval'`，并全站允许 Google、Apple、多个广告/分析来源；`img-src` 允许任意 HTTPS；没有 `frame-ancestors`。
- `pages/_document.js` 全站加载 Google GSI、Apple 登录脚本，并保留多家分析脚本分支；付款页没有按路由收敛。
- CSP 在宝塔生成的 proxy 配置与 extension 文件中重复定义，实际生效值可能被 proxy 文件覆盖；未先确定唯一 owner 就直接改一份配置会产生“以为已收紧、实际未生效”的漂移。
- `_dmarc.nezha.am` 无 TXT；`nezha.am` 无 DS、DNSKEY、CAA。
- 已有 SPF，且 `resend._domainkey.nezha.am` 存在 DKIM 公钥；`send.nezha.am` 有 Amazon SES SPF/MX。DMARC 上线前仍须用真实邮件头证明 From、DKIM、SPF 的域对齐，不能仅凭 DNS 记录推断全部合法发件源已覆盖。
- `https://nezha.am/.well-known/security.txt` 当前返回 404，尚无公开且已验证可达的安全举报入口。

## 2. 风险发现

### SEC-USDT-001 — 管理员可直接覆盖正在收款的地址

- 严重性：**High**
- 位置/证据：`app/Http/Controllers/Admin/VendorController.php::updatePaymentInfo`；`routes/admin.php` 的 `update-payment-info/{restaurant}`。
- 影响：管理员会话、后台操作链或浏览器被劫持后，新地址可立即成为顾客看到的收款地址；通知失败不回滚写入。地址输错也会产生同类资金损失。
- 修复：默认关闭的地址变更状态机、交易级 TOTP、商家确认、不同管理员复核、批准后新地址立即用于新付款、旧凭据仅按原到期时间继续、追加式审计，并封死旧入口直写旁路。
- 暂时缓解：保持地址变更人数最小化；任何异常先暂停对应网络而不是写新地址；人工双渠道核对当前地址指纹。
- 误报边界：现有 Basic Auth、Laravel 登录和登录 TOTP 降低了利用概率，但不能代替交易级复核，也不能消除误操作。

### SEC-USDT-002 — 地址凭据候选不能单独构成地址变更防线

- 严重性：**High**
- 位置/证据：候选分支 `936be838` 只新增凭据服务/API/迁移/订单接入；未新增地址变更实体、网络状态或旧写入口封堵。
- 影响：即使凭据功能开启，攻击者仍可从旧管理员入口改地址；尚未签发凭据的新订单会读取被替换后的地址。候选还落后当前 main，原样发布可能覆盖后续租户边界与面板修复。
- 修复：按当前 main 重新移植凭据能力，并把地址版本证据接入变更状态机；批准后新地址立即用于新付款，旧凭据只按各自原到期时间继续。禁止直接部署历史候选。
- 暂时缓解：继续保持候选未部署、迁移未运行、功能闸关闭。
- 误报边界：候选中的地址快照与绑定设计本身有价值；问题是它的覆盖边界不足，不是说其代码等同于漏洞。

### SEC-USDT-003 — 付款路由的 CSP 防御面过宽

- 严重性：**Medium**
- 位置/证据：生产 `Content-Security-Policy`；nginx 的 `extension/nezha.am/security_headers.conf` 与宝塔 proxy 生成配置。
- 影响：若页面另有注入点，`unsafe-inline`、`unsafe-eval` 和过宽第三方来源会扩大脚本执行与数据外传空间；付款地址属于高价值 DOM 内容。
- 修复：先 Report-Only，再按付款路由移除未使用第三方、消除 `unsafe-eval`、以 nonce/hash 替代内联放行、加入 `frame-ancestors 'none'`，最终强制执行。
- production 状态（2026-07-15）：Web 已为 `/checkout`、`/info`、`/tracking` 启用 `Content-Security-Policy-Report-Only`；策略保留现行 host 与 `'unsafe-inline'`，移除 `'unsafe-eval'`，补 `frame-ancestors 'none'`、`worker-src 'self'`、`manifest-src 'self'`。API 的仅 POST 报告端已发布，仍按 16 KiB、60/分钟与 600/小时、10 分钟去重，并只记录脱敏字段。现行 enforce、nginx 与 Cloudflare 未在本轮更改。
- 暂时缓解：保留 `object-src 'none'`、`base-uri 'self'`、`form-action 'self'`，监控前端依赖和第三方配置变化。
- 误报边界：宽 CSP 不是“已经发生 XSS”的证据；它是关键页面防御纵深不足。

### SEC-USDT-004 — 登录和分析脚本全站注入付款页面

- 严重性：**Medium**
- 位置/证据：前端 `pages/_document.js` 中 Google GSI、Apple 登录及多家分析脚本分支。
- 影响：付款页面承担不必要的第三方供应链、隐私和 CSP 放行成本；未来启用分析配置时，付款路由会随之扩权。
- 修复：社交登录脚本只在实际登录动作或登录页懒加载；分析脚本明确排除结算、付款、订单凭据展示路由。
- production 状态（2026-07-15）：Google GSI 与 Apple ID SDK 已从全站 `_document` 移到登录组件按需单次加载；即使 analytics API 返回非空配置，付款路由也不请求或渲染广告/分析脚本。公开 `/home`、`/checkout` 与 social-login SDK 回归已通过；真实登录动作、地图、Firebase/PWA 与 Cloudflare JS Detection 的完整生产兼容仍应继续观察。
- 暂时缓解：当前分析配置为空时维持关闭，并增加配置变更验收。
- 误报边界：已列出的脚本是官方来源，不等同于恶意脚本；风险来自不必要的高权限加载范围。

### SEC-USDT-005 — 域名邮件防伪与 DNS 完整性未闭环

- 严重性：**Medium**
- 位置/证据：公开 DNS 无 DMARC、DS、DNSKEY、CAA；已有 SPF/DKIM/SES 子域记录。
- 影响：攻击者更容易伪造品牌邮件，收件方缺少明确处置策略；DNSSEC 缺失使 DNS 数据缺少链式完整性验证。
- 修复：先盘点真实发件源和对齐，再从 `p=none` 监控逐步到 `quarantine/reject`；单独按注册商流程启用 DNSSEC 并验证 DS 链。
- 暂时缓解：只从固定官方渠道发送资金相关通知；客服明确不索要助记词、私钥、钱包签名或授权。
- 误报边界：DMARC/DNSSEC 不能阻止相似域名、账号接管或链上转账骗局；它们只覆盖特定信任边界。

### SEC-USDT-006 — 缺少公开安全举报入口与统一处置链

- 严重性：**Medium**
- 位置/证据：`/.well-known/security.txt` 为 404，当前文档未定义可验证的公开安全邮箱和仿冒工单字段。
- 影响：客户、研究者或合作方难以及时提交仿冒域名、假客服、恶意地址证据；处置记录容易散落，无法复盘 SLA 和下架效果。
- 修复：先建立有人值守的 `security@`/`abuse@` 收件入口，再发布 RFC 9116 `security.txt`，并执行统一取证、分流、举报、客户通知和结案流程。
- 暂时缓解：客服沿用现有可信入口受理，但不得宣称尚不存在的 24/7 响应能力。
- 误报边界：发布 `security.txt` 不等于授权渗透测试，也不保证第三方平台下架时效。

## 3. 默认关闭的地址变更状态机

### 3.1 安全目标与功能闸

新增独立闸：

- `nezha_payment_address_credential_status=0`：地址凭据签发/消费；
- `nezha_payment_address_change_status=0`：地址变更状态机与旧入口封堵。

两个闸都必须默认关闭。代码部署、迁移完成也不得自动开启。开启顺序必须是：

1. 第二管理员与 TOTP 就绪；
2. 凭据读写全链路通过并先开凭据闸；
3. 观察凭据签发、消费、过期与订单回显；
4. 再开地址变更闸，同时封堵旧入口直写；
5. 任一步异常先关新请求或暂停单网络，不通过写回旧地址“修复”。

### 3.2 数据对象

#### `nezha_payment_address_credentials`

沿用候选能力，并在当前 main 重新实现：

- 绑定顾客、商家、网络、支付方式、订单；
- 加密保存地址快照，明文不进入普通日志；
- 公开 ID 与一次性 secret 分离，secret 只存哈希；
- 状态至少为 `issued|consumed|expired|revoked`；
- 保留已消费记录作为订单证据，不因地址切换删除。

用户 2026-07-14 已批准凭据复用与分层留存；production 已于 2026-07-15 运行该 migration，但两个闸仍关闭、凭据表仍为 0 行。真实日签发量尚未产生，因此容量门槛仍未完成：

- 未消费且已过期/已撤销的凭据：从过期/撤销时刻起保存 30 天，之后清除加密地址快照和 secret 哈希，只留绑定、地址指纹、状态与时间等不含地址的审计；
- 已消费凭据：随其绑定订单/财务证据的正式留存周期保留，不能先于订单证据删除；
- 顾客端在 `sessionStorage` 暂存当前标签页的凭据 token，并在刷新/重试时交回后端；后端只有在 user+商家+网络+支付方式、secret、当前地址指纹均匹配且凭据尚未过期/消费/撤销/脱敏时才复用原行，绝不延长原到期时间。后端不保存或恢复明文 secret；
- 30 天脱敏任务每日执行、逐行加锁复核，消费与脱敏竞争时以已消费订单证据优先；任务不删除整行，也不处理已消费记录；
- 上线前必须用真实日签发量和 MySQL `data_length/index_length` 复算容量，不能用未经实测的行大小估算替代容量验收。

#### `nezha_payment_network_states`

每个 `restaurant_id + network` 一行：

- `state`: 正常新流程使用 `active|paused`；`draining` 只为升级前遗留记录兼容收尾，不再由新审批产生；
- 当前地址版本与指纹；
- `pending_change_id`；
- 乐观锁版本、时间戳；
- 唯一约束：同商家同网络只能有一个当前状态。

#### `nezha_payment_address_changes`

- 商家、网络、加密旧/新地址、不可逆指纹；
- 状态与期望旧版本；
- 请求管理员、商家确认人、复核管理员；
- 各交易级 TOTP 验证时间，只存验证结果和时间，绝不存 TOTP；
- `drain_until`、过期时间、申请原因、应急标记；
- 应用、拒绝、取消、失败的时间和机器可读原因；
- 幂等键和版本字段。

#### `nezha_payment_address_change_events`

只追加、不更新历史事件：

- 变更 ID、前后状态、动作、actor 类型/ID、发生时间；
- 有界并加密/哈希的来源上下文；
- 通知发送结果；
- 不记录地址明文、TOTP、会话 cookie、token、私钥或助记词。

### 3.3 正常流程

```text
active(A)
  -> requested
  -> pending_merchant
  -> pending_distinct_admin
  -> applying
  -> applied(B)
  -> active(B)
```

分支终态：`rejected|canceled|expired|failed`。

1. **管理员申请**
   - 必须重新输入当前管理员的交易级 TOTP；“本次已登录并通过过 TOTP”不能复用。
   - 严格按网络校验并规范化地址；新旧地址不得相同。
   - 锁定网络状态并绑定“期望旧地址指纹/版本”，防止并发申请覆盖。
   - 同商家同网络只允许一个未终结变更。

2. **商家确认**
   - 只允许商家 owner，不允许普通员工。
   - 页面显示网络、新地址完整值与分段指纹；商家只能确认或拒绝，不能在确认页编辑。
   - 任何字段变化都使此前确认失效并回到申请阶段。

3. **不同管理员复核**
   - `approved_by != requested_by`，复核管理员必须已启用 TOTP，并再次做交易级 TOTP。
   - 复核看到旧/新地址、网络、商家确认时间、请求来源和通知结果。
   - 用户 2026-07-14 最新裁决：复核管理员必须能明确“批准”或“驳回”，不能只靠不批准、等待超时或让管理管理员取消来处理可疑申请；批准与驳回都必须经过交易级 TOTP，并分别记录真实 actor、时间、状态和通知结果。
   - 驳回只允许发生在 `pending_distinct_admin`，地址保持不变，释放该商家+网络的 pending 占用；它是人工审核结论，不能与系统超时产生的 `expired` 混写。用户 2026-07-14 最新裁决：驳回原因选填，留空也可提交；非空原因作为加密事件 context 留痕。
   - 复核员人工驳回后，系统对商家 owner 做 best-effort 站内信、Telegram、邮件与 push 通知；只发送实际可用渠道，并逐渠道记录 `ok|failed|skipped|no_recipient`，不得把“已尝试”表述为“已送达”。通知不包含完整地址或驳回原因。
   - 申请超时对用户统一显示并通知为“已驳回（超时）”，但底层继续使用 `state=expired`、`actor_type=system` 和 `rejection_code=approval_timeout`；不得伪造成某个复核员作出的人工驳回。
   - 当前只有 1 个管理员，因此在第二管理员建立前必须硬失败，不能提供“紧急自批地址替换”。

4. **立即切换与旧凭据自然到期（用户已批准）**
   - 不再为普通换址进入 `draining`，也不暂停该商家该网络的新付款。
   - 不同管理员复核通过后，在同一数据库事务内把当前地址切换为 B；事务提交后的新凭据立即固化 B。
   - 已签发 A 凭据继续按各自地址快照有效到 `expires_at`，不被普通换址撤销，也不会延长。默认有效期来自未付款订单超时，当前约 10 分钟；代码硬限制 1–120 分钟。
   - 顾客刷新页面并带回 A token 时，后端发现其地址指纹不再是当前 B，不复用 A，而是签发 B；原 A token 对已经在途的付款仍有效到原到期时间。
   - 只有怀疑 A 被盗、私钥失陷或换址异常时才走紧急暂停，撤销 A 的未消费凭据并阻止继续签发；随后仍须按商家 owner + 不同管理员流程批准安全地址。
   - `draining/applyReadyChange` 仅兼容未来部署时可能存在的升级前遗留状态；新审批不再产生它。

5. **原子应用**
   - 数据库事务和行锁内重新核对状态、期望旧指纹、地址校验和网络版本；不要求旧凭据排空。
   - 同一事务写入商家地址、状态版本、变更终态与审计事件。
   - 成功后才恢复该网络为 `active(B)` 并允许签发新凭据。
   - 失败时旧地址 A 不变；网络保持 `paused` 等人工复核，不能半写或自动反向覆盖。

### 3.3.1 管理员最小权限与 reviewer 2FA 门

- `payment_address_manage` 只拥有地址变更申请、取消和指定商家+网络的紧急暂停；紧急暂停不授予 reviewer。
- `payment_address_review` 是独占角色模块，不能与 `restaurant`、`payment_address_manage` 或其它模块组合；其数据面只返回 `pending_distinct_admin` 队列、单条待复核详情、批准和驳回动作。
- reviewer 即使因历史脏数据被误配其它模块，仍由全局 scope 限制：未完成 2FA 注册时只允许 setup、enable、语言切换、仅修改本人密码的设置页和退出；完成注册且当前会话通过第二因子后，只允许 pending/show/approve/reject、语言切换、仅修改本人密码的设置页、2FA 状态/恢复码页和退出，不能关闭 2FA。
- 餐厅 CRUD、旧 `updatePaymentInfo` 地址直改、押金充值、汇率、折扣、申请、取消和暂停均不属于 reviewer；超管仍由既有 `role_id=1` 旁路处理，但地址批准继续受“请求人不得自批”和交易级 TOTP 约束。
- reviewer 正式 UI 以 2026-07-14 业主确认的 V3 规格与 18 张基准图为准：复用生产后台真实顶栏、侧栏和工作台 token，独占 reviewer 侧栏只保留“钱·风控 / 收款地址复核”，隐藏已拍板的首页/洞察与页头四入口；队列只手动刷新或动作后刷新，不轮询，不回显驳回通知渠道结果。页面的 2FA 状态 pill 读取当前管理员真值；未启用时显示琥珀告警、沿用控制器原句说明原因，并禁用交易级动态码与批准/驳回。业主随后批准壳层闭环：reviewer logo 与登录 2FA challenge 成功落到复核队列，账户菜单只保留修改密码/退出，设置页只显示修改密码，页脚 Business setup/Profile/Dashboard 与已启用后的关闭 2FA 入口隐藏；首次绑定、恢复码保存、语言切换、修改密码和退出仍保留。V3 已接入 production release；Fable 唯一阻断证据（移动 401 错误态取景）补拍后按其“补上即转 GO”结论通过，业主于 2026-07-15 终验回复 GO，V3 UI 正式定稿。代码、migration 和有效网络初始化已发布，但真实 reviewer 尚未创建、TOTP 尚未绑定、后台 V3 页尚未做 production 登录终验，两个闸仍关闭。

### 3.4 应急流程

“暂停收款”和“改成新地址”必须分开：

- 单个启用 TOTP 的管理员可用交易级 TOTP **立即暂停一个商家的一个 USDT 网络**，并撤销该网络尚未消费的凭据；这是止损动作，不改变资金去向。
- 已消费凭据和订单证据保留；顾客已按旧凭据转账的订单进入人工核验，不能自动判定无效。
- 从暂停状态换成新地址仍必须经过商家确认和不同管理员复核。
- 恢复原地址也必须核对当前指纹、未决订单和事件记录，不能把“取消申请”等同于自动恢复收款。

### 3.5 不可绕过约束

当 `nezha_payment_address_change_status=1` 时：

1. 旧 `updatePaymentInfo` 不得再直接覆盖任何 USDT 地址字段；只能创建变更申请或返回明确拒绝；
2. 顾客付款页和订单回显只能使用有效凭据中的地址快照，不得在错误时静默回退到 `restaurants` 当前地址；
3. 关闭某一网络时，客户付款页直接隐藏该网络；两个 USDT 网络都不可用时隐藏 USDT 支付方式，但仍显示真实可用的其它付款方式；
4. 所有写动作必须有幂等键、行锁/版本检查、CSRF/认证、角色校验、速率限制和追加式事件；
5. 商家端只读“双二维码”页面展示配置结果，不拥有地址写权限。

### 3.6 测试方案

任何迁移或翻闸授权前，至少完成：

#### 单元/契约

- TRC20/BEP20 合法、非法、混链、空白、相同地址校验；
- TOTP 错误、过期、重放、限频，且日志无验证码；
- 请求人与复核人相同必失败；无第二管理员必失败；员工确认必失败；
- 状态转移白名单，越级转移和重复调用保持幂等；
- 两个并发申请只能成功一个；旧版本变化后应用必失败；
- 加密列、指纹、secret 哈希和日志脱敏契约。

#### 集成

- A 地址签发凭据 -> 复核通过后 B 立即供新签发使用 -> 已签发 A 仍解析为 A 直到自身到期且不延长；
- 同一顾客+商家+网络+支付方式带回有效 token 时复用原凭据行；旧地址、过期、已消费、已撤销、已脱敏或绑定不匹配时必须签发新行；
- 未消费过期/撤销满 30 天才脱敏，已消费记录与地址指纹/审计字段不被清理；
- 申请/确认/复核/应用任一步失败时，数据库无半写；
- 开闸后旧管理接口不能直写；关闸时不改变当前生产行为；
- 紧急暂停只影响指定商家+网络，不影响支付宝、另一 USDT 网络或其它商家；
- 顾客付款页没有 live-address fallback；订单详情保持订单地址证据；
- 通知失败被记录并告警，但不能伪造成审批成功或回滚资金状态。

#### 真实浏览器/运行验证

- 管理员申请、商家 owner 确认、不同管理员复核、拒绝、过期、并发冲突、应急暂停；
- 顾客桌面与目标移动视口：网络可用、单网络隐藏、双网络隐藏、非 USDT 方式仍可用；
- 退出、刷新、重新登录后状态一致；console error 为 0；无敏感值进入 URL、HTML 注释、LocalStorage 或日志。

### 3.7 实施、发布与回滚

建议拆成可审查的小提交，不把历史候选直接 rebase 后上线：

1. 当前 main 上移植地址值对象和凭据服务/测试，闸保持 0；
2. 增加状态表、变更表、事件表与模型/测试，不运行迁移；
3. 增加申请/确认/复核/暂停 API 和服务层；
4. 封堵旧直写入口，并以功能闸保持现行行为；
5. 增加经确认的 UI，完成真机验收；
6. 提交迁移演练、影子读验证、生产影响与最终翻闸申请。

发布影响：增加表和索引；开启后改变管理员地址修改流程及指定网络的新凭据签发。

发布前置：第二管理员+TOTP、数据库备份、迁移耗时/锁评估、全套测试、监控与告警、逐闸授权。

代码回滚：先关地址变更闸和凭据闸；不得删除已签发/已消费凭据或审计事件。

迁移回滚：首版只新增表/列；上线观察期内不 drop，应用回退后保留数据，待独立授权再清理。

地址回滚：不能通过“自动写回旧地址”代替事故处理；先暂停网络，按新的受控变更重新审批。

### 3.8 2026-07-14 隔离实现状态

后端治理基线已推进至 `039acca5ad66765e8cac72f30d4c5001b2d5c168`；本批先在 `codex/usdt-rbac-2fa-20260714-1340` 完成 reviewer 最小权限、强制 2FA、人工驳回与 V2 原型，再在后端隔离分支 `codex/usdt-immediate-switch-retention-20260714` 完成 B 对新付款立即生效、A 凭据自然到期与 30 天脱敏；前端隔离分支 `codex/usdt-credential-reuse-20260714` 完成同一标签页有效凭据复用。业主确认 V3 后，正式 reviewer Blade、pending HTML 分支、同源徽标与独占 reviewer 壳层收敛已接入 `codex/usdt-production-backport-20260714-1830` 候选。MySQL 5.7 临时库已验证新增留存 migration 的 up、重复 up、down、回滚保护和 re-up，临时库已删除且生产库聚合前后不变。全部功能闸仍默认关闭：

- 地址凭据、严格 TRC20/BEP20 校验、网络状态、变更申请和追加式事件；
- 交易级 TOTP 返回时间步并以数据库唯一约束防重放，不保存验证码；
- 商家 owner 确认、不同管理员复核、B 对新付款立即原子生效、已签发 A 凭据自然到期；
- 地址漂移/版本漂移时不覆盖当前值并暂停网络；
- 紧急暂停只撤销目标商家+网络的未消费凭据，已消费证据和其它网络不动；
- 旧 `updatePaymentInfo` 在状态机开启时既拒绝变更请求，也不再重新写入任何 USDT 字段；
- 初始化命令默认 dry-run，维护命令在闸关闭时只返回 `disabled`；普通 migration rollback 拒绝删除非空凭据/状态/审计表。
- 管理员采用 A 主页面完成发起、紧急暂停与当前状态查看，采用 C 复核抽屉展示完整地址、指纹、请求人和商家确认；请求人仍不能自批。
- reviewer V3 独立入口沿用 production 基线后台壳层；列表仅显示短指纹，详情按需显示完整新旧地址。批准和驳回均要求当前 reviewer 的交易级 TOTP，驳回原因选填；请求人不能自批或自驳，紧急暂停仍只归 `payment_address_manage`。2FA 状态展示读取当前管理员真值，未启用时明确告警且动作禁用。业主已补充批准 reviewer 壳层闭环：logo 与登录 2FA challenge 成功落到复核队列，账户菜单只保留修改密码/退出，页脚死链接和关闭 2FA 入口隐藏，同时保留首次绑定、恢复码、语言切换和密码修改。18 张隔离 HTTP 浏览器状态图已按最终壳层重拍，并另产出壳层专项图；Fable 缺图项已补并按既定口径转 GO，业主于 2026-07-15 终验 GO，UI 已定稿。
- 商家「支付信息」只读页增加本人确认/拒绝入口及状态时间线；安全通知只携带商家、网络、状态和短指纹，不在通知正文放完整新地址，并记录真实写入结果。
- reviewer 人工驳回会对商家 owner 尝试站内信、Telegram、邮件和 push，并逐渠道记录真实 `ok|failed|skipped|no_recipient`；申请超时对商家显示“已驳回（超时）”，但底层保留 system actor、`expired` 和 `approval_timeout`。
- 顾客选择 USDT 时先签发绑定顾客/商家/方式的地址版本凭据；只有后端明确返回“功能未启用”才保持旧路径，其余签发失败、过期或未登录均不展示当前地址并阻止提交。
- 顾客刷新/重试会带回同一标签页尚有效的凭据 token 供后端严格复用；不延长到期时间。未消费凭据过期/撤销满 30 天后每日脱敏，已消费订单证据不受影响。
- 订单详情只读取下单时固化的 `address_credential` 白名单快照；历史订单缺少该证据时明确中止并提示联系客服，绝不读取商家当前地址补位。

仍未完成、因此绝不能翻闸：

- 生产第二独立管理员及 TOTP；
- 生产邮件/Telegram/push 等通知渠道的真实配置、送达验证与失败告警；隔离代码已有 best-effort 调用及逐渠道结果审计，但尚未在生产验证，不能表述为已送达；
- 网络暂停时，顾客端支付方式列表层的主动隐藏（当前签发失败会安全阻止展示地址与提交）；
- 生产迁移、生产状态初始化对拍、部署和逐闸观察。

## 4. CSP 收敛方案

### 4.1 目标

优先保护结算、USDT 付款、订单付款凭据展示路由；不因全站一次性改造导致登录、地图或订单功能中断。

### 4.2 分阶段执行

1. **唯一 owner 与基线**
   - 确认版本化 `ops/nginx/nezha.am/security_headers.conf` 为正本，并明确宝塔 proxy 生成文件的同步机制；
   - 增加“版本化配置 == `nginx -T` 实际头”的漂移检查；
   - 保存当前头、nginx 配置和发布包作为回滚证据。

2. **安全的 Report-Only 接收**
   - 新增同源或明确允许的 CSP 报告端点；仅接收 CSP JSON，body 上限建议 16 KiB，单 IP/全局限速；
   - 剥离 query、fragment、cookie 和可疑脚本样本，只保留 directive、源站、路径级 blocked URI、页面路由、浏览器时间；
   - 设短期保留和去重，避免报告端点变成 PII/日志注入入口；
   - 先加 `Content-Security-Policy-Report-Only`，不替换现有强制头。

3. **减少付款页第三方**
   - Google GSI、Apple 登录只在登录页或实际登录动作懒加载；
   - 结算、付款、凭据展示路由排除 GTM/GA/Facebook/LinkedIn/TikTok/Snapchat/X/Pinterest；
   - 地图只在真正显示地图的路由加载；
   - 对每个保留来源记录业务 owner 和移除条件。

4. **消除内联与 eval 放行**
   - 先去掉自有内联脚本或改为受控外部模块；
   - 对 Next.js 必需脚本使用每请求 nonce（或稳定资源 hash），nonce 必须随机、不可预测、只在请求内使用；
   - 评估 nonce 导致的动态渲染与缓存影响；
   - 付款路由先从 Report-Only 去掉 `'unsafe-eval'`，清零真实违规后再强制。

5. **付款路由强制策略**
   - 目标至少包含 `default-src 'self'`、`object-src 'none'`、`base-uri 'self'`、`form-action 'self'`、`frame-ancestors 'none'`；
   - `script-src/connect-src/img-src/style-src/font-src` 只保留该路由实测所需；
   - 不使用 `img-src https:` 或“先把所有第三方加进去”维持表面兼容。

### 4.3 验证与回滚

- 自动化：解析实际响应头；断言付款路由无分析/社交脚本、无 `unsafe-eval`、存在 `frame-ancestors 'none'`；报告端点限流/截断/脱敏测试。
- 浏览器：登录、地图、首页、结算、付款、订单详情；桌面+目标移动视口；console、网络请求、CSP 报告和支付方式状态。
- 观察：Report-Only 至少覆盖真实业务高峰和主要浏览器，再进入强制。
- 回滚：只回退到上一版已验证的强制 CSP；保留 Report-Only 和报告数据。若付款页故障，回滚对应路由策略，不把全站永久放宽。

参考：

- [Next.js Pages Router CSP 指南](https://nextjs.org/docs/13/pages/building-your-application/configuring/content-security-policy)
- [Next.js headers 配置](https://nextjs.org/docs/pages/api-reference/config/next-config-js/headers)

## 5. DMARC、DNSSEC 与 CAA 方案

### 5.1 DMARC

1. 盘点所有合法发件源：Laravel/Resend、SES 退信域、客服或营销系统；逐一发送到外部测试邮箱并保存完整邮件头。
2. 对每个源核对 `header.from`、DKIM `d=`、SPF MAIL FROM 及 DMARC alignment；未知源不得为了通过而扩大 SPF。
3. 建立并人工验证 `dmarc-reports@nezha.am` 或受控分析器收件地址，明确值守人、访问权限与报告保留期。
4. 经 DNS 变更授权后，首阶段只发布监控策略，例如：

   ```text
   v=DMARC1; p=none; rua=mailto:dmarc-reports@nezha.am; adkim=r; aspf=r; pct=100
   ```

5. 观察多个完整业务周期，确保合法源全部对齐，再分阶段 `quarantine`（可从较小 `pct` 开始），最后才考虑 `reject`。
6. 每阶段保留 DNS 旧值、TTL、发布时间、聚合报告和测试邮件头；任何合法邮件失败立即回到上一策略并修正源，不以永久 `p=none` 结案。

DMARC 影响：错误策略会导致合法邮件进垃圾箱或拒收。

回滚：恢复上一条已验证 TXT；DNS 传播期间同时修复实际发件源并监控聚合报告。

参考：[Resend DMARC 文档](https://resend.com/docs/dashboard/domains/dmarc)、[Resend 域名验证](https://resend.com/docs/dashboard/domains/introduction)。

### 5.2 DNSSEC

1. 确认权威 DNS、注册商、当前 nameserver 和操作 owner；保存 zone 导出。
2. 在 DNS 提供方生成 DNSSEC 参数，核对算法、key tag、digest、digest type；在注册商提交 DS。
3. 从多个公共解析器验证 DS -> DNSKEY -> 签名链，检查 apex、API、邮件相关记录和续签/轮换说明。
4. 设监控并记录 DS TTL；不能只看控制台“已启用”。

DNSSEC 影响：DS 与签名不匹配会导致整个域名在验证解析器上不可达。

正确回滚顺序：**先在注册商删除 DS，等待 TTL 并验证公网已无 DS，再关闭 DNS 提供方签名**。绝不能在 DS 仍存在时先停签。

参考：[Cloudflare DNSSEC 指南](https://developers.cloudflare.com/registrar/get-started/enable-dnssec/)。

### 5.3 CAA（后续项）

当前无 CAA。添加前必须先确认 Cloudflare Universal SSL/实际证书链允许的签发机构及备用签发路径；否则可能阻断续签。CAA 与 DMARC/DNSSEC 分开审批、分开回滚，不捆绑一次变更。

## 6. 仿冒与安全举报方案

### 6.1 入口上线顺序

1. 建立 `security@nezha.am` 或 `abuse@nezha.am`，通过 Cloudflare Email Routing 或现有邮件系统路由到明确值守人；完成收发、垃圾箱和离岗接力测试。
2. 定义响应时间，只承诺能真实执行的时限。
3. 再发布 `https://nezha.am/.well-known/security.txt`，Content-Type 为 `text/plain; charset=utf-8`，至少包含：

   ```text
   Contact: mailto:security@nezha.am
   Expires: <部署时生成，少于一年>
   Canonical: https://nezha.am/.well-known/security.txt
   Preferred-Languages: zh, en
   ```

4. 如后续发布漏洞披露政策，再加入 `Policy:`；不得用 `security.txt` 暗示未授权测试许可。

参考：[RFC 9116](https://www.rfc-editor.org/rfc/rfc9116.html)。

### 6.2 举报最小字段

- 完整 URL、首次发现时间和时区、入口来源；
- 页面截图、跳转链、浏览器/设备基础信息；
- 冒充邮件附完整邮件头或 `.eml`，不要只转发截图；
- 涉及链上资金时提供网络、地址、交易哈希、时间和金额；
- 明确提示：**哪吒不会索要助记词、私钥、钱包连接、钱包签名或 USDT 授权**。

证据进入只读案件目录或工单，计算文件哈希，限制 PII 访问和保留期；不得把敏感材料复制进 Git、普通聊天或公开截图。

### 6.3 分流与动作

1. **疑似官方站被入侵**：立即按内部安全事故处理；先保护顾客、暂停受影响付款网络、保全日志，再判断是否切换发布包。
2. **相似域名/克隆站**：保存 DNS/RDAP/证书/页面/重定向证据，同时向注册商、托管/CDN、Google Safe Browsing、Microsoft SmartScreen 举报。
3. **假客服/社交账号**：保存账号 URL、平台 ID、聊天导出和收款地址，向平台及账号托管方举报，并通过官方渠道发布有针对性的澄清。
4. **恶意 TRON/BSC 地址**：向对应区块浏览器/钱包风险渠道提交地址、交易哈希和诈骗证据；平台标记不能追回资金，也不能替代执法/法律咨询。
5. 每个案件记录 ticket ID、提交时间、补件、状态、下架时间、客户影响和复盘结论。

对外通知只走已验证的官网/应用/官方账号；不得在不确定时公开指认个人。涉及实际损失、跨境数据、商标或执法材料时交由法务/合规判断。

### 6.4 监控与衡量

- 证书透明度、相似域名/RDAP/DNS 变化、品牌关键词与页面指纹监控；
- 每周统计首次响应、提交举报、下架、重复域名和客户损失线索；
- 监控只能产生真实事件，不在产品里伪造红点、数量或“已拦截”状态。

参考：

- [Google 安全/垃圾内容举报入口](https://developers.google.com/search/help/report-quality-issues)
- [Cloudflare Abuse 举报](https://developers.cloudflare.com/fundamentals/reference/report-abuse/submit-report/)
- [TRONSCAN 诈骗举报](https://support.tronscan.org/hc/en-us/articles/21841611138585-How-to-report-a-scam)

## 7. 需要业主明确裁决的停点

以下资金规则已于 2026-07-14 获业主明确批准；外部状态仍按逐项授权边界执行：

1. **已批准**：正常地址变更必须“商家 owner 确认 + 不同管理员复核”，不允许请求人自批；先建立第二管理员并启用 TOTP。
2. **已批准**：普通换址不暂停新付款；复核通过后 B 立即用于新凭据，已签发 A 按自身原到期时间继续有效且不延长。只有疑似失陷/异常才紧急暂停并撤销 A 的未消费凭据。
3. **已批准**：单个管理员经交易级 TOTP 可紧急暂停一个商家的一个网络；改成新地址仍须双角色确认。
4. **逻辑与 V3 UI 已批准并定稿**：reviewer 可批准或驳回，二者都需交易级 TOTP；驳回原因选填；超时对外显示“已驳回（超时）”，底层保留 system/expired 审计语义。V3 规格、18 张基准图及 reviewer 壳层闭环均已由业主确认，正式 UI 已接入 production backport 候选并按最终壳层重拍 18 张实装状态图；Fable 对版补证完成，业主于 2026-07-15 终验 GO。UI 验收完成，但未获部署或其它生产动作授权。
5. **部分已批准**：有效 A 凭据自然到期；未消费过期/撤销凭据 30 天后脱敏，已消费凭据随订单/财务证据周期保存。顾客在紧急撤销前后已向旧地址转账时的人工核验、补偿和责任政策仍待单独裁决。
6. **生产授权现状**：加密生产备份已授权并完成；本轮只授权准备 production backport 候选与隔离验收证据。migration、部署/current、第二生产管理员、真实商户/网络初始化和两个功能闸均须分别取得行动时授权；reviewer 真实身份、2FA/recovery custodian、Basic 输入与全量地址状态覆盖仍是 NO-GO 条件。nginx/Cloudflare/DNS/邮件、DMARC/CAA/DNSSEC、举报邮箱、`security.txt` 与真实外部举报未授权，不得外推。
7. **发布口径已批准**：以当前 production 为基线精确 backport 本批，不把最新 `main` 的其它待发布改动整包上线。该批准允许准备隔离 backport 候选；Fable 对版与业主 UI 终验现已完成，但 reviewer 真实身份/2FA/Basic 输入、真实商户/网络初始化、migration、部署/current 和两个功能闸仍各自需要独立授权，不因 UI GO 或发布口径选择而自动放行。

## 8. 实施优先级

| 优先级 | 工作 | 原因 |
|---|---|---|
| DONE | Fable 对照 V3 基准复核 18 张实装状态图，再由业主终验 | 缺图补证后 Fable 按既定口径转 GO，业主 2026-07-15 终验 GO；这里只解锁 UI 定稿，不解锁生产动作 |
| P0 | 建立第二独立管理员+TOTP；权限与站内通知已隔离实现 | 当前只有 1 个管理员，安全流程仍无法正确闭环；还缺真实身份、2FA/recovery custodian 与 Basic Auth 输入 |
| P0 | 后端状态机与顾客地址版本凭据已完成隔离接入及 MySQL 5.7 临时库演练，按现行 production 准备精确 backport | UI 已定稿；身份输入和全量状态覆盖仍未闭合，migration、部署/current、管理员、初始化与两闸仍分别待独立授权，生产仍 NO-GO |
| P1 | 付款路由 CSP Report-Only、第三方脚本路由化、漂移检测 | 先获得真实兼容证据，再收紧强制策略 |
| P1 | 发件源盘点与 DMARC `p=none` 方案 | 仿冒邮件风险高，但错误强制会伤害合法邮件 |
| P1 | 验证举报邮箱并发布 `security.txt`、落地处置 SOP | 低改动成本，提升外部线索进入和可追踪性 |
| P2 | DNSSEC、CAA | 必须有注册商/DNS owner 和回滚演练，避免域名整体不可达 |
