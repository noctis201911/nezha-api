# 哪吒 应用层安全 QA Playbook（SECURITY_QA_PLAYBOOK）

> 用户喊「应用层安全 QA / 像黑客一样查漏洞 / 越权排查」时走本文件。
> 与 `QA_PLAYBOOK.md`(产品体验 14 轴)、`OPS_QA_PLAYBOOK.md`(运维 10 轴)、`QA_FUNDLOOP_PLAYBOOK.md`(资金闭环)互补——本文件只查**代码层安全漏洞**(越权/注入/赋值/上传/鉴权/PII)。
> 运维层(限速/key/fail2ban/安全头/MySQL 加密)见 [[nezha-risk-control]]③,不在本文件范围。

起因:2026-06-16 首次应用层安全 QA 发现一整簇 IDOR(对象级鉴权缺口),既有的产品/运维/资金 QA 全都没覆盖——因为它们问的是"功能通不通 / 性能稳不稳 / 钱对不对",**没人问"这东西归不归请求者"**。本 playbook 就是补这块。

---

## 0. 工作法（开工先读）

1. **读线上活文件,不读 `.bak`、不靠记忆/旧 schema**。仓库在服务器,经 `node nz.js run "..."` 读。求证而非断言([[verify-over-assert-or-concede]])。
2. **侦察先行**:先读 `routes/api/v1/api.php` + `routes/vendor.php` + `routes/admin.php` + `app/Http/Middleware/` 画出"攻击面 + 谁挂了什么鉴权中间件",再逐轴深挖。
3. **要证据**:每个发现要么给出 `文件:行号 + 代码片段`,要么给出**非破坏性** curl 复现。
4. **不拿真实数据做利用**:不读真人 PII、不写脏数据、不动真实订单/聊天/地址。需要跨账户 PoC 时,用一次性测试账号/测试数据并**用完回退**([[dev-script-exhaust-blindspot]] 残留测试数据轴)。
5. **改前判等级**:任何资金/退款/合规/机制相关先查 `INVARIANTS.md`——L1 红线**禁止擅改、先报用户批准**;纯访问控制加固(加 user_id/参与者校验)通常是 L3。
6. **改法**:多窗口共享工作目录→服务端精确 `str_replace`(断言唯一)、**不整文件 push**([[zh-messages-whole-file-push-clobber]]);改前 `cp .bak.$(date +%Y%m%d%H%M)`、改后 `php -l`、精确 `git add` 本任务文件(别扫别窗口改动 [[git-am-sweeps-other-windows]])。
7. **输出**:分轴报告(每轴:有无问题/证据/分级/修复建议) + 给用户「亲手补验清单」(需真实账号那些)。

**分级**(安全严重度,独立于 INVARIANTS 的 L 级):
- 🔴 **HIGH**:未授权读写他人数据/资金、RCE、可枚举批量拖库、绕过鉴权。
- 🟡 **MED**:需登录但能越权改/删他人非资金数据、消息注入、信息泄露。
- 🟢 **LOW/加固**:防御纵深不足但无直接可利用路径(如仅扩展名校验、`$guarded=[]` 但当前不可达)。
- ⚠️ **合规旗标**:不是漏洞但触及 L1(如平台持币),单独列、交用户拍板。

---

## 轴 A — 对象级鉴权 / IDOR（最重要，本项目主战场）
**问题模式**:接口"按对象 id 查/改"却不校验"这东西归不归请求者"。
**怎么查**:
- grep `::find($request` / `findOrFail($request` / `where('id', $request` / `where('id', $id)`,逐个问"后面有没有跟 `user_id`/`guest_id`/`vendor_id` 的归属过滤"。
- 重点看 URL 带 `{id}` 的路由 + body 带 `*_id` 的写接口。
- **对照法**:同一 Controller 里有的方法带了 `user_id` 有的没带 = 八成漏了(本项目 `delete_address` 带、`update_address` 没带就是这么发现的)。
**要的证据**:`文件:行号` + 那句缺归属的查询。
**本项目已修**:`offline_payment`/`update_offline_payment_info`(H1)、`update_address`(M2)、`cart update/remove`(M3) — commit `fbc01d5`。
**已确认安全**:vendor/dm 订单接口用 `whereHas('restaurant.vendor', id=$vendor->id)` 正确圈定。
**仍要警惕**:任何**新加的"按 id 操作"接口**默认都假设它漏了归属校验,直到证明带了。

## 轴 B — 可枚举的凭证 / 认证会话
**问题模式**:用"可猜的值"当访问凭证(顺序自增 id、短随机、无签名 token)。
**怎么查**:
- 看 `guest_id`/`token`/`order_id`/`conversation_id` 这类既当"身份"又当"门票"的值**是不是顺序自增**。
- 看鉴权中间件是否**真的拒绝坏 token**(`APIGuestMiddleware` 曾有 `app('auth')->guard('api')` 恒真的写法——它不拒坏 token,只回退游客;对游客接口可接受,但要清楚它不是认证门)。
- 登录/验证码/firebase token 校验是否可绕。
**本项目已修**:`guest_id` 由顺序自增改成 **< 2^53 随机大整数**(H2,commit `6078b5c`)——枚举不可行,且前端 Number/JSON 不丢精度。
**注意**:再加任何"返回给客户端当凭证"的 id,务必随机且 JS 安全范围内(< 9007199254740991)。

## 轴 C — 跨租户 / 越权访问后台·商家功能
**问题模式**:商家能动别家店、骑手能动别人单、普通用户能打到 admin/vendor 路由。
**怎么查**:vendor/dm 接口里每个查询是否都绑了 `$request['vendor']->id` / `$dm->id`;路由分组中间件(`vendor.api`/`dm.api`/`admin`)是否覆盖到位、有没有漏挂的接口。
**已确认安全**:商家订单接口已正确圈定;后台另有 nginx Basic Auth 外层锁([[nezha-admin-basic-auth-lock]])。

## 轴 D — 注入（SQL / 命令 / 模板）
**怎么查**:grep `whereRaw|orderByRaw|selectRaw|havingRaw|DB::raw|DB::statement|DB::select|->raw(`,逐个看**有没有把 `$request` 的值字符串拼进去**。
**判定**:拼接用户输入=🔴;用 `?`/绑定参数或拼的是服务端常量(如 Carbon `->format('w')`)=安全。
**本项目结论**:Vendor Report 的 `DB::raw` 全是静态聚合 SQL;`VendorCategoryController` 的 `whereRaw` 用了 `?` 绑定 — 均安全,无注入。

## 轴 E — 支付 / 资金完整性（直付平台命脉）
**问题模式**:信任客户端传的金额/价格、付款凭证可被他人写、钱包可自充、佣金可篡改。
**怎么查**:
- `place_order`:`order_amount` 最终是不是**服务端按 DB 商品价重算**覆盖客户端值?(本项目是,安全)。每项单价取自 `Food` 模型还是请求载荷?
- 付款凭证(offline_payment)只能本人写?(已修 H1)。
- 钱包 `add_fund`:入账走**网关回调**还是本请求直接加余额?(本项目走 `wallet_success` 回调,不能自充)。
- 退款金额是否 ≤ 原单、是否只原路(L1-2/3,改动需用户批准)。
**⚠️ 合规旗标**:钱包/digital_payment 一旦开启=平台持币,与 **L1-1「平台不碰钱」**冲突,确认 B 方案下是关的。

## 轴 F — PII / 敏感数据暴露
**问题模式**:API 响应回传他人手机/地址/邮箱/支付凭证/聊天;日志打印 PII/密钥;错误回栈。
**怎么查**:看返回 `$conversation`/`$order`/`$user` 整对象的接口是否裹挟了不该给的字段(participant 的 phone/email);`info()/Log::` 是否打印敏感值;`APP_DEBUG` 是否 false(线上)。
**本项目已修**:聊天 `messages`/`dm_messages` 读越权(会话对象含参与者 UserInfo 的 phone/email)——加参与者校验(H3/dm,commit `fbc01d5`+`6078b5c`)。

## 轴 G — 文件上传
**问题模式**:可传可执行/标记类型(php/phtml/svg/html)致 RCE/存储型 XSS;只校验扩展名不校验内容;目录可执行 PHP。
**怎么查**:看上传走的 `Helpers::upload`→`validateFile` 的允许扩展名集合(`Constant.php` 的 `IMAGE/VIDEO/DOCUMENT/AUDIO/FILE_EXTENSION`);客户端可控扩展名是否被原样落盘。
**本项目结论**:白名单**不含 php/phtml/html/svg/js** → 无 RCE/SVG-XSS;jpg/png 还会重编码成 webp 抹载荷。🟢 加固项:仅扩展名校验(非内容嗅探),gif/webp/pdf 原样落盘可藏 polyglot,建议补 MIME 内容嗅探。

## 轴 H — 批量赋值（Mass Assignment）
**问题模式**:`Model::create($request->all())` / `->update($request->all())` / `->fill($request->all())`,或模型 `$guarded=[]`,让用户注入 `status`/`price`/`is_admin`/`user_id`/佣金等不该改的字段。
**怎么查**:grep `(create|update|fill|insert)\($request->all\(\))` + grep 模型 `guarded\s*=\s*\[\s*\]`。
**本项目结论**:仅 `NewsletterController::create($request->all())`(低敏感);`Food`/`ZoneDeliveryOption` 是 `$guarded=[]` 但仅 vendor/admin 鉴权后用显式数组,🟢 建议改 `$fillable`。

## 轴 I — 速率限制 / 滥用
**怎么查**:登录/下单/发券/发消息/上传/枚举型接口是否挂 `rateLimiter`;有没有"可枚举但没限速"的接口(枚举 + 无限速 = 拖库放大器)。
**本项目**:auth/place/newsletter/merchant-lead/support 已挂 rateLimiter;全局 1200/min/IP。

## 轴 J — 密钥 / 配置 / 临时脚本泄露
**怎么查**:grep 硬编码密钥/密码/token;`.env` 是否 web 可达;`staticCredentials` 是否被误部署(本地空会覆坏线上 Google 登录,**绝不部署**);git 历史扫密钥;**本机 `~/*.js` 一次性脚本**是否堆积明文凭证([[dev-script-exhaust-blindspot]])。
**铁律**:脚本从 env 读凭证、只读、**用完即删**。

## 轴 K — 业务逻辑滥用 / 状态机
**问题模式**:优惠券重复用、负数/超大 quantity 绕限购、refund 后再 cancel、改 payment_method 重置状态白嫖、并发双花。
**怎么查**:对每个"改状态/给优惠/扣库存"的接口,想"如果我重放/并发/乱序调用会怎样";关键写操作是否在事务 + 是否校验当前状态合法迁移。
**关联**:资金状态机滥用走 `QA_FUNDLOOP_PLAYBOOK.md`。

---

## 收尾(汇报"查完/修完"前必走)
1. 逐轴给结论(有问题/无问题/加固建议),发现项标 🔴🟡🟢⚠️ + `文件:行号`。
2. 修复项:`php -l` 通过 + 非破坏性 curl 验证 + 备份在 + 精确 commit(写清"不改资金/合规机制" or 触及 L1 已获批准)。
3. 临时脚本删干净、测试数据回退干净。
4. 给用户「亲手补验清单」(需真实账号/真实他人对象的跨账户 PoC)。
5. 触及合规的同步 `docs/compliance/CHANGELOG.md`;残留未修项明确列出交接([[deliver-complete-one-shot]])。

## 已知残留(滚动维护，修一条删一条)
- 暂无(2026-06-16 首轮 H1/H2/H3/M1/M2/M3 + dm_messages 已修)。
- 复查重点:任何**新增的"按 id 操作"接口**默认按轴 A 重审。

### 2026-06-20 第二轮(commit ecd35d7) — 已修
- **订阅 IDOR 簇(潜伏)**:`OrderSubscriptionController` 的 show/edit/update/update_schedule 4 个 by-id 方法原**零归属校验**(auth:api 下任意登录顾客可读他人订阅+订单收货地址PII / 取消/改他人订阅)。当时 `subscriptions=0`、功能未启用故潜伏(启用即🔴)。**前端有订阅 UI 在用**(subscription-details 组件树+react-query/subscription hooks),故**加 `where('user_id', auth id)` 作用域而非拔路由**(对齐同控制器 index() 既有正确模式)。
- **骑手评价越权写**:`DeliveryManReviewController::submit_review` 原不校验 order 归属本人/该骑手是否真送此单 → 可伪造骑手评分。补订单归属校验(对齐 `ProductController::submit_product_review` 的 nz_order_owned)。注:该端点在 `actch:deliveryman_app` 激活闸后(实测 503),可达性本就低,此为纵深加固。
- **🟢 加固**:`update_payment_method`/`order_notification` 补 is_guest 过滤(原靠 H2 随机 guest_id 不碰撞兜底,不可利用但不一致);`get_order_details` 未找到改 200→404;删 `CustomerController` 未挂路由的死代码 `get_order_list`/`get_order_details`(按 order_id 取 OrderDetail 无归属,虽不可达仍清除防误接线)。
- **覆盖局限(诚实)**:订阅作用域为**静态(where user_id)+动态不破坏(show/edit/update→404、index/order.list→200、无 500/无泄露)**验证;因订阅 0 行,**未能动态区分"owner 得 200 vs 非 owner 404"**。双账号正向跨账户 PoC 未做(仅 1 个真实测试账号 user6,只做了负向对照)。place_order 全量(轴 E/K)、vendor/dm 端本轮未重审。

### 2026-06-20 第三轮 轴E(资金完整性)+轴K(业务逻辑) place_order 审计(commit ecd... 见 e48ffe5)
**确认安全**:order_amount 客户端值(L228占位)被服务端 L537 重算覆盖(DB商品价×qty+加料-商家折扣-券-tax+配送+小费),商品单价取自 Food DB,商家折扣/税服务端算,起送价(券后)服务端强制,add/update_cart quantity min:1。**已修(commit e48ffe5,L2业务规则不碰L1)**:
- **折扣券 min_purchase 下单未强制(现行可绕,已修)**:`Helpers::coupon_check` 调 `CouponLogic::is_valide` 只传3参(order_amount=null)→最低消费(409)死分支;6个active券全设门槛2000-5000。place_order 算出购物车口径后补 `if($coupon->min_purchase>0 && basis<min) rollback+403`。**动态实测**:SICHUAN500(min3000)打300元单→**403 coupon**(修前会放行)。
- **buy_now(活动商品)数量无下界(已修)**:`is_buy_now==1` 走 `$request['cart']` 绕 Cart 表 min:1;foreach 补 `>=1 正整数`校验。**动态实测**:qty=-2→**406 quantity**。两守卫 reject 前 DB::rollBack 不建脏单(实测订单数不变)。
- **优惠券每人限领并发race(轴K TOCTOU,已修 0613faa)**:`Order::where(user_id,coupon_code)->count()<limit`(及 first_order 的 `Order::where(user_id)->count()`)在order commit前→并发双提交可超限。修法(最省,非Cache::lock):place_order 事务内、coupon_check **之前**对券行 `\App\Models\Coupon::where('code',$code)->lockForUpdate()->first()`,串行化"同一张券"的并发下单→使 is_valide 的 count() 在锁内权威;锁随 commit/rollBack 释放,只锁同券不影响别券。**验证**:①代码评审确认锁在事务内、coupon_check 在锁后;②真事务对 NEZHA-NEW(limit=1,first_order)跑 lockForUpdate 返回券行无错+干净rollback;⬜并发双提交 E2E 未确定性复现(需同用户并发fire,InnoDB FOR UPDATE 串行化属已知行为,未压测)。

- **free_delivery 型券 min_purchase 下单未强制(已修 345fab4)**:coupon_check 对 free_delivery 券**先置 $coupon=null 再设 delivery_charge=0**(发生在购物车金额已知前)→ #1 的内联检查(基于$coupon)覆盖不到→可低于门槛白嫖免运费(FREESHIP min=2500)。修法:coupon_check 回传 `free_delivery_min_purchase`;place_order 按购物车口径(与折扣券同源 basis)补 `if($free_delivery_by && min>0 && basis<min) rollback+403`(不回退运费;该处 $free_delivery_by 只可能来自券,admin/vendor 免运费规则 L520+ 才评估)。**动态实测(user6/店6/FREESHIP·经真实 HTTP 下单)**:food32×1(basis 300<2500)→**403 coupon**「未达最低起送金额 2500 AMD」;food32×9(basis 2700≥2500)→**200 下单成功**(门不误伤,免运费券照常生效)。测试单已删+券 total_uses 复位 0。

**开放残留(本轮未修,待评估/批准)**:本簇(place_order 券/数量/资金完整性)暂无未决项。

**覆盖局限(诚实)**:place_order 资金链路+券逻辑+数量为静态+关键路径动态(min_purchase/数量 reject 实测);正向≥门槛 E2E 已补(free_delivery 券 food32×9 basis2700→200·测试单已清);钱包/partial/digital 路径只扫入口未深查(B方案应关);退款金额上限/原路(L1-2/3 NezhaRefundControl)未重审;并发race仅静态推断未压测。

### 2026-06-21 安全架构化(轴A 结构守卫 + 轴E 前端值不读) — 已实施
- **轴A 从"每次人工审"升级为"CI 防回归门"**:新增 `App\Traits\OwnedByCustomer::forCustomer($id[,$isGuest])`(owner 为 null 即抛异常,绝不退化成全表)+ `tests/Feature/NezhaIdorGuardTest.php`(扫顾客 API 命名空间,凡对归属模型 Order/CustomerAddress/Cart/Conversation/Subscription… 做裸 by-id 查询而**同方法内无任何归属过滤 token**(user_id/guest_id/forCustomer/sender_id/receiver_id/auth_token/whereHas/`// idor-ok`)即 fail)。**新写顾客 by-id 接口默认就被守卫盯**;合法跨查在该行加 `// idor-ok: <理由>`。
  - **方法级粒度局限(诚实)**:方法体里别处恰好有归属 token 时,本方法内的裸查询不会被拦(false negative)。它拦的是"整段方法毫无归属概念"的新代码;语义级精确鉴权仍需人工审本轴。
  - 已加固:`CustomerController::set_default_address` 末句裸 `CustomerAddress::where('id')->update` → `forCustomer($userId)->where('id')`。Order/Cart/CustomerAddress 已 use OwnedByCustomer,其余顾客归属模型可增量采用。
- **轴E place_order 前端 order_amount 已彻底不读**:删早赋值(原本被服务端重算覆盖),校验改 nullable;真实下单谎报 order_amount=1 → 存库 4500 验证客户端值被丢弃。
- **风控入参客户端信任缺口(已修 e3a1575)**:`NezhaRiskController::build_context` 早评读客户端 `$request->order_amount`,顾客低报可绕单笔/大额风控。修法:place_order 在服务端金额重算定型后(save前·事务内)用权威金额**复评一次风控**,命中 reject/review 即 rollBack+记审计(record 在 rollBack 后调用防审计被回滚)+返回 risk_reject/risk_review。**真单实测**:低报 order_amount=1 + 真实 qty25=112500>单笔限110437 → risk_reject「超过单笔上限」(客户端值1不可能触发=只能来自复评)·未建单·审计持久; 正常 qty1=4500 下单成功(不误伤)。仅改"用哪个金额评估"(客户端→服务端权威),阈值/开关未变。

### 2026-06-22 第三轮 vendor 端多租户越权簇(commit 085e1b0 + 另窗 a487ff8) — 已修
补上轮明确标注未审的 **vendor 端**(轴 A/C/F)。根因同既有 IDOR 簇:商家 web 面板 by-id 改写不校验 restaurant 归属。**隔离 PoC 双轮坐实**(throwaway 数据标记假店 999001,攻击者=demo vendor#6,绝不碰真数据;修前 6/6 VULN,修后 6/6 BLOCKED 且 rest6 真顾客 getUserData 不误伤)。
- `CouponController` status/delete:加 where(restaurant_id)+null 守卫(原任意商家启停/删他店券)。
- `AddOnController` status/delete:同上(他店加料)。
- `Vendor/OrderController::updateSchedule`:订单查询加 restaurant_id+404 守卫(原改他店订单 schedule_at+触发推送给他店顾客)。
- `POSController` getUserData/getUserAddress/chooseAddress/editAddress:顾客信息仅返回"在本店下过单的顾客"(原按 id 泄露任意用户 phone/email/wallet 可枚举;POS 实测 0 使用故非破坏)。
- `DeliveryManController` status/earning/update/delete/edit:find 加 restaurant_id(delivery_men=0+module gated,潜伏加固)。
- 另窗并发 a487ff8:`Vendor/OrderController` add_order_proof/remove_proof_image 也补 restaurant_id(凭证图越权)。
- **未覆盖(诚实)**:轴 B/I/K/E 全量本轮沿用旧结论未重跑;vendor WalletMethod/Wallet 提现 by-id 未深核(B方案平台不放款,低优)。网络/主机层审计另见 memory nezha-network-security-audit。
