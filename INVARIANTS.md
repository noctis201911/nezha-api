# 哪吒外卖 — 核心机制不变量清单 (INVARIANTS)

> 本文件定义平台各机制的**变更等级**。任何人(含 AI 助手/新开的工作窗口)在改动前必须先查本表确认等级。
> 这是防止"后续窗口无意中改坏合规红线"的评级系统。完整背景见 `docs/compliance/`。

## 变更等级定义

| 等级 | 含义 | 改动规则 |
|---|---|---|
| 🔴 **L1 合规红线** | 改动 = 法律/合规风险(反洗钱、二清、外汇、数据保护) | **禁止擅自改动。必须先向平台负责人(用户)说明并取得明确批准,改后记入 `docs/compliance/CHANGELOG.md`** |
| 🟡 **L2 业务参数/规则** | 可调,但影响业务行为或顾客体验 | 可改,但须**留痕**(git commit 写清原因)并在下次同步告知用户;阈值类优先用后台设置项调,不改代码 |
| 🟢 **L3 实现细节** | UI 文案、表结构、代码组织、性能优化 | 自由改,正常 commit 即可 |

> 📍 **USDT 链上到账核验（2026-06-21）= 🟢 L3**：顾客填交易哈希后，系统只读查询公链（TronGrid / BSC RPC，复用退款·制裁同一套设施与已配 TRON-PRO-API-KEY）核对收款地址=商家、币种=USDT、金额≥应付、区块已确认，结果仅作商家「确认收款」的**辅助判断展示**。**只读、不移动资金、不触发自动放款或自动确认收款**（仍由商家人工确认），不归集、不经手顾客资金，不构成代收/二清，与 L1-1（平台不碰钱）无冲突。法币（支付宝）实付金额比对与凭证图片体检均为**软提示、永不阻断下单**。〔2026-06-21 补〕新增 BEP20（币安智能链）USDT 收款，与波场TRC20 **同属只读链上核验真到账**（BSC RPC 多节点 failover），同 🟢 L3 定性、平台不碰钱；真免手续费的币安内部转账(Binance Pay)链下无哈希不可核验、未引入。

## 🔴 L1 合规红线清单 (改动必须用户批准)

| # | 不变量 | 所在 | 为什么是红线 |
|---|---|---|---|
| L1-1 | **平台全程不碰资金**:顾客的钱直付商家本人账户,平台不归集、不代收代付 | OrderLogic 直付分支、收款面板 | 平台归集再分发 = 二清(无证经营支付结算),违法 |
| L1-2 | **退款只允许原路退回**:原支付方式/原卡/原钱包/原USDT地址,金额≤原订单,**禁止退到任何第三方账户** | ✅已实施②:Admin/OrderController 退款分支 + NezhaRefundControl::lock_route/check_limits + OrderLogic::refund_order;USDT原路反查锁定、法币政策+凭证、金额强制≤原单;留痕 nezha_refund_records。开关 nezha_refund_control_status(默认关) | 退到第三方 = 洗钱通道,帮信罪风险 |
| L1-3 | **USDT 只可退回原始付款钱包地址**,系统不提供"USDT兑人民币/提现到中国账户"任何入口;USDT余额仅境外使用 | ✅已实施②:NezhaRefundControl 退款目标=原始tx反查的from地址(锁死,后台无自由填地址入口),退款tx链上校验金额+地址;无任何换汇/提现入口 | 跨境换汇/提现 = 非法经营、外汇违规 |
| L1-4 | **交易资料只按真实法定/争议目的最小留存**：订单金额、商家、时间、状态等必要事实可去主体化留存；tx hash、来源地址、截图、自由文本不得以“普遍 AML 五年”为由一律保留 | `orders` 保留最小交易事实；`offline_payments`、`refunds`、`nezha_refund_records` 在顾客注销后清除地址、哈希、截图与自由文本。若运营者实际成为 AML 报告主体、或某笔材料被税务/诉讼/主管机关依法要求，再按该明确范围与期限冻结 | 亚美尼亚普通撮合平台并非当然的 AML 报告主体；税务五年只覆盖证明税基、收入支出与已缴税款所必要的文件/字段 |
| L1-5 | **二清打款腿已拔除,不可恢复**:RestaurantDisbursement 已禁用,直付订单不记 total_earning | RestaurantDisbursementController、OrderLogic | 恢复 = 退回二清结构 |
| L1-6 | **制裁筛查按真实连接点适用，不把 OFAC 冒充亚美尼亚普遍法**：现有 OFAC 地址/姓名筛查可作为平台自愿风险控制继续运行；亚美尼亚法定最低清单应另核本国与联合国清单，出现美国主体、美国人、美国服务商、美元清算或合同承诺时再核 OFAC 强制适用 | 现有 `NezhaSanctionScreen` / `NezhaKycScreen` 行为本次不关闭；对外与内部法律文本不得写成“只因在亚美尼亚经营或使用 USDT 就法定必须 OFAC” | 错误法律归因会制造隐形合规债务，也会遗漏真正应核的亚美尼亚/联合国清单 |
| L1-7 | **PII 加密、最小化并在目的用尽后删除**：地址、联系方式、支付截图、链上地址、哈希、自由文本和上传文件均须有明确目的与期限；注销完成后清除或去主体化，依法冻结只限必要范围和期限 | MySQL 表空间加密 + 加密备份只是静态保护，不替代删除；顾客自动注销使用字段矩阵、附件删除、恢复重删和加密完成通知 outbox。商家 KYC 资料仅在真实准入、合同、税务、争议或报告主体义务需要时保留；当前不能把所有 KYC 自动套成五年 AML 豁免 | 亚美尼亚个人数据最小化、目的限制和目的完成后终止处理义务 |
| L1-8 | **押金持有 + 商家退出结算退还**(待·组④⑤设计中):①押金**法币-only**、平台不持任何加密资产(不收 USDT);②退还只退回**缴纳主体本人 KYC 核验账户**、非第三方;③退款前按真实适用清单与平台风险政策复核，不把 OFAC 写成亚美尼亚普遍法;④三账户独立、不跨账户挪用;⑤流水按实际会计、税务与争议目的保留必要字段和期限，不自动套用 AML 五年 | 现有 dormant 实现与风险门保留；正式启用前须按运营者、资金路径和真实适用法重新核定。正本 `docs/PLAN_merchant_offboard.md` §4/§8 · `docs/DESIGN_merchant_offboard.md` | 退第三方与平台持币会突破“平台不碰钱”基石；适用税务、支付和制裁义务必须随运营者与资金路径核定 |
| L1-9 | **平台不出资促销(账务定性)**:店铺折扣/多级满减=商家自掏,记账 100% 归 vendor(discount_on_product_by=vendor·expenseCreate created_by=vendor·amount_admin=0),平台不记补贴、不减净利;佣金按商家实收(减后额)计,平台因让利"少收"D×佣金率=让利分摊非平台支出 | ✅已实施(2026-07-02):OrderController place_order(满减命中→vendor) + OrderLogic create_transaction(vendor 分支 amount_admin=0·已删 admin discount_on_product 拆分行);多级满减灰度关不影响·现有 POS 商家折扣即时生效 | 把商家自掏促销记成平台补贴/admin 出资=虚构"平台贴钱", 与 L1-1"平台不碰钱不补贴"对外陈述冲突, 误导监管/银行/商家对账(账务定性红线) |

> "(待)"= 机制已规划但尚未实装(见对应组别)。实装时这些红线即生效,实装前不得以"简化"为由跳过。

### 直收支付 V2 超时迟付的 L1-2/L1-3 限域例外（2026-07-19 已批准，未上线）

> **（2026-07-20 状态更新）** V2 候选实现已于 `74d22ce0` 从 main 撤下（代码保留于 `fa428b9c`；从未部署、开关恒 0、生产零 case）。本节例外条款保留为**已批准的设计约束**。重新落地前置：① 补 `event_key`/150000 父 migration ② 五停点业主裁决（交易所路线 /debate、多付例外、关闸语义、gas、L2 阈值）③ nezha-auditor GATE。

平台负责人已明确批准下列规则只适用于“订单已经超时取消、随后才发现付款”的独立迟付案件；现网普通退款仍按上表 L1-2/L1-3 与 `NezhaRefundControl` 执行，不随 V2 自动收紧或放宽：

- 原订单永久保持 `canceled`，不得因迟到付款复活或继续履约；顾客仍需商品时重新下单。
- 平台只记录和协调案件，资金始终由顾客直付商家、由商家自行退回顾客，平台不代收、代退或归集。
- USDT 自托管钱包按原付款来源地址退；若原付款来自交易所，商家直接联系顾客取得退款地址，不增加平台强制地址所有权认证或顾客站内确认闸。该地址只能记为“顾客提供”，不得伪称平台已证明归属。
- 实收多于应付时仍正常退款；具体净退款额与手续费由商家和顾客自行协商，平台不规定默认手续费，也不强制站内确认。系统若记录该金额，只能标记为双方线下协商后的商家申报值。
- USDT 只有在严格核对链上成功状态、终局性、对应网络 USDT 合约、案件退款地址和协商后的原子整数净退款额后才能关闭退款案件。
- 支付宝由商家确认已退款后关闭；该结论只能标记为 `merchant_declared`，不得标记为 `provider_verified`。顾客称未收到时可另行向平台发起申诉，原订单仍不复活。
- 现行 `nezha_payment_address_credentials` / `nezha_payment_network_states` 继续是唯一收款地址治理 owner，V2 禁止 live-address fallback；退款记录继续复用现行退款 owner，不新建第二套顶层退款账。
- 普通退款与 V2 迟付共用物理表 `nezha_refund_records`，但按域隔离：Funds 普通退款继续以 `event_key=order:{id}:refund` 保证 exactly-once；V2 只写 `source_domain=direct_payment_late_v2` + 每订单唯一 `case_key`，并保持 `event_key=NULL`。因此同一订单最多各有一条普通退款和一条 V2 迟付案件。迁移固定先 `150000`（Funds）后 `180000`（V2）；一旦有 V2 数据，只允许前向兼容演进，不执行 `down()` 回退。

当前候选实现已接入独立迟付案件控制器、现行退款 owner 的追加字段与事件表 migration、严格 USDT provider 适配器、商家/后台证据台及顾客 H5；新开关 `nezha_direct_payment_late_v2_status` migration 默认写入 `0`。候选尚未部署、未开启、未接入生产流量，测试只使用隔离数据库与 provider fake；进入运行态前仍须完成实现审计、精确发布动作包、staging 无资金 canary 与回滚验证。

## 🟡 L2 业务参数 (可调,留痕告知)

| 参数 | 当前值 | 调节方式 |
|---|---|---|
| 风控总开关 nezha_risk_control_status | 1(开) | 后台风控设置;开启属"真实影响开关",需满足前提(见 [[nezha-risk-control]])并告知用户 |
| 风控阈值(单笔/单日/频次/大额) | 单笔110437֏(≈$300)/单日累计800000֏/24h20单·10min8单/大额368120֏ | 后台风控设置页,不改代码 |
| 人工放行宽限期 | 60 分钟 | 后台风控设置页 |
| USDT 独立阈值 | 单笔110437֏(≈$300)/单日800000֏ | 后台风控设置页 |
| **退款护栏总开关 nezha_refund_control_status** | 1(开) | 后台「风控设置→退款控制」;**独立于下单风控**;开启属"真实影响开关",需测试单验证+告知用户后再开 |
| 退款限额(单笔/单日累计/单日笔数/窗口) | 单笔400000֏/单日800000֏/10笔/3天 | 后台「风控设置→退款控制」,不改代码 |
| USDT退款链上自动校验 nezha_refund_usdt_verify_status | 1(开) | 后台;关则仅锁定+人工核 |
| **制裁筛查总开关 nezha_sanction_screen_status** | 1(开) | 后台「风控设置→制裁名单筛查」;关则不筛查 USDT 来源地址 = **L1-6 不生效**,关闭须告知用户;名单源 URL nezha_sanction_source_url 同处可调 |
| 佣金率/服务费率 | 佣金10%/服务费5%(未启用) | 后台业务设置 |
| 预存佣金（履约保证金）阈值/扣佣开关 | 0/关 | 后台 |
| **广告计费总开关 nezha_ad_billing_status** | 0(关) | 后台「广告管理→广告计费设置」;开=商家投广按天从保证金扣 advertisement_fee(平台收自有广告服务费、不碰顾客钱,非二清),属"真实影响开关",开前确认单价/商家知情/有保证金充值通道 |
| 广告单价/曝光加权/平台下架退费 | nezha_ad_price_per_day 1000֏·天 / nezha_ad_boost_weight 0.5 / nezha_ad_refund_on_platform_takedown 1(开) | 后台「广告计费设置」,不改代码 |
| **广告竞价总开关 nezha_ad_auction_status** | 0(关) | 后台「广告管理」(第二期出 UI); 开=商家按点击竞价投广、从独立 ad_balance 扣 ad_click_fee(**只动 ad_balance、永不碰 deposit_balance**=广告烧空不触发停业闸), 属"真实影响开关", 开前确认商家已充 ad_balance + 死亡测试全绿; 与 CPT(nezha_ad_billing_status)解耦可并存。参数(floor/日预算上限/单次封顶/dedup窗口/质量分等)见迁移 2026_07_02_000100 + docs/PLAN_ad_auction.md。🔴2026-07-02 /debate 追加前置门槛: C1 后端加固(click/impression 加 throttle + 按日计费点击上限 + 自点击剔除 + impression→click nonce)+ C2 前端触发正确性 + 首页推广条去 merit 标签「精选好店」, 三者未完成前**不得真上线开启**(现 click 路由无 Laravel throttle + dedup 仅 15min 无按日上限 → 对手小号可日烧空对手预算 + 可盲扣任意对手广告; 详见 PLAN §11)。仅可短暂测试排序后关回。 |
| **逾期未退款考核总开关 nezha_refund_overdue_status** | 0(关) | 后台「风控中心→逾期未退款」;开=对超期未原路退款的商家施加非资金约束(记风控refund_overdue+催办+告警+运营手动停接单),平台不碰钱/不代退/不扣保证金赔顾客;属"真实影响开关"(会暂停商家经营),开前确认默认阈值;阈值改小时级 nezha_refund_overdue_remind_hours(现设4催办)/nezha_refund_overdue_suspend_hours(现设36(=1.5天)建议停接单)同处可调(缺则回退旧*_days×24);cron每小时扫 |

## 改动 L1 的标准流程
1. 在工作窗口中**停下**,向用户说明:要改哪条 L1、为什么、影响什么。
2. 取得用户**明确批准**后再动。
3. 改完在 `docs/compliance/CHANGELOG.md` 记一笔(日期/改了什么/批准人/原因)。
4. git commit 写清"L1变更:..."。

相关: `docs/compliance/AML-policy.md` · `docs/compliance/business-flow.md` · `docs/compliance/data-protection.md`

> 🟢 **L1 现有自动化断言(2026-06-21·子项C)**: `tests/Feature/NezhaL1RedlineTest.php` 把 L1-1(平台不碰钱)/L1-2,3(退款只原路)/L1-5(二清腿已拔)/L1-6(制裁命中即拒)写成可重复运行的断言; `composer test:redlines` 同时跑红线 + IDOR 结构守卫(`NezhaIdorGuardTest`), git `pre-push` 门红则拒推(应急 `--no-verify` 自负风险)。**改 L1 机制时这些断言会随之红——按上面流程取得批准后, 必须同步更新断言**使其反映新的红线态, 而不是删断言绕过。

---

## 附：本地生活/入驻「硬禁业务」机制定级〔2026-06-21〕

「哪些业务坚决不能上线」已落成机制，定级如下（均为**强化**合规、不触 L1）：

- 🟡 **L2 业务参数**：违禁词库 `business_settings.locallife_banned_words`（后台可增删，命中即拒）。改动只影响新发布内容、不回溯；改动留痕告知用户即可。
- 🟢 **L3 实现/机制载体**：
  - `local_life_categories.compliance_level` 三级（0 可上 / 1 需牌照人工审 / 2 硬禁）；等级 2 强制不上线、前端不渲染、不可启用。
  - 共享筛查器 `app/CentralLogics/NezhaContentScreen.php`（UGC 发帖 + 商家录入共用）。
- ⚠️ 与 L1 的关系：本机制**只拦内容/类目，不碰资金**，不改退款（L1-2/3）、制裁筛查（L1-6）、留存（L1-4）等既有红线机制。属于在 L1「平台不碰钱」之上对**业务准入**的额外护栏。


### L1-7 附注：违规帖「证据冻结」(legal hold) 有限例外〔2026-06-21〕

L1-7 要求 PII 到期删除。本地生活违规帖证据冻结是其**有限例外**(已批准)：运营对判定违规/需配合执法的帖置 `local_life_posts.legal_hold=1` → 豁免 30 天到期清除(`nezha:purge-locallife-pii` 跳过)，供留证。**边界**：仅运营人工设/解(非举报自动触发，防滥用与过度留存)；目的限违规处理/配合主管机关调查；用尽目的应解除冻结。用户协议 §5.3 已同步加例外条款使政策与行为一致。改动此机制(扩大冻结触发面/延长留存)仍需按 L1 流程取得批准。


---

## 附: 数据完整性墙 (L3 · task9 · 2026-07-01 上线 a05ce34)

给 StackFood 松字段加的结构性约束(定级 L3 表结构)。因 MySQL 5.7 静默忽略 CHECK + App 连接 strict=false(config/database.php:59) 非严格模式静默 coerce, 真正能"拒绝"坏数据的只有**外键 + 触发器 SIGNAL**。看到下列拒绝是**预期、不是 bug**:

- **价格≥0 触发器**(`nz_*_price_bi/bu`, 10 个): food/add_ons/variation_options/item_campaigns 的 price·tax·discount + order_details 的 price·tax_amount·total_add_on_price≥0 且 quantity≥1。负值写入 → `SIGNAL 45000`。未含 orders 聚合金额(adjusment 合法可负)与钱包余额流水(可负)。
- **外键防孤儿**(ON DELETE RESTRICT): order_details.order_id→orders(`nz_fk_od_order`) / food.restaurant_id→restaurants(`nz_fk_food_restaurant`) / orders.restaurant_id→restaurants(`nz_fk_orders_restaurant`)。孤儿写入 → 1452; 删被引用父行 → 1451。
  - 🔺 **删餐厅**: 有订单/菜品的店不能删(FK 挡, 顺带保护 **L1-4 订单留存** 不被删店误删)。`VendorController::destroy` 已配套友好拦截(有订单历史→提示不可删; 无订单→先清菜品再删)。
- **状态非空白触发器**(`nz_orders_status_bi/bu`): orders.order_status/payment_status 不可空白 ''。完整状态词表合法性交**应用层 in: 校验**(order_status 跨 6+ 端点动态写入, 含易漏的 accepted/paused, 严格 DB 词表墙经审计判高破坏风险已弃)。
- **food.name NOT NULL**。

迁移 `database/migrations/2026_07_02_0100~0103_nezha_*.php`。全程 staging(nezha_staging)验证下单不破 + 每墙可回滚。改这些墙前先想清与下单/删店/改单流程的交互。详见 memory nezha-data-integrity-walls。
