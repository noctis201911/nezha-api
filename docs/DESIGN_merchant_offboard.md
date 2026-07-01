# DESIGN — 商家退出结算/押金账户 实现设计（硬化 spec）

> **配套**：政策/决策正本 = `docs/PLAN_merchant_offboard.md`（§8 红队结论 + L1 决策）。本文件把 §8.2 五阻断项 + §8.3 应修**落成贴真实代码的可实现设计**。
> **状态**：设计草案（2026-07-01 硬化）。**第二轮 /debate 已跑：三方 verdict 一致「需补后可实现」（见 §K）**。🔴 **仍不进代码**——待用户拍板 §K.5 三处 + 出 DESIGN v2 折叠 K.1/K.2 后才进实现。
> **前置已定**：押金**法币-only、平台不持 USDT**（PLAN §4.3/§8.5）；L1-8 已记 `INVARIANTS.md`（commit 926d388）。
> **代码锚（本会话已核实，非红队转述）**：扣佣 `OrderLogic.php:262-340`(DB::beginTransaction)+`:278`(lockForUpdate deposit_balance)；返佣 `:723-785`+`:739`；结算幂等先例 `settle_delivered :401`+`:416`(OrderTransaction::exists)；制裁引擎 `NezhaSanctionScreen::screen_address`(仅链上地址)+KYC 名筛 `screen_status`；对账二元三目 `Vendor/NezhaDepositController.php:108/:150`；退款 `OrderController::refund_request :1668`。

---

## 0. 一处对红队 E 的实测修正（必读）
红队称"顾客用 `refund_request` 把 delivered 单翻回 `refund_requested` 当押金人质"。**实测 `OrderController.php:1709`：`offline_payment`(直付商家=B方案默认支付) 订单一律 410 拦死**，只有非直付(网关/钱包)单才能走到 `:1745` 翻 `refund_requested`。哪吒真实订单群=直付 → **该武器对真实订单基本失效**。
- ✅ 直付单：delivered 是稳定终态，顾客翻不动（`:1709` 结构性关闭）。
- ⚠️ 残留武器（E 真正要防的）：**① 举报 `restaurant_reports` 一条 pending 即冻结退出；② 风控 `nezha_risk_records` review pending**。这两条才是外部人可武器化的门。
- 设计据此**收窄 E**：主攻举报/风控门的"真实相关性甄别"，退款门按直付现实（本就终态）处理。

---

## A. Schema（迁移 DDL）

**A1 `restaurant_wallets`**（加押金余额列，同构 deposit/ad）
```
guarantee_balance  decimal(24,2) default 0   -- 押金余额(法币, AMD 折算)
```

**A2 `restaurant_deposit_transactions`**（补 3 列，服务法币缴纳记录+回执留痕 L1-4）
```
currency         varchar(8)   default 'AMD'  -- 原始缴纳币种(CNY/AMD)
original_amount  decimal(24,2) null          -- 原始缴纳金额(如缴 X 元现金)
original_ref     text          null          -- 法币转账回执号/缴纳凭证(model 加 encrypted cast)
```
> 非押金 type 留空（有 `commission` 列只部分 type 填的先例）。`original_ref` 属 PII 凭据→`RestaurantDepositTransaction` casts 加 `'original_ref'=>'encrypted'`（对齐 KYC 双层，PLAN §8.3）。

**A3 `restaurants`**（来路溯源 + 退出状态机锚）
```
onboard_source   enum('self_register','admin_create') null  -- 入驻当刻写死, 之后只读(不可变)
offboard_status  enum('active','settling','owing','offboarded') default 'active'
```

**A4 新表 `restaurant_offboard_settlements`**（退出结算状态机 + 幂等）
```
id, vendor_id UNIQUE, restaurant_id,
status enum('applied','approved','rejected','paying','paid','partial','failed') default 'applied',
applied_at, cooldown_until,                 -- 冷静期锚(applied 当刻+20d, 之后不因撤回重置)
guarantee_amt, deposit_amt, ad_amt,         -- 三账户各自余额快照(锁内读)
deduction_amt,                              -- 未结佣金/罚款(只从 deposit 抵)
net_amount, shortfall_amount,               -- 应退净额 / 净额<0 欠款
kyc_screen_status,                          -- 审批当刻制裁筛查快照(hit/possible→rejected)
approved_by, approved_at,                   -- 审批留痕
leg_deposit_paid, leg_ad_paid, leg_guarantee_paid,  -- 三腿各自到账标记(bool, 部分成功不置零它腿)
payout_ref, note, timestamps
```
> **`vendor_id` UNIQUE = 幂等根**：一 vendor 一生一条退出结算，双击/两窗口第二次 insert 撞唯一约束即失败 → 根除重复退（阻断项 B）。re-onboard=新 vendor(见 D)=新行，不冲突。

---

## B. 退出结算状态机（阻断项 B：原子性 + 幂等）

```
[对账中心底部「申请退出结算」] 
   │ 前置硬门(§E)全过 + 无 offboard 记录
   ▼
 applied ──(超管审批·冷静期满·再过门+制裁)──► approved ──► paying ─┬─► paid(三腿全到账,余额置0)
   │                                                              └─► partial(部分腿失败,留待人工续)
   ├──(前置门/制裁不过)──► rejected
   └──(净额<0)──────────► owing(标欠款,不退,人工追缴)
```
- **applied**：insert settlement(status=applied, cooldown_until=now+20d)；`restaurants.offboard_status='settling'`（触发冻结，§C）。UNIQUE(vendor_id) 保证只此一条。
- **approved**：超管审批。**审批当刻重跑前置门 + 制裁筛查**（冷静期内状态可能变）。高档(5000)/大额强制次日转+二次确认链接（§H）。
- **paying→paid**：净额计算+置零+写退款流水**在同一锁+同一事务内**（§C）；三腿各自标 `leg_*_paid`，**任一腿失败只标 partial、不置零该腿**（阻断项 B 部分失败不可逆）。
- 记账(置零+负流水)与"已确认线下到账"两状态位解耦：`paying`=已记账待转，`paid`=确认到账。

---

## C. 锁 + 冻结（阻断项 A：净额竞态）

**C1 冻结态短路**（治"20 天窗口内迟到扣佣/广告点击污染净额"）
- `offboard_status='settling'` 时，在两个扣费入口**短路跳过扣费**：
  - `OrderController::nezha_commission_active($restaurant)` → settling 返回 false（不扣佣）。
  - `AdvertisementController` ad_click_fee 扣费前判 settling → 跳过。
- 同时 settling **停止接单**（复用/叠加 `nezha_deposit_below_threshold` 的接单门；商家退出=不再营业）。
> 光加锁只保证不写坏，不保证净额不被"合法的迟到交易"改变——故必须冻结态从源头掐断新扣费，锁只兜并发写。

**C2 结算事务**（镜像 `OrderLogic.php:278` 已验证的成熟模式）
```php
DB::transaction(function() use ($vendorId) {
    $w = RestaurantWallet::where('vendor_id',$vendorId)->lockForUpdate()->first(); // 锁 wallet 行
    // 锁内 fresh 读三余额 + 算未结佣金/罚款
    // deduction 只从 deposit 抵(§F); 算 net/shortfall
    // 写三笔 *_refund 负流水, balance_after 精确=0(§G 对账不断裂)
    // 三余额置0; 更新 settlement 状态
}); // 提交
```
- 读净额、置零、写退款流水**必须同一锁同一事务**（不能读一个事务、转账另一个）。

---

## D. 制裁门（阻断项 C · L1-6 · 已批 L1 变更）
- **法币-only 无 USDT 地址可筛** → 门 = 查**主体制裁状态**，非 `screen_address`(那是链上地址)。
- **applied + approved 两道**：`vendor_kyc_profiles.screen_status ∈ {'hit','possible'}` → 一律挡，settlement=`rejected`，转人工 AML，`Helpers::sendTelegramToAdmin` 告警（复用 `NezhaSanctionScreen::record_*` 同款告警）。
- **无 KYC 或未核验**：`kyc_status != 'approved'` 也挡——退款给商家=对外付款，主体没核验过身份不能付（AML fail-closed，参 `NezhaSanctionScreen::inconclusive_action='hold'` 同精神）。
- 记 settlement.kyc_screen_status 快照留痕。

---

## E. 来路溯源 + 退出门硬化（阻断项 D + E）

**E1 来路（D）**
- `restaurants.onboard_source` 入驻当刻写死：`VendorLoginController::register`（`:192` restaurant->status=0 处）写 `'self_register'`；`VendorController@store`（超管建店）写 `'admin_create'`。之后只读。
- **跨 vendor 身份聚合**：退出审批页显示"该 KYC 主体历史 offboard/退押金 N 次"；re-onboard 若命中历史 `vendor_kyc_profiles.closed_at` 记录→超管红标（防换号薅豁免）。
  - 🔴 **设计难点（转 §J 待 debate）**：KYC 字段是 `encrypted` cast、**不参与 SQL WHERE**（迁移注释明载）→ 无法直接 `WHERE legal_name=?` 跨 vendor 匹配。需引入**确定性身份指纹列**（如 `HMAC(id_doc_number)` 明文索引列，不可逆但可等值匹配）才能跨行聚合。这是 D 落地的真正关口。

**E2 退出门（E，按 §0 修正后）**
- 门①订单终态：直付单 delivered 本就稳定终态（`:1709` 顾客翻不动）→ 按现状即可。
- 门②无纠纷 → 改为**"无经甄别的真实相关 pending 纠纷"**：
  - 举报 `restaurant_reports.status=0(待处理)`：加超管"标恶意/驳回"动作，**被驳回的不计入退出门**（防竞对一条举报永久冻结）。
  - 风控 `nezha_risk_records` review pending：同理，经甄别的才算真纠纷。
- 门③冷静期：`cooldown_until` 存 settlement，**applied 当刻锚定，撤回重提不重置**（防刷新起算点）。
- **申请后新增纠纷**：走人工、**不自动阻断已进结算(applied 后)的退出**（防第 19 天制造 pending 重置）。

---

## F. 抵扣 + 资金隔离（应修 · INV-1 受控豁免 · 已批 L1 变更）
- 未结佣金/罚款**只从 `deposit_balance` 抵**；`ad_balance`/`guarantee_balance` **各自独立全额退给商家、不参与抵扣**。
- 净额 = 三账户和 − deduction，**仅作展示合计**，不是"可跨账户挪用的池子"——ad 永不被拿去抵佣金（守 INV-1）。
- deposit 不够抵未结佣金 → 差额进 `shortfall_amount`，settlement=`owing`，`offboard_status='owing'`（阻断 re-onboard 豁免，呼应 D）。

---

## G. 对账中心接入（应修 · 治二元三目串味）
- `Vendor/NezhaDepositController::ACCOUNTS` += `'guarantee'=>['guarantee_deposit','guarantee_refund']`；`normalizeAccount` 放行 `'guarantee'`。
- **`:108`/`:150` 二元三目 `$account==='ad'?ad:deposit` → 改 `match($account){'ad'=>$adBalance,'guarantee'=>$guaranteeBalance,default=>$depositBalance}`**（否则 guarantee 静默落 deposit 分支→ anchoredBounds 锚错→期初期末流水全错却自洽）。
- `export` 的 `account_label`(`:164`) + `$slug`(`:176`) 同步加 guarantee 分支。
- `index.blade` `$typeLabels` + 导出 `file-exports/nezha-reconciliation.blade.php` `$labels` 补 `guarantee_deposit/guarantee_refund/deposit_refund/ad_refund` 中英标签，grep 零残留 key。
- 退还三笔 `balance_after` 精确=0（对账归零点不断裂）。

---

## H. 审批异步二次闸 + 审计 + 被动红旗（应修）
- **异步二次闸**：settlement 天然是持久工单(非一键)+20 天冷静期=已含异步；高档(5000)/大额**强制次日转 + 邮件/TG 二次确认链接**（防盗号/钓鱼一次点两下就付）。
- **审计**：押金档位设定/变更写审计（决策人/档位/来路/理由/时间）；offboard 审批写 `approved_by/approved_at`。
- **被动红旗**（零定时成本，纯查询）：对账/风控页高亮"单量/GMV 超阈值但押金档=豁免/500"的店，超管每次打开即见（治"高单量店敞口长期偏低无人发现"）。

---

## I. 实现顺序建议（灰度）
1. Schema 迁移（A1–A4，`hasColumn` 守卫，可回滚）+ 模型 casts/fillable + 对账三分支(§G，纯读、先上不碰资金)。
2. 押金缴纳记账（超管后台，对称 store_recharge）+ 对账押金 Tab 点亮。
3. 退出结算状态机（B）+ 锁/冻结（C）+ 抵扣隔离（F）——**核心资金腿，staging 下单 harness 全绿才上**。
4. 制裁门（D）+ 来路/退出门（E）+ 审批闸/审计/红旗（H）。
5. 全程 staging 验证；实装时同步 `NezhaL1RedlineTest`（加制裁门 + 法币-only + 隔离豁免断言）。

---

## J. 留给下一轮 /debate 的开放点
1. 🔴 **跨 vendor 身份匹配 vs 加密 PII**（E1）：`encrypted` cast 不能 SQL 搜索 → 用 `HMAC(证件号)` 确定性指纹明文列做等值匹配？盐/密钥管理？误匹配(同名不同人)/漏匹配(证件号录入差异)如何权衡。这是 D 能否真落地的关口。
2. `restaurant_offboard_settlements` `UNIQUE(vendor_id)` vs re-onboard：re-onboard=新 vendor 行成立否？若同 vendor 复用需换 `UNIQUE(vendor_id,generation)`。
3. **冻结 settling 停接单**与 `nezha_deposit_below_threshold` 接单门的交互：先停接单再置零的次序，防"能收单但余额已归零"窗口（红队补充观察）。
4. **罚款(penalty)** 从哪来：deduction 里"未结佣金"清楚(commission_deduction 累计)，"罚款"目前代码无此概念，需先定义来源/写入路径，否则 net 公式里的罚款项悬空。
5. 门②"真实相关性甄别"的判定标准：谁、多快、被驳回举报的申诉回路（别把"防恶意举报"做成"商家可甩掉真实举报")。
6. 非直付(网关)单若存在：其 refund_requested 是真实退款(合法阻断退出)非滥用——确认哪吒是否有非直付订单，无则 E 只剩举报/风控两门。

---

## K. 第二轮 /debate 结论（2026-07-01 · 三路独立核验 DESIGN 本身）
> 三路红队(资金正确性/合规落地/实现可行性)独立核验本设计。**三方 verdict 一致：需补后可实现**(骨架被三队独立确认站得住,非推倒重做;下列 5🔴+9🟡 补齐后可进实装)。完整报告存本地 scratch debate2_1/2/3。

### K.1 🔴 必修(写代码前解决)
- **K-A 冻结接缝错+覆盖不全**(资金+实现双队·最强交叉):§C1 让 `nezha_commission_active` settling 返 false——但 `nezha_deposit_below_threshold:2308` 首行依赖它→ `nezha_store_paused` 返 false → **settling 店照样接单**(诉求反了);且真正污染净额的 `refund_reversal:740` 门是 `is_direct_pay` **压根不看** commission_active。全仓 6 条写 deposit_balance 只堵 2 条。→**修**:停接单在 `nezha_store_paused` 独立加 `||offboard_status==='settling'`;停扣佣在 `OrderLogic:276` 调用点独立加判断;补全漏的 4 条(refund_reversal/广告下架退费/admin手改/ChargeAdOnStart cron);**并加"paid 事务内重读真实余额 vs approved 快照,不一致就 abort 转人工"兜底**(漏没漏冻结都不错退=§C 核心防呆)。
- **K-B net 公式悬空**(资金):`penalty` 概念代码中不存在,`未结佣金` 未定义(commission_deduction 下单即扣、已不在 deposit)。→**修**:本版删 penalty 项;`未结佣金` 定义为 settling 首步强制跑完在途 `settle_delivered` 后的确定值。
- **K-C 制裁门读入驻旧快照**(合规):`screen_status` 入驻当刻写,OFAC 名单每日刷新却不重算它→入驻干净、后来上名单的商家退出照付(L1-6 退款时点空转)。→**修**:§D 用当前 `nezha_sanction_names` 实时 RE-run `NezhaKycScreen::screen_names`,不读 `screen_status` 旧列;possible/inconclusive 一律 fail-closed。
- **K-D KYC 门卡死 100% 存量商家**(合规·线上数据):`vendor_kyc_profiles=0 行、restaurants=7 全 active`→§D 要 `kyc_status='approved'` 则 7/7 全被挡、连 KYC 行都没有。→**修**:补"退出申请时无 approved KYC 则先转 KYC 补录+审批子流程"前置;§I 写明这是存量店必经路径。
- **K-E 跨 vendor 身份匹配撞加密列硬墙**(合规+实现双队):KYC 字段 encrypted cast 不能 SQL WHERE,§E1 跨行聚合物理做不到。§J1 的 HMAC 指纹**不是实装细节、是 D 能否成立的地基**。→**修**:先定 HMAC 方案(证件号确定性归一化+独立密钥进 .env+漏匹配>误匹配、指纹只做辅助红标);若否决 HMAC 则 §E1 降级"人工红标辅助"、删自动阻断承诺。

### K.2 🟡 需补(实装期落地)
UNIQUE(vendor_id) 挡 rejected/failed 重试→放宽"仅活跃一条唯一"(生成列) · partial 恢复腿级幂等(照抄 `ChargeAdOnStart:81`) · 户名核对仍人肉读 free-text(拆 `account_holder_name` 或审批页 enforce 勾选+审计) · `original_ref` 留存无显式豁免断言(INVARIANTS L1-8⑤+迁移注释+守卫测试) · §E1 漏第 3 条建店路径 `VendorController:148`(onboard_source 加显式 default 非 NULL+三路径都写) · §G 漏 8 处 `index.blade` `$account===` 二元分支(Tab/余额卡/汇总/流水标题/充值说明→全三态化或数据驱动) · 迁移锁窗+部署顺序(§I 明确随 `nzdeploy-api.sh` migrate、5.7 nullable+default 走 INPLACE) · 缴纳记账压成一句话(新增 §B' `store_guarantee` 完整设计) · balance_after=0 vs anchoredBounds 倒推偏差(退出时写对齐调整流水)。

### K.3 🟢 设计的担忧被证伪(别过度改)
§F 怕 ad_refund 撞断言:`NezhaL1RedlineTest` 无 ad 断言、`NezhaAdAuctionTest:test_9` 只 sum `ad_click_fee`,ad_refund 新 type 不撞(加正向断言别删 test_9) · §C4 怕每单多查 restaurants:`settle_delivered:411` 已 loadMissing restaurant,不产生额外查询。

### K.4 ✅ 三队独立确认站得住(骨架别推翻)
§0 直付 410 终态(三队实测) · UNIQUE 幂等根方向(资金+实现) · §C2 lockForUpdate 成熟范式(资金+实现) · §F 隔离守 INV-1(三队) · §D 制裁门查主体状态方向对(三队,读法要改) · §G controller 侧 match 修对(三队) · 迁移全 additive 可回滚 greenfield(实现) · §H 无定时任务避开 bootstrap 坑(实现)。

### K.5 下一步:DESIGN v2(待用户拍板 3 处后修订)
需用户定:① penalty 删还是先定义(建议删) ② 存量商家 KYC 门(强制补建 vs grandfather) ③ HMAC 指纹(做 vs D 降级人工)。定后出 DESIGN v2 折叠 K.1+K.2,再评估是否第三轮 debate。

---
*相关：`docs/PLAN_merchant_offboard.md`（政策/§8 红队/L1 决策）· `INVARIANTS.md` L1-8 · `OrderLogic.php`(扣佣/返佣 lock+tx 模式) · `NezhaSanctionScreen.php`(制裁引擎) · `Vendor/NezhaDepositController.php`(对账) · memory `project_nezha-merchant-accounts-reconciliation-refund`。*
