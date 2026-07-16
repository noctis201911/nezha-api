<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒 平台集运申报 — 管理端需求汇总。
 * KPI(提交数/意向率/预估月总货量[体积·重量·箱数]/覆盖品类) + 品类汇总 + 频率分布
 * + 商家提交列表(带近90天平台成交额, 供判断深度合作商家) + 详情 + 导出CSV。
 * 「预估月货量」= 各商家货量档中位数 × 频率(次/月) 的合计, 按填报单位分体积/重量/箱数口径(不混加)。
 * 成交额 = 近90天已确认订单口径(平台内 GMV 代理值)。仅读, 不碰钱。
 */
class NezhaConsolidationController extends Controller
{
    private const VOL_MID  = ['<1' => 0.5, '1-3' => 2, '3-5' => 4, '5-10' => 7.5, '>10' => 12];     // m³
    private const WT_MID   = ['<100' => 50, '100-500' => 300, '500-1000' => 750, '>1000' => 1200];  // kg
    private const FREQ_PM  = ['weekly' => 4.3, 'biweekly' => 2, 'monthly' => 1, 'quarterly' => 0.33, 'irregular' => 0.5]; // 次/月
    private const CONFIRMED = ['confirmed', 'processing', 'handover', 'picked_up', 'delivered'];

    public function index(Request $request)
    {
        $intentFilter = in_array($request->get('intent'), ['yes', 'maybe', 'no']) ? $request->get('intent') : null;
        $sort = in_array($request->get('sort'), ['gmv', 'recent']) ? $request->get('sort') : 'gmv';

        $surveys = DB::table('nezha_consolidation_surveys')->get();

        $total = $surveys->count();
        $intentYes = $surveys->where('intent', 'yes')->count();
        $catSet = [];
        $volSum = 0.0;
        $wtSum = 0.0;
        $boxSum = 0.0;
        $catAgg = [];   // 中文标签 => ['count'=>, 'vol'=>, 'wt'=>]
        $freqAgg = ['weekly' => 0, 'biweekly' => 0, 'monthly' => 0, 'quarterly' => 0, 'irregular' => 0];

        $rowMetrics = [];
        foreach ($surveys as $s) {
            $pm = self::FREQ_PM[$s->frequency] ?? 0.5;
            $rowVol = ($s->volume_unit === 'm3' && isset(self::VOL_MID[$s->volume_m3])) ? self::VOL_MID[$s->volume_m3] * $pm : 0.0;
            $rowWt  = ($s->volume_unit === 'kg' && isset(self::WT_MID[$s->weight_kg])) ? self::WT_MID[$s->weight_kg] * $pm : 0.0;
            $rowBox = ($s->volume_unit === 'box' && (int) $s->box_count) ? (int) $s->box_count * $pm : 0.0;
            $volSum += $rowVol;
            $wtSum += $rowWt;
            $boxSum += $rowBox;
            $rowMetrics[$s->id] = ['vol' => $rowVol, 'wt' => $rowWt, 'box' => $rowBox];

            foreach ((json_decode($s->categories ?: '[]', true) ?: []) as $c) {
                $catSet[$c] = true;
                $catAgg[$c] = $catAgg[$c] ?? ['count' => 0, 'vol' => 0.0, 'wt' => 0.0];
                $catAgg[$c]['count']++;
                $catAgg[$c]['vol'] += $rowVol;
                $catAgg[$c]['wt'] += $rowWt;
            }
            if (isset($freqAgg[$s->frequency])) {
                $freqAgg[$s->frequency]++;
            }
        }
        uasort($catAgg, fn ($a, $b) => $b['vol'] <=> $a['vol']);

        $kpi = [
            'total'       => $total,
            'intent_yes'  => $intentYes,
            'intent_rate' => $total ? (int) round($intentYes * 100 / $total) : 0,
            'vol_month'   => (int) round($volSum),
            'wt_month_t'  => round($wtSum / 1000, 1),
            'box_month'   => (int) round($boxSum),
            'cats'        => count($catSet),
        ];

        // 近90天平台成交额(已确认订单口径)
        $rids = $surveys->pluck('restaurant_id')->filter()->unique()->values()->all();
        $gmvMap = [];
        if ($rids) {
            $rows = DB::table('orders')
                ->whereIn('restaurant_id', $rids)
                ->where('created_at', '>=', now()->subDays(90))
                ->whereIn('order_status', self::CONFIRMED)
                ->groupBy('restaurant_id')
                ->select('restaurant_id', DB::raw('SUM(order_amount) as gmv'), DB::raw('COUNT(*) as cnt'))
                ->get();
            foreach ($rows as $r) {
                $gmvMap[$r->restaurant_id] = ['gmv' => (float) $r->gmv, 'cnt' => (int) $r->cnt];
            }
        }
        $nameMap = Restaurant::whereIn('id', $rids ?: [0])->pluck('name', 'id')->all();

        $list = $surveys->map(function ($s) use ($gmvMap, $nameMap, $rowMetrics) {
            $g = $gmvMap[$s->restaurant_id] ?? ['gmv' => 0.0, 'cnt' => 0];
            $s->rname = $nameMap[$s->restaurant_id] ?? ('Vendor #' . $s->vendor_id);
            $s->gmv90 = $g['gmv'];
            $s->cnt90 = $g['cnt'];
            $s->cat_list = json_decode($s->categories ?: '[]', true) ?: [];
            $s->est_vol = $rowMetrics[$s->id]['vol'] ?? 0.0;
            $s->est_wt = $rowMetrics[$s->id]['wt'] ?? 0.0;
            $s->stale = \App\CentralLogics\NezhaConsolidation::isStale($s->updated_at); // A-3: >90天数据陈旧标记
            return $s;
        });
        if ($intentFilter) {
            $list = $list->where('intent', $intentFilter);
        }
        $list = ($sort === 'gmv') ? $list->sortByDesc('gmv90') : $list->sortByDesc('updated_at');
        $list = $list->values();

        // A-2 未填名单: 全体 restaurants 减去已提交 vendor, 供运营定向联系(店名/电话/近90天成交/状态)。仅管理端可见, 不进任何对外物料。
        $submittedVendorIds = $surveys->pluck('vendor_id')->filter()->unique()->values()->all();
        $unfilled = Restaurant::when(!empty($submittedVendorIds), function ($q) use ($submittedVendorIds) {
                $q->whereNotIn('vendor_id', $submittedVendorIds);
            })
            ->select('id', 'name', 'phone', 'vendor_id', 'status')
            ->orderBy('name')->get();
        $ufRids = $unfilled->pluck('id')->filter()->values()->all();
        $ufGmv = [];
        if ($ufRids) {
            foreach (DB::table('orders')
                ->whereIn('restaurant_id', $ufRids)
                ->where('created_at', '>=', now()->subDays(90))
                ->whereIn('order_status', self::CONFIRMED)
                ->groupBy('restaurant_id')
                ->select('restaurant_id', DB::raw('SUM(order_amount) as gmv'))->get() as $r) {
                $ufGmv[$r->restaurant_id] = (float) $r->gmv;
            }
        }
        $unfilledList = $unfilled->map(function ($r) use ($ufGmv) {
            $r->gmv90 = $ufGmv[$r->id] ?? 0.0;
            return $r;
        })->values();

        return view('admin-views.nezha-consolidation.index', compact('kpi', 'catAgg', 'freqAgg', 'list', 'intentFilter', 'sort', 'unfilledList'));
    }

    public function show($id)
    {
        $s = DB::table('nezha_consolidation_surveys')->where('id', $id)->first();
        if (!$s) {
            abort(404);
        }
        $rest = Restaurant::find($s->restaurant_id);
        $s->rname = optional($rest)->name ?? ('Vendor #' . $s->vendor_id);
        $s->phone = optional($rest)->phone;
        $g = DB::table('orders')
            ->where('restaurant_id', $s->restaurant_id)
            ->where('created_at', '>=', now()->subDays(90))
            ->whereIn('order_status', self::CONFIRMED)
            ->selectRaw('COALESCE(SUM(order_amount),0) as gmv, COUNT(*) as cnt')->first();
        $s->gmv90 = (float) ($g->gmv ?? 0);
        $s->cnt90 = (int) ($g->cnt ?? 0);

        return view('admin-views.nezha-consolidation.show', compact('s'));
    }

    public function export()
    {
        $surveys = DB::table('nezha_consolidation_surveys')->orderByDesc('updated_at')->get();
        $nameMap = Restaurant::whereIn('id', $surveys->pluck('restaurant_id')->filter()->unique()->values()->all() ?: [0])
            ->pluck('name', 'id')->all();

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="consolidation_surveys_' . date('Ymd_His') . '.csv"',
        ];

        return response()->stream(function () use ($surveys, $nameMap) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM, Excel 友好
            fputcsv($out, ['商家', '意向', '品类', '其它品类', '货量单位', '体积桶', '重量桶', '箱数', '频率', '时效', '目前物流成本', '期望降幅', '愿推荐服务方', '推荐信息', '现状', '痛点', '建议', '提交时间']);
            $arr = fn ($j) => implode(' / ', json_decode($j ?: '[]', true) ?: []);
            foreach ($surveys as $s) {
                fputcsv($out, [
                    $nameMap[$s->restaurant_id] ?? ('Vendor #' . $s->vendor_id),
                    $s->intent, $arr($s->categories), $s->category_other,
                    $s->volume_unit, $s->volume_m3, $s->weight_kg, $s->box_count,
                    $s->frequency, $s->lead_time, $s->current_cost, $s->expected_saving,
                    $s->refer_provider, $s->refer_provider_info,
                    $arr($s->current_channels), $arr($s->pain_points), $s->suggestion, $s->updated_at,
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }
}
