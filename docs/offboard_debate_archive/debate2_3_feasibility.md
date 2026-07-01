# DESIGN debate 第二轮 — 实现可行性/StackFood集成红队 — verdict: 需补后可实现(2🔴+4🟡+1🟢+7站得住)
基线:生产MySQL5.7.43;restaurants=7/deposit_tx=2/wallets=7/orders=17(小体量,锁大表担忧当前不成立但设计未写进前提)。restaurant_wallets.vendor_id无unique/index(到处按它查却没索引,建议加,非阻断)。全库greenfield无offboard/guarantee残留。

## 🔴 地基缺口/会炸
### ① §C"停接单"与"停扣佣"共用nezha_commission_active→自相矛盾,退出期间照样接单(与资金队"选错接缝"互证,更锐)
- nezha_deposit_below_threshold(OrderController:2308)第一行`if(!nezha_commission_active) return false`;真接单闸nezha_store_paused(:2318)=nezha_temp_closed||below_threshold,被place_order:2341+结算预检:2553消费。§C1要在commission_active里settling→return false(停扣佣),**但这让below_threshold立刻返false→store_paused返false→退出期间照常接单**(诉求反了,改commission_active反而拆了停接单门)。
- 修复:停接单**别走**commission_active/below_threshold链→在nezha_store_paused独立加`||offboard_status==='settling'`(与nezha_temp_closed并列);停扣佣单独在OrderLogic:276扣佣条件加`&&offboard_status!=='settling'`。两件事两个独立判断别共用。
- 证据:OrderController:2307-2315/:2318-2327/:2341/:2553;OrderLogic:276。

### ② §E1跨vendor身份匹配依赖对encrypted列SQL→物理做不到;§J1 HMAC是决定D成立的地基非细节(与合规队③互证)
- VendorKycProfile legal_name/id_doc_number/bank_account全encrypted cast,模型注释明写"不做SQL WHERE搜索,制裁筛查内存比对"。密文每次不同,WHERE id_doc_number=?永匹配不到→§E1跨行聚合无法SQL,只能全表拉内存逐行解密(几百店后不可行+全明文PII进内存违背最小暴露)。
- §J1标🔴但列"开放点"语气像可选;实际是§E1(阻断D)能否落地的先决:HMAC指纹若被否,§E1整个换实现或砍功能,非调参。
- 修复:先定案§J1再谈§E1=加id_doc_fingerprint=HMAC-SHA256(密钥,规范化证件号)明文索引列,密钥进.env不入库(参L1-7 keyring);规范化(去空格/大小写/证件类型前缀)FE/BE统一;误匹配<漏匹配风险可接受。若否决HMAC→§E1降级"审批页人工核对"删自动红标承诺。
- 证据:VendorKycProfile.php:22-33(7个encrypted+注释);INVARIANTS:26。

## 🟡 需补
### ③ §E1只写2条建店路径,实际3条——顶层VendorController.php:148漏写onboard_source
- 除register+Admin/VendorController@store,还有顶层app/Http/Controllers/VendorController.php store():148 new Restaurant(StackFood原生多步入驻向导create/store/business_plan/payment/final_step)。这条建的店onboard_source落NULL→§F命中历史阻断re-onboard豁免+§E1红标对这些店失效。
- 修复:onboard_source enum加显式default(建议self_register或新增unknown值别留NULL);3路径都写;grep全库new Restaurant/Restaurant::create确认无第4条(已grep:这3处+NezhaDepositController只建wallet不建店)。
- 证据:VendorController.php:42(store)/:148;VendorLoginController:102;Admin/VendorController:65。

### ④ §G只列4处,实际vendor index.blade有8处硬编码$account二元分支→guarantee Tab渲染错乱(数值自洽)
- §G覆盖normalizeAccount/:107/:150三目/account_label:166/$slug:178/两$labels。但index.blade还有8处$account==='deposit'/'ad'裸分支没提:余额卡高亮:49/:61、Tab active:83/:86、汇总@if:131、流水标题三目:170、页尾充值+低额告警@if:210。guarantee落所有==='deposit'的else→押金Tab不高亮/流水标题显"预存佣金流水"/错挂充值说明+告警设置。controller match改对故数值不串味但UI错乱。
- 修复:§G补"index.blade全部$account===改三态或数据驱动(controller下发accountTabs/accountTitle/showRechargeHelp,blade不硬判)";低额告警/充值块明确仅deposit保留@if别误挂guarantee;grep零残留$account==='。
- 证据:index.blade:49,61,83,86,131,170,210。

### ⑤ 迁移在切current前跑在活库上,设计未交代5.7锁窗+随部署顺序
- nzdeploy-api.sh:51 migrate --force在:71切current符号链接**之前**跑(旧release仍服务时迁移已打进共享活库)。§A给restaurants(+2enum)/deposit_tx(+3列)/wallets(+1列)加列+新表带FK。5.7 ADD COLUMN/ADD FK要元数据锁,deposit_tx长大后迁移阻塞扣佣insert(OrderLogic:281)。当前7店无感但设计当零风险。
- 修复:§I明确"迁移commit+push进release随nzdeploy-api.sh自动migrate";5.7 ADD COLUMN nullable+default用INPLACE/LOCK=NONE(hasColumn守卫先例ad_auction v1);FK可行(2026_07_02_010200先例,FK不受sql_mode影响);enum带default是INPLACE。
- 证据:nzdeploy-api.sh:51在:71前;2026_07_02_010200_nezha_fk_anti_orphan.php(5.7 FK先例)。

### ⑥ 缴纳记账被压成一句话,store_recharge配套全在但没写进§A/§B/§I验收面
- 退出前提是押金先进guarantee_balance,缴纳记账§A/§B无独立设计(§I步骤2一句"对称store_recharge")。store_recharge(Admin/NezhaDepositController:99-141)有validate/DB::beginTransaction+lockForUpdate/wallet不存在则建/create type=recharge流水带balance_after/created_by审计/Toastr/异常rollback——押金缴纳都要对称补(写guarantee_balance+type=guarantee_deposit+新3列currency/original_amount/original_ref+original_ref加密cast+后台表单blade)。做得出但压成一句易漏currency/original_ref校验或漏后台入口。
- 修复:新增§B' store_guarantee控制器+blade+校验(amount/currency∈{AMD,CNY}/original必填)+RestaurantDepositTransaction casts加original_ref=>encrypted(§A2已提别漏进模型)+created_by;缴纳流水type进ACCOUNTS['guarantee']。

## 🟢 建议/纠正
### ⑦ §C4"每单送达多查restaurants"实测不会(对设计有利)
- settle_delivered(OrderLogic:401):411已loadMissing['restaurant','restaurant.vendor'],offboard_status作restaurants列随之加载→§C1加判断不产生额外查询只多读已加载列,§C4担心的N+1不成立。但settling免佣薅单需产品定性:只要修对①停接单(settling真停接单)冷静期内接不了新单,薅法自动关闭→§C显式写"settling同时停接单+停扣佣,缺一出漏洞"。

## 站得住(诚实7条)
1. 锁+事务腿(§C2)可行:镜像OrderLogic:278成熟模式,三余额同锁同事务读-算-置零-写。
2. UNIQUE(vendor_id)幂等根(§A4/§B)可行,re-onboard=新vendor行成立。
3. 对账match()(§G controller侧)可行,anchoredBounds按$types数组过滤天然支持第三账户,只要补8处blade。
4. 制裁门(§D)可行:查单个vendor的screen_status/kyc_status不撞§J1加密搜索(§D查当前这一个vendor非跨vendor聚合)。
5. 隔离(§F)可行合规:deduction只从deposit,守INV-1(ad_balance物理分列2026_07_02_000100),L1-8④已记INVARIANTS:27。
6. 迁移全additive/可回滚(§A/§I)可行:greenfield无双建,hasColumn守卫+down()删列删表既定模式。
7. 无定时任务(§H被动红旗)可行:纯查询零cron,避开"调度须注册bootstrap非Kernel"坑。前提:实装别顺手加自动追缴/清理定时任务,要加必进bootstrap::withSchedule。

一句话:地基(资金腿/幂等/隔离/制裁门)扎实能落地;但§C停接单与停扣佣共用commission_active会互相抵消(🔴必修)、§E1跨vendor溯源撞加密列不能SQL硬墙(🔴先定§J1 HMAC再谈E1)、§E1漏第3条建店路径+§G漏8处blade+缴纳记账压成一句话(🟡补齐即可)。五处补完可进实装。
