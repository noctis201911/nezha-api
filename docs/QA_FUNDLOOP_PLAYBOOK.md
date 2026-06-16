# 哪吒 端到端真实资金闭环 QA Playbook（第5层 · 触发词「资金闭环QA」）

> **怎么用**：用户说「资金闭环QA」→ 读本表 → 逐轴走 → 每轴要证据 → 输出「分轴报告」+「给用户亲手补验」清单。
> **读法**：`node nz.js run "cat /www/wwwroot/api.nezha.am/docs/QA_FUNDLOOP_PLAYBOOK.md"`
> **定位**：这是 QA_MASTER 第5层。验的是「下单→真付商家(RMB/USDT)→商家确认→扣佣→退款」**用真金额走通且账目对称闭合**。
> 与第4层「资金合规QA」分工：第4层查机制是否真生效（PII真删/制裁拒/二清拔/退款只原路）；本层查**钱的闭环算账对不对、对称不对称、扣穿/并发/悬空**。

## 0. 闭环地图（B方案 直付商家，平台不碰钱）

| 环节 | 触发 | 代码 | 钱的动作 |
|---|---|---|---|
| ① 下单 | 顾客提交 | `Api/V1/OrderController::order_validation_check` → `nezha_deposit_below_threshold` | 扣佣开关开时，`deposit_balance < threshold` → 403 停接单；不动钱 |
| ② 付款+凭证 | 顾客转账给商家本人后上传 | checkout 创建 `offline_payments`(status=pending) | 钱进**商家本人账户**，平台不经手 |
| ③ 商家确认收款 | 商家点「确认收款」 | `OrderLogic::confirm_offline_payment`（admin 后备同源） | paid/confirmed/verified + 通知顾客 + 留痕；**不扣佣** |
| ④ delivered 扣佣 | 订单标记送达 | `Vendor/Admin OrderController` delivered 分支 → `OrderLogic::create_transaction`(received_by=admin) | `is_direct_pay` 分支：**只**从 `deposit_balance` 扣佣金、写 `commission_deduction` 流水；**不**加 total_earning / digital_received |
| ⑤ 退款 | admin 退款/取消 | 已送达→`refund_order`；未送达取消→`refund_before_delivered` | `refund_order` 对称返还佣金(`refund_reversal`)；直付单**不走平台钱包**(`!$isDirectPay`) |

- 扣佣率：`restaurant.comission` 优先，否则回退 `admin_commission`（当前 =10%）。佣金基数 = 食品小计（扣除配送/税/小费/加料费等后）。
- 关键开关（线上现值见每次跑的探针）：`nezha_deposit_mode_status`(扣佣总开关,默认0关) · `nezha_min_deposit_threshold`(停接单阈值) · `wallet_add_refund`(平台钱包退款,B方案应保持0) · `nezha_refund_control_status`(退款护栏②)。

## 🔴 1. 安全协议（本层特有，违反即可能搞坏生产资金/合规）

1. **绝不在生产乱翻全局开关**：`nezha_deposit_mode_status` 等是 `business_settings` 全局行，开了会**立刻影响所有真实商家**（低押金店停接单、真实单开始扣佣）。属"真实影响开关"，**改前必须用户明确批准**（CLAUDE.md / INVARIANTS L2）。
2. **要实跑闭环用「事务回滚仿真」**：把 `create_transaction`/`refund_order`/`nezha_deposit_below_threshold` 包在 `DB::beginTransaction()` … 结尾无条件 `DB::rollBack()` 里跑（开关也在事务内临时设，回滚即消失，其他并发连接读已提交数据看不到）。**零落库**。
3. **仿真也算 money-write，需用户批准**：即便回滚，写生产财务库/翻合规开关仍属高风险，auto 模式分类器会拦（正确）。跑前向用户取得明确批准。
4. **不调会发通知的方法**：`confirm_offline_payment` 会给真实顾客发推送/邮件。仿真只验钱的路径时，用手工置状态代替，**不要真调它**（避免骚扰真实顾客）。
5. **不能用真单走真流程"试一笔"**：会真扣真退真通知。只能事务回滚仿真，或在隔离的 staging（当前无）。

## 2. 分轴清单（逐条要证据）

- **A 下单 gate**：开关开 + 余额<阈值 → 下单返回 403 `restaurant_temporarily_unavailable_low_deposit`；余额≥阈值 → 放行。证据：`nezha_deposit_below_threshold()` 真返回值。
- **B 凭证创建**：选 offline 下单后 `offline_payments` 必有一行(status=pending)、`payment_info` 含 method_name。证据：表行。⚠️ 无此行时 `confirm_offline_payment` 会 fatal（见 F-1）。
- **C 商家确认**：`confirm_offline_payment` 后 order=paid/confirmed、`offline_payments`=verified、顾客收到「支付已验证」、`logs` 有 by_type=vendor 留痕；**deposit_balance 不变**（确认不扣佣）。
- **D delivered 扣佣**：标记 delivered → `order_transaction` 生成(admin_commission>0)、`deposit_balance` 恰好减佣金、`commission_deduction` 流水 balance_after 与钱包一致。幂等：`$order->transaction==null` 守门，重复标 delivered 不重复扣。证据：扣佣前后余额差 == admin_commission。
- **E 退款对称**：已送达单退款 → `refund_reversal` 流水 + `deposit_balance` 恢复到扣佣前 + `admin total_commission_earning` 冲回；幂等：`refunded/failed` 单不可再退（controller 守门）。直付单退款**不**进顾客钱包（`!$isDirectPay`）。
- **F 边界/异常**（已知隐患，见 §3）：扣穿成负 / 并发 lost-update / 无凭证行 fatal / 取消路径护栏。
- **G 合规闭合**（与第4层重叠，本层只验账面）：直付单全程 `digital_received` 与 `total_earning` 不因该单变动（平台不碰钱 L1-1）；USDT 退款锁定原 from 地址（L1-2/3）；`nezha_refund_records` 留痕（L1-4 ≥5年）。
- **H 对账闭合**：∑commission_deduction（按单）== ∑refund_reversal（已退单）对冲；无悬空（delivered 单都有 transaction、扣佣流水 balance_after 链式连续）。

## 3. 已知隐患台账（每次跑核对是否仍在/已修）

| # | 隐患 | 位置 | 级别 | 状态 |
|---|---|---|---|---|
| F-1 | 无 `offline_payments` 行的 offline 单，`confirm_offline_payment` 第~700行 `$order->offline_payments->payment_info` 读 null 属性 → fatal | `OrderLogic::confirm_offline_payment` | 🟡 健壮性 | ✅ 已修 2026-06-16(nullsafe+空串守卫)，仿真回归通过 |
| F-2 | 扣佣无 `max(0,…)` 下限：gate 在下单时拦、扣佣在 delivered 时滞后，堆叠多单后余额可被扣成负 | `OrderLogic.php` `deposit_balance = (…) - $comission_amount`（~232行） | 🟡 业务策略 | ✅ 2026-06-16 用户拍板 A: 接受负数=商家欠平台佣金(不 clamp)；负余额自动触发停接单，等充值回正；admin 充值一览 + 商家端均把负值显示为"欠款 ֏X" |
| F-3 | 扣佣/返还对 `deposit_balance` 读-改-写无 `lockForUpdate`，同商家并发送达/退款会 lost-update（少扣一笔佣金） | `create_transaction`/`refund_order` | 🟡 并发正确性 | ✅ 已修 2026-06-16(扣佣/返还改 lockForUpdate 读最新余额)，仿真回归通过 |
| F-4 | `refund_before_delivered`（取消路径）缺 `is_direct_pay` 护栏：已确认(paid)的 offline 单被取消时，错误冲减 `adminWallet->digital_received`（直付从没加过）；且若 `wallet_add_refund` 开，会用平台钱包退款给顾客 = 平台碰钱 **L1-1 风险** | `OrderLogic::refund_before_delivered` | 🟡（潜在🔴） | ✅ 已修 2026-06-16(加 offline_payment 直付 no-op 护栏，与 refund_order 对齐)；仿真最坏情况 wallet_add_refund=1 下平台 digital_received 不变+无钱包退款+COD 未误伤，零落库通过。遗留: 取消直付单后"商家退顾客"无留痕，待后续 |

> 修复任一条须遵守 INVARIANTS：F-4 触及 L1-1，改前停下问用户 + 记 CHANGELOG。

## 4. 输出格式
1. **线上现状探针**（只读）：开关现值、各商家 `deposit_balance`、`commission_deduction`/`refund_reversal` 流水、offline 单状态分布、是否有负余额。
2. **分轴报告 A–H**：每轴 通过/不通过/不适用 + 证据（数字/流水/返回值）。
3. **隐患台账核对**：§3 每条仍在/已修。
4. **给用户亲手补验**：需真翻开关、真单实跑、律师/会计判断的项，列清单交用户。

## 5. 本层历史跑记
- **2026-06-16 首次建立+首跑（代码trace + 只读探针）**：闭环代码路径完整接通，账面设计对称（扣佣↔返还基于流水、直付单不碰平台现金桶、退款幂等守门）。**线上闭环从未真跑通**（扣佣开关关、0 delivered offline 单、0 commission_deduction、offline_payments 表空、仅1笔 pending 测试单 #100003）。事务回滚仿真脚本已就绪但**因属生产 money-write 被安全拦截，待用户批准后再跑**。发现隐患 F-1~F-4（见 §3）。

- **2026-06-16 修 F-1+F-3**：F-1 加 nullsafe `?->` + `?? ''` 守卫防无凭证行 confirm fatal；F-3 扣佣/退款返还改 `lockForUpdate()` 读最新余额，串行化同商家并发、防 lost-update。事务回滚仿真回归全 PASS、零落库（单线程结果与改前一致）。F-2(扣穿成负)待业务拍板、F-4(取消路径 L1-1)待批准后再动。

- **2026-06-16 修 F-4a(L1-1)**：`refund_before_delivered` 开头加直付 no-op 护栏(offline_payment→return true)，与 `refund_order` 对齐，堵死①错误冲减 digital_received ②`wallet_add_refund` 开时平台垫钱退顾客(L1-1)。事务回滚仿真最坏情况 wallet_add_refund=1 下平台不碰钱、COD 未误伤、零落库通过。已记 `docs/compliance/CHANGELOG.md`。顾客自助取消路径经查不可达(只能取消 pending 单)无需改。
