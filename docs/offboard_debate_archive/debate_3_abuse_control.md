# 防薅/滥用/内控红队 — verdict: 🔴 阻断上线

核实代码事实:自助注册 VendorLoginController@register 与后台建店 Admin/VendorController@store 都只写 restaurants 行,无 self_registered/created_by/来路字段;审批 update_application 只翻 vendor.status+restaurant.status 不落来路。顾客退款 OrderController.php:1668/1717 对 delivered&&paid 单把终态翻成 refund_requested,唯一前置 refund_active_status(已启用LIVE)。举报 RestaurantReportController@store auth:api+日上限10+同店pending去重+10min限频,但一条pending就够冻结。风控 nezha_risk_records action=review status=pending 游客下单即可触发。对账读数 Vendor/NezhaDepositController:108/:150 是二元三目 $account==='ad'?ad:deposit,guarantee 默认落 deposit 分支。

## 🔴 阻断
### #1 无入驻来路持久字段→退出重入驻薅豁免+收取时机被绕
- 攻击:自助注册缴1000押金→经营→退出取回→换邮箱/手机(unique换号即绕)重入驻,超管onboarding无处知道"这人上次自助注册退过押金",凭话术设豁免→永久免押。变体:趁toggle关闭期走后台建店(默认豁免),开自助注册后这店永远"后台建店"来路永不补押。
- §2"不新造标志超管当刻按来路手动设档"假设了不存在的记忆载体(来路从不落库)。
- 修复:①落不可变 restaurants.onboard_source enum(self_register/admin_create)入驻当刻写死;②押金/退款历史按法人身份(KYC legal_name/id_doc_number/bank_account/USDT地址)跨vendor聚合非仅vendor_id,审批页显示"该主体历史退过N次押金";③重入驻用legal_name/证件命中历史closed_at红标。

### #2 退出前置门被外部人当武器→押金人质/竞对冻结
- 三门(订单终态/无pending纠纷/20天)触发权全在商家之外:
  - 2a 顾客对delivered旧单调refund-request(OrderController.php:1717 只要delivered&&paid就翻refund_requested)→终态门永久不满足,可反复制造。refund_request路无送达时间窗,只要退款开着任意delivered paid单随时拖回非终态。
  - 2b 一条举报(理由白名单有"价格欺诈")status=0→纠纷门不满足,要超管人工点掉(§3人工按需不加定时→不及时),日上限10换3小号=30条滚动冻结。
  - 2c 下一单触发风控review pending→纠纷门不满足。
- 竞对花几分钟+几小号让任意合规商家永远无法干净退出、押金拿不回=纯损失+平台声誉/法律风险。§3把三门当客观事实门,未意识每门触发条件都是外部可写状态。
- 修复:①门②改"无经初步甄别的真实相关pending纠纷"非"任何pending计数>0",给超管"标恶意/驳回"动作,驳回的不计入退出门;②refund_request对delivered单加送达后时间窗(复用nezha_appeal_window_hours),窗外不许拖回;③退出申请后新增纠纷走人工不自动阻断已进结算的退出(防第19天制造pending重置)。

## 🟡 应修
### #3 对账接入guarantee会串味落错余额(code证据)
- Vendor/NezhaDepositController:108/:150 二元三目 $account==='ad'?ad:deposit,新guarantee静默落else(deposit)分支→押金Tab显示预存佣金余额;anchoredBounds(:71)按currentBalance回推,锚错则期初期末流水全错却自洽=memory记的"balance_after串味"复发更隐蔽。写入侧rechargeDeposit(VendorController.php:718/728)balance_after写deposit_balance,三余额共表若guarantee写入没写对应列running balance互污。
- 修复:$account==='ad'?..:.. 全改显式三分支match($account),guarantee读guarantee_balance;balance_after按账户各写各列;新type中英标签$typeLabels+导出$labels同步补grep零残留;加对账自检(三账户balance_after末行==wallet对应列当前值)。

### #4 单人审批+free-text法币腿=一次误批/钓鱼/转错就送出押金
- 超管一人当日转+强二次确认只防误触不防被诱导确信操作,单点无4-eyes;钓到会话伪造高押金退出诱导点两次→打给攻击者。法币腿free-text肉眼读线下转,转错账户/金额/两次代码侧零防线(USDT有原址锁法币啥都没)。
- 修复:①动真钱异步二次闸(工单→冷却30min/次日→执行,留"不是我发起"反应窗),高档(5000)强次日转+邮件/TG二次确认链接;②法币腿回执上传+转出金额/账户末四位二次录入比对应退(≠则挡)标paid需附凭证;③审批页显示"退往收款主体==KYC核验主体"一致性检查。

### #5 超管手动设档无留痕无审计→寻租不可查
- onboarding无公式纯自由裁量,勾结时把5000档店设豁免/500,设档动作不写审计(store/update_application无"档位=X决策人=Y时间=Z"留痕),事后无从发现同类不同档;§7-③人工按需不加定时→高单量店敞口长期偏低无系统触发点暴露。
- 修复:①档位设定/变更写审计(决策人/档位/来路/理由/时间);②即使不加定时放后台被动红旗(单量/GMV超阈值但押金档=豁免/500的店在对账/风控页高亮,零定时成本纯查询)。

## 钻不动(诚实)
1. USDT换汇/落地中国/退第三方:§4.3+§5 usdt_address结构化+原址锁+复用NezhaRefundControl+币本位+L1-3进pre-push门,无出口。
2. 退押金≠二清:商家自有B2B退同一主体,不归集顾客钱不再分发,构成要件逐条不沾边。
3. 举报接口伪造身份/无限刷:user_id服务端取token不信body(防IDOR)+日上限+同店pending去重+限频。单账号刷被挡,2b只能靠"一条就够冻结"(根因在退出门设计不在举报接口)。
4. 负净额干净退出套现:§3明确不退标欠款人工追缴挡住(前提抵扣顺序真按实现,组⑤实现期验)。
5. 直接改DB提押金:走事务留痕+created_by记admin,商家侧无直改guarantee_balance API。
补充观察:nezha_deposit_below_threshold(OrderController.php:2307)用deposit_balance门控接单,退出清零后自动无法接单(与offboarded一致是好事);需确认退出流程先停接单再清零、不卡"能收单但余额已归零"窗口(组⑤实现期验)。

一句话:钱的定性和USDT腿稳可放心;但"来路快照缺失(#1)"和"退出门被外部人当人质(#2)"是结构性空子必须写代码前补设计,否则押金对惯犯失效+合规商家被竞对锁死押金。#3-#5内控加固可实现同期。
