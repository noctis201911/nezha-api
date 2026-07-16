<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaConsolidationRound;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Brian2694\Toastr\Facades\Toastr;

/**
 * 哪吒 平台集运 · 期次报名(包2 · B骨架 — 商家端)。
 *
 * 平台按"期次(round)"发布货代 / 价格 / 截止日; 商家在"报名中"的期次里登记本店预估货量与品类意向。
 * 全 dormant, 由总闸 NezhaConsolidationRound::enabled() 统一门禁; 闸关时期次 / 报名入口整体不透出(零透出)。
 *
 * 🔴 红线(勿破):
 *  - 平台只展示 pricing, 无任何代收 / 收款入口; 付款由商家在平台外直接与货代结算(费用文案逐字用 FEE_NOTE, 在 blade 侧渲染)。
 *  - 一切增删改按 Helpers::get_vendor_id() 作用域, 只能动自己的报名; 一店一期一份(靠表 UNIQUE(round_id, vendor_id))。
 *  - 仅当期次 status=open 且未过截止(cutoff_at)才可报名 / 改 / 撤。
 *
 * 依赖(指挥窗地基, 本控制器只调用):
 *  - App\CentralLogics\NezhaConsolidationRound::enabled():bool           总闸
 *  - App\CentralLogics\NezhaConsolidationRound::progress($round):array   成团进度(只同单位统计)
 *  - App\CentralLogics\NezhaConsolidationRound::CATEGORIES               品类白名单(label + is_food)
 *  - 表 nezha_consolidation_rounds / nezha_consolidation_enrollments     指挥窗迁移建
 */
class NezhaConsolidationRoundController extends Controller
{
    /**
     * 商家端期次报名页。
     * 闸关 → 404(零透出); 闸开 → 展示当前"报名中"期次卡 + 本店报名态 + 报名 / 改 / 撤表单。
     */
    public function index()
    {
        // 门禁: 总闸 + 每店集运资格(与侧栏 gate 双保险)。
        $this->gate();

        $vendorId = Helpers::get_vendor_id();

        // 当前"报名中"期次: 取最早截止(最紧迫)的一期; 无截止的排后, 再退到最新 id。
        $round = DB::table('nezha_consolidation_rounds')
            ->where('status', 'open')
            ->orderByRaw('cutoff_at IS NULL asc')
            ->orderBy('cutoff_at', 'asc')
            ->orderByDesc('id')
            ->first();

        $enrollment = null;
        $progress   = null;
        if ($round) {
            // 本店在该期次的报名(含已撤销行 —— 靠 UNIQUE 一店一份; 撤销后 store 复位即可重新报名)。
            $enrollment = DB::table('nezha_consolidation_enrollments')
                ->where('round_id', $round->id)
                ->where('vendor_id', $vendorId)
                ->first();
            $progress = NezhaConsolidationRound::progress($round);
        }

        // v1 需求问卷(仅用于首次报名的品类 + 货量单位 / 档预填, 不参与鉴权)。
        $survey = DB::table('nezha_consolidation_surveys')->where('vendor_id', $vendorId)->first();

        return view('vendor-views.nezha-consolidation-rounds.index', compact(
            'round', 'enrollment', 'progress', 'survey'
        ));
    }

    /**
     * 报名(首次) —— 按 (round_id, vendor_id) upsert; 已存在(含之前撤销)则更新并复位为 enrolled。
     */
    public function store(Request $request)
    {
        $this->gate();

        $request->validate([
            'round_id'         => 'required|integer',
            'est_volume_value' => 'nullable|numeric|min:0|max:99999999.99',
            'est_volume_unit'  => 'nullable|in:m3,kg,box',
            'categories'       => 'nullable|array',
            'note'             => 'nullable|string|max:500',
        ], [
            'round_id.required' => translate('缺少期次参数'),
        ]);

        $vendorId = Helpers::get_vendor_id();

        // 期次须存在且可报名(open + 未过截止), 否则拒绝 / 提示。
        [$round, $mutable] = $this->roundState($request->round_id);
        if (!$round) {
            abort(404);
        }
        if (!$mutable) {
            Toastr::error(translate('本期集运报名已截止或已关闭。'));
            return back();
        }

        $restaurant = Restaurant::where('vendor_id', $vendorId)->first();

        $data = [
            'restaurant_id'    => $restaurant->id ?? null,
            'est_volume_value' => $this->cleanVolume($request->est_volume_value),
            'est_volume_unit'  => in_array($request->est_volume_unit, ['m3', 'kg', 'box'], true) ? $request->est_volume_unit : null,
            'categories'       => json_encode($this->cleanCategories($request->categories), JSON_UNESCAPED_UNICODE),
            'note'             => $this->clip($request->note, 500),
            'status'           => 'enrolled',
            'updated_at'       => now(),
        ];

        $exists = DB::table('nezha_consolidation_enrollments')
            ->where('round_id', $round->id)->where('vendor_id', $vendorId)->exists();
        if ($exists) {
            DB::table('nezha_consolidation_enrollments')
                ->where('round_id', $round->id)->where('vendor_id', $vendorId)
                ->update($data);
        } else {
            $data['round_id']   = $round->id;
            $data['vendor_id']  = $vendorId;
            $data['created_at'] = now();
            DB::table('nezha_consolidation_enrollments')->insert($data);
        }

        Toastr::success(translate('已报名本期集运，可在截止前随时修改或撤销。'));
        return back();
    }

    /**
     * 修改本店在某期次的报名(截止前)。$id = enrollment 主键。
     */
    public function update(Request $request, $id)
    {
        $this->gate();

        $request->validate([
            'est_volume_value' => 'nullable|numeric|min:0|max:99999999.99',
            'est_volume_unit'  => 'nullable|in:m3,kg,box',
            'categories'       => 'nullable|array',
            'note'             => 'nullable|string|max:500',
        ]);

        $vendorId = Helpers::get_vendor_id();

        // IDOR: 只取属于本店的报名; 取不到即 404(不泄露他人报名是否存在)。
        $enrollment = DB::table('nezha_consolidation_enrollments')
            ->where('id', $id)->where('vendor_id', $vendorId)->first();
        if (!$enrollment) {
            abort(404);
        }

        // 所属期次须仍可报名(open + 未过截止)。
        [$round, $mutable] = $this->roundState($enrollment->round_id);
        if (!$round) {
            abort(404);
        }
        if (!$mutable) {
            Toastr::error(translate('本期集运报名已截止或已关闭，无法修改。'));
            return back();
        }

        DB::table('nezha_consolidation_enrollments')
            ->where('id', $id)->where('vendor_id', $vendorId)
            ->update([
                'est_volume_value' => $this->cleanVolume($request->est_volume_value),
                'est_volume_unit'  => in_array($request->est_volume_unit, ['m3', 'kg', 'box'], true) ? $request->est_volume_unit : null,
                'categories'       => json_encode($this->cleanCategories($request->categories), JSON_UNESCAPED_UNICODE),
                'note'             => $this->clip($request->note, 500),
                'status'           => 'enrolled', // 修改即视为在报名中(覆盖任何历史撤销态)
                'updated_at'       => now(),
            ]);

        Toastr::success(translate('已更新本期集运报名。'));
        return back();
    }

    /**
     * 撤销本店在某期次的报名(截止前; 置 status=canceled, 不物理删除, 便于统计与再报名)。
     */
    public function cancel(Request $request, $id)
    {
        $this->gate();

        $vendorId = Helpers::get_vendor_id();

        // IDOR: 只取属于本店的报名; 取不到即 404。
        $enrollment = DB::table('nezha_consolidation_enrollments')
            ->where('id', $id)->where('vendor_id', $vendorId)->first();
        if (!$enrollment) {
            abort(404);
        }

        // 截止前才可撤销。
        [$round, $mutable] = $this->roundState($enrollment->round_id);
        if (!$round) {
            abort(404);
        }
        if (!$mutable) {
            Toastr::error(translate('本期集运报名已截止或已关闭，无法撤销。'));
            return back();
        }

        DB::table('nezha_consolidation_enrollments')
            ->where('id', $id)->where('vendor_id', $vendorId)
            ->update(['status' => 'canceled', 'updated_at' => now()]);

        Toastr::success(translate('已撤销本期集运报名。'));
        return back();
    }

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

    /**
     * 统一门禁: 总闸 + 每店「集运资格」(运营手动标记)。
     * 🔴 集运仅面向经营达标的深度合作商家(业主 2026-07-16 定), 故报名入口/报名动作一律按资格收口。
     * 任一不过 → 404(与闸关同为零透出, 不泄露"有这功能但你没资格"; 直连 URL 也进不来)。
     * 注: 首页提示卡与 v1 问卷**不受此门限制**(需求摸底面向全体·业主裁决)。
     */
    private function gate(): void
    {
        if (!NezhaConsolidationRound::enabled()) {
            abort(404);
        }
        if (!NezhaConsolidationRound::eligibleByVendor(Helpers::get_vendor_id())) {
            abort(404);
        }
    }

    /**
     * 读期次并判定是否可增删改。
     * @return array{0: object|null, 1: bool} [round(或 null), mutable]
     *   mutable = status=open 且 (cutoff_at 为空 视为不设截止 | now < cutoff_at)。
     */
    private function roundState($roundId)
    {
        $round = DB::table('nezha_consolidation_rounds')->where('id', $roundId)->first();
        if (!$round) {
            return [null, false];
        }
        $mutable = $round->status === 'open'
            && (empty($round->cutoff_at) || now()->lt(Carbon::parse($round->cutoff_at)));
        return [$round, $mutable];
    }

    /** 货量清洗: 归一到 DECIMAL(10,2) 值域; 空 / 非数字 → null。 */
    private function cleanVolume($v)
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        $n = round((float) $v, 2);
        if ($n < 0) {
            $n = 0;
        }
        if ($n > 99999999.99) {
            $n = 99999999.99;
        }
        return $n;
    }

    /** 品类清洗: 只保留 CATEGORIES 白名单内的标签, 去重(防注入任意值)。 */
    private function cleanCategories($arr)
    {
        if (!is_array($arr)) {
            return [];
        }
        $allowed = array_column(NezhaConsolidationRound::CATEGORIES, 'label');
        $out = [];
        foreach ($arr as $x) {
            $x = trim((string) $x);
            if ($x !== '' && in_array($x, $allowed, true) && !in_array($x, $out, true)) {
                $out[] = $x;
            }
        }
        return $out;
    }

    /** 文本裁剪: trim + 限长; 空 → null。 */
    private function clip($v, $max)
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
