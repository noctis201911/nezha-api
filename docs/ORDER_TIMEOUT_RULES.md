# 哪吒外卖 — 订单超时规则 (ORDER_TIMEOUT_RULES)

> 目的：杜绝订单"无限停留在待接单 / 备餐中 / 出餐后未配送 / 配送中未送达"。规则**集中在后端一处计算**（`app/CentralLogics/NezhaOrderTimeout.php`），
> 供两处共用：① 订单详情 API 下发顾客可见状态（展示层）② 每分钟兜底任务 `nezha:order-timeout-sweep` 执行自动动作（动作层）。
> **禁止在前端写散落计时器**——前端只渲染后端下发的 `nezha_timeout` 对象。
>
> 等级：本规则含**自动取消 + 退款留痕**，触及 L1-1（平台不碰钱）/ L1-2（退款只原路退）。已于 2026-06-19 经平台负责人批准（见 `docs/compliance/CHANGELOG.md`）。

## 一、阶段划分（键控于真实字段，不臆造）

| 阶段 | 命中条件 | 时钟起点 | 含义 |
|---|---|---|---|
| **A 凭证审核** | `order_status=pending` 且 `payment_method=offline_payment` 且 offline_payment.status=`pending` | offline_payment.created_at | 顾客已下单（多为直付/离线付），等商家点"确认收款" |
| **B 付款确认后待接单** | `order_status=confirmed`（钱已确认在商家） | order.confirmed | 商家已确认收款，但尚未开始备餐 |
| **C 备餐** | `order_status=processing` | order.processing，对比 `processing_time`(ETA 分钟) | 商家已接单备餐 |
| **D 已出餐待配送** | `order_status=handover` 且 `order_type=delivery` | order.handover（**仅此真实时间列，无则不判超时**） | 餐已出好但尚未进入配送 |
| **E 配送中** | `order_status=picked_up` 且 `order_type=delivery` | order.picked_up（**仅此真实时间列，无则不判超时**） | 配送中长时间未送达 |

> 其它状态（accepted/delivered/canceled/failed/refund_*）不在本规则范围。take_away 的 handover=可取餐，非配送延迟，**不计配送超时**。
> 阶段 A 是否"已提交有效付款凭证"(`hasPaymentProof`)= ① `hasProofImage`：method_fields 标 `input_type=file` 的字段有非空文件值（截图，支付宝路径）；**或** ② `hasValidHashText`：method_fields 标 `input_type=text` 且字段名含「哈希/Hash」的字段，其值经 64 位十六进制(0x 可选)正则校验通过（USDT 链下付款主推凭证）。**二者任一有效即视为已提交凭证**，与支付抽屉 PaymentDrawer「哈希或截图」承诺对齐。乱填/非 hex 文本不算有效（避免给未真付款单凭空造退款义务）。
> **阶段 D/E 仅展示层（无动作层）**：饭已出/在配送、钱已付，按 L1 绝不自动取消，只对长时间无进展给诚实升级提示。时钟起点只认状态切换真实时间列（order.handover / order.picked_up），**无可靠记录时返回 `no_time_record` 诚实告知，绝不退用 created_at/updated_at 臆造超时**（需求3）。

## 二、规则矩阵（两层）

### 展示层（顾客可见，纯计算，无副作用、无幂等问题）

| 阶段 | 阈值 | severity | 标题/下一步 |
|---|---|---|---|
| A 无有效凭证 | 未到自动取消阈值 | info | 说明订单内已无法补交；未付款无需操作并显示动态自动取消分钟数；已付款联系商家核实 |
| A 无有效凭证 | 已到自动取消阈值 | warning | 如实说明系统正在处理；未自动取消时稍候或联系客服；已付款联系商家核实 |
| A / B | 0–5min | info | 正常等待商家确认 |
| A / B | ≥5min | warning | "商家暂未确认，已等待 X" + 引导联系商家/客服 |
| C | ETA 内 | info | "备餐中，预计 X 分钟出餐" |
| C | ETA+5min | warning(橙) | "出餐稍有延迟" |
| C | ETA+15min / ETA 未知 | error(红) | "备餐异常，已升级客服处理" |
| D 已出餐待配送 | <45min | —（返回 null，前端用正常默认文案"餐已出好，等待配送"） | 正常，不下发超时对象 |
| D 已出餐待配送 | ≥45min | warning | "已出餐较久，仍未开始配送" + 联系商家/客服，**不造 Yandex/ETA** |
| D/E 无时间记录 | order.handover/picked_up 为空 | info(no_time_record) | "无后台时间记录，无法判断是否超时"，不报假超时 |
| E 配送中 | <90min | —（返回 null，前端用正常默认文案"配送中"） | 正常，不下发超时对象 |
| E 配送中 | ≥90min | warning | "配送时间偏长，仍未送达" + 联系商家/客服，**不造 Yandex/ETA** |

> `describe()` 的每个非空对象统一增量下发只读阈值：`remind_min/email_merchant_min/cancel_min/unpaid_cancel_min`；前端时间轴使用这些值，旧接口缺字段时才回落 10/20。阶段 D/E 的业务字段仍为 `severity/title/next_step/contact_hint(联系入口建议)/refund_method(退款责任=联系商家原路退)/refund_eta/elapsed_minutes`。
> **不得声称第三方配送正在重试 / 已有骑手 / 提供虚假 ETA**（需求5）。

### 动作层（后端任务执行，**每个动作每单恰好一次**，幂等账本 `nezha_order_timeout_events`）

| 阶段 | 条件 | 阈值 | 自动动作 |
|---|---|---|---|
| **A 无有效凭证** | 顾客未提交有效凭证（无截图且无有效哈希=确定没付钱） | 10min | **自动取消**（无资金、无退款，安全）。canceled_by=`system_timeout` |
| **A 有有效凭证（图或哈希）** | 顾客已提交凭证（截图或有效交易哈希，可能已付待商家核对） | 10min | **邮件商家**催处理 + admin/客服打标通知 |
| **A 有有效凭证（图或哈希）** | 同上 | 20min | **自动取消** + 生成待退款留痕 + **邮件商家**原路退款 + 通知顾客"商家接单超时，将通知商家联系退款" |
| **B 待接单** | 钱已确认在商家 | 10min | **邮件商家** + admin/客服打标通知 |
| **B 待接单** | 同上 | 20min | **自动取消** + 待退款留痕 + 邮件商家退款 + 通知顾客同上 |
| **C 备餐** | 超 ETA+15min 或 ETA 未知(历史 processing_time=NULL) | ETA+15min | **升级客服**：邮件商家 + admin 打标通知（**不自动取消**：饭在做、钱已付，取消风险高） |
| **D 已出餐 / E 配送中** | — | — | **无动作层**：仅展示诚实升级提示，**绝不自动取消**（钱已付、饭已出/在配送，取消风险最高）。sweep 任务硬编码只扫 pending/confirmed/processing，不触及 handover/picked_up |

## 三、合规红线落实（L1）

1. **平台不碰钱**：自动取消**绝不**触发平台退款。已付款单一律走 `OrderLogic::record_direct_pay_refund_pending()`
   生成 `nezha_refund_records(status=pending_merchant_refund)` + 邮件商家原路退款。
2. **顾客文案**：永远显示**真实责任人=商家** + 客服流程，**绝不出现"自动原路退款 / 平台已退款"**字样。
   退款方式 = "联系商家原路退回"；预计到账 = "以商家退款时间为准（平台不经手）"。
3. **可逆/默认安全**：总开关 `nezha_timeout_status`（默认 1）；关 = 只展示不执行任何自动动作。

## 四、幂等设计（需求6）

- 表 `nezha_order_timeout_events(id, order_id, action, fired_at, detail)`，唯一索引 `(order_id, action)`。
- 任务执行某动作前先尝试 `insertOrIgnore`/捕获唯一冲突；插入成功才执行，失败即说明已做过 → 跳过。
- 任务 `withoutOverlapping()`，自动取消复用 `order_status` 终态判断（已 canceled/delivered 的单不再处理）。
- 自动取消 + 退款留痕 + 邮件 + 通知包在 DB 事务里，任一步失败回滚、不留半截状态。

## 五、阈值（business_settings，可后台调，测试可注入）

| key | 默认 | 含义 |
|---|---|---|
| `nezha_timeout_status` | 1 | 总开关；0=只展示不动作 |
| `nezha_timeout_remind_min` | 5 | A/B 顾客可见黄色提醒起点 |
| `nezha_timeout_email_merchant_min` | 10 | A(有凭证)/B 邮件商家 |
| `nezha_timeout_unpaid_cancel_min` | 10 | A(无凭证) 自动取消 |
| `nezha_timeout_cancel_min` | 20 | A(有凭证)/B 自动取消+退款留痕 |
| `nezha_timeout_prep_orange_min` | 5 | C 备餐 ETA+N 橙色 |
| `nezha_timeout_prep_red_min` | 15 | C 备餐 ETA+N 红色异常+升级客服 |
| `nezha_timeout_handover_min` | 45 | D 出餐后未进入配送的 warning 起点（仅 delivery） |
| `nezha_timeout_picked_min` | 90 | E 配送中未送达的 warning 起点（仅 delivery） |

## 六、测试（需求8，不等真 20 分钟）

- 后端（A/B/C 动作层）：注入 `pending/confirmed/processing` 时间戳为过去 → 跑 `nezha:order-timeout-sweep --dry-run` 验命中、跑实跑验动作 + 幂等（连跑两次第二次 0 动作）+ 边界（阈值前一分钟不触发、后一分钟触发）。
- 后端（D/E 展示层）：`tests/Feature/NezhaDeliveryTimeoutTest.php`——纯计算单测（内存 Order 实例、零入库、DatabaseTransactions 回滚，**绝不 RefreshDatabase 因连生产库**）。覆盖：阈值前不超时 / 刚好达到及超过 / 缺状态时间 no_time_record / handover·picked_up·delivered·canceled 边界 / take_away 豁免 / 文案无虚假表述。13 测试 58 断言 PASS。
- 前端：用可控测试单（注入旧时间戳）或 Playwright route-mock 验三色态 + 下一步/退款方式文案渲染，不等真实计时。
