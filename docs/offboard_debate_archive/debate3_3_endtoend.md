# DESIGN debate 第三轮 — 端到端/边界/运营红队 — verdict: 有7断点(3🔴死循环+4🟡+1🟢)不可进代码,需补状态回流矩阵
元判断:v2把钱怎么算对/不重复退/隔离焊死了(账目可信),但端到端走=**只有正向没回流的单向管道**,settling/kyc_pending/owing三状态全"进得去出不来",配"设档不核缴/C5语义错位/单超管跑不动14步"=3真死循环+4断点。

## 🔴 死循环
### 1 缺cancel/withdraw路径—商家applied就回不到active,误点=永久停业(死循环根)
商家点"申请退出"→settling(停接单停扣佣立即生效)→反悔想经营→**查遍v2无任何边把settling改回active**。状态机5出口(approved/rejected/paying/paid/owing)全是"继续往退出走"。rejected是超管驳回置active_uniq=NULL但offboard_status是否回active/店能否重接单§B没写。§A5备注写"撤回重提不重置cooldown"=设计者假设撤回存在但状态机没画这条边。
- 修:状态机加边 applied/kyc_pending──(商家撤回or超管取消)──►active,同时①offboard_status回active解冻②active_uniq=NULL③status加终态withdrawn;仅approved前可撤(快照锁定放款后不可撤)。P0。

### 2 kyc_pending+KYC review拒绝=永久停业无出口
存量7店全kyc_status=none必走KYC补录。applied→无approved KYC转kyc_pending(此刻offboard_status已settling停业)→超管录KYC审核→**证件有问题review判rejected**。实核NezhaKycController::review:124 rejected→kyc_status='rejected'写closed_at→**永非approved**→§D2门"approved才放行"永过不去→店卡kyc_pending+settling=停业+退不了+无路。KYC拒绝真实会发生(证件过期/户名对不上/疑似制裁)v2当它不发生。
- 修:定义kyc_pending失败出口—KYC rejected时settlement转rejected且offboard_status回active(店恢复营业,身份没核成功不给退但不能停死)。

### 3 owing追缴无终点—net<0进limbo永不复出
deposit被佣金扣负→net<0→owing(§J4只写"人工追缴")→**owing是终态无出口边**。追缴成功流转到哪§B没画;失败则永远owing+settling=退不了(net<0)+经营不了(settling)+无"结清销户"终态=纯limbo。老赖:法人A开店1恶意刷deposit负30万→申请退出owing→跑路→同法人A开店2继续经营,§E2指纹**只红标非硬闸**→老赖换vendor号(同法人)照开新店红标了也可放行=**欠款对同法人其它vendor无硬约束**。§J4自列未解。
- 修:①owing加两出口 owing──(追缴到账超管store_recharge补平)──►回paying或销户;追缴长期失败──►written_off坏账核销终态(记审计店永久offboarded不占号)。②法人级欠款硬约束:同fingerprint/legal_name有未结owing时新入驻或该法人其它vendor退出至少超管强确认(比红标重一档)。

## 🟡 断点
### 4 设档vs缴纳"两动作无核对闸"—可设5000档只缴500开业
onboarding超管手动设档(PLAN §2豁免/500/1000/5000),缴纳store_guarantee(§B')是另一动作,**两者无核对闸**。设5000档商家只转500超管记500→**开业闸nezha_store_paused只看offboard_status/temp_closed不看"实缴≥所设档"**→照常营业风险敞口1/10。系统无"应缴档位"字段,商家可一分不缴开业。押金作风控工具地基虚。
- 修:①轻:restaurants加guarantee_tier(应缴档)缴纳页显示应缴/实缴/缺口超管可核对;②重:实缴<应缴则nezha_store_paused返true。①足够但必须补否则"设档"是空话。

### 5 C5"强制settle_delivered跑完在途单"是语义误解
实核settle_delivered(OrderLogic:412)前置`if(delivered!=null || !in_array(order_status,['handover','picked_up'])) return false`→**只把handover/picked_up推进到delivered,已delivered的第一个条件delivered!=null就return false**。所以:①"delivered-but-unsettled单"根本不是settle_delivered处理对象(该用create_transaction),描述与函数语义对不上,net算错;②前置门①要"所有订单终态"但handover/picked_up/cooking/accepted在途单非终态,商家settling停接单这些单靠谁推进?B方案无骑手点送达只顾客确认或auto-finalize cron(bootstrap:117 hourly)兜底;顾客不确认只能等cron。并发§J3自点未解:C5首步settle_delivered与hourly auto-finalize同时对同批在途单调settle_delivered,幂等靠OrderTransaction::exists:416兜方向对但"需实测"没设计防线。
- 修:①C5措辞对齐—"首步对handover/picked_up在途单调settle_delivered;非该态的活跃在途单前置门①直接挡applied要商家先处理完";②真delivered-but-unsettled漏结单用create_transaction补;③门要前移(C4安全网只在paying→paid触发,进不了approved轮不到)。

### 6 re-onboard与老店offboarded数据关系没理清(呼应§J1+指纹)
paid→老店offboarded/active_uniq=NULL→同法人半年后重入驻是建新vendor还是复活老restaurant_id v2没说。换证件类型(护照→身份证)normalize_doc_number按id_doc_type分域前缀(passport: vs national_id:)**必生成不同HMAC→漏匹配**,§E2自认"漏匹配>误匹配"→换证重入驻历史红标必漏。§J1未解确认。
- 修:明确re-onboard=新vendor(老店保留offboarded存档),入驻KYC页跨vendor关联提示按legal_name+手机号+fingerprint多信号(不只单一fingerprint)降换证漏匹配。

### 7 单超管=单点,一店退出跨20+天14步跑不动
唯一超管要:收申请→录KYC→审KYC(可能拒回断点2)→store_guarantee补记→等20天→审批当刻重跑制裁re-screen(possible转人工AML自己跟)→逐字核对legal_name==holder==付款人→勾holder_verified→**高额5000双人复核(只有一超管"双人"从何来?§J2真问题)**→线下转账→回填payout_ref→触发置零→partial逐腿补→net<0转owing自己追缴(断点3无终点)。§H"次日转+TG二次确认"=同人隔天点两次≠双人(防不了同人被诱导/盗号)。
- 修(多是运营决策非代码):①holder高额双人在单超管下降级"强制次日转+独立渠道TG二次确认+审计双时间戳",§D3/§J2明写"单超管现实双人=时间隔离+渠道隔离不追求真双人";②给超管退出工作台(一店14步进度可视化)防漏步。

## 🟢 前瞻
### 8 与"平台垫付赔顾客"追偿(L1-8同骨)接缝—net没扣这笔
平台对商家有垫付追偿未结(另L1项目追偿腿=L1-8同骨),net=guarantee+deposit+ad(§F)无追偿减项→商家惹赔付平台垫钱正要追偿→商家抢先退出net正数→押金全退→追偿落空。退出是平台最后"钱在手"时刻=追偿天然扣款点v2没预留钩子。
- 修(前瞻不必现在做):net预留pending_clawback减项接口(现恒0),§F/§J记一笔"net未含垫付追偿该项目落地须回来改net"别让接缝无人认领。

## 闭合(诚实6条不凑数)
1. 对账guarantee Tab缴纳/退款流水自洽:anchoredBounds(Vendor/NezhaDepositController:71)正为"缴了又清零"设计,缴1000退1000期初期末对平不断裂✅。
2. 三腿原子性+逐腿幂等(§B partial+C4快照)照抄ChargeAdOnStart:81范式✅方向对(前提能走到paying)。
3. 资金隔离INV-1(§F各退各账+ad_refund不撞test_9)✅。
4. 制裁门实时re-screen(§D1不读stale screen_status/两道/possible fail-closed)修对K-C✅。
5. 停接单/停扣佣双接缝正交(§C1/C2不碰commission_active,:276扣佣门与:2318停接单门两独立判断)修对v1接缝错✅。
6. 置零+负流水balance_after=0精度(§G)✅。

一句话:v2账目层可信(钱算对/不重复退/隔离焊死),但端到端=只有正向没回流的单向管道,settling/kyc_pending/owing三状态进得去出不来=3真死循环+4断点。修法核心一句:给状态机补全"回流边"矩阵(撤回→active/KYC拒→active/owing→销户或补平)+C5措辞对齐settle_delivered真实语义。补完可进代码;不补=商家误点一次永久停业+老赖换法人马甲带款跑路。
