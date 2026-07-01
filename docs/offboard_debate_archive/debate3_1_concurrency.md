# DESIGN debate 第三轮 — 并发/状态机/回归红队 — verdict: v2还需补3处(2🔴回归新硬伤+1🟡),骨架站得住
核心:v2对前两轮5🔴的资金正确性修法基本修对(C1正交化/C2单点/net公式/制裁实时方向都对),但v2依赖的"幂等兜底"底座本身有结构性并发漏洞,C5放大既有窗口;C1漏POS入口。

## 🔴 新硬伤/回归
### 1 C1停接单漏POS入口—settling店仍可商家后台POS收单
C1 settling判断加在nezha_store_paused()。grep全库该函数活跃调用者仅4处全顾客API侧(OrderController:2341 place_order/:2553预检/Helpers:849/:908列表flag)。**但POSController:357 place_order是独立下单入口,校验只type/购物车/订阅额度(:357-389),完全不调nezha_store_paused无settling判断**。→商家申请退出settling后顾客端"休息中"下不了单,但商家后台POS照常给进店客人下单成功。违"settling=停接单"前提;资金层不直接错(POS单后续被C2拦不扣佣)但产生"退出中店的活跃订单"与结清语义冲突,快照锁定后冒新单人工对账乱。
- 修:C1不能只改nezha_store_paused(POS不经过)。抽nezha_offboard_frozen($restaurant) helper在**所有**建单入口(place_order API+POS place_order)显式调;或POSController::place_order建单前加settling拦截。证据POSController:357-423/OrderLogic:2318-2331。

### 2 settle_delivered幂等无DB底座+多套racy check;C5新增并发调用者放大双扣佣(本轮最硬)
①实测SHOW INDEX FROM order_transactions:**只PRIMARY(id)+zone_id_index,无任何order_id唯一键**;restaurant_deposit_transactions.order_id也非唯一。②settle_delivered幂等闸OrderLogic:416 `OrderTransaction::where('order_id')->exists()`纯读无lockForUpdate,check与insert间无事务包裹(DB::beginTransaction在create_transaction内部、在exists之后)。③幂等检查多套各自为政:settle_delivered:416 exists()/商家端Vendor/OrderController:653 `$order->transaction==null`(lazy读无锁)/create_transaction 6+调用者各带前置null判断,racing在一张无唯一约束表上。④cron AutoFinalizeHandover的withoutOverlapping(bootstrap:117)只防两cron实例重叠,不防cron-vs顾客确认-vs商家点送达-vs C5首步。
- 竞态时序:handover满24h未delivered→T1商家点已送达(Vendor:653 transaction==null true)+同刻auto-finalize cron命中(:416 exists false)+同刻商家退出C5首步settle_delivered也扫到→三方都过各自"未结算"判断→都进create_transaction→都insert commission_deduction→deposit_balance扣2-3次。InnoDB行锁:278只锁wallet行串行化"读余额-写余额"但不阻止第二/三笔流水insert(无唯一键可撞)→每笔基于自己读的fresh覆盖写回=多扣佣(lost-update镜像)。
- C5放大:v2 §C5"settling首步强制settle_delivered跑完在途单"新增第三个并发调用者,恰和cron/顾客确认撞同批handover老单,双路并发变三路命中概率上升。§J3自认"靠exists幂等兜住需实测"→实测结论:底座不足以兜住。
- 后果:直付单佣金从deposit多扣→保证金错误多扣→退出净额算错少退商家=资金正确性问题。**⚠️这是LIVE既有bug(非offboard引入),offboard C5只是放大**。
- 修治本:order_transactions.order_id加**UNIQUE(order_id)**(配合直付单一单一笔语义),DB层最终仲裁并发第二笔insert撞唯一失败catch当幂等跳过。⚠️加前先查历史重复GROUP BY order_id HAVING count>1,有则先清理否则迁移失败。次选(弱):exists改lockForUpdate且与insert同事务,但跨四套调用者统一成本高易漏。证据SHOW INDEX实测/OrderLogic:415-417/Vendor/OrderController:653/bootstrap:117。

## 🟡 需补
### 3 C4快照安全网可被慢速合法写入变"商家退出被DoS"+C2 gate读stale/N+1
C3冻结4条deposit写路径但没冻结create_transaction的commission扣减本身,C2只让settling店不扣佣但C2 gate依赖`$order->restaurant->offboard_status`读到settling。loadMissing只在settle_delivered:411,其余6+调用者(Vendor:661/Deliveryman:554/Admin:678)不保证restaurant已eager-load且offboard_status是审批后刷新的→若请求早期已加载restaurant(settling前)→读缓存旧值'active'→C2 gate失效→settling仍扣佣→deposit变→C4重读余额vs approved快照对不上→abort。每次abort→offboard永远走不到paid→商家钱永卡settling(店停业)。§C4只说"abort转人工重审"没定义谁重算快照/快照作废/重走冷静期→人工只"再点paying"不重锁则无限循环=合法退出商家资金被无限期冻结DoS。
- 修:①C2 gate改显式fresh查询Restaurant::where('id',$order->restaurant_id)->value('offboard_status')(1次轻查避stale/N+1);②C4 abort后确定性恢复=回approved作废旧快照重锁新快照(重跑settle_delivered归零在途佣金后重算net)+连续abort N次熔断告警超管;③先补#1#2才能让C4从DoS源回归安全网。

## 🟢 partial恢复触发者未定义
§B partial逐腿幂等方向对(照抄ChargeAdOnStart:81,refund_reversal:738也用lockForUpdate),但谁触发恢复/能否触发两次并发§B/§J未写→人工按钮无锁两次点击并发进for each leg双付某腿。修:partial恢复入口加lockForUpdate锁settlement行串行闸,leg标记读写同事务。approved快照不变已正确规避"余额又变"(net不重算)这点攻不动修对了。

## 修对了/攻不动(诚实7条)
1. C1接缝正交化修对(v1错在改commission_active被below_threshold:2307首行依赖反致store_paused返false;v2改nezha_store_paused体内与temp_closed并列加settling与抽佣开关正交,实测:2318-2331支持;唯一遗漏POS=上文#1非C1逻辑错)。
2. C2单点停扣佣位置对(create_transaction:276是正确单chokepoint,6+分散调用者逐个加会漏;缺口只在读offboard_status方式stale=#3位置本身对)。
3. net=三账户和删penalty修对(create_transaction:276-289直付分支lockForUpdate扣写commission_deduction,refund_reversal:738-752 deposit加回;C5后net=三账户和无悬空减项)。
4. §A5 active_uniq NULL部分唯一在5.7正确攻不动(实测MySQL5.7.43,NULL在UNIQUE视为相异;两条active并存窗口不存在,InnoDB原子强制。唯一补应用层:catch唯一冲突当幂等重复勿500)。
5. D1制裁实时重筛方向修对(两道门各自实时查nezha_sanction_names无跨门缓存竞态)。
6. §0直付410前提站得住(delivered稳定终态状态机不因顾客翻单回退)。
7. 迁移additive可回滚(实测目标列表全不存在,7店greenfield,INPLACE/LOCK=NONE适用)。

一句话:资金正确性修法方向和位置基本修对骨架站得住,但两处必须先补再写代码:①C1漏POS下单入口(POSController:357不经nezha_store_paused)settling店仍柜台收单;②settle_delivered幂等无DB唯一约束底座(实测order_transactions无order_id唯一键+多套racy check-then-insert),C5首步强制结算放大双扣佣→治本给order_transactions.order_id加唯一约束DB兜底(⚠️LIVE既有bug)。第三处🟡C4冻结不完备时变商家退出被无限abort DoS+未定义恢复路径,补完①②后重评。
