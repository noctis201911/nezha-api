<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\NezhaRiskRecord;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;

/**
 * 哪吒风控① 后台「风控中心」.
 *  - queue    : 人工审核队列 (action=review & status=pending) — 放行/退款/清退
 *  - logs     : 全部风控命中记录 (审计日志, 只读, 可按状态筛选)
 *  - settings : 风控阈值设置 (全部可调, 不硬编码)
 */
class NezhaRiskController extends Controller
{
    /** 风控阈值配置项的 key 列表 (设置页读写 + 默认值) */
    private array $cfgKeys = [
        'nezha_risk_control_status'         => '1',
        'nezha_risk_single_order_limit'     => '100',
        'nezha_risk_daily_cumulative_limit' => '300',
        'nezha_risk_freq_24h_count'         => '5',
        'nezha_risk_freq_10min_count'       => '2',
        'nezha_risk_round_amount_flag'      => '1',
        'nezha_risk_large_amount_threshold' => '80',
        'nezha_risk_usdt_single_limit'      => '200',
        'nezha_risk_usdt_daily_limit'       => '500',
        'nezha_risk_approval_grace_minutes' => '60',
        'nezha_risk_contact_info'           => '',
        // 退款控制 (机制②) — 独立于上方下单风控
        'nezha_refund_control_status'       => '0',
        'nezha_refund_single_limit'         => '100',
        'nezha_refund_daily_total_limit'    => '300',
        'nezha_refund_daily_count_limit'    => '5',
        'nezha_refund_window_days'          => '7',
        'nezha_refund_usdt_verify_status'   => '1',
        'nezha_usdt_refund_binding_mode'    => 'drain',
        'nezha_usdt_refund_legal_gate'      => 'pending',
        'nezha_refund_reconfirm_ttl_seconds'=> '300',
        'nezha_refund_bsc_finality_blocks'  => '12',
        'nezha_refund_tron_finality_blocks' => '20',
        'nezha_refund_sanction_max_sync_age_hours' => '48',
        'nezha_refund_bscscan_api_key'      => '',
        'nezha_refund_trongrid_api_key'     => '',
        // 制裁筛查 (机制② L1-6) — USDT 付款来源地址比对 OFAC SDN, 命中即拒收
        'nezha_sanction_screen_status'      => '1',
        'nezha_sanction_source_url'         => 'https://sanctionslistservice.ofac.treas.gov/api/PublicationPreview/exports/SDN.XML',
        'nezha_sanction_last_sync'          => '',
        'nezha_sanction_inconclusive_action' => 'hold',   // 反查不出来源时: hold=拦截待人工(默认) / allow=放行+留痕
    ];

    /** 人工审核队列 */
    public function queue(Request $request)
    {
        $records = NezhaRiskRecord::with(['user', 'restaurant'])
            ->where('action', 'review')
            ->where('status', 'pending')
            ->orderBy('id', 'desc')
            ->paginate(25);

        $pending_count = NezhaRiskRecord::where('action', 'review')->where('status', 'pending')->count();

        return view('admin-views.nezha-risk.queue', compact('records', 'pending_count'));
    }

    /** 全部风控日志 (审计) */
    public function logs(Request $request)
    {
        $status = $request->get('status', 'all');
        $query = NezhaRiskRecord::with(['user', 'restaurant'])->orderBy('id', 'desc');
        if ($status === 'sanction') {
            // 制裁筛查相关记录(命中 sanction / 未决 sanction_inconclusive)
            $query->where('hit_rules', 'like', '%sanction%');
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        $records = $query->paginate(30)->appends(['status' => $status]);

        return view('admin-views.nezha-risk.logs', compact('records', 'status'));
    }

    /** 风控设置页 */
    public function settings()
    {
        $cfg = BusinessSetting::whereIn('key', array_keys($this->cfgKeys))->pluck('value', 'key')->toArray();
        // 用默认补齐未种入的 key (如 approval_grace)
        $cfg = array_merge($this->cfgKeys, $cfg);

        return view('admin-views.nezha-risk.settings', compact('cfg'));
    }

    /** 保存风控设置 (开关用下拉传 1/0, 阈值用数字; 未提交的字段保留原值) */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'nezha_usdt_refund_binding_mode' => 'sometimes|required|in:enforce,drain,closed',
            'nezha_usdt_refund_legal_gate' => 'sometimes|required|in:pending,approved,rejected',
            'nezha_refund_reconfirm_ttl_seconds' => 'sometimes|required|integer|min:60|max:600',
            'nezha_refund_bsc_finality_blocks' => 'sometimes|required|integer|min:1|max:200',
            'nezha_refund_tron_finality_blocks' => 'sometimes|required|integer|min:1|max:500',
            'nezha_refund_sanction_max_sync_age_hours' => 'sometimes|required|integer|min:1|max:168',
        ]);
        // 安全(P0-b): 链上 API 密钥仅超级管理员可改; 非超管提交一律跳过(防掩码值覆盖真值)
        $nzIsSuper  = auth('admin')->check() && auth('admin')->user()->role_id == 1;
        if (! $nzIsSuper && ($request->has('nezha_usdt_refund_binding_mode')
            || $request->has('nezha_usdt_refund_legal_gate'))) {
            abort(403, 'Only a super administrator may change USDT refund release gates.');
        }
        $secretKeys = ['nezha_refund_bscscan_api_key', 'nezha_refund_trongrid_api_key'];

        // SEC-3 审计: 风控阈值=L2 业务参数(须留痕)。收集本次实际变更, 写一行审计。
        // 🔴 密钥(secretKeys)只记"键名已变更", 绝不把明文写进 before/after。
        $auditBefore    = [];
        $auditAfter     = [];
        $secretsChanged = [];

        foreach (array_keys($this->cfgKeys) as $key) {
            if (in_array($key, $secretKeys, true) && ! $nzIsSuper) {
                continue;
            }
            if ($key === 'nezha_risk_contact_info') {
                $value = (string) $request->input($key, '');   // 客服联系方式允许清空
            } else {
                if (!$request->has($key)) {
                    continue;                                   // 未提交则保留原值
                }
                $value = (string) $request->input($key);
            }

            $old = optional(BusinessSetting::where('key', $key)->first())->value;
            if ((string) $old !== $value) {
                if (in_array($key, $secretKeys, true)) {
                    $secretsChanged[] = $key;                   // 密钥: 只记键名, 不记任何明文
                } else {
                    $auditBefore[$key] = $old;
                    $auditAfter[$key]  = $value;
                }
            }

            BusinessSetting::updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()]
            );
        }

        if (!empty($auditBefore) || !empty($secretsChanged)) {
            $after = $auditAfter;
            if (!empty($secretsChanged)) {
                $after['_secrets_changed'] = $secretsChanged;   // 仅键名
            }
            \App\Models\AdminAuditLog::record('risk_settings_update', 'business_settings', null, $auditBefore, $after);
        }

        Toastr::success(translate('风控设置已保存'));

        return back();
    }

    /** 该风控记录是否为商家 KYC 姓名筛查(非订单)记录 —— 决定处置结论/提示用 KYC 语境而非订单语境。 */
    private static function isKycRecord(NezhaRiskRecord $rec): bool
    {
        return collect($rec->hit_rules ?? [])->contains(
            fn ($h) => \Illuminate\Support\Str::startsWith($h['rule'] ?? '', 'sanction_kyc_name')
        );
    }

    /** 放行: 该顾客在宽限期内重新下单直接通过 */
    public function approve(Request $request, $id)
    {
        $rec = NezhaRiskRecord::findOrFail($id);
        $rec->status          = 'approved';
        $rec->reviewed_by     = auth('admin')->id();
        $rec->reviewed_at     = now();
        $rec->review_note     = $request->input('note');
        if (self::isKycRecord($rec)) {
            $rec->disposal_result = '核实无误·解除疑似标记 (已人工核对: 非制裁名单同一人, 商家 KYC 姓名筛查疑似解除)';
            $rec->save();
            Toastr::success(translate('已核实无误, 解除该商家 KYC 姓名疑似标记'));
        } else {
            $rec->disposal_result = '放行 (顾客可在宽限期内重新下单付款)';
            $rec->save();
            Toastr::success(translate('已放行, 顾客可在宽限期内重新下单付款'));
        }

        return back();
    }

    /** 清退/拒绝该订单 */
    public function reject(Request $request, $id)
    {
        $rec = NezhaRiskRecord::findOrFail($id);
        $rec->status          = 'rejected';
        $rec->reviewed_by     = auth('admin')->id();
        $rec->reviewed_at     = now();
        $rec->review_note     = $request->input('note');
        if (self::isKycRecord($rec)) {
            $rec->disposal_result = '确认命中制裁名单 (法人/受益人姓名经核实确认命中 OFAC SDN, 不予合作/拒绝入驻, L1-6)';
            $rec->save();
            Toastr::warning(translate('已确认命中制裁名单, 已标记拒绝该商家 KYC'));
        } else {
            $rec->disposal_result = '清退/拒绝 (不予放行)';
            $rec->save();
            Toastr::warning(translate('已清退该订单'));
        }

        return back();
    }

    /**
     * 退款留痕 (B方案平台不持币: 系统只锁定"原路退回"并留痕, 实际由商家原路退给顾客).
     * 原路 = 原支付方式/原卡/原钱包 (人民币) 或 原USDT付款地址.
     */
    public function refund(Request $request, $id)
    {
        $rec = NezhaRiskRecord::findOrFail($id);
        $route = $rec->payment_channel === 'usdt' ? '原USDT付款地址' : '原人民币付款方式(微信/支付宝)';
        $rec->status          = 'cleared';
        $rec->reviewed_by     = auth('admin')->id();
        $rec->reviewed_at     = now();
        $rec->review_note     = $request->input('note');
        $rec->disposal_result = '退款: 仅原路退回 ' . $route . ' (金额≤原订单, 禁止退至第三方; 商家执行, 平台留痕)';
        $rec->save();
        Toastr::success(translate('退款指令已记录: 仅允许原路退回'));

        return back();
    }

    /**
     * 制裁筛查「未决(反查不出来源)」记录 —— 人工核实来源地址后放行并确认收款 (L1-6 人工复核闸口).
     *
     * 仅适用于 sanction_inconclusive 的待复核记录(命中 reject 的是 status=auto、不进本队列, 无法经此放行)。
     * 动作: 以 allow_inconclusive=true 重新走 confirm_offline_payment ——
     *   - 重新筛查若此时反查出【命中制裁名单】→ 仍自动拒收(本方法捕获异常、不放行, 把记录转 rejected);
     *   - 仍查不出 / 或已可反查且干净 → 越过"暂挂"完成确认收款(放行出餐), 记录转 approved。
     * 即: 人工只能放行"查不出", 永远不能放行"已命中"。要求填核实备注(合规留痕: 管理员声明已在区块浏览器核对来源)。
     */
    public function release_inconclusive(Request $request, $id)
    {
        $rec = NezhaRiskRecord::findOrFail($id);

        $isInconclusive = collect($rec->hit_rules ?? [])
            ->contains(fn ($h) => ($h['rule'] ?? '') === 'sanction_inconclusive');
        if ($rec->action !== 'review' || $rec->status !== 'pending' || !$isInconclusive) {
            Toastr::warning(translate('该记录不是待复核的制裁筛查未决项, 无法经此放行。'));
            return back();
        }

        // 合规留痕: 必须填写核实说明(管理员声明已核对来源地址)
        $note = trim((string) $request->input('note', ''));
        if ($note === '') {
            Toastr::error(translate('请先在备注填写来源地址核实结论(如已在区块浏览器核对该地址不在制裁名单), 再放行。'));
            return back();
        }

        $order = \App\Models\Order::with('offline_payments')->find($rec->order_id);
        if (!$order) {
            Toastr::error(translate('关联订单不存在。'));
            return back();
        }
        if ($order->payment_method !== 'offline_payment' || !$order->offline_payments || $order->offline_payments->status !== 'pending') {
            // 订单已被其它途径处理(已确认/已拒收), 仅收尾本复核记录, 不再重复确认。
            $rec->status          = 'approved';
            $rec->reviewed_by     = auth('admin')->id();
            $rec->reviewed_at     = now();
            $rec->review_note     = $note;
            $rec->disposal_result = '订单已被其它途径处理(非待确认收款), 复核记录归档。';
            $rec->save();
            Toastr::warning(translate('订单已不是待确认收款状态, 已归档本复核记录。'));
            return back();
        }

        try {
            \App\CentralLogics\OrderLogic::confirm_offline_payment($order, 'admin', auth('admin')->id(), true);
        } catch (\App\Exceptions\SanctionScreenException $e) {
            // 重新筛查这次反查出【命中制裁名单】→ 已自动拒收, 不予放行。
            $rec->status          = 'rejected';
            $rec->reviewed_by     = auth('admin')->id();
            $rec->reviewed_at     = now();
            $rec->review_note     = $note;
            $rec->disposal_result = '重新筛查命中制裁名单, 已自动拒收, 不予放行(L1-6)。';
            $rec->save();
            Toastr::error(translate('重新筛查发现来源命中制裁名单, 已拒收, 不予放行。'));
            return back();
        }

        $rec->status          = 'approved';
        $rec->reviewed_by     = auth('admin')->id();
        $rec->reviewed_at     = now();
        $rec->review_note     = $note;
        $rec->disposal_result = '人工核实来源地址后放行并确认收款(制裁筛查未决人工复核)。';
        $rec->save();

        Toastr::success(translate('已人工放行并确认收款。'));
        return back();
    }
}
