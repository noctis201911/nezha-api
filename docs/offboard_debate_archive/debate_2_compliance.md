# 合规 L1 红队核验报告 — verdict: 🔴 阻断上线

## 🔴 阻断项
### 退出结算前置硬门缺「制裁筛查未命中」→ 可能向受制裁主体付款
- 场景：商家 KYC screen_status=hit/possible(法人/受益人命中OFAC SDN)或KYC USDT退款地址在 nezha_sanction_addresses 名单 → 平台把押金+预存佣金+广告退还给被制裁主体。
- 踩 L1-6。§3前置校验只三项(订单终态/无纠纷/20天),无"制裁筛查未命中";§4通篇未提L1-6。
- 依据：NezhaKycController::save 命中hit只 Toastr::error('建议拒绝')不自动拒不冻结(Admin/NezhaKycController.php:110-114);review()纯admin手点(:141-149)=L1-6入驻侧是"软告知+人工兜底"无硬门。offboard是"当日转+单人审批"比拒收顾客更危险(那是拒收/这是主动付款)。NezhaSanctionScreen::screen_address()能对单地址OFAC比对(设施现成没接)。
- 🔴关键差异:screen_address 只筛USDT加密地址;法币bank_account退款目标无任何制裁筛查(唯一姓名筛NezhaKycScreen在入驻且不硬拦);SDN地址名单筛的是交易对手地址不是vendor实体是否受制裁。
- 修复：§3加第4条红线级=screen_status∈{hit,possible}一律挡+转人工AML+审批入口对hit禁用;USDT退款腿打款前 screen_address($usdt_address)再筛;§4补L1-6裁定(被制裁主体押金冻结不退,退给谁/没收依据待律师,参 AML-manual-review-SOP.md §8)。不补不能上线。

## 🟡 应修项
### §4.1 二清论证方向对但是"受原路约束保护的结论"非无条件
- "再分发第三方❌"由§3原路约束保证非天然成立;法币腿转错主体则"退第三方"发生,破L1-1/L1-2。修复:§4.1补前置——本裁定成立前提是§3原路约束真落地,失效即不再成立。别当无条件真理被断言/审计误判。

### 法币腿 free-text bank_account 户名一致性无系统校验守不住L1-2内核
- KYC legal_name/beneficial_owner 与 bank_account(free-text含户名)不一致时超管人肉转无系统拦。bank_account一个自由文本塞户名+账号+USDT地址三种语义,机器无从校验户名==法人名。对比:顾客USDT腿链上反查from锁死;顾客法币退有原始付款截图作锚;押金法币退无"缴纳截图反查",缴纳只是超管记账入guarantee_balance,退时凭什么核对同一主体?未答。
- 修复:①缴纳时留存缴纳凭证/付款主体(对称顾客付款截图),退款双向核对 legal_name==bank_account户名==缴纳凭证付款人三者一致;②回执留痕入original_ref/note(L1-4)。

### §4.3「平台自有USDT地址+托管=运营考量非红线」定性偏松=事实上USDT资金池
- 平台自建USDT地址收多商家押金→U沉淀平台控制地址数月+平台有私钥控制权=持有混合多商家USDT余额。顾客维度对(押金非顾客钱)但"平台持有托管一池商家USDT"与L1-1"平台全程不碰资金"字面有张力,划"非红线"下得太快。
- 关键差异:顾客USDT直付商家平台从不持有(只读链上核验);押金USDT是平台自己收/自己保管私钥/自己转出=完全不同的托管行为,是对"不持币"基石(business-flow.md §6/L1-5)的实质突破非运营细节。
- 修复:不建议划非红线。首选**法币-only收押金不收USDT**(规避持币),删"平台自有USDT地址+托管";若确要收USDT须正式定性L1邻近+走INVARIANTS L1变更+律师复核(平台持商家USDT是否触外汇/支付牌照)+私钥保管/冷热钱包/多商家隔离禁混池。

### 新增 usdt_address 与既有 bank_account(已含USDT语义)重复+原址锚强度不同
- 迁移bank_account注释已声明可存"USDT收款地址";两处都能放→退款读哪个?历史数据在bank_account新字段空→读空无地址退。更深:顾客退款原路锚=链上反查from(不可伪造);押金退款原路锚=KYC超管手录usdt_address(可录错录假)非同强度。§4.3说"复用NezhaRefundControl链上校验"但其地址来源是从原始付款tx反查,押金若无链上缴纳tx可反查就退化成信任手录地址。
- 修复:①usdt_address为唯一权威源,迁移把bank_account里USDT地址迁出+去USDT语义;②押金USDT缴纳时记链上到账tx hash入original_ref,退款从该缴纳tx反查from作目标(真复用反查锁)非信任手录=L1-3字面落地。

## 🟢 建议
- original_ref存USDT地址/银行凭据缺应用层encrypted cast(RestaurantDepositTransaction模型无);表空间ENCRYPTION=Y已达标非违规但与KYC PII双层不一致。修复:加encrypted cast+§4.4 INVARIANTS声明该表押金流水免PII清除留存≥5年(已核实purge命令都不碰此表,现状安全)。
- 净额/追缴 vs L1-5"二清腿已拔":退还=押金/佣金B2B结算与被拔的顾客货款分账打款腿是两码事,§4补一句划清防NezhaL1RedlineTest审计误判"二清腿复活"。

## 站得住(诚实)
1. §4.1 押金≠顾客资金归集成立(AML-policy.md §1/business-flow.md §2,押金全程不涉顾客货款,顾客走直付独立通道L1-1)。方向正确。
2. §4.2 L1-2精确化对(L1-2字面是顾客订单退款语境,押金净额跨期套不上,收敛到反洗钱内核站得住,符立法目的)。
3. §4.3 USDT币本位/只退KYC地址/禁换汇提现中国完全正确必要(对齐L1-3)。退法本身合规(争议只在平台持有托管前置行为)。
4. §3 净额<0不退走人工追缴挡住干净退出对(AML fail-closed,AML-manual-review-SOP.md §7)。
5. 20天+订单终态+无pending纠纷前置门方向对(守L1-4)。缺的只是少L1-6一条,非现有三条错。

一句话:合规推理基本扎实(二清/原路/USDT三主论证站得住),但漏offboard独有L1-6——"退钱给商家"新动作第一次让平台可能主动向受制裁主体付款,现有KYC制裁筛查只软告知+人工兜底无硬门。补制裁前置硬门+收紧USDT托管定性+法币腿户名核对后再进代码。三条都属"改前必停取批准"的L1变更。
