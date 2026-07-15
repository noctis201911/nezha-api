# 2026-07-14 上线前 NO-GO 收口台账

状态：**production NO-GO**。本台账只记录事实、责任与签收，不授予 production/shared staging 部署、migration、数据、配置、开关、资金或真实通知写入权限。当前配置值不等于已批准值。

## 运行基线

- Web 最终应用候选 `b4e0ea0f17e3bfc65b3eebe9e645f5334de0faed`；`a9e50070ec1ea58ce1d33eb9b43c628f9425f878..b4e0ea0f` 只有 `AGENTS.md`，无应用文件变化；production `2f81803` / `20260714-101004-2f81803`。
- API 最终应用候选 `a53cfb5c967daa5917ce2cb4c2489d6799434ff2`。`b14c9c58bee66b59a45bb338f2d742609a3466f3..a53cfb5c` 对 8 个邮箱隔离运行时文件 diff=0；独立本地克隆聚焦 `5 / 51`、Feature `191 / 1013`、Unit `15 / 46` 和 8/8 PHP lint 通过。production 为热修 `dea5dd11a2a57b57660608e9c37a4fd528aa4efe` / `20260715-042928-dea5dd1`，不是最终 main 候选。
- 最终只读复核时，共享源码工作树已漂移为 Web `fb142145`（dirty 13）、API `5042b39b`（dirty 87），表明存在本动作包之外的在途改动；本轮没有清理、reset、提交或部署这些工作树，不能把它们冒充上述已验候选或 production 运行态。
- shared staging 保持 Web `ef542785`、BUILD `n4VGKngOQXDelVRDdK9yN`、dirty 16；API `f766dd62`、dirty 39。禁止清理或 reset。
- 2026-07-15 06:48–06:55 CEST 只读复核：`migrate:status` 为 460 Ran / 0 Pending；两个 queue worker online、累计重启各 93、`unstable_restarts=0`，命令仍含 `--max-time=3600`；Redis `PONG`、failed jobs=0。production `dea5dd11..a53cfb5c` migration 文件 diff 为 0。上线前仍须重新查 pending/new migration 与运行态。

## B1 外部签收工件

2026-07-14 原 B1 包由文档提交 `98efcc2a625e8ba19b068747251d2ed3d66a497d` 固定；2026-07-15 已把 [B1 包入口](PRELAUNCH_B1_EXTERNAL_SIGNOFF_PACKAGE_20260714.md) 及五份表单重封到 API `a53cfb5c967daa5917ce2cb4c2489d6799434ff2`、Web `b4e0ea0f17e3bfc65b3eebe9e645f5334de0faed`，8 文件重封内容快照提交为 `fc026a78130709ce13af356914ce01c50000d866`：

- [26 类 demo 关联数据裁决表](PRELAUNCH_B1_DEMO_ASSOCIATION_DECISIONS_20260714.md)
- [律师／会计固定事实包](PRELAUNCH_B1_LEGAL_ACCOUNTING_FACTS_20260714.md)
- [三项开关签收表](PRELAUNCH_B1_SWITCH_SIGNOFF_20260714.md)
- [物理设备／真实通知／专业渗透签收表](PRELAUNCH_B1_EXTERNAL_QA_SIGNOFF_20260714.md)

B1 只把外部问题、证据栏和签名门固定为仓库文档；所有裁决/批准/回执/签名仍为空白，六类 NO-GO 均未关闭。本包没有运行 production 备份/demo 工具、生成/外传备份、发送真实通知，或写入/deploy production/shared staging。

2026-07-15 签回验收结论：本轮可访问的工作区/Desktop/Downloads、Documents、Gmail、Google Drive 及 API 远端 heads/tags 中，未发现填写后的表单、外部意见、通知回执、设备/渗透报告或签名附件，收到材料 **0 件**。因此外部报告 SHA-256、实名/机构、签署时间/时区可验数量均为 0；不能据此推断“拒绝”，只能判定“尚未收到/未提供”。重封 Git 对象、8 份文档、5 份 manifest 和 2 份既有浏览器证据包仍是内部锚点，不能代签。

签名完整性仍为：demo 26/26 `HOLD` 且 31 个订单未逐项定性；法律/会计 0/4 专业签收；三项开关 0/3 目标值、0/5 汇总签名；外部 QA 封面 0/11、D1–D6 0/6、三渠道回执为空、无渗透报告、总签收 0/6。店 12 与新 `utf8mb4` 备份恢复属于 B1 外独立硬门，也未关闭。

候选冻结门已关闭：五份 B1 表单统一记录 API `a53cfb5c967daa5917ce2cb4c2489d6799434ff2`、Web `b4e0ea0f17e3bfc65b3eebe9e645f5334de0faed`；新增运行时代码已相称回归。由于收到签名为 0，不存在“已签意见被新 SHA 失效”的问题；后续任一应用 SHA 前移仍须停下重新定界并重测。旧 `589a5366` 上的最终“通过”不得接受。

## 单人项目责任模型（2026-07-15）

- 当前团队拓扑为一个内部项目 owner。产品、运营、内容、数据、基础设施/恢复、QA/安全、设备测试人和真实渠道接收人是责任维度，不是不同人数配额；同一 owner 可在真实持有权限并亲自核验对应证据时兼任。
- 内部留痕使用稳定 owner 标识、兼任角色、日期时间/时区和证据引用；不要求向 Codex 提供或在 Git 中保存 owner 实名。确需实名映射或未遮蔽原件时，保存在仓库外私有记录。
- 亚美尼亚法律/隐私、当地会计/税务、USDT/AML 专业意见和独立专业渗透仍是外部专业证据；内部 owner 不得自签替代。一个专业人员或机构只有在资质与书面范围真实覆盖时，才能覆盖多个专业问题。
- 真机矩阵、通知回执、备份恢复、开关目标、demo 裁决和店 12 就绪按真实证据关门，不按签字人数关门。店 12 只有在同一 owner 确实兼具实际经营、收款控制和履约责任时才允许合并内部角色；否则该店继续 NO-GO。
- 单人模型不关闭任何门，也不授权运行态动作。B1 内容快照 `fc026a78130709ce13af356914ce01c50000d866` 和五份历史表单保持不动，作为当时的历史证据；后续责任解释以本台账与 `PRELAUNCH_QA_MASTER.md` 为准。
- B1 包 §4.2 的 8 个表中 SHA-256 可在 LF→CRLF 规范化后 8/8 复现，但 0/8 等于原始 Git blob 字节哈希；历史快照不回写。后续验收必须分别标明 canonical Git blob SHA-256 与外发文件字节 SHA-256/换行口径，禁止继续把 CRLF 规范化值称作“Git blob 原始字节”。

## 1. 隐私政策与静态加密

- **当前事实**：192 张 InnoDB 表中 33 张无 `ENCRYPTION='Y'`；根盘普通 ext4、未见 LUKS；线上仍写“数据库整体采用静态加密”。
- **证据入口**：`information_schema`、`findmnt`/`lsblk`、`/api/v1/config privacy_policy`、`docs/legal/privacy-policy.md`、`docs/compliance/data-protection.md`。
- **唯一 owner**：单一项目 owner 兼任数据保护与基础设施/安全责任，使用稳定内部 owner 标识留痕。
- **需签收**：单一项目 owner 的技术事实确认 + 亚美尼亚隐私专业书面意见。
- **自动验证**：表加密计数、磁盘事实、CMS↔Git 文案 diff。
- **人工边界**：披露充分性、跨境处理、当地法适用。
- **最小正确动作**：先由律师批准真实口径，再同步 Git 正本和 CMS；不边测边批量 ALTER。
- **风险/回滚**：失实披露与 PII 风险；CMS 旧值可技术回滚，但回滚即恢复 NO-GO。
- **上线门**：技术事实、线上文案、Git 正本一致，且内部技术确认与独立隐私专业意见均可回溯；不按内部签字人数计数。

签收：`[ ] 单一项目 owner（数据保护/安全）` `[ ] 亚美尼亚隐私专业意见`

## 2. 三项未签收开关

| 开关 | 当前事实 | 需要谁签 | 不写 production 可关闭的部分 | 上线门 |
|---|---|---|---|---|
| `nezha_preorder_status=1` | `schedule_order=true`，依赖已开；exact-main SQLite 语义测试已过 | 产品 owner + 商家运营 | API 预约聚焦 53 tests + Web 取消动作契约已关闭 | 真实 UI 下单/取消/并发/刷新通过；决定保持 1 或另批回退 0 |
| `nezha_notif_async_status=1` | queue online、failed=0、Redis PONG；exact-main 异步语义 7 tests 已过；无真实渠道回执 | 运营 owner + 实际收件人 | 隔离入队/跳过/同步回退语义已关闭 | FCM/邮件/TG 真实回执后签字 |
| `nezha_merchant_video_status=1` | production 当前 release 读取商户 12：stored=2、normalized=2，封面与 `v.douyin.com` 外跳技术约束通过 | 平台负责人 | 只读模型/共享 storage 核对已关闭事实层 | 内容 owner 批准两条封面/标题/外跳，或另批回退 0 |

- **唯一 owner**：平台上线开关 owner（平台负责人）。
- **风险/回滚**：未验功能/内容已暴露；不接受当前值时另取 Go 精确回退单项为 0。
- `nezha_autooffline_status=1` 符合 A 类应开启目标，仅旧台账值漂移，不计第四项偏差；仍需通知/阈值签收。
- **非生产证据**：API exact-main 在强制 SQLite `:memory:` 安全门下共 `63 tests / 162 assertions` 通过，其中预约 53、异步通知 7、DB 安全门 3；Web exact-main `src/utils/nezhaCancelAction.test.js` 通过。PHPUnit 仅有既存 schema/doc-comment deprecation 和 SQLite 无 `business_settings` 的测试日志。上述证据不覆盖真实浏览器预约链、真实渠道送达或 owner 批准。
- **视频只读证据**：从 production 当前 release（不是独立 worktree）直接读取 `LocalLifeMerchant#12` 并使用共享 storage 调用规范化方法，stored=2、normalized=2；两条均为 `https://v.douyin.com` 且 cover URL 存在。独立 worktree 会因隔离 storage 错报 normalized=0，不能作为线上素材存在性证据。公开详情 API 会写浏览计数，本轮为遵守零 production 写入没有调用；剩余仅为内容 owner 人工签收。

签收：`[ ] preorder` `[ ] notif_async` `[ ] merchant_video` `[ ] autooffline 通知/阈值`

逐项填写入口：`docs/PRELAUNCH_B1_SWITCH_SIGNOFF_20260714.md`。当前值只能填“只读事实”，批准目标值由对应 owner 留痕签字。

## 3. Demo 数据与 Banner

- **当前事实**：marker 命中 vendors 7、restaurants 7、local-life merchants 6、posts 21；总体行数 9/9/10/21；`Demo Banner` 仍在。
- **证据入口**：版本化 `nzdemo-rollback.sh`/`nzdemo-cleanup.php` 的 PLAN、固定 evidence SHA、production `/config`。
- **唯一 owner**：单一项目 owner 兼任上线数据清理与基础设施/恢复责任，使用稳定内部 owner 标识留痕。
- **单人补偿控制**：数据裁决与备份恢复可以由同一人负责，但不得用同一份未经验证的材料互相证明；新备份的独立文件/hash、全新隔离恢复和逐字节指纹必须先于任何 production cleanup 动作包完成，缺一即 fail closed。
- **需签收**：单一项目 owner 的逐类业务裁决与技术恢复证据；涉及资金、PII、审计或法定保留期的删除仍需对应专业意见。
- **自动验证**：marker、manifest 哈希、订单/评价/add-on 精确 scope、manifest 外关联 blocker、执行后残留、事务回滚同 hash。
- **人工边界**：任何关联订单、评价、菜品、退款/风控/资金/通知/用户状态或已改变内容是否可删；Banner 替换文案。
- **最小正确动作**：数据 owner 先逐类裁决 manifest 外关联并补可逆证据；隔离库 `PLAN→REHEARSE` 全绿后，production 另取数据 Go、执行前备份、重新 PLAN、按 hash GO；Banner 单独改。
- **风险/回滚**：误删真实数据；工具默认只读，缺证据/有非 marker 订单即拒绝；当前加密 DB 备份存在 `utf8mb4` 字符保真缺口，production 回滚必须同时有新备份恢复证据与受影响行级快照。
- **上线门**：DB marker 残留=0、Banner 不再是 demo、真实记录未受影响。

2026-07-14 production 只读 PLAN 的新增发现（旧 residual 脚本未覆盖）：

- demo 店 6–11 仍关联 31 笔非 `_demo_socialproof_seed` 订单：`9–13`、`1999002074–1999002075`、`2000000001–2000000024`。这些订单可能是 QA/演示，但在数据 owner 逐项签收前不得按“店是 demo”自动推断可删。
- `_ll_merchants_manifest.json` 的现存 ID 为 `1,2,3,5,6,7`，六行名称都已偏离最初 seed 值；只凭旧 manifest ID 不足以证明仍可删。
- 新工具允许导出 **rehearsal-only** 精确 scope；REHEARSE 会在事务前强制断言实际数据库名以 `nezha_qa_` 开头，且该 scope 被硬性禁止用于提交。production 需数据 owner 重新确认后生成 `purpose=production-approved` 的独立 scope，并取得新的精确 Go。

2026-07-14 非生产动作包 A 结果：

- 一次性恢复库 `nezha_qa_demo_goa_20260714`（192 表、orders=44）导出的 rehearsal scope SHA-256 为 `ea85ed0adf1f97c24c597da94918c05e536286ce35e1c58ed45b54816520b72a`，精确覆盖 31 笔关联订单、22 条评价、0 个 add-on 和现存 6 个本地生活商家名称。
- 工具将 20 类目标表/关联行的完整当前值纳入 SHA-256 指纹。17:26 加密备份恢复后只有 `local_life_posts.cover_emoji` 与 production 不同：21 行备份值均为 `?`（hex `3F`），production 为 4-byte emoji；备份脚本的 `mysqldump` 未显式固定 `--default-character-set=utf8mb4`。为继续本轮隔离对账，只把这 21 个公开展示字段从 production 只读快照补到一次性库，补齐后 20/20 类行指纹一致；这不算备份字符保真通过。
- schema 全列关系审计得到 48 个非零引用入口；扣除工具本来会精确删除的同一批行后，扩大为 26 类 manifest 外 blocker。同一 scope 在补齐后的恢复库 PLAN 为 `55bdf110eaee3c17e1b03ab2d038c9ce41cb1b029538417efd325ff9783a680c`，在 production 只读 PLAN 为 `c13203f4a3595669272aa26e18f9a8f1d7d06708e916744d3a0a1a0060a0cba5`；二者 20/20 类目标行指纹一致、26 类 blocker 与计数一致，`--apply --rollback` 在进入事务前以 exit 4 拒绝。
- 26 类非零关联计数：额外 food 20、seed category 关联但 manifest 外 food 58、food/order 关联但 scope 外 review 各 1、cart 1、coupon claim 4、cuisine 6、local-life merchant account 1、log 96、message 2、cart event 86、consolidation survey 1、CS ticket 1、order timeout event 38、refund 17、review report 1、risk 13、offline payment 23、order transaction 13、deposit transaction 2、notification setting 72、restaurant report 1、user info 4、user notification 43、vendor feedback 1、wishlist 7。它们分别触及内容、优惠券权利、客服、资金/支付凭证、风控、通知和用户状态，不得由 demo 工具自行推断删除。
- 安全验证事件：首次临时环境文件替换未命中带前导空格的 DB 项，命令误连 production；工具已开启事务，删除语句在餐厅外键处失败并由异常路径完整 `ROLLBACK`，未执行 `COMMIT`。随后相同 production PLAN、orders=44 及 marker/residual 计数复核不变。已把 REHEARSE 的实际库名 `nezha_qa_` 前缀断言固化进版本化工具与契约测试。

因此本项现在不是“等待执行 demo rollback”，而是等待数据清理 owner 提交一份逐类裁决包：保留/迁移/删除策略、精确 ID、业务/资金记录保留年限、依赖顺序、执行前行级快照与恢复演练。未完成前不得生成 production-approved scope。

备份子门同时重新打开：备份 owner 必须修复字符集参数，生成一份新的加密备份，在全新隔离库验证结构/计数/mysqlcheck，并对 `utf8mb4` 样本及受影响行指纹逐字节一致；旧备份只能证明结构可恢复，不能作为本次 demo 清理的唯一回滚点。

固定 evidence SHA-256：

- `_demo_seed_manifest.json`: `68982d4926210e3e394a4b6cdde53e8ffe4defc0a9fe525c8abedc7bc42b3c62`
- `_demo_locallife_v2.json`: `e6ff14552788c9af4c734aadb78bf7fa4a9fc89cef1e69926c6cfec9f076f0ee`
- `_locallife_v2_backup_20260617212214.json`: `82a6e165127bfec5c1ee43ae24ced39a2fa4fcef4610e2a305aa630b114be5a6`
- `_ll_merchants_manifest.json`: `75a635af0a887aa12e956d75ba3c5578fa669ea6e4e93924b97be77f3fc174bd`
- `_demo_locallife_service.json.archived.20260617140211`: `19be5491c8bbf40e0e72a1f35e3d2cb29a5568a2c4d65b9029b96764eb114f80`

签收：`[ ] 数据清理 owner` `[ ] 关联订单/内容逐项裁决` `[ ] 备份字符保真` `[ ] 平台负责人 production GO`

逐类填写入口：`docs/PRELAUNCH_B1_DEMO_ASSOCIATION_DECISIONS_20260714.md`。26 类机器键、计数与 owner 路由已经固定，但 26/26 仍为 HOLD。

## 4. 店 12 真实营业就绪

- **当前事实**：`active=0`、营业时间 0、38/38 菜品 active；支付宝码和 TRC20 有值，BEP20 无值。
- **经营边界（2026-07-15）**：店 12 属于朋友，尚未开始正式真实经营；单一项目 owner 不是已证实的实际经营者、收款控制人或履约负责人。朋友关系和现存收款字段均不能替代经营授权、收款归属、履约能力与受控订单证据。
- **校准**：`nezha_deposit_mode_status=0`、最低保证金阈值 0、店 12 佣金关闭；余额/保证金为 0 不是当前阶段独立阻断。
- **唯一 owner**：店 12 实际经营者；若单一项目 owner 同时真实控制经营、收款与履约，可兼任平台商家运营。
- **需签收**：实际经营/收款归属确认 + 平台运营确认；可以是同一 owner 的合并留痕，但不能代替真实营业、通知和订单证据。
- **自动验证**：营业时间、菜品、active、收款字段、通知绑定。
- **人工边界**：收款归属、真实营业能力、出餐配送、消息接收。
- **最小正确动作**：先由朋友以实际经营者身份参与并完成经营/收款/履约与通知签收，再配置营业时间，另取精确 Go 跑受控订单，最后才允许评估激活。
- **风险/回滚**：错收款或无人接单；第一回滚动作是 `active=0`。
- **上线门**：实际经营者与收款控制权可回溯、平台运营批准、受控订单三端闭环；不按签字人数计数。

签收：`[ ] 实际经营者/收款控制人` `[ ] 平台商家运营（可由同一 owner 兼任）` `[ ] 通知` `[ ] 受控订单`

## 5. 亚美尼亚法律、税务与 USDT

- **当前事实**：公开法律文档缺运营主体全称/地址，并明确待当地律师审校；税务和 USDT 定性无签字。
- **唯一 owner**：拟运营主体负责人；主体未落地前由平台发起人负责。
- **需签收**：亚美尼亚法律/隐私、当地会计/税务、加密资产/AML 专业书面意见；若同一专业人员或机构的资质与委托范围真实覆盖多个问题，可合并文件，但不得由内部 owner 自签。
- **自动验证**：文档缺口、线上 CMS 对账、法律版本记录。
- **人工边界**：实体、VAT/所得税/开票、消费者责任、USDT 是否构成受监管服务、AML/制裁、跨境数据与亚语文本。
- **最小正确动作**：向三方提交固定业务事实包并取得书面结论，再按结论改文档/流程。
- **风险/回滚**：无主体经营、税务或许可风险；意见未到不改变运行态，保持 NO-GO。
- **上线门**：主体公开、三方签字、强制整改落地。

官方入口：<https://www.arlis.am/en/acts/210999>、<https://www.cba.am/en/announcements/9440/>、<https://www.arlis.am/en/acts/221385>、<https://www.pdpa.am/en/legislation>。

签收：`[ ] 主体负责人` `[ ] 律师` `[ ] 会计师` `[ ] USDT/AML 合规`

书面意见入口：`docs/PRELAUNCH_B1_LEGAL_ACCOUNTING_FACTS_20260714.md`。固定事实与专业问题已分栏；AI/仓库维护者不能代签结论。

## 6. 专业渗透、真机与真实通知

- **当前事实**：仓库安全自动化不等于独立渗透；物理 iPhone Safari、Android Chrome、微信/TG WebView 和商家 Android App 未完成矩阵；真实 FCM/邮件/Telegram 无回执。2026-07-15 本地 R2 已补齐代码语义与编译证据，但未改变真实送达状态。
- **唯一 owner**：单一项目 owner 兼任上线 QA/安全责任，使用稳定内部 owner 标识留痕。
- **需签收**：独立专业渗透报告；目标设备测试人与真实渠道接收人可由同一内部 owner 兼任，但每个设备、渠道和结果仍须有真实证据。
- **自动验证**：固定 SHA、安全回归、queue/幂等、console/失败请求、报告哈希。
- **人工边界**：真实设备、系统通知权限、真实收达、独立攻击判断。
- **最小正确动作**：先补 Firebase `google-services` 配置、release signing、Play full-screen intent/`specialUse` 声明与必要审核并在隔离目标应用 2 个通知 migration；再用专用测试身份做 D1–D6、商家 App、FCM/邮件/Telegram 受控实测。上述均是后续精确动作包，不由本台账授权。
- **风险/回滚**：未发现漏洞或漏单；关闭隔离目标、撤销测试 token、只删 marker fixture。
- **上线门**：独立报告中无未处理 Critical/High；D1 核心 H5 + 标准 Web Push、D2 核心 H5 + FCM、D3–D6 核心 H5、商家 Android App 核心 H5 + FCM 有逐设备证据；邮件/TG 按角色×事件有真实回执。平台/系统/ROM/用户控制造成的客观限制必须在结果中如实记录，不能写成绝对送达保证；不按内部签字人数计数。

### 6.1 第一版平台/渠道承诺矩阵

| 目标 | 保证范围 | 证据边界 |
|---|---|---|
| D1 iPhone Safari | 核心 H5；符合平台条件并安装到主屏幕后单列标准 Web Push | 不承诺 FCM；真实 iPhone、系统支持、授权、订阅与回执 |
| D2 Android Chrome | 核心 H5 + FCM | Play 服务/网络、浏览器权限、token 与回执 |
| D3/D4 微信 WebView | 核心 H5 | 不承诺 FCM；邮件/TG 独立角色渠道兜底 |
| D5/D6 Telegram WebView | 核心 H5 | 不承诺 FCM；Telegram bot 消息与 WebView 内推送分开验 |
| 商家 Android App | 商家核心 H5 + FCM；可观察的通知/频道/全屏提醒/勿扰/电池/厂商自启动入口 | 不绕过用户控制；Android/Play/ROM/权限/频道约束逐项记录 |
| 邮件 / Telegram | 按角色×事件矩阵保证服务端投递 | 真实收件箱/bot 回执；不是按容器复制一套 |

顾客通知以安装实例为 owner：同一顾客允许多设备；token 轮换与重新绑定不新建身份，退出只注销当前安装实例，其他设备不受影响。

### 6.2 2026-07-15 本地 R2 证据与未关闭条件

- API `763786c`（功能提交 `d91100d`，锚定当时最新 `origin/main=45a8e8c6b5aeb84bcd7ed93babc7db825b1ac7de`）：通知聚焦 `40 tests / 154 assertions`、桥接脚本 `4/4`、PHP/JS 语法和 diff 检查通过；测试强制 SQLite `:memory:`，未连接真实渠道。
- Web `17c64e3`（含 `5e7c462`）：安装实例/容器矩阵 `9/9`，Next `15.5.20` build 通过；ignored 空凭据占位仅用于本地编译并已删除。default API TLS warning 与 2 个 moderate PostCSS/Next 依赖告警仍记录在案。
- 商家 Android App `cac9975`：`testDebugUnitTest assembleDebug lintDebug` BUILD SUCCESSFUL，但 unit tests 多为 `NO-SOURCE`；lint `0 errors / 43 warnings`，XML `14/14`。debug APK 5,308,815 B，SHA-256 `EAF8F71E9AFC5202B37B90EDF034B834C93F99E802AEB467E8A8C7BC48BD6AA0`；未内置 Firebase 配置，未 release signing，未真机验证。
- **本轮只关闭**：多安装实例、token rebinding/rotation/logout、App 有界重试/refresh、容器路由，以及编译/lint/静态权限与备份排除规则。
- **仍开放**：2 个新增 API 通知 migration 未应用；相对 `origin/main` 的第三个 migration 文件 diff 只是既有 outbox 状态注释补 `queued`，无可执行/schema 差异。production 运行记录 0；Firebase 配置与 release signing；Play full-screen intent/`specialUse` 声明/审核；物理 Android/iPhone、D1–D6 与商家 App；FCM/邮件/TG 真实回执；独立渗透/外部报告。当前可验外部附件 SHA-256、实名/角色/机构、签署日期时间/时区数量仍全为 0。
- 五份 `PRELAUNCH_B1_*` 历史表单与快照 `fc026a78130709ce13af356914ce01c50000d866` 保持不动；固定 B1 API/Web SHA 不是尚未部署的通知 R2 候选。内部签收使用稳定 owner 标识即可，不要求把实名交给 Codex 或写入 Git。

签收：`[ ] QA/安全 owner` `[ ] 渗透报告` `[ ] 设备矩阵` `[ ] FCM` `[ ] 邮件` `[ ] Telegram`

执行与回执入口：`docs/PRELAUNCH_B1_EXTERNAL_QA_SIGNOFF_20260714.md`。表单已备妥不等于授权测试；真实通知、受控订单、扫描/渗透另取精确 Go。

## 可立即关闭与必须等待

| 状态 | 本轮结果 | 剩余边界 |
|---|---|---|
| 已关闭（零 production 写入） | 开关/店 12/静态加密事实漂移已校准；预约与异步通知 exact-main 自动语义通过；通知 R2 的多安装实例/token 生命周期/容器路由和 API/Web/App 编译静态子门通过；旧 demo 脚本风险已替换为版本化 fail-closed 入口；demo manifest 外关联已精确暴露；视频 stored=2/normalized=2 的技术可见性已核对 | 这些只关闭事实、代码语义和编译/静态子项；不关闭 migration、配置/签名/Play、真机、真实渠道或六类 NO-GO |
| 已备妥、不能代签 | B1 已生成数据裁决表、律师/会计事实包、设备矩阵、渗透范围、真实通知用例和开关签收表；2026-07-15 已冻结/回归候选并重封五份表单 | 可访问签回材料仍为 0 件；不能发送真实通知、改配置/素材/数据或冒充 owner 签字 |
| 单一 owner 可负责、证据未齐 | preorder/notification/video 目标值；demo 关联裁决；`utf8mb4` 备份恢复；店 12 条件就绪；通知 R2 migration/配置/签名/Play；物理设备矩阵与真实渠道回执 | 可由同一 owner 承担内部角色，但店 12 还需朋友作为实际经营者参与；每项仍需真实证据和对应精确 Go，当前均未关闭 |
| 必须等待外部专业证据 | 亚美尼亚法律/隐私、当地会计/税务、USDT/AML 专业意见；独立专业渗透与复测 | 任一未完成，production 仍 NO-GO |

## 推荐动作顺序与权限停点

1. 非生产准备已完成：版本化正本与 demo 工具；一次性恢复库演练；exact-main 预约/通知语义；B1 外部签收模板。
2. 候选冻结门已完成：API `a53cfb5c...` / Web `b4e0ea0f...` 已固定，`b14c9c58` 及其后续运行时范围已回归，五份 B1 表单已重封；这只是代码/文档证据，不授权部署或外部写入。
3. 单一 owner 先完成无需外部专业判断的决策包；通知 R2 的 2 个 migration、Firebase 配置/release signing/Play 申报、真实通知、真机数据写路径、备份修复/运行、demo、店 12 与开关动作仍分别另取精确 Go。
4. 等外部专业证据：法律/隐私、会计/税务、USDT/AML、独立专业渗透；设备测试人与收件人不再被误列为必须的外部人员。
5. 专业结论到件后再另取精确 Go 实施其要求的隐私 CMS、流程或产品范围修改；不得边咨询边改 production。
6. 六类门全关后，才重新跑 production 部署必测门；部署候选仍是独立最终动作，不由本台账自动授权。
