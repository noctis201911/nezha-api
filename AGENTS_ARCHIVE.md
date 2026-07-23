# AGENTS_ARCHIVE — 认领区归档（只进不出）

> 规则〔2026-07-22〕：AGENTS.md 认领区只留最近 14 天与所有未完成 `[ ]` / `⚙️发现`；更老的已完成 `[x]` 整行移入本文件，保持原文不改写、按原顺序追加。搬运提交净删 >15 行会触 commit-msg 墙，带 `[force-revert]` 属预期。

（暂空——首次搬运待做）

## 归档批次 2026-07-22（cutoff=2026-07-08，共 63 条）

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
- [x] Claude(违规帖证据冻结窗口) ✅已完成(本次提交·🔴L1-7有限例外·已批准): local_life_posts加legal_hold/reason/at(迁移140000)+PurgeLocalLifePii跳过冻结帖(contact+图留证)+后台legalHoldToggle方法/路由/列表blade🔒按钮+冻结徽章+模型fillable/cast。§5.3承诺(正本+线上locallife_terms)加「依法/配合调查需保留除外」(线上旧值备份_locallife_terms_backup.txt)。docs:data-protection/ADMIN_GUIDE8.8/CHANGELOG(L1变更)/INVARIANTS(L1-7附注)。验证:dry-run冻结帖豁免/未冻帖被清+后台渲染200。未碰他窗WIP（2026-06-21）
- [x] Claude(隐私政策对齐窗口) ✅已完成(本次提交): 隐私政策§5加(5)本地生活联系方式30天清除+留证例外(正本docs/legal/privacy-policy.md+线上data_settings.privacy_policy,旧值备份_privacy_policy_live_backup.html),清Laravel缓存+nginx60s自动过期,真机/privacy-policy验证渲染console0。仅privacy-policy.md+CHANGELOG+AGENTS。未碰他窗WIP/已暂存MM（2026-06-21）
- [x] Claude(后端部署边界改造窗口·架构债根治) 正在做【后端发布边界 Capistrano-lite·阶段1a】: 建 /www/wwwroot/api-deploy/{shared,releases}+current软链、storage 与 .env 抽共享、改备份脚本指向 shared、生成首个 release。🔴动 storage/结构·牵一发动全身——别窗请勿动 storage/ 与新部署结构;此步不碰 nginx/cron/fpm,线上仍读旧工作目录【完全无感】。回滚=删 api-deploy + storage 切回。(2026-06-22)
- [x] Claude(集成验收窗口·代提交) ✅ 佣金计算收口已提交 f99c383: 某窗口(疑B支付方式组合)把 OrderLogic::create_transaction 内联计佣公式抽成纯函数 nezha_commissionable_amount() 的改动停在工作树未提交、未登记认领;经用户授权由集成窗口代提交(私有index精确 add 仅 OrderLogic.php + NezhaCommissionTest.php,未带 en/messages.php、无别窗WIP)。已逐分支验证等值(费率/计佣基数/express二次扣/订阅免佣)+NezhaCommissionTest 10例19断言全绿+pre-push红线通过。🔴原窗口勿再重复提交这版佣金重构(已在 f99c383)。另:本窗顺手清理 en/messages.php 尾部3行 translate()自动污染(含中文键"已标记为已退款"=确证污染,来源 Vendor/OrderController:378),已还原至 HEAD,备份 messages.php.bak.intgwin.*（2026-06-22）
- [x] ✅ 已收尾(提交 8c799e1, 佣金窗口 2026-06-23: 5.5b 曾随 d243409 提交但被并发窗口冲出 HEAD, 已从工作树重新精确提交+push, origin 已含) — 原 🔔 知会【单店专属佣金率/佣金窗口】(集成验收窗口留): 后端共享工作树 ADMIN_GUIDE.md 有 +10 行未提交(新增 ### 5.5b 单店专属佣金率)，正是你 d243409 提交说明里写明"ADMIN_GUIDE新增5.5b"要随该提交一起的文档——代码已上(私有index)，这段 doc 漏在共享树没 staged。集成验收窗口按规【不代提交业务窗口文件】，请你: ① git diff HEAD -- ADMIN_GUIDE.md 确认是你的、无别窗 WIP 混入 ② 用 bash /www/wwwroot/nzcommit.sh /www/wwwroot/api.nezha.am -F <消息文件> ADMIN_GUIDE.md 精确提交+push 收尾 ③ 划掉本行。当前线上发布(前端 BUILD c4JH_DxrABm2DVdXiLRc1)不受其阻塞(后端 push-gated，未提交不上线)。(2026-06-22)
- [x] Claude(窗口ADMIN-SEC-P0·后台安全P0) ✅已完成上线(提交 8cb8b83, deploy release 8cb8b83): 敏感路由权限位拆分(nezha-risk 拆 module:risk[队列/日志/处置] + module:risk_settings[改阈值] / nezha-refund→module:refund / nezha-deposit→module:deposit / nezha-kyc→module:kyc, 原均搭 order/restaurant 宽位=活体坐实越权) + custom-role create/edit.blade 补 risk/risk_settings/refund/deposit/kyc 5 个 checkbox + 风控设置页 BscScan/TronGrid API key 非超管脱敏(NezhaRiskController::updateSettings 同步写入守卫) + ModulePermissionMiddleware admin 无权限落品牌化中文 403 页(admin-views/errors/no-permission.blade; vendor 分支保持 back() 不动)。验证免 captcha 确定性: 权限门三态(order-only全deny/risk_settings-only仅settings/超管全allow) + route:list 接线(working+deployed两层) + 密钥脱敏渲染(超管明文/非超管掩码) + 3 blade 编译 + php -l 全过; 唯一现存 admin=超管恒 bypass=生产零影响。改 7 文件, 未碰别窗 WIP(ADMIN_GUIDE.md 等)。🔔 留给其它窗口: ①侧栏 _sidebar.blade 「风控中心」仍判 module_permission_check('order')、nezha-deposit 判 'account'——归 UI-1 对齐到新位(当前 fail-closed 安全: 路由已拦死, 超管照常全见) ②nezha-cs 仍搭 module:order(含 DeepSeek key + relay 模板转达, 建议另拆独立位) ③审计日志 SEC-3(改阈值 updated_by=0 无 Log / 角色 / 员工变更无审计)另窗做。(2026-06-23)
- [x] Claude(窗口SEC-CS-PERMISSION) ✅已完成上线(提交7edc2cb·deploy release 7edc2cb): nezha-cs 后台拆独立权限位 module:nezha_cs(原整组搭 module:order=仅 order 权限员工即可进 AI 客服后台·改总开关影响真实顾客·看顾客客服评价含PII) — routes/admin.php 路由组 + _sidebar「AI在线客服」菜单 guard 由 module_permission_check('chat') 对齐到 'nezha_cs' + custom-role create/edit.blade 补 nezha_cs checkbox(复用既有 translate('AI在线客服') 零新增未译key)。DeepSeek key 脱敏审查=无需改动(nezha_cs_ai_api_key 后台从不渲染明文, NezhaCsController 仅取 (bool)hasKey 作"是否已配置"提示, saveSettings 不写该key, blade 无输入框, 仅服务端 NezhaCsAssistant 读取)。验证: route:list 4条全挂 nezha_cs + 权限门三态(order-only deny / nezha_cs allow / 超管 role_id=1 bypass) + 3 blade 编译 + create 页完整渲染含 checkbox + 部署版复核。私有index仅提交本窗4文件; _sidebar 只入 mine-only blob, 未带别窗风控UI-1侧栏WIP(其WIP仍在工作树完好)。（2026-06-23）
- [x] Claude(窗口UI-1-ADMIN-SIDEBAR) ✅已完成(本次提交): 后台侧栏 _sidebar.blade.php 风控中心权限位对齐到8cb8b83拆分的新位——组壳判 risk||risk_settings||refund||kyc(原判order)+各子项各自判对应位(queue/logs→risk·settings→risk_settings·refund records/overdue→refund·kyc→kyc); 佣金充值管理 account→deposit + 交易管理小标题组条件补 deposit。不碰路由权限逻辑(已在8cb8b83)。验证: 超管渲染/admin=200且7链接全在(无回归) + 构造 refund-only/risk-only/order-only 角色跑 module_permission_check 证分位过滤正确(order-only现全0=旧错位已修)。未碰他窗WIP(ADMIN_GUIDE/en messages.php)（2026-06-23）
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
- [x] Claude(广告竞价v1第一期·后端核心) ✅已完成上线(提交64414ed/deploy release 64414ed): CPC按点击计费+首价+近实时物化竞价(每5min,nezha:recompute-ad-auction)+独立ad_balance子余额(只扣它永不碰deposit_balance,INV-1从结构根除买广告把自己店买下线)+可信计费身份(登录+真实下单史)+难刷质量分. 开关nezha_ad_auction_status默认0关(关时零行为变化). 改: RestaurantLogic.php(排序读mat_boost,关走原CPT) / bootstrap/app.php(+recompute调度) / routes/api/v1/api.php(+click auth:api/impression) / Api/V1/AdvertisementController.php / 新命令RecomputeAdAuction+CreditAdBalance / 迁移2026_07_02_000100(全additive) / tests/NezhaAdAuctionTest / docs(CHANGELOG/INVARIANTS/PLAN). 流水类型ad_click_fee(隔离不混deposit对账). 死亡测试PHPUnit9/9+真并发30/50路零超扣无死锁对账一致. 未碰他窗WIP(routes/admin.php D窗·OrderLogic存量bug·admin UI第二期). (2026-07-02)
- [x] Claude(广告竞价第二期·超管侧A窗口) ✅已完成上线(提交 fd2c4ac / deploy release 20260701-004105-fd2c4ac): admin/advertisement 竞价参数页(auction-settings, 9 键 L2 + 守卫 floor>0/封顶≥floor/日预算≥floor + 总开关翻转写 AdminAuditLog) + 广告余额充值页(ad-recharge, 复用新 AdBalanceLogic::credit 单一真相源 + 只充值不扣减(冲正走 CLI) + 二次确认 + 审计) + routes/admin.php 4 路由 + admin _sidebar 2 入口 + AdBalanceLogic(CreditAdBalance CLI 命令重构委托同逻辑, force-revert 净删=搬迁非退码) + 文档(ADMIN_GUIDE ch24/CHANGELOG/PLAN §10)。开关仍默认 0，纯超管 UI 零 live 影响。验证: 进程内真渲染 22/22(含 INV-1 隔离证伪 deposit 纹丝不动 + 事务 rollback 零残留) + 部署后 LIVE 复验两页 render + switch=0 + 4 路由。未碰他窗 WIP(en messages.php / 优惠券 vendor 侧)。真超管浏览器点击(登录验证码)待业主亲测。(2026-07-02)
- [x] Claude(广告竞价第二期·商家侧 Slice B) ✅已完成上线(提交 ec5b206 / deploy release 20260701-013733-ec5b206): 商家后台「广告→竞价推广」3 旋钮面板(开关/低中高/日预算)+只读 广告余额·今日已花·今日点击; 一店一条 cpc advertisements 行 upsert(tier→出价跟随后台 floor/max_per_click·出价隐藏·自助即时 status=approved·IDOR 服务端取 restaurant_id·CPT 列表过滤 cpc); 侧栏入口跟随总开关(关时隐藏)。改 Vendor/AdvertisementController(+promotion/savePromotion/cpcTierBids+index 过滤)+新 promotion.blade+routes/vendor.php+vendor _sidebar+文档(MERCHANT_GUIDE/PLAN§10/CHANGELOG)。零 live 影响(开关默认 0+ad_balance=0 惰性)。验证进程内真渲染+upsert 20/20+门控 4/4+部署后 LIVE 复验。真机商家操作待亲测。(2026-07-02)
- [x] Claude(商家订单列表P3叫车抽屉窗口) ✅已完成上线(提交 6ba5d4c / deploy release 20260701-090813-6ba5d4c): 详情页 Yandex 叫车卡抽为共享 partial(_dispatch_tools·ID参数化-订单号) + 列表配送单(备餐中/待配送)「叫车配送」→底部抽屉复用 + OrderController list restaurant eager-load(防N+1)。真机验: 列表按钮/抽屉开合/详情页叫车回归未坏/移动全屏, console+page错误0(demo单2000000005裸翻processing截图后已还原)。（2026-07-01）
- [x] Claude(商家订单列表P6今日营收窗口) ✅已完成上线(提交 fa36b0e / deploy release 20260701-095632-fa36b0e): 「今日营收」开关(今日单数+已确认到账,默认关)加到「显示已完成」筛选条(精简版·不加会与侧栏每状态计数重复的chips)。数字同源: DashboardController::nezha_today_sales() 抽为共享 helper(单一真相源防drift), nezha_today_summary 重构为调用之。改 DashboardController(helper+重构)/OrderController(list传$nzToday,仅all视图)/list.blade。真机验: 开关默认关/开显示֏3800(与dashboard今日经营卡同数=同源)/localStorage持久/移动/列表页console0; dashboard回归今日卡正常。纯L3只读展示不碰L1(平台不碰钱)。（2026-07-01）
- [x] Claude(订单通知异步化窗口) ✅代码+测试完成(提交见本次): Helpers.php 3原语(sendNotificationToHttp/sendTelegramToRestaurant/ToAdmin)改灰度可入队(SendPush/SendTelegramMessageJob 经 nezha-queue worker) + tests/Feature/NezhaNotificationAsyncTest(7绿·含灰度关=不入队)。开关 nezha_notif_async_status 默认0=关=零行为变化(内联同步),已commit+push。**生产翻开关激活待 /debate + staging下单QA + 签字**。未碰他窗 messages.php WIP（202607012113）
- [x] Claude(商家订单列表P4·接单可选出餐时间) ✅已上线(别窗门店形象 b2379a8 部署 origin/main 时连带带上·commit 1025833·offline迁移 2026_07_02_050000 [167] 已跑)·进程内渲染(LIVE release)验非500 + prep按钮/initPrepPrompt在位 + 事务造态回滚净·✅Swal交互业主真机确认弹框正常(2026-07-01): list.blade 开始备餐(非offline)+确认收款(offline三合一)点击弹 Swal 填预计出餐时间(预填店铺/平台默认·可改·校验1-1440·写隐藏域走原AJAX+auto-print) + OrderLogic/Vendor OrderController confirm_offline_payment 增第5可选参数 processing_time(向后兼容·仅覆盖展示ETA·不碰资金/状态/制裁). Blade真编译+php-l+pre-push12测试过·未browser QA. 🔴暂缓部署(业主定·2026-07-02): 部署origin/main会连带 migrate --force 跑 offboard 959b8b4 建表到生产库(别窗设计阶段schema)——谁要部署先协调 offboard窗口/业主, 且部署后须替本任务真机QA三态. 未碰他窗WIP.（2026-07-02）
- [x] Claude(今日售罄窗口) ✅已上线(后端537162b+迁移2026_07_02_060000/前端见nezha.am): 商家一键今日售罄. **food 加 nezha_sold_out_date 列**(可空date, 与拖拽排序 nezha_order_column 并存无冲突)+Helpers::addonAndVariationStockCheck 开头加售罄拦(覆盖unlimited/全加购下单路径)+Food isSoldOutToday/appends is_sold_out+**product/list.blade 加「今日售罄」toggle 列**+路由vendor.food.sold-out/控制器soldOut. MERCHANT_GUIDE已补. 未碰他窗messages.php/AdvertisementController WIP. (2026-07-01)
- [x] Claude(菜品批量操作窗口) ✅已上线(后端f6016fe / release 20260701-184200-f6016fe·blade-probe OK+config/zone/login=200): 商家菜品列表批量操作. **在拖拽排序窗口(3bbc467)+帮助图标(72e6fbd)提交后接手落地**,与它们同3文件但不同区域(它们:sort路由/sortIndex-sortSave/排序按钮/表头?图标; 我的:update-price后加bulk-*路由/updatePrice后加批量方法/表头+行加勾选列/底部操作条+改价改分类弹窗+JS)。改: FoodController.php(+bulkStatus/bulkPrice/bulkCategory/bulkDelete+nezhaBulkFoods+单条delete()补有订单拦截+list()加parentCategories) / routes/vendor.php(4条bulk-*) / product/list.blade.php(勾选列+底部bar+2弹窗+JS)。RestaurantScope限本店防IDOR+批量删除跳过有订单菜(order_details对food无外键=不500,保留报表引用,引导改下架)。进程内渲染验证22/22(no-500+5元素+4端点+IDOR+订单拦截,事务rollback零副作用)。MERCHANT_GUIDE已补。🟡JS交互(勾选→bar/弹窗提交/toast)待真机亲测(后台验证码无法Playwright)。未碰他窗WIP(RestaurantController/sort.blade)。(2026-07-02)
- [x] Claude(顾客取消理由探针窗口) ✅已完成(代码已push·未部署,待截图拍板): 新增后台「顾客取消理由」只读分析页 — Admin/NezhaOrderCancelDemandController + admin-views/nezha-order-cancel-demand/index.blade + routes/admin.php + _sidebar.blade.php(路由/侧栏精确追加)。数据源orders表无新表,两取消路径合并(finalize抄入cancellation_reason),note默认打码超管可见。进程内渲染185KB no-500。（2026-07-02）
- [x] Claude(商家退出结算 step4-4/step5 窗口) ✅已完成(commit b2ae622·push origin/main·🔴未部署 dormant): 暴露层(商家端对账中心底部申请/撤回退出 + 超管 admin/nezha-offboard 审批/放款 UI + 侧栏入口)+step5(制裁实时 re-screen fail-closed + 户名三方核对 holder_verified + 审批闸 H 高额 T+1)+KYC review 联动 onKyc*+「待退出核验」队列+L1-8③变更(业主批准·实时 screen_names)。开关 `nezha_offboard_status` 默认关(服务端 open() 控制器强制)。验收: staging harness P2 70/70+P3 52/52+进程内渲染 15/15+NezhaL1RedlineTest 9/9。改 19 文件(NezhaOffboard/Vendor+Admin NezhaDeposit... 见 commit)。🔔 **谁下次 nzdeploy-api 会连带上线本次(dormant·开关关+入口 switch-gated 无 live 影响)**,随 offboard 批次由业主协调。🔔 未碰别窗 WIP: 工作树有 order-export(app/Exports/OrderExport+OrderRefundExport·Vendor/OrderController·order list/order-view blade·file-exports)=另窗未提交改动,我用 `nzcommit.sh` 私有 index 只精确提交本任务 19 文件、未扫入你的 WIP(git diff --stat 已核对)。(2026-07-02)
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

## 归档批次 2026-07-23（nzclaim archive · >14天 [x] · 2 条）
- [x] Claude(超管后台M1窗口续) D2+D3已完成上线,**M1(D1+D2+D3)全部完成**(D2提交6fa804a·release 20260707-232528-6fa804a;D3提交1bd5aa7·release 20260707-234531-1bd5aa7;两次健康门config/zone/login=200+blade-probe OK+COD自检绿): D2藏8个死菜单(POS/堂食/周期购订单/调度中心/忠诚积分/返现/骑手打款/结算报表,新增hide字段+`_sidebar-item.blade.php`渲染HTML注释代替`<li>`,路由/控制器全保留)——复用D1手法7档权限×9路径harness验证63组合中36组合差异精确=且仅=8项藏匿注释,27组合零差异与理论预期(risk_kyc/settings_only/zero权限本无权访问这些死菜单)精确吻合;curl抽查8条藏匿路由均401同D1基线;⚠️经读header代码证实顶栏购物车图标实为"待处理订单"快捷入口(链admin.order.list pending)非POS徽标,Fable brief视觉猜测有误,**未藏**。D3按§B重排为8组新导航(①今天②订单③钱风控④商家⑤内容审核⑥顾客客服⑦洞察⑧系统,⑧含集成/装配/危险区/报表存档四折叠子区),只挪分组归属+组标题改中文直书,route/gate/active/badge/yield字段值全部原样保留;新建1个此前从无入口的条目(安全审计日志nezha-audit,module:audit闸);116条manifest入口完备性核对**116/116全覆盖**(106可见href+10条D2藏匿命中,0遗失);7档权限冒烟测试中揪出并修复1处真实gate倒退(report_only档原看不到系统组下的报表存档折叠,已补report权限到组gate)+1处expanded判据误删(firebase-otp*/storage-connection*两条件在合并集成子项时被误删,已补回)。4处§B原文未写死的判断call(zone/addon/email-setup/join-us就近归组)已在 `docs/ADMIN_M1_D3_LEDGER.md` 标注,低风险可逆,如需调整回Fable复核。ADMIN_GUIDE.md §1新增1.2节说明8组结构。**⬜未验证需人测**: 真机Playwright截图(需业主给最新basic auth密码,HANDOFF已提示业主会改密码)+侧栏搜索框真实点测(结构分析认为通用DOM文本过滤不受分组变动影响,但未真机验证)+iOS/移动端;§F明确不在M1范围(路由级403禁用/驾驶舱/计数provider/订单收敛/开关台账等)均未做,留M2-M6。Fable下一步出M2驾驶舱效果图走业主点头闸。(2026-07-08)

- [x] Claude(本地生活批2·攻略MVP+分享面窗口) ✅全上线(4899dce+文档1511482): nezha_guides表+公开API(开关封印)+Admin CRUD(生活攻略菜单)+og品牌卡两模板+PRELAUNCH/ADMIN_GUIDE§26。进程内验证全绿(Blade编译/开关两态/详情4占位+cards/XSS剥除/stale/有用+1/og三卡)。开关nezha_guides_status=0封印待五篇内容录入。（2026-07-08）
