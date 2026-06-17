# 哪吒 业务流程交叉验证 QA Playbook（第 10 层 · 触发词「全平台业务交叉验证 / 交叉验证QA / 全流程业务QA」）

> **定位**：验**顾客 ↔ 商家 ↔ 平台/后台 三方，在真实运作状态下，每条业务流程跨角色握手是否对账、跨界面数字是否自洽且正确**。
> 不是单角色单页"通不通"（那是第1层产品QA），不是账务对称（第4层），是**整条业务链三视角是否既自洽又正确**。
> **读法**：`node nz.js run "cat /www/wwwroot/api.nezha.am/docs/CROSSCHECK_QA_PLAYBOOK.md"`
> 由来：2026-06-18。"全流程QA"反复被窄化（漏退款、漏登录态、漏跨界面数字一致性=横幅幽灵数 bug）→ 固化成本层 + 覆盖深度纪律。

## 0. 铁律（违反即"没做全"）

1. **先摊矩阵再跑**：3 角色 × 4 类路径 × 2 登录态，逐格标覆盖深度，**不静默只跑 happy path**。
2. **覆盖深度诚实**：每格标 ✅真机实测 / 🔶账面回滚仿真 / ⬜未覆盖 / 👤需真人 / 🟣wired未命中。
   **仿真 ≠ 实测；proxy ≠ 真实路径（别拿 demo 店6 代真实店12）；wired ≠ 命中分支；单点 ≠ 矩阵。**
3. **🔴 数字/计数类功能必须用真数据，绝不注入**：注入假计数（拦截塞 `new_pending_order_ids:[900001]`）会**把"这数字怎么来的、对不对"整道题跳过**——只验了 UI 机制（弹/跳/去重），没验数据正确性。横幅幽灵数 bug 就这么穿过去的（教训 [[verify-users-actual-path-not-proxy]]）。验计数=真单跑出来 + 顺手对侧栏/列表。
4. **🔴 跨界面不变量轴**：同一个数字在 ≥2 处显示（横幅 vs 侧栏徽标 vs 列表条数 vs 详情），**必须同源或显式对账**。一处一套查询、另一处另一套、本该相等却分叉 = 系统性盲区，专设一轴查（见 §2 轴 I）。
5. **三方握手对账**：每个跨角色交接点都对账；**三视角自洽 ≠ 正确**（可以一致地一起错）。
6. **四类路径全覆盖**：正向 + 逆向(退款/取消/拒收打回/部分退/重下单) + 异常(上传失败/连点/超时/售罄/限额命中/制裁命中/余额不足停接单/limbo脏单) + 合规(L1 全条真生效)。
7. **两种登录态全覆盖**：登录 + 未登录；含登录闸(`guest_checkout_status=0` 必须登录下单)、游客本地车登录后**合并不丢**。
8. **安全**：money-write 走事务回滚仿真(零落库)+用户批准；测试单用 **demo 店(非真实店12)**+跑完清；不真退真扣真通知真实顾客。

## 1. 交叉验证矩阵（逐格要证据，标覆盖深度）

### 顾客侧（customer）
| 路径类 | 未登录(guest) | 登录 |
|---|---|---|
| 正向 | 浏览 home/餐厅/本地生活✅；加购本地车✅；**checkout→登录闸拦住下单**(必验) | 浏览→加购→结算→选支付→上传凭证→下单(E2E 真测) |
| 逆向 | — | 取消未付款单 / 取消已付款单→联系商家退款closure / 申请退款 |
| 异常 | 登录闸是否漏(游客绕过下单?)；游客车登录后是否丢 | 上传失败回滚(#3)；连点双提交(#4)；上传控件挡按钮(#2) |
| 合规 | 未登录不留 PII | 直付不碰钱(L1-1)；原路退(L1-2) |

### 商家侧（vendor）
| 路径类 | 取证点 |
|---|---|
| 正向 | 新单提醒(横幅/响铃/Telegram)→**待确认收款 tab 看到单**→确认收款(L1-6筛查嵌入)→开始备餐→准备移交(出餐) |
| 逆向 | 拒收/打回(deny_offline_payment)；待退款 tab 标记已退款 |
| 异常 | **跨界面数字一致(横幅计数 == 侧栏徽标 == 列表条数)**；营业时间未设→顾客下不了单；售罄 |
| 合规 | 制裁命中拒收不放行出餐(L1-6)；扣佣停接单(deposit 阈值) |

### 平台/后台侧（platform/admin）
| 路径类 | 取证点 |
|---|---|
| 正向 | 下单 gate→确认→出餐→settle→扣佣→对账(Σ commission_deduction == Σ admin_commission) |
| 逆向 | admin 退款(refund_order 对称+护栏 check_limits/lock_route+账本 nezha_refund_records)；超限→转审核队列 |
| 异常 | 并发双花/lost-update(lockForUpdate)；幂等(transaction==null 守门)；**裸 DB 计数绕作用域(代码味道, 见 §2 轴 I)** |
| 合规 | L1-1~L1-7 真生效(第4层 FUNDS_COMPLIANCE 交叉) |

## 2. 专设轴

- **轴 I 跨界面不变量/数字一致性**（本层核心，既有 QA 的结构盲区）：
  - 任何"计数/金额/状态"在多处显示 → 列出所有显示点 + 各自数据来源 → **同源或对账**，否则记隐患。
  - **代码味道扫描**：`grep -rn "DB::table('orders')" app/Http/Controllers/{Vendor,Api} | grep -E 'count|checked|pluck'` —— 面向顾客/商家的**裸 DB 计数绕过模型全局作用域**(NotDigitalOrder/NotPos/zone)是幽灵数温床。已知实例：`Vendor/SystemController.php:20`(新单指示器, 不筛 order_status)——**与横幅幽灵数同源、待修**。
  - 触发条件常是 **limbo 脏单**(被计数却被作用域隐藏)：happy-path 造干净单照不到、全流程很快推过 pending、进列表页 checked 翻 1 横幅即清零→脚本停不住，**只有真人盯有脏遗留单的真屏才撞得到**。故本轴**必配真人补验**。
- 其余沿用各层：渲染/交互(第1)、IDOR(第3)、账务(第4)、闭环(第5)。

## 3. 可复用技法（取证手段）
- 顾客 token：`POST /api/v1/auth/login`(login_type=manual) → 注入 `localStorage.token`(顾客登录无验证码可 API 直取)。
- 商家会话：服务端 `Auth::guard('vendor')->loginUsingId` + `CookieValuePrefix` 加密 cookie 注入 Playwright + chown session 给 www + 用完删（见 [[nezha-merchant-panel-ui-verify]]）。
- 后台 admin：内部派发 或 Basic Auth+session（[[nezha-admin-page-verify-internal-dispatch]]/[[nezha-admin-basic-auth-lock]]）。
- money-write：`DB::beginTransaction()…DB::rollBack()` 仿真，零落库，需用户批准。
- 完整真单实操：[[nezha-fullflow-real-order-qa]]。

## 4. 输出格式
1. **填满的覆盖矩阵**（3×4×2 每格标深度+证据）。
2. **轴 I 不变量报告**：每个多处显示的数字的来源对账结果 + 裸DB计数 grep 清单。
3. **缺口清单** + **给用户亲手补验**（真链 USDT、真机推送、limbo脏单真人盯屏、律师）。
