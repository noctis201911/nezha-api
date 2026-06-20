# 哪吒外卖 — 核心机制不变量清单 (INVARIANTS)

> 本文件定义平台各机制的**变更等级**。任何人(含 AI 助手/新开的工作窗口)在改动前必须先查本表确认等级。
> 这是防止"后续窗口无意中改坏合规红线"的评级系统。完整背景见 `docs/compliance/`。

## 变更等级定义

| 等级 | 含义 | 改动规则 |
|---|---|---|
| 🔴 **L1 合规红线** | 改动 = 法律/合规风险(反洗钱、二清、外汇、数据保护) | **禁止擅自改动。必须先向平台负责人(用户)说明并取得明确批准,改后记入 `docs/compliance/CHANGELOG.md`** |
| 🟡 **L2 业务参数/规则** | 可调,但影响业务行为或顾客体验 | 可改,但须**留痕**(git commit 写清原因)并在下次同步告知用户;阈值类优先用后台设置项调,不改代码 |
| 🟢 **L3 实现细节** | UI 文案、表结构、代码组织、性能优化 | 自由改,正常 commit 即可 |

> 📍 **USDT 链上到账核验（2026-06-21）= 🟢 L3**：顾客填交易哈希后，系统只读查询公链浏览器（Tronscan/BscScan）核对收款地址=商家、币种=USDT、金额≥应付、区块已确认，结果仅作商家「确认收款」的**辅助判断展示**。**只读、不移动资金、不触发自动放款或自动确认收款**（仍由商家人工确认），不归集、不经手顾客资金，不构成代收/二清，与 L1-1（平台不碰钱）无冲突。法币（支付宝）实付金额比对与凭证图片体检均为**软提示、永不阻断下单**。

## 🔴 L1 合规红线清单 (改动必须用户批准)

| # | 不变量 | 所在 | 为什么是红线 |
|---|---|---|---|
| L1-1 | **平台全程不碰资金**:顾客的钱直付商家本人账户,平台不归集、不代收代付 | OrderLogic 直付分支、收款面板 | 平台归集再分发 = 二清(无证经营支付结算),违法 |
| L1-2 | **退款只允许原路退回**:原支付方式/原卡/原钱包/原USDT地址,金额≤原订单,**禁止退到任何第三方账户** | ✅已实施②:Admin/OrderController 退款分支 + NezhaRefundControl::lock_route/check_limits + OrderLogic::refund_order;USDT原路反查锁定、法币政策+凭证、金额强制≤原单;留痕 nezha_refund_records。开关 nezha_refund_control_status(默认关) | 退到第三方 = 洗钱通道,帮信罪风险 |
| L1-3 | **USDT 只可退回原始付款钱包地址**,系统不提供"USDT兑人民币/提现到中国账户"任何入口;USDT余额仅境外使用 | ✅已实施②:NezhaRefundControl 退款目标=原始tx反查的from地址(锁死,后台无自由填地址入口),退款tx链上校验金额+地址;无任何换汇/提现入口 | 跨境换汇/提现 = 非法经营、外汇违规 |
| L1-4 | **链上交易记录留存 ≥ 5 年**(tx hash/来源地址/币种/金额/订单号) | ✅已实施②:nezha_refund_records 表(原始/退款 tx hash、锁定地址、链、金额、校验结果、订单号),免于PII自动清除 | 反洗钱法定留存义务 |
| L1-5 | **二清打款腿已拔除,不可恢复**:RestaurantDisbursement 已禁用,直付订单不记 total_earning | RestaurantDisbursementController、OrderLogic | 恢复 = 退回二清结构 |
| L1-6 | **制裁名单筛查命中即拒收**:付款来源地址命中 OFAC SDN/黑名单 → 拒绝并记录 | ✅已实施②:USDT「确认收款」时反查付款 tx 的 from 地址(复用 NezhaRefundControl 链上设施)→ 比对 nezha_sanction_addresses(OFAC SDN 数字货币地址)→ 命中即拒收(deny + 抛 SanctionScreenException, 订单不放行出餐)+ 写 nezha_risk_records(rule=sanction)。名单由 `nezha:sync-sanction-list` 每日 04:30 自动刷新(失败保留旧名单)。开关 nezha_sanction_screen_status(默认 1 开)。NezhaSanctionScreen | 与受制裁主体交易 = 重大法律风险 |
| L1-7 | **PII 与支付凭证加密存储 + 到期删除**:用户地址/联系方式/支付截图加密,支付截图默认保留期到期自动删 | ✅已实施③:MySQL表空间加密(全表)+支付凭证90天自动删+每日加密备份;keyring=`/www/server/mysql-keyring/` | 数据保护法(PDPA/GDPR)义务 |

> "(待)"= 机制已规划但尚未实装(见对应组别)。实装时这些红线即生效,实装前不得以"简化"为由跳过。

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

## 改动 L1 的标准流程
1. 在工作窗口中**停下**,向用户说明:要改哪条 L1、为什么、影响什么。
2. 取得用户**明确批准**后再动。
3. 改完在 `docs/compliance/CHANGELOG.md` 记一笔(日期/改了什么/批准人/原因)。
4. git commit 写清"L1变更:..."。

相关: `docs/compliance/AML-policy.md` · `docs/compliance/business-flow.md` · `docs/compliance/data-protection.md`
