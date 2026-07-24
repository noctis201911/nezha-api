# 哪吒外卖 — 上线前开关清单 (PRELAUNCH_SWITCHES)

> **用途**：平台正式上线前，逐条过一遍所有"故意先关着/待决策"的开关，决定开还是关。**这是上线 go/no-go 硬门之一**，`PRELAUNCH_QA_MASTER.md` 顶部指向本表。
> **值来源**：2026-07-14 上线前只读收口，以 `php artisan nezha:switches-verify` + production `/api/v1/config` 交叉核对（非记忆）。开关值会变，改动时**同步更新本表 + 当前值 + 签收状态**；当前值不等于已批准值。
>
> 🔴 **改开关的正确姿势**：优先走后台对应设置页（改完自动清缓存）；**直接改 DB 后必须** `Cache::forget('business_settings_all_data')`，且"激活类翻转"须 `kill -USR2 $(FPM master)` 刷 worker（`get_business_settings` 进程内 static 缓存，否则间歇读旧值）。**关闭任何 L1 开关须先取得用户批准 + 记 `docs/compliance/CHANGELOG.md`。**

---

## A. 🟢 上线前【要打开】的（现在关着，上线应开）

| 开关 key | 现值 | 上线动作 | 说明 / 位置 | 等级 |
|---|---|---|---|---|
| `nezha_refund_overdue_status` | **0** | **改 1（激活）** | 商家逾期未退款兜底：自动催办+记风控+告警。配套 `nezha_refund_overdue_auto_suspend` 默认 0=手动停接单（到阈值只告警建议、由运营手动停），如要自动停改 1。后台「风控中心 → 逾期未退款」设置页。**激活=真实约束商家，先亲测催办邮件真发出**。 | L2 |
| `nezha_autooffline_status` | **1** | **当前已开；补通知真送达 + 阈值签收** | 商家长期不确认订单自动停接单：滚动窗口内商家责任超时取消达 N 单且期间无成功接单(不在场)→自动停接单，保护顾客不被继续喂给失联/不响应商家。商家自助一键/运营后台恢复（**无冷却自动恢复**·业主 2026-07-11）。与退款逾期挂起 `nezha_order_suspended` 独立(各用各列，接单闸 OR 两信号)。sweep 每 cron 直接读 DB。2026-07-14 只读核对 live=1，符合 A 类目标，**不是 D 类偏差**；但配置值不能替代通知/阈值签收。 | L2 |
| `nezha_autooffline_strike_count` | 3 | 后台可调（默认 3） | 触发阈值 N（**参数非布尔**）：滚动窗口内商家责任超时取消(cancel_paid_refund)达 N 单才触发。空/0 回落默认 3。sweep 直接读 DB，改后下一分钟生效。 | L2 |
| `nezha_autooffline_window_hours` | 2 | 后台可调（默认 2） | 滚动窗口 H 小时（**参数非布尔**）：统计 strike 的时间窗。空/0 回落默认 2。sweep 直接读 DB，改后下一分钟生效。 | L2 |
| `nezha_guides_status` | **1** | **⚠️ 2026-07-10 现已开（1）**——确认五篇攻略内容确已录入，否则本地生活页显空攻略。原计划：内容就绪后再开 | 生活攻略板块总闸（本地生活页入口条 / `/local-life/guides` 列表 / 详情 / og 分享卡）。=0 时列表空、详情走空态不 404、入口条整条不渲染。翻 1 后若读不到新值：`Cache::forget('business_settings_all_data')` + `kill -USR2 $(FPM master)`（进程内 static 缓存）。后台「生活攻略」录入内容。 | L1-1 邻（纯信息展示） |
| `nezha_listing_status` | **1** | **部署前预置为 1**（业主 2026-07-21 拍板；现网已有 10 家挂牌店、其中 9 家 `status=1` 已上架真实商家，若让总闸以 0 落地会在部署→翻闸之间把这 9 家变回站内可下单而无人接单）。🔴 **关闸不是安全回滚**：关掉即这 9 家恢复站内接单 | 外卖挂牌态总闸（2026-07-21 补，此前只有逐店 `restaurants.nezha_listing_only`，全关只能 revert + 重部署）。开=逐店开关生效：挂牌店只展示菜单、站内全部加购/结算入口不渲染 + 后端 403，顾客经 Telegram 联系商家自行下单。关=全部挂牌店回到功能上线前：`status=0` 的预建店直链 404、`status=1` 的店恢复正常接单（前后端读同一有效值 `NezhaListing::isListingOnly()`，不会出现"前端能加购、后端拒单"）。后台「商家 → 挂牌态管理」翻闸/开逐店开关/录联系方式。翻闸即时生效（直读 DB，无进程内 static 缓存），顾客侧 ISR 最长约 60 秒。🔴 真实第三方商家挂牌前须先裁定 L1-6 姓名筛查射程。 | L3（不触资金链路） |

> 〔2026-07-01 路A 已上线代码 f1a4734，机制休眠，就等这个开关。〕
> 〔2026-07-08 生活攻略批2 已上线代码（后端 4899dce + 前端），机制封印，就等 `nezha_guides_status` + 五篇内容录入。〕

---

## B. ✅ 必须【保持开启】的安全轨（上线前确认仍=1，别被误关）

| 开关 key | 现值 | 作用 | 等级 |
|---|---|---|---|
| `nezha_risk_control_status` | 1 | 下单风控（单笔/单日/频次/大额） | L2 |
| `nezha_refund_control_status` | 1 | 退款护栏（原路锁定+限额） | L1-2 |
| `nezha_refund_usdt_verify_status` | 1 | USDT 退款链上核验 | L1-3 |
| `nezha_usdt_refund_binding_mode` | **drain** | USDT 退款地址绑定三态闸：当前发布只允许 `drain`，停止新 USDT，但已有合格快照仍可按快照退款；`closed` 只读/挂起。只有律师 Q1/Q2 正式放行、`legal_gate=approved` 且另有开启授权时才能改 `enforce`。任何值都禁止 `tx.from` fallback | 🔴L1-2/L1-3 |
| `nezha_usdt_refund_legal_gate` | **pending** | 法律否决闸。`pending` 时服务端拒绝新 USDT，即使 binding mode 被误设为 enforce；收到并归档正式 Q1/Q2 结论前必须保持 pending | 🔴L1-2/L1-3 |
| `nezha_refund_reconfirm_ttl_seconds` | 300 | 顾客退款时原登录方式新鲜认证挑战，短时、单次、订单与退款快照绑定 | 🔴L1-2/L1-3 |
| `nezha_refund_bsc_finality_blocks` / `nezha_refund_tron_finality_blocks` | 12 / 20 | USDT 退款链上完成的最低终局确认数；不足只可 `verification_pending`，不得完成 | 🔴L1-3 |
| `nezha_refund_sanction_max_sync_age_hours` | 48 | 退款目标使用本地 OFAC 地址库前允许的最大同步龄；筛查关闭、最近同步非成功/过期、查询异常或命中均保持 `refund_destination_hold`，不得开放商家资金动作 | 🔴L1-6 |
| `nezha_sanction_screen_status` | 1 | USDT 收款来源制裁筛查（命中即拒） | 🔴L1-6 |
| `nezha_kyc_sanction_screen_status` | 1 | 商家入驻 KYC 人名制裁筛查 | 🔴L1-6 |
| `nezha_timeout_status` | 1 | 订单超时自动处理（催/取消未付单/升级） | L1-1 邻 |
| `nezha_yandex_link_purge_status` | 1 | Yandex 链接 PII 到期清除 | L1-7 |
| `nezha_payment_address_credential_status` | 1 | USDT 付款地址版本凭据：绑定顾客/商家/网络/方式的加密地址快照；2026-07-15 完成 migration、网络初始化及签发/复用/消费/过期 production canary 后按顺序启用。关闭不删除证据。 | L1-1 邻 |
| `nezha_payment_address_change_status` | 1 | USDT 收款地址受控变更：旧入口禁直写，交易级 TOTP → 商户 owner 确认 → 不同管理员 TOTP 复核；2026-07-15 完成驳回、批准、恢复原地址和通知审计 production canary 后启用。 | L1-1 邻 |
| `offline_payment`（直付） | 应开 | B方案顾客直付商家的核心付款方式（**关了没人能下单**，上线务必确认开） | 核心 |
| `home_delivery`（配送） | 1 | 唯一在运营的履约类型 | 核心 |
| reCAPTCHA（`recaptcha.status`）+ 邮件（`config mail.status`） | 应开 | 注册防刷 / 邮箱找回等邮件（不在 business_settings，见 config，上线确认） | — |

---

## C. ⛔ 必须【保持关闭】的（上线前确认仍=0，开了就出事）

| 开关 key | 现值 | 为什么必须关 | 等级 |
|---|---|---|---|
| `cash_on_delivery` | {"status":"0"} | 货到付款与 B方案（平台不碰钱）冲突；部署脚本有 COD 硬自检，开了自检拦上线 | 🔴L1-1 |
| `maintenance_mode` | 0 | 维护模式=全站下线，上线时必须为 0 | — |
| `digital_payment` | {"status":"0"} | 在线支付网关未接（B方案直付，无网关），保持关 | — |

---

## D. 🚧 未就绪 / 有前置条件——【暂不开】（上线也先关，别急）

| 开关 key | 现值 | 卡在哪 / 何时才能开 | 等级 |
|---|---|---|---|
| `nezha_consolidation_rounds_status` | **0** | 集运期次撮合**总闸**（阶段 B 骨架 dormant）。真开=①staging 整链 QA（建期次→报名→状态机 draft→open→closed/canceled→脱敏导出）②业主批准③开城后有实际拼柜需求。开=vendor 端显 open 期次卡+报名流+成团进度；关=vendor 端期次/报名整体零透出（admin 端始终可用·运营先建期次）。🔴 **翻本闸只是必要条件**：集运仅面向经营达标的深度合作商家，**每店资格另由 `restaurants.nezha_consolidation_eligible` 控（默认全关）**，须在 admin「平台集运申报 → 需求汇总」页逐家点「开通」；**只翻闸不开资格 = 商家端仍全 404（这不是 bug）**。开期通知同样只发已开通资格的商家。提示卡与 v1 问卷面向全体、不受资格限制（摸底用）。平台只组织撮合、公示货代报价·付款商家直付货代·不碰钱。见 `fable-brief/PLAN_consolidation_roadmap.md §3-B`。 | L3 |
| `nezha_payment_address_change_approval_ttl_min` | 1440 | 地址变更申请等待商家/复核的有效期，默认 24 小时，可调 30–10080 分钟；它只使未完成申请超时并对用户显示“已驳回（超时）”，底层保留 system/expired 审计。它不是资金地址冷静期，也不延长任何已签发凭据。 | L1-1 邻参数 |
| `schedule_order`（全局·StackFood 平台级 business_settings） | **1（`/config schedule_order=true`）** | **当前已开；它只是预约前置，不是预约功能签收** | 预约功能隐藏前置。2026-07-14 production 只读核对已为 1，因此 `nezha_preorder_status=1` 不再是“总闸开但依赖关”的静默死链；仍须 exact-main 隔离全链、商家操作与产品 owner 签收。影响面仅 opt-in 预约店（每店列=1）；不得把当前值冒充已批准值。 | L2 |
| `nezha_preorder_status` | **1（未签收）** | 预约下单/集中配送**总闸**。2026-07-14 live=1 且 `schedule_order=true`；当前阻断从“隐藏依赖关闭”校准为“功能已暴露但缺 owner 签收”。exact-main 强制 SQLite `:memory:` 的预约聚焦 53 tests 已过，Web 取消动作契约也通过；这关闭服务端时序/窗口/取消/作业台等自动语义，不等于真实 UI 完整链。关闭前端/接口透出或继续保持 1 都是产品决定。剩余签收：①隔离真实浏览器下单/取消/并发/刷新；②产品 owner；③商家运营；④UI 目标视口证据。当前值不等于批准值。 | L2 |
| `nezha_preorder_min_lead_hours` / `nezha_preorder_max_days_ahead` | 2 / 3 | 预约下单提前量参数(**参数非布尔**·后台可调)。顾客下预约单时,窗口起始须 ≥ now + `min_lead_hours` 且 ≤ now + `max_days_ahead` 天(M6 净新增服务端硬校验·债辩纠正① delivery 原只拦「不能约过去」)。空/0 回落默认。改后须 `Cache::forget('business_settings_all_data')`。 | L2 |
| `nezha_preorder_point_step_min` | 20 | 预约送达点步长(**参数非布尔**·默认 20·业主 2026-07-11 定「单点模型」)。顾客在商家开放的可约时段内按本值一档选**精确送达点**(像美团 10:00/10:20/10:40);READ 铺点 + place_order `validateWindowTiming` 对齐校验共用。空/0 回落默认。改后须 `Cache::forget('business_settings_all_data')`。 | L2 |
| `nezha_preorder_timeout_lead_min` | 0 | 预约超时时钟提前量(**参数非布尔**·默认 0·补登记 M2)。M2 窗口锚定时钟:预约单在 now < schedule_at − 本值分钟 时对超时清扫 dormant。见 `NezhaOrderTimeout` preorder_lead。 | L2 |
| `nezha_preorder_free_cancel_lead_hours` | 2 | 预约免费取消提前量(**参数非布尔**·默认 2·业主 2026-07-11 定·M6b/11-6)。已确认(confirmed)预约单在窗口起始前 ≥ 本值小时且未备货时,顾客可自助免费取消(走既有原路退款)。总闸关时 11-6 整条不生效。改后须 `Cache::forget('business_settings_all_data')`。 | L2 |
| `nezha_preorder_dispatch_lead_min` / `nezha_preorder_window_remind_min` | 30 / 45 | screen05 作业台预约分区参数(**参数非布尔**·后台可调·业主 2026-07-11)。`dispatch_lead_min`=**单点版**每单「建议叫车时间」= 预计送达点 − 本值分钟(固定提前量·非实时 ETA·11-7),也是「该叫车」置顶与叫车推送的触发锚;`window_remind_min`=窗口版遗留(单点版作业台已不用·「该叫车」判定改用 `dispatch_lead_min`)·参数保留可后台调。空/0 回落默认。改后须 `Cache::forget('business_settings_all_data')`。 | L2 |
| `nezha_preorder_dispatch_remind_push` | 1 | 预约叫车提醒·**平台 killswitch**(**布尔·默认 1 开**·07 稿·阶段③)。关=平台一刀切停全部商家的预约叫车推送(不动预约功能本身)。**每商家自选**在 `restaurants.nezha_preorder_dispatch_remind`(默认开·商家「通知设置」页各管各·业主 0712·migration `2026_07_12_040000`)。叫车推送**三门**: 总闸 `nezha_preorder_status` + 本 killswitch + 本店列。到每单「建议叫车时间」推「该叫车了：N 单待发」摘要一条(防轰炸:摘要合并 + 在场抑制 + 一周期一条)。改后须 `Cache::forget('business_settings_all_data')`。 | L2 |
| `nezha_refund_dispute_status` | 0 | denied 凭证争议流(R1-R4 全实装·dormant)。开=商家可对「待退款(段B·凭证在案先核后退)」发起争议→运营在「退款留痕 → 退款争议裁决」裁决(维持退款义务 / 核实未收款);逾期计时锚点 R4 已接(维持后从裁决时刻重算 + 催办重周期)。关=入口/弹层/裁决动作全不触发。🔴 技术就绪, 真开前须业主批准 + 亲测整条争议链(商家发起→超管裁决→逾期恢复)。 | 🔴L1-2 |
| `nezha_ad_auction_status` | 0 | 广告竞价 CPC。🔴 INVARIANTS 钉死：C1 后端加固(throttle+按日上限+自点击剔除)+C2 前端触发正确性+首页推广条去 merit 标签，**三者齐前不得真开**。见 `docs/PLAN_ad_auction.md §11`。 | L2 |
| `nezha_ad_billing_status` | 0 | 广告 CPT 按天计费。广告变现未启动，现广告免费。想收费再开（确认单价/商家知情/有充值通道）。 | L2 |
| `nezha_deposit_mode_status` | 0 | 预存佣金/扣佣模式。一阶段免佣免押。何时开始收佣是商业决策（开=商家要充保证金才能接单）。`nezha_min_deposit_threshold` 现 0。 | L2 |
| `nezha_notif_async_status` | **1（未签收）** | 订单通知异步化灰度。2026-07-14 两个 queue worker online、failed jobs=0、Redis `PONG`；exact-main 强制 SQLite `:memory:` 的 7 个异步通知测试已覆盖开关开启时 push/HTTP/TG 入队、无 chat/text 跳过及关闭时同步回退。production `/config is_mail_active=true` 只说明配置声明，**不等于 FCM/邮件/Telegram 已真实送达**。正式签收仍需专用收件人的真实渠道回执 + 运营 owner 签字。 | L3-性能 |
| `nezha_timeout_escalate_status` | 0 | 超时**无人接单→业主 TG 升级**(批次1·TG双管 L3 兜底腿·代码已上线 dormant)。开=超时 sweep 在 `email_merchant`(10min)级，除既有「商家 TG 催单+邮件」外，并联向业主 TG(`nezha_risk_admin_chat_id`)发一条升级(店名/单号/已挂N分/**商家电话**·禁顾客 PII)。关=业主升级跳静默，既有商家 TG/邮件行为**零回归**。🔴 只加通知跳·**不碰** `NezhaOrderTimeout` 取消/退款/状态动作(故列 L1-1 邻)。真开=业主批准+≥1 商家绑 `telegram_chat_id`+亲测升级到达。见 `fable-brief/PLAN_merchant_order_alert_reliability.md §5`。 | L1-1 邻 |
| `nezha_owner_shadow_status` | 0 | 上线校准·业主影子接收(P1·P0 发送真值已上线 d62c895·代码 dormant)。开=每笔真实新单 T+0 抄送业主 TG(`nezha_risk_admin_chat_id`)一条(店名/单号/类型/合计/时间·**禁顾客 PII**)，供业主观察每店接单时效、为 P2 的 T+5 升级阈值提供数据；关=影子抄送跳静默，**不影响**商家 TG/邮件/风控告警。走 P0 已加固 `sendTelegramToAdmin`(真值+重试)·每单一次幂等。真开=业主批准接受消息量(§9-3)+绑定期开始；前 ~30 笔后业主**手动翻回 0**(不做自动计数降级)。翻此闸须 `Cache::forget('business_settings_all_data')`。见 `fable-brief/PLAN_merchant_order_alert_reliability.md §5-6/§9-3`。 | L1-1 邻 |
| `nezha_new_order_nag_status` | 0 | 新单**反复提醒商家接单**总闸(方案A网页循环播报+B手机TG反复补发·代码上线 dormant)。开=`NezhaNewOrderNagSweep` 每分钟按商家设定间隔(手机端≥60s)对**未接单**反复发 TG 催单，商家一**查看**(`checked→1`)或**接单**(离开待办态)即停；待催口径=vendor 看板 toast 三桶(`checked=0`·待收款/待接单/已确认待接)，商家在提示音面板**自行开启并勾选**覆盖类别(默认只勾「待接单」)。🔴 纯通知·**不碰**取消/退款/状态/资金(故列 L1-1 邻)·与 `OrderTimeoutSweep`(L1)/`NezhaVendorAlarmSweep`(App推)完全分离。关=整条 sweep 直接 return(零真实影响)。真开=业主批准+商家自行开启+≥1商家绑 `telegram_chat_id`+亲测催单到达。<br>开催单前须先开 `nezha_order_tg_card_actions_status`，否则催单文案指向不存在的按钮。翻此闸须 `Cache::forget('business_settings_all_data')`。见桌面 `商家新单提醒-循环播报方案-20260718.md`。 | L1-1 邻 |
| `nezha_order_tg_card_status` | 0 | 外卖 TG P2.1 **零顾客 PII 新单卡片**总闸。开=已绑定店的新单由旧纯文本替换为店名/单号/类型/菜品≤8/合计/付款方式/时间卡片，并把 Telegram `message_id` 写入 `nezha_order_tg_cards` 供原地编辑；动作按钮另由独立闸控制，卡片仍不含地址/坐标/顾客姓名电话/order_note/付款截图。关=完全走旧纯文本路径。首验店=实际绑定店（现 id6 业主自测）；翻闸/回滚均须 cache clear + FPM reload。 | L1-1 邻 |
| `nezha_order_tg_card_actions_status` | 0 | 外卖 TG P2.2a **确认收款按钮**独立总闸。开=只在私聊、绑定店、未结束且有离线付款记录的订单卡片显示「💰 确认收款」，再选 15/30/45 分钟或店默认；复用现有确认收款规则，制裁命中仍自动拒收且只显示固定脱敏提示。其它接单/拒单/备餐/出餐/拒收按钮均不显示，群绑定只读。关=卡片无任何按钮，旧按钮点击只提示功能未开启。真开前须越权、重复点击、终态、制裁脱敏、checked 单单、一单一卡和 redlines 全绿，并由业主真机截图拍板；翻闸/回滚均须 cache clear + FPM reload。 | L1-6 |
| `nezha_timeout_escalate_owner_min` | 5 | 业主超时升级阈值(**参数非布尔**·默认 5·L2 后台可调)。仅在 `nezha_timeout_escalate_status` 开时生效——业主 TG 升级挪到 **T+5**(独立参数·不复用 `nezha_timeout_remind_min`/`email_merchant`·§0.5④)，20min 自动取消前留 ~15min 挽回窗。空/0 回落默认 5。改后须 `Cache::forget('business_settings_all_data')`。见 `fable-brief/PLAN_merchant_order_alert_reliability.md §5-8/§9-2`。 | L2 |
| `nezha_offboard_status` | 0 | 商家退出结算(step4-4/step5 已实装·dormant)。开=商家端「对账中心」底部出现「申请退出平台」入口 + 服务端 `open()` 放行；关=入口不渲染且服务端拒。资金流出路径，审批闸 H(高额净额≥`nezha_offboard_high_amount_amd` 默认 500000֏ 强制审批后 T+1)+制裁实时 re-screen+户名三方核对齐备。**灰度：存量 7 店(6 测试+1 朋友)KYC 未录→退出必落 kyc_pending，无真实退出需求前保持关**；真开前先 staging 单店试跑。超管侧审批队列(`admin/nezha-offboard`)不受本开关限、始终可见。 | 🔴L1-8 |
| `nezha_merchant_video_status` | **1（未签收）** | 本地生活商家页「店内视频」外链卡**总闸**。2026-07-14 必须从 production 当前 release + 共享 storage 核对（独立 worktree 的隔离 storage 会误报封面缺失）：商户 12 存 2 条 `v.douyin.com` 记录，规范化输出 2，两条 cover URL 均存在，技术可见性成立。剩余是内容 owner 对封面/标题/外跳的批准；不接受当前内容则另取精确 Go 回退 0。当前值不等于批准值。见 `fable-brief/HANDOFF_locallife_merchant_video.md`。 | L1-1(纯信息墙)/内容 |
| `nezha_local_merchant_selfserve_status` | 1 | 〔🟢2026-07-10 业主放量·开关台账登记归 F 已开·不再报偏离〕本地生活商户轻管理面**总闸**(2026-07-09 翻开 live)。开=已开号商户可登 `api.nezha.am/m/login` 自助维护店铺展示信息(简介/服务/相册/logo/联系方式/营业时间/到店优惠·店名与地址改动重点复核)→**所有提交进超管复审 `admin/local-life/merchant-changes`·通过才更新顾客端**；关=整 `/m` 面板(含登录页)404、驾驶舱「商户资料」chip 恒 0 隐藏。🔴 技术就绪·真开前须：①业主看商户端截图点头(桌面 `商户管理面_*_0708.png`)②后台给≥1 家商户开号(商户编辑页「商户自助管理账号」填邮箱→发设密邮件)③真机走通 设密→登录→改→提交→超管过审→顾客端生效 ④确认 Mailgun 能送达设密邮件。翻此闸须 `php artisan cache:clear`(business_settings 缓存)。入驻仍走运营代录·不放自助注册。 | L3(新商户鉴权面·内容非PII·密码 bcrypt) |
| `nezha_merchant_notes_status` | 1 | 〔🟢2026-07-10 业主放量·开关台账登记归 F 已开·不再报偏离〕本地生活商家页「笔记」内容层**总闸**(批N·2026-07-10 翻开)。开=过审笔记在 H5 商家页「笔记」卡展示；登录顾客可写笔记(每商家每日≤2·禁联系方式·违禁词扫描)、商家可在 `/m` 发笔记，均进超管「本地生活→笔记审核」队列(**全复审**·通过才显)；关=前台整卡隐藏(含写入口)，审核台不受影响。冷启动=商家先在 `/m` 种几篇→过审→顾客见到内容才跟写(不做常驻空壳发布入口)。笔记 ≠ 评价(无星级/点赞/好评率·§②-1)。🔴 技术就绪·真开前须：①业主看前端截图点头(桌面 `哪吒商家页笔记-隔离预览截图-2026-07-10`)②`/m` 或客户端产出 ≥1 篇过审笔记(否则整卡不显)。 | L1-1(纯信息墙)/内容 UGC |

> 〔2026-07-03 自助充值批次 A3 (S1-S4) 全上线·全 dormant。下列开关一起决定"商家自助充值申请流"是否对外亮。复用运营手动入账，平台不碰钱。审核结果通知(TG+顶栏铃铛站内信 S4·随总闸)与余额不足邮件「去充值」直链(S4·随总闸)一并 dormant。〕

| `nezha_topup_status` | 0 | 自助充值申请**总闸**。开=商家对账中心出现「申请充值」卡(自报额+传凭证→超管队列 `admin/nezha-topup` 审核入账·复用手动入账·平台不碰钱)；关=保持"联系客服"文案。🔴开前须先在后台配好**平台收款账户**(`nezha_topup_alipay_account`/`_name`/`_holder`/`_qr`，否则收款码空)。开后审核结果自动发 TG+铃铛站内信、余额不足邮件带「去充值」直链。 | L2 |
| `nezha_topup_guarantee_status` | 0 | 押金账户自助充值腿(**总闸开且此开**才亮押金腿)。 | L2 |
| `nezha_topup_ad_status` | 0 | 广告账户自助充值腿(总闸开且此开)。广告计费(`nezha_ad_billing_status`/`nezha_ad_auction_status`)未上线前不亮，等广告真开再开。 | L2 |
| `nezha_topup_refund_status` | 0 | 押金**中途退款**(营业中·运营核算制·`NezhaGuaranteeRefund` G0-G6 门)。🔴**前置：`nezha_offboard_status` 未开→本开关不得开**(offboard 全额退是基线、中途退是其子集，护栏共用)。开=商家端申请退押金 + 超管审批放款队列。 | 🔴L1-8 |
| `nezha_topup_min_amd` / `nezha_topup_max_amd` | 5000 / 2000000 | 自助充值金额上下限(AMD·参数非布尔，后台可调)。 | L2 |

---

## E. 🤔 上线前【业务决策】——开不开你定（无对错，想清楚再定）

| 开关 key | 现值 | 决策 | 备注 |
|---|---|---|---|
| `take_away`（自取） | 0 | 上线要不要开自取？ | 现只运营配送；自取 2026-06-20 关的（纯 DB 开关）。`dine_in`=NULL 从未启用。 |
| `nezha_kyc_required_status` | 0 | 上线要不要强制商家 KYC 才能经营？ | 现不强制。制裁筛查(B类)仍照跑，这个只管"是否必须完成 KYC"。 |
| `customer_verification` | 空(关) | 上线要不要开顾客手机验证？ | 现关（省 SMS 成本，注册靠 reCAPTCHA+限流）。亚美尼亚 SMS≈$0.14/条。 |

---

## F. ℹ️ 已开着的其它（无需动作，仅记录）
`nezha_feedback_digest_status=1`(反馈日报) · `nezha_cs_ai_status=1`(AI客服) · `nezha_cs_merchant_relay_status=1` · `nezha_cs_vendor_tg_relay_status=1`(商家↔顾客 TG) · `nezha_search_log_status=1`(搜索需求探针) · `order_delivery_verification=1` · `nezha_busy_mode_status=1`(商家忙碌模式/定时挂起)。

**`nezha_busy_mode_status`（2026-07-08 go-live）**：一次性功能总闸（非日常操作·无后台 UI）。开=商家「今天·作业台」店态胶囊出三档（营业/忙碌·选原因+出餐分钟/暂停·选时长或不定时），顾客端餐厅页顶部显 🔥暖黄「高峰期繁忙·出餐约需X分钟」(仍可下单) 或 ☕暖灰「店家小憩中·约X分钟后恢复接单」(倒计时)。日常商家翻自己店的忙碌/暂停**不动此闸、无缓存坑**。🔴 **只有翻这个总闸本身**才须 `php artisan cache:clear` + `/etc/init.d/php-fpm-82 restart`（单 `Cache::forget`+graceful reload **不够**·实测 FPM worker 仍读旧值恒返回关）。


**`TELEGRAM_OIDC_ENABLED` + `TELEGRAM_OIDC_ALLOW_NEW_ACCOUNTS`（2026-07-22 go-live）**：顾客 Telegram 登录（OIDC）。**env 开关，不在 `business_settings`** —— 改 `/www/wwwroot/api-deploy/shared/.env`（在 `shared/` 下，部署不覆盖）。现值均为 `true`：登录已开 + 陌生人可经 TG 直接注册。改完通常下个请求即生效（本项目 config 未缓存）；不生效再 `php artisan config:clear` + `systemctl restart php-fpm-82`。🔴 **禁 `php artisan config:cache`**（会把 .env 冻结进缓存）。应急关闭：`ENABLED=false` → 按钮立即消失、已进入的返 503、不建号不报错。只停新注册、保留已有用户登录：`ALLOW_NEW_ACCOUNTS=false`。BotFather 侧配置在 **Login Widget 页**（非 Bot Settings→Domain）的 `Redirect URIs` 与 `Trusted Origins` 两个列表，与 staging 共用 bot `@NezhaCustomerLoginBot`、各自两条互不影响。备份：`.env.bak.202607221644`（配置前）、`.env.bak.allownew.202607221726`（改 true 前）。合规记录见 `docs/compliance/CHANGELOG.md` 2026-07-22 条。

---

## 上线当天开关操作顺序（建议）
1. 过 B/C 两类：确认安全轨全 1、COD/维护全 0（`digital_payment` 关）。
2. 决 E 类三个业务开关。
3. 开 A 类 `nezha_refund_overdue_status=1`（先看催办邮件真发）。
4. D 类保持关。
5. 改完 grep 确认值 + 清 `business_settings_all_data` 缓存 + `kill -USR2` FPM。
6. 真机冒烟：下单→付款(直付)→接单→配送→退款一条龙，确认没被新开的开关误伤。

---

## 🔧 维护约定
- **新增任何"默认关、待激活/待决策"的开关都要加进本表**（尤其资金/合规/性能灰度类），别让它散落在各 memory 里等上线时漏掉。
- 改了任何开关的真实值 → 同步更新本表的"现值"列。
- 定期（或每次跑上线 QA 时）用一条查询核对本表现值 vs 生产 `business_settings` 真实值，防表与实漂移。
- **核对命令 `php artisan nezha:switches-verify` 已实装（M3）**：三方对账（注册表 `config/nezha_switches.php` ↔ 本表 ↔ 生产 `business_settings`），输出 🔴偏离预期 / 🟡文档现值过期 / 🟢一致（退出码 0/1/2）。QA 例行（`QA_MASTER §五 T0/T1`）跑一次，也可随时手动跑。
- **开关台账只读页** `admin/nezha-switches`（超管后台 ⑧系统 → 开关台账）：把本表实时可视化，偏离预期红色置顶；🔴纯只读，翻闸仍来各自设置页（本表与页面同源于注册表，改开关清单＝改 `config/nezha_switches.php` 并同步本表）。
