# 中途退回押金 (A3 · S3-B) — /debate 三路红队核验存档

> 2026-07-03。押金退还的**营业中(中途)**实现。L1-8 资金路径,按业主高风险铁律先跑 /debate 对抗核验退口设计再实施。
> 三路独立红队(资金安全 / 合规 L1-8 / 防薅内控)对设计草案 v0 核验,**verdict 全部 🔴 阻断**,各路阻断项基本不重叠(印证"单跑一路必漏三四条")。本档留存裁决 + v1 硬化 + 落地实证。

## 0. 核心结构性发现(三路收敛)
押金(`guarantee_balance`)在运行时是**"死抵押"**:全库仅 5 文件碰它,**无任何下单/扣佣路径动它**,唯一被用来兜欠佣的时刻是 **NezhaOffboard 离场**(`net=deposit+guarantee+ad`)。v0 让商家**营业中把这层垫子抽走还继续接单**,而佣金是**订单完成后**才扣→退款审批当刻账面未欠→欠款门空过→**先套现押金、再欠佣跑路,全系统无 clawback 追偿无着**。⇒ offboard 的门抄了,但它赖以安全的四前提(settling 全冻结 / 全额置零 / uq_active 唯一约束 / 离场 settleInflight 结净)在中途退场景**统统不成立**。

## 1. 三路红队阻断项(去重后 9 类)
| # | 阻断 | 来源 |
|---|---|---|
| 1 | 押金死抵押·无 clawback·放款前不结净在途佣金 → 掏空兜底后欠佣跑路(**核心**) | 资金/防薅 |
| 2 | 放款不持钱包行锁、快照门 TOCTOU → 并发两笔超额抽干、余额击穿为负 | 资金 |
| 3 | 无"同店至多一笔 pending 退款"唯一约束 → 双单双放 | 资金 |
| 4 | `required_floor` 依赖 nullable 档×可变汇率;exempt 档 floor=0 可全退;`set_tier` 只 Log 无审计可寻租调低 | 资金/防薅 |
| 5 | **holder_verified 是超管复选框、非代码强制;收款账户 free-text 可现填;缴纳付款人从不程序化比对** → "本人缴、第三方退"洗钱 | 合规/防薅 |
| 6 | 制裁复筛硬绑 offboard 工单对象、无新鲜度门、须每笔放款都筛(不分金额) | 合规/防薅 |
| 7 | 单运营+无频率闸 → 盗号/社工连续小额(压高额阈值下)把多店押金转攻击者账户 | 防薅/合规 |
| 8 | 互斥不全:`is_frozen` 只覆盖 settling、**owing 态漏放行**;退到只剩渣绕开 offboard 冷静期+制裁门 | 防薅/资金 |
| 9 | 无冷静期/频率 → 拆额规避二次闸 + 反复薅运营 | 合规/防薅 |

**经核实已守住(非阻断)**:法币-only(无 USDT 出口)、L1-1 不碰顾客钱(guarantee 唯一 credit 来源=商家自缴、无顾客钱混入)、留存行不进 90 天 purge。⚠️ `pending_clawback` 垫付钩子若将来真接需回 /debate。

## 2. 业主拍板方向 + v1 硬化(运营核算制·方案A)
业主选 **A 收窄为运营核算制**(不做营业中自助快退;放款人工核实敞口 + 代码硬门;clawback 活兜底留抽佣上线的 B 期再做)。开关 `nezha_topup_refund_status` 默认 0 dormant。逐门(编号=解阻断项):

- **G0** 开关 + 互斥:用 `is_deposit_credit_frozen`(offboard_status≠active,**覆盖 settling+owing+offboarded**)非 `is_frozen` [#8]
- **G1** 欠款/敞口:`deposit_balance<0` 挡;抽佣开启须人工核实敞口(manual_exposure_confirmed)[#1]
- **G2** 可退额:tier=NULL→fail-closed;exempt→引导 offboard;floor=档×汇率(锁内当刻);`0<X≤guarantee−floor` [#4]
- **G3** 制裁实时复筛:**每笔都筛(不分金额)**·四态 fail-closed·approve+pay 各筛一次(新鲜度) [#6]
- **G4** 户名核对(**代码强制**):`normHolder`(CJK-safe)比 legal==holder;收款账户**锁定 KYC·运营不得现填**;身份指纹 `kyc_apply_fp`(申请当刻捕获,放款对比,变更即挡·无 override) [#5]
- **G5** 原子:approve/pay 全程 request 行锁+钱包行锁;pay C4 快照校验;`uq_active_refund` 唯一墙 [#2#3]
- **G6** 频率+异步二次闸:同店最小间隔(默 30 天)/月上限;高额或单运营超日额→`scheduled_pay_at` 次日转 [#7#9]

四处对账对称(负向):X == |流水 amount| == guarantee 冲减量 == 对账中心 guarantee_refund 汇总。放款主体=平台线下法币转账到 KYC 锁定账户,不接网关/不自动扣款。

## 3. 落地实证(逐门反例 harness)
`NezhaGuaranteeRefund`(approve/pay 两段)+ 迁移(topup_requests 补 sanction_rescreen_at/holder_verified/approved_at/scheduled_pay_at/guarantee_snapshot/payout_ref/active_refund_uniq 唯一墙/kyc_apply_fp)。
**逐门反例 harness 26 PASS / 0 FAIL**:happy 四处对账 + 每门造反例证明被挡(开关关/owing/欠佣/抽佣未核/tier NULL/exempt/超额/无KYC/制裁命中/户名不符/身份变更/C4 快照/结构墙双单/频率/高额次日转),全程事务 rollback 零残留。

🔴 **实施中 verification 逮到真 bug**:`NezhaKycScreen::normalize_name` 用 `[^A-Z0-9 ]` 剥中文 → 两个不同中文名都归一化成空串"相等" → **中文商户(哪吒主力)户名核对形同虚设**。已改 CJK-safe `normHolder`(保留中文只去大小写/空白/标点)+ 身份指纹(免疫制裁复筛 apply_to_profile 对 screen_ 状态列的写回致 updated_at 误报)。单模型自律绝发现不了,是 /debate + 逐门反例的价值。

## 4. 遗留 / 后续
- **缴纳付款人第三锚点**:当前缴纳侧 `original_ref`=回执号非付款人姓名,户名核对靠 legal==holder+身份指纹,付款人姓名比对为后续(缴纳侧补捕获)。
- **clawback 活兜底(B 期)**:抽佣上线时把 guarantee 改造成运行时可划扣的活安全垫,才能安全支持"营业中退超额";clawback 设计届时再单独跑一轮 /debate。
- 开关顺序进 PRELAUNCH_SWITCHES:offboard 未开→refund 不得开;抽佣+护栏验完才可开。
