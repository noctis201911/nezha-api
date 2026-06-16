# 交接提示词 — 直付单退款「通知商家退款 + 留痕 + 商家标记已退款」

> 来源：2026-06-16 资金闭环 QA 的 F-4 遗留缺口。把这整段贴给一个新窗口即可开工。
> 读法（任何窗口）：`node nz.js run "cat /www/wwwroot/api.nezha.am/docs/handoff/refund-merchant-notify-prompt.md"`

---

任务：给「直付单(offline_payment)退款/取消」补一条「通知商家退款 + 留痕 + 商家标记已退款」闭环。

背景（B方案 L1-1）：顾客的钱直付商家本人账户，平台不碰钱。直付单退款=商家原路退回原付款人，
全程在平台外。现状缺口：admin 取消/退款直付单后，平台不通知商家去退、也没有"商家已退款"的留痕。

🔴 开工先读：INVARIANTS.md(L1-2 退款只原路 / L1-3 USDT 只退原地址) + docs/compliance/business-flow.md §4
   + 后端 git log。触及退款=L1，改前停下向用户说明取得批准，改后记 docs/compliance/CHANGELOG.md。

确切落点（已 grep 出）：
- Admin\OrderController::update_order_status（约636行）：'canceled' 分支(约842行)调
  OrderLogic::refund_before_delivered；'refunded' 分支(约711行)调 OrderLogic::refund_order +
  NezhaRefundControl::lock_route/check_limits + 写 NezhaRefundRecord(仅当 nezha_refund_control_status=1)。
- OrderLogic::refund_before_delivered 已在开头对 offline_payment 直付单 return true(F-4a，平台不动钱)。
- OrderLogic::refund_order 对直付单已对称冲销佣金(refund_reversal)，不动平台现金桶。
- 商家订单视图：resources/views/vendor-views/order/*（commit d672602 已加确认收款/拒收按钮+付款截图预览，仿此加按钮）。
- 通知套路：OrderLogic::notify_offline_payment_result + Helpers::send_push_notif_to_device。
- 留痕模型：app/Models/NezhaRefundRecord.php；护栏 app/CentralLogics/NezhaRefundControl.php。

🟡 关于"商家怎么退款 / 要不要客户联系方式"（已查清，直接用，别再加新字段）：
- 🔴 注册不采集手机号(邮箱/Google/Apple 登录, users.phone 是 StackFood 遗留列、真实注册留空)；但**收货地址表单必填电话**(前端 ValidationSchemaForAddAddress), 故**配送单 delivery_address 里有顾客填的电话**(为配送/呼叫用, 非注册字段), 另含 contact_person_name / contact_person_email。注册顾客必有邮箱(登录身份); 游客无账号邮箱、只有地址里必填的电话。
- 顾客付款时上传的**付款凭证截图**已存（offline_payments），商家订单页已能预览——截图上能看到付款人。
- 退款怎么走：
  · USDT → 退回链上**原 from 地址**（地址在原 tx 里，无需联系方式；L1-3）。
  · 微信/支付宝(人民币) → 商家在自己微信/支付宝里：原收款记录若支持「原路退回/退款」就一键退；
    若不支持(个人收款码常见) → 商家用顾客的微信/支付宝账号**重新转账**退回，账号来自
    ①付款凭证截图 ②按订单里的邮箱/地址电话联系顾客索取(注册顾客优先用邮箱)。
- 结论：**不需要新增"客户联系方式"字段**。商家退款卡上展示"已有的邮箱(注册顾客必有) +（配送单）地址电话 + 付款凭证截图 + 原路信息"即可。

要做：
1) 当 admin 取消/退款一个直付单(offline_payment)时，无论 nezha_refund_control_status 开关如何，
   都创建一条 NezhaRefundRecord(status='pending_merchant_refund')，记原路通道(rmb/usdt)/锁定地址(USDT 反查原 from 地址)/
   金额≤原单/顾客联系(取自 delivery_address)。
2) 给商家推送 + 在商家订单视图显示"待你退款"状态卡，卡上展示：应退金额、原付款渠道(微信/支付宝/USDT)、
   USDT 须退回的原地址、顾客 contact_person_number/email、**顾客上传的付款凭证截图**(复用 d672602 的预览)。
3) 商家端加"标记已退款"按钮(可选填 USDT 退款 tx hash / 备注)→ 把 NezhaRefundRecord 置 'merchant_refunded' 留痕。
   USDT 若填 tx hash，复用 NezhaRefundControl 的链上校验(可后置)。
4) 平台全程不碰钱(L1-1)：本功能只做通知/留痕/状态流转，绝不引入平台代退/平台钱包退款。

验收：①admin 取消直付单→生成 pending_merchant_refund 记录 + 商家收到推送 ②商家视图看到待退款卡(含原地址/顾客电话/付款截图)
   ③商家点已退款→记录转 merchant_refunded ④全程 adminWallet/平台钱包零变动(事务回滚仿真验证)
   ⑤更新 ADMIN_GUIDE + MERCHANT_GUIDE + docs/compliance/CHANGELOG.md。
