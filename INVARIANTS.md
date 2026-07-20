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
| L1-4 | **链上交易记录留存 ≥ 5 年**(tx hash/来源地址/币种/金额/订单号) | ✅已实施②:nezha_refund_records 表(原始/退款 tx hash、锁定地址、链、金额、校验结果、订单号),免于PII自动清除 | 反洗钱法定留存义务 |
| L1-5 | **二清打款腿已拔除,不可恢复**:RestaurantDisbursement 已禁用,直付订单不记 total_earning | RestaurantDisbursementController、OrderLogic | 恢复 = 退回二清结构 |
| L1-6 | **制裁名单筛查命中即拒收**:付款来源地址命中 OFAC SDN/黑名单 → 拒绝并记录 | ✅已实施②:USDT「确认收款」时反查付款 tx 的 from 地址(复用 NezhaRefundControl 链上设施)→ 比对 nezha_sanction_addresses(OFAC SDN 数字货币地址)→ 命中即拒收(deny + 抛 SanctionScreenException, 订单不放行出餐)+ 写 nezha_risk_records(rule=sanction)。名单由 `nezha:sync-sanction-list` 每日 04:30 自动刷新(失败保留旧名单)。开关 nezha_sanction_screen_status(默认 1 开)。NezhaSanctionScreen。〔2026-06-22 阶段1扩展〕同一红线已扩到**商家入驻 KYC 姓名筛查**: 录入/自注册时对法人(legal_name)+受益人(beneficial_owner_name)比对 OFAC SDN **人名**名单(nezha_sanction_names, NezhaKycScreen::screen_name)→ 规范化精确=hit(写风控 action=reject/status=auto)、token 近似=possible(转人工风控队列 action=review/status=pending)。开关 nezha_kyc_sanction_screen_status(默认1开)。局限: 仅精确+词重叠、漏音译变体, 属入驻初筛非完备制裁合规(详见 CHANGELOG 2026-06-22)。 | 与受制裁主体交易 = 重大法律风险 |
| L1-7 | **PII 与支付凭证加密存储 + 到期删除**:用户地址/联系方式/支付截图加密,支付截图默认保留期到期自动删 | ✅已实施③:MySQL表空间加密(全表)+支付凭证90天自动删+每日加密备份;keyring=`/www/server/mysql-keyring/`。〔2026-06-22 KYC 留存例外〕**商家 KYC 资料**(vendor_kyc_profiles: 法人姓名/证件号/收款账户/联系方式)为 AML/CDD 核验记录——模型层 encrypted cast + 表 ENCRYPTION='Y'(加密符 L1-7), 但作为反洗钱法定记录须**留存 ≥5 年、明确豁免 PII 自动清除**(同 L1-4 的 nezha_refund_records); 全库三个 purge 任务均不碰该表(2026-06-22 KYC 专项已核实, 代码亦无自动删 KYC 路径); 默认不上传证件扫描件以降 PII 负债; closed_at 仅为留存倒计时锚点, 当前无定时任务消费(≥5年是下限, 不自动删)。 | 数据保护法(PDPA/GDPR)义务 |
| L1-8 | **押金持有 + 商家退出结算退还**(待·组④⑤设计中):①押金**法币-only**、平台不持任何加密资产(不收 USDT);②退还只退回**缴纳主体本人 KYC 核验账户**、非第三方,退前核对 legal_name==bank_account 户名==缴纳凭证付款人;③**退款给商家前制裁核验未命中**(审批放款时用【当前】OFAC SDN 名单**实时 RE-run screen_names**、**不读入驻旧 screen_status 列**——名单每日刷新、旧列会空转;命中=拒+转人工 AML,疑似/未决=fail-closed 转人工,呼应 L1-6);④三账户合并结算时**未结佣金/罚款只从 deposit_balance 抵**,ad_balance/guarantee_balance 各自独立全额退给商家、不跨账户挪用(守 ad 资金隔离);⑤押金流水/结算记录留存 ≥5 年、免 PII 清除(同 L1-4) | ✅ step4-4/step5 已实装(未部署·dormant): NezhaOffboard::rescreenSanctions(§D1 实时 re-screen·fail-closed)+approve() 4 门(status/冷静期/re-screen/holder_verified)+暴露层(商家申请·超管审批);开关 nezha_offboard_status 默认 0(服务端强制)。正本 `docs/PLAN_merchant_offboard.md` §4/§8 · `docs/DESIGN_merchant_offboard.md`。〔2026-07-03 追加**中途退回押金(营业中·A3 S3-B)**: `NezhaGuaranteeRefund`(运营核算制·G0-G6 逐门·CJK-safe 户名核对+身份指纹 kyc_apply_fp·钱包行锁+C4 快照·每笔制裁复筛不分金额·用 is_deposit_credit_frozen 覆盖 owing·uq_active_refund 结构墙); 开关 `nezha_topup_refund_status` 默认 0 dormant; /debate 三路红队+26/0 逐门反例硬化, 存 `docs/topup_refund_debate_archive.md`〕 | 持有并退还商家资金:退第三方=洗钱通道;向受制裁主体付款=重大法律风险;平台持币=触外汇/支付牌照 + 突破"平台不碰钱"基石 |
| L1-9 | **平台不出资促销(账务定性)**:店铺折扣/多级满减=商家自掏,记账 100% 归 vendor(discount_on_product_by=vendor·expenseCreate created_by=vendor·amount_admin=0),平台不记补贴、不减净利;佣金按商家实收(减后额)计,平台因让利"少收"D×佣金率=让利分摊非平台支出 | ✅已实施(2026-07-02):OrderController place_order(满减命中→vendor) + OrderLogic create_transaction(vendor 分支 amount_admin=0·已删 admin discount_on_product 拆分行);多级满减灰度关不影响·现有 POS 商家折扣即时生效 | 把商家自掏促销记成平台补贴/admin 出资=虚构"平台贴钱", 与 L1-1"平台不碰钱不补贴"对外陈述冲突, 误导监管/银行/商家对账(账务定性红线) |

> "(待)"= 机制已规划但尚未实装(见对应组别)。实装时这些红线即生效,实装前不得以"简化"为由跳过。

### 直收支付 V2 超时迟付的 L1-2/L1-3 限域例外（2026-07-19 已批准，未上线）

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
