<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaBadReview;
use App\CentralLogics\NezhaCustomerNudge;
use App\CentralLogics\NezhaOrderCounts;
use App\CentralLogics\NezhaOrderNextAction;
use App\CentralLogics\NezhaOrderTimeout;
use App\CentralLogics\NezhaPreorder;
use App\Http\Controllers\Controller;
use App\Models\NezhaRefundRecord;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒作业台(今天 · Workbench) —— W1: summary 只读数据契约。
 *
 * 商家登录后「此刻我该干嘛」首屏的数据源。纯 L3 只读聚合, 全部复用既有单一真相源:
 *  - 计数 : NezhaOrderCounts(与侧栏徽标 / 订单页组 tab 同源 → 满足三合计断言 DoD#1)
 *  - 每行主 CTA : NezhaOrderNextAction::decide()(与详情页 M-04 同源, 禁止在此写第二套 if/elseif → DoD#3)
 *  - 差评预警 : NezhaBadReview ; 今日经营 : DashboardController::nezha_today_sales(已确认收款口径, 与对账一致)
 *
 * 红线自查: 不碰 confirm / payment / 退款 / L1 机制; 不新增任何写路径; 复用既有确认弹层/叫车抽屉/详情退款卡端点。
 * **绝不触碰 checked 标记**(list() 进页会把 checked=0→1, 那是新单响铃口径; summary 只读不得误清 → ring 由 W4 心跳独立处理)。
 *
 * W1 = 惰性: 仅本控制器 + 一条只读路由, 未被任何页面调用 → 上线零行为变化。
 */
class WorkbenchController extends Controller
{
    /** 每队列最多直显行数(超出 → 「还有 N 单 · 查看全部」跳订单页对应筛选)。 */
    const ROWS = 5;

    /**
     * W1 只读接口: GET vendor/workbench/summary 。返回作业台完整数据契约(JSON)。
     * 未接线, 供 W2 首屏渲染 + W4 6s 心跳刷新共用同一契约。
     */
    public function summary(Request $request)
    {
        $rid = (int) Helpers::get_restaurant_id();

        return response()->json(self::buildSummary($rid));
    }

    /**
     * W2: 作业台首屏页面。默认落点暂不切(dashboard 保留), 经侧栏「今天」入口进入(§6.1 裁决: 并存试用)。
     * 与 summary 接口共用 buildSummary() 同一数据契约, 防两处口径 drift。
     */
    public function index(Request $request)
    {
        $rid = (int) Helpers::get_restaurant_id();
        // 在场感知: 总闸开时给作业台打在场心跳(叫车推送据此抑制·参考 vendor chat nzViewing)。dormant 不打点。
        if (NezhaPreorder::enabled()) { NezhaPreorder::markWorkbenchSeen($rid); }
        $wb = self::buildSummary($rid);
        $dispatchOrders = self::dispatchOrdersFor($wb);

        return view('vendor-views.workbench.index', compact('wb', 'dispatchOrders'));
    }

    /**
     * 哪吒 P3 接单机模式: 图文指引页(把闲置手机/平板设成常开接单机)。纯静态说明, 仅父级 vendor 鉴权, 无写路径/无副作用。
     */
    public function guide(Request $request)
    {
        return view('vendor-views.workbench.guide');
    }

    /**
     * 哪吒 自动下线: 商家作业台「恢复接单」一键(自助)。清本店 nezha_auto_offline 标记(与钱无关·独立于退款逾期挂起)。
     * 点这个动作本身即证明商家已在场; 恢复后立即恢复接新单。仅父级 vendor 鉴权, 只清本店标记(作用域=当前登录店)。
     */
    public function recoverAutoOffline(Request $request)
    {
        $rid = (int) Helpers::get_restaurant_id();
        if ($rid) {
            \App\CentralLogics\NezhaAutoOffline::recover($rid, 'self');
        }
        return redirect()->route('vendor.workbench.index')->with('success', '已恢复接单，顾客现在可以正常下单了。');
    }

    /**
     * W4: 作业台可刷新分区(_body)的 HTML 片段。并入全局 6s 心跳(app.blade poll)刷新, 不另开轮询。
     * 与 index() 共用 buildSummary() + dispatchOrdersFor() 同一契约; 返回无 layout 的 _body partial(供 JS 换入 #nzwbRefresh)。
     * 纯只读: 与 summary/index 同源, 不写 checked / 不改状态 / 无副作用。
     */
    public function refresh(Request $request)
    {
        $rid = (int) Helpers::get_restaurant_id();
        // 在场感知: 6s 轮询刷新即在场心跳(总闸开时·叫车推送据此抑制不推、只亮横幅)。dormant 不打点·纯只读。
        if (NezhaPreorder::enabled()) { NezhaPreorder::markWorkbenchSeen($rid); }
        $wb = self::buildSummary($rid);
        $dispatchOrders = self::dispatchOrdersFor($wb);

        return view('vendor-views.workbench._body', compact('wb', 'dispatchOrders'));
    }

    /**
     * 叫车抽屉源 —— ②备餐(processing) + ③待叫车(handover) 行对应订单模型, 供 _dispatch_tools 渲染
     * (复用订单列表页同款「叫车底部抽屉」·同一 partial, 不造第二套写路径)。只取本页已显行的 id, 逐一有源。
     * index() + refresh() 共用, 防两处 drift。
     */
    protected static function dispatchOrdersFor(array $wb)
    {
        $dispatchIds = array_merge(
            array_column($wb['queues']['cooking']['rows'] ?? [], 'id'),
            array_column(array_filter($wb['queues']['delivery']['rows'] ?? [], fn ($r) => ($r['stage'] ?? '') === 'handover'), 'id')
        );
        // screen05 单点版: 预约「该叫车」单(confirmed/handover 均可·[叫车]折叠出餐)并入叫车抽屉源(复用同一 partial·不造第二套写路径)。
        $dispatchIds = array_merge($dispatchIds, $wb['preorder']['dispatch_ids'] ?? []);
        $dispatchIds = self::uniqInts($dispatchIds);

        return $dispatchIds
            ? Order::with(['restaurant', 'details'])->whereIn('id', $dispatchIds)->get()
            : collect();
    }

    /**
     * 作业台数据契约单一构造器(summary 接口 + 未来 W2 首屏 blade 共用同一函数, 防两处口径 drift)。
     * 只读: 不写 checked / 不改任何订单状态 / 不产生任何副作用。
     */
    public static function buildSummary(int $rid): array
    {
        $counts  = NezhaOrderCounts::forRestaurant($rid);
        $rateCny = (float) (DB::table('business_settings')->where('key', 'nezha_rate_cny_to_amd')->value('value') ?: 55);
        $rateUsd = (float) (DB::table('business_settings')->where('key', 'nezha_rate_usd_to_amd')->value('value') ?: 400);

        // ── 需动作总览条: grp_action 的组件分区。──
        // action.total = grp_action = 侧栏徽标 = 订单页需动作组 tab(三合计断言 DoD#1 的唯一口径)。
        // 组件(offline_pending / 催促∪超时 / 待退款 / 退款申请中)供总览条 4 枚胶囊「分类数」展示;
        // 注意: 待叫车(handover) 不属 grp_action —— 见交接包待定项, 故意不放进 action, 它归队列③。
        $nudgeIds   = NezhaCustomerNudge::openOrderIds($rid) ?: [];
        $timeoutIds = NezhaOrderTimeout::alertOrderIds($rid) ?: [];
        $nudgeOrTimeout = count(self::uniqInts(array_merge($nudgeIds, $timeoutIds)));

        $action = [
            'total'            => (int) $counts['grp_action'],       // 唯一徽标口径(按单去重的并集)
            'offline_pending'  => (int) $counts['offline_pending'],
            'nudge_or_timeout' => (int) $nudgeOrTimeout,
            'refund_pending'   => (int) $counts['refund_pending'],
            'refund_requested' => (int) $counts['refund_requested'],
        ];

        // ── 五队列(每队列前 5 行 + total==provider 计数, DoD#2) ──
        $queues = [
            'confirm_payment' => self::queueConfirmPayment($rid, $counts, $rateCny, $rateUsd),
            'cooking'         => self::queueCooking($rid, $counts, $rateCny, $rateUsd),
            'delivery'        => self::queueDelivery($rid, $counts, $rateCny, $rateUsd),
            'nudge_timeout'   => self::queueNudgeTimeout($rid, $nudgeIds, $timeoutIds, $nudgeOrTimeout),
            'refund'          => self::queueRefund($rid, $counts, $rateCny, $rateUsd),
        ];

        // ── 右栏数字 ──
        $sales = DashboardController::nezha_today_sales($rid);   // {orders, collected} —— collected=已确认收款口径
        $bad   = NezhaBadReview::summary($rid);                  // {bad_review_count/days/from/to} 深链同源
        $avg   = $sales['orders'] > 0 ? (int) round(((float) $sales['collected']) / $sales['orders']) : null;

        $rail = [
            'bad_review' => $bad,
            'today'      => [
                'orders'     => (int) $sales['orders'],
                'collected'  => (float) $sales['collected'],
                'avg_ticket' => $avg,   // 客单价 = 自收款 / 今日单量(与今日经营卡示例同口径; collected 为已确认收款)
            ],
        ];

        // W5: 店态胶囊三档(营业/忙碌/暂停接单)+ 时长。忙碌=仍接单挂横幅; 暂停=nezha_temp_closed(店可见+休息中+拦单)。
        //   mode_enabled=灰度总闸(关时前端退化为两档营业/暂停·与旧版一致)。
        $sr = DB::table('restaurants')->where('id', $rid)
            ->first(['nezha_temp_closed', 'nezha_pause_until', 'nezha_busy_until', 'nezha_busy_min', 'nezha_busy_reason', 'nezha_auto_offline', 'nezha_auto_offline_reason', 'nezha_auto_offline_at']);
        $srBusy = $sr && $sr->nezha_busy_until && \Carbon\Carbon::parse($sr->nezha_busy_until)->isFuture();
        $store = [
            'temp_closed'  => (bool) ($sr->nezha_temp_closed ?? 0),
            'busy'         => (bool) $srBusy,
            'busy_min'     => $srBusy ? (int) $sr->nezha_busy_min : null,
            'busy_reason'  => $srBusy ? $sr->nezha_busy_reason : null,
            'pause_until'  => (($sr->nezha_temp_closed ?? 0) && $sr->nezha_pause_until) ? $sr->nezha_pause_until : null,
            'mode_enabled' => (int) \App\CentralLogics\Helpers::get_business_settings('nezha_busy_mode_status') === 1,
        ];

        // 哪吒 自动下线(长期不确认订单被自动停接单): 作业台顶部红条 + 商家一键恢复。与忙碌/暂停三档独立(各用各列)。
        $autoOffline = [
            'on'     => (bool) ($sr->nezha_auto_offline ?? 0),
            'reason' => $sr->nezha_auto_offline_reason ?? null,
            'at'     => $sr->nezha_auto_offline_at ?? null,
        ];

        // screen05: 预约分区。总闸 nezha_preorder_status 关(dormant 常态)→ enabled() 短路→ 只 ['enabled'=>false], 零额外查询、整区块不渲染。
        $preorder = NezhaPreorder::enabled() ? self::queuePreorder($rid, $rateCny, $rateUsd) : ['enabled' => false];

        return [
            'action'       => $action,
            'queues'       => $queues,
            'preorder'     => $preorder,
            'rail'         => $rail,
            'store'        => $store,
            'auto_offline' => $autoOffline,
            'rates'        => ['cny' => $rateCny, 'usd' => $rateUsd],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /* ───────────────────────── 队列构造器 ───────────────────────── */

    /** 公共基座: 本店 + 非POS + 今日订阅口径(与 list `$nzCountBase` / NezhaOrderCounts::compute base 完全一致)。 */
    protected static function base(int $rid)
    {
        return Order::with(['customer', 'offline_payments', 'details', 'restaurant.restaurant_sub'])
            ->where('restaurant_id', $rid)->Notpos()->HasSubscriptionToday();
    }

    /**
     * screen05 单点版 · 作业台「预约配送」分区(05b·业主 2026-07-11 单点模型·推翻窗口分组)。总闸 nezha_preorder_status 开时才构造(buildSummary 已 gate·关时整区块不出)。
     * 只读: 把「今日起 + 可约上限内」的预约单铺成**按预计送达点排的独立配送单**(非窗口批次), 供商家在「送达点 − dispatch_lead」逐单叫 Yandex。绝不写任何订单状态。
     *   - 与现有 5 队列有意「同单并存」(那 5 队列按阶段·本区块按送达点): 不改现有队列口径(守 NezhaOrderCounts 计数 parity·DoD#1)。
     *   - [叫车]折叠出餐(业主 2026-07-12 定): confirmed/handover 的「该叫车」单点[叫车]即开既有 Yandex 抽屉, 一步出餐·标记配送中(逐单·Yandex 一车一单·不批量)。
     */
    protected static function queuePreorder(int $rid, float $rateCny, float $rateUsd): array
    {
        $now  = now();
        $from = $now->copy()->startOfDay();
        $to   = $now->copy()->addDays(NezhaPreorder::maxDaysAhead() + 1)->endOfDay();   // 今日起 + 可约上限, 防拉超远脏单

        $orders = self::base($rid)
            ->where('scheduled', 1)->whereNotNull('schedule_at')
            ->whereBetween('schedule_at', [$from, $to])
            ->whereIn('order_status', ['confirmed', 'accepted', 'processing', 'handover', 'picked_up', 'delivered'])
            ->orderBy('schedule_at', 'asc')->orderBy('created_at', 'asc')
            ->get();

        $dispatchLead = NezhaPreorder::dispatchLeadMin();

        // 每单一张独立卡(单点·非窗口分组): 先铺卡, 再按天分节、组内待办优先排序。
        $rank = ['due' => 0, 'upcoming' => 1, 'called' => 2, 'delivered' => 3];
        $cardsByDate = [];
        $dispatchIds = [];                                             // 「该叫车」单 id → 叫车抽屉源(折叠出餐)
        $dueCount = 0; $dueEarliestPoint = null; $dueEarliestSuggest = null;
        $total = 0; $unfinished = 0;
        $todayTotal = 0; $todayDue = 0; $todayDone = 0; $todayAmd = 0.0; $futureTotal = 0;

        foreach ($orders as $o) {
            $st      = (string) $o->order_status;
            $point   = Carbon::parse($o->schedule_at);
            $suggest = $point->copy()->subMinutes($dispatchLead);      // 建议叫车 = 送达点 − 固定提前量
            $callReached = $now->gte($suggest);
            $state   = NezhaPreorder::pointCardState($st, $callReached);   // due / upcoming / called / delivered
            $isToday = $point->isSameDay($now);
            $isDone  = in_array($st, ['picked_up', 'delivered'], true);
            $total++;

            $card = [
                'id'         => (int) $o->id,
                'customer'   => self::maskedCustomer($o)['name'],
                'items_qty'  => (int) ($o->details?->sum('quantity') ?? 0),
                'amount_amd' => Helpers::format_currency($o->order_amount),
                'point'      => $point->format('H:i'),
                'state'      => $state,
                'chip'       => self::preorderChip($state),
                'rank'       => $rank[$state] ?? 1,
            ];
            $sugDisplay = ($isToday ? '' : NezhaPreorder::dayLabel($point, $now) . ' ') . $suggest->format('H:i');

            if ($state === 'due') {
                $card['suggest_time'] = $sugDisplay;
                $dispatchIds[] = (int) $o->id;
                $dueCount++;
                if ($dueEarliestPoint === null || $point->lt($dueEarliestPoint)) {
                    $dueEarliestPoint = $point->copy();
                    $dueEarliestSuggest = $suggest->copy();
                }
            } elseif ($state === 'upcoming') {
                $card['suggest_time'] = $sugDisplay;
                $card['wait_min']     = max(0, (int) round($now->diffInMinutes($suggest, false)));   // 距建议叫车还有多久
            } elseif ($state === 'called') {
                $t = $o->picked_up ? Carbon::parse((string) $o->picked_up)->format('H:i') : null;   // 已叫车时刻 = picked_up
                $card['note'] = ($t ? '已于 ' . $t . ' 叫车' : '已叫车') . (!empty($o->yandex_tracking_url) ? ' · 跟踪链接已发顾客' : '');
            } else { // delivered
                $dt = $o->delivered ?: $o->picked_up;
                $t  = $dt ? Carbon::parse((string) $dt)->format('H:i') : null;
                $card['note'] = $t ? $t . ' 已送达' : '已送达';
            }

            $cardsByDate[$point->format('Y-m-d')][] = $card;

            if (!$isDone) { $unfinished++; }                          // tab 计数 = 未完成(已叫车/已送达不计)
            if ($isToday) {
                $todayTotal++;
                if ($state === 'due') { $todayDue++; }
                if ($isDone) { $todayDone++; }
                $todayAmd += (float) $o->order_amount;
            } else {
                $futureTotal++;
            }
        }

        // 按天分节(date 升序=今天/明天/…), 组内 rank(待办优先) 再送达点升序(PHP8 usort 稳定·orders 已按 schedule_at 升序)。
        ksort($cardsByDate);
        $sections = [];
        foreach ($cardsByDate as $dateKey => $cards) {
            usort($cards, fn ($a, $b) => $a['rank'] <=> $b['rank']);
            $d = Carbon::parse($dateKey);
            $sections[] = [
                'day_label'  => NezhaPreorder::dayLabel($d, $now),
                'date_label' => $d->format('n月j日') . ' ' . NezhaPreorder::weekdayLabel((int) $d->dayOfWeek),
                'count'      => count($cards),
                'cards'      => $cards,
            ];
        }

        // 在场横幅 = 所有「该叫车」单的聚合(与 07 叫车推送同一套数·两处永远同源)。无 due 单则无横幅。
        $banner = $dueCount > 0 ? [
            'count'         => $dueCount,
            'earliest'      => $dueEarliestPoint ? $dueEarliestPoint->format('H:i') : '',
            'suggest'       => $dueEarliestSuggest ? $dueEarliestSuggest->format('H:i') : '',
            'dispatch_lead' => $dispatchLead,
        ] : null;

        return [
            'enabled'  => true,
            'sections' => $sections,
            'summary'  => [
                'total'       => $total,
                'today_total' => $todayTotal,
                'today_due'   => $todayDue,
                'today_done'  => $todayDone,
                'future'      => $futureTotal,
                'total_amd'   => Helpers::format_currency($todayAmd),
                'total_cny'   => $rateCny > 0 ? round($todayAmd / $rateCny) : null,
                'total_usd'   => $rateUsd > 0 ? round($todayAmd / $rateUsd) : null,
            ],
            'banner'        => $banner,
            'tab_count'     => $unfinished,
            'dispatch_ids'  => $dispatchIds,
            'dispatch_lead' => $dispatchLead,
            'empty_text'    => '还没有预约单',
        ];
    }

    /** 单点卡 chip: 状态 → [中文, 色族]。族①琥珀 a=该叫车 / 族③紫 b=未到时间 / 族②绿 g=已叫车·已送达。 */
    protected static function preorderChip(string $state): array
    {
        return [
            'due'       => ['该叫车', 'a'],
            'upcoming'  => ['未到时间', 'b'],
            'called'    => ['已叫车', 'g'],
            'delivered' => ['已送达', 'g'],
        ][$state] ?? ['未到时间', 'b'];
    }

    /**
     * ① 待确认收款 = pending + offline + 有 pending 凭证(计徽标) ; 尾部弱化行 = 无凭证 pending 离线单(不计徽标·无主CTA)。
     */
    protected static function queueConfirmPayment(int $rid, array $counts, float $rateCny, float $rateUsd): array
    {
        // 有凭证待核(等待最久置顶)
        $rows = self::base($rid)
            ->where('order_status', 'pending')->where('payment_method', 'offline_payment')
            ->whereHas('offline_payments', function ($q) { $q->where('status', 'pending'); })
            ->orderBy('created_at', 'asc')->limit(self::ROWS)->get()
            ->map(function ($o) use ($rateCny, $rateUsd) {
                return self::row($o, $rateCny, $rateUsd, [
                    'proof'      => self::proofMeta($o),
                    'placed_at'  => $o->created_at ? Carbon::parse($o->created_at)->format('H:i') : null,
                    'waited_min' => self::minutesSince($o->created_at),
                ]);
            })->values()->all();

        // 无凭证弱化行(与 P1b-B wait 态同源: decide() 返回 kind=wait · 无主CTA)
        $noProofQ = self::base($rid)
            ->where('order_status', 'pending')->where('payment_method', 'offline_payment')
            ->whereDoesntHave('offline_payments', function ($q) { $q->where('status', 'pending'); });
        $noProofTotal = (clone $noProofQ)->count();
        $noProofRows  = $noProofQ->orderBy('created_at', 'asc')->limit(self::ROWS)->get()
            ->map(function ($o) use ($rateCny, $rateUsd) {
                return self::row($o, $rateCny, $rateUsd, [
                    'no_proof'   => true,
                    'waited_min' => self::minutesSince($o->created_at),
                ]);
            })->values()->all();

        return [
            'total'          => (int) $counts['offline_pending'],   // == provider(DoD#2)
            'rows'           => $rows,
            'no_proof_total' => (int) $noProofTotal,                // 「另有 N 单等顾客传凭证(不计需动作)」
            'no_proof_rows'  => $noProofRows,
            'empty_text'     => '暂无待确认收款的订单',
        ];
    }

    /** ② 备餐中 = processing 。 */
    protected static function queueCooking(int $rid, array $counts, float $rateCny, float $rateUsd): array
    {
        $rows = self::base($rid)
            ->where('order_status', 'processing')->NotDigitalOrder()
            ->orderBy('processing', 'asc')->limit(self::ROWS)->get()
            ->map(function ($o) use ($rateCny, $rateUsd) {
                return self::row($o, $rateCny, $rateUsd, [
                    'cooking_min' => self::minutesSince($o->processing ?? $o->confirmed ?? $o->created_at),
                ]);
            })->values()->all();

        return [
            'total'      => (int) $counts['cooking'],
            'rows'       => $rows,
            'empty_text' => '暂无备餐中的订单',
        ];
    }

    /** ③ 配送 = handover(待叫车) ∪ picked_up(配送中) 。 */
    protected static function queueDelivery(int $rid, array $counts, float $rateCny, float $rateUsd): array
    {
        $rows = self::base($rid)
            ->whereIn('order_status', ['handover', 'picked_up'])->NotDigitalOrder()
            ->orderByRaw("FIELD(order_status,'handover','picked_up')")
            ->orderBy('schedule_at', 'asc')->limit(self::ROWS)->get()
            ->map(function ($o) use ($rateCny, $rateUsd) {
                $picked = $o->order_status === 'picked_up';
                return self::row($o, $rateCny, $rateUsd, [
                    'stage'      => $picked ? 'picked_up' : 'handover',
                    'stage_min'  => self::minutesSince($picked ? $o->picked_up : $o->handover),
                    'tracking'   => $o->yandex_tracking_url ? 'posted' : 'none',
                    'nudged'     => !empty($o->delivery_link_reminded_at) && empty($o->yandex_tracking_url),
                ]);
            })->values()->all();

        return [
            'total'      => (int) ($counts['ready_for_delivery'] + $counts['food_on_the_way']),
            'wait_car'   => (int) $counts['ready_for_delivery'],   // 待叫车
            'on_the_way' => (int) $counts['food_on_the_way'],      // 配送中
            'rows'       => $rows,
            'empty_text' => '暂无待叫车或配送中的订单',
        ];
    }

    /** ④ 催促 · 超时(横切告警·轻量跳转行) = 催促 ∪ 超时, 按单去重; 与①②③有意重叠, 不重复整行卡。 */
    protected static function queueNudgeTimeout(int $rid, array $nudgeIds, array $timeoutIds, int $total): array
    {
        $ids       = self::uniqInts(array_merge($nudgeIds, $timeoutIds));
        $nudgeSet  = array_flip(self::uniqInts($nudgeIds));
        $rows = [];
        if ($ids) {
            $orders = self::base($rid)->whereIn('id', $ids)->orderBy('schedule_at', 'asc')->limit(self::ROWS)->get();
            foreach ($orders as $o) {
                $isNudge = isset($nudgeSet[(int) $o->id]);
                $rows[] = [
                    'id'          => (int) $o->id,
                    'status'      => $o->order_status,
                    'status_text' => self::statusText($o->order_status),
                    'reason'      => $isNudge ? 'nudge' : 'timeout',
                    'hint'        => self::nudgeTimeoutHint($o, $isNudge),
                    'waited_min'  => self::minutesSince(self::statusSince($o)),
                    'cta'         => NezhaOrderNextAction::decide($o),   // 跳到该单主操作
                ];
            }
        }

        return [
            'total'      => (int) $total,   // 按单去重并集, 与 grp_action 口径一致
            'rows'       => $rows,
            'empty_text' => '没有催促或超时，安心备餐',
        ];
    }

    /** ⑤ 退款处理(两段式) = 有 pending_merchant_refund 留痕的单; 段A payment=paid(红·置顶) / 段B 非 paid(灰)。 */
    protected static function queueRefund(int $rid, array $counts, float $rateCny, float $rateUsd): array
    {
        $orders = self::base($rid)
            ->whereIn('id', function ($sub) {
                $sub->select('order_id')->from('nezha_refund_records')->whereIn('status', \App\Models\NezhaRefundRecord::STATUS_UNRESOLVED);
            })
            ->orderByRaw("(payment_status = 'paid') DESC")
            ->orderByRaw("(SELECT MIN(nrr.created_at) FROM nezha_refund_records nrr WHERE nrr.order_id = orders.id AND nrr.status IN ('pending_merchant_refund','disputed')) ASC")
            ->limit(self::ROWS)->get();

        // 每单取最新一条 pending 退款记录(与 mark_refunded latest('id') 目标一致)
        $recs = NezhaRefundRecord::whereIn('order_id', $orders->pluck('id'))
            ->whereIn('status', \App\Models\NezhaRefundRecord::STATUS_UNRESOLVED)
            ->orderBy('id', 'desc')->get()->groupBy('order_id');

        $rows = $orders->map(function ($o) use ($recs, $rateCny, $rateUsd) {
            $rec = optional($recs->get($o->id))->first();
            $amt = $rec ? (float) $rec->refund_amount : (float) $o->order_amount;
            $seg = $o->payment_status === 'paid' ? 'A' : 'B';

            return [
                'id'         => (int) $o->id,
                'segment'    => $seg,                        // A=已确认收款·须退 / B=凭证在案·先核后退
                'disputed'   => optional($rec)->status === 'disputed',
                'refund_amd' => Helpers::format_currency($amt),
                'refund_cny' => $rateCny > 0 ? round($amt / $rateCny, 1) : null,
                'refund_usd' => $rateUsd > 0 ? round($amt / $rateUsd, 2) : null,
                'channel'    => self::refundChannelLabel($rec, $o),
                'held_text'  => self::heldSince(optional($rec)->created_at),
                'meta'       => $seg === 'B' ? '顾客有付款凭证在案，请核对您的收款账户' : null,
                // 卡上不做一键标记; CTA = 去退款核对 → 进详情退款核对卡(原路强确认 L1 呈现原样保留)
                'cta'        => [
                    'kind'  => 'link',
                    'label' => optional($rec)->status === 'disputed' ? '查看争议' : '去退款核对',
                    'route' => route('vendor.order.details', ['id' => $o->id]),
                ],
            ];
        })->values()->all();

        return [
            'total'      => (int) $counts['refund_pending'],
            'rows'       => $rows,
            'empty_text' => '没有需要处理的退款',
        ];
    }

    /* ───────────────────────── 行 / 字段构造 ───────────────────────── */

    /** 队列行公共字段 + decide() 主 CTA(与详情页同源)。$extra 为各队列专属字段。 */
    protected static function row($order, float $rateCny, float $rateUsd, array $extra = []): array
    {
        $scheduleLabel = null;
        if ((int) $order->scheduled === 1 && !empty($order->schedule_at)) {
            $scheduleAt = Carbon::parse($order->schedule_at);
            $scheduleLabel = NezhaPreorder::dayLabel($scheduleAt, now())
                .'（'.NezhaPreorder::weekdayLabel($scheduleAt->dayOfWeek).'） '
                .$scheduleAt->format('H:i');
        }

        return array_merge([
            'id'            => (int) $order->id,
            'amount'        => (float) $order->order_amount,
            'amount_amd'    => Helpers::format_currency($order->order_amount),
            'amount_cny'    => $rateCny > 0 ? round(((float) $order->order_amount) / $rateCny, 1) : null,
            'amount_usd'    => $rateUsd > 0 ? round(((float) $order->order_amount) / $rateUsd, 2) : null,
            'payment_label' => self::paymentLabel($order),
            'customer'      => self::maskedCustomer($order),
            'items'         => self::itemsSummary($order),
            'cta'           => NezhaOrderNextAction::decide($order),
            'is_preorder'   => $scheduleLabel !== null,
            'schedule_label'=> $scheduleLabel,
        ], $extra);
    }

    /** 支付方式标签: 离线单取 offline_payments.payment_info.method_name(与 list.blade:711 同源), 回退「线下支付」。 */
    protected static function paymentLabel($order): string
    {
        if ($order->payment_method === 'offline_payment' && $order->offline_payments) {
            $pinfo = json_decode($order->offline_payments->payment_info, true) ?: [];
            if (!empty($pinfo['method_name'])) {
                return $pinfo['method_name'];
            }
            return '线下支付';
        }

        return $order->payment_method ? translate('messages.' . $order->payment_method) : '—';
    }

    /** 凭证元数据: 有无 + 标签(USDT 类→哈希, 其余→凭证)。 */
    protected static function proofMeta($order): array
    {
        $op = $order->offline_payments;
        $isHash = false;
        $url = null;
        if ($op) {
            $pinfo = json_decode($op->payment_info, true) ?: [];
            $mn = mb_strtolower((string) ($pinfo['method_name'] ?? ''));
            $isHash = str_contains($mn, 'usdt') || str_contains($mn, 'trc') || str_contains($mn, 'bep') || str_contains($mn, 'hash');
            // 第一张凭证图 URL(缩略图点开大图·快速预筛; 正式核对仍在详情页收款tab)。offline_payment_proof_url 只对存在的图片路径返 URL, 否则 null。
            foreach ($pinfo as $v) {
                if (is_string($v) && ($u = Helpers::offline_payment_proof_url($v))) {
                    $url = $u;
                    break;
                }
            }
        }

        return ['has' => (bool) $op, 'label' => $isHash ? '哈希' : '凭证', 'url' => $url];
    }

    /** 顾客标识: 下单 24h 内(nezha_customer_contact_reveal_hours 可调)完整显示便于联系, 超窗打码; 真实电话/地址在派 Yandex 抽屉亦保留完整。见 NezhaContactVisibility。 */
    protected static function maskedCustomer($order): array
    {
        $c = $order->customer;
        $name = $c ? trim(((string) ($c->f_name ?? '')) . ' ' . ((string) ($c->l_name ?? ''))) : '';
        $nzVisible = \App\CentralLogics\NezhaContactVisibility::visible($order->created_at ?? null);

        return [
            'name'  => $name !== '' ? ($nzVisible ? $name : Helpers::mask_name($name)) : '顾客',
            'phone' => $c && $c->phone ? ($nzVisible ? $c->phone : Helpers::mask_phone($c->phone)) : null,
        ];
    }

    /** 菜品摘要(1 行, 最多 2 项, 取下单快照名, 防食品被删导致 null)。 */
    protected static function itemsSummary($order): string
    {
        $details = $order->details ?? collect();
        $parts = [];
        foreach ($details as $d) {
            $fd = is_string($d->food_details ?? null) ? json_decode($d->food_details) : ($d->food_details ?? null);
            $name = $fd->name ?? null;
            if ($name) {
                $parts[] = $name . ' ×' . (int) $d->quantity;
            }
            if (count($parts) >= 2) {
                break;
            }
        }
        $s = implode(' · ', $parts);
        if ($s !== '' && ($details->count() ?? 0) > count($parts)) {
            $s .= ' 等';
        }

        return $s;
    }

    /* ───────────────────────── 小工具 ───────────────────────── */

    protected static function uniqInts(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** 从某时刻到现在的分钟数(绝对值, 运行时 tz=Asia/Yerevan, created_at 与 now 同 tz)。 */
    protected static function minutesSince($ts): ?int
    {
        if (!$ts) {
            return null;
        }

        return (int) round(abs(now()->diffInMinutes(Carbon::parse($ts))));
    }

    /** 进入当前状态的时刻(取对应状态时间戳列, 缺则回退 created_at)。 */
    protected static function statusSince($o)
    {
        $col = [
            'confirmed' => 'confirmed', 'accepted' => 'accepted', 'processing' => 'processing',
            'handover' => 'handover', 'picked_up' => 'picked_up',
        ][$o->order_status] ?? null;

        return ($col && $o->$col) ? $o->$col : $o->created_at;
    }

    protected static function statusText(string $os): string
    {
        return [
            'pending'    => '待确认收款',
            'confirmed'  => '已接单',
            'accepted'   => '已接单',
            'processing' => '备餐中',
            'handover'   => '出餐待叫车',
            'picked_up'  => '配送中',
        ][$os] ?? $os;
    }

    /** ④ 一句话「该干嘛」。催促分场景; 超时统一直跳主操作。 */
    protected static function nudgeTimeoutHint($o, bool $isNudge): string
    {
        if ($isNudge) {
            if ($o->order_status === 'picked_up' && empty($o->yandex_tracking_url)) {
                return '顾客想看配送进度 → 去贴追踪链接';
            }
            if (in_array($o->order_status, ['processing', 'confirmed', 'accepted'], true)) {
                return '顾客在催 → 尽快出餐或回复';
            }
            return '顾客催促 · 请尽快处理';
        }

        return '已超时 · 请尽快处理该单';
    }

    /** 退款原渠道标签: 以退款记录 payment_channel 为准(usdt/rmb), 缺记录回退订单支付方式。 */
    protected static function refundChannelLabel($rec, $o): string
    {
        $ch = optional($rec)->payment_channel;
        if ($ch === 'usdt') {
            return 'USDT';
        }
        if ($ch === 'rmb') {
            return '支付宝';
        }

        return self::paymentLabel($o);
    }

    /** 挂起时长文案(退款记录建立至今)。 */
    protected static function heldSince($ts): string
    {
        $min = self::minutesSince($ts);
        if ($min === null) {
            return '';
        }
        if ($min < 60) {
            return '挂起 ' . $min . ' 分钟';
        }
        if ($min < 1440) {
            return '挂起 ' . intdiv($min, 60) . ' 小时';
        }

        return '挂起 ' . intdiv($min, 1440) . ' 天';
    }
}
