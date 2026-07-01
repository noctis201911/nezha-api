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
- 改后端 = 工作树 `/www/wwwroot/api.nezha.am` 改 → 精确 `git add`+commit+**push** → 跑 `node nz.js run "bash /www/wwwroot/api-deploy/nzdeploy-api.sh"` 上线(干净ref+vendor硬链+原子切current+健康门+自动回滚)。
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
- [ ] Claude(商家订单列表P3叫车抽屉窗口) 正在改 vendor-views/order/order-view.blade.php(抽叫车卡为共享partial·ID参数化防碰撞) + list.blade.php(配送单下一步操作改「叫车配送」→底部抽屉复用partial) + 新增 partials/_dispatch_tools.blade.php。不碰他窗WIP。（2026-07-01）
