# DESIGN debate 第二轮 — 资金正确性/并发/状态机红队 — verdict: 需补后可实现(3🔴+3🟡+1🟢+6站得住)

## 🔴 硬伤
### A 冻结漏掉4条写deposit_balance路径(阻断A没真堵)
grep全仓 deposit_balance= 有6类写入,§C只短路扣佣+ad_click_fee两条。settling期间照写的4条:
1. **refund_reversal(OrderLogic:740)**:顾客对settling前的直付单退款、admin在settling期间批准(Admin/OrderController:757→refund_order)→+commission回deposit。🔥门是`if($is_direct_pay&&$deducted>0)`(:731/:738)**根本不看nezha_commission_active**→哪怕按设计改commission_active返false这条纹丝不动照写。net被低估。
2. 平台下架广告退费(Admin/AdvertisementController:288)按未投比例+refund回deposit,无settling判断。
3. admin手改押金(VendorController:718 rechargeDeposit/Admin/NezhaDepositController:108 store_recharge),无settling判断。
4. **nezha:charge-ad-on-start(ChargeAdOnStart:90,bootstrap/app.php:129每小时)**从deposit_balance扣广告投放费(独立于ad_click_fee的**第二条ad扣费腿走deposit不走ad_balance**),cron不认settling。
- 修复:接缝不该是"改扣费函数返回值"。(推荐)approved锁定net+三账户快照落库;paying→paid事务内lockForUpdate重读真实余额**与快照不一致直接abort转人工别盲目置零**(漏没漏冻结都不错退)。(配合)4条路径各加offboard_status IN(settling/owing/offboarded)短路/拒绝,枚举必须补全。

### B §C选错短路接缝:改nezha_commission_active横向污染5个无关调用点
nezha_commission_active是单一真相源,5+处调用:扣佣OrderLogic:276/接单闸nezha_deposit_below_threshold:2308→is_store_open/DashboardController:229保证金卡/VendorController:793 $depositMode展示。重载成"顺便表达settling"→settling店后台显示"未启用抽佣模式"、dashboard翻转=一个函数两个意思埋雷。
- 修复:**别动nezha_commission_active**。在扣佣调用点(OrderLogic:276)前独立加offboard_status==='settling'短路,与抽佣开关正交(冻结=退出中语义,抽佣=商业模式语义)。

### C §F net公式当前不可计算:penalty概念代码不存在+未结佣金模糊
- "未结佣金"模糊:commission_deduction已逐单实时从deposit扣(OrderLogic:279下单即扣),已扣的早不在deposit里。真"未结"=delivered但settle_delivered未跑的单(auto-finalize-handover每小时补跑settling期间有在途)。设计没定义集合怎么算、没说settling是否强制先跑完settle_delivered。
- "罚款penalty"全仓零命中(§J4自认),net公式挂着无来源/无写入/无字段的减项→核心公式无法求值,net_amount/shortfall/owing建在悬空量上。§J4承认但**未降级verdict**。
- 修复:①未结佣金精确定义为可SQL集合+settling首步强制结算所有在途单(handover/picked_up未settle的跑完)再算net;②penalty本版**从公式删掉**(不存在别写进),要penalty另立L2独立功能不阻塞退出。

## 🟡 需补
### D UNIQUE(vendor_id)让rejected/failed商家永久无法重新申请
制裁/前置门没过→status='rejected'行还在vendor_id已占用→补齐KYC/举报驳回后重新申请撞UNIQUE永久挡死。failed/partial重试同理。制裁有possible疑似档误判后无自助回路。§J2只想到re-onboard(新vendor)没想到同vendor重试。
- 修复:UNIQUE改"只对活跃一条唯一"=UNIQUE(vendor_id) WHERE status IN(applied/approved/paying)(MySQL无partial index用生成列或UNIQUE(vendor_id,active_generation),rejected/failed释放generation)。幂等只需"同一时刻一条在途"。

### E partial恢复重复退风险:无腿级幂等
net三账户合计一次算。deposit腿成功leg_deposit_paid=1且deposit=0、guarantee腿失败标partial未置零。人工续partial若重跑整个结算逻辑而非逐腿查leg_*_paid→把已置零deposit腿再算再写负流水(0再减变负)。
- 修复:明确partial恢复**逐腿幂等** for each leg: if leg_paid skip;net_amount保申请时快照不变只补未付腿。照抄ChargeAdOnStart:81(事务内重读paid_at已扣return already)。

### F 锁边界:净额快照锁定时刻未定义,approved→paid跨天裸奔
§C2 lockForUpdate只在最后置零那下。流程跨多请求可能跨多天(20天冷静期+高档次日转§H)。approved算net=100,几天内上述4路径改deposit(refund_reversal+30),paid读deposit=130——设计没说用approved快照100退还是paid时刻130退。100→少退30;130→审批针对100做的金额已变审批失效。
- 修复:paid事务内锁定后重读真实余额与approved快照比对:一致才置零放款,不一致(说明漏冻结)不放款转人工重审。**这条同时是硬伤A的安全网**——漏冻结某路径快照对不上就停不错退。强烈建议写进§C。

## 🟢 建议
### G balance_after=0精度:anchoredBounds倒推vs历史流水不全→归零点可能非0
anchoredBounds(:71)用"期末=当前余额−区间后净变动"倒推。历史流水Σamount与真实deposit有累计偏差(:69注释自认种子直写余额没留流水),置零后currentBalance=0但opening/closing回推可能非0→对账页"余额0但期末−0.03"。金额小非阻断但商家会截图问。
- 修复:置零前写一笔显式对齐调整流水使Σamount与真实余额吻合再置零。

## 站得住(诚实6条)
1. §0直付单410终态修正完全正确已实测(OrderController:1709 offline_payment一律410)。
2. UNIQUE(vendor_id)防双击/双窗重复退幂等根成立(只是过强见D,但防重复退目的达到)。
3. §C2结算事务镜像OrderLogic:278 lockForUpdate是本仓已验证成熟范式(store_recharge:100/ChargeAdOnStart:79/扣佣返佣全用)。事务内部纪律对(问题在跨请求快照时刻)。
4. §F隔离方向守INV-1正确(ad/guarantee独立全退不抵佣金,符AdBalanceLogic)。
5. §D制裁门改查主体状态非screen_address判断正确(法币-only无链上地址,hit/possible/未核验fail-closed符inconclusive_action='hold')。
6. §G对账match($account)替二元三目修得对,:108/:150/:164/:176+两blade枚举没漏。

拍板三件事(按阻断优先级):①【必改】§C冻结改"扣佣调用点独立加settling判断"+补全4条漏路径+加"paid快照比对不一致就停"兜底(一箭双雕堵A和锁边界)。②【必改】§F net删penalty悬空项+定义未结佣金=settling首步强制跑完在途settle_delivered后确定值。③【应改】UNIQUE放宽到"仅活跃一条唯一"给rejected/failed留重试回路。
