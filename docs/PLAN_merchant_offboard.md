# PLAN — 押金账户 + 商家退出结算/退还（组④⑤）

> **状态**：设计草案（2026-07-01 起草）。政策已由平台负责人（用户）拍板；合规裁定已核实 INVARIANTS L1。
> **下一步**：新窗口跑 `/debate` 红队核验本方案 → 用户批准 → 记 `INVARIANTS.md` + `docs/compliance/CHANGELOG.md` → 才建代码。
> 🔴 **L1 邻近**：本方案新增「平台持有商家押金 + 平台退钱给商家」两条资金路径。**未经 /debate + 用户明确批准 + 记 INVARIANTS 前，不得写实现代码。**
> **前置**：对账中心（Phase 1）已上线（commit `2938dc0`）。本方案往其**灰置的押金 Tab**接。起草背景见 memory `project_nezha-merchant-accounts-reconciliation-refund`。

---

## 0. 为什么做
平台代持商家三类钱（预存佣金余额 + 广告余额 + 押金），却没有一个统一的「商家退出→结清并退还」出口，也没有押金账户。缺口 = 运营断点 + 公平/合规隐患（持有商家的钱却无制度化归还路径）。

## 1. 账户架构现状（已核实，见对账中心）
| 账户 | 存哪 | 进(+) | 出(−) |
|---|---|---|---|
| 预存佣金/保证金 | `restaurant_wallets.deposit_balance` | `recharge` | `commission_deduction` · `refund_reversal` · `advertisement_fee`(CPT) |
| 广告 | `restaurant_wallets.ad_balance` | `ad_recharge` | `ad_click_fee` |
| **押金（本方案新增）** | `restaurant_wallets.guarantee_balance`(待建) | `guarantee_deposit` | `guarantee_refund` |

留痕都写同一张 `restaurant_deposit_transactions`，靠 `type` 分账户。退出结算的三笔退还建议新 type：`deposit_refund` / `ad_refund` / `guarantee_refund`（金额为负，balance→0）。**以上 type 命名为提案，/debate 定稿。**

---

## 2. 押金账户（④）— 已拍板政策

**收多少**
- 普通商家 **500–1000 元人民币**。
- 单日营业额确实很高的商家可上浮到 **5000**，以覆盖风险。
- 档位按商家单日订单额实际情况定。

**什么时候收 / 谁豁免**
- 平台尚未正式启动。**前期主动筛选**、明显正常运营/正经做生意的商家：**入驻豁免、不收**。
- **商家自己主动申请入驻**的：**收押金**——防"上线收钱不发货、坑骗顾客"。

**存哪 / 收取动作**
- 建议 `restaurant_wallets` 加 `guarantee_balance decimal(24,2) default 0`（与另两账户一致，`anchoredBounds` 可复用）。
- 商家线下把押金转平台 → 超管后台记账入 `guarantee_balance`（对称于预存佣金 `store_recharge`）。
- USDT 缴的押金：走链上核验到账（复用 `NezhaRefundControl` 设施），并**单独记原币种/地址**（见 §4.3）。

**⚠️ 待 /debate/用户细化**
- "主动申请"的判定标准（避免运营随意）。
- 5000 档的触发口径（"单日营业额"取哪个字段/谁评估/多久复核）。

---

## 3. 退出结算 / 退还（⑤）— 已拍板政策

**触发入口**（推荐：后台入口，非纯邮件）
- 在「对账中心」页底部放**不显眼的「申请退出结算」入口**（避免误触）。理由：有状态机 + 自动留痕 + 结算预览 + 审批工单（邮件都没有）。

**前置硬校验**（任一不满足即挡住并说明原因）
1. **所有订单处于终态**（已送达/已取消/已退款，无 limbo 脏单；复用数据完整性状态词表）。
2. **无进行中纠纷**（退款/举报/风控 pending）。
3. **满 20 天冷静期**（从申请日算）。

**结算口径**
- 一次性总额算清，**不可部分退**（繁琐、多一步评估，用户已否决）。
- 抵扣顺序：**先扣未结佣金/罚款**。
- 应退净额 = 押金 + 预存佣金余额 + 广告余额 − 未结佣金/罚款。
- **净额 >0**：退给商家。**净额 <0**：不退，标记"欠平台 X"，走**人工追缴（不自动）**；这种基本=商家非正常经营，本身是风控信号，退出结算预览直接挡住干净退出。

**退到哪 / 币种两条腿**
- **法币缴的部分** → 商家 **KYC 核验的收款账户** `vendor_kyc_profiles.bank_account`（银行卡/支付宝）。
- **USDT 缴的部分** → 商家 **KYC USDT 地址（原路）**，USDT 形态，复用 `NezhaRefundControl` 链上校验；**币本位退**（收几个 U、扣抵后退几个 U）。
- 🔴 禁止：USDT 换成人民币提现到中国 / 退到任何第三方账户。

**到账 / 审批**
- 符合条件**当日转**。**超管（目前=平台负责人一人）审批**，**强二次确认防误批**（动真钱）。
- 打款主体 = 平台线下转账（B2B，平台履行义务），系统只记录 + 留痕 + 标记商家 offboarded（对称于充值：充值是商家转平台记账，退还是平台转商家记账）。

---

## 4. 合规裁定（本会话已核实 `INVARIANTS.md` L1）

**4.1 退押金 ≠ 二清**（核实 L1-1 / L1-5）
二清构成要件 = 归集**顾客**资金 + 再分发。押金是商家**自有** B2B 预付、退给**同一主体** → 归集他人资金 ❌、再分发第三方 ❌ → **不构成二清**。与 L1-1/L1-5「归集顾客钱再分发」的二清结构不沾边。

**4.2「原路+原额」不适用押金 → 精确改写**
L1-2 字面（金额≤原订单、原支付方式）是**顾客订单退款**场景。押金退还是**净额**（扣抵后 ≠ 原额）、时间久，字面"原路原额"不现实。L1-2 的**内核**是反洗钱（钱回付款人本人、不进第三方）。押金退还红线精确化为：
> **退回缴纳主体本人 + KYC 核验账户 + 非第三方 + 全程留痕（≥5 年）。**

用户方案（退 KYC 卡）满足内核。

**4.3 USDT（L1-3 不松）**
平台**可以收** USDT 作 B2B 押金/预付（平台是收款交易对手、非归集顾客钱的中间人 → 非二清）。**但那部分退还只能以 USDT 退回商家 KYC 的 USDT 地址、不可换人民币提现到中国**。L1-3 卡的是"换汇 + 落地中国"，与金额无关。业务点：USDT 押金**币本位**退（平台不担汇率），平台需自有 USDT 收款地址 + 托管（运营考量，非红线）。

**4.4 建代码上线时须做**
- `INVARIANTS.md` 新增 L1 条目（押金持有 + 退还：同主体/KYC账户/非第三方/USDT原址/留存≥5年）+ 记 `docs/compliance/CHANGELOG.md`。
- 更新 `tests/Feature/NezhaL1RedlineTest.php` 断言（`composer test:redlines` pre-push 门）。

---

## 5. 接入对账中心（打通接口，从 HEAD ≥ `2938dc0`）
- `Vendor/NezhaDepositController::ACCOUNTS` 加 `'guarantee' => ['guarantee_deposit','guarantee_refund']`；`index()`/`export()` 的 `currentBalance` 加押金分支（读 `guarantee_balance`）。
- `index.blade.php`：押金 disabled `<span>` → 链接；总览押金卡填余额。
- `$typeLabels`（index.blade）+ 导出视图 `file-exports/nezha-reconciliation.blade.php` 的 `$labels` 补新 type 中文标签。
- `anchoredBounds` 照传 `currentBalance` 即可（已按当前余额回推）。

## 6. /debate 红队靶子（要压的点）
- **资金安全**：净额计算竞态（并发"退出申请 + 新订单/新扣佣"）、抵扣顺序、负数追缴闭环。
- **原路约束**：USDT 退款腿地址锁死、防退第三方、无换汇出口（复用 L1-3 现有断言）。
- **防薅**：退出→重新入驻循环薅"豁免押金"？押金收取时机被绕？
- **KYC 依赖**：`bank_account` 是单个 free-text（户名+账号/支付宝/USDT 地址混存），退款执行靠超管人工读——够不够？要不要拆结构化字段 + 二次核验。
- **审批**：单人审批防误批、审批留痕、可否事后审计。
- **边界口径**："无纠纷""订单终态"判定、20 天冷静期起算点。
- **L1-1 边界**：论证"平台退商家钱 ≠ 碰顾客钱"（B2B 履约 vs 归集顾客货款），别被自动断言误伤。

## 7. 开放问题（待用户后续拍板）
- "主动申请"判定标准 / 押金档位口径 / 复核频率。
- 押金存 `wallet` 列 vs 独立表。
- `bank_account` 是否拆结构化 + 币种字段。
- KYC 手册（目前不在 git）是否补进 `docs/legal/` 或 `docs/compliance/`。

---
*相关：对账中心 Phase 1（`2938dc0`）· `INVARIANTS.md` L1-1~L1-7 · `app/CentralLogics/NezhaRefundControl.php`（USDT 原路设施）· `vendor_kyc_profiles`（`bank_account` 已存在）· memory `project_nezha-merchant-accounts-reconciliation-refund`。*
