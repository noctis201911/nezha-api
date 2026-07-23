# TG 卡片 P2.2a 动作守卫映射

> 基线：`origin/main` 的代码树 `bc291647731be7f79d61bf686ce4984e666ac7e9`。
> 本文是 `HANDOFF_waimai_tg_p22a_action_buttons.md` §5.1 要求的 blocking 交付物。
> 0723 晚业主+Fable 裁决：本包正式缩为只做「确认收款 → 选择备餐时间」；其它五个动作完全不显示。`status()` 共享服务原则批准但留给 P2.2s，本包不改 Vendor 控制器。

## 1. 事实—缺口矩阵

| 拟处理的风险/需求 | 当前入口与真实路径 | 当前组件、状态单源与数据来源 | 运行产物与工作树是否一致 | 现有证据/裁决 | 真实缺口 | 最小处理方式 |
|---|---|---|---|---|---|---|
| 新单卡片增加动作 | 新单通知经 `Helpers::sendTelegramOrderAlert()` 调 `NezhaOrderTgCard::sendAndPersist()` | 文本由订单、餐厅、订单明细组成；卡片坐标在 `nezha_order_tg_cards` | 当前分支含 P2.1 提交 `1e443acb`；本次未重新核验 production release | P2.1 已有只读卡片；handoff 要求新动作闸默认 0 | `compose()` 只返回文本，发送请求没有 `reply_markup` | 在不增加 PII 的前提下扩展键盘；动作闸 0、群聊、终态时必须无键盘 |
| callback 传输与授权 | Telegram webhook 现处理 `message / edited_message`，位于 secret-token 门后 | `TelegramWebhookController`; `restaurants.telegram_chat_id` 是 chat→餐厅绑定单源 | 当前代码已含客服回复、绑定码、提示音三支；handoff 的“末位增加 callback 分支”仍适用 | secret token 只证明请求来自 Telegram，不证明订单归属 | 无 `callback_query` 分支；无 chat+restaurant+order 复合作用域 | callback data 只带版本、动作、订单 id；由 chat 反查餐厅，再按 `id + restaurant_id` 取单 |
| 接单、备餐、出餐 | 商家网页 POST 到 `Vendor\OrderController::status()` | `Order::isFinalized()` 是终态单源；其余校验、副作用、锁和通知写在控制器方法内 | 源码事实与 handoff 的“状态类动作应走核心”要求冲突 | 禁止复制状态机；禁裸改 `order_status` | 没有纯核心函数可调用 | **硬停**：先裁决抽共享服务，并让网页与 bot 共用 |
| 确认收款 | 商家网页调用 `confirm_offline_payment()` | 主状态机在 `OrderLogic::confirm_offline_payment()`；幂等前检和异常脱敏仍在控制器壳 | 当前源码与 handoff 纠正项一致 | L1-6 命中必须拒收；原始异常详情不得进 Telegram | bot 尚未重建 offline/pending 前检与固定脱敏文案 | 可复用 `OrderLogic`，但只能在 callback 总体架构获批后接入 |
| 拒单、拒收 | 网页表单分别要求 `reason` 与 `note` | 拒单核心为 `OrderLogic::finalize_cancellation()`；拒收核心为 `deny_offline_payment()` | 当前源码确认两个字段均为 required | handoff §2.12 禁止自造默认理由 | Telegram 二段确认无法提供必填自由文本 | 本期按钮只能提示“请在商家后台操作”，不得执行 |
| 一单一卡原地更新 | P2.1 已存 `chat_id/message_id/last_state/last_action_by_tg_uid` | `nezha_order_tg_cards.order_id` 唯一 | 所需列已存在，不需要新迁移 | handoff 要求 edit 失败时才允许重发并更新 message id | 当前没有 edit 方法和动作留痕更新 | 解除状态机硬停后再按官方 Bot API 契约实现与测试 |
| callback 投递 | 现 webhook 注册未纳入本次代码事实复核 | `setWebhook.allowed_updates` 是 Telegram 侧运行态 | 本次未调用 `getWebhookInfo`，也不得运行 setWebhook | handoff 要求只写同步命令、不运行 | 缺幂等同步命令 | 可独立实现，但不能把命令存在写成运行态已启用 |

## 2. 六动作守卫映射

| 动作 | 控制器层守卫/副作用 | 核心层守卫/副作用 | bot 必须重建或复用 | 正确调用点与当前裁决 |
|---|---|---|---|---|
| 接单 `confirmed` | `status()` 校验 `id/order_status`；按 session 餐厅作用域取单；拒绝 delivered/`isFinalized()`；检查配送责任与 `order_confirmation_model`；设置 `confirmed` 时间；锁内复核原状态未变化且非终态；成功后发订单通知、更新订阅日志 | **无通用核心函数**。行锁、状态写入和通知都内嵌在 `Vendor\OrderController::status()` | chat 必须是正数且已绑定；订单必须按 `id + restaurant_id` 作用域加载；动作闸、合法前态、终态、配送责任、并发锁、通知与订阅日志都不能丢 | **无可用调用点，硬停。** 不能调用 session+Blade 控制器，也不能复制/裸写状态机 |
| 拒单 `reject` | `reason` 必填、字符串、最多 500；按 session 餐厅作用域取单；只允许 `pending/confirmed`；事务包裹核心调用 | `OrderLogic::finalize_cancellation()` 写 canceled、取消来源/理由、销量和订单计数；已付直付单生成待退款留痕并通知；发送订单通知。核心本身不做餐厅作用域、必填校验、允许前态或行锁复核 | 必须有真实拒单理由；还需复合作用域、允许前态与并发复核 | handoff §2.12 明确要求：**本期降级为“请在商家后台操作”提示，不调用核心，不自造理由** |
| 开始备餐 `processing` | `processing_time` 必填、整数、最少 1 分钟；按 session 餐厅作用域取单；拒绝 delivered/终态；设置 `processing_time` 与 `processing` 时间；锁内复核；发通知、更新订阅日志 | **无通用核心函数**。全部状态写入、锁与后置副作用在 `status()` | 除 chat/订单作用域外，还需严格时长校验、合法前态、终态、并发锁、通知与订阅日志 | **无可用调用点，硬停。** 15/30/45/店默认键盘不能在没有共享核心时接线 |
| 已出餐 `handover` | `status()` 校验状态值；按 session 餐厅作用域取单；拒绝 delivered/终态；设置 `handover` 时间；锁内复核；发通知、更新订阅日志 | **无通用核心函数**。全部状态写入、锁与后置副作用在 `status()` | chat/订单复合作用域、合法前态、终态、并发锁、通知与订阅日志 | **无可用调用点，硬停。** 不得直接 `$order->save()` |
| 确认收款 `confirm_pay` | `processing_time` 可选且为 1–1440 整数；按 session 餐厅作用域加载 `offline_payments`；拒绝终态；必须是 `offline_payment + offline_payments.status=pending`；捕获 `SanctionScreenException` 并区分命中/核验中，输出固定脱敏文案 | `OrderLogic::confirm_offline_payment()` 校验链上证据；执行 L1-6 筛查，命中时拒收并抛异常、未决时 fail-closed；锁内拒绝终态；置 paid/verified 和 confirmed/processing；发送顾客/配送/商家通知并记录动作。核心**不含** offline/pending 幂等前检 | 动作闸、私聊、绑定与复合作用域；终态；offline/pending 前检；以绑定餐厅的 `vendor_id` 作为 vendor 留痕 actor；固定脱敏 catch；成功后单单写 checked、卡片留痕和原地编辑 | 核心调用点存在：`OrderLogic::confirm_offline_payment($order, 'vendor', $restaurant->vendor_id, false, $processingTime)`；但总体 callback handler 在状态动作硬停裁决前不写 |
| 拒收 `deny_pay` | `note` 必填、字符串、最多 255；按 session 餐厅作用域加载 `offline_payments`；必须是 `offline_payment + offline_payments.status=pending` | `OrderLogic::deny_offline_payment()` 直接将凭证标 denied、保存 note、通知顾客并写动作日志；核心没有作用域、幂等锁或终态守卫 | 必须有真实拒收原因；还需复合作用域、offline/pending 幂等与并发复核 | handoff §2.12 明确要求：**本期降级为“请在商家后台操作”提示，不调用核心，不自造 note** |

## 3. 状态类动作为什么不能直接复用 `status()`

`Vendor\OrderController::status()` 不是纯状态机，而是同时依赖：

1. HTTP `Request` 校验和 Blade `return back()` / Toastr 反馈；
2. `Helpers::get_restaurant_id()` 与 `Helpers::get_restaurant_data()` 的 session 餐厅上下文；
3. 配送责任、订单确认模型、终态和 delivered 等控制器层守卫；
4. 对加载中的 Eloquent 实例写时间戳/状态，再在事务中按旧状态和终态复核后保存；
5. `Helpers::send_order_notification()` 与 `OrderLogic::update_subscription_log()` 后置副作用。

callback 没有 vendor session，无法安全调用该控制器；复制上述逻辑会立即形成第二套状态机，违反 handoff §2.5、§3.2，并使网页与 Telegram 后续漂移。只抽一个“写状态”函数也不够，因为配送责任守卫、并发复核和后置副作用仍会分叉。

## 4. 已裁决的实施边界

P2.2s 后续包原则上允许做**纯重构、行为不变**：

1. 新建共享服务，输入为已解析的餐厅、订单、目标状态、processing time 与 actor；
2. 把 `confirmed / processing / handover` 的合法前态、配送责任、终态、行锁、时间戳、通知和订阅日志集中到服务；
3. `Vendor\OrderController::status()` 保留现有 request/session/Toastr 壳，改为调用共享服务；
4. Telegram callback 在 chat→餐厅复合作用域和动作闸之后调用同一服务；
5. 用既有 vendor 状态测试证明网页行为零回归，再补 callback 的 IDOR、幂等、终态和通知恰一次测试。

本包已正式采用缩范围方案：只交付 `confirm_pay`；接单/备餐/出餐/拒单/拒收按钮全部不渲染，由卡片 footer 指向商家后台。

禁止方案：

- 在 callback 内复制 `status()`；
- 伪造 vendor session 后内部调用控制器；
- 裸写 `order_status`/时间戳；
- 为拒单或拒收填平台自造默认理由。
