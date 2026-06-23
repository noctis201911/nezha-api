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
- [ ] 🔔 知会【单店专属佣金率/佣金窗口】(集成验收窗口留): 后端共享工作树 ADMIN_GUIDE.md 有 +10 行未提交(新增 ### 5.5b 单店专属佣金率)，正是你 d243409 提交说明里写明"ADMIN_GUIDE新增5.5b"要随该提交一起的文档——代码已上(私有index)，这段 doc 漏在共享树没 staged。集成验收窗口按规【不代提交业务窗口文件】，请你: ① git diff HEAD -- ADMIN_GUIDE.md 确认是你的、无别窗 WIP 混入 ② 用 bash /www/wwwroot/nzcommit.sh /www/wwwroot/api.nezha.am -F <消息文件> ADMIN_GUIDE.md 精确提交+push 收尾 ③ 划掉本行。当前线上发布(前端 BUILD c4JH_DxrABm2DVdXiLRc1)不受其阻塞(后端 push-gated，未提交不上线)。(2026-06-22)

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

- [ ] Claude(窗口M-04/M-05·商家订单详情顶部状态条+唯一主操作) 正在改 resources/views/vendor-views/order/order-view.blade.php —— Step A 抽 $nzPrimary 决策块(零视觉变化) + Step B 仅 pending·离线待核验一态打样置顶条(镜像确认收款·原位去重·保留打回/拒单);后续态待真机验证后再铺开。不碰路由/控制器/状态机;与M-01(dashboard)无重叠。(2026-06-23)
