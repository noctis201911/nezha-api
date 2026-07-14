# 哪吒 USDT 收款地址与仿冒风险治理方案

状态：**资金地址规则与 A＋C 正式 UI 已获业主批准；默认关闭的后端状态机、内部通知、管理员/商家正式 UI 及顾客地址版本凭据均已在隔离分支实现，仍未部署、未迁移、未翻闸，未修改 DNS/nginx/生产配置**

核对日期：2026-07-14（Europe/Berlin）

适用仓库：后端 `/www/wwwroot/api.nezha.am`、前端 `/www/wwwroot/nezha.am`

## 0. 完成定义与边界

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
- 候选 `936be838` 已提供默认关闭的支付地址凭据：随机公开标识、秘密哈希、加密地址快照、网络/商家/用户/订单绑定、过期与单次消费。它解决“订单应使用哪个版本地址”的证据问题，但没有地址变更审批、网络暂停/排空、旧写入口封堵或追加式审计。
- 生产数据库只存在 1 个管理员且已启用 TOTP。正常地址变更若要求“不同管理员复核”，当前组织条件下将无法完成；正确处理是先建立第二个独立管理员并启用 TOTP，不能退化为自批。

### 1.3 当前 CSP、邮件与公开报告入口

- 顾客站当前强制 CSP 仍允许 `script-src 'unsafe-inline' 'unsafe-eval'`，并全站允许 Google、Apple、多个广告/分析来源；`img-src` 允许任意 HTTPS；没有 `frame-ancestors`。
- `pages/_document.js` 全站加载 Google GSI、Apple 登录脚本，并保留多家分析脚本分支；付款页没有按路由收敛。
- CSP 在宝塔生成的 proxy 配置与 extension 文件中重复定义，实际生效值可能被 proxy 文件覆盖；未先确定唯一 owner 就直接改一份配置会产生“以为已收紧、实际未生效”的漂移。
- `_dmarc.nezha.am` 无 TXT；`nezha.am` 无 DS、DNSKEY、CAA。
- 已有 SPF，且 `resend._domainkey.nezha.am` 存在 DKIM 公钥；`send.nezha.am` 有 Amazon SES SPF/MX。DMARC 上线前仍须用真实邮件头证明 From、DKIM、SPF 的域对齐，不能仅凭 DNS 记录推断全部合法发件源已覆盖。
- 邮件不是单一传输链：通用 Laravel 邮件当前走 Resend；站内客服工单会优先采用数据库最新一条 `mail_configs`，2026-07-14 只读核对到的现行记录为 Gmail SMTP 且 From 域为 `gmail.com`。运维指南所述 Gmail `support@nezha.am` Send mail as 尚未用真实外发邮件头复核；DMARC 盘点必须分别覆盖这三条路径。
- `https://nezha.am/.well-known/security.txt` 当前返回 404，尚无公开且已验证可达的安全举报入口。

## 2. 风险发现

### SEC-USDT-001 — 管理员可直接覆盖正在收款的地址

- 严重性：**High**
- 位置/证据：`app/Http/Controllers/Admin/VendorController.php::updatePaymentInfo`；`routes/admin.php` 的 `update-payment-info/{restaurant}`。
- 影响：管理员会话、后台操作链或浏览器被劫持后，新地址可立即成为顾客看到的收款地址；通知失败不回滚写入。地址输错也会产生同类资金损失。
- 修复：默认关闭的地址变更状态机、交易级 TOTP、商家确认、不同管理员复核、凭据排空、原子切换、追加式审计，并封死旧入口直写旁路。
- 暂时缓解：保持地址变更人数最小化；任何异常先暂停对应网络而不是写新地址；人工双渠道核对当前地址指纹。
- 误报边界：现有 Basic Auth、Laravel 登录和登录 TOTP 降低了利用概率，但不能代替交易级复核，也不能消除误操作。

### SEC-USDT-002 — 地址凭据候选不能单独构成地址变更防线

- 严重性：**High**
- 位置/证据：候选分支 `936be838` 只新增凭据服务/API/迁移/订单接入；未新增地址变更实体、网络状态或旧写入口封堵。
- 影响：即使凭据功能开启，攻击者仍可从旧管理员入口改地址；尚未签发凭据的新订单会读取被替换后的地址。候选还落后当前 main，原样发布可能覆盖后续租户边界与面板修复。
- 修复：按当前 main 重新移植凭据能力，并把它作为状态机的排空依据；禁止直接部署历史候选。
- 暂时缓解：继续保持候选未部署、迁移未运行、功能闸关闭。
- 误报边界：候选中的地址快照与绑定设计本身有价值；问题是它的覆盖边界不足，不是说其代码等同于漏洞。

### SEC-USDT-003 — 付款路由的 CSP 防御面过宽

- 严重性：**Medium**
- 位置/证据：生产 `Content-Security-Policy`；nginx 的 `extension/nezha.am/security_headers.conf` 与宝塔 proxy 生成配置。
- 影响：若页面另有注入点，`unsafe-inline`、`unsafe-eval` 和过宽第三方来源会扩大脚本执行与数据外传空间；付款地址属于高价值 DOM 内容。
- 修复：先 Report-Only，再按付款路由移除未使用第三方、消除 `unsafe-eval`、以 nonce/hash 替代内联放行、加入 `frame-ancestors 'none'`，最终强制执行。
- 暂时缓解：保留 `object-src 'none'`、`base-uri 'self'`、`form-action 'self'`，监控前端依赖和第三方配置变化。
- 误报边界：宽 CSP 不是“已经发生 XSS”的证据；它是关键页面防御纵深不足。

### SEC-USDT-004 — 登录和分析脚本全站注入付款页面

- 严重性：**Medium**
- 位置/证据：前端 `pages/_document.js` 中 Google GSI、Apple 登录及多家分析脚本分支。
- 影响：付款页面承担不必要的第三方供应链、隐私和 CSP 放行成本；未来启用分析配置时，付款路由会随之扩权。
- 修复：社交登录脚本只在实际登录动作或登录页懒加载；分析脚本明确排除结算、付款、订单凭据展示路由。
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

#### `nezha_payment_network_states`

每个 `restaurant_id + network` 一行：

- `state`: `active|draining|paused`；
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
  -> draining
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
   - 当前只有 1 个管理员，因此在第二管理员建立前必须硬失败，不能提供“紧急自批地址替换”。

4. **排空**
   - 对该商家的该网络进入 `draining`：只停止签发新凭据；商家营业、支付宝及其它网络不受影响。
   - 已签发且未过期的凭据继续按其地址快照完成。
   - `drain_until = max(该网络未过期凭据的 expires_at)`；可配置极小安全余量，默认 0。禁止随意增加 24 小时等没有订单依据的冷静期。

5. **原子应用**
   - 数据库事务和行锁内重新核对状态、期望旧指纹、地址校验、凭据是否已排空。
   - 同一事务写入商家地址、状态版本、变更终态与审计事件。
   - 成功后才恢复该网络为 `active(B)` 并允许签发新凭据。
   - 失败时旧地址 A 不变；网络保持 `paused` 等人工复核，不能半写或自动反向覆盖。

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

- A 地址签发凭据 -> 发起 B -> 排空期间旧凭据仍解析为 A -> 排空后新凭据只解析为 B；
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

后端治理基线已推进至 `039acca5ad66765e8cac72f30d4c5001b2d5c168`；本批在隔离分支 `codex/usdt-ui-ac-20260714` 完成 A＋C 正式 UI/内部通知，在前端隔离分支 `codex/usdt-customer-credential-ui-20260714` 完成顾客地址版本凭据接入。全部功能闸仍默认关闭：

- 地址凭据、严格 TRC20/BEP20 校验、网络状态、变更申请和追加式事件；
- 交易级 TOTP 返回时间步并以数据库唯一约束防重放，不保存验证码；
- 商家 owner 确认、不同管理员复核、按未过期凭据排空、原子切换；
- 地址漂移/版本漂移时不覆盖当前值并暂停网络；
- 紧急暂停只撤销目标商家+网络的未消费凭据，已消费证据和其它网络不动；
- 旧 `updatePaymentInfo` 在状态机开启时既拒绝变更请求，也不再重新写入任何 USDT 字段；
- 初始化命令默认 dry-run，维护命令在闸关闭时只返回 `disabled`；普通 migration rollback 拒绝删除非空凭据/状态/审计表。
- 管理员采用 A 主页面完成发起、紧急暂停与当前状态查看，采用 C 复核抽屉展示完整地址、指纹、请求人、商家确认与排空状态；请求人仍不能自批。
- 商家「支付信息」只读页增加本人确认/拒绝入口及状态时间线；安全通知只携带商家、网络、状态和短指纹，不在通知正文放完整新地址，并记录真实写入结果。
- 顾客选择 USDT 时先签发绑定顾客/商家/方式的地址版本凭据；只有后端明确返回“功能未启用”才保持旧路径，其余签发失败、过期或未登录均不展示当前地址并阻止提交。
- 订单详情只读取下单时固化的 `address_credential` 白名单快照；历史订单缺少该证据时明确中止并提示联系客服，绝不读取商家当前地址补位。

仍未完成、因此绝不能翻闸：

- 生产第二独立管理员及 TOTP；
- 外部邮件/短信/Telegram 等通知渠道接线、送达结果和失败告警（本批仅完成站内安全通知与追加式日志）；
- 网络暂停/排空时，顾客端支付方式列表层的主动隐藏（当前签发失败会安全阻止展示地址与提交）；
- 迁移演练、生产状态初始化对拍、部署和逐闸观察。

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

1. 盘点所有合法发件源：Laravel/Resend、SES 退信域、数据库 `mail_configs` 驱动的站内客服工单、Gmail `support@nezha.am` Send mail as，以及任何客服或营销系统；逐一发送到外部测试邮箱并保存完整邮件头。
2. 对每个源核对 `header.from`、DKIM `d=`、SPF MAIL FROM 及 DMARC alignment；未知源不得为了通过而扩大 SPF。
3. 建立并人工验证 `dmarc-reports@nezha.am` 或受控分析器收件地址，明确值守人、访问权限与报告保留期。
4. 经 DNS 变更授权后，首阶段只发布监控策略，例如：

   ```text
   v=DMARC1; p=none; rua=mailto:dmarc-reports@nezha.am; adkim=r; aspf=r
   ```

5. 观察多个完整业务周期，确保合法源全部对齐，再按 RFC 9989 分阶段执行：`p=quarantine; t=y` → `p=quarantine` → `p=reject; t=y` → `p=reject`。`pct` 已是历史标签，不再用“小比例 pct”作为升级闸。
6. `t=y` 仍可能被不认识该标签的旧收件系统忽略，不能视为绝对保险；升级条件必须同时包含真实邮件头、聚合报告、完整业务周期和关键收件方实测，不只按固定天数放行。
7. 每阶段保留 DNS 旧值、TTL、发布时间、聚合报告和测试邮件头；任何合法邮件失败立即回到上一策略并修正源，不以永久 `p=none` 结案。默认不启用 `ruf`，避免逐封失败报告扩大敏感内容暴露面。

DMARC 影响：错误策略会导致合法邮件进垃圾箱或拒收。

回滚：恢复上一条已验证 TXT；DNS 传播期间同时修复实际发件源并监控聚合报告。

参考：[RFC 9989](https://www.rfc-editor.org/info/rfc9989/)、[Resend DMARC 文档](https://resend.com/docs/dashboard/domains/dmarc)、[Resend 域名验证](https://resend.com/docs/dashboard/domains/introduction)。

### 5.2 DNSSEC

1. 确认权威 DNS、注册商、当前 nameserver 和操作 owner；保存 zone 导出。
2. 在 DNS 提供方生成 DNSSEC 参数，核对算法、key tag、digest、digest type；在注册商提交 DS。
3. 从多个公共解析器验证 DS -> DNSKEY -> 签名链，检查 apex、API、邮件相关记录和续签/轮换说明。
4. 设监控并记录 DS TTL；不能只看控制台“已启用”。

DNSSEC 影响：DS 与签名不匹配会导致整个域名在验证解析器上不可达。

正确回滚顺序：**先在注册商删除 DS，等待 TTL 并验证公网已无 DS，再关闭 DNS 提供方签名**。绝不能在 DS 仍存在时先停签。

参考：[Cloudflare DNSSEC 指南](https://developers.cloudflare.com/registrar/get-started/enable-dnssec/)。

### 5.3 CAA（后续项）

当前无 CAA。添加前必须先确认 Cloudflare Universal SSL、任何自定义/源站证书及邮件跟踪域的完整证书清单；否则可能阻断续签。Cloudflare 说明 Universal SSL 域名在设置任一 CAA 后会按当前合作签发机构自动生成 CAA 集合，因此不得把某次查询到的 CA 列表长期手工固化；授权执行时应先以低 TTL 添加最小记录，立即核对 Cloudflare 实际合成的 `issue`/`issuewild` 与证书包状态。CAA 与 DMARC/DNSSEC 分开审批、分开回滚，不捆绑一次变更。参考：[Cloudflare CAA](https://developers.cloudflare.com/ssl/edge-certificates/caa-records/)。

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
2. **已批准**：不设任意固定冷静期；按未过期地址凭据自然排空，安全余量默认 0。
3. **已批准**：单个管理员经交易级 TOTP 可紧急暂停一个商家的一个网络；改成新地址仍须双角色确认。
4. **需业务裁决**：顾客在凭据被撤销前后已向旧地址转账时的人工核验、补偿和责任政策。
5. **分开授权**：业主采用一个明确方案即授权实施该方案逐项列出的隔离代码、测试与分支交付；不自动授权方案未列明的生产或外部动作。运行迁移、部署、创建生产管理员、状态初始化、开启凭据闸、开启地址变更闸、改 CSP/nginx、发布 DMARC/CAA、启用 DNSSEC、发布举报邮箱与 `security.txt` 仍须逐项授权。

## 8. 实施优先级

| 优先级 | 工作 | 原因 |
|---|---|---|
| P0 | 建立第二独立管理员+TOTP；A＋C UI 与站内通知已隔离实现 | 当前只有 1 个管理员，安全流程仍无法正确闭环；创建生产管理员仍需授权 |
| P0 | 后端状态机与顾客地址版本凭据已完成隔离接入，继续迁移演练和全链对拍 | 部署、迁移、初始化、翻闸仍须分别批准 |
| P1 | 付款路由 CSP Report-Only、第三方脚本路由化、漂移检测 | 先获得真实兼容证据，再收紧强制策略 |
| P1 | 发件源盘点与 DMARC `p=none` 方案 | 仿冒邮件风险高，但错误强制会伤害合法邮件 |
| P1 | 验证举报邮箱并发布 `security.txt`、落地处置 SOP | 低改动成本，提升外部线索进入和可追踪性 |
| P2 | DNSSEC、CAA | 必须有注册商/DNS owner 和回滚演练，避免域名整体不可达 |
