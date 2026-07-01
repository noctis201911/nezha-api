# DESIGN debate 第三轮 — 合规/KYC/指纹落地红队 — verdict: v2可进代码但需补4处(3🟡+1🟢),无假落地
基座实测:KYC total=0/approved=0/restaurants=7;KYC路由仅routes/admin.php:523-527(module:kyc admin门),api.php/web.php零KYC命中(商家端无自助入口);nezha_sanction_names 43576条+每日04:30刷(bootstrap:112);offboard全部字段/表线上尚不存在(确证design-stage)。多数攻击面v2已诚实预判降级,不为凑数升格。

## 🟡 需补
### 1 KYC补录前置是真门但商家端0自助入口→7店退出全卡admin手工,无运营闭环
NezhaKycController全在Admin命名空间(edit/save/review return admin-views,review用Auth::guard('admin')),路由仅admin侧,商家端无KYC提交端点。VendorKycProfile::count()=0/restaurants=7。非假落地(§B/§D2/§I诚实写"KYC补录必经路径"机制真门逻辑通),但漏运营可用性:save字段全"运营当面/视频核验后录入"商家无法自填→"商家点退出→kyc_pending"下一步=平台负责人本人当面/视频核验7店逐个admin手录+自己review approved。7店规模能跑不阻断,但没写清"谁核/多久/商家等待态"→商家卡kyc_pending无期限=体验死胡同。
- 修(不写商家自助KYC那增PII负债):§B/§I补运营SLA+商家可见态:①kyc_pending时商家对账页显"已收到退出申请,平台需完成身份核验(预计N工作日)届时联系视频核验";②admin侧"待退出核验"工作队列(KYC index加offboard_status='kyc_pending'筛);③核验超期不作为兜底(自动提醒超管)。运营闭环缺口非合规硬伤但不补则退出对商家不可用。

### 2 INVARIANTS L1-8③文本与v2 §D1自相矛盾—文本仍写"读screen_status列",v2改实时重跑
L1-8③原文"退款前制裁筛查未命中(vendor_kyc_profiles.screen_status∉{hit,possible})"明写读存储列。v2 §D1(K-C)正确指出这是bug改"实时RE-run screen_names"。修法方向对是真收紧(名单43576条每日刷,入驻clear主体今天可能已上榜只有实时重跑守得住L1-6退款腿)。但INVARIANTS是L1正本,改L1机制必须同步正本文本(CLAUDE.md铁律+INVARIANTS"改L1断言须同步不删")。现状实装照v2做则代码与L1-8③白纸黑字背离→未来窗口读INVARIANTS以为该读列可能"修回"成bug。
- 修(务必做):实装§D1时按L1变更流程把L1-8③文本从"读screen_status列"改"退款/审批当刻用当前名单实时RE-run screen_names(legal_name,beneficial_owner_name);possible/未决fail-closed转人工AML",记CHANGELOG。否则L1正本与实现漂移。

### 3 §F/§I的offboard L1断言:结构层写得出但资金闭环那半是空壳,须写清"断言只保证什么"
实读NezhaL1RedlineTest.php:结构/开关层断言(use DatabaseTransactions不RefreshDatabase/ReflectionMethod验签名/assertStringContainsString读源码守卫/内存new Order),注释明写"phpunit.xml未启用独立测试库,APP_ENV=testing仍连生产MySQL"→故意零持久写入。offboard三表/字段线上不存在。
- ✅测得到(照抄现有范式):ad_refund只允许type/全额/不改deposit正向断言+制裁re-screen调用点存在+法币-only无USDT开关+original_ref/KYC表purge豁免(可用源码守卫+开关态+反射签名,同test_L1_5/L1_6同构)。
- ⬜测不到(会空壳):任何需"跑真实结算置零三账户/验leg幂等/验C4快照拒付"的断言(需持久seed wallet余额,本harness连生产库只事务回滚做不到)。硬写只能伪断言证明不了资金闭环。
- 修:§I把offboard断言拆两类标清:①进NezhaL1RedlineTest只写结构守卫层(re-screen调用点未删/ad_refund不写deposit源码守卫/法币-only开关/purge豁免)明写"此层只保证开关态与代码守卫不替代资金闭环";②资金置零/leg幂等/C4快照拒付归staging下单harness手动跑(§I第4步已提"staging harness全绿才上"绑定为offboard资金正确性唯一验收来源,别让人误以为CI断言覆盖了它)。诚实分层非空壳。

## 🟢 指纹密钥轮换脆弱但现规模成本≈0补一句预案即可
id_doc_number=encrypted;v2 §E2 HMAC(env密钥,规范化证件号)密钥进.env没说轮换。核降级恰当可收敛建议:①指纹"只做辅助红标非硬闸"密钥泄露后果=红标信号被伪造/失效不影响任何资金放款/拒付(不是硬闸)严重性天然低;②现网KYC=0/7店即便轮换密钥逐行解密全表重算也是秒级7行内一次性脚本谈不上成本。攻击面"逐行解密全表重算"在别的体量硬伤在这不是。
- 修(轻):§E2补一句轮换预案"密钥轮换=一次性artisan命令遍历vendor_kyc_profiles解密id_doc_number(cast自动)用新NEZHA_KYC_FP_KEY重算回写;指纹辅助红标非闸重算期间红标短暂失效可接受"。不必现在写命令写清"泄露时这样做成本可控"免它当未知脆弱单点悬着。

## 核v2降级是否恰当(#4#5)—恰当不升警报
#4 normalize_doc_number分域漏匹配:按id_doc_type分域前缀确让"同人今护照明身份证"指纹不同漏匹配,但降级恰当:①指纹已标"辅助红标非硬闸接受漏匹配>误匹配"分域漏匹配落容差内;②不分域反面(护照号与某身份证号数字巧合撞库)误红标真人,低频软信号误报伤害>漏报。与整体"宁漏勿误"辅助定位自洽。真要更强得引入跨证件类型人+证件组合库属完整AML方案§J已列后续。
#5单超管holder_verified/双人复核=空动作:属实(review用Auth::guard('admin'),平台一超管"双人"无第二人)。但①v2 §J2已列余留debate项不是假装解决;②holder_verified从v1"写进SOP口头核对"升级"留痕enforce勾选+account_holder_name结构化字段(§A4)供逐字比对"与第一轮批的"人肉读转错"有实质区别(第一轮读一次转一次无留痕,v2结构化三方比对+审计留痕)。同人打勾防不了"本人蓄意放水"但防"手滑读错/事后无据"且留可审计轨迹。不是空动作是"单人可达最强留痕";真双人不可交付v2已诚实挂debate恰当。建议§H"高额5000次日转+邮件/TG二次确认链接"明确认定为单超管"双人复核"等价替身(同人两时点两信道),§J2从开放收敛为"已用异步二次确认替代"。

## v2真落地了的(诚实)
K-C制裁stale修对(nezha_sanction_names 43576条每日刷,入驻screen_status一次性写不重算,v2实时RE-run+possible fail-closed对症,仅须同步L1-8③文本见需补2)/K-D KYC前置真门(kyc_status机器none/pending/approved/rejected真实,review→approved路径通,approved=0→7店退出全落kyc_pending逻辑闭合仅缺运营SLA)/K-E指纹机制成立(id_doc_number encrypted,HMAC明文索引列+独立.env key方向对,辅助红标降级使漏匹配/轮换成本落可接受)/停接单停扣佣接缝改对(nezha_store_paused:2318与create_transaction:276正交,:411 loadMissing属实)/net去penalty成立/AML留存豁免有先例(L1-7已为vendor_kyc_profiles明写≥5年豁免,§A2给restaurant_deposit_transactions同款对齐范式)。

一句话:v2骨架站得住合规修法真能进代码。落地前补需补1(运营SLA·可用性必做)+需补2(L1-8③文本同步·L1正本一致性必做)+需补3(断言分层说明·防空壳误导QA)。建议1与#4#5均v2已诚实预判核验通过不阻断。
