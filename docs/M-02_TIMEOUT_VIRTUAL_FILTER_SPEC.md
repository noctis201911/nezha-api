# M-02 timeout virtual filter — implementation spec

> **状态：已实现（IMPLEMENTED）@ commit `58c1356`（2026-06-23，MERCHANT-M02-TIMEOUT-IMPLEMENT 窗口）。** 本文件为实现级规格。
> 落地与规格的一处有据偏离：`alertOrderIds()` 在参考实现基础上**额外镜像了 list 公共收尾的 `->HasSubscriptionToday()` 作用域**
> （连同 `Notpos()` + `restaurant_id`），以保证「卡计数 == list('timeout') 列表条数」在订阅单等边界也严格相等——
> 这是兑现 §0/§7.1「数字同源」主目标、闭合 §8 同类作用域漂移坑（与 NotDigitalOrder 同款），仍为纯只读、状态机零改动。
> 验证：构造 confirmed/processing/offline+凭证 三桶超时单 + 三类排除项（无凭证/info/配送），
> `alertOrderIds == list(timeout) == dashboard card = 3` 同源；真实 FPM 路由 `/list/timeout` HTTP 200、标题「超时单」、空态「暂无数据」、
> Playwright 桌面+移动端 console=0 / 无横向溢出。`git diff` 证 `NezhaOrderTimeout` 仅 +1 方法、现有方法零改动。
>
> 🔴🔴 **硬约束红线：本特性只新增「列表过滤 / 查询入口」，绝不修改 `NezhaOrderTimeout` 状态机
> （`phase()` / `describe()` / `clockStart()` / settings 阈值 / `OrderTimeoutSweep`）。**
> 允许在 `NezhaOrderTimeout` 上**新增**一个只读聚合方法（`alertOrderIds()`），但不得改动任何现有方法的行为。
> 若实现时发现非改状态机不可，必须停下、回到用户拍板，不得擅自改。
>
> 产品背景：M-01 已上线（商家 Dashboard 工作台「待办行动条 + 今日经营卡」，commit `92b1010`）。
> M-01 过渡期里「超时单」卡点进去落到 `timeout_list_key`（processing→cooking 等整桶 tab），数字与列表对不上。
> 产品已拍板采用 **B 方案**：新建「超时」虚拟过滤，让超时卡点进去 = 一个专门的 `timeout` 过滤列表，数字同源。
>
> 适用 repo：后端 `api.nezha.am`（Laravel 8.2）。所有行号基于 2026-06-23 工作树版本，实现前以实际为准。

---

## 0. TL;DR（给实现窗口）

新增一个虚拟过滤值 `timeout`，复用现有路由 `vendor.order.list` 的自由 `{status}` 段，**不加路由**。
落点 = 商家 Dashboard「超时单」待办卡。核心是 **数字同源**：超时卡的计数和点进去列表的条数，
必须用**同一个公式、同一个源**算出来——把口径抽成 `NezhaOrderTimeout::alertOrderIds()`，
Dashboard 和 list 都调它。

改动面（最小）：
- `app/CentralLogics/NezhaOrderTimeout.php`：**新增** 1 个静态方法 `alertOrderIds()`（不动现有方法）。
- `app/Http/Controllers/Vendor/DashboardController.php`：超时计数收敛到 `alertOrderIds()`；删过渡 hack `timeout_list_map`/`timeout_list_key`。
- `app/Http/Controllers/Vendor/OrderController.php`：`list()` 加 1 个 `timeout` 分支 + NotDigitalOrder 白名单加 `timeout` + 标题特判。
- `resources/views/vendor-views/partials/_todo-actionbar.blade.php`：超时卡 `href` 指向 `timeout`。
- 路由：**不改**。侧栏：**不改**。状态机：**不改**。

---

## 1. 当前 `OrderController@list` 的状态分支定位

文件：`app/Http/Controllers/Vendor/OrderController.php`，方法 `public function list($status, Request $request)` 在 **第 33 行**。

- 路由：`routes/vendor.php:254` — `Route::get('list/{status}', [OrderController::class, 'list'])->name('list')` → 名称 `vendor.order.list`。
  `{status}` 是**自由路径段**，新增过滤值 `timeout` **不需要改路由**。
- 状态分支全是 `->when($status == 'xxx', …)` 链：
  `searching_for_deliverymen`(46) / `confirmed`(49) / `pending`(52) / `cooking`→`processing`(59) /
  `accepted`(62) / `food_on_the_way`→`picked_up`(65) / `delivered`(68) / `ready_for_delivery`→`handover`(71) /
  `refund_requested`(74) / `refunded`(77) / `payment_failed`→`failed`(80) / `canceled`(83) / `dine_in`(86) /
  `offline_pending`(91) / `refund_pending`(99) / `scheduled`(108) / `all`(119)。
- 公共收尾（约第 145–151 行）：
  `->Notpos()` → 非 `offline_pending/refund_pending` 时 `->NotDigitalOrder()` → `->hasSubscriptionToday()`
  → `->where('restaurant_id', Helpers::get_restaurant_id())` → `->orderBy('schedule_at','desc')`
  → `->paginate(config('default_pagination'))`。
- 标题处理（第 153 行）：`offline_pending`/`refund_pending` 因无翻译 key 已被特判为中文「待确认收款」「待退款」，
  其余走 `translate('messages.'.$status)`。**`timeout` 同样无 key，必须比照特判**，否则页面标题显示 `messages.timeout`。

> 关键事实：**没有 `processing` 这个 list key**——processing 单走 `cooking` tab。这正是 M-01 过渡期
> `timeout_list_map=['processing'=>'cooking']` 的由来。

---

## 2. `restaurant_data()` 里 timeout phase/severity 口径定位

文件：`app/Http/Controllers/Vendor/DashboardController.php`，`restaurant_data()` 在 **第 90 行**；
超时聚合段在 **第 148–179、201–216 行**。这是「超时卡」当前数字的唯一来源：

```
$open = Order::with('offline_payments')
         ->where('restaurant_id', $rid)
         ->whereIn('order_status', ['pending','confirmed','processing'])
         ->Notpos()->get();
foreach($open as $o):
   phase = NezhaOrderTimeout::phase($o);                          if(!phase) continue;
   if(phase===PHASE_PROOF && !NezhaOrderTimeout::hasPaymentProof($o)) continue; // 未传凭证=等顾客付,商家无可为→剔除
   d = NezhaOrderTimeout::describe($o);
   if(!d || severity==='info') continue;                          // 只留 warning/error
   bucket: PHASE_ACCEPT→confirmed / PHASE_PREP→processing / PHASE_PROOF→offline_pending
timeout_target  = 第一条 alert 的 bucket（单桶聚焦,非聚合）     // 第179行
timeout_list_map= ['processing'=>'cooking']                       // 第202行 仅为把桶名翻成合法 list key
返回 timeout_total / timeout_order_ids / timeout_target / timeout_list_key  // 第213-216行
```

severity 与 phase 的权威定义在 `app/CentralLogics/NezhaOrderTimeout.php`：
- `phase()`（第 60 行）：`pending`+`offline_payment`→`PHASE_PROOF`；`confirmed`→`PHASE_ACCEPT`；
  `processing`→`PHASE_PREP`；`handover`/`picked_up` 仅 `delivery` 单→ D/E（配送阶段）；其余 `null`。
  **注意：`pending` 且非离线支付 → `null`，不算超时候选**（B 方案下顾客直付商家，单基本都是 offline_payment）。
- `describe()`（第 185 行）按各阶段阈值（`settings()` 第 32 行，读 `business_settings` 的 `nezha_timeout_*`，
  含默认 remind=5 / prep_orange=5 / prep_red=15 / handover=45 / picked=90 等）算出 `severity` = info/warning/error。

**消费现状（M-01 过渡 hack）**：`_todo-actionbar.blade.php` 第 62–74 行「超时单」卡，
`href` 指向 `route('vendor.order.list', [$nz_timeout_key])`（= `timeout_list_key`，processing→cooking 等）。
点进去落到整桶 tab（如全部备餐中单），数字与列表对不上——正是 M-02 要修的。

---

## 3. 新 `timeout` filter 应聚合哪些桶 —— 源码确认结果

**不是简单 `whereIn(order_status,[pending,confirmed,processing])`。** 那会把「未超时的正常单」和
「未传凭证的待付款单」也算进来，数字会远大于超时卡。

正确聚合 = **Dashboard 第 151–176 行算出的同一批单**，即同时满足：
1. `restaurant_id = 本店` 且 `Notpos()`；
2. `order_status ∈ {pending, confirmed, processing}`；
3. `phase() != null`（等价于：pending 必须是 offline_payment）；
4. 若 `phase==PHASE_PROOF`，必须 `hasPaymentProof()`（已传凭证）；
5. `describe().severity ∈ {warning, error}`（剔除 info）。

涉及「桶」= **offline_pending（已传凭证且超时）+ confirmed（待接单超时）+ processing（备餐超时）** 三类**并集**，
**不含** handover/picked_up（配送阶段 D/E 当前未纳入超时卡，保持一致；本期不扩）。

> ⚠️ 条件 4/5 含 **severity 判定，纯 SQL 表达不出**（依赖各阶段阈值 + 凭证检测）。
> 所以新过滤必须走「PHP 算出 ID 集合 → `whereIn('id',$ids)`」，不能写成纯 query scope。
> 开放单数量小（Dashboard 每次轮询已这样跑），成本可接受。

---

## 4. 侧栏 / 待办卡点击落点 URL 建议

- 落点 URL：`route('vendor.order.list', ['timeout'])` → `/vendor/orders/list/timeout`（路由 `{status}` 自由段，零路由改动）。
- 改 `_todo-actionbar.blade.php:64`：把 `route('vendor.order.list', [$nz_timeout_key])` 改成 `route('vendor.order.list', ['timeout'])`。
  `$nz_timeout_key` / `timeout_list_key` / `timeout_list_map` 三处过渡 hack 可同步删（见 §5/§6）。
- **侧栏：本期不动。** 当前 `_sidebar.blade.php` 未发现订单子 tab 链接，订单分桶导航主要靠待办卡。
  超时是动态告警量、非常驻分类，挂侧栏会变成长期占位（数字 0 时仍在），不符合「不造假红点 / 最小改动」。
  如确需侧栏角标，另立任务并复用 §5 同源计数。

---

## 5. controller 最小改动方案

**第一原则：数字同源。** 把 Dashboard 第 151–176 行算 ID 的逻辑抽成 `NezhaOrderTimeout` 的静态方法，
Dashboard 和 list 都调它——保证「超时卡数字」与「点进去列表条数」用同一公式、同一源。

### (a) NezhaOrderTimeout 新增共享方法（纯新增，不动现有方法 → 不触红线）

```php
/** 本店当前处于 warning/error 超时的开放单 ID（dashboard 卡 + list 过滤同源）。 */
public static function alertOrderIds(int $restaurantId): array
{
    $ids = [];
    $open = \App\Models\Order::with('offline_payments')
        ->where('restaurant_id', $restaurantId)
        ->whereIn('order_status', ['pending','confirmed','processing'])
        ->Notpos()->get();
    foreach ($open as $o) {
        $phase = self::phase($o);                                       if (!$phase) continue;
        if ($phase === self::PHASE_PROOF && !self::hasPaymentProof($o)) continue;
        $d = self::describe($o);
        if (!$d || ($d['severity'] ?? 'info') === 'info')              continue;
        $ids[] = $o->id;
    }
    return $ids;
}
```

然后 Dashboard 第 151–176 行改为调用它，`timeout_total`/`timeout_order_ids` 由这里产出
（bucket/minutes 明细如仍要展示可另留，但计数必须同源）。此步是「收敛同源」，可与 M-02 同批，也可先单独做。

### (b) list 新增分支（在 §1 的 `->when` 链里，紧跟 `refund_pending` 之后）

```php
->when($status == 'timeout', function ($query) {
    $ids = \App\CentralLogics\NezhaOrderTimeout::alertOrderIds(
        \App\CentralLogics\Helpers::get_restaurant_id()
    );
    return $query->whereIn('id', $ids ?: [0]);   // 空集用[0]保证返回空,不退化成全部
})
```

### (c) 放行 NotDigitalOrder

第 148 行 `!in_array($status, ['offline_pending','refund_pending'], true)` 要把 `'timeout'` 也加进去
→ `['offline_pending','refund_pending','timeout']`。
因为超时集合含 `pending+offline_payment` 单，`NotDigitalOrder()` 会把它们隐藏（与 offline_pending 同坑）。

### (d) 标题特判（第 153 行）

```php
$status = match($st) {
    'offline_pending' => '待确认收款',
    'refund_pending'  => '待退款',
    'timeout'         => '超时单',
    default           => translate('messages.'.$status),
};
```

### (e) 保留 restaurant_id 公共收尾

第 149 行的 `->where('restaurant_id', Helpers::get_restaurant_id())` 保留做纵深防御（权限，见 §8）。
即使 ids 已按 rid 算，也保留这层约束。

> 改动量：NezhaOrderTimeout +1 方法、Dashboard 收敛到该方法、OrderController 3 处
> （新 when 分支 / NotDigitalOrder 白名单 / 标题特判）。
> **不碰状态机本身**（`phase`/`describe`/`clockStart`/sweep 全不动），满足红线「只新增读取/查询入口」。

---

## 6. blade / sidebar 最小改动方案

- `_todo-actionbar.blade.php:64`：`href` 改为 `route('vendor.order.list', ['timeout'])`。
- 同文件第 11 行 `$nz_timeout_key = $nz_todo['timeout_list_key'] ?? 'pending';` 可删（不再使用）。
- `DashboardController.php:201-203` 的 `timeout_list_map` / `timeout_list_key` 过渡 hack 删除；
  返回数组里 `timeout_list_key` 项一并删。`timeout_target` 若 grep 确认无其它 blade 消费，也可删。
- `resources/views/vendor-views/order/list.blade.php`：**经确认没有硬编码状态 tab 导航条**
  （页面只渲染表格 + 标题，按 `$status` 文案展示），故**无需加「超时」tab**。
  但需确认空态有中文占位（见 §8 空态）。
- 侧栏：不动（理由见 §4）。

---

## 7. 验收清单（实现后按此验，本规格阶段未执行）

> 标尺：每条都要**真实证据**，HTTP 200 / build 成功不算验收。
> 商家端验收法见 memory `nezha-merchant-panel-ui-verify`（造 vendor 会话注入 Playwright，需 chown session）。

1. **数字同源（核心）**：同一时刻，Dashboard「超时单」卡数字 == `/vendor/orders/list/timeout` 列表分页 total。
   证据：两处截图 + `tinker` 调 `NezhaOrderTimeout::alertOrderIds($rid)` 的 `count()`，三者一致。
2. **集合正确**：列表里每一单逐个用 `describe()` 验 severity ∈ {warning,error}；任取一未超时的同状态单确认**不在**列表。
3. **桶并集**：构造 confirmed 超时 + processing 超时 + offline(已传凭证)超时各 ≥1 单，确认三类都进同一列表。
4. **剔除项**：pending+offline **未传凭证** 单不出现；pending+在线支付单不出现；
   info 级（未到阈值）单不出现；handover/picked_up 配送单不出现。
5. **标题**：页面标题显示「超时单」而非 `messages.timeout`。
6. **空态**：无超时单时列表显示中文空占位、不空白、不报错（whereIn 空集走 `[0]` 返空）。
7. **权限/越权**：A 店登录访问 `/list/timeout` 只见 A 店单；伪造他店 order id 不泄露（restaurant_id 双重约束）。
8. **状态机零回归**：`OrderTimeoutSweep` 命令、顾客端追踪页超时条、`describe()` 文案全不变
   （`git diff` 确认 NezhaOrderTimeout 仅 +1 新方法、现有方法零改动）。
9. **真机渲染**：Playwright vendor 会话截图，列表卡片移动端重排正常、console 0 错。
10. **多窗口**：改动只碰 §5/§6 列出的文件；提交前 `git diff HEAD -- <这些文件>` 确认无别窗 WIP。

---

## 8. 风险

- **数字同源（最高）**：Dashboard 卡与 list 必须共用 `alertOrderIds()`（§5a），否则两套并行逻辑必然漂移
  （M-01 过渡 hack 就是反例）。残留固有差：卡与列表在不同时刻渲染，某单可能在两次渲染之间跨过阈值，
  导致瞬时差 1——这是公式同源下的时间差，非 bug，验收按「同一时刻」判。
- **分页**：超时集合走 PHP 算 ID → `whereIn('id',$ids)` → 复用现有 `paginate(default_pagination)`，分页天然正确。
  开放单量小，PHP 预扫成本可忽略。空集必须用 `$ids ?: [0]`，否则语义不清/退化风险，明确给空。
- **排序**：当前公共收尾是 `orderBy('schedule_at','desc')`，超时单沿用即可与其它 tab 一致。
  **可选增强**（非必须，本期不做）：按「超期时长 desc」排更利于商家先处理最久的，但需 PHP 端排好 ID 再
  `orderByRaw(FIELD(id,...))`，增加复杂度，记为后续优化。
- **权限/越权**：`list()` 无 employee module 权限细分（仅 `details()` 有），超时 tab 继承与其它 tab 相同访问面，
  不新增越权面；但**必须保留** `where('restaurant_id', …)`（§5e）+ ID 集合本就按 rid 算，
  双重约束防 IDOR（参考 memory `nezha-applayer-idor-audit`）。
- **空态**：list.blade 需有中文空占位（如「当前没有超时订单」）。若现有空态是英文/StackFood 残留，需顺手中文化，
  属本任务可见范围，验收第 6 条覆盖。
- **NotDigitalOrder 遗漏（易踩坑）**：忘了把 `timeout` 加进第 148 行白名单 → pending+offline 超时单被
  `NotDigitalOrder()` 静默隐藏，列表数 < 卡数，直接破坏「数字同源」。这是与 offline_pending 完全同款的坑，
  已在 §5c 标明。

---

## 9. 实现窗口收尾约定

- 实现完成、Playwright 验收通过后：把本文件顶部状态行改为「已实现 + commit hash」。
- 若涉及商家可见操作/界面变化，同步 `MERCHANT_GUIDE.md` 相应章节（按 CLAUDE.md 文档维护约定）。
- 严守红线：`NezhaOrderTimeout` 只允许 +`alertOrderIds()`，任何现有方法行为改动一律停下找用户拍板。

_本规格作者：MERCHANT-M02-TIMEOUT-SPEC 窗口，2026-06-23。纯文档，不影响运行时，不需部署。_
