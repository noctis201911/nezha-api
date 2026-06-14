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
}
