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
        'nezha_refund_bscscan_api_key'      => '',
        'nezha_refund_trongrid_api_key'     => '',
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
        if ($status !== 'all') {
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
        foreach (array_keys($this->cfgKeys) as $key) {
            if ($key === 'nezha_risk_contact_info') {
                $value = (string) $request->input($key, '');   // 客服联系方式允许清空
            } else {
                if (!$request->has($key)) {
                    continue;                                   // 未提交则保留原值
                }
                $value = (string) $request->input($key);
            }
            BusinessSetting::updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()]
            );
        }
        Toastr::success(translate('风控设置已保存'));

        return back();
    }

    /** 放行: 该顾客在宽限期内重新下单直接通过 */
    public function approve(Request $request, $id)
    {
        $rec = NezhaRiskRecord::findOrFail($id);
        $rec->status          = 'approved';
        $rec->reviewed_by     = auth('admin')->id();
        $rec->reviewed_at     = now();
        $rec->review_note     = $request->input('note');
        $rec->disposal_result = '放行 (顾客可在宽限期内重新下单付款)';
        $rec->save();
        Toastr::success(translate('已放行, 顾客可在宽限期内重新下单付款'));

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
        $rec->disposal_result = '清退/拒绝 (不予放行)';
        $rec->save();
        Toastr::warning(translate('已清退该订单'));

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
}
