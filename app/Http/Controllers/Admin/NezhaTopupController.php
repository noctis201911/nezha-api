<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\CentralLogics\NezhaOffboard;
use App\CentralLogics\NezhaDepositLedger;
use App\Models\Restaurant;
use App\Models\NezhaTopupRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Brian2694\Toastr\Facades\Toastr;

/**
 * 哪吒 预存佣金/广告/押金 自助充值申请 (A3) — 超管审核队列 + 入账接线 (S3-A).
 * 商家在对账中心提交 pending 申请(S2) -> 运营在此核对到账 -> [确认入账] 走 NezhaDepositLedger 记账 / [打回] 附理由.
 * 本控制器只处理 direction=topup(充值方向); direction=refund(押金退口) 由 S3-B 另做(L1-8 · dormant).
 *
 * 入账不造第二套: 按 account_type 分派到 NezhaDepositLedger 三方法(单一落点), 一个事务内原子完成
 * 「改申请状态 + 记账 + 回填 transaction_id」, 四处金额对账(amount_credited == 流水 amount == 子余额增量 == 对账中心)由构造保证.
 * 权限: module:deposit(超管侧, 复用不新增). 单运营审核(v1 不双复核).
 */
class NezhaTopupController extends Controller
{
    /** account_type -> 中文标签(展示用) */
    private const ACCOUNT_LABELS = [
        'deposit'   => '预存佣金',
        'guarantee' => '押金',
        'ad'        => '广告余额',
    ];

    public function index(Request $request)
    {
        $status = in_array($request->get('status'), ['pending', 'approved', 'rejected'], true)
            ? $request->get('status') : 'pending';

        $list = NezhaTopupRequest::with('restaurant')
            ->where('direction', 'topup')
            ->where('status', $status)
            ->orderByDesc('id')
            ->paginate(20)
            ->appends($request->all());

        $counts = [
            'pending'  => NezhaTopupRequest::where('direction', 'topup')->where('status', 'pending')->count(),
            'approved' => NezhaTopupRequest::where('direction', 'topup')->where('status', 'approved')->count(),
            'rejected' => NezhaTopupRequest::where('direction', 'topup')->where('status', 'rejected')->count(),
        ];

        return view('admin-views.nezha-topup.index', [
            'list'          => $list,
            'status'        => $status,
            'counts'        => $counts,
            'accountLabels' => self::ACCOUNT_LABELS,
        ]);
    }

    /**
     * 确认入账: 核对平台账户实际到账后填【实际入账金额】(以实际到账为准, 可 != 申请额) ->
     * 复用 NezhaDepositLedger 按 account_type 记账 -> 状态 approved + 回填 transaction_id.
     * 一个事务原子完成, 保证四处金额对账一致.
     */
    public function approve(Request $request, $id)
    {
        $request->validate([
            'amount_credited' => 'required|numeric|min:0.01',
            'note'            => 'nullable|string|max:255',
        ]);

        try {
            DB::transaction(function () use ($request, $id) {
                $req = NezhaTopupRequest::where('id', $id)
                    ->where('direction', 'topup')
                    ->lockForUpdate()
                    ->first();

                if (!$req || $req->status !== 'pending') {
                    throw new \RuntimeException(translate('该申请无法入账(可能已处理或不存在)'));
                }

                $restaurant = Restaurant::where('vendor_id', $req->vendor_id)->firstOrFail();
                if (NezhaOffboard::is_frozen($restaurant)) {
                    throw new \RuntimeException(translate('该商家正在办理退出结算, 结算期间不可入账'));
                }

                $amount   = (float) $request->amount_credited;
                $operator = auth('admin')->id();
                $note     = $request->note ?: ('自助充值申请#' . $req->id . ' 入账');

                switch ($req->account_type) {
                    case 'guarantee':
                        // 押金 L1-8① 法币-only: 自助腿币种恒 AMD(S2 固定), 兜底仍限 AMD/CNY; L1-4 留痕回执号取申请单号
                        $currency = in_array($req->currency, ['AMD', 'CNY'], true) ? $req->currency : 'AMD';
                        $tx = NezhaDepositLedger::recordGuaranteeDeposit(
                            $restaurant,
                            $amount,
                            $currency,
                            $req->original_amount ? (float) $req->original_amount : $amount,
                            $req->original_ref ?: ('TOPUP#' . $req->id),
                            $note,
                            $operator
                        );
                        break;
                    case 'ad':
                        $tx = NezhaDepositLedger::recordAdRecharge($req->vendor_id, $amount, $note);
                        break;
                    default: // deposit(预存佣金)
                        $tx = NezhaDepositLedger::recordRecharge($restaurant, $amount, $note, $operator);
                        break;
                }

                $req->status          = 'approved';
                $req->amount_credited = $amount;
                $req->operator_id     = $operator;
                $req->transaction_id  = $tx->id;
                $req->reviewed_at     = now();
                $req->save();
            });
            Toastr::success(translate('已确认入账, 商家余额已增加'));
        } catch (\RuntimeException $e) {
            Toastr::error($e->getMessage());
        } catch (\Throwable $e) {
            info('nezha topup approve failed #' . $id . ': ' . $e->getMessage());
            Toastr::error(translate('入账失败, 请重试'));
        }

        return back();
    }

    /** 打回: 必填理由, 状态 rejected(商家可修改后重新提交). */
    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string|max:255']);

        $req = NezhaTopupRequest::where('id', $id)
            ->where('direction', 'topup')
            ->where('status', 'pending')
            ->first();

        if (!$req) {
            Toastr::warning(translate('该申请无法打回(可能已处理或不存在)'));
            return back();
        }

        $req->status      = 'rejected';
        $req->reason      = $request->reason;
        $req->operator_id = auth('admin')->id();
        $req->reviewed_at = now();
        $req->save();

        Toastr::success(translate('已打回该充值申请'));
        return back();
    }
}
