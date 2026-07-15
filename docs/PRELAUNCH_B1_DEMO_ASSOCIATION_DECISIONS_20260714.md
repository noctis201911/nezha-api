# B1：26 类 demo 关联数据裁决表（2026-07-14）

状态：**已备妥、26/26 未裁决、demo rollback NO-GO**。

固定签收对象：API `a53cfb5c967daa5917ce2cb4c2489d6799434ff2`、Web `b4e0ea0f17e3bfc65b3eebe9e645f5334de0faed`。API 候选已覆盖 `b14c9c58bee66b59a45bb338f2d742609a3466f3` 的后台账号邮箱隔离运行时代码并完成本地隔离回归；任何应用 SHA、数据快照或工具版本变化都会使受影响签收失效。

本表只固定版本化工具在 production 只读 PLAN 与隔离恢复库中一致暴露的关联类别和计数。它不授权读取更多 production 明细、导出数据、生成备份、执行 `REHEARSE/GO`、删除或改写任何数据。每行当前处置均为 **HOLD**；外部 owner 必须填写最终决定，工具不得替人推断。

## 1. 固定证据与前置边界

- marker 精确命中：vendors=7、restaurants=7、local-life merchants=6、local-life posts=21；总表行数分别为 9/9/10/21，不能把总行数当 demo 行数。
- 2026-07-15 owner 补充事实：现存 `_demo_seed_manifest.json` 明确列出 vendor/restaurant `6–11`；owner 再次确认其中 `7–11` 是 Claude 生成的示范商家。因此这些 ID 的无效或空收款地址不能误报为真实商户地址事故。restaurant `12` 不在该 manifest，仍不得未经证据归类为 demo。
- 2026-07-15 owner 指定 `demo_seed_1@nezha.am` 作为 USDT credential production canary 的受控顾客，并授权创建可识别、可回滚的 production 测试数据。只读核对时该邮箱尚不存在；后续新建记录必须明确标为 demo、生成精确 rollback manifest，并在 canary 验收后清理或按 owner 新裁决保留。该授权不把测试顾客提升为后台 reviewer，也不改变本表的 demo rollback NO-GO。
- 精确 rollback scope 当前覆盖 31 个非 `_demo_socialproof_seed` 订单、22 个 reviews、0 个 add-ons 与 6 个本地生活商家当前名称；`/config` 仍含 `Demo Banner`，Banner 是独立配置动作，不属于数据工具。
- 31 个订单 ID：`9–13`、`1999002074–1999002075`、`2000000001–2000000024`。这些订单“与 demo 店关联”不证明可删，必须单独签收。
- 恢复库在只为本轮对账补齐旧备份损失的 21 个公开 `cover_emoji` 后，与 production 的 20/20 类目标行指纹一致；恢复库 PLAN SHA `55bdf110eaee3c17e1b03ab2d038c9ce41cb1b029538417efd325ff9783a680c`，production 只读 PLAN SHA `c13203f4a3595669272aa26e18f9a8f1d7d06708e916744d3a0a1a0060a0cba5`。
- rehearsal scope SHA：`ea85ed0adf1f97c24c597da94918c05e536286ce35e1c58ed45b54816520b72a`。两端 26 类 blocker/计数一致，事务前 exit 4 拒绝，证明 fail-closed 生效；不证明任何一类可删。
- 旧加密备份把 21 个 4-byte emoji 恢复为 `?`（hex `3F`），不能作为 production demo 清理的唯一回滚点。修备份脚本、生成/外传新备份及隔离恢复另取精确 Go，均不在 B1。
- 工具安全门：`PLAN` 默认只读；`REHEARSE` 只许 `nezha_qa_*` 一次性数据库并回滚；`GO` 还需已批准精确 scope 与 `NZ_DEMO_ALLOW_COMMIT=YES`。本表签完也不自动满足执行授权。

固定 manifest SHA-256：

| 文件 | SHA-256 |
|---|---|
| `_demo_seed_manifest.json` | `68982d4926210e3e394a4b6cdde53e8ffe4defc0a9fe525c8abedc7bc42b3c62` |
| `_demo_locallife_v2.json` | `e6ff14552788c9af4c734aadb78bf7fa4a9fc89cef1e69926c6cfec9f076f0ee` |
| `_locallife_v2_backup_20260617212214.json` | `82a6e165127bfec5c1ee43ae24ced39a2fa4fcef4610e2a305aa630b114be5a6` |
| `_ll_merchants_manifest.json` | `75a635af0a887aa12e956d75ba3c5578fa669ea6e4e93924b97be77f3fc174bd` |
| `_demo_locallife_service.json.archived.20260617140211` | `19be5491c8bbf40e0e72a1f35e3d2cb29a5568a2c4d65b9029b96764eb114f80` |

## 2. 决策码

| 码 | 含义 | 必填证据 |
|---|---|---|
| `R` | 保留；不得随 demo rollback 删除 | 为什么是合法/真实数据；若父实体会删除，必须给出新的非 demo 父 ID 或其它完整引用方案 |
| `M` | 迁移、重挂或匿名化后保留 | 精确源 ID、目标 ID、字段动作、前后不变量、验证与反向步骤 |
| `D` | 纳入未来精确删除 scope | 精确 ID 清单、数据域 owner + 保留义务 owner 同意、依赖顺序、审计证据与可用回滚点 |
| `H` | 保持阻断，不执行 demo rollback | 缺少的 owner/证据和下一次复核条件；这是所有行的当前默认状态 |

任何涉及订单、退款、支付凭证、账本、保证金、风控、消息、PII、通知或审计记录的 `D`，必须取得对应业务 owner 及法律/会计/合规保留意见；仅数据清理 owner 一人签字不够。

## 3. 26 类逐项裁决

| # | 工具机器键 | 只读计数 | 数据含义／主要风险 | 必须参与的 owner | 当前 | owner 决策（R/M/D/H） | 精确 ID/证据引用 |
|---|---|---:|---|---|---|---|---|
| 1 | `food.outside_manifest` | 20 | demo 餐厅下 manifest 外菜品；目录、订单引用和展示完整性 | 商品/商家运营 + 数据清理 | HOLD |  |  |
| 2 | `food.category_id_outside_manifest` | 58 | seed category 下 manifest 外菜品；分类不能等同 demo 归属 | 商品/商家运营 + 数据清理 | HOLD |  |  |
| 3 | `reviews.by_food_outside_scoped_reviews` | 1 | 菜品关联但不在精确 review scope 的评价；用户内容与审核记录 | 内容/客服 + 隐私/保留义务 + 数据清理 | HOLD |  |  |
| 4 | `reviews.by_order_outside_scoped_reviews` | 1 | 订单关联但不在精确 review scope 的评价；订单与用户内容双重引用 | 内容/客服 + 隐私/保留义务 + 数据清理 | HOLD |  |  |
| 5 | `carts.restaurant_id` | 1 | 顾客未结算意图；可能影响活跃会话 | 产品 + 隐私/保留义务 + 数据清理 | HOLD |  |  |
| 6 | `coupon_claims.coupon_id` | 4 | 已领取优惠权益；删除可能改变顾客权利与对账 | 运营/财务 + 客服 + 数据清理 | HOLD |  |  |
| 7 | `cuisine_restaurant.restaurant_id` | 6 | 菜系与餐厅关系；目录和筛选完整性 | 商品/商家运营 + 数据清理 | HOLD |  |  |
| 8 | `local_life_merchant_accounts.merchant_id` | 1 | 本地生活商家登录/账号关系；身份与访问边界 | 账号安全 + 隐私 + 数据清理 | HOLD |  |  |
| 9 | `logs.restaurant_id` | 96 | 操作/系统日志；审计、争议与安全取证价值 | 安全/审计 + 法律保留义务 + 数据清理 | HOLD |  |  |
| 10 | `messages.order_id` | 2 | 订单会话消息；客服、争议、PII 与跨境处理 | 客服 + 隐私/法律 + 数据清理 | HOLD |  |  |
| 11 | `nezha_cart_events.restaurant_id` | 86 | 购物车行为事件；分析数据和潜在用户关联 | 产品分析 + 隐私 + 数据清理 | HOLD |  |  |
| 12 | `nezha_consolidation_surveys.restaurant_or_vendor` | 1 | 合并配送调研；用户反馈与运营决策记录 | 产品/运营 + 隐私 + 数据清理 | HOLD |  |  |
| 13 | `nezha_cs_tickets.order_or_vendor` | 1 | 客服工单；投诉、争议与处置轨迹 | 客服 + 法律保留义务 + 数据清理 | HOLD |  |  |
| 14 | `nezha_order_timeout_events.order_id` | 38 | 订单超时事件；履约、自动动作和责任审计 | 订单运营 + 风控/审计 + 数据清理 | HOLD |  |  |
| 15 | `nezha_refund_records.restaurant_or_order` | 17 | 退款阶段正本；直接影响资金争议与原路退款证据 | 财务/退款 owner + 法律/会计 + 数据清理 | HOLD |  |  |
| 16 | `nezha_review_reports.review_id` | 1 | 评价举报与审核处置；内容治理证据 | 内容安全/客服 + 法律保留义务 + 数据清理 | HOLD |  |  |
| 17 | `nezha_risk_records.restaurant_or_order` | 13 | 风控记录；制裁、欺诈和人工判断依据 | 风控/AML + 法律保留义务 + 数据清理 | HOLD |  |  |
| 18 | `offline_payments.order_id` | 23 | 商家直付方式、付款凭证与审核状态；含敏感支付材料 | 支付/财务 + 隐私/法律 + 数据清理 | HOLD |  |  |
| 19 | `order_transactions.order_or_vendor` | 13 | 订单交易账本；会计、退款和审计不变量 | 财务/会计 + 审计 + 数据清理 | HOLD |  |  |
| 20 | `restaurant_deposit_transactions.restaurant_or_vendor` | 2 | 商家保证金账本；虽当前阶段关闭，历史记录仍有会计价值 | 财务/会计 + 法律 + 数据清理 | HOLD |  |  |
| 21 | `restaurant_notification_settings.restaurant_id` | 72 | 商家真实通知偏好/地址关系；删除可能造成漏单 | 商家运营 + 隐私 + 数据清理 | HOLD |  |  |
| 22 | `restaurant_reports.restaurant_or_vendor` | 1 | 餐厅举报和处置记录；内容/商家治理证据 | 商家治理/客服 + 法律 + 数据清理 | HOLD |  |  |
| 23 | `user_infos.vendor_id` | 4 | 商家用户扩展资料；身份、PII 和权限关系 | 账号安全 + 隐私 + 数据清理 | HOLD |  |  |
| 24 | `user_notifications.vendor_id` | 43 | 商家站内通知历史与真实未读状态 | 商家运营 + 隐私 + 数据清理 | HOLD |  |  |
| 25 | `vendor_feedback.restaurant_or_vendor` | 1 | 商家反馈和平台回应；运营承诺与客服证据 | 商家运营/客服 + 法律 + 数据清理 | HOLD |  |  |
| 26 | `wishlists.restaurant_or_food` | 7 | 顾客收藏意图；用户数据与产品状态 | 产品 + 隐私 + 数据清理 | HOLD |  |  |

计数校验：`20+58+1+1+1+4+6+1+96+2+86+1+1+38+17+1+13+23+13+2+72+1+4+43+1+7`，共 **26 类**；这里的“类”不是行数总和。

## 4. 上游 scope 与执行包签收

以下签收与 26 行逐项裁决同样必需，不能用一张总签名替代：

| 项目 | owner 明确结论 | 证据/附件 | 姓名/角色 | 日期时间/时区 |
|---|---|---|---|---|
| 31 个关联订单逐 ID 的真实/QA/demo 定性及 R/M/D/H |  |  |  |  |
| 22 个 scoped reviews 与 0 个 add-ons 的 scope 完整性 |  |  |  |  |
| 6 个本地生活商家当前名称与原 manifest 名称漂移的处理 |  |  |  |  |
| 26 类裁决汇总后重新生成的精确 PLAN/ID 附件 |  |  |  |  |
| 数据保留、税务、AML、争议和 PII 义务复核 |  |  |  |  |
| 新 `utf8mb4` 保真备份及全新隔离恢复验证（B1 外另批） |  |  |  |  |
| 最终 `REHEARSE` 前后 hash、残留与事务回滚（B1 外另批） |  |  |  |  |

## 5. 签名与 NO-GO 判定

| 角色 | 结论（同意/附条件/拒绝） | 强制条件或例外 | 姓名/机构 | 日期时间/时区 |
|---|---|---|---|---|
| 数据清理 owner |  |  |  |  |
| 商品/商家运营 owner |  |  |  |  |
| 客服/内容治理 owner |  |  |  |  |
| 财务/会计 owner |  |  |  |  |
| 风控/AML/安全 owner |  |  |  |  |
| 隐私/法律保留义务 owner |  |  |  |  |

关闭条件：26 行均有非空决策和精确 ID 证据；31 个订单逐项定性；所有 `D/M` 有顺序、不变量、反向步骤和已验证回滚点；新备份字符保真与隔离 rehearsal 另行通过；再取得 production demo GO。任一条件未满足，工具必须继续 fail closed，production 继续 NO-GO。
