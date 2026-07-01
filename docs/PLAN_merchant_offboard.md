# PLAN — 押金账户 + 商家退出结算/退还（组④⑤）

> **状态**：设计草案。政策已拍板；合规裁定已核实 INVARIANTS L1；§7 开放问题已细化定稿；**/debate 三路红队已跑，verdict 全部 🔴 阻断上线（见 §8）**。（均 2026-07-01）
> 🔴 **当前结论：不进代码**。✅ L1 决策已批准并记入 `INVARIANTS.md`(新增 **L1-8**) + `CHANGELOG`(commit `926d388`)。**剩余门槛**：5 个阻断项（§8.2 A–E）的工程设计硬化未完成前，**不得写实现代码**。
> 🔴 **L1 邻近**：本方案新增「平台持有商家押金 + 平台退钱给商家」资金路径。**押金已定 法币-only、平台不持 USDT**（§4.3、§8.5）。
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

> ⚠️ 押金缴纳/退还的流水行还须记**原币种（现金 CNY）+ 原额 + 回执凭据**——现表 `amount` 仅 AMD 折算单值。故 `restaurant_deposit_transactions` 补 `currency`/`original_amount`/`original_ref`(法币转账回执号，L1-4 留痕) 三列（详见 §5 与 §7-④）。〔押金法币-only 后不再有 USDT 币本位原址退，三列改服务于法币缴纳记录+回执留痕。〕

---

## 2. 押金账户（④）— 已拍板政策

**收多少 / 怎么定档**（§7-① 定稿：超管手动设档，不建自动单量公式）
- 档位枚举：**豁免 / 500 / 1000 / 5000 元人民币**（普通 500–1000；单日单量确实很高的可上浮到 5000 覆盖风险）。
- onboarding（后台建店 / 审批自助注册）当刻**超管手动选一档**。**不设自动单量公式**——新店无历史单量、平台未启动，自动算档为时过早。
- 默认「后台建店=豁免、自助注册=需缴」**仅作超管参考、不落硬规则**（保留自由裁量）。

**什么时候收 / 谁豁免**
- 平台尚未正式启动。**前期主动筛选**、明显正常运营/正经做生意的商家：**入驻豁免、不收**。
- **商家自己主动申请入驻**的：**收押金**——防"上线收钱不发货、坑骗顾客"。
- 系统落点（§7-①）：「主动申请」= 商家走**自助注册**（`toggle_restaurant_registration` 开关，现默认关；`VendorLoginController` → 超管审批 approval/deny）；「平台主动筛选」= 超管**后台直接建店**（`VendorController@store`）。**不新造标志**——超管在 onboarding/审批当刻按来路手动设档即可。近期自助注册未开 → 全为后台建店 → 按默认全豁免，押金随自助注册启用才实际起收。
- 🔴 **debate 修正（§8.2-D）**：「按来路手动设档」依赖一个**不存在的记忆载体**——现有 `register`/`store` 都不落来路。必须落不可变 `restaurants.onboard_source`，否则退出→换号重入驻可薅豁免押金。

**存哪 / 收取动作**（押金 **法币-only**，§4.3 / §8.5）
- `restaurant_wallets` 加 `guarantee_balance decimal(24,2) default 0`（与另两账户一致，`anchoredBounds` 可复用；§7-④）。
- 押金**只收人民币现金/银行转账**，**不收 USDT**（平台不持币）。商家线下把押金转平台 → 超管后台记账入 `guarantee_balance`（对称于预存佣金 `store_recharge`），**留缴纳凭证/付款主体**入 `original_ref`（退款时核对同一主体，§8.3）。

---

## 3. 退出结算 / 退还（⑤）— 已拍板政策

**触发入口**（推荐：后台入口，非纯邮件）
- 在「对账中心」页底部放**不显眼的「申请退出结算」入口**（避免误触）。理由：有状态机 + 自动留痕 + 结算预览 + 审批工单（邮件都没有）。

**前置硬校验**（任一不满足即挡住并说明原因）
1. **所有订单处于终态**（已送达/已取消/已退款，无 limbo 脏单；复用数据完整性状态词表）。
2. **无进行中纠纷**（退款/举报/风控 pending）。→ 🔴 **debate 修正（§8.2-E）**：改为「无**经甄别的真实相关** pending 纠纷」+ 超管「标恶意/驳回」动作；否则竞对一条退款/举报即钉死商家退出（押金人质）。
3. **满 20 天冷静期**（从申请日算）。→ 🔴 申请后新增纠纷走人工、不自动重置已进结算的退出（§8.2-E）。
4. 🔴 **新增（§8.2-C，L1-6）**：**KYC 制裁筛查未命中**（`screen_status ∉ {hit,possible}`），命中一律挡、转人工 AML。【L1 变更·需你批】

**结算口径**
- 一次性总额算清，**不可部分退**（繁琐、多一步评估，用户已否决）。
- 抵扣顺序：**先扣未结佣金/罚款**，且 🔴 **只从 `deposit_balance` 抵**（ad/guarantee 各自独立全额退，守 INV-1，§8.3）。
- 应退净额 = 押金 + 预存佣金余额 + 广告余额 − 未结佣金/罚款。
- **净额 >0**：退给商家。**净额 <0**：不退，标记"欠平台 X"（落 `shortfall_amount`+`offboard_status='owing'`，阻断重入驻豁免），走**人工追缴（不自动）**；这种基本=商家非正常经营，本身是风控信号，退出结算预览直接挡住干净退出。

**退到哪**（押金 法币-only，单条腿）
- → 商家 **KYC 核验的收款账户** `vendor_kyc_profiles.bank_account`（银行卡/支付宝；维持单 free-text，超管人工转账）。
- 🔴 **退款执行前必核**（§8.3）：`KYC legal_name == bank_account 户名 == 缴纳凭证付款人` 三者一致，不一致即挡；转账后回执入 `original_ref`/note 留痕（L1-4）。**退回缴纳主体本人、非第三方**（L1-2 内核）。
- 🔴 禁止：退到任何第三方账户。

**到账 / 审批**
- 符合条件**当日转**。**超管（目前=平台负责人一人）审批**。→ 🔴 **debate 修正（§8.3）**：单人「强二次确认」只防误触、不防被诱导/盗号；动真钱须加**异步二次闸**（工单→冷却→执行；高档强次日转 + 邮件/TG 二次确认链接）。
- 打款主体 = 平台线下转账（B2B，平台履行义务），系统只记录 + 留痕 + 标记商家 offboarded（对称于充值：充值是商家转平台记账，退还是平台转商家记账）。**须幂等**：`restaurant_offboard_settlements` vendor_id 唯一约束防重复退（§8.2-B）。

---

## 4. 合规裁定（本会话已核实 `INVARIANTS.md` L1）

**4.1 退押金 ≠ 二清**（核实 L1-1 / L1-5）
二清构成要件 = 归集**顾客**资金 + 再分发。押金是商家**自有** B2B 预付、退给**同一主体** → 归集他人资金 ❌、再分发第三方 ❌ → **不构成二清**。与 L1-1/L1-5「归集顾客钱再分发」的二清结构不沾边。
> ⚠️ debate 补正（§8.3）：本裁定「再分发第三方 ❌」是**受 §3 原路约束保护的结论**、非无条件——法币腿一旦转错第三方即破。故户名一致性核对是这条裁定的落地前提。

**4.2「原路+原额」不适用押金 → 精确改写**
L1-2 字面（金额≤原订单、原支付方式）是**顾客订单退款**场景。押金退还是**净额**（扣抵后 ≠ 原额）、时间久，字面"原路原额"不现实。L1-2 的**内核**是反洗钱（钱回付款人本人、不进第三方）。押金退还红线精确化为：
> **退回缴纳主体本人 + KYC 核验账户 + 非第三方 + 全程留痕（≥5 年）。**

用户方案（退 KYC 卡）满足内核。

**4.3 押金 法币-only —— 平台不持 USDT**（2026-07-01 debate 后定，§8.5）
合规红队指出：平台自建 USDT 地址托管押金 = 平台**首次持有加密资产余额**，实质突破整套设计的**「平台不持币」合规基石**（顾客 USDT 是直付商家、平台从不碰）。为从源头规避（外汇/支付牌照/资金池风险），**押金只收人民币现金/银行、不收 USDT**。据此**删除**原「平台可收 USDT 押金 + 自有 USDT 地址托管」设计。
> 注：这与 L1-3「顾客退款 USDT 只退原址」不冲突——那是**顾客**退款场景、平台只读链上不持币；押金是另一码事，法币-only 后押金侧无 USDT。

**4.4 建代码上线时须做（含 debate 后新增，🔴=需你先批的 L1 变更）**
- 🔴 `INVARIANTS.md` 新增 L1 条目：①押金持有+退还（法币-only/同主体/KYC账户/非第三方/留存≥5年）；②**制裁筛查未命中**为退出结算硬门（§8.2-C，L1-6）；③退出结算为 **INV-1 受控豁免**（三账户合并退，但抵扣只从 deposit，§8.3）。+ 记 `docs/compliance/CHANGELOG.md`。
- 更新 `tests/Feature/NezhaL1RedlineTest.php` 断言（`composer test:redlines` pre-push 门）：加制裁门 + 法币-only（无 USDT 押金腿）。

---

## 5. 接入对账中心（打通接口，从 HEAD ≥ `2938dc0`）
**新增 schema（迁移；法币-only 后更新）**
- `restaurant_wallets` 加 `guarantee_balance decimal(24,2) default 0`（同构 `deposit_balance`/`ad_balance`；照 `2026_06_10_120000` / `2026_07_02_000100` 两迁移的 `hasColumn` 守卫加列写法）。
- `restaurant_deposit_transactions` 补三列：`currency`（CNY/AMD）+ `original_amount decimal(24,2)` + `original_ref`（**法币转账回执号 / 缴纳凭证**，加 encrypted cast，§8.3）。现表 `amount` 仅 AMD 折算单值；三列服务于**原始缴纳记录 + 回执留痕**（L1-4）；非押金 type 留空（有 `commission` 列只部分 type 填的先例）。
- 🔴 建 `restaurant_offboard_settlements` 表（§8.2-B）：`vendor_id` **唯一约束** + 状态机 `applied/approved/paid/failed` + `shortfall_amount`；从结构上根除重复退。
- 🔴 `restaurants` 加 `onboard_source` enum(`self_register`/`admin_create`) + `offboard_status`(`settling`/`owing`/`offboarded`)（§8.2-A/D/E 的冻结态与来路溯源）。
- ~~`vendor_kyc_profiles` 加 `usdt_address`/`usdt_chain`~~ **取消**（法币-only 无 USDT 押金腿；§7-② 作废）。`bank_account` 法币仍单 free-text 不动。

**代码接入**
- `Vendor/NezhaDepositController::ACCOUNTS` 加 `'guarantee' => ['guarantee_deposit','guarantee_refund']`；`index()`/`export()` 的 `currentBalance` 加押金分支（读 `guarantee_balance`）。→ 🔴 现为二元三目 `$account==='ad'?ad:deposit`（`:108`/`:150`），必须改**显式三分支** `match()`，否则 guarantee 静默落 deposit 分支串味（§8.3）。
- `index.blade.php`：押金 disabled `<span>` → 链接；总览押金卡填余额。
- `$typeLabels`（index.blade）+ 导出视图 `file-exports/nezha-reconciliation.blade.php` 的 `$labels` 补新 type 中英标签，grep 零残留 key。
- `anchoredBounds` 照传 `currentBalance` 即可（已按当前余额回推）；退还三笔 `balance_after` 精确写 0（§8.3 对账不断裂）。

## 6. /debate 红队靶子（要压的点 —— **已由 §8 回答**）
- **资金安全**：净额计算竞态（并发"退出申请 + 新订单/新扣佣"）、抵扣顺序、负数追缴闭环。→ §8.2-A/B、§8.3。
- **原路约束**：USDT 退款腿地址锁死、防退第三方、无换汇出口。→ 法币-only 后押金无 USDT 腿；法币腿走户名核对（§8.3）。
- **防薅**：退出→重新入驻循环薅"豁免押金"？押金收取时机被绕？→ §8.2-D。
- **KYC 依赖**：法币 `bank_account` 维持 free-text 超管人工转，法币腿会不会转错？→ §8.3（户名核对+回执+异步二次闸）。
- **审批**：单人审批防误批、审批留痕、可否事后审计。→ §8.3。
- **边界口径**："无纠纷""订单终态"判定、20 天冷静期起算点。→ §8.2-E。
- **L1-1 边界**：论证"平台退商家钱 ≠ 碰顾客钱"（B2B 履约 vs 归集顾客货款）。→ §8.4（站得住）。

## 7. 开放问题 —— 已细化定稿（2026-07-01，用户拍板）
- **① "主动申请"判定 + 押金档位口径** → **超管手动设档**。档位枚举 豁免/500/1000/5000 元；onboarding 时超管按入驻来路（自助注册 vs 后台建店）手动选一档，默认建店豁免·自助注册需缴（仅参考、不落硬规则）。**不建自动单量公式**（新店无单量数据、平台未启动）。〔debate 补：来路须落 `onboard_source` 字段，§8.2-D。〕
- **② `bank_account` 拆不拆** → ~~中间方案·仅拆 USDT 地址~~ **已作废**（debate 后押金定 法币-only、无 USDT 腿，§8.5）。`bank_account` 维持单 free-text；改由**退出结算审批页做户名一致性核对**（KYC legal_name==户名==缴纳凭证付款人，§8.3）守住 L1-2 内核。
- **③ 复核频率** → **人工按需**。不加定时任务；超管发现单量涨/风险变化时手动加缴或调档。前期店少够用。〔debate 补：加后台**被动红旗**（高单量却低档的店高亮，零定时成本），§8.3。〕
- **④ 押金存 wallet 列 vs 独立表** → **wallet 列 + 流水补 3 列**。`restaurant_wallets.guarantee_balance`（对账中心与 deposit/ad 统一读一列）；`restaurant_deposit_transactions` 补 `currency`/`original_amount`/`original_ref`（法币-only 后=原始缴纳记录+回执留痕）。理由：同构现有两账户、对账中心接入最省。〔注：**退出结算的状态机另立独立表** `restaurant_offboard_settlements`，§8.2-B——押金余额本身仍在 wallet 列。〕
- **⑤ KYC 手册归置** → **`docs/compliance/`**（与 `AML-manual-review-SOP.md`/`CHANGELOG.md`/L1 文档同目录；不为一份手册新开 `docs/legal/`）。

---

## 8. 红队核验结论（/debate · 2026-07-01 · 三路独立对抗核验）
> 三路红队（资金安全 / 合规 L1 / 防薅内控）独立核验，**verdict 全部 🔴 阻断上线**；各队逮到的阻断项**互不重叠**（单跑一路必漏三四个）。完整报告存本地 scratch `debate_1_fund_safety` / `debate_2_compliance` / `debate_3_abuse_control`。

### 8.1 元判断
政策与合规**推理扎实**（二清/USDT原址/原路三条主论证被两队独立确认站得住），但本方案是**"政策文档"非"资金机制设计"**——把锁/原子性/幂等/制裁门/来路溯源/退出门防滥用都留在 §6 当"待压点"未解决。

### 8.2 🔴 五个阻断项（写代码前必逐一解决）
- **A 净额竞态无冻结/无锁**（资金）：20 天冷静期内迟到 `commission_deduction`/`ad_click_fee`（不受订单终态约束）污染净额→多退少退；`balance_after` 并发覆盖→账实分叉。证据 `OrderLogic.php:278/739`、`AdvertisementController.php:176`（两套并发模型）。→ **解法**：退出结算单事务 + `SELECT…FOR UPDATE` 锁 wallet 行 + `offboard_status='settling'` 冻结态在扣费入口短路 + 读净额/置零/写流水同一锁同一事务。
- **B 三腿退还无原子性/无幂等**（资金）：法币成/USDT败中间态未定义；强二次确认仅 UI 层，双击/两窗口重复记账重复线下转。→ **解法**：建 `restaurant_offboard_settlements`（`vendor_id` 唯一约束 + 状态机）+ 三腿独立标记成功/失败、任一失败标 `partial` + 记账与「已确认到账」解耦。
- **C 制裁筛查前置门缺失（L1-6）**【🔴 L1 变更·需你批】（合规）：offboard 首次让平台可能主动向受制裁主体付款；现有 KYC 命中 SDN 仅 `Toastr::error` 不硬拦（`NezhaKycController.php:110`）。→ **解法**：§3 前置校验加第 4 条红线级 = `screen_status ∈ {hit,possible}` 一律挡 + 转人工 AML + 审批入口对 hit 禁用；§4 补 L1-6 裁定（被制裁主体押金冻结不退，退给谁/没收依据待律师，参 `AML-manual-review-SOP.md §8`）。
- **D 无入驻来路持久字段 → 退出重入驻薅豁免**（防薅）：`register`/`store` 都不写来路；换号即绕，押金对惯犯失效。→ **解法**：落不可变 `restaurants.onboard_source`（入驻当刻写死）+ 押金/退款历史按 KYC 法人身份跨 vendor 聚合（非仅 vendor_id）+ 重入驻命中历史 `closed_at` 红标。
- **E 退出前置门被外部人当武器（押金人质）**（防薅）：三门（订单终态/无纠纷/20 天）触发权全在商家之外；竞对用一条退款（`OrderController.php:1717` delivered→refund_requested 无时间窗）/举报/风控单可永久钉死任意商家退出。→ **解法**：门②改「无经甄别的真实相关 pending 纠纷」+ 超管「标恶意/驳回」动作；`refund_request` 对 delivered 单加送达后时间窗（复用 `nezha_appeal_window_hours`）；退出申请后新增纠纷走人工、不自动阻断已进结算的退出。

### 8.3 🟡 应修（实现期落地）
- **资金**：ad_balance 并入净额 vs INV-1 隔离 →【🔴 L1 变更·需你批】记 INVARIANTS 受控豁免 + **抵扣只从 deposit 扣**、ad/guarantee 各自独立全额退；负数追缴落持久字段（`shortfall_amount` + `offboard_status='owing'` 阻断重入驻豁免）。
- **合规**：法币腿**户名一致性核对**（KYC legal_name == bank_account 户名 == 缴纳凭证付款人）+ 回执留痕入 `original_ref`；`original_ref` 补 encrypted cast；§4 划清 vs L1-5「二清腿已拔」防审计误判。
- **防薅**：对账 `NezhaDepositController:108/150` 二元三目 → 显式 `match()` 三分支（否则 guarantee 吞进 deposit 串味，memory 记过的坑复发）+ `balance_after` 按账户各写各列 + 新 type 中英标签双补、grep 零残留；单人审批加**异步二次闸**（工单→冷却→执行，高档强次日转 + TG 二次确认）；手动设档写审计留痕 + 后台被动红旗（高单量却低档的店高亮，零定时成本）。

### 8.4 ✅ 站得住 / 别过度修（三队诚实结论）
二清定性（资金+合规双确认：商家自有 B2B 退同一主体，构成要件逐条不沾边）· USDT 原址锁/币本位/禁换汇（三队确认，指顾客退款侧）· 净额<0 处置对（fail-closed）· 举报接口本身防 IDOR/限频（冻结根因在退出门设计、不在举报接口）· `RestaurantWallet::save()` 不跨列覆盖 · 押金无直接改 DB 的 API。

### 8.5 政策更新（debate 后你拍板 2026-07-01）
**押金 法币-only**：只收人民币现金/银行、**不收 USDT**、平台不持任何加密资产。据此删除原 §4.3「平台自有 USDT 地址 + 托管」及 §5 `usdt_address`/`usdt_chain` 结构化字段；§7-② 的「仅拆 USDT 地址」作废。法币腿户名一致性由退出结算审批页核对（§8.3）。

### 8.6 下一步（debate 后）
1. ✅ **已完成**（2026-07-01）：§8.2-C 制裁门 + §8.3 资金隔离 + §8.5 法币-only 经业主批准，记入 `INVARIANTS.md`（新增 **L1-8**）+ `CHANGELOG`（commit `926d388`）。
2. 把 §8.2 五阻断 + §8.3 应修转成实现设计（结算状态机表 / 锁与冻结态 / 制裁门 / 来路字段 / 退出门甄别）——建议独立窗口做，可再跑一轮 debate 核验硬化设计。
3. 全部就绪才写代码。**在此之前本方案不进实现。**

---
*相关：对账中心 Phase 1（`2938dc0`）· `INVARIANTS.md` L1-1~L1-7 · `app/CentralLogics/NezhaRefundControl.php`（USDT 原路设施，顾客退款侧）· `vendor_kyc_profiles`（`bank_account` 已存在）· memory `project_nezha-merchant-accounts-reconciliation-refund` · debate 报告存本地 scratch `debate_1/2/3`。*
