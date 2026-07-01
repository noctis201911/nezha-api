# 资金安全红队核验报告 — verdict: 🔴 阻断上线

## 🔴 阻断级
### #1 净额快照读无账户冻结 → 并发扣佣/退款污染净额
- 净额=guarantee+deposit+ad−未结佣金/罚款，读的是 T0 余额；20天冷静期+审批间(T0→T1)顾客确认收货触发迟到 commission_deduction、ad_click_fee 任意时刻可发生(不受订单终态约束)。
- 后果：多退/少退；read-modify-write 与退出结算置零互相覆盖 balance_after → 账实分叉、5年流水永对不平。
- 证据：扣佣 lockForUpdate read-modify-write app/CentralLogics/OrderLogic.php:278(读)/:323(save)；退款返还同构 OrderLogic.php:739；广告是另一套 CAS 条件UPDATE AdvertisementController.php:176(WHERE ad_balance>=?)——两条腿两种并发模型。
- 修复：①退出结算单事务+SELECT FOR UPDATE 锁 wallet 行；②引入结算冻结状态 restaurants.offboard_status='settling'，在 commission/ad_click_fee 入口加"冻结中拒扣"短路；③读净额+置零+写退款流水同一锁同一事务，balance_after 锁内 fresh 回填。

### #2 三腿退还无原子性/幂等 → 部分成功不可逆中间态+重复退
- 打款是平台线下B2B转账、系统只记账 → "记账置零"与"真实到账"是两动作。法币腿成功/USDT腿失败时三列全置零还是只置零成功笔？未定义。
- 后果：USDT账上归零但钱没退→需人工反向补；"强二次确认"是UI层、无DB幂等标记→并发双击/两窗口重复记两笔+线下转两次。
- 证据：佣金结算有幂等闸可借鉴 OrderTransaction::exists() OrderLogic.php:412 附近 settle_delivered $already_settled；store_recharge 有事务无幂等键 Admin/NezhaDepositController.php:99。
- 修复：①建 restaurant_offboard_settlements 表(vendor_id 唯一约束+status applied/approved/paid/failed)；②三腿独立标记成功失败,任一失败不置零该腿、标 partial 人工续；③记账与"已确认到账"解耦两状态位。

## 🟡 应修级
### #3 ad_balance 并入净额 vs INV-1 隔离冲突
- INV-1(INVARIANTS.md:46)：广告费只动 ad_balance 永不碰 deposit_balance。净额合并三账户且"先扣未结佣金/罚款"没说从哪账户扣→若吃 ad_balance 抵佣金债=破隔离墙。退出结算是唯一把三账户混算处。
- 修复：明确"退出结算是 INV-1 受控豁免"记入 INVARIANTS；抵扣顺序精确到账户=未结佣金/罚款只从 deposit_balance 抵，ad/guarantee 各自独立全额退不参与抵扣(净额只是展示总和非可挪用池)。

### #4 混币抵扣按AMD折/退按币本位 套利+三列不足撑多笔混币
- deposit_balance 是 AMD 单值折算(2026_06_10_120000:52)；§4.3 USDT 币本位退。商家混缴 CNY+USDT 时未结佣金(AMD债)先抵哪币种未定；缴纳日vs退出日(20天+审批数周)汇率波动→商家挑有利币种抵扣把汇损甩平台。
- §5 三列 currency/original_amount/original_ref 是流水行级单值；多笔混币时 guarantee_balance 一个AMD汇总数无法还原"U几个/到哪地址/CNY多少"。NezhaRefundControl::reverse_lookup_from_address 天然一笔退款对一原址,不支持一汇总退多原址。
- 修复：①USDT份额原额原址全退不参与抵扣、佣金只从法币份额抵(与#3合流最干净)；②若坚持USDT可抵须按币种分列持仓 guarantee_balance_amd/_usdt+每笔USDT地址明细表；③汇率锚点按缴纳当日锁定不重折。

### #5 负数追缴无字段落地
- §3 净额<0"标欠平台X人工追缴"但挂哪字段/表没说。现网扣佣不设下限 deposit_balance 可为负(OrderLogic.php:279 无 max(0)),admin 专门统计 negative_count(Admin/NezhaDepositController.php:64)=负余额已知常态。
- 修复：欠款落持久字段(结算单 shortfall_amount+restaurants.offboard_status='owing')，owing 阻断重新入驻豁免押金；追缴SOP引用该字段留痕。

## 🟢 建议
### #6 归零点对账断裂+补标签
- anchoredBounds 以当前余额往回推；置0后归零前历史流水汇总≠0→对账页"期末0但区间净变动非零"断裂。修复：退还三笔 balance_after 精确写0；$typeLabels/导出$labels 补 deposit_refund/ad_refund/guarantee_refund 三个中文标签防空白。

## 攻不动(诚实)
1. §4 退押金≠二清定性稳(INVARIANTS.md:20 L1-1,两要件都不满足)。
2. §4.3 USDT红线稳+设施兜底：NezhaRefundControl::lock_route/reverse_lookup_from_address 从原始tx反查from地址锁死、后台无自由填地址入口(INVARIANTS.md:22-23 L1-3)。只要退款腿走 reverse_lookup+verify_refund_tx 而非超管手填地址,L1-3守得住。
3. 单路径扣佣/充值/退款并发在"只有订单路径"时安全(OrderLogic lockForUpdate 行锁)。风险是退出结算这新的跨三账户路径没进锁体系,非单路径本身。
4. RestaurantWallet::save() 不跨列覆盖(fillable=['vendor_id'],Eloquent 只写dirty列)→并发不会冲掉 guarantee_balance。真风险是净额读取时机(#1)非save覆盖。

一句话：政策合规判得对,但这还是"政策文档"不是"资金机制设计"——四个靶子(净额竞态/三腿原子性/币本位对账/负数闭环)留在§6当待压点,正文无锁/事务/状态机/幂等/跨账户抵扣规则。补齐#1#2前不具备写代码条件。
