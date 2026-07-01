> ⚠️⚠️ **已废弃 / SUPERSEDED（2026-07-01 · 商家订单页窗口核实后）—— 请勿据此写代码** ⚠️⚠️
>
> 核实线上代码后发现：本单要建的 `predecline_order` 与**既有** `reject_order`（`app/Http/Controllers/Vendor/OrderController.php:749`，路由 `vendor.order.reject`）+ 详情页按钮（`resources/views/vendor-views/order/order-view.blade.php:1165`，`@if(in_array($order->order_status,['pending','confirmed']))`）**完全重复**。既有 reject 已覆盖"商家付款前对未付单一键拒 · 零退款"——`finalize_cancellation` 按 OfflinePayments **记录存在性**分叉（本单 §4/§5 的洞察正确，但它恰恰解释了**为何 reject 本就安全、无需新 guard**：未付单无记录→天然走无退款分支；垃圾凭证单有记录→安全按"有记录"处理不丢钱）。§1「商家对 pending 单无任何主动拒动作」前提**证伪**。
>
> **已落地的实际动作**：给既有 reject 补了**列表页快捷入口**（= 本单 §7 标"可选"那条）——列表行末「⋯」菜单里的「🚫 拒接本单」，复用 `vendor.order.reject` 零后端改动，提交 `bda8c7c` + `1822766`（deploy 上线 · 真机验证 formAction 精确指向所点订单 · console 0）。**本设计单仅存档留证，勿再实现 predecline_order**（否则详情页会出现两个拒单按钮=重复红线）。
>
> ---

# 哪吒 — 商家「无法配送此单」付款前主动拒单 (vendor_predecline) — 实现交接单

> 交接自「配送范围/远单」讨论窗口 → 商家订单页窗口（你正在改 order-view/list.blade + en messages）。
> 我方已把方案设计死、锚点核实完（下附 file:line），但按多窗协调不动你 WIP 的 blade，故整块交你顺手实现。
> 状态：**方案就绪·未写代码**。用户已批"自审直接做"（不必再跑 /debate）。

## 1. 为什么做（背景，冷启动可读）
- B方案：顾客直付商家、平台不碰钱。配送 = 商家手动深链叫 Yandex，**不依赖 API**（`0763138`/`1fcd26a`），提前不知费用/可达性（有意接受）。
- zone 只能粗圈多边形（现 2 个生效区 Yerevan / Yerevan Center）。"区内但很远 / Yandex 叫不到车"的单会漏进来。
- 现流程：离线单落地 `order_status='pending'`（`app/Http/Controllers/Api/V1/OrderController.php:245`）→ 顾客付款传凭证 → 商家 `confirm/deny_offline_payment`。商家的拒收闸 `deny_offline_payment` **要求凭证已 pending（=顾客已付）**，所以商家想拒远单时钱常已在手 → 只能退款（USDT 退款尤其麻烦，触 L1）。
- **空洞**：顾客付款**之前**那段 pending 单，商家没有任何"主动拒"的动作，只能干等 10min 自动取消。
- **A = 补这个动作**：让商家在顾客付款前一键「无法配送此单」→ 干净取消、**零退款**。

## 2. 范围（只做 A，别扩）
不动付款时序（顾客仍可即时付）·不加距离阈值/半径·不碰 D/E（配送中/已出餐）·不改"全流程付款前置闸"（B/C 方案已否）·不碰已付单退款腿。

## 3. 触发 guard（A 的全部安全性在此，只对"真·付款前"单开放）
```php
$order->order_status === 'pending'
&& $order->payment_method === 'offline_payment'
// 🔴 关键：顾客尚未发起离线付款（无 OfflinePayments 记录）= 真"付款前"。
//    不能只判 !hasPaymentProof —— 见 §5 兜底陷阱。
&& \App\Models\OfflinePayments::where('order_id', $order->id)
       ->whereIn('status', ['pending','verified','denied'])->doesntExist()
&& !\App\CentralLogics\NezhaOrderTimeout::hasPaymentProof($order)  // 冗余第二重(内容级)
&& $order->restaurant_id === \App\CentralLogics\Helpers::get_restaurant_id()  // 本店鉴权
```
- 若已有 OfflinePayments 记录（顾客已发起/已传凭证）→ **不是付款前了** → 拒绝 predecline，Toastr 提示"顾客已提交付款凭证，请改用『拒收/打回』并按需退款"，落回现有 `deny_offline_payment` 腿。

## 4. 动作（新方法 `Vendor/OrderController::predecline_order` + `OrderLogic`）
1. 事务内 `lockForUpdate` **复检** §3 全部条件（防三方竞态：顾客同时发起付款 / `OrderTimeoutSweep` 同时到点自动取消）。参照 `OrderTimeoutSweep::cancelOrder` 的 `lockForUpdate` + 状态复检写法（对称，谁先提交谁赢，另一方复检失败即放弃）。
2. 复检失败（已有付款记录 / 非 pending / 非本店）→ 中止 + 上面的 Toastr。
3. 通过 → 调 `OrderLogic::finalize_cancellation($order, 'vendor_predecline', $reason, $note, $vendorId)`。
   - `finalize_cancellation`（`app/CentralLogics/OrderLogic.php:1320`）内建分叉：**有 OfflinePayments 记录→退款留痕+通知；无→纯取消不退款**。§3 guard 已保证"无记录"→天然走**无退款**分支。
   - 兜底第二网：即便有记录漏进，它自己会检出→转退款留痕，**不会丢顾客钱**。
4. 顾客通知：`finalize_cancellation` 末尾已 `send_order_notification`(canceled)。**再加一条未付专用文案**（新 `OrderLogic::notify_customer_predecline($order)`，仿 `notify_customer_cancel_refund` @ `OrderLogic.php:1356`，但**绝不提退款**）：
   > "商家无法配送到该地址，订单已取消，未产生任何费用，你可另选商家。"
   走 `is_guest` 判断（guest 无 `user_id`/`cm_firebase_token`，仿 1356 里的判空）。

## 5. 🔴 兜底陷阱（务必读，我上轮差点踩）
`hasPaymentProof`（内容级：截图 file 非空 或 含"哈希"text 过 64 位 hex）与 `finalize_cancellation` 的判据（**存在**任一 OfflinePayments 记录 status∈[pending,verified,denied]）**不是一回事**。一张顾客填了垃圾凭证（无效 hash、无图）的单：`hasPaymentProof=false` 但 OfflinePayments 记录存在 → 若只用 `!hasPaymentProof` 当 guard，`finalize_cancellation` 会**误生成退款留痕**（凭空造退款义务）。∴ §3 guard 必须用"**无 OfflinePayments 记录**"做主判据，`!hasPaymentProof` 只做冗余。

## 6. canceled_by 新值
`'vendor_predecline'`（与 customer / system_timeout / vendor / vendor_reject 区分，后台/报表可识别"商家付款前拒单"）。`canceled_by` 是自由字符串列，加值安全。

## 7. UI（在你正改的 `resources/views/vendor-views/order/order-view.blade.php`）
- **仅当 `pending` + §3 guard 成立时**渲染「无法配送此单」按钮（后端应下发一个 `$nzCanPredecline` 布尔，别在 blade 里散写判据）。
- 复用你/`3c6822f` 的异步表单 pattern（fetch 同源 POST + 按钮 spinner + 不整页刷新）。
- 二次确认弹窗（破坏性动作）+ **必填原因** 下拉（超出可配送范围 / 无法叫到配送 / 其他+备注），仿 `deny_offline_payment` 的 `note required` 校验（`Vendor/OrderController.php:316`）。
- 建议顺手在 `list.blade.php` 也加快捷入口——A 的价值在"抢在顾客付款前"，列表一键更快；你在改列表，最合适。不做也不阻塞主功能。

## 8. 路由（`routes/vendor.php`，仿 `:280` deny-offline-payment）
```php
Route::put('predecline-order/{id}', [OrderController::class, 'predecline_order'])->name('predecline-order');
```

## 9. 可选 kill-switch
`business_settings.nezha_vendor_predecline_status`（默认开）。出问题不重部署可关（记得 `Cache::forget('business_settings_all_data')`）。做不做你定。

## 10. 文件改动清单
1. `routes/vendor.php` — 1 路由
2. `app/Http/Controllers/Vendor/OrderController.php` — `predecline_order()`（guard + 事务复检 + 调用；仿 `deny_offline_payment` 结构 :316）
3. `app/CentralLogics/OrderLogic.php` — `notify_customer_predecline()`（仿 :1356）
4. `resources/views/vendor-views/order/order-view.blade.php` — 条件按钮 + 弹窗 + 异步提交
5.（可选）`resources/views/vendor-views/order/list.blade.php` — 快捷入口
6. `resources/lang/zh/messages.php` + `en/messages.php` — 按钮/弹窗/顾客通知文案（zh/en 白名单手动同步）
7. `docs/ORDER_TIMEOUT_RULES.md` + `MERCHANT_GUIDE.md` — 同步"商家付款前主动拒单"入口
8. `docs/compliance/CHANGELOG.md` — 记一笔（**注明未改资金规则**）

## 11. L1 判定
只作用于**未付款**单 → 无钱流动、无退款 → L1-1（平台不碰钱）/ L1-2（退款只原路）机制未碰；复用的 `finalize_cancellation` 本身 L1 合规。归 **L3 新入口复用 L1-safe 原语**，无需 L1 批准，但仍同步 §10.7/10.8 文档 + CHANGELOG 记一笔。

## 12. 验收（我方案里的自审红队，请落测）
| 轴 | 要点 | 测法 |
|---|---|---|
| 鉴权/IDOR | B 店 token 打 A 店 order id → 须拒 | 本店 guard |
| **竞态(最关键)** | 付款/拒单/sweep 三方抢同一 pending 单 → lockForUpdate + 事务内复检"仍 pending 且仍无 OfflinePayments 记录"；有记录/非 pending 即中止 | 并发脚本；验证不丢钱、不双取消 |
| L1 丢钱 | "有记录漏进"分支走退款留痕不丢钱 | 覆盖该分支 |
| 兜底陷阱(§5) | 垃圾凭证单不得被 predecline 误造退款 | 造一张 OfflinePayments=pending 但 hasPaymentProof=false 的单 → predecline 须拒 |
| guest 单 | is_guest offline 单被拒 → 通知不炸 | guest 下单测 |
| 验证手段 | blade 编译探针 + 进程内 `view()->render()` 非 500 + `php -l`；参照 memory `[[blade-render-verify-in-process]]` | — |

## 13. 已核实锚点（省你重查）
- `OrderLogic::finalize_cancellation` @ `app/CentralLogics/OrderLogic.php:1320`（paid/unpaid 分叉，reuse 目标；§4/§5）
- `OrderLogic::notify_customer_cancel_refund` @ `:1356`（已付通知，仿它写未付版 `notify_customer_predecline`）
- `confirm/deny_offline_payment` @ `app/Http/Controllers/Vendor/OrderController.php:273 / :316`（方法结构 + note required 模板）
- deny 路由 @ `routes/vendor.php:280`
- 顾客离线单初始 `pending` @ `app/Http/Controllers/Api/V1/OrderController.php:245`（注：`PlaceNewOrder.php:862` 那个 'confirmed' 是 `placePosOrder` 商家 POS 路径，**不是**顾客单）
- `OrderTimeoutSweep::cancelOrder`（`app/Console/Commands/OrderTimeoutSweep.php` ~178：`lockForUpdate` + 状态 in ['pending','confirmed'] 复检 + `$paid=false` 分支只把 OfflinePayments pending→canceled 不退款——**这是 A 想要的"未付干净取消"语义参照**）
- `NezhaOrderTimeout::hasPaymentProof`（"有效凭证"定义，OrderTimeoutSweep ~58 有调用）
- 异步表单 pattern：commit `3c6822f`
- 决策上下文正本：`docs/ORDER_TIMEOUT_RULES.md`（订单生命周期已定调，2026-06-19 批准）

## 14. 我明确没做/没碰
未写任何代码；未碰你的 WIP（order-view/list.blade/en messages）；未改付款时序；未加半径。有疑问在 AGENTS 认领区 @ 回我这窗即可。
