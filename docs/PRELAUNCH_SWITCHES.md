# 哪吒外卖 — 上线前开关清单 (PRELAUNCH_SWITCHES)

> **用途**：平台正式上线前，逐条过一遍所有"故意先关着/待决策"的开关，决定开还是关。**这是上线 go/no-go 硬门之一**，`PRELAUNCH_QA_MASTER.md` 顶部指向本表。
> **值来源**：2026-07-01 读生产 `business_settings` 真实值（非记忆）。开关值会变，改动时**同步更新本表 + 当前值**。
>
> 🔴 **改开关的正确姿势**：优先走后台对应设置页（改完自动清缓存）；**直接改 DB 后必须** `Cache::forget('business_settings_all_data')`，且"激活类翻转"须 `kill -USR2 $(FPM master)` 刷 worker（`get_business_settings` 进程内 static 缓存，否则间歇读旧值）。**关闭任何 L1 开关须先取得用户批准 + 记 `docs/compliance/CHANGELOG.md`。**

---

## A. 🟢 上线前【要打开】的（现在关着，上线应开）

| 开关 key | 现值 | 上线动作 | 说明 / 位置 | 等级 |
|---|---|---|---|---|
| `nezha_refund_overdue_status` | **0** | **改 1（激活）** | 商家逾期未退款兜底：自动催办+记风控+告警。配套 `nezha_refund_overdue_auto_suspend` 默认 0=手动停接单（到阈值只告警建议、由运营手动停），如要自动停改 1。后台「风控中心 → 逾期未退款」设置页。**激活=真实约束商家，先亲测催办邮件真发出**。 | L2 |

> 〔2026-07-01 路A 已上线代码 f1a4734，机制休眠，就等这个开关。〕

---

## B. ✅ 必须【保持开启】的安全轨（上线前确认仍=1，别被误关）

| 开关 key | 现值 | 作用 | 等级 |
|---|---|---|---|
| `nezha_risk_control_status` | 1 | 下单风控（单笔/单日/频次/大额） | L2 |
| `nezha_refund_control_status` | 1 | 退款护栏（原路锁定+限额） | L1-2 |
| `nezha_refund_usdt_verify_status` | 1 | USDT 退款链上核验 | L1-3 |
| `nezha_sanction_screen_status` | 1 | USDT 收款来源制裁筛查（命中即拒） | 🔴L1-6 |
| `nezha_kyc_sanction_screen_status` | 1 | 商家入驻 KYC 人名制裁筛查 | 🔴L1-6 |
| `nezha_timeout_status` | 1 | 订单超时自动处理（催/取消未付单/升级） | L1-1 邻 |
| `nezha_yandex_link_purge_status` | 1 | Yandex 链接 PII 到期清除 | L1-7 |
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
| `nezha_refund_dispute_status` | 0 | denied 凭证争议流(R1-R4 全实装·dormant)。开=商家可对「待退款(段B·凭证在案先核后退)」发起争议→运营在「退款留痕 → 退款争议裁决」裁决(维持退款义务 / 核实未收款);逾期计时锚点 R4 已接(维持后从裁决时刻重算 + 催办重周期)。关=入口/弹层/裁决动作全不触发。🔴 技术就绪, 真开前须业主批准 + 亲测整条争议链(商家发起→超管裁决→逾期恢复)。 | 🔴L1-2 |
| `nezha_ad_auction_status` | 0 | 广告竞价 CPC。🔴 INVARIANTS 钉死：C1 后端加固(throttle+按日上限+自点击剔除)+C2 前端触发正确性+首页推广条去 merit 标签，**三者齐前不得真开**。见 `docs/PLAN_ad_auction.md §11`。 | L2 |
| `nezha_ad_billing_status` | 0 | 广告 CPT 按天计费。广告变现未启动，现广告免费。想收费再开（确认单价/商家知情/有充值通道）。 | L2 |
| `nezha_deposit_mode_status` | 0 | 预存佣金/扣佣模式。一阶段免佣免押。何时开始收佣是商业决策（开=商家要充保证金才能接单）。`nezha_min_deposit_threshold` 现 0。 | L2 |
| `nezha_notif_async_status` | 0 | 订单通知(SMS/TG/推送)异步化灰度。代码已上线但激活待 /debate + staging 下单 QA + 你签字（关键路径，异步化搞错会漏通知）。见 memory `project_nezha-capacity-queue-redis-staging-isolation`。 | L3-性能 |
| `nezha_offboard_status` | 0 | 商家退出结算(step4-4/step5 已实装·dormant)。开=商家端「对账中心」底部出现「申请退出平台」入口 + 服务端 `open()` 放行；关=入口不渲染且服务端拒。资金流出路径，审批闸 H(高额净额≥`nezha_offboard_high_amount_amd` 默认 500000֏ 强制审批后 T+1)+制裁实时 re-screen+户名三方核对齐备。**灰度：存量 7 店(6 测试+1 朋友)KYC 未录→退出必落 kyc_pending，无真实退出需求前保持关**；真开前先 staging 单店试跑。超管侧审批队列(`admin/nezha-offboard`)不受本开关限、始终可见。 | 🔴L1-8 |

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
