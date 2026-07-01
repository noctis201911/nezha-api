<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 方案C: 搜索需求后台页。
 * 读 nezha_search_terms(全量搜索埋点, 匿名聚合)：
 *   - view=hot  热门搜索(按搜索次数)
 *   - view=miss 搜了没结果(zero_result_count>0, 按无结果次数) = 未被满足的需求
 * 过滤: 类型(商品/餐厅) / zone / 最近N天 / 关键词。分页 + 导出CSV。纯只读。
 */
class NezhaSearchDemandController extends Controller
{
    /** 按请求过滤构建 nezha_search_terms 查询(不排序/不分页)。返回 [builder, view, type, zone, days, search]。 */
    private function buildQuery(Request $request): array
    {
        $view   = $request->get('view', 'hot') === 'miss' ? 'miss' : 'hot';
        $type   = in_array($request->get('type'), ['product', 'restaurant'], true) ? $request->get('type') : 'all';
        $zone   = $request->get('zone', 'all');
        $days   = max(0, (int) $request->get('days', 0));
        $search = trim((string) $request->get('search', ''));

        $q = DB::table('nezha_search_terms')
            ->when($type !== 'all', fn ($x) => $x->where('search_type', $type))
            ->when(is_numeric($zone), fn ($x) => $x->where('zone_id', (int) $zone))
            ->when($days > 0, fn ($x) => $x->where('last_seen_at', '>=', Carbon::now()->subDays($days)->format('Y-m-d H:i:s')))
            ->when($search !== '', fn ($x) => $x->where('keyword', 'like', '%' . $search . '%'))
            ->when($view === 'miss', fn ($x) => $x->where('zero_result_count', '>', 0));

        return [$q, $view, $type, $zone, $days, $search];
    }

    public function index(Request $request)
    {
        $zones = Schema::hasTable('zones') ? DB::table('zones')->orderBy('name')->get(['id', 'name']) : collect();

        if (! Schema::hasTable('nezha_search_terms')) {
            $terms = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 30);
            $summary = ['distinct_terms' => 0, 'total_hits' => 0, 'zero_terms' => 0, 'zero_hits' => 0, 'switch_on' => true];
            return view('admin-views.nezha-search-demand.index', compact('terms', 'summary', 'zones') + [
                'view' => 'hot', 'type' => 'all', 'zone' => 'all', 'days' => 0, 'search' => '',
            ]);
        }

        [$q, $view, $type, $zone, $days, $search] = $this->buildQuery($request);

        $summary = [
            'distinct_terms' => (int) (clone $q)->count(),
            'total_hits'     => (int) (clone $q)->sum('hit_count'),
            'zero_terms'     => (int) (clone $q)->where('zero_result_count', '>', 0)->count(),
            'zero_hits'      => (int) (clone $q)->sum('zero_result_count'),
            'switch_on'      => (string) \App\CentralLogics\Helpers::get_business_settings('nezha_search_log_status') !== '0',
        ];

        $orderCol = $view === 'miss' ? 'zero_result_count' : 'hit_count';
        $terms = $q->orderByDesc($orderCol)->orderByDesc('last_seen_at')->paginate(30)->appends($request->all());

        return view('admin-views.nezha-search-demand.index', compact('terms', 'summary', 'view', 'type', 'zone', 'days', 'search', 'zones'));
    }

    public function export(Request $request)
    {
        if (! Schema::hasTable('nezha_search_terms')) {
            return back();
        }
        [$q, $view] = $this->buildQuery($request);
        $orderCol = $view === 'miss' ? 'zero_result_count' : 'hit_count';
        $rows = $q->orderByDesc($orderCol)->orderByDesc('last_seen_at')->limit(5000)->get();

        $filename = 'search_demand_' . $view . '_' . date('Ymd_His') . '.csv';
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM, 让 Excel 正确识别中文
            fputcsv($out, ['关键词', '类型', 'zone_id', '搜索次数', '无结果次数', '无结果率', '最近搜索']);
            foreach ($rows as $r) {
                $rate = $r->hit_count > 0 ? round($r->zero_result_count / $r->hit_count * 100, 1) . '%' : '—';
                fputcsv($out, [$r->keyword, $r->search_type, $r->zone_id, $r->hit_count, $r->zero_result_count, $rate, $r->last_seen_at]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
