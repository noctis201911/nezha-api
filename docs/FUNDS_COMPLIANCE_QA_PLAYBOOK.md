# 哪吒 资金 + 合规实跑 QA Playbook（第 4 层）

> 触发词：**资金合规QA**。QA 总索引见 `QA_MASTER.md` 第 4 层。
> 读法：`node nz.js run "cat /www/wwwroot/api.nezha.am/docs/FUNDS_COMPLIANCE_QA_PLAYBOOK.md"`
> 定位：查**资金账务正确性 + L1 合规红线是否真生效**（不是查体验=QA_PLAYBOOK，不是查运维=OPS_QA，不是查越权=SECURITY_QA）。
> 由来：2026-06-16 QA 总盘点立项；2026-06-17 首次实跑建本表。

## 怎么跑（铁律）
1. **不信文档断言，只信实跑证据**：连线上库（经 nz.js + 框架 DB 连接，本机非 git 仓库）、读真实代码分支、跑 `--dry-run`。
2. **先读 `INVARIANTS.md` + `docs/compliance/` 三件套**（business-flow / AML-policy / data-protection）+ 后端 `git log`，对照每条 L1 看代码真生效否。
3. **开关真实值 vs 文档默认值**：发现线上开关≠文档"默认关"时，**先查 `docs/compliance/CHANGELOG.md` 有无留痕再下结论**——已留痕=合规的"已开"，非 bug（2026-06-17 三开关皆此情形）。
4. **🔴 多窗口快照漂移**：后端 PHP 从工作树直跑、无构建，别窗口提交即 live。**QA 期间状态会被并发推翻**——开跑前后各 `git log -6` 对一次，发现新机制落地要回头补验（2026-06-17 实例：QA 中途 L1-6 制裁筛查被另一窗口实装+提交，轴E 结论从"未实装"翻转为"已实装需复验"；阈值也从 736240 被改到 110437）。
5. 发现即分级（🔴L1红线 / 🟡L2参数或中危 / 🟢L3）、每条带证据、最后输出分轴报告 + 给用户"亲手补验"清单（律师/真实资金闭环/渗透 AI 够不到）。

## 轴清单（逐条要证据）

| 轴 | 红线 | 实跑取证点 |
|---|---|---|
| **A 二清拔除** | L1-1/L1-5 | `RestaurantDisbursementScheduler::handle()` no-op + `generate_disbursement()` no-op + scheduler 未挂 `bootstrap/app.php` + `OrderLogic` 直付分支 `if(!$is_direct_pay)` 不累加货款 total_earning；**`disbursements`/`disbursement_details` 表实测 0 行** |
| **B 退款只原路** | L1-2/L1-3 | `Admin/OrderController` refund 段：`refund>order_amount→=order_amount` 硬上限；`NezhaRefundControl::lock_route()` USDT 反查 from 锁死、无自由填地址入口；`!$isDirectPay` 守卫直付不走平台钱包；over_limit→转审核不执行；留痕 `nezha_refund_records` |
| **C 链上留存≥5年** | L1-4 | `nezha_refund_records` 表结构完整、**免于 PII 自动清除**（dry-run 确认不在清除范围） |
| **D PII真删+加密** | L1-7 | `bootstrap/app.php` withSchedule 4 任务 + 系统 cron `* * * * * schedule:run` 在跑；3 purge 任务 `--dry-run` 干净执行；**全库扫描 `information_schema.TABLES` 找 `CREATE_OPTIONS NOT LIKE "%encryption%"` 的 InnoDB 表**——MySQL5.7 新表不继承全库加密，反复复发，每轮必扫 |
| **E 制裁筛查** | L1-6 | `NezhaSanctionScreen::screen_order()` USDT 反查 from 比对 `nezha_sanction_addresses`；命中→`record_reject`+`deny_offline_payment`+throw `SanctionScreenException`；嵌在 `confirm_offline_payment` **最顶、状态变更前**；Vendor/Admin 双 catch 不确认；开关 `nezha_sanction_screen_status` 默认开；`SyncSanctionList` 同步 OFAC SDN |
| **F 佣金/保证金对账** | L2 | `OrderLogic` 直付分支 `nezha_deposit_mode_status` 开时扣 `deposit_balance`+写 `commission_deduction` 流水；佣金率 `admin_commission`；**有真实订单后对账：Σ commission_deduction == Σ admin_commission；deposit_balance 不足→停接单** |
| **G 状态机+并发双花** | — | 扣佣受 `$order->transaction==null` 守卫=单次扣佣；`confirm_offline_payment` 幂等（vendor 已守 `status!=pending`；**admin 路径+本体仍缺守卫**=已知🟡）；订阅单 `||isset(subscription_id)` 重跑 create_transaction=直付订阅理论重复扣佣（边缘） |
| **H/I 开关 vs 文档** | L2 | 退款护栏/风控/制裁/UGC 开关线上真实值 → 对 CHANGELOG 留痕；阈值德拉姆计价校准 |

## 2026-06-17 首轮实跑结论（基线）
- ✅ L1-1/L1-2/L1-3/L1-4/L1-5/L1-6 + 开关留痕：实测守住。L1-6 当轮由另一窗口实装(b19c63e)、复验命中即拒 PASS。
- 🔴→✅ **L1-7 加密缺口已修**：全库 154 表曾 3 张未加密——`merchant_leads`(phone/wechat/address PII)+`nezha_refund_records`(USDT地址,≥5年)已 `ALTER ENCRYPTION="Y"`（用户批准）；`nezha_sanction_addresses` 未加密但装**公开 OFAC SDN 数据(非PII)**，不构成缺口、未动。
- 🟡 待办：admin 确认收款幂等守卫下沉本体（热点函数，留协调）；订阅直付扣佣边缘（无订阅业务时不触发）。`sync-sanction-list` 已挂每日 04:30 调度（实测确认）。
- ⚠️ 数据局限：全库仅 1 单/0 退款/0 二清，**对账数字与资金闭环无真实数据可跑**（轴F/第5层闭环只验机制）。

## 给用户亲手补验（AI 够不到）
- 律师审校退款/AML 政策（亚美尼亚 + B 模式）
- 真实资金闭环（QA 第5层）：有真实商家+订单后走"下单→真付商家→确认→扣佣"全链对账
- 专业渗透（不替代第3层应用层安全QA）
