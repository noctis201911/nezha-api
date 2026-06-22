<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NezhaRefundRecord;
use App\CentralLogics\NezhaRefundControl;
use App\CentralLogics\Helpers;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;

/**
 * 哪吒 退款机制② 后台「退款留痕/审核」.
 *  - records   : 退款留痕列表 + 超限审核队列(pending_admin)
 *  - submitTx  : USDT 登记商家退款 tx hash → 链上校验(金额+原路地址)
 *  - uploadProof: 上传退款凭证(法币截图/USDT辅助)
 *  - approve/reject: 超限退款审核放行/拒绝
 */
class NezhaRefundController extends Controller
{
    /** 退款留痕列表 + 超限审核队列 */
    public function records(Request $request)
    {
        $status = $request->get('status', 'all');
        $query = NezhaRefundRecord::with(['order', 'restaurant'])->orderBy('id', 'desc');
        if ($status === 'pending') {
            $query->where('status', 'pending_admin');
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        $records = $query->paginate(30)->appends(['status' => $status]);
        $pending_count = NezhaRefundRecord::where('status', 'pending_admin')->count();

        return view('admin-views.nezha-refund.records', compact('records', 'status', 'pending_count'));
    }

    /** USDT: 登记商家退款 tx hash → 链上校验(收款方==锁定地址 且 金额≥退款额) */
    public function submitTx(Request $request, $id)
    {
        $rec = NezhaRefundRecord::findOrFail($id);
        $hash = trim((string) $request->input('refund_tx_hash'));
        if ($hash === '') {
            Toastr::warning(translate('请填写退款交易哈希'));
            return back();
        }
        $chain = $rec->chain ?: NezhaRefundControl::detect_chain($hash);
        $res = NezhaRefundControl::verify_refund_tx($hash, $chain, $rec->locked_to_address, (float) $rec->refund_amount);

        $rec->refund_tx_hash      = $hash;
        $rec->chain               = $chain;
        $rec->chain_verify_status = $res['status'];
        $rec->chain_verify_detail = $res['detail'];
        $rec->save();

        $map = [
            'verified' => '链上校验通过: 退款金额与原付款地址均匹配',
            'failed'   => '链上校验未通过: 金额或收款地址与原付款不符, 请核对(禁止退第三方)',
            'manual'   => '已登记退款哈希; 链上自动校验未完成, 待人工核对',
        ];
        $msg = $map[$res['status']] ?? '已登记';
        if ($res['status'] === 'failed') {
            Toastr::warning(translate($msg));
        } else {
            Toastr::success(translate($msg));
        }

        return back();
    }

    /** 上传退款凭证(法币退款截图 / USDT 辅助截图) */
    public function uploadProof(Request $request, $id)
    {
        $rec = NezhaRefundRecord::findOrFail($id);
        if ($request->hasFile('refund_proof_image')) {
            $name = Helpers::upload('refund_proof/', 'png', $request->file('refund_proof_image'));
            $rec->refund_proof_image = $name;
            $rec->save();
            Toastr::success(translate('退款凭证已上传'));
        } else {
            Toastr::warning(translate('请选择要上传的图片'));
        }

        return back();
    }

    /** 超限退款审核: 放行(该订单下次退款豁免限额, 请回订单页重新执行) */
    public function approve(Request $request, $id)
    {
        $rec = NezhaRefundRecord::findOrFail($id);
        $rec->status      = 'approved';
        $rec->reviewed_by = auth('admin')->id();
        $rec->reviewed_at = now();
        $rec->review_note = $request->input('note');
        $rec->save();
        Toastr::success(translate('已放行: 请回订单详情页重新执行该退款(限额已对此订单豁免)'));

        return back();
    }

    /** 超限退款审核: 拒绝 */
    public function reject(Request $request, $id)
    {
        $rec = NezhaRefundRecord::findOrFail($id);
        $rec->status      = 'rejected';
        $rec->reviewed_by = auth('admin')->id();
        $rec->reviewed_at = now();
        $rec->review_note = $request->input('note');
        $rec->save();
        Toastr::warning(translate('已拒绝该退款'));

        return back();
    }

    /** 逾期未退款列表(pending_merchant_refund 且尚未标记退款) + 运营手动停/解除接单 */
    public function overdue(Request $request)
    {
        $remindHours  = \App\CentralLogics\NezhaRefundOverdue::thresholdHours('nezha_refund_overdue_remind_hours', 'nezha_refund_overdue_remind_days', 12);
        $suspendHours = \App\CentralLogics\NezhaRefundOverdue::thresholdHours('nezha_refund_overdue_suspend_hours', 'nezha_refund_overdue_suspend_days', 72);
        $status      = (int) (\App\Models\BusinessSetting::where('key', 'nezha_refund_overdue_status')->value('value') ?? 0);

        $records = NezhaRefundRecord::with(['order', 'restaurant'])
            ->where('status', 'pending_merchant_refund')
            ->whereNull('merchant_refunded_at')
            ->orderBy('created_at', 'asc')
            ->paginate(30)
            ->appends($request->all());

        $suspended = \App\Models\Restaurant::where('nezha_order_suspended', 1)
            ->orderByDesc('nezha_suspended_at')
            ->get(['id', 'name', 'nezha_order_suspended', 'nezha_suspend_reason', 'nezha_suspended_at']);

        return view('admin-views.nezha-refund.overdue', compact('records', 'suspended', 'remindHours', 'suspendHours', 'status'));
    }

    /** 运营手动: 据某退款留痕暂停该商家接单(非资金, 留人工复核口子)。 */
    public function overdueSuspend(Request $request, $id)
    {
        $rec = NezhaRefundRecord::findOrFail($id);
        if (!$rec->restaurant_id) {
            Toastr::warning(translate('该留痕未关联商家, 无法停接单'));
            return back();
        }
        $reason = '退款逾期未处理(留痕#' . $rec->id . ' 订单#' . $rec->order_id . ')';
        if (\App\CentralLogics\NezhaRefundOverdue::suspend((int) $rec->restaurant_id, $reason)) {
            Toastr::success(translate('已暂停该商家接单(退款逾期)。商家标记退款或您手动解除后恢复。'));
        } else {
            Toastr::warning(translate('找不到该商家'));
        }
        return back();
    }

    /** 运营手动: 解除某商家接单暂停。 */
    public function overdueUnsuspend(Request $request, $restaurant)
    {
        if (\App\CentralLogics\NezhaRefundOverdue::unsuspend((int) $restaurant)) {
            Toastr::success(translate('已解除该商家接单暂停。'));
        } else {
            Toastr::warning(translate('找不到该商家'));
        }
        return back();
    }

    /**
     * 运营手动: 人工核实该退款留痕已实际退款(商家退了但忘标记), 转 merchant_refunded 并自动解除挂起。
     * 🔴 仅改留痕状态(留痕/审计), 零资金操作。
     */
    public function overdueResolve(Request $request, $id)
    {
        $rec = NezhaRefundRecord::where('id', $id)->where('status', 'pending_merchant_refund')->first();
        if (!$rec) {
            Toastr::warning(translate('该记录不存在或已处理'));
            return back();
        }
        $rec->status               = 'merchant_refunded';
        $rec->merchant_refunded_at = now();
        $rec->merchant_refund_note = '运营人工核实已退款: ' . ($request->input('note') ? mb_substr($request->input('note'), 0, 200) : '已确认商家原路退款');
        $rec->save();
        \App\CentralLogics\NezhaRefundOverdue::lift_suspend_if_clear((int) $rec->restaurant_id);
        Toastr::success(translate('已标记该退款为已完成, 并视情况解除接单暂停。'));
        return back();
    }

    /** 运营: 保存逾期未退款阈值/总开关(L2, 后台可视化设置)。🔴 总开关=真实影响, 开=系统每天自动催办/记风控/告警。 */
    public function overdueSettings(Request $request)
    {
        $request->validate([
            'nezha_refund_overdue_status'        => 'required|in:0,1',
            'nezha_refund_overdue_remind_hours'  => 'required|integer|min:1|max:720',
            'nezha_refund_overdue_suspend_hours' => 'required|integer|min:1|max:2160',
        ]);
        $remind  = (int) $request->nezha_refund_overdue_remind_hours;
        $suspend = (int) $request->nezha_refund_overdue_suspend_hours;
        if ($suspend < $remind) {
            Toastr::warning(translate('停接单建议小时数不能小于催办小时数, 已自动取催办小时数。'));
            $suspend = $remind;
        }
        foreach ([
            'nezha_refund_overdue_status'        => (string) ((int) $request->nezha_refund_overdue_status),
            'nezha_refund_overdue_remind_hours'  => (string) $remind,
            'nezha_refund_overdue_suspend_hours' => (string) $suspend,
        ] as $key => $val) {
            \App\Models\BusinessSetting::updateOrCreate(['key' => $key], ['value' => $val]);
        }
        if ((int) $request->nezha_refund_overdue_status === 1) {
            Toastr::success(translate('已保存。⚠️ 兜底已开启: 系统将每小时自动催办逾期商家+计入风控+告警; 停接单仍需您手动。'));
        } else {
            Toastr::success(translate('已保存。兜底总开关保持关闭, 系统不会自动催办/记风控/告警(本页仍可手动处置)。'));
        }
        return back();
    }
}
