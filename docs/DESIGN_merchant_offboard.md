# DESIGN v3 — 商家退出结算/押金账户 实现设计（三轮 debate 已折叠 · 实装就绪）

> **版本**：v3（2026-07-01）。经三轮 /debate（政策/设计/v2）核验，本版折叠全部 4+5+4 🔴 + 应修。**核心骨架三轮验证站得住**（资金账目/隔离/幂等根/接缝/制裁实时/直付 410），v3 主要补 v2 缺的**状态机回流边 + 全建单入口冻结 + 并发底座依赖**。debate 历史见 §K。
> **配套**：政策正本 `docs/PLAN_merchant_offboard.md`（§8）；红线 `INVARIANTS.md` L1-8。
> 🔴 **仍不进代码**：这是**最后一版设计**；建议按 §I 灰度直接实装，**staging 下单 harness 作资金正确性唯一验收**（CI 断言测不了资金闭环）。不再跑第四轮 debate（边际递减）。
> 🔗 **前置依赖**：`order_transactions.order_id` 唯一约束（既有 LIVE 双扣佣 bug，独立任务 `task_fb41eea8`）须先修——C5 的并发结算安全依赖它。
> **代码锚（三轮核实）**：扣佣 `OrderLogic::create_transaction:83`(订单完成时,:276 gate,:278 lockForUpdate,:284 commission_deduction 流水,:416 exists 幂等**无锁**) · `settle_delivered:401`(:412 前置只推 `handover`/`picked_up`、对已 delivered return false) · 接单闸 `nezha_store_paused:2318` · **POS 独立建单 `POSController:357`(不经 store_paused)** · 制裁 `NezhaKycScreen::screen_names:142`/`normalize_name:42` · KYC `NezhaKycController::save:69`(→pending)/`review:124`(→approved/rejected) · 对账 `Vendor/NezhaDepositController:108/:150`+`anchoredBounds:71` · 漏 deposit 写 `refund_reversal:740`/`AdvertisementController:288`/`ChargeAdOnStart:90` · `order_transactions` 无 order_id 唯一键(实测 SHOW INDEX)。

## 0. 直付单退款武器已结构性失效（三轮确认）
`refund_request` 对 `offline_payment`(直付=B方案默认)单在 `OrderController:1709` 一律 410 → delivered 稳定终态、顾客翻不动。退出门 E 的"退款人质"武器对哪吒真实订单(直付)失效，E 真武器=举报+风控(§E3)。

---

## A. Schema（迁移 DDL · v3）
- **A1 `restaurant_wallets`**：`guarantee_balance decimal(24,2) default 0`。
- **A2 `restaurant_deposit_transactions`**：`currency varchar(8) default 'AMD'` + `original_amount decimal(24,2) null` + `original_ref text null`(模型 `encrypted` cast)。🔴 **留存**：迁移注释 + INVARIANTS L1-8⑤ 明写「任何 purge 不得清除本表」+ 结构守卫测试(对齐 KYC 豁免范式)。
- **A3 `restaurants`**：`onboard_source enum('self_register','admin_create','unknown') default 'unknown'`(存量 7 店回填 unknown,新入驻写真值,不可变) + `offboard_status enum('active','settling','owing','offboarded') default 'active'` + **`guarantee_tier enum('exempt','500','1000','5000') null`**(应缴档,L.2 设档核对;改需审计)。
- **A4 `vendor_kyc_profiles`**：+ `account_holder_name text null`(`encrypted`,户名从 free-text `bank_account` 拆出供 enforce 核对) + `id_doc_fingerprint varchar(64) null index`(HMAC hex 明文可索引,见 §E2)。
- **A5 新表 `restaurant_offboard_settlements`**：
```
id, vendor_id, restaurant_id,
active_uniq tinyint null,                       -- 活跃=1 / 关闭(withdrawn/rejected/failed/paid)=NULL
UNIQUE KEY uq_active (vendor_id, active_uniq),  -- 5.7 NULL 相异→同 vendor 仅一条 active,已关闭多条 NULL 并存(可重申);应用层须 catch 唯一冲突当幂等勿 500
status enum('applied','kyc_pending','approved','rejected','withdrawn','paying','paid','partial','failed') default 'applied',
applied_at, cooldown_until,                     -- applied 当刻锚定,撤回重提不重置
kyc_gate_passed bool default 0, sanction_rescreen_at, holder_verified bool default 0,
guarantee_amt, deposit_amt, ad_amt, net_amount, shortfall_amount,  -- approved 当刻锁定快照
pending_clawback decimal(24,2) default 0,       -- 垫付追偿减项接口(现恒 0,§F)
leg_deposit_paid bool default 0, leg_ad_paid bool default 0, leg_guarantee_paid bool default 0,
approved_by, approved_at, payout_ref, note, timestamps
```
- **A6 前置依赖(独立任务)**：`order_transactions.order_id` 加 UNIQUE(清历史重复后)——治双扣佣底座(L-C)。**非本设计新增,但 C5 依赖它**。

---

## B. 退出结算状态机（v3 · 补全回流边,治 L-A 三死循环）
```
 active ──[商家申请退出]──► applied ─┐
   ▲  ▲                             │(无 approved KYC?)
   │  │  ┌──(商家撤回/超管取消·approved前)──┘        ├─是─► kyc_pending ─(review approved)─► applied
   │  │  │  =回 active:offboard_status=active,               │                 └(review rejected)─► ★回 active(status=rejected,身份没核成功不给退但恢复营业)
   │  │  │   active_uniq=NULL,status=withdrawn                │
   │  └──┴──────────────────────────────────────────────────┘
   │                    applied ─(制裁re-screen过·冷静期满·超管审批+户名核对·净额>0)─► approved ─► paying ─┬─► paid(三腿置0,active_uniq=NULL)
   │                       │(净额<0)                                   (锁定快照)                          └─► partial(逐腿幂等续)
   │                       ▼
   └──(追缴到账,超管store_recharge补平)── owing ──(长期追缴失败)──► written_off(坏账核销,记审计,店永久offboarded不占号)
```
- **★回流边(L-A 核心补丁)**：①`applied`/`kyc_pending` **可撤回→active**(商家反悔/超管取消,仅 approved 前;同时 offboard_status→active 解冻、active_uniq→NULL、status→withdrawn)——治"误点=永久停业"。②KYC `review` **拒绝→回 active**(status=rejected,店恢复营业)——治"KYC 拒卡死"。③`owing` **两出口**(追缴补平→继续退/销户;长期失败→`written_off` 终态)——治"owing limbo"。
- **applied**：insert(active_uniq=1),`offboard_status='settling'`(触发 §C 冻结),`cooldown_until=now+20d`。
- **KYC 前置**：`kyc_status!='approved'`(现网 7 店全 none)→`kyc_pending`,走 `NezhaKycController::save→review`;通过回 applied,拒绝回 active。
- **approved**：重跑制裁 re-screen(§D1)+户名核对(holder_verified)+锁定快照。
- **paying→paid**：净额+置零+退款流水同锁同事务(§C4);三腿标 `leg_*_paid`,失败标 `partial` 不置零该腿;**partial 恢复逐腿幂等**(for each leg: if leg_paid skip,net 用快照不重算),恢复入口 `lockForUpdate` 锁 settlement 行防并发双续。
- **法人级欠款硬约束**：同 `id_doc_fingerprint`/`legal_name` 有未结 `owing`/`written_off` 时,该法人新入驻或其它 vendor 退出**超管强确认**(比红标重一档)——治老赖换 vendor 马甲带款跑。

## B'. 押金缴纳记账（对称 `store_recharge` + 档位核对）
超管后台 `store_guarantee`(镜像 `Admin/NezhaDepositController::store_recharge:99-141`):validate(`amount≥0.01`/`currency∈{AMD,CNY}`/`original_amount`/`original_ref` 必填)→`DB::beginTransaction`+`lockForUpdate`→`guarantee_balance+=amount`+`create` `type='guarantee_deposit'`(带 currency/original_amount/original_ref/balance_after/created_by)。**缴纳页显示「应缴(guarantee_tier)/实缴/缺口」**(L.2 设档核对,超管一眼可见)。缴纳 type 进 `ACCOUNTS['guarantee']`。

---

## C. 锁 + 冻结（v3 · 全入口冻结 + C4 恢复 + C5 语义对齐）
- **C1 停接单(补 L-B POS)**：抽 helper `nezha_offboard_frozen($restaurant) = (offboard_status==='settling')`,在**所有建单入口**显式调:①顾客 API `nezha_store_paused:2318`(与 `nezha_temp_closed` 并列)②`POSController::place_order:357` 建单前(现不经 store_paused)③结算预检 `:2553`。grep 全库建单点确认无第 4 处漏。
- **C2 停扣佣(补 L.2 stale)**：`create_transaction:276` 扣佣条件加 settling 判断,gate 用**显式 fresh 查询** `Restaurant::where('id',$order->restaurant_id)->value('offboard_status')`(1 次轻查,避 6+ 调用者 lazy relation 读到缓存旧值 'active' 的 N+1/stale)。与抽佣开关正交(不碰 `nezha_commission_active`)。
- **C3 补全 4 条漏的 deposit 写路径**(settling 期各处置)：`refund_reversal:740`(记 shortfall 非回充)/下架退费 `AdvertisementController:288`(拒,要求先撤退出)/admin 手改 `store_recharge`(拒)/`ChargeAdOnStart:90` cron(跳过 settling 店)。
- **C4 快照安全网 + 确定性恢复(补 L-D DoS)**：`paying→paid` 事务内 `lockForUpdate` 重读三余额 vs approved 快照:**一致才置零放款;不一致→不放款,回 `approved`、作废旧快照、重跑 settle_delivered 归零在途佣金后重锁重算 net**(非无限 abort);**连续 abort N 次→熔断告警超管人工**。→ 漏冻结/跨天变动都不错退,且不 DoS 商家退出。
- **C5 结算首步(补 L-D 语义对齐)**：settling 首步对**所有 `handover`/`picked_up` 在途单调 `settle_delivered` 推进结算**(使佣金落 commission_deduction);**真"delivered-but-unsettled"漏结单用 `create_transaction` 补**(非 settle_delivered,其 :412 对已 delivered return false);**`cooking`/`accepted`/`pending` 等非终态活跃单→前置门①(§E3)直接挡 applied**,要商家先处理完(门前移,不等到 paying)。🔴 **并发依赖**：C5 与 `auto-finalize-handover` cron/顾客确认多路并发调 settle_delivered,靠 `order_transactions.order_id` UNIQUE(A6 前置任务)DB 兜底防双扣。
- **C6 结算事务**：镜像 `OrderLogic:278` 成熟范式,单事务 `lockForUpdate` 锁 wallet 行→读净额→置零→写三笔 `*_refund` 负流水(`balance_after=0`)。

---

## D. 制裁门 + KYC 门（v3 · 实时重筛 + 运营 SLA + 单超管现实）
- **D1 制裁(K-C)**：**不读 `screen_status` 旧列**(入驻当刻写、名单每日刷新不重算=空转)。applied+approved 两道门用当前 `nezha_sanction_names`(43,576 条)实时 RE-run `NezhaKycScreen::screen_names([legal_name,beneficial_owner_name])`;`hit`→挡+rejected;`possible`/未决**一律 fail-closed** 转人工 AML(退款=对外付款高危)。🔴 **实装须同步 INVARIANTS L1-8③ 文本**(现写"读 screen_status 列",按 L1 变更流程改为"实时 RE-run screen_names",记 CHANGELOG——否则 L1 正本与实现漂移,L.2)。
- **D2 KYC 前置 + 运营 SLA**：`kyc_status='approved'` 才放行,否则 `kyc_pending`(§B,现网 7 店 0 档必经)。**KYC 仅 admin 后台可录**(无商家自助入口=有意,降 PII 负债)→补运营 SLA:①`kyc_pending` 时商家对账页显「已收到退出申请,平台需完成身份核验(预计 N 工作日),届时联系视频核验」②admin 侧「待退出核验」队列(KYC index 加 `offboard_status='kyc_pending'` 筛)③核验超期自动提醒超管。KYC `review` 拒绝→回 active(§B)。
- **D3 户名 enforce + 单超管现实**：审批页强制超管逐字核对 `legal_name==account_holder_name(§A4)==缴纳凭证付款人`→勾 `holder_verified` 审计。**单超管无第二人**→§J2「双人复核」明确降级为 **§H 异步二次确认(强制次日转+独立信道 TG 确认链接+审计双时间戳)= 等价替身**(同人、两时点、两信道,防盗号/诱导),不追求不可交付的"真双人"。

## E. 来路溯源 + 指纹 + 退出门（v3）
- **E1 来路(补第 3 路径)**：`onboard_source` 入驻当刻写死,**三条建店路径都写**:`VendorLoginController::register:102`(self_register)、`Admin/VendorController@store:65`(admin_create)、顶层 `VendorController@store:148/:160`(self_register,v1/v2 漏)。
- **E2 指纹(K-E)**：`id_doc_fingerprint=HMAC-SHA256($env_key, normalize_doc_number(id_doc_number))`;`normalize_doc_number` 镜像 `normalize_name`(大写/去标点/压空白)+按 `id_doc_type` 分域前缀;密钥 `NEZHA_KYC_FP_KEY` 进 `.env`(不入库,独立于表空间加密 key)。退出审批/新入驻按**多信号**(fingerprint + legal_name + 手机号,非单键)跨 vendor 查历史→红标"该主体退过押金/欠款 N 次"。🔴 **只做辅助红标非硬闸**(接受漏匹配>误匹配;换证件类型漏匹配靠多信号降低)。**密钥轮换预案**(L.3):一次性 artisan 遍历解密 `id_doc_number`(cast 自动)用新 key 重算回写;辅助红标非闸,重算期短暂失效可接受。
- **E3 退出门**：门①订单终态(直付本就稳定;非终态活跃单挡 applied,§C5);门②「无**经甄别真实相关** pending 纠纷」+超管「标恶意/驳回」动作(驳回不计入)——治举报/风控武器化;门③冷静期 applied 锚定不重置,申请后新增纠纷走人工不阻断已进结算的退出。

## F. 抵扣 + 净额 + 隔离（v3 · 删 penalty + 预留追偿钩子）
- **net = `guarantee_balance + deposit_balance + ad_balance − pending_clawback`**。settling 首步(§C5)已把所有佣金落 commission_deduction 从 deposit 扣净→**无独立"未结佣金"减项、无 penalty**(删,K-B)。`pending_clawback` 现**恒 0**,预留「平台垫付赔顾客」追偿(L1-8 同骨,该项目落地接;L.3)。deposit 被佣金扣负→该负值即 shortfall→net<0→`owing`。
- **INV-1 隔离**：`ad_refund` 写 ad_balance/`guarantee_refund` 写 guarantee_balance,各退各账、抵扣不跨户。断言:`NezhaL1RedlineTest` **加正向断言**(offboard `ad_refund` 只允许 type/全额/不改 deposit),**不改 `NezhaAdAuctionTest:test_9`**(只 sum ad_click_fee,不撞;禁"删断言绕过")。

## G. 对账中心接入（v3）
- controller:`ACCOUNTS+=guarantee`;`normalizeAccount` 放行;`:108`/`:150` 二元三目→`match($account)`;`account_label:164`/`$slug:176` 同步。
- **blade 8 处**(`index.blade` `$account===` 裸分支 `:49/:61/:83/:86/:131/:170/:210`)→数据驱动(controller 下发 `accountTitle`/`showRechargeHelp`),充值/低额告警块保留仅 deposit 语义。grep 零残留。
- 退还三笔 `balance_after=0`;退出时写对齐调整流水抹平 `anchoredBounds:71` 历史偏差。`$typeLabels`+导出 `$labels` 补 guarantee/refund 中英,grep 零残留 key。

## H. 审批异步二次闸 + 审计 + 被动红旗（v3）
异步:settlement 持久工单+20 天冷静期;**高额(5000)/大额强制次日转+邮件/TG 二次确认链接 = 单超管下"双人复核"等价替身**(§D3)。审计:`guarantee_tier` 设定/变更 + offboard `approved_by/approved_at/holder_verified` 留痕。被动红旗:对账/风控页高亮「实缴<应缴档」「高单量却低押金档」店(纯查询零 cron,避 bootstrap 调度坑)。

## I. 实现顺序（灰度 · v3）
0. 🔗 **前置**：修 `order_transactions.order_id` 唯一约束(独立任务 `task_fb41eea8`)——C5 并发结算安全依赖。
1. **迁移(A)** + 模型 casts/fillable + **对账三态(G,纯读先上)**。迁移随 `nzdeploy-api.sh migrate`;5.7 `ADD COLUMN` nullable+default 走 `INPLACE,LOCK=NONE`;FK 有先例;全 additive 可回滚 greenfield。
2. **缴纳 B'** `store_guarantee` + 档位核对 + 押金 Tab 点亮。
3. **KYC 子流程(D2)+运营 SLA + `onboard_source` 三路径(E1)+ `id_doc_fingerprint`(E2)**。
4. **状态机(B,含回流边)+锁/冻结(C:`nezha_offboard_frozen` 全入口 + C4 恢复 + C5 语义)+抵扣隔离(F)** — staging 下单 harness 全绿才上。
5. **制裁 re-screen(D1)+ L1-8③ 文本同步 + 审批闸/审计/红旗(H)**。
- **断言分层(L.2)**：进 `NezhaL1RedlineTest` **只写结构守卫层**(re-screen 调用点存在/`ad_refund` 不写 deposit 源码守卫/法币-only 开关/purge 豁免),明写「只保证开关态与代码守卫、**不替代资金闭环**」;**资金置零/leg 幂等/C4 快照拒付 归 staging 下单 harness 唯一验收**(CI 连生产库、只事务回滚,测不了持久资金)。
- 🔴 存量 7 店:KYC 补录是退出必经路径,别默默 reject。

## J. 开放点（v3 · 已基本清空）
三轮共 ~15 问题全部折叠。**无阻断级余留**。实装期细节(可 code review/staging 兜):`normalize_doc_number` 各证件类型格式细化 · owing 追缴的催缴时效/坏账核销审批 · KYC 运营 SLA 的具体天数 · `pending_clawback` 接口待垫付项目对接。

---

## K. Debate 历史（三轮 · 骨架三轮站得住,详报存 scratch）
- **一轮(政策·PLAN §8)**：资金/合规/防薅三红队 → verdict 全🔴阻断上线,5 阻断项 → 定 L1 决策 + 押金**法币-only** + 记 INVARIANTS L1-8。报告 `debate_1/2/3`。
- **二轮(设计·§K@v1)**：资金正确性/合规落地/实现可行性 → 需补后可实现,5🔴(冻结接缝/net 悬空/制裁 stale/KYC 卡存量/加密 PII 硬墙)+9🟡 → v2。报告 `debate2_1/2/3`。
- **三轮(v2·§L)**：并发/合规/端到端 → verdict 不一致但收敛,4🔴(状态机无回流边 3 死循环/POS 漏冻结/`order_transactions` 双扣佣既有 bug/C5 语义+C4 DoS)+🟡 → **本 v3**。报告 `debate3_1/2/3`。
- **三轮一致确认站得住**：资金账目自洽 · 三腿原子性+逐腿幂等 · 隔离 INV-1 · 停接单/停扣佣接缝正交 · net=三账户和删 penalty · active_uniq NULL 5.7 部分唯一 · 制裁实时 re-screen · §0 直付 410 · 迁移 additive 可回滚 · 合规修法无假落地。
> §L(v2 第三轮结论全文)保留于 git 历史 commit `623b87b`;v1/v2 全文见 `b695a58`/`deb46b1`。

---
*相关：`docs/PLAN_merchant_offboard.md`（政策/L1 决策）· `INVARIANTS.md` L1-8 · `OrderLogic.php`(create_transaction/settle_delivered/refund_reversal) · `POSController.php:357`(POS 建单) · `NezhaKycScreen/Controller`(制裁名筛+KYC) · `Vendor/NezhaDepositController`(对账) · 独立任务 `task_fb41eea8`(order_transactions 唯一约束) · memory `project_nezha-merchant-accounts-reconciliation-refund` · 三轮红队报告 scratch `debate_1/2/3`+`debate2_1/2/3`+`debate3_1/2/3`。*
