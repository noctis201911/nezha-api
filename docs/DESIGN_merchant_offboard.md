# DESIGN v2 — 商家退出结算/押金账户 实现设计（第二轮 debate 已折叠）

> **版本**：v2（2026-07-01）。v1 经第二轮 /debate 三路红队核验（verdict 一致「需补后可实现」），本版折叠 5🔴 修法 + 9🟡 + 用户 3 决策（penalty 删 / 存量强制 KYC / 做 HMAC 指纹）。debate 结论见 §K（保留）。
> **配套**：政策正本 `docs/PLAN_merchant_offboard.md`（§8）；红线 `INVARIANTS.md` L1-8（`926d388`）。
> 🔴 **仍不进代码**：v2 引入新结构（结算状态机/冻结接缝/指纹列/KYC 前置），建议先过第三轮 debate 或用户直接批准再写代码。
> **代码锚（本会话核实）**：扣佣在 `OrderLogic::create_transaction`(:83，**订单完成时**扣，非下单时；:276 gated by `nezha_commission_active`+`is_direct_pay`，:278 `lockForUpdate`，:284 写 `commission_deduction` 流水) · 幂等先例 `settle_delivered:401`+`OrderTransaction::exists:416` · 接单闸 `nezha_store_paused:2318`(=`nezha_temp_closed || nezha_deposit_below_threshold:2307`，后者首行依赖 `nezha_commission_active`) · 制裁引擎 `NezhaKycScreen::screen_names:142`/`normalize_name:42` · KYC 审核 `NezhaKycController::save:69`(→pending)/`review:124`(→approved/rejected) · 对账 `Vendor/NezhaDepositController:108/:150` · 漏的 deposit 写路径 `refund_reversal OrderLogic:740`/`Admin/AdvertisementController:288`/`ChargeAdOnStart:90`/`store_recharge`.

## 0. 直付单退款武器已结构性失效（v1 保留）
`refund_request` 对 `offline_payment`(直付=B方案默认)单在 `OrderController.php:1709` 一律 410。哪吒真实订单=直付→delivered 稳定终态、顾客翻不动。退出门 E 的"退款人质"武器只对非直付单成立(哪吒当前无此类单)，E 真武器=举报+风控(§E2)。

---

## A. Schema（迁移 DDL · v2）
**A1 `restaurant_wallets`**：`guarantee_balance decimal(24,2) default 0`。
**A2 `restaurant_deposit_transactions`**（法币缴纳记录+回执留痕）：`currency varchar(8) default 'AMD'` + `original_amount decimal(24,2) null` + `original_ref text null`（模型 casts 加 `'original_ref'=>'encrypted'`）。🔴 **留存**：本表属 AML 法定留存，迁移注释 + INVARIANTS L1-8⑤ 明写「任何现有/未来 purge 任务不得清除本表」，加结构守卫测试（对齐 KYC 的显式豁免，堵 K.2-留存）。
**A3 `restaurants`**：`onboard_source enum('self_register','admin_create','unknown') default 'unknown'`（**存量 7 店回填 `unknown`**，新入驻写真值，不可变）+ `offboard_status enum('active','settling','owing','offboarded') default 'active'`。
**A4 `vendor_kyc_profiles`**：+ `account_holder_name text null`（模型 `encrypted` cast；把户名从 free-text `bank_account` 拆出结构化，供户名一致性 enforce，堵 K.2-户名）+ `id_doc_fingerprint varchar(64) null`（HMAC hex，明文可索引，加普通 index；见 §E）。
**A5 新表 `restaurant_offboard_settlements`**：
```
id, vendor_id, restaurant_id,
active_uniq  tinyint null,                    -- 活跃时=1, 关闭(rejected/failed/paid)时置 NULL
UNIQUE KEY uq_active (vendor_id, active_uniq),-- MySQL 5.7 NULL 视为相异→同 vendor 仅一条 active_uniq=1(幂等根),多条已关闭 NULL 并存(可重申)
status enum('applied','kyc_pending','approved','rejected','paying','paid','partial','failed') default 'applied',
applied_at, cooldown_until,                    -- 冷静期 applied 当刻锚定, 撤回重提不重置
kyc_gate_passed bool default 0, sanction_rescreen_at,  -- KYC/制裁门留痕
holder_verified bool default 0,                -- 户名逐字核对留痕(超管勾选)
guarantee_amt, deposit_amt, ad_amt,            -- approved 当刻锁定的三账户快照
net_amount, shortfall_amount,
leg_deposit_paid bool default 0, leg_ad_paid bool default 0, leg_guarantee_paid bool default 0,
approved_by, approved_at, payout_ref, note, timestamps
```
> `UNIQUE(vendor_id, active_uniq)` 用 NULL 相异特性做"仅一条活跃"部分唯一(替代 v1 的 `UNIQUE(vendor_id)`，堵 K.2-重试死角：rejected/failed 置 active_uniq=NULL 后可重新申请)。

---

## B. 退出结算状态机（v2 · 阻断 B 原子性/幂等 + K-D KYC 前置）
```
[对账中心底部「申请退出结算」]
   │ 前置硬门(§E2)全过
   ▼
 (无 approved KYC?)──是──► kyc_pending ──(KYC补录+超管审批 §D)──► ┐
   │否                                                              │
   ▼◄───────────────────────────────────────────────────────────┘
 applied ──(制裁re-screen过 §D · 冷静期满 · 超管审批+户名核对)──► approved
   │                                                    │(锁定三账户快照+net)
   ├──(前置门/制裁/KYC不过)──► rejected(active_uniq=NULL)  │
   │                                                    ▼
   │                                    paying ─┬─► paid(三腿全到账,余额置0,active_uniq=NULL)
   │                                            └─► partial(部分腿失败,人工续,逐腿幂等)
   └──(净额<0)──────────────────────────────────► owing(标欠款,不退,人工追缴)
```
- **applied**：`nezha_commission_active` 无关；insert settlement(active_uniq=1)，`restaurants.offboard_status='settling'`(触发 §C 冻结)，`cooldown_until=now+20d`。
- **KYC 前置(K-D)**：applied 时若 `vendor_kyc_profiles.kyc_status != 'approved'`(现网 7 店全 `none`)→转 `kyc_pending`，走 `NezhaKycController::save`(→pending)+`review`(→approved)子流程，通过才回 applied。**§I 写明这是存量店退出的必经路径**。
- **approved**：**审批当刻重跑制裁 re-screen(§D)** + 户名核对(holder_verified) + 锁定 `guarantee_amt/deposit_amt/ad_amt/net_amount` 快照。
- **paying→paid**：净额计算+置零+写退款流水**同一锁同一事务**(§C2)；三腿各标 `leg_*_paid`，任一腿失败标 `partial` **不置零该腿**；**partial 恢复逐腿幂等**：`for each leg: if leg_paid then skip`(照抄 `ChargeAdOnStart:81` 事务内重读 paid 标记)，net 用 approved 快照不重算。
- 记账(置零+负流水)与"已确认线下到账"两状态位解耦。

## B'. 押金缴纳记账（新增 · 补 K.2-缴纳地基，对称 `store_recharge`）
超管后台 `store_guarantee` 方法（镜像 `Admin/NezhaDepositController::store_recharge:99-141` 全配套）：validate(`restaurant_id`/`amount≥0.01`/`currency∈{AMD,CNY}`/`original_amount`/`original_ref` 必填)→`DB::beginTransaction`+`lockForUpdate`→wallet 不存在则建→写 `guarantee_balance += amount` + `create` 一笔 `type='guarantee_deposit'`(带 `currency/original_amount/original_ref/balance_after/created_by`)→Toastr→异常 rollback。配套后台 blade 表单。缴纳流水 `type` 进 `ACCOUNTS['guarantee']`。

---

## C. 锁 + 冻结（v2 · K-A 重做接缝 + 补全路径 + 快照安全网）
**C1 停接单**（独立接缝，不碰 `nezha_commission_active`）：`nezha_store_paused:2318` 加一条与 `nezha_temp_closed` 并列的判断——
```php
if ($tempClosed || (($restaurant->offboard_status ?? 'active') === 'settling')) return true;
```
（v1 错在改 `nezha_commission_active`→`nezha_deposit_below_threshold:2308` 首行依赖它→反而让 `store_paused` 返 false 照样接单。修正后停接单与抽佣开关正交。）
**C2 停扣佣**（独立接缝）：`OrderLogic::create_transaction:276` 扣佣条件加 `&& ($order->restaurant->offboard_status ?? 'active') !== 'settling'`（`:411` 已 `loadMissing('restaurant')`，读已加载列**不产生额外查询**，K.3 证实）。
**C3 补全 4 条漏的 deposit 写路径**（settling 期间各自处置）：
- `refund_reversal`(`OrderLogic:740`，门是 `is_direct_pay` **不看** commission_active)：settling 时**记 shortfall 而非回充 deposit**。
- 平台下架广告退费(`Admin/AdvertisementController:288`)：settling 时 Toastr 拒绝，要求先撤退出。
- admin 手改(`store_recharge`/`rechargeDeposit:718`)：settling 时拒绝。
- `ChargeAdOnStart:90`(每小时 cron，从 deposit 扣广告投放费)：跳过 settling 店(本就该停投)。
**C4 快照安全网（K-A 核心防呆，兜住漏冻结+锁边界跨天）**：`paying→paid` 事务内 `lockForUpdate` 重读真实三余额，**与 approved 快照比对：一致才置零放款；不一致(说明某路径漏冻结/冷静期内变动)→不放款，abort 转人工重审**。→ 漏没漏冻结路径都不错退。
**C5 结算事务**（镜像 `OrderLogic:278` 成熟范式）：`settling 首步强制 settle_delivered 跑完所有 delivered-but-unsettled 单`(使佣金全部落 `commission_deduction`)→单事务 `lockForUpdate` 锁 wallet 行→读净额→置零→写三笔 `*_refund` 负流水(`balance_after=0` §G)。

---

## D. 制裁门 + KYC 门（v2 · K-C 实时重筛 + K-D 前置 + 户名 enforce）
**D1 制裁(K-C 修 stale)**：**不读 `screen_status` 旧列**（入驻当刻写、名单每日刷新不重算=L1-6 退款空转）。改为 applied + approved 两道门**用当前 `nezha_sanction_names` 表实时 RE-run** `NezhaKycScreen::screen_names([legal_name, beneficial_owner_name])`；`hit`→挡+`rejected`；`possible`/名单反查未决→**一律 fail-closed** 转人工 AML(退款=对外付款属高危，不止挡 hit)。命中 `Helpers::sendTelegramToAdmin` 告警。
**D2 KYC 前置(K-D)**：`kyc_status='approved'` 才放行；否则转 `kyc_pending` 子流程(§B)。（退款给商家=对外付款，主体没核验身份不能付；现网 7 店 0 档，此为必经。）
**D3 户名核对 enforce(K.2-户名)**：退款前审批页强制超管**逐字核对** `legal_name == account_holder_name(§A4 已拆结构化) == 缴纳凭证付款人`→勾选写 `holder_verified` 审计；高额(5000)双人复核。（把 v1 的"写进 SOP 口头核对"变留痕 enforce 动作。）

---

## E. 来路溯源 + 指纹 + 退出门（v2 · K-E 指纹落地 + 补第3路径）
**E1 来路(补 K.2-第3路径)**：`onboard_source` 入驻当刻写死，**三条建店路径都写**：`VendorLoginController::register:102`(=`self_register`)、`Admin/VendorController@store:65`(=`admin_create`)、**顶层 `VendorController@store:148/:160`**(StackFood 多步入驻向导，v1 漏了，=`self_register`)。grep 全库 `new Restaurant`/`Restaurant::create` 已确认无第 4 条。
**E2 指纹(K-E · 用户已批做)**：`id_doc_fingerprint = HMAC-SHA256($env_key, normalize_doc_number(id_doc_number))`。
- `normalize_doc_number`：镜像 `NezhaKycScreen::normalize_name`(大写/去标点/压空白) + 按 `id_doc_type` 分域前缀(如 `passport:`)；FE/BE 统一规范化。
- 密钥 `NEZHA_KYC_FP_KEY` 进 `.env`(**不入库、独立于表空间加密 key**，一处泄露不双沦陷)。
- 退出审批 + 新入驻时按 fingerprint 跨 vendor 查历史 `restaurant_offboard_settlements`/KYC `closed_at`→命中则**审批页红标"该主体退过押金 N 次"**。
- 🔴 **只做辅助红标、非硬闸**：接受**漏匹配>误匹配**(录入差异漏网 < 误红标真人)；因此"防换号薅豁免"是超管决策的辅助信号，不做自动拒绝。
**E3 退出门(E2 修正后)**：门①订单终态(直付单本就稳定 §0)；门②改「无**经甄别真实相关** pending 纠纷」+超管「标恶意/驳回」动作(驳回的不计入)——治举报/风控被武器化；门③冷静期 applied 锚定、撤回重提不重置；**申请后新增纠纷走人工、不自动阻断已进结算的退出**。

---

## F. 抵扣 + 净额 + 隔离（v2 · K-B 删 penalty）
- **net = `guarantee_balance + deposit_balance + ad_balance`**（settling 首步已强制 settle_delivered 把所有佣金落进 `commission_deduction`、从 deposit 扣净→**无独立"未结佣金"减项、更无 penalty**，K-B 悬空项消除）。
- deposit 若被佣金扣成负→该负值即 shortfall→net 可能<0→`owing`(标欠款、阻断 re-onboard 豁免)。
- **INV-1 隔离**：`ad_refund` 写 `ad_balance` 负流水、`guarantee_refund` 写 `guarantee_balance`，**各退各账、抵扣不跨账户**(未结佣金只体现在 deposit)。ad/guarantee 独立全额退。
- **断言(K.3)**：`ad_refund` 不撞 `NezhaAdAuctionTest:test_9`(只 sum `ad_click_fee`)；实装给 `NezhaL1RedlineTest` **加正向断言**(offboard `ad_refund` 只允许 type/全额/不改 deposit)，**不改 test_9 过滤**(禁"删断言绕过")。

## G. 对账中心接入（v2 · controller + 8 处 blade）
- **controller**：`ACCOUNTS += 'guarantee'=>['guarantee_deposit','guarantee_refund']`；`normalizeAccount` 放行 guarantee；`:108`/`:150` 二元三目 → `match($account){'ad'=>$ad,'guarantee'=>$guarantee,default=>$deposit}`；`account_label:164`/`$slug:176` 同步。
- **blade(补 K.2-8处)**：`vendor-views/nezha-deposit/index.blade.php` 的 8 处 `$account==='deposit'/'ad'` 裸分支(余额卡 `:49/:61`、Tab active `:83/:86`、汇总 `:131`、流水标题 `:170`、页尾充值+低额告警 `:210`)→**改数据驱动**(controller 下发 `accountTitle`/`showRechargeHelp` 等，blade 不硬判 account)；充值说明/低额告警块保留"仅 deposit"语义、别误挂 guarantee。grep 零残留 `$account === '`。
- 退还三笔 `balance_after` 精确=0；退出时写一笔**对齐调整流水**抹平 `anchoredBounds` 历史偏差(`:71` 倒推 vs 种子无流水，K.3-🟢)。
- `$typeLabels`+导出 `$labels` 补 guarantee/refund 各 type 中英，grep 零残留 key。

## H. 审批异步二次闸 + 审计 + 被动红旗（v2 保留）
异步：settlement 持久工单+20 天冷静期=已含异步；高档(5000)/大额强制次日转+邮件/TG 二次确认链接。审计：档位设定/变更 + offboard `approved_by/approved_at/holder_verified` 留痕。被动红旗：对账/风控页高亮"高单量却低押金档"店(纯查询、零 cron，避开 bootstrap 调度坑)。

## I. 实现顺序（灰度 · v2 补迁移/KYC 路径）
1. **迁移(A)** + 模型 casts/fillable + **对账三态(G，纯读先上不碰资金)**。迁移 commit+push 进 release 随 `nzdeploy-api.sh migrate`(release 部署，工作树改动不上线)；5.7 `ADD COLUMN` nullable+default 走 `ALGORITHM=INPLACE,LOCK=NONE`(hasColumn 守卫先例)；新表 FK 有先例(`2026_07_02_010200`)；全 additive 可回滚(greenfield 已 grep 无残留)。
2. **缴纳记账 B'** `store_guarantee` + 押金 Tab 点亮。
3. **KYC 补录子流程(D2)接入 + `onboard_source` 三路径(E1) + `id_doc_fingerprint`(E2)**。
4. **退出结算状态机(B)+锁/冻结(C，含 4 路径+C4 快照安全网)+抵扣隔离(F)** — staging 下单 harness 全绿才上。
5. **制裁 re-screen(D1)+审批闸/审计/红旗(H)**。同步 `NezhaL1RedlineTest`(制裁 re-screen + 法币-only + 隔离正向断言 + `original_ref` 留存断言)。
- 🔴 **存量 7 店**：KYC 补录是退出必经路径(D2)，别默默 reject。

## J. 开放点（v2 · 多数已解，余留第三轮 debate）
已解：penalty(删)/KYC 门(强制补建)/HMAC(做)/停接单接缝/net 公式/8 处 blade/第 3 建店路径/UNIQUE 重试。**余留 debate**：① `normalize_doc_number` 分域规则细节(护照 vs 身份证 vs 居留证号格式差异，漏匹配率) ② `holder_verified` 高额双人复核的"双人"在单超管现实下如何落地(次日转+TG 二次确认是否等价) ③ settling 首步"强制 settle_delivered 在途单"与 `auto-finalize-handover` cron 的并发(会不会双结算，靠 `OrderTransaction::exists` 幂等兜住需实测) ④ `owing` 欠款的人工追缴闭环(催缴/时效/是否影响法人名下其它 vendor)。

---

## K. 第二轮 /debate 结论（2026-07-01 · 三路独立核验 DESIGN 本身）
> 三路红队(资金正确性/合规落地/实现可行性)独立核验 v1。**三方 verdict 一致：需补后可实现**(骨架站得住，非推倒重做)。完整报告存本地 scratch debate2_1/2/3。本 v2 已折叠下列全部修法。

### K.1 🔴 必修（v2 已折叠）
- **K-A 冻结接缝错+覆盖不全**→ §C(停接单 `nezha_store_paused` 独立加 settling / 停扣佣 `create_transaction:276` 独立加 / 补全 4 路径 / C4 快照安全网)。
- **K-B net 公式悬空**→ §F(删 penalty；未结佣金经 settling 首步强制 settle_delivered 归零，net=三账户和)。
- **K-C 制裁门读旧快照**→ §D1(实时 RE-run `screen_names`，不读 `screen_status`；possible/未决 fail-closed)。
- **K-D KYC 门卡死 100% 存量**→ §B/§D2(KYC 补录审批前置子流程；§I 列为存量店必经)。
- **K-E 加密 PII 撞硬墙**→ §A4/§E2(`id_doc_fingerprint` HMAC 明文索引列+归一化+密钥进 .env；只做辅助红标)。

### K.2 🟡 已折叠
UNIQUE 重试→§A5 `active_uniq` NULL 部分唯一 · partial 腿级幂等→§B · 户名核对→§A4 `account_holder_name`+§D3 enforce · original_ref 留存→§A2 断言 · 第3建店路径→§E1 · 8 处 blade→§G · 迁移锁窗/部署→§I · 缴纳记账→§B' · balance_after 精度→§G 对齐流水。

### K.3 🟢 设计担忧被证伪（v2 采纳）
`ad_refund` 不撞断言(test_9 只 sum `ad_click_fee`)→§F 加正向断言别删 test_9 · 每单不多查 restaurants(已 loadMissing)→§C2。

### K.4 ✅ 三队确认站得住（v2 保留骨架）
§0 直付 410 · UNIQUE 幂等根方向 · §C2 lockForUpdate 范式 · §F 隔离守 INV-1 · §D 制裁查主体状态方向(读法已改) · §G controller match · 迁移 additive 可回滚 · §H 无定时任务避开 bootstrap 坑。

### K.5 用户已拍板(2026-07-01)
① penalty 本版删；② 存量商家退出前强制补建 KYC+审批；③ 做 HMAC 指纹列。均已折叠进 v2 §A–§I。

---
*相关：`docs/PLAN_merchant_offboard.md`（政策/§8 红队/L1 决策）· `INVARIANTS.md` L1-8 · `OrderLogic.php`(create_transaction 扣佣/settle_delivered 幂等/refund_reversal) · `NezhaKycScreen.php`(制裁名筛+归一化) · `NezhaKycController.php`(KYC save/review) · `Vendor/NezhaDepositController.php`(对账) · memory `project_nezha-merchant-accounts-reconciliation-refund` · 两轮红队报告存 scratch debate_1/2/3+debate2_1/2/3。*
