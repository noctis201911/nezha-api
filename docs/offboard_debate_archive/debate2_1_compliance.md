# DESIGN debate 第二轮 — 合规落地/加密PII红队 — verdict: 需补后可实现(2🔴+3🟡+1🟢纠正+6站得住)

基线:设计+INVARIANTS L1-6/7/8+NezhaSanctionScreen+NezhaKycScreen+KYC迁移/模型+SyncSanctionList+6个purge+NezhaL1RedlineTest+NezhaAdAuctionTest+**线上真实数据(vendor_kyc_profiles=0行/restaurants=7全active)**。

## 🔴 假落地
### ① 制裁门读入驻旧快照,名单每日刷新它却不重算→L1-6退款时点空转
- §D=退出查 vendor_kyc_profiles.screen_status∈{hit,possible}。但 screen_status 只在**入驻当刻**写(NezhaKycController.php:106-107 admin保存 / VendorController.php:201 建店),NezhaKycScreen::apply_to_profile()写回即固化。
- OFAC名单每日刷新(bootstrap/app.php:112 nezha:sync-sanction-list dailyAt04:30;SyncSanctionList.php:132同步人名到nezha_sanction_names),但**只upsert名单表,绝不回头重算任何screen_status**(grep全库无调度调apply_to_profile,仅2个HTTP入口)。
- 后果:入驻日clear、第30天上SDN的商家 screen_status仍clear→退出照付受制裁主体=L1-6在退款付款时点假落地。
- 修复:§D两道门必须用**当前nezha_sanction_names表RE-run NezhaKycScreen::screen_names([legal_name,beneficial_owner_name])实时内存比对**,不是读screen_status旧列(旧列只作展示/告警)。且screen_name只做规范化精确+token重叠、漏音译变体→approved门对possible/inconclusive一律fail-closed转人工,别只挡hit。

### ② KYC门卡死100%存量商家,设计无迁移方案(线上数据决定性)
- §D要kyc_status='approved'才放行(fail-closed本身对)。但线上 vendor_kyc_profiles**0行**、restaurants**7行全active**;kyc_status默认'none'、KYC入驻非强制(VendorController建店不落KYC行,迁移注释自承)。
- 后果:7/7存量商家想退出全被挡死,连挡它们的KYC行都不存在。§J没列这条,正文无backfill语。方向对但缺"存量商家退出前先补建KYC+审批"强制前置+运营SOP,否则退出功能对现网所有商家上线即不可用。
- 修复:补前置"applied时若无approved KYC→先转KYC补录+审批子流程,通过再继续";§I灰度写明这是所有存量店必经路径,不能默默reject。

## 🟡 需补
### ③ HMAC指纹是真难点,防换号薅落空前只是画饼
- §E1承认encrypted不能SQL WHERE,提HMAC(id_doc_number)但只写半句。id_doc_number是text+encrypted、录入自由文本格式不一;HMAC要输入完全一致→空格/大小写/前导0/护照身份证混填差异=漏匹配(换号薅照过)。现有normalize_name只归一姓名、证件号无归一管线。密钥/盐存哪§J1是问句无答。
- 修复:证件号先确定性归一(去空白/大写/去分隔,按id_doc_type分域)再HMAC;密钥独立于表空间加密key存keyring/env;明确"漏匹配>误匹配"→指纹只做**辅助红标线索不做硬闸**。未落地前§E1"防换号薅"应标"人工红标辅助",不得宣称已阻断D。

### ④ 户名核对仍人肉读自由文本,与第一轮同洞,无系统校验
- L1-8②要核对legal_name==bank_account户名==缴纳凭证付款人。bank_account是单个自由文本(户名没独立字段,法币-only后也没拆),legal_name是encrypted。落地=超管肉眼比对=第一轮批的"人肉读转错"同洞,只是写进SOP。
- 修复:最低拆bank_account出account_holder_name结构化子字段,或审批页强制超管勾选"已逐字核对"+落审计+高额双人复核,把"核对"从口头变留痕enforce。当前不算L1-8②真落地。

### ⑤ 留存靠"purge不碰"隐性成立,新增original_ref加密PII列无显式豁免断言
- §A2给restaurant_deposit_transactions加original_ref(encrypted=PII)。6个purge命令逐一核过(PurgePaymentProofs/LocalLifePii/MerchantLeads/YandexLinks/NezhaPurgeAnalytics/RestaurantReports)各target特定表,无一碰restaurant_deposit_transactions/restaurant_offboard_settlements→当前默认安全。但是"靠没人写清除"隐性安全,不像KYC有显式豁免声明(L1-7明文+迁移注释)。
- 修复:A2迁移注释+INVARIANTS L1-8⑤明写"original_ref属AML法定留存,任何purge不得清除本表"+加结构守卫测试。补一句声明+守卫,非重做。

## 🟢 纠正设计的担忧
### ⑥ §F担心的ad_refund撞断言其实不成立
- NezhaL1RedlineTest根本无ad_balance隔离断言(只L1-1/2/3/5/6)。ad隔离在NezhaAdAuctionTest:test_3(:177 ad↛deposit方向)+test_9(:277对账,:291 只sum type='ad_click_fee')。offboard写ad_refund(新type)不进test_9的sum→不撞。
- 提醒:§I.5加隔离断言的正确姿势=**加一条正向断言**(ad_refund只允许该type/全额退/不改deposit),而非改/放宽test_9的where过滤(那是"删断言绕过",INVARIANTS末尾明令禁)。

## 站得住(诚实6条)
1. 法币-only/平台不持USDT(L1-8①)真落地:§D正确区分链上地址筛(screen_address)vs主体名筛(screen_name)。
2. 抵扣隔离(L1-8④/INV-1)扎实:§F deduction只从deposit、ad/guarantee独立全退、净额仅展示合计,与现网ad隔离一致未突破。
3. 幂等根正确:§A4 vendor_id UNIQUE+§B状态机读净额/置零/写流水同锁同事务(§C2镜像OrderLogic:278),阻断A/B成立;部分腿失败标partial不置零符不可逆。
4. 冻结态从源头掐断新扣费(§C1)对症净额竞态。
5. 对账guarantee分支修复(§G)方向对,堵串味洞(memory有先例)。
6. §0对红队E实测修正准确:offline直付单OrderController:1709 410拦死,E收窄举报/风控两门是对真实订单结构的正确认知。

一句话:钱不会被跨账户挪用、幂等/竞态/隔离工程骨架是真的;但两道L1门在当前设计下坏的——①制裁门读入驻旧快照(名单更新它不更新,L1-6退款空转)须改当日名单实时重筛;②KYC门卡死现网全部7家商家(0份KYC档)漏了存量迁移。这两条补齐前L1-8不算真落地。③证件号指纹④户名核对仍"写进流程没给系统校验"别当已阻断。
