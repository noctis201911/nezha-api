<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaBadReview;
use App\CentralLogics\NezhaCustomerNudge;
use App\CentralLogics\NezhaOrderCounts;
use App\CentralLogics\NezhaOrderNextAction;
use App\CentralLogics\NezhaOrderTimeout;
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
        $wb = self::buildSummary($rid);
        $dispatchOrders = self::dispatchOrdersFor($wb);

        return view('vendor-views.workbench.index', compact('wb', 'dispatchOrders'));
    }

    /**
     * W4: 作业台可刷新分区(_body)的 HTML 片段。并入全局 6s 心跳(app.blade poll)刷新, 不另开轮询。
     * 与 index() 共用 buildSummary() + dispatchOrdersFor() 同一契约; 返回无 layout 的 _body partial(供 JS 换入 #nzwbRefresh)。
     * 纯只读: 与 summary/index 同源, 不写 checked / 不改状态 / 无副作用。
     */
    public function refresh(Request $request)
    {
        $rid = (int) Helpers::get_restaurant_id();
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
            'confirm_payment' => self::queueConfirmPayment($rid, $counts, $rateCny),
            'cooking'         => self::queueCooking($rid, $counts, $rateCny),
            'delivery'        => self::queueDelivery($rid, $counts, $rateCny),
            'nudge_timeout'   => self::queueNudgeTimeout($rid, $nudgeIds, $timeoutIds, $nudgeOrTimeout),
            'refund'          => self::queueRefund($rid, $counts, $rateCny),
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

        return [
            'action'       => $action,
            'queues'       => $queues,
            'rail'         => $rail,
            // W5: 店态胶囊两档(营业/暂停接单)。暂停=nezha_temp_closed(店可见+休息中+拦单), 与门店页 update-active-status 同源。
            'store'        => ['temp_closed' => (bool) DB::table('restaurants')->where('id', $rid)->value('nezha_temp_closed')],
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
     * ① 待确认收款 = pending + offline + 有 pending 凭证(计徽标) ; 尾部弱化行 = 无凭证 pending 离线单(不计徽标·无主CTA)。
     */
    protected static function queueConfirmPayment(int $rid, array $counts, float $rateCny): array
    {
        // 有凭证待核(等待最久置顶)
        $rows = self::base($rid)
            ->where('order_status', 'pending')->where('payment_method', 'offline_payment')
            ->whereHas('offline_payments', function ($q) { $q->where('status', 'pending'); })
            ->orderBy('created_at', 'asc')->limit(self::ROWS)->get()
            ->map(function ($o) use ($rateCny) {
                return self::row($o, $rateCny, [
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
            ->map(function ($o) use ($rateCny) {
                return self::row($o, $rateCny, [
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
    protected static function queueCooking(int $rid, array $counts, float $rateCny): array
    {
        $rows = self::base($rid)
            ->where('order_status', 'processing')->NotDigitalOrder()
            ->orderBy('processing', 'asc')->limit(self::ROWS)->get()
            ->map(function ($o) use ($rateCny) {
                return self::row($o, $rateCny, [
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
    protected static function queueDelivery(int $rid, array $counts, float $rateCny): array
    {
        $rows = self::base($rid)
            ->whereIn('order_status', ['handover', 'picked_up'])->NotDigitalOrder()
            ->orderByRaw("FIELD(order_status,'handover','picked_up')")
            ->orderBy('schedule_at', 'asc')->limit(self::ROWS)->get()
            ->map(function ($o) use ($rateCny) {
                $picked = $o->order_status === 'picked_up';
                return self::row($o, $rateCny, [
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
    protected static function queueRefund(int $rid, array $counts, float $rateCny): array
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

        $rows = $orders->map(function ($o) use ($recs, $rateCny) {
            $rec = optional($recs->get($o->id))->first();
            $amt = $rec ? (float) $rec->refund_amount : (float) $o->order_amount;
            $seg = $o->payment_status === 'paid' ? 'A' : 'B';

            return [
                'id'         => (int) $o->id,
                'segment'    => $seg,                        // A=已确认收款·须退 / B=凭证在案·先核后退
                'disputed'   => optional($rec)->status === 'disputed',
                'refund_amd' => Helpers::format_currency($amt),
                'refund_cny' => $rateCny > 0 ? round($amt / $rateCny, 1) : null,
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
    protected static function row($order, float $rateCny, array $extra = []): array
    {
        return array_merge([
            'id'            => (int) $order->id,
            'amount'        => (float) $order->order_amount,
            'amount_amd'    => Helpers::format_currency($order->order_amount),
            'amount_cny'    => $rateCny > 0 ? round(((float) $order->order_amount) / $rateCny, 1) : null,
            'payment_label' => self::paymentLabel($order),
            'customer'      => self::maskedCustomer($order),
            'items'         => self::itemsSummary($order),
            'cta'           => NezhaOrderNextAction::decide($order),
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

    /** PII 脱敏顾客标识(浏览类展示; 真实电话/地址在派 Yandex 抽屉保留完整, 此处不 over-mask)。 */
    protected static function maskedCustomer($order): array
    {
        $c = $order->customer;
        $name = $c ? trim(((string) ($c->f_name ?? '')) . ' ' . ((string) ($c->l_name ?? ''))) : '';

        return [
            'name'  => $name !== '' ? Helpers::mask_name($name) : '顾客',
            'phone' => $c && $c->phone ? Helpers::mask_phone($c->phone) : null,
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
