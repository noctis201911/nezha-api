<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\CentralLogics\NezhaConsolidationRound;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Brian2694\Toastr\Facades\Toastr;

/**
 * 哪吒 平台集运 · 期次管理 (包2·B) — 管理端。
 * 运营在此预建/编辑集运「期次」(round): 设标题·截止时间·ETD/ETA·成团目标货量·报价与货代信息,
 * 走 draft → open → closed 的生命周期(可随时 cancel); open 的期次商家端才可见并报名(总闸另控)。
 *
 * 🔴 平台不碰钱: 全程无任何代收/收款入口, 只「展示」pricing; 费用文案逐字用 NezhaConsolidationRound::FEE_NOTE。
 * 🔴 导出脱敏: 给货代的 CSV 默认用「商家#{id}」代称, 仅期次号/标题/货量/单位/品类/报名时间, 绝不含 GMV/电话/成本;
 *    admin 页面内(内部视图)可显真名, 导出加 ?names=1 才显真名。
 * 全 dormant + additive: 总闸 NezhaConsolidationRound::enabled() 控商家端可见性, 管理端始终可预建。
 *
 * 数据: nezha_consolidation_rounds(期次) / nezha_consolidation_enrollments(报名) — 由指挥窗迁移建表。
 * 成团进度只同单位统计(NezhaConsolidationRound::progress), 其它单位计入 other_count, 绝不跨单位换算相加。
 * 风格: DB::table + translate('中文'), 与现有 NezhaConsolidationController 一致; admin 全局无 IDOR 面。
 */
class NezhaConsolidationRoundController extends Controller
{
    /** 可编辑的期次状态(草稿/报名中): 已截止/已取消不再改明细 */
    private const EDITABLE = ['draft', 'open'];

    /**
     * 期次列表: 期次号 / 标题 / 状态 badge / 报名数 / 成团进度摘要 + 新建入口。
     */
    public function index()
    {
        $rounds = DB::table('nezha_consolidation_rounds')->orderByDesc('id')->get();

        // 逐期取成团进度(只同单位统计); 期次量小(约按月建), 逐行调用可接受, 不做批量优化以免过度构建。
        $rows = $rounds->map(function ($r) {
            $r->progress = NezhaConsolidationRound::progress($r);
            return $r;
        });

        // 总闸状态: 关闭时管理端仍可预建, 仅提示商家端尚不可见(在场感知)。
        $switchOn = NezhaConsolidationRound::enabled();

        return view('admin-views.nezha-consolidation-rounds.index', compact('rows', 'switchOn'));
    }

    /**
     * 新建期次表单。
     */
    public function create()
    {
        return view('admin-views.nezha-consolidation-rounds.create');
    }

    /**
     * 保存新期次(状态 draft): 校验 + 生成期次号 + 落库。
     */
    public function store(Request $request)
    {
        $data = $this->validateRound($request);

        $id = DB::table('nezha_consolidation_rounds')->insertGetId([
            'round_no'         => NezhaConsolidationRound::generateRoundNo(),
            'title'            => $data['title'],
            'status'           => 'draft',
            'cutoff_at'        => $this->parseDateTime($request->input('cutoff_at')),
            'etd'              => $this->nullIfBlank($request->input('etd')),
            'eta'              => $this->nullIfBlank($request->input('eta')),
            'min_volume_value' => $request->filled('min_volume_value') ? $data['min_volume_value'] : null,
            'min_volume_unit'  => $data['min_volume_unit'],
            'pricing_info'     => json_encode($this->pricingPayload($request), JSON_UNESCAPED_UNICODE),
            'forwarder_info'   => json_encode($this->forwarderPayload($request), JSON_UNESCAPED_UNICODE),
            'notes'            => $this->nullIfBlank($request->input('notes')),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        Toastr::success(translate('期次已创建(草稿)'));
        return redirect()->route('admin.nezha-consolidation-rounds.show', $id);
    }

    /**
     * 编辑期次表单(仅 draft / open)。
     */
    public function edit($id)
    {
        $round = $this->findOr404($id);
        if (!in_array($round->status, self::EDITABLE, true)) {
            Toastr::warning(translate('已截止或已取消的期次不可编辑'));
            return redirect()->route('admin.nezha-consolidation-rounds.show', $id);
        }
        $pricing = json_decode($round->pricing_info ?: '{}', true) ?: [];
        $forwarder = json_decode($round->forwarder_info ?: '{}', true) ?: [];

        return view('admin-views.nezha-consolidation-rounds.edit', compact('round', 'pricing', 'forwarder'));
    }

    /**
     * 更新期次(仅 draft / open; 不改期次号与状态, 状态经开放/截止/取消动作单独流转)。
     */
    public function update(Request $request, $id)
    {
        $round = $this->findOr404($id);
        if (!in_array($round->status, self::EDITABLE, true)) {
            Toastr::warning(translate('已截止或已取消的期次不可编辑'));
            return back();
        }
        $data = $this->validateRound($request);

        DB::table('nezha_consolidation_rounds')->where('id', $id)->update([
            'title'            => $data['title'],
            'cutoff_at'        => $this->parseDateTime($request->input('cutoff_at')),
            'etd'              => $this->nullIfBlank($request->input('etd')),
            'eta'              => $this->nullIfBlank($request->input('eta')),
            'min_volume_value' => $request->filled('min_volume_value') ? $data['min_volume_value'] : null,
            'min_volume_unit'  => $data['min_volume_unit'],
            'pricing_info'     => json_encode($this->pricingPayload($request), JSON_UNESCAPED_UNICODE),
            'forwarder_info'   => json_encode($this->forwarderPayload($request), JSON_UNESCAPED_UNICODE),
            'notes'            => $this->nullIfBlank($request->input('notes')),
            'updated_at'       => now(),
        ]);

        Toastr::success(translate('期次已更新'));
        return redirect()->route('admin.nezha-consolidation-rounds.show', $id);
    }

    /**
     * 开放报名: draft → open (仅从 draft)。
     */
    public function open($id)
    {
        $round = $this->findOr404($id);
        if ($round->status !== 'draft') {
            Toastr::warning(translate('只有草稿状态的期次可以开放报名'));
            return back();
        }
        DB::table('nezha_consolidation_rounds')->where('id', $id)->update([
            'status'     => 'open',
            'updated_at' => now(),
        ]);
        // 包3 开期通知在此接入
        Toastr::success(translate('期次已开放报名'));
        return back();
    }

    /**
     * 截止报名: open → closed (仅从 open)。
     */
    public function close($id)
    {
        $round = $this->findOr404($id);
        if ($round->status !== 'open') {
            Toastr::warning(translate('只有报名中的期次可以截止'));
            return back();
        }
        DB::table('nezha_consolidation_rounds')->where('id', $id)->update([
            'status'     => 'closed',
            'updated_at' => now(),
        ]);
        Toastr::success(translate('期次已截止'));
        return back();
    }

    /**
     * 取消期次: 任意 → canceled。有报名者时的二次确认由 show 视图的 JS 确认弹窗负责。
     */
    public function cancel($id)
    {
        $round = $this->findOr404($id);
        if ($round->status === 'canceled') {
            Toastr::warning(translate('该期次已是取消状态'));
            return back();
        }
        DB::table('nezha_consolidation_rounds')->where('id', $id)->update([
            'status'     => 'canceled',
            'updated_at' => now(),
        ]);
        Toastr::success(translate('期次已取消'));
        return back();
    }

    /**
     * 期次详情: 期次信息 + 报名明细(店名/货量/品类/报名时间/状态) + 成团进度 + FEE_NOTE + 导出 + 状态操作。
     * admin 内部视图可显真名(导出才脱敏)。
     */
    public function show($id)
    {
        $round = $this->findOr404($id);

        $enrollments = DB::table('nezha_consolidation_enrollments')
            ->where('round_id', $id)
            ->orderByDesc('id')->get();

        // 店名: 内部视图直接显真名(优先 restaurant_id 映射, 无则 vendor_id 代称)。
        $rids = $enrollments->pluck('restaurant_id')->filter()->unique()->values()->all();
        $nameMap = $rids ? Restaurant::whereIn('id', $rids)->pluck('name', 'id')->all() : [];

        // 品类标签 + 食品标记(食品类旁显示 FOOD_HINT)。
        $catLabels = array_column(NezhaConsolidationRound::CATEGORIES, 'label');
        $foodLabelSet = array_column(
            array_filter(NezhaConsolidationRound::CATEGORIES, fn ($c) => !empty($c['is_food'])),
            'label'
        );
        $enrollments = $enrollments->map(function ($e) use ($catLabels, $foodLabelSet) {
            $labels = $this->categoryLabels($e->categories, $catLabels);
            $e->cat_labels = $labels;
            $e->has_food = count(array_intersect($labels, $foodLabelSet)) > 0;
            return $e;
        });

        $progress = NezhaConsolidationRound::progress($round);
        $pricing = json_decode($round->pricing_info ?: '{}', true) ?: [];
        $forwarder = json_decode($round->forwarder_info ?: '{}', true) ?: [];

        return view('admin-views.nezha-consolidation-rounds.show', compact(
            'round', 'enrollments', 'nameMap', 'progress', 'pricing', 'forwarder'
        ));
    }

    /**
     * 导出脱敏 CSV(给货代)。默认代称「商家#{id}」; ?names=1 才显真名。
     * 🔴 只含 期次号/标题/商家/预估货量/单位/品类/报名时间; 绝不含 GMV/电话/成本。只导 enrolled(有效报名)。
     */
    public function export(Request $request, $id)
    {
        $round = $this->findOr404($id);

        $showNames = $request->get('names') == '1';
        $enrollments = DB::table('nezha_consolidation_enrollments')
            ->where('round_id', $id)
            ->where('status', 'enrolled')
            ->orderByDesc('id')->get();

        $nameMap = [];
        if ($showNames) {
            $rids = $enrollments->pluck('restaurant_id')->filter()->unique()->values()->all();
            $nameMap = $rids ? Restaurant::whereIn('id', $rids)->pluck('name', 'id')->all() : [];
        }

        $unitLabels = NezhaConsolidationRound::UNIT_LABELS;
        $catLabels = array_column(NezhaConsolidationRound::CATEGORIES, 'label');

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="consolidation_round_' . $round->round_no . '_' . date('Ymd_His') . '.csv"',
        ];

        return response()->stream(function () use ($round, $enrollments, $nameMap, $unitLabels, $catLabels, $showNames) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM, Excel 友好
            fputcsv($out, ['期次号', '期次标题', '商家', '预估货量', '单位', '品类', '报名时间']);
            foreach ($enrollments as $e) {
                $merchant = $showNames
                    ? ($nameMap[$e->restaurant_id] ?? ('商家#' . ($e->restaurant_id ?: $e->vendor_id)))
                    : ('商家#' . ($e->restaurant_id ?: $e->vendor_id));
                fputcsv($out, [
                    $round->round_no,
                    $round->title,
                    $merchant,
                    $e->est_volume_value,
                    $unitLabels[$e->est_volume_unit] ?? $e->est_volume_unit,
                    implode(' / ', $this->categoryLabels($e->categories, $catLabels)),
                    $e->created_at,
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }

    // ---------- 内部工具 ----------

    /** 取期次或 404 */
    private function findOr404($id)
    {
        $round = DB::table('nezha_consolidation_rounds')->where('id', $id)->first();
        if (!$round) {
            abort(404);
        }
        return $round;
    }

    /** 期次表单校验(create/update 共用) */
    private function validateRound(Request $request): array
    {
        return $request->validate([
            'title'            => 'required|string|max:191',
            'cutoff_at'        => 'nullable|date',
            'etd'              => 'nullable|date',
            'eta'              => 'nullable|date',
            'min_volume_value' => 'nullable|numeric|min:0',
            'min_volume_unit'  => 'required|in:m3,kg,box',
            'unit_price'       => 'nullable|string|max:191',
            'lead_time'        => 'nullable|string|max:191',
            'declare_method'   => 'nullable|string|max:500',
            'forwarder_contact' => 'nullable|string|max:255',
            'forwarder_name'   => 'nullable|string|max:191',
            'notes'            => 'nullable|string|max:2000',
        ]);
    }

    /** 报价 JSON: 单价 / 时效 / 申报方式 / 货代联系方式 (只展示, 无收款) */
    private function pricingPayload(Request $request): array
    {
        return [
            'unit_price'        => trim((string) $request->input('unit_price')),
            'lead_time'         => trim((string) $request->input('lead_time')),
            'declare_method'    => trim((string) $request->input('declare_method')),
            'forwarder_contact' => trim((string) $request->input('forwarder_contact')),
        ];
    }

    /** 货代 JSON: 名称/公司 */
    private function forwarderPayload(Request $request): array
    {
        return [
            'name' => trim((string) $request->input('forwarder_name')),
        ];
    }

    /** datetime-local(Y-m-dTH:i) → MySQL DATETIME; 空则 null */
    private function parseDateTime($val): ?string
    {
        if ($val === null || $val === '') {
            return null;
        }
        return \Carbon\Carbon::parse($val)->format('Y-m-d H:i:s');
    }

    /** 空串归 null */
    private function nullIfBlank($val): ?string
    {
        return ($val === null || $val === '') ? null : $val;
    }

    /**
     * 报名 categories(JSON) → 中文品类标签数组。
     * 商家端存储格式未知, 防御性兼容: 整数(下标)映射 CATEGORIES, 字符串(标签)原样用。
     */
    private function categoryLabels($json, array $catLabels): array
    {
        $arr = json_decode($json ?: '[]', true) ?: [];
        $out = [];
        foreach ($arr as $c) {
            if (is_int($c) || (is_string($c) && ctype_digit($c))) {
                $out[] = $catLabels[(int) $c] ?? (string) $c;
            } else {
                $out[] = (string) $c;
            }
        }
        return $out;
    }
}
