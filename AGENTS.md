# AGENTS.md — 哪吒多窗口并发协调约定（所有 AI 窗口必读）

> ⚠️ 本服务器是**单一共享工作目录**，可能同时有多个 AI 窗口（Claude Code / Codex / …）在改同一批文件。
> 没有这份约定，两个窗口会互相覆盖未提交改动、或构建时把对方的半成品一起推上线（已多次发生：构建带半成品 → 全站 500）。
> **每个窗口开工前先读本文件，并遵守下面四条。**

## 0. 构建方式：排队脚本自行构建（不依赖人盯）〔2026-06-16 定〕
- 构建上线**一律走排队脚本**：`node nz.js run "bash /www/wwwroot/nezha.am/nzbuild.sh"`，**绝不裸跑 `npm run build`**。
- 脚本用 **flock 串行化**：两个窗口同时要构建会自动排队，不会并发写坏 `.next`（那才是全站 500 的真凶，已被它解决）；失败不切换、健康门自动回滚。
- **任何窗口都可自行构建，不需要等谁手动把关**——用户不一定在电脑前。
- 残余风险（构建可能带上别人未提交的半成品）靠第 1 条「一窗一提交不留 WIP」降到最低，不靠人盯。

## 1. 一窗一提交（不留 WIP）
每改完一处、**Playwright 验证通过后立刻 commit**：精确 `git add <自己的文件>`（**严禁 `-a` / `-am`**，会打包别人的改动），写清描述后 commit + push。
**绝不让自己的半成品在工作树里过夜**——别人随时可能构建，你的半成品会被一起推上线。

## 2. 构建前扫一眼（排队锁已兜底并发）
构建前 `git status` + 看 `nzbuild.sh` 的 `[drift]` 段：
- `[drift]` 标 🔴（文件被改回旧版 / 落后 HEAD）→ **先核对再构建**，否则会把旧版出货上线。
- 看到别人未提交的 WIP：能等它提交更稳；**但不必为此死等人**——排队锁保证不会并发写坏，最坏是带上对方半成品（非 500，真挂了健康门会自动回滚）。
- 真正的防线是第 1 条：大家都一窗一提交、不留 WIP，就基本不会带到别人半成品。

## 3. 先认领再动手
动某文件/页面前，到末尾「认领区」加一行（改完即 commit+push 这一行；登记前先 `git pull`）。其它窗口动手前先扫这区，撞了先避让或找人协调，干完划掉自己那行。

## 4. 跨全站改动要打招呼
改 theme / navbar / `_app` / 全局样式 / `nzbuild.sh` / `next.config.js` 这类**牵一发动全身**的东西，先在认领区显著标注——它几乎和所有页面冲突，别人没法躲。

## 出事了
- 不确定工作树里别人的改动能不能动：**停，问人**，别赌。
- 构建挂了 / 全站 500：先 `cat /tmp/nezha_build_last.log`，多半是带了半成品。

---
## 认领区（动手前登记，干完划掉）
<!-- 格式： - [ ] 窗口X 正在改 <文件/页面>（YYYY-MM-DD HH:MM） -->
- [x] 窗口(F-4) 已完成(提交 6a94cab) 改过 OrderLogic.php / Admin·Vendor OrderController / vendor order-view·_sidebar / routes·docs —— 直付单退款通知商家+留痕（2026-06-17）
- [x] Codex 已完成(提交 e2d5134) OrderLogic.php / Helpers.php —— 顾客确认收款通知去重及 Yandex 状态文案（2026-06-19 07:37→07:45）
- [x] Claude 已完成(自 Codex 接手·提交 fac6624) 客户可见内部术语去除: zh-CN 与 zh-cn/messages.php 的「契约/交易引擎/状态机/推演」共 55+55 处改普通用户语言(order_updated_successfully→订单状态已更新、order_push_title→订单状态有更新、order_placed_successfully→订单已提交、Delivered→已送达、completed→已完成、Privacy_Policy→隐私政策、subscription_*契约系→订阅系等), 保留 key/占位符/业务含义; php -l 通过, 4 词残留=0。验证: app 实发 X-localization=zh-CN(确为所改文件); 渲染页 home/profile/tracking/order-history 与实时 API(order/track·details order#12,zh-CN) 均无术语。仅改两 messages.php(pathspec), 未碰他窗 NezhaDeliveryAppeal WIP。**第二轮(提交 948b4c5)已清剩余生造黑话**: 妥投|物理结单|战绩|C端|风控介入|订单池|跳变|闭环|履约链路 共 38+38 处, 按「客户可见/管理端/代码注释」分类改写(非机械全局替换): 客户可见 refund requested→退款申请中·orders_delivered→已送达订单·THANK_YOU→感谢您的惠顾·customer wallet→顾客钱包·wallet_payment→钱包支付; 管理端设置项按原意专业化(C端→顾客/客户端·物理结单→将订单状态改为已送达·风控介入→确定要…吗, 去探针/蓄水池/编外推销员/灰产/投喂等)。前端客户面仅代码注释 PC端(保留)。两文件 php -l 通过, 9 词残留=0。验证(BUILD SlcJIP1m): 实时 zh-CN API order/list·track·details·wallet/transactions·config 均无黑话; 移动端 home/profile/order-history/tracking/info?orderId=13 渲染无黑话·无溢出·console=0。后端改即时生效(无需前端构建)（2026-06-19 10:10→11:05）

- [x] Claude(handover/picked_up超时迁移窗口) ✅已完成(后端 c1c6225, 13测试58断言PASS): 后端 NezhaOrderTimeout.php(加 handover/picked_up 阶段+45/90阈值进business_settings+describe分支,含无时间记录诚实兜底/contact_hint) + ORDER_TIMEOUT_RULES.md/compliance CHANGELOG/ADMIN_GUIDE + 新增 tests。不碰 OrderTimeoutSweep 动作层(handover/picked_up 仅展示不自动取消)。前端 TrackingPage.jsx 删本地45/90 longWait改读nezha_timeout（2026-06-19）
- [x] Claude(配送+支付方向调整窗口) ✅已完成(FE 37eb009 / BE 959d2ab) 改: 后端配送责任一律merchant(Order.php/OrderController/Helpers/OrderLogic)+去微信支付(停用offline方式id=3+Restaurant模型+VendorController+vendor/admin payment-info·wallet-method blade)。不碰messages.php(他窗WIP)（2026-06-19）

- [x] Claude(钱包/提现B方案审计窗口) ✅已完成(提交见本次) 审计商家钱包/提现并加固: admin _sidebar.blade.php 隐藏提现列表/方式菜单(false&&) + Vendor/WalletController::w_request + Api/V1/Vendor/VendorController::request_withdraw + Admin/VendorController::withdrawStatus 三处加 nezha_withdraw_enabled 硬闸(默认禁用) + docs/compliance/CHANGELOG.md 记录。不碰他窗 WIP(OrderLogic/Vendor·OrderController/messages.php/order-view.blade)（2026-06-20）

- [x] Claude(法务文案窗口) ✅已完成(提交见本次) 补 docs/legal/about-us.md + docs/legal/refund-policy.md 草稿(关于我们/退款政策),核对已上线 terms/privacy 准确性。仅写 docs/legal/ 草稿文件+本认领行,不动 live data_settings/不动前端/不构建。已发布关于我们到live data_settings+修terms星号瑕疵(<strong>)+清缓存+真机验证;退款仅靠用户协议§5不单独开页(未动refund);更CHANGELOG/ADMIN_GUIDE17。备份/root/nezha-legal-backups（2026-06-20）
- [x] Claude(商家逾期未退款兜底窗口) ✅已完成(本次提交): 新增 RefundOverdueSweep 命令+NezhaRefundOverdueMail+迁移(账本表+restaurants挂起列+settings)+后台逾期未退款列表; 改动共享文件: Api/V1/OrderController.php(接单闸加一条match臂+helper) / Vendor/OrderController.php(mark_refunded自动解除挂起) / bootstrap/app.php(调度) / zh messages.php(1条key) / docs (2026-06-21)
- [x] Claude(订单取消机制窗口) ✅已完成上线【顾客接单后申请取消 + 商家拒单】: 迁移 orders nezha_cancel_*5列 + OrderLogic::finalize_cancellation/notify_customer_cancel_refund + Api cancel_request 端点 + track nezha_cancel meta + Vendor reject_order/cancel_request_decision + 补 status:541 留痕缺口 + 路由 + order-view.blade(顾客取消申请同意/拒绝 banner + 商家拒单按钮)。后端25项断言全过(三路+L1零平台退款)·route 401 live·前端 BUILD Z_n44055 三态验过。提交: 后端 d040747(被逾期未退款窗口共享index一并提交)+229ea64(补cancel_request方法体)+eadbecd(文档), 前端 6b02277。未碰他窗WIP（2026-06-21）
- [ ] Claude(逾期未退款阈值设置页+角标修复窗口) 正在做: admin overdue.blade加阈值设置卡+NezhaRefundController::overdueSettings+admin路由overdue.settings + vendor _sidebar.blade待退款角标改与列表同口径(只数订单存在的) + 删孤儿退款留痕#20 + ADMIN_GUIDE19.1 (2026-06-21)
