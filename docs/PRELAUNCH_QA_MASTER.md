# 哪吒 上线前总验收 QA Master（触发词「上线前全平台QA / 上线前总验收」）

> **这是上线运营前的单一 go/no-go 入口。** 新窗口接到"上线前 QA"→ 读本表 → 按序跑每个维度 → 逐项标覆盖深度 → 出 go/no-go 报告。
> **读法**：`node nz.js run "cat /www/wwwroot/api.nezha.am/docs/PRELAUNCH_QA_MASTER.md"`
> 与 `QA_MASTER.md` 分工：QA_MASTER 是**日常各层索引**；本表是**上线前一次性全维度总验收 + 硬门**。
> 由来：2026-06-18。"全流程QA"反复被窄化(漏退款/登录态/跨界面数字一致性)→ 把"上线该验全的所有维度"一次定全，免用户逐次追加。

> 🔴 **上线开关硬门**：跑本表前，必读并逐条过 `docs/PRELAUNCH_SWITCHES.md`（所有"故意先关着/待决策"开关的 go-live 清单：要打开的 `nezha_refund_overdue_status`、必须保持关的 COD/维护模式、未就绪的广告竞价 C1/C2、业务决策的自取/KYC/顾客验证）。开关这关没过 = 不 go。

## 0. 怎么用 + 覆盖深度纪律（铁律）
1. **先摊全维度再跑**：18 维度逐个过，**不静默只跑 happy path**。
2. **覆盖深度诚实**：每维度标 ✅真机实测 / 🔶仿真 / ⬜未覆盖 / 👤需真人。**仿真≠实测、proxy≠真实路径、wired≠命中分支、单点≠矩阵**。
3. **🔴 硬门(must)全过(或真人签字)才可上线**；其余 gate 项有缺口要列清单 + 风险。
4. **数字/计数类必真数据不注入**；**≥2处显示的同一数字必同源或对账**（横幅幽灵数教训）。

## 0.5 无人值守跑法（休息时跑、醒来看结果）
新窗口说"上线前全平台QA"→ **不会一口气全自动跑完**，按「自动化分级」分两段：
- **第一段（无人值守自动跑）**：把所有 🟢 维度全跑完，产出 **findings 报告**。
- **攒清单（不擅自执行）**：把所有 🟡 项归成一份「**待你批准清单**」（每项写清要做什么、为什么需批准），👤 项列「需真人」清单。**绝不擅自翻开关/真下单/清演示数据/跑会被拦的资金仿真**。
- **第二段（你回来后）**：你看报告 + **一次性批一串 🟡** → 我再跑第二段。
> 🔴 为什么 🟡 必须停：money-write 仿真(哪怕零落库)会被 auto 分类器拦；翻开关/清演示数据是**真实影响所有商家顾客**的破坏性操作（INVARIANTS 强制拍板）；真下单写真库发真通知。**QA 不值得为省事冒翻错开关的险。**

## 0.6 🔴 资金硬门(must): 货到付款(COD)必须全平台关闭

B方案平台不碰钱、无现金代收路径，`cash_on_delivery` 必须恒为 0。若被(误)开启，failed/pending 单的「切换货到付款」会把订单改成 `cash_on_delivery` 产出脏单。

**一键 go/no-go 自检**：
```
node nz.js run "bash /www/wwwroot/api.nezha.am/nzcheck-cod.sh"
```
- 同时校验两层：① DB `business_settings.cash_on_delivery.status == 0` ② 线上 `/api/v1/config` 的 `cash_on_delivery == false`。
- 两项全绿 → 通过；任一红字 → **no-go**（脚本 exit 1，可用于阻断/CI）。
- 修复：后台 `/admin/business-settings/payment-setup` 关闭「Cash on Delivery」(保留 Offline 支付)，保存即生效（BusinessSetting saved-observer 自动清 `business_settings_all_data` 缓存）。
- 已接入 `nzhealth.sh`（[9] 节）与前后端部署脚本（`nzdeploy-api.sh`/`nzdeploy-web.sh` 部署后红字告警，不阻断部署）。
- 由来：2026-06-23 PAYMENT-COD-CLEANUP；前端 COD 入口已移除(墙=后端开关，牌=前端入口)，本硬门防开关被误翻回。

## 0.7 B1 外部签收包

2026-07-14 的 B1 原始包由 API 文档提交 `98efcc2a625e8ba19b068747251d2ed3d66a497d` 固定；2026-07-15 已把 [B1 外部签收包](PRELAUNCH_B1_EXTERNAL_SIGNOFF_PACKAGE_20260714.md) 重封到 API 应用候选 `a53cfb5c967daa5917ce2cb4c2489d6799434ff2`、Web `b4e0ea0f17e3bfc65b3eebe9e645f5334de0faed`，8 文件内容快照提交为 `fc026a78130709ce13af356914ce01c50000d866`。旧 API `589a5366633f951fc9692810cc2a4c21c553b629` 只保留历史追溯，不得作为最终签收对象。包包含：

- [26 类 demo 关联数据裁决表](PRELAUNCH_B1_DEMO_ASSOCIATION_DECISIONS_20260714.md)
- [律师／会计固定事实包](PRELAUNCH_B1_LEGAL_ACCOUNTING_FACTS_20260714.md)
- [三项开关签收表](PRELAUNCH_B1_SWITCH_SIGNOFF_20260714.md)
- [物理设备／真实通知／专业渗透签收表](PRELAUNCH_B1_EXTERNAL_QA_SIGNOFF_20260714.md)

状态仅为**已备妥、全部未签收**；不改变任何 18 维度结果，不授予测试、写入、通知、开关、demo rollback、部署或 production Go。内部 owner 批准与外部专业意见必须附固定 SHA 与可回溯证据，不能用当前配置值、角色数量或自动化结果代签。

2026-07-15 候选重封：API `b14c9c58` 的 8 个运行时文件到最终应用候选 `a53cfb5c` 无差异；独立本地克隆中聚焦 `5 tests / 51 assertions`、完整 Feature `191 / 1013`、Unit `15 / 46` 与 8/8 PHP lint 全部通过，migration diff 为 0。Web `a9e5007..b4e0ea0f` 只有 `AGENTS.md`，无应用文件变化。五份表单统一记录上述最终 40-hex；本轮可访问签回材料仍为 0 件，外部附件 SHA-256、实名/机构和签署时间可验数量仍为 0。详细范围、计数、重封内容提交/哈希和 runtime 快照见 B1 包 §4；production 继续 NO-GO。

## 0.8 单一项目 owner 责任模型

- 当前团队拓扑为一个内部项目 owner。产品、运营、内容、数据、基础设施、QA/安全、设备测试人和真实渠道接收人是责任角色，不要求由不同自然人担任；同一 owner 只有在真实持有相应权限并亲自观察对应证据时才能兼任。
- 内部签收使用稳定 owner 标识、兼任角色、日期时间/时区和证据引用；不要求把 owner 实名提交给 Codex 或写进 Git。必要实名映射及未遮蔽原件保存在仓库外的私有记录。
- 亚美尼亚法律/隐私、当地会计/税务、USDT/AML 专业意见和独立专业渗透仍要求相应独立能力与可回溯报告；内部 owner 不能自签为这些外部专业结论。一个专业人员或机构只有在资质与书面范围确实覆盖时，才可覆盖多个专业问题。
- 真机矩阵和 FCM/邮件/Telegram 回执按设备、渠道和真实结果关门，不按签字人数关门；店 12 只有在同一 owner 确实是实际经营者、收款控制人和履约负责人时才允许合并内部角色。
- 本节只重新定义责任与证据归属，不关闭任何未完成门。B1 内容快照 `fc026a78130709ce13af356914ce01c50000d866` 和五份历史表单保持不动；后续执行以本 Master 与收口台账的单人模型解释为准。

## 0.9 通知平台承诺矩阵与本地 R2 证据（2026-07-15）

第一版的“全设备、全通知渠道”不是承诺每个容器都支持同一种推送协议，也不是承诺操作系统、浏览器、ROM 或用户设置无法阻断投递。上线口径固定为：

| 目标 | 第一版保证范围 | 不承诺/前置条件 |
|---|---|---|
| D1 iPhone Safari | 核心 H5；符合平台条件并安装到主屏幕后按标准 Web Push 单列验收 | 不承诺 FCM；需系统/浏览器支持、用户授权和有效订阅 |
| D2 Android Chrome | 核心 H5 + FCM | 需 Google Play 服务/网络、浏览器通知权限与有效 token；真实送达仍逐机验收 |
| D3/D4 iPhone/Android 微信 WebView | 核心 H5 | 不承诺 FCM；离场提醒由邮件/Telegram 等已签收角色渠道兜底 |
| D5/D6 iPhone/Android Telegram WebView | 核心 H5 | 不承诺 FCM；Telegram 机器人消息是独立服务端渠道，不等同 WebView 内推送 |
| 商家 Android App | 商家核心 H5 + FCM；提供通知、告警频道、全屏提醒、勿扰权限、电池和厂商自启动的可观察设置入口 | 不绕过用户控制；是否弹出/响铃受系统、Play 服务、ROM、权限、频道和策略约束 |
| 邮件 / Telegram | 按角色×事件矩阵保证服务端投递与回执 | 不是每个 H5 容器各实现一套；必须用真实收件箱/机器人回执关门 |

- 顾客按“安装实例”建模：同一顾客可绑定多个设备/浏览器订阅；token 轮换和重新绑定保持实例身份，退出只注销当前安装实例，不误伤其他设备。
- 本地 R2 代码证据：API `763786c`（功能提交 `d91100d`，锚定当时最新 `origin/main=45a8e8c6b5aeb84bcd7ed93babc7db825b1ac7de`）通过通知聚焦 `40 tests / 154 assertions`、桥接脚本 `4/4`、触及 PHP/JS 语法与 diff 检查；Web `d65bd1c`（含容器路由 `17c64e3`、安装实例 `5e7c462`）通过安装实例/容器/凭据闸门 `13/13` 与 Next `15.5.20` compile-only production build；商家 Android App `53ddcda`（含 `cac9975`）通过 token hash/轮换实质单测 `4/4`、`assembleDebug`/`lintDebug`，lint 为 `0 errors / 40 warnings`，XML `14/14` 可解析。debug APK 为 4,867,057 B，SHA-256 `B396AEC64B85460057D1F946E27A129676CA1609389654B69FDC41CC0A37ED5D`。
- 上述证据只关闭本地代码语义、编译、静态权限/备份规则、token 生命周期与“不合格构建 fail closed”子门。测试强制 SQLite `:memory:`，未连接真实渠道；Web 普通 build 在缺公共登录凭据时 exit 1，显式 `build:compile-only` 无需手工伪造文件且 exit 0，但默认 API 域名仍产生 TLS 预渲染 warning；Android `assembleRelease` 在缺 Firebase 与外部签名输入时由 `verifyReleaseReadiness` exit 1。`npm audit` 仍有 2 个 moderate（Next 依赖链中的 PostCSS），不以破坏性强制降级冒充修复。
- 仍开放：本地通知分支新增的 2 个 API migration 未应用；相对 `origin/main` 的第三个 migration 文件差异只是在既有 outbox 状态注释中补入 `queued`，无可执行/schema 差异。Firebase `google-services` 配置与 release signing 缺失；Play 的 full-screen intent / `specialUse` 声明及审核未完成；D1–D6、商家 App、物理 iPhone/Android 未实测；FCM/邮件/Telegram 无真实回执；外部专业报告/签回材料仍为 0。production/shared staging 未部署或写入，production 继续 **NO-GO**。
- 店 12 是朋友的店且尚未正式真实经营，不能作为实际商家、收款控制人或履约主体关闭商家就绪门。内部留痕不要求向 Codex 提交实名；门按真实设备、渠道、角色、机构、时间/时区与证据关闭，不按签名人数关闭。
- 本节是内部正本的后续执行口径，不回写五份 `PRELAUNCH_B1_*` 历史表单或内容快照 `fc026a78130709ce13af356914ce01c50000d866`。

## 1. 上线前 18 维度总表

> 自动化分级：🟢 无人值守可跑(只读/grep/带token真机浏览) · 🟡 需你批准(money-write仿真/真下单/翻开关/破坏性) · 👤 需真人(AI够不到)

| # | 维度 | 触发/归属 | 类型 | 自动化 | 现状(每轮更新) |
|---|---|---|---|---|---|
| **A 测试层（QA_MASTER 9 层）** ||||||
| 1 | 产品/前端 | `QA_PLAYBOOK`(14轴) | gate | 🟢 | ✅持续 |
| 2 | 运维/服务器 | `OPS_QA_PLAYBOOK`(10轴) | gate | 🟢 | ✅ |
| 3 | 应用层安全(IDOR/注入/上传/鉴权/密钥) | `SECURITY_QA_PLAYBOOK`(11轴) | 🔴硬门 | 🟢代码审计/只读越权·🟡改数据型越权 | ✅(非替代专业渗透) |
| 4 | 资金合规(L1 红线真生效) | `FUNDS_COMPLIANCE_QA_PLAYBOOK`(8轴) | 🔴硬门 | 🟡(机制靠回滚仿真+翻开关) | ✅机制 |
| 5 | 资金闭环(下单→扣佣→退款) | `QA_FUNDLOOP_PLAYBOOK` | gate | 🟡(回滚仿真=money-write拦) | 🔶仿真(无真实数据对账) |
| 6 | 性能/负载/N+1 | `PERF_LOAD_QA_PLAYBOOK`(+G轴) | gate | 🟢轻量·🟡重压测 | ✅ |
| 7 | **容灾/备份恢复演练** | QA_MASTER 第7层 | 🔴硬门 | 🟢隔离结构/字符指纹·👤整机/跨机 | 结构/计数✅；`utf8mb4` 字符保真❌（21 个 emoji→`?`）；整机⬜ |
| 8 | 兼容/多端真机矩阵 | QA_MASTER 第8层 | gate | 👤(真设备矩阵) | ⬜无矩阵化(零散真机已修) |
| 9 | i18n/币种֏+¥/$ | `I18N_QA_PLAYBOOK`(8轴) | gate | 🟢 | ✅ |
| **B 业务流程交叉验证（第10层·核心）** ||||||
| 10 | 三方(顾客↔商家↔平台)×四类路径(正向/逆向/异常/合规)×两登录态 | `CROSSCHECK_QA_PLAYBOOK` | 🔴核心 | 🟢正向只读浏览/登录态·🟡逆向异常资金(下单/仿真)·👤limbo脏单 | 正向✅/逆向🔶⬜/登录态⬜ |
| ↳ | **轴 I 跨界面数字一致性/不变量**(横幅==徽标==列表;裸DB计数代码味道) | 同上 §2轴I | gate | 🟢grep+只读对账·👤limbo脏单真人盯 | ⬜(横幅幽灵数已暴露; SystemController:20 孪生待修) |
| **C 上线就绪（非测试·易漏）** ||||||
| 11 | **演示数据清除**（唯一入口 `bash nzdemo-rollback.sh`）：`PLAN <evidence-dir>` 默认只读并输出计划 SHA；`REHEARSE <dir> <sha>` 只许 `nezha_qa_*` 一次性数据库、执行后事务回滚；`GO <dir> <sha>` 还必须显式 `NZ_DEMO_ALLOW_COMMIT=YES`。manifest/备份校验固定 SHA，订单/评价/add-on 必须进入精确 scope，目标当前行也进入计划指纹；manifest 外业务/资金/通知/用户关联一律 fail closed。2026-07-14 schema 全列审计收敛出 26 类 blocker，隔离演练在事务前被正确拒绝；先走数据 owner 裁决包。旧服务器本地 8 子脚本不得再直接执行。Banner 是独立配置写入，不由数据工具顺手修改。 | `docs/PRELAUNCH_CLOSURE_LEDGER_20260714.md` | 🔴硬门 | 🟢PLAN·🟡REHEARSE/GO | ⬜生产未执行 |
| 12 | 真实商家就绪（店12 营业时间/实际经营者/收款归属/通知/上架/激活） | `active=0` 且无营业时间；当前免佣免押，余额 0 不是独立阻断 | 🔴硬门 | 🟡/👤（商家或平台运营设置并签收） | ⬜ |
| 13 | **开关上线态逐个确认**(deposit_mode/guest_checkout/refund_control/sanction/risk/wallet_add_refund 目标值+CHANGELOG留痕) | INVARIANTS L2 | 🔴硬门 | 🟢现值只读核·🟡翻动需拍板 | ⬜逐个核 |
| 14 | 通知送达真机（D1 标准 Web Push；D2/商家 Android App FCM；邮件/Telegram 按角色事件） | §0.9 / [[nezha-order-notifications]] | gate | 🟢本地语义/编译·👤真机与真实回执 | 本地 R2 语义/编译✅；2 migrations、配置/签名/Play 审核、物理设备与真实渠道⬜ |
| 15 | 手册就绪(ADMIN/MERCHANT_GUIDE)+客服+退款流程(B方案联系商家原路退) | [[nezha-bfang-refund-contact-merchant]] | gate | 🟢(读文档核对) | 多数✅ |
| **D 人工门（AI 够不到，只能打底稿）** ||||||
| 16 | 法律/政策审校(协议/隐私/退款/AML·亚美尼亚+B模式) | `docs/legal` | 🔴硬门 | 👤律师 | ⬜ |
| 17 | 独立专业渗透测试(真收钱前) | B1 外部 QA/收口台账 | 🔴硬门 | 👤独立专业方 | ⬜ |
| 18 | 实体/税务/USDT跨境合规 | [[nezha-legal-tax-structure-plan]] | 🔴硬门 | 👤待律师 | ⬜ |

## 2. 执行顺序（建议）
1. 先 **🟢 无人值守全跑**（1/2/9/3代码审计/6轻量/10正向+登录态+轴I grep/13现值核/14站内信/15手册）→ 出 findings。
2. **🟡 攒「待批准清单」**（4资金合规/5闭环/10逆向异常/11演示数据清除/12真实商家/13翻开关）。
3. **👤 列「需真人」**（7容灾演练/8真机矩阵/16律师/17渗透/18实体税务/limbo脏单盯屏）。
4. 用户回来：看报告 → 一次性批 🟡 串 → 跑第二段（按 🔴硬门优先：3安全→4资金合规→7容灾→11演示数据→13开关）。

## 3. go / no-go 判定
- **可上线** = 全部 🔴硬门有与固定候选相称的成功证据；16/18 取得专业书面意见，17 取得独立报告并关闭 Critical/High，其他 gate 缺口已知且由有权 owner 明确处理。
- **任一 🔴硬门未过 = no-go**。尤其：演示数据没清(11)、真实商家下不了单(12)、开关态没核(13)、备份没演练过恢复(7)、律师没审(16/18)、独立专业渗透没完成(17)。

## 4. 输出格式

> 🔴 **证据账本铁律（强制 · 2026-06-18 立项）**：本报告每一个标 ✅/通过 的维度，必须在下方「证据账本」里紧挨着写出**证明它的那一条具体证据**（命令输出片段 / 真机截图文件名 / 真实成功态的观察）；**写不出证据的项禁止标 ✅**。**成功路径依赖"我做不到的东西"（真实 Google/Apple 账号登录、真机 iOS 键盘/安全区/推送、真支付、真链上 tx、真人解验证码）的项，禁止标 ✅——一律标 ⬜ 未验证·需你亲测 + 写清"卡在哪一步 / 为什么我做不到"。** "渲染出来 / 按钮在 / 长得对" ≠ "功能通"，永不能用前者冒充后者。
>
> 反面案例（本表自身教训）：06-18 上线前 QA 曾把顾客 Google 一键登录标 **🟢 通过**，实则只验了登录抽屉**渲染**（按钮在 / 无手机号 OTP）、**从没真跑 OAuth**（无头浏览器做不到，Google 屏蔽自动化、弹窗只到 about:blank）。真因后查明＝用户**手机 → Google 连接被网络/VPN 掐断（ERR_CONNECTION_CLOSED）+ 账号权重不足**，App 配置/后端校验全对——但当初那个 🟢 是**浅验证冒充铁结论**，靠一份光鲜的 18 维度报告蒙混过去，用户照着信了。根因＝广度稀释严谨度 + 被动 memory 无"扳机"。本铁律就是那个扳机。

1. 18 维度逐项：覆盖深度 + 自动化分级 + 证据 + go/no-go。
2. 第10层填满的交叉验证矩阵 + 轴I不变量报告。
3. **待你批准清单**(🟡 每项写清做什么/为什么需批准) + **需真人**清单(👤)。
4. **🔴 证据账本（缺它＝没做完）**：逐个 ✅/通过 项 → 紧挨证据一行；逐个 ⬜需你亲测 项 → "卡在哪一步 / 为什么我做不到"一行。

## 5. 维护约定
每轮跑完更新"现状"列；某维度建好/补完，更新归属 + 状态；本表与 `QA_MASTER.md` 第10层互链。
