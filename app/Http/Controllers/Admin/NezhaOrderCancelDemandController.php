<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒: 顾客取消理由后台分析页 (纯只读 L3, 无新表)。
 * 数据源 = orders 表。口径按「下单时间」cohort(取消一般紧随下单, created_at≈取消时刻, 让取消率有意义):
 *   - 主报表: order_status='canceled' AND canceled_by='customer' 的单, 按 cancellation_reason 分组。
 *       两条取消路径都汇到 cancellation_reason(商家同意接单后取消申请时 finalize_cancellation 已抄入),
 *       用 nezha_cancel_request 区分: 空=未接单自助取消 / 'approved'=接单后申请获准。
 *   - 副面板: nezha_cancel_request IN ('requested','rejected') 的单(顾客想取消但被拒/待处理, 订单可能仍履约),
 *       按 nezha_cancel_request_reason 分组 = 履约摩擦信号。
 * 补充说明(note)含顾客自由输入=可能 PII: 明细/导出默认打码, 仅超管(role_id=1)可见完整。
 */
class NezhaOrderCancelDemandController extends Controller
{
    private function hasReq(): bool
    {
        return Schema::hasColumn('orders', 'nezha_cancel_request');
    }

    /** 主 cohort: 按下单时间 + 店铺过滤的 orders 基础查询。 */
    private function baseQuery(Request $request)
    {
        $days = max(0, (int) $request->get('days', 30));
        $rid  = $request->get('restaurant', 'all');

        return DB::table('orders')
            ->when($days > 0, fn ($x) => $x->where('created_at', '>=', Carbon::now()->subDays($days)->format('Y-m-d H:i:s')))
            ->when(is_numeric($rid), fn ($x) => $x->where('restaurant_id', (int) $rid));
    }

    /** 已被顾客取消的单 (两路径合并)。 */
    private function canceledCustomer(Request $request)
    {
        return $this->baseQuery($request)
            ->where('order_status', 'canceled')
            ->where('canceled_by', 'customer');
    }

    public function index(Request $request)
    {
        $days    = max(0, (int) $request->get('days', 30));
        $rid     = $request->get('restaurant', 'all');
        $search  = trim((string) $request->get('search', ''));
        $isSuper = auth('admin')->check() && auth('admin')->user()->role_id == 1;
        $hasReq  = $this->hasReq();

        $restaurants = Schema::hasTable('restaurants')
            ? DB::table('restaurants')->orderBy('name')->get(['id', 'name'])
            : collect();

        // ── 汇总卡 (按下单时间 cohort) ──
        $totalOrders  = (int) $this->baseQuery($request)->count();
        $canceledBase = $this->canceledCustomer($request);
        $canceledCnt  = (int) (clone $canceledBase)->count();
        $selfCnt      = $hasReq
            ? (int) (clone $canceledBase)->where(fn ($x) => $x->whereNull('nezha_cancel_request')->orWhere('nezha_cancel_request', ''))->count()
            : $canceledCnt;
        $approvedCnt  = $hasReq ? (int) (clone $canceledBase)->where('nezha_cancel_request', 'approved')->count() : 0;
        $attemptedCnt = $hasReq ? (int) $this->baseQuery($request)->whereIn('nezha_cancel_request', ['requested', 'rejected'])->count() : 0;

        $summary = [
            'total_orders' => $totalOrders,
            'canceled'     => $canceledCnt,
            'cancel_rate'  => $totalOrders > 0 ? round($canceledCnt / $totalOrders * 100, 1) : 0,
            'self'         => $selfCnt,
            'approved'     => $approvedCnt,
            'attempted'    => $attemptedCnt,
        ];

        // ── 理由分布 (主视图) ──
        $dist = (clone $canceledBase)
            ->when($search !== '', fn ($x) => $x->where('cancellation_reason', 'like', '%' . $search . '%'))
            ->selectRaw("COALESCE(NULLIF(cancellation_reason, ''), '(未填理由)') as reason, COUNT(*) as c")
            ->groupBy('reason')->orderByDesc('c')->get();

        // ── 按店铺 (顾客取消最多的店) ──
        $byRestaurant = (clone $canceledBase)
            ->selectRaw('restaurant_id, COUNT(*) as c')
            ->groupBy('restaurant_id')->orderByDesc('c')->limit(10)->get();
        $rnameMap = $restaurants->pluck('name', 'id');

        // ── 明细 ──
        $detailCols = ['id', 'restaurant_id', 'cancellation_reason', 'cancellation_note', 'canceled', 'created_at'];
        if ($hasReq) {
            $detailCols[] = 'nezha_cancel_request';
        }
        $detail = (clone $canceledBase)
            ->when($search !== '', fn ($x) => $x->where('cancellation_reason', 'like', '%' . $search . '%'))
            ->orderByDesc('canceled')
            ->paginate(30, $detailCols)->appends($request->all());

        // ── 副面板: 想取消被拒/待处理 ──
        $attemptedRows = collect();
        if ($hasReq && Schema::hasColumn('orders', 'nezha_cancel_request_reason')) {
            $attemptedRows = $this->baseQuery($request)
                ->whereIn('nezha_cancel_request', ['requested', 'rejected'])
                ->selectRaw("COALESCE(NULLIF(nezha_cancel_request_reason, ''), '(未填理由)') as reason, nezha_cancel_request as st, COUNT(*) as c")
                ->groupBy('reason', 'st')->orderByDesc('c')->get();
        }

        return view('admin-views.nezha-order-cancel-demand.index', compact(
            'summary', 'dist', 'byRestaurant', 'rnameMap', 'detail', 'attemptedRows',
            'restaurants', 'days', 'rid', 'search', 'isSuper', 'hasReq'
        ));
    }

    public function export(Request $request)
    {
        $isSuper = auth('admin')->check() && auth('admin')->user()->role_id == 1;
        $hasReq  = $this->hasReq();
        $search  = trim((string) $request->get('search', ''));

        $cols = ['id', 'restaurant_id', 'cancellation_reason', 'cancellation_note', 'canceled'];
        if ($hasReq) {
            $cols[] = 'nezha_cancel_request';
        }
        $rows = $this->canceledCustomer($request)
            ->when($search !== '', fn ($x) => $x->where('cancellation_reason', 'like', '%' . $search . '%'))
            ->orderByDesc('canceled')->limit(5000)->get($cols);

        $rnameMap = Schema::hasTable('restaurants')
            ? DB::table('restaurants')->pluck('name', 'id')
            : collect();

        $filename = 'order_cancel_reasons_' . date('Ymd_His') . '.csv';
        return response()->streamDownload(function () use ($rows, $rnameMap, $isSuper, $hasReq) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM, 让 Excel 正确识别中文
            fputcsv($out, ['订单号', '店铺', '取消理由', '补充说明', '取消时间', '路径']);
            foreach ($rows as $r) {
                $path = $hasReq ? (($r->nezha_cancel_request ?? '') === 'approved' ? '接单后申请获准' : '未接单自助取消') : '—';
                $note = $r->cancellation_note;
                if (! $isSuper && $note) {
                    $note = '••• (超管可见)';
                }
                fputcsv($out, [
                    $r->id,
                    $rnameMap[$r->restaurant_id] ?? ('#' . $r->restaurant_id),
                    $r->cancellation_reason,
                    $note ?: '',
                    $r->canceled,
                    $path,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
