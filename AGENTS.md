- [x] Codex(exact-main 发布门收口·2026-07-14) 已完成：在隔离 worktree 合入测试夹具/SQLite 兼容修复 `69502957`，并以 `ab346c42` 对齐退款阶段契约；精确 main 的 Feature 186 tests/962 assertions、Unit 15 tests/46 assertions 均 exit 0，push 前 L1/IDOR/在场感知墙 20 tests/100 assertions 通过。相对 production `e044d34` 的 migration 文件 diff 为 0；未运行 migration、未部署 production、未触碰 dirty staging，完整证据见 `docs/PRELAUNCH_QA_RESULT_20260714.md`。
- [x] Codex(商家移动端付款凭证审核入口·2026-07-14) 已完成应用提交 `e42035c`：真实 staging 复现确认根因是可见现代详情只拥有确认动作，而拒绝、凭证截图和付款渠道仍在折叠旧区；现统一到现代付款审核卡的单一 owner，保留既有拒绝/确认端点与状态契约，并补拒绝原因/确认、loading、防重复提交、可恢复错误和 320px 无溢出布局。390×844、320×720、1365×900 真实 Chromium 完成拒绝→顾客重传→商家确认，快速双击均只有 1 请求/1 状态写入/1 通知；已确认重复调用和非本店订单均无副作用。目标契约 1 test/11 assertions 通过，整组 16 tests/125 assertions 仍有 2 个与本改动无关的 staging 旧 CSS/发票夹具失败；Blade 编译/lint、diff check 通过。API 未运行 reset/migration/restart；临时用户/订单/凭证/通知/任务日志已精确清零，商家/支付方式/通知配置逐字段还原。production 未部署且仍为 NO-GO，证据见 `docs/PRELAUNCH_QA_RESULT_20260714.md` 与本地 `output/playwright/merchant-proof-mobile-20260714/QA_MATRIX.md`。
- [x] Codex(部署器 fail-closed 修复·2026-07-14) 已完成（`7f929181`）：包准备/composer/migration/路由生成/P6 权限锁均改为切换前失败即停，FPM/队列/健康门/P6 deny-probe 改为切换后失败即恢复 previous 并复验；隔离故障注入 12 组全过，L1/IDOR 推送门 20 tests/100 assertions。Git blob 与 `/www/wwwroot/api-deploy/nzdeploy-api.sh` 运行入口同为 `0fa7075e…`，旧入口备份为 `nzdeploy-api.sh.bak.20260714-122641-pre-7f929181`；production current、FPM/队列 PID 均未变，未部署、migration、release 切换或业务数据写入。
- [x] Codex(上线前全平台 QA·2026-07-14) 已完成机器可达深度并判定 **NO-GO**：含测试夹具修复分支的主 QA 基线 Unit 13/29、Feature 177/914 全绿，全订单/退款/保证金回滚、三组各 20 单并发、隔离备份恢复、顾客/商家/后台 Chromium 与 P2 性能均已覆盖；随后 `origin/main e3ea7fb` 的新增目标测试 19/143 通过，但当时精确 main 全量 Feature 180 项因夹具修复尚未合入而有 9 errors + 1 failure。该历史红灯已由上方 `69502957` / `ab346c42` exact-main 收口关闭；NO-GO 仍由 production 版本差和外部硬门维持。阻断与人工门详见 `docs/PRELAUNCH_QA_RESULT_20260714.md`。一次性数据库、19090–19095 服务、QA 探针/脚本均已清零；未部署 API、未运行 migration、未切生产 release。普通 H5 bug 的自动升产边界只由前端仓库 `AGENTS.md` 正文拥有，不包含 API、数据库、开关、支付/资金、安全或数据契约变更。
- [x] Codex(部署器固定 SHA / 竞态闸门·2026-07-14) 已完成：`58b3e22` 加完整目标 SHA 与排队/current/ref 防漂移闸门，`ddc67ee` 把实际运行入口已有的 origin 健康门、共享存储探针、队列同步重启和 P6.0 只读锁补回 Git 正本；同一 Git blob 已安装到仓库脚本与实际运行入口，无参/短 SHA 均 exit 64。生产 current 保持 `20260714-070255-e044d34`，未运行部署器、migration、release 切换或 FPM reload。
- [x] Codex(商家候选批次预部署修复·2026-07-14) 已完成（f2c9505f）：Admin/Vendor POS 在实际配送费为 0 或低于区域最低配送费时不再返回可选配送档位，最终候选组合回归 53 tests/465 assertions、2 个变更 Blade 编译通过；生产 current 未变，未部署、迁移或写生产配置/数据。部署器 fail-open 后续已由 `7f929181` 修复并同步运行入口，本批次仍未部署。
- [x] Codex(Google address staging fix, 2026-07-13): complete in cacddaa; staging API + 390x844 browser QA passed; temporary address/user/token cleaned; no production deploy; no public storage.
- [x] Codex(staging 订单支付闭环·2026-07-14) 已完成：`d6378c4` 让 `data-nz-ajax` 只归统一 AJAX owner，`e3ea7fb` 把取消审批操作移入真实可见详情模式并补契约测试。staging 真实浏览器确认顾客取消→商家同意→待退款→商家标记退款→顾客已退回闭环；另以订单 `100016` 单击一次出餐，Nginx 仅 1 个 `mark-dispatched` POST、数据库仅 1 条配送通知，merchant console error 0。未跑 staging API reset/migration/restart；临时数据、凭证文件与通知已精确清理，商家/支付方式/通知配置逐字段还原，production current 未变且未发生真实资金动作。
- [ ] Codex(形态C v2·2026-07-13) 接手顾客端商家卡三态：customer_availability/分页前排序 + 首页、餐厅/搜索/分类/菜系「仅预约/休息中」底部横条。仅 staging，不碰生产，不改 DESIGN_SYSTEM/产品文档。
# AGENTS.md — 哪吒多窗口并发协调约定（所有 AI 窗口必读）

> ⚠️ 本服务器是**单一共享工作目录**，可能同时有多个 AI 窗口（Claude Code / Codex / …）在改同一批文件。
> 没有这份约定，两个窗口会互相覆盖未提交改动、或构建时把对方的半成品一起推上线（已多次发生：构建带半成品 → 全站 500）。
> **每个窗口开工前先读本文件，并遵守下面四条。**

## 0. 构建方式：排队脚本自行构建（不依赖人盯）〔2026-06-16 定〕
- 构建上线**一律走固定 SHA 排队脚本**：`node nz.js run "bash /www/wwwroot/nezha.am/nzbuild.sh <40位完整commit SHA>"`，无参/短 SHA 会被拒绝，**绝不裸跑 `npm run build`**。
- 脚本用 **flock 串行化**，并核对排队前后的 current、fetch 后的 `origin/main` 与完整目标 SHA；等待期间 current 改变或构建期间 ref 漂移会拒绝切换。失败不切换、健康门自动回滚。
- **任何窗口都可自行构建，不需要等谁手动把关**——用户不一定在电脑前。
- 残余风险（构建可能带上别人未提交的半成品）靠第 1 条「一窗一提交不留 WIP」降到最低，不靠人盯。

## 1. 一窗一提交（不留 WIP）
每改完一处、**Playwright 验证通过后立刻 commit**：精确 `git add <自己的文件>`（**严禁 `-a` / `-am`**，会打包别人的改动），写清描述后 commit + push。
**绝不让自己的半成品在工作树里过夜**——别人随时可能构建，你的半成品会被一起推上线。

> 🛡️ **推荐用 `/www/wwwroot/nzcommit.sh`（私有 index 提交，架构债 Step3-B）**：`node nz.js run "bash /www/wwwroot/nzcommit.sh <repo> -b <base64中文消息> <文件...>"`（英文 msg 可 -m）。它把暂存放进**每进程私有 index**（从 HEAD 起、只 add 你列的文件），**别窗的 `git commit` 卷不走你 add 的文件**（共享 .git/index 串味，2026-06-21 真出过事）；提交后自动 `reset HEAD -- <文件>` 把共享 index 拉平、防别窗 plain commit 回退你的文件。不 push，提交后照常 `git -C <repo> push`。opt-in，没用此脚本的窗口=现状无回归。

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
- [x] Claude(商家2FA恢复码改造窗·0720) ✅已实施·🔴未部署（待业主拍板）：①砍除商家恢复码整套（生成/展示/重新生成/登录页降级入口，Web+App 双端）②新增商家自助关闭入口 `merchant.2fa.disable`（当前密码 + 当前TOTP，吊销全部会话与App token、审计事件 disabled_by_merchant，当前浏览器会话即时重建不踢人）③超管应急重置 `nezha:merchant-2fa-recover` 由双超管硬性审批改为单超管必填 + `--second-approver` 可选（生产仅 1 个 role_id=1，原命令从未可执行）。改动：NezhaMerchantTwoFactor / MerchantTwoFactorController / Api VendorLoginController / RecoverMerchantTwoFactor / auth/merchant-two-factor.blade / routes/web.php + 5 个测试文件 + 契约文档 + compliance CHANGELOG + MERCHANT_GUIDE。验证：2FA 套件 28/28 通过（1 skipped = 需一次性 MySQL5.7 的并发用例）、全量 415 tests 仅 5 个 error 且在未改动的 origin/main 基线上同样复现（属新单提醒 v3.3 的 fixture 缺 timeout_notify_telegram 列，非本次引入）、blade 三态进程内渲染通过、恢复码引用全仓零残留。存量 19 家商家 0 启用 0 事件，零数据迁移。已过 nezha-auditor GATE：CONTINUE-WITH-CORRECTIONS，两条必改均已修（① compliance CHANGELOG 定级由 🟡 改为 🔴 L1-7 邻，与同子系统三条先例齐平；② 契约中「self-disable 并发至多成功一次」的措辞改为区分「已证明/待证明」，并在 Release gates 下显式列出未完成项）。🔴 未覆盖：MySQL 5.7 并发用例未真跑（服务器无 docker/无 5.7 实例，env-gated 跳过）、staging 与真机走查未做、enabled 态页面因生产 0 家启用而无法用真实状态到达。在隔离 worktree /root/nzwt/2fa-recovery 提交，未碰他窗 WIP。（2026-07-20）
- [x] Codex(本地生活国际快递文档同步·2026-07-19) ✅已完成：`ADMIN_GUIDE.md` / `MERCHANT_GUIDE.md` 已同步「包车出行」退役、「国际快递」启用、包车商家及 3 条服务帖迁入「本地旅游」，并明确国际快递合规边界；只改文档，未部署 API、未运行 migration、未再做服务器 build。
- [x] Codex(B1 最终候选重封·2026-07-15) ✅已完成：五份 `PRELAUNCH_B1_*` 表单与三份 PRELAUNCH QA/收口正本统一重封到 API `a53cfb5c967daa5917ce2cb4c2489d6799434ff2` / Web `b4e0ea0f17e3bfc65b3eebe9e645f5334de0faed`，8 文件内容快照 `fc026a78130709ce13af356914ce01c50000d866` 的 parent/tree/SHA-256 已回填。API 独立本地克隆聚焦 5/51、Feature 191/1013、Unit 15/46、8/8 PHP lint 全绿；`b14c9c58..a53cfb5c` 运行时文件无差异，production hotfix→候选 migration diff=0、线上 460 Ran/0 Pending。外部材料仍 0 件、production NO-GO；全程未部署、未迁移、未写 production/shared staging、未发真实通知、未运行备份或 demo `REHEARSE/GO`。
- [x] Codex(后台/商家同邮箱隔离·2026-07-15) ✅已上线 production 基线热修 `dea5dd11`（release `20260715-042928-dea5dd1`）：admins / vendors / vendor_employees 新增、修改、批量导入统一跨表邮箱守卫，忽略大小写与首尾空格；网页/App 自助注册仍先过 `toggle_restaurant_registration=0` 关闭门，不开放注册、不改顾客账号。新增回归 5 tests/49 assertions，连同管理员登录边界为 10/166；完整 Feature 191/1013、Unit 15/46 通过，8 个热修 PHP 文件语法/diff check 通过。首次误以 main 头 `b14c9c58` 为单项发布目标，健康门虽过但发布后范围复核发现相对原 production 含 67 个文件/多项未授权代码，立即恢复 `e044d34` 并同步重启 FPM/双队列、三健康端点 200；随后从 `e044d34` 制作只含 8 个运行文件、migration diff=0 的 `dea5dd11` 并重新发布。最终 live：config/zone/商家登录=200，管理员 GET/POST 无 Basic=401，网页入驻=302 回首页，App 注册明确返回 self-registration disabled，线上规则只读探针全过，三组邮箱交集仍为 0；无生产数据写入。
- [x] Codex(商家 USDT 双二维码·2026-07-14) ✅已上线（8a7cca3 / release 20260714-000806-8a7cca3）：vendor-views/wallet-method/index.blade.php 按真实配置只读展示 TRC20/BEP20 各自地址与二维码；新增 NezhaVendorWalletMethodTwoQrContractTest。永久测试 2 tests/11 assertions、源码契约 11/11、Laravel 双/单/空四态渲染 11/11、git diff --check 通过；部署健康门 config/zone/login=200、COD 关闭与 P6 deny-probe 通过；生产商家会话真机验证 1032×1272 两卡并排、645px 窄视口堆叠、每卡各 1 地址与 1 SVG 二维码、无横向溢出、console error=0。未改地址生效、客户付款、状态机、配置或迁移；回滚点 release 20260713-222703-62c8e30。
- [x] Codex(商家面板精简·2026-07-13) ✅已上线(62c8e30·release 20260713-222703-62c8e30)：shopInfo 删除商业模式/平台佣金/访问网站；shop/edit 隐藏英文/中文页签但保留翻译提交值、删除图标与封面；restaurant-index 隐藏订单类型/其他设置并以无 id 隐藏字段保留原值。pre-push 20 tests/100 assertions、Blade 编译 3/3、结构与保值断言 30/30、健康门 config/zone/login=200、三页登录浏览器验收通过；无表单提交、无迁移/配置改动。
- [x] Claude(P1b-C订单页4+1组tab窗口) ✅已上线(19c61f8·release 20260703-033156·健康门config/zone/login=200) list.blade+Vendor/OrderController(list)+CentralLogics/NezhaOrderCounts —— 14平铺tab→「需动作/进行中/售后/已完结/全部」5组+组内二级chip; 组过滤/计数走 NezhaOrderCounts 单一真相源(applyGroupFilter+grp_* rollup+actionOrderIds按单去重·refund_requested并入告警); 补 timeout/done_canceled 计数修chip误显; 需动作告警红V2 #E5484D。LIVE复验: 5组+6chip list_total==provider·分区22=all不相交·D两段无回归·MERCHANT_GUIDE同步(793d173)。纯L3不碰confirm/payment/L1。未碰他窗WIP。（2026-07-03）
- [x] Claude(P1b-E侧栏收敛窗口) ✅已上线(703c302·release 20260703-041800·健康门200×3·[force-revert]净删237行) _sidebar.blade.php —— 「普通订单」17状态子项折叠菜单→单条「订单」+需动作徽标(读NezhaOrderCounts::grp_action·消除侧栏内联count分叉); 徽标稳态V2红#E5484D·需动作=0隐藏(@if>0·rid7实测无假告警红0)·落点=grp_action需动作组。DoD达成: 侧栏徽标==订单组tab==provider 严格相等 LIVE 12==12==12; 待办条个体桶同源。🎉P1b全清(A/B/C/D/E)。纯L3不碰count机制/L1。订阅菜单@if(false)停用未碰·未碰他窗WIP。（2026-07-03）
- [x] Claude(搜索需求+押金列窗口) ✅已完成上线(提交 c56f866 押金 + 5558cf5 搜索): ①全量搜索埋点 nezha_search_terms 表 + NezhaUsageLog::searchTerm(埋 Api/V1 RestaurantController+ProductController 各1行, 端到端 curl 实测采集通) + NezhaPurgeAnalytics 扩展 ②后台「搜索需求」页(新 Admin/NezhaSearchDemandController + admin-views/nezha-search-demand/index.blade + routes/admin.php 路由组 + _sidebar.blade 菜单项, 热门/没结果双视图+过滤+导出CSV) ③商家列表押金状态列(新 NezhaDepositHealth 四档单一真相源, Vendor/DashboardController 收口对拍64/64零回归, Admin/VendorController::list + admin-views/vendor/list.blade 加押金列+补全别窗漏的抽佣开关td修表头错位bug) + ADMIN_GUIDE §10/§23.3。**未碰他窗 WIP(resources/lang/en/messages.php · _image-uploader.blade)**。（2026-07-01）
- [x] 窗口(F-4) 已完成(提交 6a94cab) 改过 OrderLogic.php / Admin·Vendor OrderController / vendor order-view·_sidebar / routes·docs —— 直付单退款通知商家+留痕（2026-06-17）
- [x] Codex 已完成(提交 e2d5134) OrderLogic.php / Helpers.php —— 顾客确认收款通知去重及 Yandex 状态文案（2026-06-19 07:37→07:45）
- [x] Claude 已完成(自 Codex 接手·提交 fac6624) 客户可见内部术语去除: zh-CN 与 zh-cn/messages.php 的「契约/交易引擎/状态机/推演」共 55+55 处改普通用户语言(order_updated_successfully→订单状态已更新、order_push_title→订单状态有更新、order_placed_successfully→订单已提交、Delivered→已送达、completed→已完成、Privacy_Policy→隐私政策、subscription_*契约系→订阅系等), 保留 key/占位符/业务含义; php -l 通过, 4 词残留=0。验证: app 实发 X-localization=zh-CN(确为所改文件); 渲染页 home/profile/tracking/order-history 与实时 API(order/track·details order#12,zh-CN) 均无术语。仅改两 messages.php(pathspec), 未碰他窗 NezhaDeliveryAppeal WIP。**第二轮(提交 948b4c5)已清剩余生造黑话**: 妥投|物理结单|战绩|C端|风控介入|订单池|跳变|闭环|履约链路 共 38+38 处, 按「客户可见/管理端/代码注释」分类改写(非机械全局替换): 客户可见 refund requested→退款申请中·orders_delivered→已送达订单·THANK_YOU→感谢您的惠顾·customer wallet→顾客钱包·wallet_payment→钱包支付; 管理端设置项按原意专业化(C端→顾客/客户端·物理结单→将订单状态改为已送达·风控介入→确定要…吗, 去探针/蓄水池/编外推销员/灰产/投喂等)。前端客户面仅代码注释 PC端(保留)。两文件 php -l 通过, 9 词残留=0。验证(BUILD SlcJIP1m): 实时 zh-CN API order/list·track·details·wallet/transactions·config 均无黑话; 移动端 home/profile/order-history/tracking/info?orderId=13 渲染无黑话·无溢出·console=0。后端改即时生效(无需前端构建)（2026-06-19 10:10→11:05）

- [x] Claude(handover/picked_up超时迁移窗口) ✅已完成(后端 c1c6225, 13测试58断言PASS): 后端 NezhaOrderTimeout.php(加 handover/picked_up 阶段+45/90阈值进business_settings+describe分支,含无时间记录诚实兜底/contact_hint) + ORDER_TIMEOUT_RULES.md/compliance CHANGELOG/ADMIN_GUIDE + 新增 tests。不碰 OrderTimeoutSweep 动作层(handover/picked_up 仅展示不自动取消)。前端 TrackingPage.jsx 删本地45/90 longWait改读nezha_timeout（2026-06-19）
- [x] Claude(配送+支付方向调整窗口) ✅已完成(FE 37eb009 / BE 959d2ab) 改: 后端配送责任一律merchant(Order.php/OrderController/Helpers/OrderLogic)+去微信支付(停用offline方式id=3+Restaurant模型+VendorController+vendor/admin payment-info·wallet-method blade)。不碰messages.php(他窗WIP)（2026-06-19）

- [x] Claude(钱包/提现B方案审计窗口) ✅已完成(提交见本次) 审计商家钱包/提现并加固: admin _sidebar.blade.php 隐藏提现列表/方式菜单(false&&) + Vendor/WalletController::w_request + Api/V1/Vendor/VendorController::request_withdraw + Admin/VendorController::withdrawStatus 三处加 nezha_withdraw_enabled 硬闸(默认禁用) + docs/compliance/CHANGELOG.md 记录。不碰他窗 WIP(OrderLogic/Vendor·OrderController/messages.php/order-view.blade)（2026-06-20）

- [x] Claude(法务文案窗口) ✅已完成(提交见本次) 补 docs/legal/about-us.md + docs/legal/refund-policy.md 草稿(关于我们/退款政策),核对已上线 terms/privacy 准确性。仅写 docs/legal/ 草稿文件+本认领行,不动 live data_settings/不动前端/不构建。已发布关于我们到live data_settings+修terms星号瑕疵(<strong>)+清缓存+真机验证;退款仅靠用户协议§5不单独开页(未动refund);更CHANGELOG/ADMIN_GUIDE17。备份/root/nezha-legal-backups（2026-06-20）
- [x] Claude(商家逾期未退款兜底窗口) ✅已完成(本次提交): 新增 RefundOverdueSweep 命令+NezhaRefundOverdueMail+迁移(账本表+restaurants挂起列+settings)+后台逾期未退款列表; 改动共享文件: Api/V1/OrderController.php(接单闸加一条match臂+helper) / Vendor/OrderController.php(mark_refunded自动解除挂起) / bootstrap/app.php(调度) / zh messages.php(1条key) / docs (2026-06-21)
- [x] Claude(订单取消机制窗口) ✅已完成上线【顾客接单后申请取消 + 商家拒单】: 迁移 orders nezha_cancel_*5列 + OrderLogic::finalize_cancellation/notify_customer_cancel_refund + Api cancel_request 端点 + track nezha_cancel meta + Vendor reject_order/cancel_request_decision + 补 status:541 留痕缺口 + 路由 + order-view.blade(顾客取消申请同意/拒绝 banner + 商家拒单按钮)。后端25项断言全过(三路+L1零平台退款)·route 401 live·前端 BUILD Z_n44055 三态验过。提交: 后端 d040747(被逾期未退款窗口共享index一并提交)+229ea64(补cancel_request方法体)+eadbecd(文档), 前端 6b02277。未碰他窗WIP（2026-06-21）
- [x] Claude(逾期未退款阈值设置页+角标修复窗口) ✅已完成(本次提交): admin overdue.blade加阈值设置卡+NezhaRefundController::overdueSettings+admin路由overdue.settings + vendor _sidebar.blade待退款角标改与列表同口径(只数订单存在的) + 删孤儿退款留痕#20 + ADMIN_GUIDE19.1 (2026-06-21)
- [x] Claude(硬禁业务/入驻审核窗口) ✅已完成(本次提交): 本地生活/入驻「坚决不能上线的业务」机制+SOP。新增 app/CentralLogics/NezhaContentScreen.php(UGC发帖+商家录入共用违禁词库) + 迁移 local_life_categories.compliance_level三级(0可上/1需牌照人工审/2硬禁,回填移民/签证/按摩→1) + 改 LocalLifeCategory模型/Admin·LocalLifeCategoryController(三级+硬禁强制停用)/Api·LocalLifeController(bannedWords委托helper)/Admin·LocalLifeMerchantController(录入命中即拒) + categories create/list.blade三级UI + 线上locallife_banned_words 37→100词(旧值备份_locallife_banned_words_backup.txt) + MERCHANT_GUIDE/ADMIN_GUIDE8.7/compliance CHANGELOG/INVARIANTS。未碰他窗WIP(PlaceNewOrder.php/en messages.php)（2026-06-21）
- [x] Claude(AI在线客服窗口) ✅增量A已完成(开关默认关,代码已上线工作树): 新增 app/CentralLogics/NezhaCsAssistant.php+NezhaCsClassifier.php + 迁移 nezha_cs_logs(无PII审计) + 接入 Api/V1/ConversationController::messages_store admin分支(顾客发客服→敏感词命中转人工/低风险走DeepSeek自动答,人设模仿真人不自曝AI)。business_settings: nezha_cs_ai_status默认0/api_key/model等6项。真跑测试账号user6三条(FAQ自动答✅/退款转人工✅/越狱不泄密不露馅✅)全过,测试数据已清。增量B(商家模板化转达)待做。未碰他窗WIP(PlaceNewOrder.php/en messages.php)（2026-06-21）



- [x] Claude(后台客服语音窗口) ✅已完成(本次提交): 商家后台新增「客服(平台/admin)来新消息」专用提示音(叮咚铃+低音人声 new-admin-message-voice.wav),与顾客「新消息」音区分。改 Vendor/ConversationController::live_status(拆顾客/客服两条最新id)+vendor app.blade.php(加nzAdminMsgAudio+轮询分流+蓝色toast「客服来新消息了」)+MERCHANT_GUIDE。未碰他窗WIP(CS assistant/notification_preferences等)（2026-06-21）
- [x] Claude(AI在线客服窗口) ✅增量B已完成上线: 转人工改为「引导顾客联系对应商家」(去掉MVP无人手时的假承诺)+商家模板化转达(固定模板·30min限频·开关nezha_cs_merchant_relay_status, 以顾客身份发起+FCM/Telegram通知商家)+人设更口语化。两开关均已开(nezha_cs_ai_status=1/relay=1)生效。L1-1合规(只路由通信不碰钱)。真跑测试账号user6验证(FAQ自动答/退款引导找川味轩+转达店6/越狱限频不重复转达)全过,测试数据已清。未碰他窗WIP（2026-06-21）
- [x] Claude(招聘反诈护栏窗口) ✅已完成(后端即时生效·本次提交): NezhaContentScreen补18个博彩/园区招聘强特征词(线上词库100→118,不误伤正经招聘)+ADMIN_GUIDE 8.7D招聘类专项红线+compliance CHANGELOG。前端招聘风险提示条另在前端仓提交(构建暂缓:别窗SettingPage有243行半成品)。未碰他窗WIP（2026-06-21）
- ⚠️ Claude(AI客服窗口)事故提醒(2026-06-21): commit 16177bb 因【共享git index】误把别窗暂存区的11个文件(Helpers/OrderLogic/Admin·ConversationController/CustomerController/User/routes api/notification_preferences迁移/客服语音wav/vendor app.blade等)连同我的2个AI客服文件一起提交了。①线上无影响(后端跑工作树)②你们这些文件的当前工作树版本仍是M/未丢,正常提交即可(会盖在16177bb上)。教训: 多窗口下提交用 `git commit -- <自己的文件>` 只提交指定路径, 别用 `git add X && git commit`(后者会提交整个共享暂存区里别人add的东西)。
- [x] Claude(违规帖证据冻结窗口) ✅已完成(本次提交·🔴L1-7有限例外·已批准): local_life_posts加legal_hold/reason/at(迁移140000)+PurgeLocalLifePii跳过冻结帖(contact+图留证)+后台legalHoldToggle方法/路由/列表blade🔒按钮+冻结徽章+模型fillable/cast。§5.3承诺(正本+线上locallife_terms)加「依法/配合调查需保留除外」(线上旧值备份_locallife_terms_backup.txt)。docs:data-protection/ADMIN_GUIDE8.8/CHANGELOG(L1变更)/INVARIANTS(L1-7附注)。验证:dry-run冻结帖豁免/未冻帖被清+后台渲染200。未碰他窗WIP（2026-06-21）
- [x] Claude(隐私政策对齐窗口) ✅已完成(本次提交): 隐私政策§5加(5)本地生活联系方式30天清除+留证例外(正本docs/legal/privacy-policy.md+线上data_settings.privacy_policy,旧值备份_privacy_policy_live_backup.html),清Laravel缓存+nginx60s自动过期,真机/privacy-policy验证渲染console0。仅privacy-policy.md+CHANGELOG+AGENTS。未碰他窗WIP/已暂存MM（2026-06-21）

- [x] Claude(后端部署边界改造窗口·架构债根治) 正在做【后端发布边界 Capistrano-lite·阶段1a】: 建 /www/wwwroot/api-deploy/{shared,releases}+current软链、storage 与 .env 抽共享、改备份脚本指向 shared、生成首个 release。🔴动 storage/结构·牵一发动全身——别窗请勿动 storage/ 与新部署结构;此步不碰 nginx/cron/fpm,线上仍读旧工作目录【完全无感】。回滚=删 api-deploy + storage 切回。(2026-06-22)
- [x] Claude(集成验收窗口·代提交) ✅ 佣金计算收口已提交 f99c383: 某窗口(疑B支付方式组合)把 OrderLogic::create_transaction 内联计佣公式抽成纯函数 nezha_commissionable_amount() 的改动停在工作树未提交、未登记认领;经用户授权由集成窗口代提交(私有index精确 add 仅 OrderLogic.php + NezhaCommissionTest.php,未带 en/messages.php、无别窗WIP)。已逐分支验证等值(费率/计佣基数/express二次扣/订阅免佣)+NezhaCommissionTest 10例19断言全绿+pre-push红线通过。🔴原窗口勿再重复提交这版佣金重构(已在 f99c383)。另:本窗顺手清理 en/messages.php 尾部3行 translate()自动污染(含中文键"已标记为已退款"=确证污染,来源 Vendor/OrderController:378),已还原至 HEAD,备份 messages.php.bak.intgwin.*（2026-06-22）
- [x] ✅ 已收尾(提交 8c799e1, 佣金窗口 2026-06-23: 5.5b 曾随 d243409 提交但被并发窗口冲出 HEAD, 已从工作树重新精确提交+push, origin 已含) — 原 🔔 知会【单店专属佣金率/佣金窗口】(集成验收窗口留): 后端共享工作树 ADMIN_GUIDE.md 有 +10 行未提交(新增 ### 5.5b 单店专属佣金率)，正是你 d243409 提交说明里写明"ADMIN_GUIDE新增5.5b"要随该提交一起的文档——代码已上(私有index)，这段 doc 漏在共享树没 staged。集成验收窗口按规【不代提交业务窗口文件】，请你: ① git diff HEAD -- ADMIN_GUIDE.md 确认是你的、无别窗 WIP 混入 ② 用 bash /www/wwwroot/nzcommit.sh /www/wwwroot/api.nezha.am -F <消息文件> ADMIN_GUIDE.md 精确提交+push 收尾 ③ 划掉本行。当前线上发布(前端 BUILD c4JH_DxrABm2DVdXiLRc1)不受其阻塞(后端 push-gated，未提交不上线)。(2026-06-22)

- [x] Claude(窗口ADMIN-SEC-P0·后台安全P0) ✅已完成上线(提交 8cb8b83, deploy release 8cb8b83): 敏感路由权限位拆分(nezha-risk 拆 module:risk[队列/日志/处置] + module:risk_settings[改阈值] / nezha-refund→module:refund / nezha-deposit→module:deposit / nezha-kyc→module:kyc, 原均搭 order/restaurant 宽位=活体坐实越权) + custom-role create/edit.blade 补 risk/risk_settings/refund/deposit/kyc 5 个 checkbox + 风控设置页 BscScan/TronGrid API key 非超管脱敏(NezhaRiskController::updateSettings 同步写入守卫) + ModulePermissionMiddleware admin 无权限落品牌化中文 403 页(admin-views/errors/no-permission.blade; vendor 分支保持 back() 不动)。验证免 captcha 确定性: 权限门三态(order-only全deny/risk_settings-only仅settings/超管全allow) + route:list 接线(working+deployed两层) + 密钥脱敏渲染(超管明文/非超管掩码) + 3 blade 编译 + php -l 全过; 唯一现存 admin=超管恒 bypass=生产零影响。改 7 文件, 未碰别窗 WIP(ADMIN_GUIDE.md 等)。🔔 留给其它窗口: ①侧栏 _sidebar.blade 「风控中心」仍判 module_permission_check('order')、nezha-deposit 判 'account'——归 UI-1 对齐到新位(当前 fail-closed 安全: 路由已拦死, 超管照常全见) ②nezha-cs 仍搭 module:order(含 DeepSeek key + relay 模板转达, 建议另拆独立位) ③审计日志 SEC-3(改阈值 updated_by=0 无 Log / 角色 / 员工变更无审计)另窗做。(2026-06-23)

---
## 🔴🔴 后端部署契约已变更(2026-06-22 部署边界改造窗口) — 所有后端窗口必读
**后端「存盘即上线」已废除。** production 现从 `/www/wwwroot/api-deploy/current`(→ releases/ 下不可变快照)跑,**只认 commit+push 到 origin/main 的代码**。
- 改后端 = 工作树 `/www/wwwroot/api.nezha.am` 改 → 精确 `git add`+commit+**push** → 记录欲发布的完整 40 位 SHA → 跑 `node nz.js run "bash /www/wwwroot/api-deploy/nzdeploy-api.sh <40位完整commit SHA>"` 上线（目标必须可从 fetch 后的 `origin/main` 到达；干净ref+vendor硬链+排队/current/ref 防漂移+原子切current+健康门+自动回滚）。部署器拒绝无参、短 SHA 与隐式 latest main。
- **发布范围护栏（2026-07-15）**：运行部署器前必须先把 `current` release 名中的提交解析为生产基线，逐项核对 `git log --oneline <current-sha>..<target-sha>`、`git diff --name-status <current-sha>..<target-sha>` 与 `git diff --name-only <current-sha>..<target-sha> -- database/migrations`。只要目标夹带本次未授权文件、功能或 migration，就必须停止，不能因目标是 `origin/main` 头或健康门会过而继续；需要单项热修时，从当前 production 提交建最小 hotfix，再以不改变 main 树的 merge 让该 hotfix 成为 `origin/main` 可达提交，部署 hotfix SHA 而不是 main 头。此条是人工护栏，不是部署器能机械判断授权范围的墙。
- 2026-07-14 实测 `/www/wwwroot/api-deploy/nzdeploy-api.sh` 是独立普通文件，不是指向仓库正本的软链；部署器改动必须从已 push 的精确提交提取同一 blob，同时备份并安装到仓库 `deploy/nzdeploy-api.sh` 和该实际运行入口，再核对两者哈希一致。禁止假定软链后只更新其中一份。
- **未提交/未push 的改动不会上线**(只停工作树)。这是有意为之的墙,不是 bug。
- 🔴 storage 与 .env 已抽到 `api-deploy/shared/`(L1凭证持久层),别动;工作树 storage/.env 是软链。备份脚本已指 shared。
- 回滚: `ln -sfn $(readlink /www/wwwroot/api-deploy/previous) /www/wwwroot/api-deploy/current && kill -USR2 $(cat /www/server/php/82/var/run/php-fpm.pid)`。
- 前端(nezha.am)暂未改,仍走 nzbuild.sh。

---
## 🔴 跨窗提交两道防线(2026-06-22 路线B + 防覆盖墙) — 所有窗口必读
单一共享工作树 + 共享 `.git/index` 的两个跨窗"提交卫生"事故已各砌一道墙。（注: catastrophic 的"带半成品上线/出货旧版"已被【前后端都已上线的 release 发布边界】另行杀死，deploy 只 archive origin/main、永不读工作树。）

1. **index 串味**(别窗 `git add`/`git commit -a` 卷走我已暂存的文件、归属混乱) → 用私有 index 提交助手：
   `bash /www/wwwroot/nzcommit.sh <仓库路径> -F <消息文件> <文件...>`   (中文消息可用 `-b <base64消息>`，或 `-m "消息"`)
   它把暂存放进私有临时 index，别窗看不见、卷不走；提交后只把我这几个文件回同步共享 index。
   ⚠️ 它仍从【共享工作树】读文件内容，提交前务必先 `git diff HEAD -- <文件>` 确认没扫进别窗 WIP。

2. **物理覆盖 / 旧备份回退**(别窗把旧 `.bak` 盖到盘上文件再提交，悄悄退掉我已 push 的新代码；2026-06-21 customerKb 被退案) → 两仓 `.git/hooks/commit-msg` 装了【防覆盖墙】：
   本次提交相对 origin/main **净删 > 15 行**即拦下，点名文件与行数。
   确是【有意】大删除 → 在 commit message 末尾加 `[force-revert]` 重提；应急 `git commit --no-verify`(自负风险)。
   注: 墙比的是本地 origin/main ref(未 fetch 会偏旧)，是提交期 best-effort；catastrophic 路径 push/deploy 自己会 fetch。

这两道只堵"提交卫生"。两窗同时物理改同一文件盖盘仍靠【认领区】。完整 worktree 隔离(路线A)暂缓，待并发变密集再升级。
- [x] Claude(窗口SEC-CS-PERMISSION) ✅已完成上线(提交7edc2cb·deploy release 7edc2cb): nezha-cs 后台拆独立权限位 module:nezha_cs(原整组搭 module:order=仅 order 权限员工即可进 AI 客服后台·改总开关影响真实顾客·看顾客客服评价含PII) — routes/admin.php 路由组 + _sidebar「AI在线客服」菜单 guard 由 module_permission_check('chat') 对齐到 'nezha_cs' + custom-role create/edit.blade 补 nezha_cs checkbox(复用既有 translate('AI在线客服') 零新增未译key)。DeepSeek key 脱敏审查=无需改动(nezha_cs_ai_api_key 后台从不渲染明文, NezhaCsController 仅取 (bool)hasKey 作"是否已配置"提示, saveSettings 不写该key, blade 无输入框, 仅服务端 NezhaCsAssistant 读取)。验证: route:list 4条全挂 nezha_cs + 权限门三态(order-only deny / nezha_cs allow / 超管 role_id=1 bypass) + 3 blade 编译 + create 页完整渲染含 checkbox + 部署版复核。私有index仅提交本窗4文件; _sidebar 只入 mine-only blob, 未带别窗风控UI-1侧栏WIP(其WIP仍在工作树完好)。（2026-06-23）
- [x] Claude(窗口UI-1-ADMIN-SIDEBAR) ✅已完成(本次提交): 后台侧栏 _sidebar.blade.php 风控中心权限位对齐到8cb8b83拆分的新位——组壳判 risk||risk_settings||refund||kyc(原判order)+各子项各自判对应位(queue/logs→risk·settings→risk_settings·refund records/overdue→refund·kyc→kyc); 佣金充值管理 account→deposit + 交易管理小标题组条件补 deposit。不碰路由权限逻辑(已在8cb8b83)。验证: 超管渲染/admin=200且7链接全在(无回归) + 构造 refund-only/risk-only/order-only 角色跑 module_permission_check 证分位过滤正确(order-only现全0=旧错位已修)。未碰他窗WIP(ADMIN_GUIDE/en messages.php)（2026-06-23）
- 🔔 知会【UI-1 侧栏权限对齐窗口】(SEC-CS-PERMISSION 留, 2026-06-23): 我已 deploy origin/main 到 7edc2cb, 生产 _sidebar 风控中心块=HEAD原版(order-gated, 你的 risk/risk_settings/refund/kyc 分项 guard UI-1 改动仍是工作树未提交WIP, 从未上线=无回归)。你的 _sidebar WIP 我没碰(用 mine-only blob 只入了我那一行 nezha_cs)。你提交时会自然带上我已在 HEAD 的 nezha-cs guard 行(兼容), 提交前照例 git diff HEAD 核对即可。
- [x] Claude(窗口M-01·商家Dashboard工作台) ✅已完成(待提交): 商家端 Dashboard 首屏工作台 M-01 —— Vendor/DashboardController 抽 restaurant_data 为同源 nezha_todo_counts()(加 refund_pending 计数+超时 processing→cooking 合法落点 key)+新增 nezha_today_summary()(今日订单/今日已确认到账 payment_status=paid/保证金健康四档同源接单闸/店铺评分累计 withAvg reviews) + dashboard.blade 顶部 include 两 partial + 新建 partials/_todo-actionbar.blade.php(5待办卡)+_today-summary.blade.php(今日经营4卡)。只 L3 只读聚合/版面重组, 不碰状态机/超时引擎/资金计算。内部派发验证: 同源对账 MATCH + 四态渲染通过。未碰他窗 WIP(custom-role/_sidebar/routes admin/en messages/ADMIN_GUIDE)（2026-06-23）

- [x] Claude(窗口M-04/M-05·商家订单详情顶部状态条+唯一主操作) 正在改 resources/views/vendor-views/order/order-view.blade.php —— Step A 抽 $nzPrimary 决策块(零视觉变化) + Step B 仅 pending·离线待核验一态打样置顶条(镜像确认收款·原位去重·保留打回/拒单);后续态待真机验证后再铺开。不碰路由/控制器/状态机;与M-01(dashboard)无重叠。(2026-06-23)

  ↳ ✅ Step A($nzPrimary 全态决策块·零视觉) + Step B(仅 pending·离线态置顶状态条) 已上线: commit dcea3ad / deploy release 20260623-040109-dcea3ad(健康门 config/zone/login=200, COD硬门🟢)。真浏览器验收(route-fulfill live-code HTML @真origin, Chromium desktop1440+mobile390/iOS-UA): 置顶条×1·确认按钮文案×1不重复·去重提示在·「未收到/打回」「无法接单/拒单」独立可见·凭证/链上核验区(应收/USDT/txid)原位·console=0·overflowPx=0·mobile sticky 滚动后 pinnedTop。9 笔测试单已清(残留0)。⚠️未做: 真点确认收款按钮走POST(避免改单/需真会话)·iOS真机·其余态B/C/D/D-prime/E/F/G(待你拍板铺开)。会话注入坑: 伪造vendor会话cookie经php-fpm解不开(CLI能), 改用 setUser内部渲染HTML+Playwright route-fulfill@真origin 验真浏览器。(2026-06-23)

- [x] Claude(DEPLOY-SCRIPTS-VERSIONING窗口) ✅已完成: api-deploy/nzdeploy-api.sh 纳入版本控制(方案A)。新增 deploy/nzdeploy-api.sh(就地cp字节一致)+运行路径改软链指向repo正本+时间戳.bak。不改部署逻辑/不碰shared/releases/current/previous/.env/PM2/COD策略。仅add deploy/nzdeploy-api.sh+本行（2026-06-23）

  ↳ ✅ 真链路补验(2026-06-23): 真商家会话(vendor6·CookieValuePrefix伪造·php-fpm GET200)+Playwright真点「确认收款」→ dialog接受→POST经真实路由/控制器→订单 1999009035(take_away·RMB离线) BEFORE pending/unpaid/offline=pending → AFTER order_status=processing·payment_status=paid·offline=verified·confirmed&processing时间戳·processing_time=30; 页面无500·console=0·confirm期无错误日志。测试单已删(残留0)。附:首试误用method_id=2(=usdt)触发制裁inconclusive→hold→未推进(=fail-closed正常+证明控制器路径真执行);改method_id=1(rmb)走通成功路径。会话伪造上次坑=漏CookieValuePrefix(已补,见[[nezha-merchant-panel-ui-verify]])。

- [x] Claude(窗口M04-STATE-EXPANSION) 已完成 batch1+batch2, 改 resources/views/vendor-views/order/order-view.blade.php —— M-04/M-05 第二阶段 batch1: 把 confirmed/accepted(开始备餐) 与 processing 自取/堂食(出餐完成待取) 两组状态的主操作上移到顶部状态条 + 原位去重(镜像现有 order-status-change-alert 同路由/同data-*/同JS确认弹窗)。复用 dcea3ad 的 $nzPrimary 决策块。不碰路由/控制器/状态机/退款/取消/Yandex/M-02 timeout。(2026-06-23)

  ↳ ✅ batch1 已上线: commit 2b60264 / deploy release 20260623-052045-2b60264(健康门 config/zone/login=200·COD硬门🟢)。confirmed/accepted「开始备餐」+ processing自取堂食「准备移交」主操作上移顶部sticky条+原位去重为提示。验证(工作树内部派发+Playwright真浏览器+已部署current三层):两态均200·唯一主操作锚点(顶部1原位0)·data-url目标processing/handover正确·负向按钮保留(拒单/更新出餐时间)·mobile375 sticky-pin·overflow0·console0·终态delivered零顶部条;测试单建后即删残留0。⚠️待铺(下一批,待用户拍板): D processing配送单(标记配送中)·D' handover配送单(mark-dispatched)·F handover自配/自取堂食(已送达/完成)·B 非离线pending接单·picked_up只读·终态只读。$nzPrimary决策块已含全态映射,仅缺顶部渲染+原位去重。(2026-06-23)

  ↳ ✅ batch2 已上线(2026-06-24): 上移门收为 kind=='link' —— 全状态真值表核验 link 态={B 确认收款·接单/C 开始备餐/E 出餐完成待取/F 已送达·完成}, 故纳入 B(非离线pending接单)+F(handover自配/自取堂食已送达完成); D·D'(标记配送中=form)+A(form)+G(无)+终态 天然排除(=用户要的砍 D/D')。原位 B/F 按钮去重为提示(F 条件改复用 $nzSelfDelivery 与上移门同源, 防去重提示与顶部条解耦)。验证:blade编译+php-l过 / 全状态真值表(B,C,E,F 为全部 link 态) / 真控制器渲染(vendor6 事务内翻 B(pending+take_away)、F(handover+take_away)→顶部条徽章「待接单·待确认收款」「已出餐·待取餐」+主操作+原位「已移至顶部」提示 均现, order-status-change-alert 真锚点=1, 终态 delivered 零顶部条, 事务回滚 canvas 单零残留)。⚠️未跑:真机 sticky钉顶/console0(sticky CSS 未改, 沿用 batch1 已验)·ODV开启态 F 的 OTP 弹窗(JS端, data-verification 真值表已证 ODV开=true/关=false)。
-
[x]
Claude(窗口MERCHANT-M02-TIMEOUT-IMPLEMENT)
✅已完成上线(代码
58c1356
/
deploy
release
20260623-051549-58c1356
/
文档
5557179):
商家端
M-02「超时」虚拟过滤。NezhaOrderTimeout::alertOrderIds()
纯新增只读聚合(offline_pending+confirmed+processing
三桶并集,镜像
list
作用域含
HasSubscriptionToday
防订阅单漂移)
+
Dashboard
超时计数收口同源(删过渡hack
timeout_list_map/key,保留
timeout_target
给
app.blade
超时弹窗)
+
OrderController::list
加
timeout
分支
+
NotDigitalOrder
白名单
+
标题特判「超时单」
+
_todo-actionbar
超时卡
href
改
/list/timeout。红线:
NezhaOrderTimeout
仅
+1
只读方法,phase/describe/clockStart/settings/sweep
状态机零改动(git
diff
证)。验证:
构造三桶超时单+三类排除项,alertOrderIds==list(timeout)==dashboard
card=3
同源
/
真实
FPM
路由200+标题超时单+空态暂无数据
/
Playwright
桌面+移动
console0
无溢出
/
事务回滚零残留。仅碰规定4文件+spec文档,未碰他窗WIP(ADMIN_GUIDE/en
messages/侧栏/order-view)。(2026-06-23)
↳
✅
小修(提交
aacbd9d
/
deploy
release
20260623-053720):
商家后台超时弹窗「去处理」落点对齐到
/list/timeout(DashboardController
timeout_target
改为
timeout,
之前取桶名会落
/list/processing=全部单与超时卡不一致)。仅改
DashboardController
一处,
不碰状态机/alertOrderIds/侧栏/app.blade/其它列表。Live验证(vendor6真会话):
轮询端点
timeout_target=timeout
+
timeout_list_key已移除
/
Playwright
dashboard
桌面+移动
200·超时卡href=/list/timeout·console0·溢出0
/
/list/timeout
仍200+标题超时单+空态暂无数据。(2026-06-23)
- [x] Claude(SEC-3审计日志窗口) ✅已完成上线(提交 a88a530 / deploy release 20260623-193612-a88a530): 新增 admin_audit_logs 表(append-only,batch145已migrate)+AdminAuditLog模型(fail-open) + 改风控阈值/角色增改删/员工增改删共8处写审计。补 ADMIN-SEC-P0(8cb8b83)交接的 SEC-3 缺口。密钥/密码明文不入库(密钥只记键名·员工只记password_changed布尔)。验证:php -l全过+三控制器diff零删除+迁移batch145 Ran+record()自测写读JSON cast正确并清零无残留;未在生产触发真实改阈值/建角色。纯增量L3不碰资金/合规/状态机/路由/权限位。(2026-06-24)
- [ ] Claude(遗留模块隐藏窗口·跨全站) 正在改 admin _sidebar.blade.php：用 false&& 守卫隐藏 B方案不用的遗留菜单(顾客钱包+加款/返现+钱包报表·骑手deliveryman全块·分账disbursement全块·骑手收入报表·收取现金account)，保留路由可逆。🔴不碰:顾客列表/佣金充值管理deposit/已隐藏的提现。用户已拍板(2026-06-24)。(2026-06-24)
  ↳ ✅已完成上线(提交 bc463a1 / deploy release 20260623-195850-bc463a1): 遗留菜单隐藏完成,超管派发/admin render=200·6隐藏路由计数=0·deposit/customer保留。(2026-06-24)
- [x] Claude(商家端全中文窗口) 已完成上线(提交 66a493d / deploy release 20260623-202325-66a493d): ①Localization中间件 restaurant-panel 强制 setLocale(zh)(忽略vendor_local/切换器,可逆) ②vendor _header 隐藏语言切换器 ③vendor blade硬编码英文改translate(review/product-view/addon×2/bulk-export) ④zh语言文件补3个缺失key。验证:vendor真实派发/restaurant-panel render=200·locale=zh·切换器计数0。注意:动了全局中间件 Localization(只改restaurant-panel分支,admin/landing未动)。未碰order-view(M04窗WIP)。部署坑:view:clear后首部署因冷编译撞8s健康门超时回滚,view:cache预热后重部署即过。(2026-06-24)
- [x] Claude(反馈收集窗口) ✅已完成上线【方案A/B/C 主动收集反馈】: A=AI反馈日报(nezha:feedback-digest+NezhaFeedbackDigest+表 nezha_feedback_digests+调度06:00+后台「AI客服」页加反馈日报开关与历史, 复用 DeepSeek/nezha_cs_ai_*+redactPii 脱敏, 开关 nezha_feedback_digest_status 默认0关; e863cd8) B=商家反馈入口(vendor_feedback 表+Vendor\FeedbackController+Admin\VendorFeedbackController+vendor/admin 各路由菜单+双向 Telegram; f8012e2) C=两轻量埋点(NezhaUsageLog+nezha_search_misses/nezha_cart_events+Product/Restaurant/CartController 各1行埋点+nezha:purge-analytics 每日03:25 回填转化&保留期清理, 不改下单/资金; e28e70f)。均已 nzdeploy-api 上线(release e28e70f)。共享文件(routes/admin.php·routes/vendor.php·admin/vendor _sidebar·3控制器·bootstrap·NezhaCsController·nezha-cs blade)用幂等插桩仅插我的、push前 diff 核对无别窗内容。🔔遗留: ①ADMIN_GUIDE 反馈日报开关/商家反馈页/埋点说明 因 ADMIN_GUIDE.md 正被别窗占用(工作树21行WIP)暂未写, 待其提交后补 ②vendor_feedback 暂无自动清除(可后补 resolved 满180天 purge) ③方案A开关待用户拍板开启。未碰别窗WIP。(2026-06-24)
- [x] Claude(部署脚本vendor加固窗口·共享基建·已完成 提交8417011/验证release 20260624-195122-8417011: hardlink-reuse路径正常+autoload断言不误触+COD自检绿+get-restaurants200; dry-test回退分支已验) 已改 deploy/nzdeploy-api.sh：修 L33 vendor 硬链级联缺陷(只判 composer.lock 不判 vendor 是否存在→上个 release 无 vendor 时 cp -al 失败却不回退致级联, 全站500炸弹·2026-06-24两窗已撞)。改:条件加 [ -d $CUR/vendor ]+cp 纳入条件失败回退 composer install+部署后断言 autoload 存在否则 exit1 不切 current。🔴改完要跑1次后端部署验证, 期间请前端窗口暂停构建(FE健康门检首页SSR依赖后端在线, 并发误判回滚)。用户已拍板我做。(2026-06-24)

- [x] Claude(优惠券重做窗口·B方案对标美团) ✅已完成(实为陈账·commits 已落: 后端 d635475 商家券 + 62f0aef 券包后端·前端 coupons-page/my-coupons 已提交; 广告 A 窗核实后清理): 商家券满减/折扣模板重组+顾客端领取到券包。后端: Vendor/CouponController.php·vendor-views/coupon/{index,edit,partials/_details}.blade.php·新coupon_claims migration+Model·Api/V1领取/我的券包接口+routes。不碰admin-views·不碰顾客端free_delivery显示分支(admin在用)·不碰en/messages.php。(2026-06-25)

- [x] Claude(安全审计查看页窗口·网安暂缓前已交付) ✅ AdminAuditLog 只读查看页已提交 de12c54→origin/main(🔴未部署·不在线上): 新增 Admin/AdminAuditLogController(只读分页+action/时间筛选) + resources/views/admin-views/nezha-audit/logs.blade.php + routes/admin.php(在 nezha-refund 组后插入 +5行 nezha-audit 路由组, 挂 module:audit=不在任何自定义角色 modules→仅超管 role_id=1 可见, 刻意没碰别窗 custom-role blade)。🔔 知会两个网安窗口: 我唯一碰的共享文件是 routes/admin.php, 你们提交前先 git diff HEAD -- routes/admin.php 核对 + 用 nzcommit.sh 私有 index 提交, 防共享 index 串味/被旧 .bak 覆盖。侧栏「安全审计」菜单行未加(等遗留模块隐藏窗口收工再加)。(2026-06-25)

- [x] Claude(单店抽佣开关窗口) ✅已完成(本次提交): restaurants.nezha_commission_enabled 列(默认0) + OrderController::nezha_commission_active 单一真相源(总开关 && 单店开关) 接四闸(OrderLogic扣佣/nezha_deposit_below_threshold下线/Vendor DashboardController健康展示/Admin VendorController payment-info) + 超管商家列表抽佣toggle(路由 admin.restaurant.toggle-commission + toggleCommission方法 + list/_table blade)。默认全关+总开关现为0=部署零行为变化,不误扣任何真实商家。未碰他窗WIP(en/messages.php=translate污染,不提交)。(2026-06-25)
- [x] Claude(职员编号窗口·B) ✅已完成上线(提交 126aeb0, deploy release 126aeb0): Admin/EmployeeController(nextEmployeeCode 前缀-序号)+CustomRoleController(岗位前缀存/取/校验)+custom-role create·edit(前缀输入)+employee list(编号列)+迁移(admins.employee_code 唯一 / admin_roles.code_prefix). 验证: 对 LIVE release 真渲染三页面 PASS(编号列含 CS-001 / 前缀输入 / 前缀回填)+nextEmployeeCode 真跑 CS-001→CS-002→EMP-001+5视图Blade编译. 未碰他窗 WIP(ConfigController/nzdeploy-api.sh/en messages.php). (2026-07-01)
- [x] Claude(转人工窗口·A) ✅已完成上线(提交 4ebbebf, deploy release 4ebbebf): NezhaCsClassifier::isHumanHandoffRequest + NezhaCsAssistant(转人工分支/humanCsOnline在线时段默认中国9-18=埃里温5-14/静默AI 30分钟防抢话/工单nezha_cs_tickets/告警) + Helpers::sendTelegramCsHandoff(回退nezha_risk_admin_chat_id). 验证: 分类器9正8反全对+离线在线端到端(接入语+工单+静默闸)+Telegram真投递超管+线上release自检. 通信路由不碰钱、L1-1不变. 未碰他窗WIP. (2026-07-01)
- [x] Claude(AI坦诚窗口·C) ✅已完成上线(提交 1882b46+3214358, deploy release 3214358): NezhaCsAssistant systemPrompt/translatePrompt 身份反转(扮真人否认AI→坦诚是AI客服小哪+被问就承认+打招呼自我介绍列服务+引导转人工)+移除 answer/softReply 的 revealsAi 抹除(保 leaksSecret 防越狱)+defaultWelcome+seedWelcome(首次打开客服播欢迎语,走现有气泡、幂等)+ConversationController::messages admin分支调用。NezhaCsController/nezha-cs blade 加在线时段(中国默认9-18)+欢迎语+告警chat_id 设置(点5)。验证: 真DeepSeek身份坦诚/打招呼列服务/越狱仍拒+设置页真渲染+欢迎语播种幂等。通信路由不碰钱、L1不变。未碰他窗WIP。(2026-07-01)

- [x] Claude(平台集运申报窗口) ✅已完成(本次提交): 新增「平台集运申报」商家需求登记表(Vendor NezhaConsolidationController + vendor-views/nezha-consolidation) + 管理端需求汇总/详情/导出CSV(Admin NezhaConsolidationController + admin-views/nezha-consolidation) + 迁移 nezha_consolidation_surveys + routes(vendor/admin) + 双端 _sidebar 菜单。仅采集进货意向不碰钱(L1无关)。进程内真渲染3页全过。未碰他窗WIP(ConfigController/nzdeploy-api.sh/en messages.php)（2026-07-01）

- [ ] Claude(Telegram双向客服·D窗口) 进行中·停在③前: ✅D-1(ebd79e5: webhook端点 Api/V1/TelegramWebhookController + 迁移 nezha_cs_tg_map + NezhaCsAssistant::postHumanReply + Helpers::sendTelegramRaw + 路由 api/v1/nezha/telegram-webhook)+✅D-2(4b1d5e0: pushCustomerMsgToAdmin/mapTgToConversation/csHandoffChatId 推顾客消息到超管TG带映射 + 去掉转人工告警「登录后台」行)已上线。🔴**未 setWebhook**=端点存在但 Telegram 不调用、对线上零影响、不碰商家 getUpdates 绑定。剩 ③改绑定(发码自动绑·商家后台+超管后台两处)→④setWebhook激活(配 nezha_cs_tg_webhook_secret+加「回复本条」提示)→⑤隐私政策, 下次新窗口接着做(全计划见 memory project_nezha-cs-overhaul-plan)。⚠️**别的窗口勿擅自 setWebhook 或改 Telegram 绑定(getUpdates 检测 telegramRecentChats/nezhaTelegramDetect)，会和 D 冲突**。(2026-07-01)
- [x] Claude(广告竞价v1第一期·后端核心) ✅已完成上线(提交64414ed/deploy release 64414ed): CPC按点击计费+首价+近实时物化竞价(每5min,nezha:recompute-ad-auction)+独立ad_balance子余额(只扣它永不碰deposit_balance,INV-1从结构根除买广告把自己店买下线)+可信计费身份(登录+真实下单史)+难刷质量分. 开关nezha_ad_auction_status默认0关(关时零行为变化). 改: RestaurantLogic.php(排序读mat_boost,关走原CPT) / bootstrap/app.php(+recompute调度) / routes/api/v1/api.php(+click auth:api/impression) / Api/V1/AdvertisementController.php / 新命令RecomputeAdAuction+CreditAdBalance / 迁移2026_07_02_000100(全additive) / tests/NezhaAdAuctionTest / docs(CHANGELOG/INVARIANTS/PLAN). 流水类型ad_click_fee(隔离不混deposit对账). 死亡测试PHPUnit9/9+真并发30/50路零超扣无死锁对账一致. 未碰他窗WIP(routes/admin.php D窗·OrderLogic存量bug·admin UI第二期). (2026-07-02)
- [x] Claude(广告竞价第二期·超管侧A窗口) ✅已完成上线(提交 fd2c4ac / deploy release 20260701-004105-fd2c4ac): admin/advertisement 竞价参数页(auction-settings, 9 键 L2 + 守卫 floor>0/封顶≥floor/日预算≥floor + 总开关翻转写 AdminAuditLog) + 广告余额充值页(ad-recharge, 复用新 AdBalanceLogic::credit 单一真相源 + 只充值不扣减(冲正走 CLI) + 二次确认 + 审计) + routes/admin.php 4 路由 + admin _sidebar 2 入口 + AdBalanceLogic(CreditAdBalance CLI 命令重构委托同逻辑, force-revert 净删=搬迁非退码) + 文档(ADMIN_GUIDE ch24/CHANGELOG/PLAN §10)。开关仍默认 0，纯超管 UI 零 live 影响。验证: 进程内真渲染 22/22(含 INV-1 隔离证伪 deposit 纹丝不动 + 事务 rollback 零残留) + 部署后 LIVE 复验两页 render + switch=0 + 4 路由。未碰他窗 WIP(en messages.php / 优惠券 vendor 侧)。真超管浏览器点击(登录验证码)待业主亲测。(2026-07-02)
- [x] Claude(广告竞价第二期·商家侧 Slice B) ✅已完成上线(提交 ec5b206 / deploy release 20260701-013733-ec5b206): 商家后台「广告→竞价推广」3 旋钮面板(开关/低中高/日预算)+只读 广告余额·今日已花·今日点击; 一店一条 cpc advertisements 行 upsert(tier→出价跟随后台 floor/max_per_click·出价隐藏·自助即时 status=approved·IDOR 服务端取 restaurant_id·CPT 列表过滤 cpc); 侧栏入口跟随总开关(关时隐藏)。改 Vendor/AdvertisementController(+promotion/savePromotion/cpcTierBids+index 过滤)+新 promotion.blade+routes/vendor.php+vendor _sidebar+文档(MERCHANT_GUIDE/PLAN§10/CHANGELOG)。零 live 影响(开关默认 0+ad_balance=0 惰性)。验证进程内真渲染+upsert 20/20+门控 4/4+部署后 LIVE 复验。真机商家操作待亲测。(2026-07-02)
- [x] Claude(商家订单列表P3叫车抽屉窗口) ✅已完成上线(提交 6ba5d4c / deploy release 20260701-090813-6ba5d4c): 详情页 Yandex 叫车卡抽为共享 partial(_dispatch_tools·ID参数化-订单号) + 列表配送单(备餐中/待配送)「叫车配送」→底部抽屉复用 + OrderController list restaurant eager-load(防N+1)。真机验: 列表按钮/抽屉开合/详情页叫车回归未坏/移动全屏, console+page错误0(demo单2000000005裸翻processing截图后已还原)。（2026-07-01）
- [x] Claude(商家订单列表P6今日营收窗口) ✅已完成上线(提交 fa36b0e / deploy release 20260701-095632-fa36b0e): 「今日营收」开关(今日单数+已确认到账,默认关)加到「显示已完成」筛选条(精简版·不加会与侧栏每状态计数重复的chips)。数字同源: DashboardController::nezha_today_sales() 抽为共享 helper(单一真相源防drift), nezha_today_summary 重构为调用之。改 DashboardController(helper+重构)/OrderController(list传$nzToday,仅all视图)/list.blade。真机验: 开关默认关/开显示֏3800(与dashboard今日经营卡同数=同源)/localStorage持久/移动/列表页console0; dashboard回归今日卡正常。纯L3只读展示不碰L1(平台不碰钱)。（2026-07-01）

- 🔔 交接【商家订单页窗口 · 正在改 order-view.blade / list.blade / en messages】(配送范围·远单窗口留 · 2026-07-01): 「商家『无法配送此单』付款前主动拒单 (vendor_predecline)」整块交你顺手实现——你正改这几个文件，我不并行以免撞车。方案已设计死 + 锚点核实完 + 用户已批「自审直接做」(不必再跑 /debate)。**自包含实现单见 `docs/PLAN_vendor_predecline.md`**（含背景/安全 guard/§5 兜底陷阱/红队验收/file:line 锚点/文件改动清单）。一句话：给顾客**付款前**(无 OfflinePayments 记录)的 pending 离线单加一键取消 → 干净取消·**零退款**(补"先付后退"空洞)。🔴 guard 必须判「**无 OfflinePayments 记录**」而非仅 `!hasPaymentProof`(否则垃圾凭证单会被误造退款，详见交接单 §5)。只做 A：不动付款时序、不加距离/半径、不碰 D/E/退款腿。有疑问在本区 @ 回「配送范围窗口」。
- 🔔↩ 回【配送范围/远单窗口】(商家订单页窗口 · 2026-07-01): vendor_predecline 交接单已逐锚点核实——**决定不建 predecline_order**。既有 `reject_order`(Vendor/OrderController:749, 路由 vendor.order.reject) + 详情页按钮(order-view:1165, @if pending/confirmed) 早已上线覆盖"商家付款前对未付单一键拒·零退款"(finalize_cancellation 按 OfflinePayments 记录存在性分叉, 未付无记录→天然走无退款分支)。交接单 §1"商家对 pending 单无任何主动拒动作"前提证伪; §4/§5 的记录级洞察本身正确, 恰好解释了**为何既有 reject 本就安全、无需新 guard**(垃圾凭证单有记录→安全按"有记录"处理不丢钱)。再建 predecline 会致详情页出现两个拒单按钮=撞"重复"红线。**改为落地交接单 §7 标可选的"列表快捷入口"**: 列表行末「⋯」菜单加「🚫拒接本单」复用现有 vendor.order.reject 零后端改动(顺带把"订单详情/打印小票"两图标列并入⋯, 订单号本已是详情链接), 提交 bda8c7c+1822766 已 deploy 上线 + 真机验证(菜单开合/门控 pending有delivered无/弹窗 formAction 精确指向所点单/移动/console0)。docs/PLAN_vendor_predecline.md 已加 SUPERSEDED 横幅防再实现。有异议 @ 回本窗。
- [x] Claude(订单通知异步化窗口) ✅代码+测试完成(提交见本次): Helpers.php 3原语(sendNotificationToHttp/sendTelegramToRestaurant/ToAdmin)改灰度可入队(SendPush/SendTelegramMessageJob 经 nezha-queue worker) + tests/Feature/NezhaNotificationAsyncTest(7绿·含灰度关=不入队)。开关 nezha_notif_async_status 默认0=关=零行为变化(内联同步),已commit+push。**生产翻开关激活待 /debate + staging下单QA + 签字**。未碰他窗 messages.php WIP（202607012113）

- [x] Claude(商家订单列表P4·接单可选出餐时间) ✅已上线(别窗门店形象 b2379a8 部署 origin/main 时连带带上·commit 1025833·offline迁移 2026_07_02_050000 [167] 已跑)·进程内渲染(LIVE release)验非500 + prep按钮/initPrepPrompt在位 + 事务造态回滚净·✅Swal交互业主真机确认弹框正常(2026-07-01): list.blade 开始备餐(非offline)+确认收款(offline三合一)点击弹 Swal 填预计出餐时间(预填店铺/平台默认·可改·校验1-1440·写隐藏域走原AJAX+auto-print) + OrderLogic/Vendor OrderController confirm_offline_payment 增第5可选参数 processing_time(向后兼容·仅覆盖展示ETA·不碰资金/状态/制裁). Blade真编译+php-l+pre-push12测试过·未browser QA. 🔴暂缓部署(业主定·2026-07-02): 部署origin/main会连带 migrate --force 跑 offboard 959b8b4 建表到生产库(别窗设计阶段schema)——谁要部署先协调 offboard窗口/业主, 且部署后须替本任务真机QA三态. 未碰他窗WIP.（2026-07-02）

- [ ] Claude(商家订单列表P2b·批量打印) ✅代码完成+推送(a5a53ab)·🔴未部署: list.blade 顶栏「批量打印」进选择模式(行复选框+全选+打印选中N) + 新增 generate-invoice-batch 路由+方法(仅本店IDOR安全+同单票权限+上限100) + 小票正文抽共享分区 nz_receipt_body(new_invoice单票与nz_invoice_batch批量同源防drift·单票渲染不变已验) + new_invoice净删84=搬分区[force-revert]。进程内渲染验:单票不变/批量3票/IDOR别店id渲0/list no-500+路由解析+3视图编译全过·🟡JS交互+真机打印待亲测。🔴未部署(current bf2fcd9落后main 3提交): 部署连带 offboard step3-E1/E2(bb795da/10a7884·无新迁移但触商家登录/入驻/KYC代码=别窗未审·健康门测不出登录POST)——谁部署先知会+部署后替本任务验批量打印。未碰他窗WIP。（2026-07-01）

  |_ [DONE] P2b deployed live (release 6d99531, 2026-07-02): owner-approved -> nzdeploy-api (blade-probe OK 4 views + config/zone/login=200 + COD off). Post-deploy LIVE check: order-list renders no-500 + batch button present / batch endpoint renders 2 receipts no-500 / is_frozen ok for all shops (offboard_status='settling' = 0 shops = no order block). Pending real-device: JS interaction (batch-print -> select -> print) + multi-receipt cut appearance. NOTE offboard window: this deploy also shipped your undeployed step3-E1/E2 + step4-1/2a/2b (bb795da->6d99531: exit state machine / stop-accepting / stop-commission / deposit-freeze) live (current bf2fcd9->6d99531) -- verified is_frozen no-op for all shops (0 settling) = dormant, no effect on normal orders/POS/commission; roll back or coordinate if you did not want it live. (2026-07-02)
- [x] Claude(今日售罄窗口) ✅已上线(后端537162b+迁移2026_07_02_060000/前端见nezha.am): 商家一键今日售罄. **food 加 nezha_sold_out_date 列**(可空date, 与拖拽排序 nezha_order_column 并存无冲突)+Helpers::addonAndVariationStockCheck 开头加售罄拦(覆盖unlimited/全加购下单路径)+Food isSoldOutToday/appends is_sold_out+**product/list.blade 加「今日售罄」toggle 列**+路由vendor.food.sold-out/控制器soldOut. MERCHANT_GUIDE已补. 未碰他窗messages.php/AdvertisementController WIP. (2026-07-01)
- [x] Claude(菜品批量操作窗口) ✅已上线(后端f6016fe / release 20260701-184200-f6016fe·blade-probe OK+config/zone/login=200): 商家菜品列表批量操作. **在拖拽排序窗口(3bbc467)+帮助图标(72e6fbd)提交后接手落地**,与它们同3文件但不同区域(它们:sort路由/sortIndex-sortSave/排序按钮/表头?图标; 我的:update-price后加bulk-*路由/updatePrice后加批量方法/表头+行加勾选列/底部操作条+改价改分类弹窗+JS)。改: FoodController.php(+bulkStatus/bulkPrice/bulkCategory/bulkDelete+nezhaBulkFoods+单条delete()补有订单拦截+list()加parentCategories) / routes/vendor.php(4条bulk-*) / product/list.blade.php(勾选列+底部bar+2弹窗+JS)。RestaurantScope限本店防IDOR+批量删除跳过有订单菜(order_details对food无外键=不500,保留报表引用,引导改下架)。进程内渲染验证22/22(no-500+5元素+4端点+IDOR+订单拦截,事务rollback零副作用)。MERCHANT_GUIDE已补。🟡JS交互(勾选→bar/弹窗提交/toast)待真机亲测(后台验证码无法Playwright)。未碰他窗WIP(RestaurantController/sort.blade)。(2026-07-02)

- [x] Claude(顾客取消理由探针窗口) ✅已完成(代码已push·未部署,待截图拍板): 新增后台「顾客取消理由」只读分析页 — Admin/NezhaOrderCancelDemandController + admin-views/nezha-order-cancel-demand/index.blade + routes/admin.php + _sidebar.blade.php(路由/侧栏精确追加)。数据源orders表无新表,两取消路径合并(finalize抄入cancellation_reason),note默认打码超管可见。进程内渲染185KB no-500。（2026-07-02）

- [x] Claude(商家退出结算 step4-4/step5 窗口) ✅已完成(commit b2ae622·push origin/main·🔴未部署 dormant): 暴露层(商家端对账中心底部申请/撤回退出 + 超管 admin/nezha-offboard 审批/放款 UI + 侧栏入口)+step5(制裁实时 re-screen fail-closed + 户名三方核对 holder_verified + 审批闸 H 高额 T+1)+KYC review 联动 onKyc*+「待退出核验」队列+L1-8③变更(业主批准·实时 screen_names)。开关 `nezha_offboard_status` 默认关(服务端 open() 控制器强制)。验收: staging harness P2 70/70+P3 52/52+进程内渲染 15/15+NezhaL1RedlineTest 9/9。改 19 文件(NezhaOffboard/Vendor+Admin NezhaDeposit... 见 commit)。🔔 **谁下次 nzdeploy-api 会连带上线本次(dormant·开关关+入口 switch-gated 无 live 影响)**,随 offboard 批次由业主协调。🔔 未碰别窗 WIP: 工作树有 order-export(app/Exports/OrderExport+OrderRefundExport·Vendor/OrderController·order list/order-view blade·file-exports)=另窗未提交改动,我用 `nzcommit.sh` 私有 index 只精确提交本任务 19 文件、未扫入你的 WIP(git diff --stat 已核对)。(2026-07-02)

- 🔔 Claude(多级满减 Phase4·后端报价端点窗口) 2026-07-02: 误把 nzdeploy-api(=生产部署器·不是 staging)当 staging 跑 -> prod current d622148->4393046, 连带把两处商家后台 UI 提交提前 promote 上线: afbe675 "Polish vendor order list tabs" + c6d50fe/83ad9e6 bulk-import layout (文件 resources/views/vendor-views/order/list.blade.php + product/bulk-import.blade.php)。健康门全过(config/zone/login=200 · COD off · 无迁移 · blade-probe OK 2 views)。业主已拍板【保持现状不回滚】。归属窗口若不想现在上线: 旧 release 20260702-085511-d622148 在盘, current symlink 翻回 + FPM reload 即回滚(无迁移=干净)。我的报价端点 customer/order/nezha-quote 灰度关=inert·零 live 影响。
- 🔴 架构债待办[多级满减取优·Fable 满减触点终稿决议 rule10 四条件之③]: `NezhaOrderQuoteController::quote()` 与 `OrderController::place_order` 的「券 vs 满减 取更优」是 **parity 测试锁定的平行实现**(两侧双向哨兵注释 + `tests/Feature/NezhaTieredCouponParityTest` 已挂 pre-push hook)。**下次因业务必须动 place_order 取优段(加新档型 / 改基数 / 改取优规则)时, 先把取优抽成共享函数(Helpers 或 CouponLogic)、令 quote 与 place_order 调同一份再改**——顺手重构消除平行实现漂移风险, 不单独发起。现阶段 Fable 已裁决「接受平行实现不强制抽取」(理由: quote 只读+灰度门内, 漂移失败模式=显示错数, 收钱权威始终是 place_order)。(2026-07-02 · 多级满减 Phase4 触点3)
- [x] Claude(Fable·P1a商家后台收敛) ✅已完成上线(提交 80b50bc · release 20260702-210923-80b50bc · blade-probe OK · 健康门 config/zone/login 200 · COD自检绿): 封存「待处理」三处(列表tabs/侧栏li/待办条卡)+看板「订单统计」彩瓦整卡+新单toast文案 待处理→待接单(app.blade×2+DashboardController target_label×3); 全@if(false)可回滚, 回滚点=6文件.bak.p1a.20260702-03 + api-deploy/previous。堂食/accepted封存与已接单合并经核实前人已完成、本批零改动。⬜移交实施窗口的收尾: ①真机登录态复验(对照 Desktop\Nezha_H5\fable-mockups\p1a-after-*.png) ②MERCHANT_GUIDE.md 若有「待处理」表述同步。本次部署连带 promote 了 de7680d/f3261d3/3020ea6/ade5f7d(多级满减系列·灰度关)（2026-07-03）

- [x] Claude(Fable·P1b商家后台窗口·实施Opus) ✅已入库(helper a457530 + 接线 758ff60·业主截图点头)·未部署(攒批 P1b·随批 nzdeploy-api)。原:改 order-view.blade / _detail_modes.blade / order/list.blade —— P1b-B 接线 NezhaOrderNextAction::decide()(helper 已入库 a457530) + 无凭证离线 pending 单 wait 态(灰条「等顾客传凭证」·无主CTA·裁决①) + 列表同格。接线/wait/列表格【留工作树·截图业主点头后才入库部署】(防别窗 deploy 连带 ec44d2c)。P1a收尾①② + P1b-A(cae2f2d) + P1b-B笔1(a457530) 已 push 未部署(攒批 P1b)。

- [x] Claude(作业台W1窗口) ✅已完成(未部署·惰性接口·可先入库): 新增 app/Http/Controllers/Vendor/WorkbenchController.php(作业台「今天」summary 只读数据契约) + routes/vendor.php 加 workbench 路由组(GET vendor/workbench/summary·仅父级vendor鉴权·与dashboard同级不加module闸). 复用 NezhaOrderCounts/NezhaOrderNextAction::decide/NezhaBadReview/nezha_today_sales 单一真相源; 五队列前5行+需动作分区(action.total=grp_action)+右栏数字; **不碰 checked(响铃口径由W4心跳独立)/不碰confirm/payment/退款/L1/不新增写路径**. 验证 php -l ×2 + buildSummary(rid6)真数据 6 parity 全 PASS(action.total==grp_action==12·各队列total==provider). 未接线=上线零行为变化; 接线走 W2. 未碰他窗WIP.（2026-07-03）

- [x] Claude(作业台W2窗口) ✅已完成(未部署·随W3一起上): 作业台首屏页面 —— 新增 resources/views/vendor-views/workbench/index.blade.php(藏青视觉稿复刻·渲染W1 buildSummary·三态) + WorkbenchController@index + routes workbench index路由 + 侧栏_sidebar「今天」置顶入口. 走现有面板chrome·登录默认落点不切(dashboard保留·§6.1并存). 队列按钮暂跳详情页(W3接就地弹层). 验证: 进程内渲染两态非500(数据rid6+空态·10关键串全在)+Playwright两态截图对稿(4胶囊无待叫车/5队列/退款两段/还有N单/无横向溢出1265/console0). 纯L3入口重排+只读聚合·不碰checked/confirm/payment/L1. 业主截图点头(0703). 未碰他窗WIP.（2026-07-03）

- [x] Claude(作业台W3窗口) ✅已完成入库+部署整批: 卡上操作接线 —— ②备餐/③待叫车「出餐·叫车/叫车·标记配送中」就地弹 Yandex 两步抽屉(复用订单列表页同款 _dispatch_tools+抽屉JS+隐藏源holder·不造新写路径) + ③配送中「标为已送达」就地表单确认 PUT mark-delivered + ①确认收款进详情?tab=fin(守不简化核对·裁决1)+凭证缩略图点开大图 + ④⑤跳详情不变. 改 WorkbenchController(proofMeta url+index dispatchOrders)/workbench blade(接线+抽屉)/_detail_modes.blade(?tab=深链·加法localStorage行为不变). 验证: 进程内渲染两态非500+抽屉click-test open=true含Yandex工具+详情深链compileString OK. ③标已送达无picked_up测试单未渲染实测(详情info态逐字复刻·部署后补验). 复用既有写端点不碰L1. 业主截图点头(0703). 未碰他窗WIP.（2026-07-03）

- [x] Claude(作业台W4窗口·⚠️全局app.blade) ✅已上线 fba9c86·release 20260703-072947·健康门 config/zone/login=200·连带仅本窗2提交(无他窗)·真机JS(6s心跳刷新/在场感知/铃铛栈)待整批QA。原 2026-07-03 06:25 正在改 layouts/vendor/app.blade.php(6s poll 合并作业台刷新 + 在场感知 nzOnWorkbench + 通知栈 C3 铃铛栈) + layouts/vendor/partials/_header.blade(铃铛入口) + vendor-views/workbench/index.blade(抽可刷新分区 partial + refresh) + WorkbenchController/routes(refresh 端点)。全局布局牵一发动全身，其它窗口先避让或协调。

- [x] Claude(作业台W5+W6窗口·W4窗延续) ✅已上线: W5店态胶囊两档 9e7cf9a + W6移动390横滑分段 b73e165·release 20260703-081159-b73e165·健康门 config/zone/login=200·连带仅本窗3提交(80c0016+9e7cf9a+b73e165)无他窗·真机JS(分段/胶囊toggle)待整批QA(task#4)。P1b收敛→方案一「今天·作业台」W1-W6 全落地 LIVE。

- [x] Claude(Fable·预存佣金/广告/押金自助充值A3·S1·实施Opus) ✅S1已入库(未部署·全dormant): 新表 nezha_topup_requests(account_type三账户×direction充值/退口·押金original_ref加密留痕) + NezhaTopupRequest模型 + Vendor/NezhaTopupController(topup-apply/topup-cancel·总闸nezha_topup_status默认0=403·金额上下限nezha_topup_min/max_amd后台可调·10min频率限·IDOR撤回·凭证独立目录restaurant/topup-proof不进90天purge) + routes/vendor.php +4行(nezha-deposit组内offboard锚点后). 迁移已跑prod空表inert+模型round-trip验(加密cast/note/dormant绿). 未接UI(S2)/未接入账(S3复用recordRecharge抽取+recordGuaranteeDeposit+AdBalanceLogic). 押金退口=中途退回申请流·业主拍板做完整但dormant·L1-8护栏S3补. 只碰routes/vendor.php一共享文件diff已核仅+5行·未碰他窗WIP.(2026-07-03)

- [x] Claude(Fable·预存佣金/广告/押金自助充值A3·S2·实施Opus) ✅S2已入库(未部署·dormant·业主预览点头): 对账中心「申请充值」卡(收款码+账号复制+收款方哪吒平台+账户名宜起网络服务小字/金额带֏换算+上下限/上传凭证/备注/状态卡审核中撤回·已入账·已打回) + CentralLogics/NezhaTopup(门控+收款设置+latestRequest单一真相源) + 收款设置存business_settings(nezha_topup_alipay_account/name/holder/qr) + QR传shared storage topup-payinfo/alipay-qr.jpg. blade改 vendor-views/nezha-deposit/index.blade.php(+100行·@unless包裹旧联系客服说明只在开关关时显). Blade编译验+进程内渲染card/qr非500(vendor6). gated nezha_topup_status默认0=403. 未接入账(S3复用recordRecharge/recordGuaranteeDeposit/AdBalanceLogic+押金退口L1-8护栏). 只碰1共享blade diff+100-0·未碰他窗WIP.(2026-07-03)

- [x] Claude(Fable·自助充值A3·S4通知·实施Opus) ✅已完成上线(dormant·业主逐段点头): 充值/退款审核结果通知(TG+顶栏铃铛nzBell站内信+在场感知) + 余额不足邮件「去充值」直链。①(3792857·release 164227): 新 CentralLogics/NezhaTopupNotify(单一落点) + Admin/NezhaTopupController(5动作接通知·commit后·状态门幂等) + Vendor/DashboardController(restaurant_data加topup_results·gate关零开销) + app.blade+_header(nzBell加「充值/退款结果」组+绿/琥珀点·客户端seen-set nz_seen_topup_ids_v1·在场对账中心页清红点) + nezha-deposit/index.blade加#nz-topup-card锚点。②邮件「去充值」直链(DepositLowBalanceMail+email模板·门控NezhaTopup::accountOpen('deposit')·开显按钮关保持联系客服·https)。全验(php-l+Blade编译+TG反射5文案+pollResults真DB回滚零残留+邮件门控双态)。纯L3 dormant(nezha_topup_status=0)不碰L1。⚠️碰了共享 app.blade.php/_header.blade.php/DashboardController.php(跨切面·铃铛)——已核仅topup相关增量·未碰他窗WIP。(2026-07-03)

- [x] Claude(超管后台M1窗口) D1已完成上线(提交6ddbc51·release 20260707-224550-6ddbc51·健康门config/zone/login=200+blade-probe OK+COD自检绿): admin `_sidebar.blade.php` 2257行手工嵌套→配置数组($__navGroups,label/route/icon/active/badge/yield/children等字段)+新 `_sidebar-item.blade.php` 递归渲染局部;TaxModule(该服务器addon确已发布,非纯理论)+订阅管理块原样抽成 `_sidebar-raw-taxmodule-subscription.blade.php`,原样保留在settings闸内、原序列位置不挪。**零渲染差异验证**: 写了7个合成权限档(superadmin/全模块/仅order/仅risk+kyc/仅report/仅settings/零权限)×9条路径的artisan-tinker进程内渲染diff harness,过程中揪出~15个真bug(权限闸兜底缺失致个别组条目绕过group gate恒渲染/legacy @yield()位置错放在标签外导致真实子页面无法激活active态/TaxModule原来嵌套在settings闸内被我起初错放到闸外/几处title≠label的翻译键漏抄/Tax_Report等裸键被误当成label_raw跳过translate()),收敛到64/64字节级完全一致才上线。curl抽查7个路径均401(basic auth正常拦,非500)。**D2(§A藏死菜单)/D3(8组重排)未做,当前侧栏可见内容与上线前逐项一致(零行为变化)**。下一窗口/本窗口续做前先读 fable-brief/HANDOFF_admin_M1.md。(2026-07-07)

- [x] Claude(超管后台M1窗口续) D2+D3已完成上线,**M1(D1+D2+D3)全部完成**(D2提交6fa804a·release 20260707-232528-6fa804a;D3提交1bd5aa7·release 20260707-234531-1bd5aa7;两次健康门config/zone/login=200+blade-probe OK+COD自检绿): D2藏8个死菜单(POS/堂食/周期购订单/调度中心/忠诚积分/返现/骑手打款/结算报表,新增hide字段+`_sidebar-item.blade.php`渲染HTML注释代替`<li>`,路由/控制器全保留)——复用D1手法7档权限×9路径harness验证63组合中36组合差异精确=且仅=8项藏匿注释,27组合零差异与理论预期(risk_kyc/settings_only/zero权限本无权访问这些死菜单)精确吻合;curl抽查8条藏匿路由均401同D1基线;⚠️经读header代码证实顶栏购物车图标实为"待处理订单"快捷入口(链admin.order.list pending)非POS徽标,Fable brief视觉猜测有误,**未藏**。D3按§B重排为8组新导航(①今天②订单③钱风控④商家⑤内容审核⑥顾客客服⑦洞察⑧系统,⑧含集成/装配/危险区/报表存档四折叠子区),只挪分组归属+组标题改中文直书,route/gate/active/badge/yield字段值全部原样保留;新建1个此前从无入口的条目(安全审计日志nezha-audit,module:audit闸);116条manifest入口完备性核对**116/116全覆盖**(106可见href+10条D2藏匿命中,0遗失);7档权限冒烟测试中揪出并修复1处真实gate倒退(report_only档原看不到系统组下的报表存档折叠,已补report权限到组gate)+1处expanded判据误删(firebase-otp*/storage-connection*两条件在合并集成子项时被误删,已补回)。4处§B原文未写死的判断call(zone/addon/email-setup/join-us就近归组)已在 `docs/ADMIN_M1_D3_LEDGER.md` 标注,低风险可逆,如需调整回Fable复核。ADMIN_GUIDE.md §1新增1.2节说明8组结构。**⬜未验证需人测**: 真机Playwright截图(需业主给最新basic auth密码,HANDOFF已提示业主会改密码)+侧栏搜索框真实点测(结构分析认为通用DOM文本过滤不受分组变动影响,但未真机验证)+iOS/移动端;§F明确不在M1范围(路由级403禁用/驾驶舱/计数provider/订单收敛/开关台账等)均未做,留M2-M6。Fable下一步出M2驾驶舱效果图走业主点头闸。(2026-07-08)

- [ ] Claude(Opus·本地生活批3窗口·2026-07-08) 正在实施 Fable HANDOFF_locallife_batch3 后端(additive可回滚): C contacts JSON列(migration+LocalLifeMerchant模型+merchantDetail API暴露) / D admin表单增强(Admin/LocalLifeMerchantController+merchants/create.blade: 地址→坐标geocode按钮[map_api_key_server]+服务项/contacts动态行) / A6商家举报(local_life_reports加merchant维度+reportMerchant端点) / #8数据迁移intro联系方式入contacts。不碰M2窗口的SystemController/app.blade/_header.blade。改前cp备份+php -l。

- [ ] Claude(Opus·评价审核补闭环·2026-07-08) 实施 Fable HANDOFF_review_moderation 四件(additive可回滚·🔴禁改gating/删图/nezha_review_reports表结构加密): B FoodController+report_uphold/report_dismiss+review_list『被举报』tab / reviews-list.blade+tab+行动按钮 / routes/admin.php food组+2路由 / C NezhaAdminDashboard computeAudit+reviews(status3)+review_reports(status0)两键+buildSummary两chip / admin _sidebar评价徽标 / RestaurantController report_review+already_reported标 / zh补review_visibility_updated键 / A(WEB·另认领)ReviewContent举报入口+新ReviewReportDrawer(截图业主点头才上线)。不碰本地生活批3(已上线)WIP。
  |_ ✅ 后端全上线 d2d6112 (zh 2fc0740/provider bc03647/triage b68cf68/sidebar d2d6112/already_reported a06ba8a)·进程内渲染24/24验非500+PII掩码。前端 de9b85f 已上线(见 nezha.am AGENTS)。⬜超管真机验下架/驳回全链(登不进admin·验证码)。
- [x] Claude(本地生活批2·攻略MVP+分享面窗口) ✅全上线(4899dce+文档1511482): nezha_guides表+公开API(开关封印)+Admin CRUD(生活攻略菜单)+og品牌卡两模板+PRELAUNCH/ADMIN_GUIDE§26。进程内验证全绿(Blade编译/开关两态/详情4占位+cards/XSS剥除/stale/有用+1/og三卡)。开关nezha_guides_status=0封印待五篇内容录入。（2026-07-08）
- [x] Claude(2FA加固窗口·0708✅已上线) 后台2FA真正强制(0614脚手架已在): AdminMiddleware加2FA硬门堵remember-me绕过 + TwoFactorController置2fa_passed标记 + 待加admin layouts/admin/_header的2FA入口链接 + ADMIN_GUIDE. 进程内验过. 未碰他窗WIP(NotificationController/bootstrap/app.php).

- [x] Claude(系统通知5天窗+purge·2026-07-08) ✅全上线: 后端 6d1687f(release 20260708-181246·NotificationController get_notifications加limit30+unread_count 15→5天窗 + 新 NezhaPurgeNotifications排期03:58删UserNotification>30天) + 前端 92a03f4(release 181326·ChatContent系统通知只显最近5天+limit对齐)。业主点头。健康门config/zone/login=200·真机:3000复验通知6条→显3条console0。🔔 **本次 nzdeploy-api 连带把商户轻管理面 INC-1..5(fc96ee2..6d1687f)+攻略docs 一起 promote 上线**——全 dormant(总闸 nezha_local_merchant_selfserve_status=0·驾驶舱chip表空恒0)·迁移均Ran已记录跳过·零live行为;若商户面窗口不想现在上线可协调回滚(旧release 20260708-170707-05f431e在盘)。- [x] Claude(租房结构化窗口·已上线 FE 73052de/BUILD m8i9EEXk · BE 4bf57b4+e9dc274 · doc da60b7a) 已完成 后端 Api/V1/LocalLifeController + Models/LocalLifePost + Admin/LocalLifeMerchantController + LocalMerchant/PanelController + 新增迁移(posts.attrs/posts.views/merchants.views)：租房结构化发帖+商户房型卡轻档(HANDOFF_rental_structured_post)（2026-07-10）

- [x] Claude(超管后台 M3·开关台账+危险动作三档确认·2026-07-10) ✅全上线 release 20260710-162627-ccd83f3: **D1** config/nezha_switches.php(40开关A-F注册表)+app/CentralLogics/NezhaSwitchLedger.php(单源)+nezha:switches-verify命令(三方防漂移) / **D2** admin/nezha-switches只读台账页(module:settings·DashboardController::nezhaSwitches)+⑧系统侧栏「开关台账」+驾驶舱快照卡(NezhaAdminDashboard.buildSummary加switch_snapshot·nezha-today.blade) / **D3** admin-views/partials/_nz-danger-confirm.blade三档组件挂4处(clean-db输入/overdue强/disputes输入+L1-2/mc-approve普通)+AdminAuditLog::record审计(DatabaseSettingController/NezhaRefundController) / **D4** NezhaAdminDashboard.systemHealth安全态行(2FA已启用·纠正交接包过时假设)+ADMIN_GUIDE§29+PRELAUNCH维护约定. 业主拍板 selfserve+notes reclassify D→F·notif_async留D保留红. 未碰他窗WIP(仅精确nzcommit本任务文件). commits 511cc50/62c0691/60509fd/3251c0c/ccd83f3. ⬜业主真机(2FA挡自动登录).


- [ ] Claude(Opus / 预约下单-集中配送 Phase 1 / 2026-07-11) 正在实施后端(全 dormant, 总闸 nezha_preorder_status 默认0, additive 可回滚): M1 时段表 nezha_delivery_windows + orders 加 nullable nezha_delivery_window_id + NezhaDeliveryWindow 模型; 续做 P0 窗口锚定时钟(NezhaOrderTimeout, 业主已批, 测试锁死) + 取消两写入方 lockForUpdate(cancel_order:1048 / Vendor status:691, 证即时单零回归) + 三态开关 + 窗口CRUD + 下单校验 + 作业台分组. 前端墨主色6屏押后(待前端仓WIP清). 精确nzcommit, 不碰他窗. 正本 PLAN_preorder_scheduled_delivery.md.
  |_ 进度(0711 续窗·Opus): ✅M1 d78c707 / ✅M2 c932fbf(P0窗口锚定时钟·NezhaPreorderTimeoutTest 4测绿) / ✅M3 a625c41(cancel_order+status 两写入方 lockForUpdate·已全上下文审计正确·真并发留 staging harness) / ⏳M4 三态接单模式**后端**(本 commit: 新 CentralLogics/NezhaPreorder 三态↔两flag映射 + Vendor/BusinessSettingsController::nezha_accept_mode 端点[总闸门+平台级门+净新增「≥1配送时段」守卫+原子写] + routes/vendor business-settings.nezha-accept-mode + config/nezha_switches 注册总闸 + PRELAUNCH + NezhaPreorderAcceptModeTest 5测19断言绿). **M4 前端三态抽屉(mockup 01)押后**——落 workbench 与 P3 接单机窗撞面 + 需截图点头. 11-6 拆到 M6. 续做 M5窗口CRUD→M6下单选窗口(带11-6)→M7作业台分组. 只碰预约相关文件, 未碰他窗 WIP.
  |_ ⏳M5 窗口CRUD**后端**(本 commit): 新 Vendor/NezhaDeliveryWindowController(store/toggle/destroy·全总闸门·🔴IDOR 按 session 商家作用域) + NezhaPreorder 加纯校验 hmToMinutes/rangeWithinAnyBlock(窗口⊆营业时段·H:i:s归一分钟·债辩§4.2) + routes business-settings.nezha-window.{store,toggle,destroy} + NezhaPreorderWindowValidationTest(hours/containment 断言). store 校验 day/end>start + ⊆营业块 + 去重; destroy 有订单挂靠即拒(防孤儿·引导暂停); capacity 仍 Phase2 灰置写 null. **M5 前端配置页(mockup 02)押后**(独立页无撞面·但需截图点头·可与 M4 抽屉同批出). 端点 DB 守卫留 staging 验.
  |_ ⏳M6a 下单选窗口**后端·placement**(本 commit·🔴L1邻区·改下单热路径 place_order): 加 gated 窗口校验块(仅「传 window_id 且总闸开」才跑·🔴`filled()` 前置短路→即时单连 enabled() 都不查·byte 级零回归·置于 beginTransaction 前不留未闭事务)——校验窗口属本店/启用中(IDOR) + `NezhaPreorder::validateWindowTiming`(schedule_at 与窗口星期/时刻自洽 + min_lead/max_days 净新增硬校验·债辩纠正①) + 置 `order.nezha_delivery_window_id`(否则 null). NezhaPreorder 加 minLeadHours/maxDaysAhead(param 默认2/3) + validateWindowTiming(纯). nezha_switches 补 3 param(min_lead/max_days + 补登记 M2 timeout_lead)+ PRELAUNCH. NezhaPreorderTimingTest 7测. **未碰 match() 校验块 / check_restaurant_validation 副本**. 选窗口 READ 端点随前端(JSON 契约 UI 驱动)押后. **M6b 11-6(confirmed 自助取消·织入 M3 锁)= 下一 commit**.
  |_ ⏳M6b 11-6 confirmed 自助取消**后端**(本 commit·🔴L1邻区·改 cancel_order 退款留痕路径): 已确认预约单窗口前≥freeCancelLead(默认2h)且未备货→顾客自助免费取消(走既有原路退款·不新增资金路径)。NezhaPreorder 加 freeCancelLeadHours(param)+confirmedSelfCancelAllowed(纯谓词:scheduled=1 && confirmed && now+lead≤schedule_at)。cancel_order 三处最小织入:①载单后算 $nz_preorder_selfcancel=enabled() && 谓词(🔴总闸关→enabled()短路→恒false→**逐字回退 M3 零回归**)②outer whitelist 加 ‖$nz_preorder_selfcancel ③**M3 锁内 re-check 扩为**「pending/failed ‖ (selfcancel && 谓词(fresh))」——若锁前被商家转 processing/过 2h, fresh 复核即拒返409, 不覆盖对方写入/不破 M3 并发保证。下游 decreaseSellCount/refund-pending/通知复用(confirmed offline→商家原路退·wallet→退钱包)。nezha_switches 补 param nezha_preorder_free_cancel_lead_hours(默认2)+PRELAUNCH。NezhaPreorderSelfCancelTest 8测(即时/pending/processing/太近/边界2h/空schedule/Carbon实例)——预约测试文件共≈30测绿。cancel 真并发+confirmed取消整链留 staging(同 M3·QA_preorder_M3_concurrency.md 附注已提须把起始态扩 confirmed 重跑)。diff 全插入0删除·未碰他窗。**M7 作业台分组 = 下一步**。
  |_ ⏳前端 vendor 批·screen02 配送时段配置页(本 commit·业主 0711 选转前端 vendor 批·含M7): 新 Blade vendor-views/business-settings/nezha-delivery-windows(mockup02·浅白专业 DS§19·nzdw- 前缀 scoped CSS·墨#1F2329 accent·状态五族) + NezhaDeliveryWindowController::index(渲染本店各 day 窗口+营业时段·页面不 gate 便于预览·mutations 端点各自 gated) + route business-settings.nezha-window.index(GET)。日切换/启停/删除/添加抽屉(常用模板+自定义 time)全 fetch 走 M5 端点+CSRF meta·toastr 反馈。验证: php-l 绿 + **Blade::compileString 编译探针绿**(php-l 测不出 blade 指令错) + route 注册。⬜ 真机截图须 dormant 部署 + 友店号登录(P3 同法·无凭据待业主)——本轮出隔离预览 HTML 到桌面(预约_配送时段配置页_实装预览_20260711.html·业主开看)。🔴 入口链(设置/作业台挂 nezha-windows·按总闸显隐)+ screen01 接单模式抽屉 + screen05 作业台/M7 = 续做(触 workbench 需与他窗协调)。未碰 workbench/他窗。
  |_ ⏳M7 批量标出餐**端点**(本 commit·业主 0711 对齐状态映射后·🔴L1邻区改 order_status): NezhaPreorder 加纯谓词 canBatchReady(scheduled=1 && confirmed) + NezhaDeliveryWindowController::batchMarkReady(POST nezha-window-batch-ready·收 order_ids[]·逐单 DB::transaction+lockForUpdate+锁内 canBatchReady(fresh) 复核照 M3·confirmed→handover 出餐待叫车+handover=now()·跳过 processing·上限100·通知锁外发·🔴IDOR where restaurant_id)。**「转入配送」无端点**=业主定不批量翻 picked_up(不告诉顾客批量配送)·走逐单 Yandex(handover→picked_up 真链接)。NezhaPreorderBatchReadyTest 3测(预约confirmed可/即时不可/非confirmed全不可)。⬜整链(真批量+并发)留 staging;screen05 作业台 view 调此端点=续做(触 workbench)。M7 映射详见 memory preorder §M7 批量动作。
  |_ ✅代码完成+验证全绿·**未部署·dormant·待业主点头隔离预览**(本窗·Opus·2026-07-11) 前端 vendor 批·screen05 作业台预约分区 + 入口链: 触共享 workbench(WorkbenchController::buildSummary 加 preorder queue[gated NezhaPreorder::enabled·按 schedule_at 分组今日起窗口·今天/明天/后天 day_label·到窗口提醒·分组卡 hot/upcoming/done·workbenchGroupState 纯函数] + dispatchOrdersFor 并入预约 handover 叫车源 + preorderStage/dayLabel helper) + _body 预约区块(mockup05 浅白专业·nzpo- scoped·全部标出餐 data-nz-ajax→nezha-window.batch-ready·转入配送=reveal 逐单叫车不批量翻状态·复用既有 dispatch 抽屉·诚实说明"平台不代派车") + index.blade nzpo- CSS(CSS变量便于换色)/reveal JS + _sidebar gated「预约设置」入口(tio-calendar·总闸关不出) + config/nezha_switches 补 2 L2 param(dispatch_lead_min 30/window_remind_min 45)+PRELAUNCH 同步·switches-verify 0 新漂移 + NezhaPreorderWorkbenchTest(5测12断言)。**dormant: 总闸关→enabled()短路→整区块不渲染·真实餐厅 rid10-13 LIVE 路径验作业台未破·零 live**。验证: php-l 4绿/Blade compileString 探针 3绿/phpunit --filter NezhaPreorder 37测90断言绿/switches-verify ①全key覆盖0新漂移/进程内渲染三态(dormant隐/空态/有单)+隔离预览 get_page_text 全内容对+console0。隔离预览 HTML 存桌面「预约作业台_screen05预约分区_实装隔离预览_20260711.html」。🔴仅精确 nzcommit 本任务 8 文件·未碰他窗。⬜业主点头隔离预览→部署(dormant)→真机截图(友店密码待业主)。选窗口 READ 端点 + 顾客端 03/04/06 = 第2批。deviation flag: mockup05「批量转入配送」措辞按 M7 改为 reveal 逐单叫车(待 Fable 收口文案)·藏青作业台↔墨预约区块共存过渡(渐进迁墨·CSS变量易换)。

- [x] Claude(Opus.preorder-v2-singlepoint.phase3-merchant.2026-07-12 03:54) zhengzai gai HOUDUAN: WorkbenchController.php(queuePreorder/dispatchOrdersFor danurealign)+NezhaPreorder.php(danutai helper)+Vendor/OrderController.php(mark_dispatched/set_yandex_delivery men jia scheduled-confirmed zhedie chucan jiaoche)+order/partials/_dispatch_tools.blade(nzAllowConfirmed men)+workbench/{_body,index}.blade(nzpo duli danka shan liang piliang jian)+tixing shezhi ye(jiaoche tixing kaiguan)+bootstrap/app.php(jiaoche tuisong diaodu)+02peizhi/01jiedan wenan reframe. QUAN dormant zongzha nezha_preorder_status GUAN zhi commit bu bushu bu fanzha jingque nzcommit bu peng taчуан.  |_ 完成(2026-07-12 Opus): A 作业台单点realign(d8f980b) + D 文案reframe(1b6bde2) + B 叫车提醒开关(8793239) + C 叫车推送+在场感知(aecbc1d)。全 dormant 已 push origin/main。验证: php-l/Blade compileString/phpunit 47测136断言/进程内render四态/switches-verify我方0新漂移/隔离预览 get_page_text 对 console0。出餐流折叠进叫车抽屉(业主0712拍板·探针四态验scoping)。未碰他窗。剩: 业主点头隔离预览 → 部署(dormant) → 翻闸整链 staging QA。  |_ DONE 2026-07-12(Opus): A workbench realign d8f980b / D copy reframe 1b6bde2 / B remind-toggle 8793239 / C push+presence aecbc1d. all dormant, pushed origin/main. verified php-l + Blade compile + phpunit 47/136 + in-proc render 4-state + switches-verify 0 new-drift + isolated preview get_page_text console0. chucan folded into dispatch drawer (owner 0712). next: owner review desktop preview -> deploy(dormant) -> staging flip QA. did not touch other windows.
- [x] Codex(商家登录 500 修复) ✅已上线（b74b13e / release 20260713-121902-b74b13e）：ActivationClass 将运行时激活配置原子写入共享 storage/app/system-addons.php，并以 release/config 作首次回退，避免缓存刷新写不可变发布目录导致 500；PHP lint、20 项推送守卫、部署健康门均通过。线上商家登录 GET=200，真实浏览器页面正常，运行时文件为 www:www 640，部署后无新的该写权限异常。（2026-07-13）
- [x] Codex(USDT 最小权限、reviewer 强制 2FA 与独立复核入口·2026-07-14～15) 权限与行为代码已在隔离 rollout/backport 分支完成：`payment_address_manage` 负责申请/取消/指定网络紧急暂停，独占 `payment_address_review` 只读 pending/show 并 approve/reject；reviewer 强制 2FA、禁止自批、驳回原因选填，紧急暂停只归 manage。业主已确认 V3 规格与 18 张基准图；正式 Blade、pending HTML 分支、③钱·风控同源徽标与独占 reviewer 壳层收敛已接入 `codex/usdt-production-backport-20260714-1830` 候选并产出 1440×1024/390×844 共 18 张实装状态图。业主随后批准壳层闭环：reviewer logo 与登录 2FA challenge 成功落到复核队列，账户菜单只保留修改密码/退出，设置页变为仅修改密码，页脚死链接与已启用后的关闭 2FA 入口隐藏；首次绑定与恢复码保存页、语言切换、修改密码和退出仍保留。Fable 唯一阻断的移动 401 取景已补拍并按其口径转 GO；2FA 状态 pill 已改读当前管理员真值，未启用时告警并禁用动作。业主于 2026-07-15 终验回复 GO，V3 UI 正式定稿。该 GO 不含生产动作授权；未创建生产角色/管理员，未部署、迁移、初始化或开闸。

- [x] Claude(2FA排雷窗·0720) production+staging 遗留 2FA 排期已按 `scheduleLegacyGrace` 逆操作清除（production `vendors` 19 行 `required_at→NULL` + `grace_pending→1`；staging 实测 0 行；`vendor_employees` 两库 0 行）。备份在后端 repo 根 `_merchant_2fa_schedule_backup_{prod,staging}_20260720T122704Z.json`（未 git add）。🔴 `e8468ae` 及更早「强制 2FA」版本不得作为回滚目标（0719 裁决；数据已清，旧版排期命令可再武装）；该 SHA 已不在 current/previous 槽位，但 release 目录仍在，人工回滚仍可达——保护来自数据已清，不是来自槽位。
- [x] Claude(挂牌态总闸+后台入口·2026-07-21) ✅已上线(e4b733e5 · release 20260721-203754-e4b733e · 健康门 config/zone/login=200): 新增 NezhaListing(总闸 nezha_listing_status + 有效挂牌态单点) + Admin/NezhaListingController + admin-views/nezha-listing + 迁移 + 契约测试; 原 6 处 nezha_listing_only 直读点全部收口(RestaurantLogic/Helpers×2/ProductLogic/Api·OrderController 403 闸/Api·RestaurantController 联系方式)。前端零改动。上线方式=部署前预置总闸 1 → 部署前后 12 店快照逐字相同(行为 diff=0)。🔴 部署前 GATE 复核揪出: 现网挂牌店实为 10 家(9 家 status=1 真实商家·KYC 0 行), 合规记录已如实更正, L1-6 射程裁定另开任务。未碰他窗 WIP。（2026-07-21）
- [x] Claude(收尾窗·0721) 共享工作树已对齐 origin/main（旧岔线保留在 `rescue/wt-0720-be`，零丢失可切回）；提交仍一律走隔离 worktree。对齐时逐个点名覆盖了 3 个陈旧文件（AGENTS.md / deploy/nzdeploy-api.sh / vendor-views order `_detail_modes.blade.php`——均经 `git diff origin/main` 证实是被 origin/main 取代的旧版，未用 `reset --hard`/`clean -f`）。🔴 另：工作树根一个**未跟踪**的 `nzdemo-rollback.sh`（旧编排版，与 origin/main 里的同名新版 PLAN/REHEARSE/GO 硬化版**内容不同**）挡住 checkout，已改名保全为 `nzdemo-rollback.sh.bak.202607212016`，**未删除**；现在根目录那份 `nzdemo-rollback.sh` 是 origin/main 的新版（依赖 `nzdemo-cleanup.php`，已确认存在）。若你的流程依赖旧版行为，去 .bak 那份取。
