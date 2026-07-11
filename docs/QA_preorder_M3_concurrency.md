# 预约下单 M3 边界① — 取消并发正确性 · staging 验证凭据

> **为什么是「计划」而非单测**:本仓 `phpunit.xml` 的 sqlite/`:memory:` 两行被注释 → 测试跑在**生产库**上;
> 且 M3 的 `DB::transaction` + `lockForUpdate` + `save` **本质需要真库并发**(单进程 PHPUnit 造不出平行写)。
> 故 M3 的并发正确性**只能在 staging(独立库)用平行请求 harness 验**,不能进 CI 单测。本文件 = 翻 `nezha_preorder_status` 前必跑的验证清单(正本 CHANGELOG 2026-07-11 M3 条与 memory `project-nezha-preorder-scheduled-delivery` 已挂账 ⬜)。
>
> 代码已在产同款范式(`OrderTimeoutSweep.php:200-213`),M3 提交 `a625c41` 已经全上下文代码审计确认逻辑正确;本清单是把「代码看着对」升级为「平行请求跑过」。

## 触点
- 顾客侧 `POST /api/v1/customer/order/cancel-order` → `Api/V1/OrderController::cancel_order`(:1048;锁内复核 `order_status ∈ [pending,failed]`,输→409)
- 商家侧 `restaurant-panel/.../status` → `Vendor/OrderController::status`(:691;锁内复核 `order_status === 加载时值`,输→Toastr+back)

## 前置(staging·独立库·绝不在生产跑造单)
1. 确认 staging `APP_ENV` 指向 **staging DB**(非 `api.nezha.am` 生产库)。
2. 造一张 `offline_payment` 预约单(`scheduled=1`,`schedule_at` = 未来窗口),上传凭证使 `offline_payments` 有 pending/verified 行。
3. 记下 `order_id`。

## 三个竞态 × 各 ≥20 轮

**Race 1 — 顾客取消 vs 商家接单(同一 `pending` 单)**
- 同刻并发:`curl 顾客cancel & curl 商家status→confirmed & wait`,循环 20 轮(每轮重置单为 pending)。
- ✅ 通过判据(每轮都须成立):
  - 恰一方落地:顾客赢→`order_status=canceled` + **恰 1 条** `record_direct_pay_refund_pending` 留痕 + `offline_payments=canceled`;商家收 Toastr「已被更新」不覆盖。
  - 商家赢→`order_status=confirmed`;顾客收 **409**「can_not_cancel」+ **0 条**退款留痕。
  - **永不并存**:`confirmed/processing`(真去送货)却挂着 `record_direct_pay_refund_pending`(顾客以为已退款)。

**Race 2 — 反向时序(商家先 confirmed,顾客随后 cancel)**
- 顾客必得 409(单已非 pending),**0 条**退款留痕。

**Race 3 — 顾客双击(两个 cancel 同刻)**
- 只 **1 条**退款留痕、`offline_payments` 只翻 1 次 canceled(锁内复核 + `whereIn` 幂等双层)。

## 校验查询(每轮后 or 汇总)
```sql
SELECT order_status FROM orders WHERE id = ?;                       -- 期望单值, 不矛盾
SELECT COUNT(*) FROM /* 待退款留痕表 */ WHERE order_id = ?;          -- 期望 ≤ 1
SELECT status, COUNT(*) FROM offline_payments WHERE order_id = ? GROUP BY status;
```

## 结论门
20×3 轮全部满足上述判据 → M3 并发正确性**实证通过**,可纳入 `nezha_preorder_status` 翻闸前的 go/no-go。任一轮出现「双退款留痕」或「送货态并存待退款」= 🔴 阻断,回 revert `a625c41` 复审。

> 附:11-6(confirmed 预约单顾客自助取消白名单)在 M6 落地后,须把 Race 1/2 的「起始态」扩展到 `confirmed`(窗口前 2h)重跑一遍。
