<?php

namespace App\CentralLogics;

use App\Models\Order;
use App\Models\Review;
use App\Models\Restaurant;
use App\Models\BusinessSetting;
use App\Models\NezhaRefundRecord;
use App\Scopes\ZoneScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒超管 M2-D4「驾驶舱·今天」数据契约单一真相源。
 *
 * counts()       : 60s 缓存的队列计数 + 异常/逾期行。三处同源消费方:
 *                  ① 顶栏铃铛(SystemController::restaurant_data 改读它) ② 侧栏「逾期未退款/争议」徽标
 *                  ③ 驾驶舱卡①③ —— 满足交接包 DoD#1「计数三处对账」(卡=列表=铃铛=脉冲, 同一 provider 供数)。
 * buildSummary() : 驾驶舱整页契约(不缓存, 页面专用), 复用 counts() + 各卡 ≤5 行明细 + 右栏 KPI。
 *
 * 纯只读 L3 呈现层聚合: 只 SELECT, 全部复用既有 helper/query/口径, 不新造任何资金动作端点, 不碰 L1 机制。
 * 逾期未退款 / 订单异常口径逐字搬自 SystemController::restaurant_data(原样, 只去掉未缓存的重复轮询)。
 *
 * 防御: 每段 self::safe 包裹, 任何子查询失败退化为 0/空并记日志, computeCounts() 绝不抛出
 *   —— counts() 被顶栏铃铛与侧栏(每页)调用, 抛出=全后台 500(见 M2-D1 e569d74 教训)。
 */
class NezhaAdminDashboard
{
    /** 跨请求缓存 TTL 秒(与 NezhaAdminCounts 同档) */
    protected const TTL = 60;

    protected const CACHE_KEY = 'nezha_admin_dashboard_counts';

    /** 卡内直显行上限(超出走「查看全部」跳列表页) */
    public const ROWS = 5;

    /** 铃铛面板行上限(与原 restaurant_data 一致, 保证收编后铃铛 JSON 形状不变) */
    protected const BELL_ROWS = 8;

    /** 请求内 memo */
    protected static ?array $memoCounts = null;

    /** 失效缓存(供订单/退款写钩子调用; 60s TTL 为兜底) */
    public static function forget(): void
    {
        self::$memoCounts = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** 统一入口: 60s 缓存的队列计数 + 异常/逾期行(单一真相源)。 */
    public static function counts(): array
    {
        if (is_array(self::$memoCounts)) {
            return self::$memoCounts;
        }

        return self::$memoCounts = Cache::remember(self::CACHE_KEY, self::TTL, function () {
            return self::computeCounts();
        });
    }

    /* ───────────────────────── 计数计算(全部 self::safe 包裹, 绝不抛出) ───────────────────────── */

    protected static function computeCounts(): array
    {
        $refund = self::computeRefundOverdue();      // 逾期未退款(含铃铛行)
        $exc    = self::computeOrderExceptions();    // 订单异常(含铃铛行)

        $disputes = self::safe(function () {
            return (int) \App\Models\NezhaRefundDispute::where('status', 'open')->count();
        });

        $funds    = self::computeFunds();            // 充值/押金退款/退出结算(dormant→0)
        $audit    = self::computeAudit();            // UGC/入驻/广告/KYC
        $risk     = self::safe(function () {
            return (int) \App\Models\NezhaRiskRecord::where('action', 'review')->where('status', 'pending')->count();
        });
        $merchant = self::computeMerchantHealth();   // 低押金 + 差评未回复(平台)

        return [
            'refund_overdue'   => $refund['count'],
            'refund_ids'       => $refund['ids'],
            'refund_rows'      => $refund['rows'],
            'disputes'         => (int) $disputes,
            'order_exceptions' => $exc['count'],
            'exception_ids'    => $exc['ids'],
            'exception_rows'   => $exc['rows'],
            'funds_pending'    => $funds['total'],
            'funds'            => $funds,
            'audit_total'      => $audit['total'],
            'audit'            => $audit,
            'risk_queue'       => (int) $risk,
            'merchant_health'  => $merchant['total'],
            'merchant'         => $merchant,
        ];
    }

    /**
     * 逾期未退款(口径逐字搬自 SystemController::restaurant_data 的 abn_refund + RefundOverdueSweep)。
     * count = 去重订单数(与原 abn_refund_total 一致); rows = 前 8 行(供铃铛), 携原始 amount 供铃铛 JS 格式化。
     */
    protected static function computeRefundOverdue(): array
    {
        return self::safeArr(function () {
            $remindHours = NezhaRefundOverdue::thresholdHours('nezha_refund_overdue_remind_hours', 'nezha_refund_overdue_remind_days', 12);
            if ($remindHours < 1) {
                $remindHours = 1;
            }
            $cutoff = Carbon::now()->subHours($remindHours);

            $base = NezhaRefundRecord::whereIn('status', NezhaRefundRecord::STATUS_NEEDS_ACTION)
                ->whereNull('merchant_refunded_at')
                ->whereRaw(NezhaRefundRecord::OVERDUE_SINCE_SQL . ' <= ?', [$cutoff->toDateTimeString()]);

            $ids = (clone $base)->orderByRaw(NezhaRefundRecord::OVERDUE_SINCE_SQL)
                ->pluck('order_id')->filter()->unique()->values()->all();

            $records = (clone $base)->with('order.restaurant')
                ->orderByRaw(NezhaRefundRecord::OVERDUE_SINCE_SQL)->limit(self::BELL_ROWS)->get();

            $rows = [];
            foreach ($records as $rr) {
                $since = $rr->overdue_anchor_at ?? $rr->created_at;
                $amt   = $rr->refund_amount ?: $rr->order_amount;
                $rows[] = [
                    'order_id'   => $rr->order_id,
                    'shop'       => optional(optional($rr->order)->restaurant)->name,
                    'amount'     => (float) $amt,
                    'overdue_hr' => $since ? (int) Carbon::parse($since)->diffInHours(Carbon::now()) : null,
                    'channel'    => self::refundChannel($rr),
                ];
            }

            return ['count' => count($ids), 'ids' => $ids, 'rows' => $rows];
        }, ['count' => 0, 'ids' => [], 'rows' => []]);
    }

    /**
     * 订单异常(口径逐字搬自 restaurant_data 的 abn_timeout: NezhaOrderTimeout severity=='error')。
     * 保持与原 restaurant_data 一致 —— 不 withoutGlobalScope, 保证收编后铃铛数字不变。
     */
    protected static function computeOrderExceptions(): array
    {
        return self::safeArr(function () {
            $open = Order::with(['offline_payments', 'restaurant'])
                ->whereIn('order_status', ['pending', 'confirmed', 'processing', 'handover', 'picked_up'])
                ->Notpos()->get();

            $ids = [];
            $rows = [];
            foreach ($open as $o) {
                $phase = NezhaOrderTimeout::phase($o);
                if (! $phase) {
                    continue;
                }
                if ($phase === NezhaOrderTimeout::PHASE_PROOF && ! NezhaOrderTimeout::hasPaymentProof($o)) {
                    continue;
                }
                $d = NezhaOrderTimeout::describe($o);
                if (! $d || ($d['severity'] ?? 'info') !== 'error') {
                    continue;
                }
                $ids[] = $o->id;
                // 与原 restaurant_data 一致: 超时行不设上限(铃铛按 total 显全部); 卡③另 array_slice 5 行。
                $rows[] = [
                    'id'       => $o->id,
                    'reason'   => $d['title'] ?? '订单超时',
                    'wait_min' => $d['elapsed_minutes'] ?? null,
                    'shop'     => optional($o->restaurant)->name,
                ];
            }

            return ['count' => count($ids), 'ids' => $ids, 'rows' => $rows];
        }, ['count' => 0, 'ids' => [], 'rows' => []]);
    }

    /** 资金审核(充值 topup + 押金退款 refund + 退出结算 offboard); 三者均 dormant, 关时=0。 */
    protected static function computeFunds(): array
    {
        $topup = self::safe(function () {
            return (int) \App\Models\NezhaTopupRequest::where('direction', 'topup')->where('status', 'pending')->count();
        });
        $depositRefund = self::safe(function () {
            return (int) \App\Models\NezhaTopupRequest::where('direction', 'refund')->where('status', 'pending')->count();
        });
        $offboard = self::safe(function () {
            return (int) \App\Models\RestaurantOffboardSettlement::where('active_uniq', 1)
                ->whereIn('status', ['applied', 'kyc_pending', 'approved', 'paying', 'partial'])->count();
        });

        return [
            'topup'          => $topup,
            'deposit_refund' => $depositRefund,
            'offboard'       => $offboard,
            'total'          => $topup + $depositRefund + $offboard,
        ];
    }

    /** 审核台四段(评价审核后台无功能, 已按业主拍板去除, 另立项 task_ed6425af)。 */
    protected static function computeAudit(): array
    {
        $ugc = self::safe(function () {
            return (int) \App\Models\LocalLifePost::where('status', \App\Models\LocalLifePost::STATUS_PENDING)->count();
        });
        $onboarding = self::safe(function () {
            return (int) \App\Models\MerchantLead::where('seen', 0)->count();
        });
        $ad = self::safe(function () {
            return (int) \App\Models\Advertisement::where('status', 'pending')->count();
        });
        $kyc = self::safe(function () {
            return (int) \App\Models\VendorKycProfile::where('kyc_status', 'pending')->count();
        });

        return [
            'ugc'        => $ugc,
            'onboarding' => $onboarding,
            'ad'         => $ad,
            'kyc'        => $kyc,
            'total'      => $ugc + $onboarding + $ad + $kyc,
        ];
    }

    /** 商家健康: 低押金(复用 VendorController 判定口径) + 差评未回复(平台版, 同 NezhaBadReview 窗口)。 */
    protected static function computeMerchantHealth(): array
    {
        $lowDeposit = self::safe(function () {
            $depoMode = (int) (BusinessSetting::where('key', 'nezha_deposit_mode_status')->value('value') ?? 0);
            if ($depoMode !== 1) {
                return 0;
            }
            $threshold = (float) (BusinessSetting::where('key', 'nezha_min_deposit_threshold')->value('value') ?? 0);
            if ($threshold <= 0) {
                return 0;
            }
            return (int) Restaurant::where('nezha_commission_enabled', 1)
                ->whereHas('wallet', function ($q) use ($threshold) {
                    $q->where('deposit_balance', '<=', $threshold);
                })->count();
        });

        $bad = NezhaBadReview::platformSummary();

        return [
            'low_deposit'      => (int) $lowDeposit,
            'bad_review'       => (int) ($bad['count'] ?? 0),
            'bad_review_shops' => (int) ($bad['restaurants'] ?? 0),
            'total'            => (int) $lowDeposit + (int) ($bad['restaurants'] ?? 0),
        ];
    }

    /* ───────────────────────── 整页契约 buildSummary() ───────────────────────── */

    public static function buildSummary(): array
    {
        $c       = self::counts();
        $rateCny = (float) (DB::table('business_settings')->where('key', 'nezha_rate_cny_to_amd')->value('value') ?: 55);

        $disputeOn  = (int) (DB::table('business_settings')->where('key', 'nezha_refund_dispute_status')->value('value') ?? 0) === 1;

        // ── 待办总览条(0 桶隐藏) ── href: 有对应卡的锚到卡, 无卡的(风控)直链源页, 防死点。
        $pulse = [];
        $pulseAdd = function ($key, $label, $count, $tone, $href) use (&$pulse) {
            if ($count > 0) {
                $pulse[] = ['key' => $key, 'label' => $label, 'count' => (int) $count, 'tone' => $tone, 'href' => $href];
            }
        };
        $pulseAdd('money', '逾期退款', $c['refund_overdue'], 'red', '#nzt-card-money');
        $pulseAdd('money', '争议裁决', $disputeOn ? $c['disputes'] : 0, 'red', '#nzt-card-money');
        $pulseAdd('funds', '充值/押金待审', $c['funds_pending'], 'amb', '#nzt-card-funds');
        $pulseAdd('exceptions', '订单异常', $c['order_exceptions'], 'amb', '#nzt-card-exceptions');
        $pulseAdd('audit', '内容待审', $c['audit_total'], 'nvy', '#nzt-card-audit');
        $pulseAdd('risk', '风控队列', $c['risk_queue'], 'amb', self::routeOr('admin.nezha-risk.queue'));

        // ── ① 钱的队列 ──
        $overdueRows = [];
        foreach (array_slice($c['refund_rows'], 0, self::ROWS) as $r) {
            $overdueRows[] = [
                'order_id'    => $r['order_id'],
                'shop'        => $r['shop'] ?: ('单 #' . $r['order_id']),
                'channel'     => $r['channel'],
                'amount_amd'  => Helpers::format_currency($r['amount']),
                'amount_cny'  => self::cny($r['amount'], $rateCny),
                'overdue_hr'  => $r['overdue_hr'],
                'overdue_txt' => self::overdueText($r['overdue_hr']),
                'tone'        => ($r['overdue_hr'] !== null && $r['overdue_hr'] >= 24) ? 'red' : 'amb',
                'route'       => self::orderDetailRoute($r['order_id']),
            ];
        }
        $money = [
            'total'         => (int) $c['refund_overdue'] + ($disputeOn ? (int) $c['disputes'] : 0),
            'overdue_count' => (int) $c['refund_overdue'],
            'overdue_rows'  => $overdueRows,
            'dispute_on'    => $disputeOn,
            'dispute_count' => $disputeOn ? (int) $c['disputes'] : 0,
            'dispute_route' => self::routeOr('admin.nezha-refund.disputes'),
            'more_route'    => self::routeOr('admin.nezha-refund.overdue'),
        ];

        // ── ② 资金审核队列(dormant 关时 total=0 → 卡隐藏) ──
        $funds = [
            'total'  => (int) $c['funds_pending'],
            'rows'   => self::fundsRows($rateCny),
            'topup_route'    => self::routeOr('admin.nezha-topup.index'),
            'refund_route'   => self::routeOr('admin.nezha-topup.refunds'),
            'offboard_route' => self::routeOr('admin.nezha-offboard.index'),
        ];

        // ── ③ 订单异常 ──
        $excRows = [];
        foreach (array_slice($c['exception_rows'], 0, self::ROWS) as $r) {
            $excRows[] = [
                'id'         => $r['id'],
                'shop'       => $r['shop'] ?: ('单 #' . $r['id']),
                'reason'     => $r['reason'],
                'wait_min'   => $r['wait_min'],
                'wait_txt'   => self::minutesText($r['wait_min']),
                'tone'       => ($r['wait_min'] !== null && $r['wait_min'] >= 120) ? 'red' : 'amb',
                'route'      => self::orderDetailRoute($r['id']),
            ];
        }
        $exceptions = [
            'count'      => (int) $c['order_exceptions'],
            'rows'       => $excRows,
            'more_route' => self::routeOr('admin.order.list', ['grp_pending']),
        ];

        // ── ④ 审核台(评价段已去除, 四段: UGC/入驻/广告/KYC) ──
        $a = $c['audit'];
        $audit = [
            'total'    => (int) $c['audit_total'],
            'segments' => array_values(array_filter([
                ['key' => 'ugc',        'label' => 'UGC 帖',  'count' => (int) $a['ugc'],        'route' => self::routeOr('admin.local-life.list')],
                ['key' => 'onboarding', 'label' => '入驻',     'count' => (int) $a['onboarding'], 'route' => self::routeOr('admin.merchant-lead.list')],
                ['key' => 'ad',         'label' => '广告',     'count' => (int) $a['ad'],         'route' => self::routeOr('admin.advertisement.requestList')],
                ['key' => 'kyc',        'label' => 'KYC',     'count' => (int) $a['kyc'],        'route' => self::routeOr('admin.nezha-kyc.index')],
            ], function ($s) {
                return $s['count'] > 0;
            })),
        ];

        // ── ⑤ 商家健康 ──
        $merchant = [
            'total' => (int) $c['merchant']['total'],
            'rows'  => self::merchantRows($rateCny),
            'more_route' => self::routeOr('admin.restaurant.list'),
        ];

        // ── 右① 今日经营 ──
        $ts = self::todaySales();
        $today = [
            'orders'         => (int) $ts['orders'],
            'collected_amd'  => Helpers::format_currency($ts['collected']),
            'collected_cny'  => self::cny($ts['collected'], $rateCny),
            'commission_amd' => Helpers::format_currency($ts['commission']),
            'commission_cny' => self::cny($ts['commission'], $rateCny),
        ];

        // ── 右② 系统健康 / 右③ 反馈日报 / 右④ 差评预警 ──
        $sys        = self::systemHealth();
        $digest     = self::feedbackDigest();
        $badReview  = [
            'count' => (int) $c['merchant']['bad_review'],
            'shops' => (int) $c['merchant']['bad_review_shops'],
            'route' => self::badReviewRoute(),
        ];

        return [
            'pulse'      => $pulse,
            'money'      => $money,
            'funds'      => $funds,
            'exceptions' => $exceptions,
            'audit'      => $audit,
            'merchant'   => $merchant,
            'today'      => $today,
            'sys'        => $sys,
            'digest'     => $digest,
            'bad_review' => $badReview,
            'rates'      => ['cny' => $rateCny],
            'has_any'    => ($money['total'] + $funds['total'] + $exceptions['count'] + $audit['total'] + $merchant['total']) > 0,
        ];
    }

    /* ───────────────────────── 卡行构造(全部 safe 包裹) ───────────────────────── */

    /** ② 资金审核行(充值/押金退款: NezhaTopupRequest pending; 退出结算: offboard active)。 */
    protected static function fundsRows(float $rateCny): array
    {
        return self::safeArr(function () use ($rateCny) {
            $rows = [];
            $reqs = \App\Models\NezhaTopupRequest::with('restaurant')
                ->whereIn('status', ['pending'])
                ->whereIn('direction', ['topup', 'refund'])
                ->orderBy('id')->limit(self::ROWS)->get();
            foreach ($reqs as $q) {
                $label = $q->direction === 'refund'
                    ? '押金退回'
                    : (($q->account_type ?? '') === 'guarantee' ? '押金补足' : (($q->account_type ?? '') === 'ad' ? '广告充值' : '佣金充值'));
                $amt = (float) ($q->amount ?? 0);
                $rows[] = [
                    'shop'       => optional($q->restaurant)->name ?: ('商家 #' . ($q->restaurant_id ?? '')),
                    'label'      => $label,
                    'amount_amd' => Helpers::format_currency($amt),
                    'amount_cny' => self::cny($amt, $rateCny),
                    'route'      => $q->direction === 'refund' ? self::routeOr('admin.nezha-topup.refunds') : self::routeOr('admin.nezha-topup.index'),
                ];
            }
            return $rows;
        }, []);
    }

    /** ⑤ 商家健康行: 低押金店(≤阈值) + 差评未回复店(平台窗口), 合并 ≤5 行。 */
    protected static function merchantRows(float $rateCny): array
    {
        return self::safeArr(function () use ($rateCny) {
            $rows = [];
            $depoMode = (int) (BusinessSetting::where('key', 'nezha_deposit_mode_status')->value('value') ?? 0);
            $threshold = (float) (BusinessSetting::where('key', 'nezha_min_deposit_threshold')->value('value') ?? 0);

            if ($depoMode === 1 && $threshold > 0) {
                $low = Restaurant::with('wallet')->where('nezha_commission_enabled', 1)
                    ->whereHas('wallet', function ($q) use ($threshold) {
                        $q->where('deposit_balance', '<=', $threshold);
                    })
                    ->orderBy('id')->limit(self::ROWS)->get();
                foreach ($low as $r) {
                    $bal = (float) ($r->wallet->deposit_balance ?? 0);
                    $rows[] = [
                        'shop'      => $r->name,
                        'kind'      => 'low_deposit',
                        'chip'      => '押金低于阈值',
                        'tone'      => 'red',
                        'meta'      => '剩 ' . Helpers::format_currency($bal) . '（阈值 ' . Helpers::format_currency($threshold) . '）',
                        'route'     => self::restaurantViewRoute($r->id),
                    ];
                }
            }

            // 差评未回复店(取几家, 补足到 5 行)
            $need = self::ROWS - count($rows);
            if ($need > 0) {
                foreach (NezhaBadReview::platformShops($need) as $shop) {
                    $rows[] = [
                        'shop'  => $shop['name'],
                        'kind'  => 'bad_review',
                        'chip'  => '差评未回复 ×' . $shop['count'],
                        'tone'  => 'amb',
                        'meta'  => '最近 ' . NezhaBadReview::WINDOW_DAYS . ' 天内',
                        'route' => self::restaurantViewRoute($shop['id']),
                    ];
                }
            }

            return array_slice($rows, 0, self::ROWS);
        }, []);
    }

    /** 右① 今日经营(平台版): 单量 + 商家自收款(记录口径) + 应计佣金(commission_deduction 记账口径)。 */
    public static function todaySales(): array
    {
        return self::safeArr(function () {
            $orders = (int) Order::withoutGlobalScope(ZoneScope::class)
                ->whereDate('created_at', Carbon::today())->Notpos()->count();

            $collected = (float) Order::withoutGlobalScope(ZoneScope::class)
                ->whereDate('created_at', Carbon::today())
                ->where('payment_status', 'paid')
                ->whereNotIn('order_status', ['canceled', 'failed', 'refunded'])
                ->Notpos()->sum('order_amount');

            // 应计佣金 = 今日「commission_deduction」记账行合计(B方案佣金从押金逐单扣, 与对账中心同源)
            $commission = (float) DB::table('restaurant_deposit_transactions')
                ->where('type', 'commission_deduction')
                ->whereDate('created_at', Carbon::today())
                ->sum('commission');

            return ['orders' => $orders, 'collected' => $collected, 'commission' => abs($commission)];
        }, ['orders' => 0, 'collected' => 0.0, 'commission' => 0.0]);
    }

    /** 右② 系统健康: 只放有现成可读源的行(队列积压 redis)。cron/nzwatch/发信额度无源 → 不显示。 */
    public static function systemHealth(): array
    {
        $rows = [];
        $backlog = self::safe(function () {
            return (int) \Illuminate\Support\Facades\Queue::size();
        }, -1);
        if ($backlog >= 0) {
            $rows[] = ['label' => '队列积压', 'value' => (string) $backlog, 'ok' => $backlog < 100];
        }
        return ['rows' => $rows];
    }

    /** 右③ 反馈日报: nezha_feedback_digests 最新一期(无表/无数据 → null → 卡隐藏)。 */
    public static function feedbackDigest(): ?array
    {
        return self::safe(function () {
            if (! \Illuminate\Support\Facades\Schema::hasTable('nezha_feedback_digests')) {
                return null;
            }
            $d = DB::table('nezha_feedback_digests')->orderByDesc('digest_date')->orderByDesc('id')->first();
            if (! $d) {
                return null;
            }
            return [
                'date'     => $d->digest_date,
                'summary'  => $d->summary,
                'counts'   => $d->counts ? json_decode($d->counts, true) : null,
                'degraded' => (bool) $d->degraded,
            ];
        }, null);
    }

    /* ───────────────────────── 小工具 ───────────────────────── */

    /** ≈¥ 换算(整数, 与商家作业台同口径 amd / rateCny)。 */
    protected static function cny(float $amd, float $rateCny): ?int
    {
        return $rateCny > 0 ? (int) round($amd / $rateCny) : null;
    }

    protected static function refundChannel($rr): string
    {
        $ch = $rr->payment_channel ?? null;
        if ($ch === 'usdt') {
            return 'USDT';
        }
        if ($ch === 'rmb') {
            return '支付宝';
        }
        return $ch ? (string) $ch : '原渠道';
    }

    protected static function overdueText(?int $hr): string
    {
        if ($hr === null) {
            return '';
        }
        if ($hr < 24) {
            return '逾期 ' . $hr . ' 小时';
        }
        return '逾期 ' . intdiv($hr, 24) . ' 天';
    }

    protected static function minutesText(?int $min): string
    {
        if ($min === null) {
            return '';
        }
        if ($min < 60) {
            return $min . ' 分钟';
        }
        if ($min < 1440) {
            return intdiv($min, 60) . ' 小时';
        }
        return intdiv($min, 1440) . ' 天';
    }

    protected static function orderDetailRoute($orderId): string
    {
        return self::routeOr('admin.order.details', [$orderId]);
    }

    protected static function restaurantViewRoute($rid): string
    {
        $r = self::routeOr('admin.restaurant.view', [$rid]);
        return $r ?: self::routeOr('admin.restaurant.list');
    }

    protected static function badReviewRoute(): string
    {
        return self::routeOr('admin.restaurant.list');
    }

    /** route() 安全包裹: 路由不存在时返回 '#'(不因命名差异 500)。 */
    protected static function routeOr(string $name, array $params = []): string
    {
        try {
            return route($name, $params);
        } catch (\Throwable $e) {
            return '#';
        }
    }

    protected static function safe(callable $fn, $default = 0)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('NezhaAdminDashboard: ' . $e->getMessage());
            return $default;
        }
    }

    protected static function safeArr(callable $fn, array $default): array
    {
        try {
            $r = $fn();
            return is_array($r) ? $r : $default;
        } catch (\Throwable $e) {
            Log::warning('NezhaAdminDashboard: ' . $e->getMessage());
            return $default;
        }
    }
}
