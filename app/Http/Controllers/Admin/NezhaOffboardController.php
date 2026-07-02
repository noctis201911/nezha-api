<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaOffboard;
use App\Models\AdminAuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantDepositTransaction;
use App\Models\RestaurantOffboardSettlement;
use App\Models\RestaurantWallet;
use App\Models\VendorKycProfile;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 哪吒 商家退出结算 — 超管审批 / 放款 UI (step4-4 §H · DESIGN_merchant_offboard)。
 *
 * 队列(applied/kyc_pending/approved/…) → 审批页(制裁实时 re-screen §D1 + 户名三方核对 §D3)
 *   → 审批 approve() → 放款 pay()(闸 H §H: 高额强制次日转 + TG 二次告警 + 审计双时间戳)。
 *
 * 资金动作全部委托 NezhaOffboard::approve()/pay() 单一真相源; 本控制器只做:
 *   ① UI 呈现队列/详情  ② 审批前置(制裁 fail-closed + 户名核对勾选)  ③ 放款闸时序  ④ 审计 + TG 告警。
 * 权限: module:deposit(资金动作, 复用押金权限位, 不新增权限位)。
 * 🔴 approve() 的 4 道 fail-closed 门(status=applied·冷静期·sanction_rescreen_at·holder_verified)是
 *    资金流出闸(INVARIANTS L1-8), 勿删减(NezhaL1RedlineTest 有结构守卫)。
 */
class NezhaOffboardController extends Controller
{
    private const ACTIVE_STATES = ['applied', 'kyc_pending', 'approved', 'paying', 'partial'];
    private const RECENT_STATES = ['paid', 'owing', 'rejected', 'withdrawn', 'failed'];

    /** 队列: 进行中工单 + 近期已结。 */
    public function index(Request $request)
    {
        $active = RestaurantOffboardSettlement::whereIn('status', self::ACTIVE_STATES)
            ->orderByDesc('id')->get();
        $recent = RestaurantOffboardSettlement::whereIn('status', self::RECENT_STATES)
            ->orderByDesc('id')->limit(50)->get();

        $rids = $active->pluck('restaurant_id')->merge($recent->pluck('restaurant_id'))->unique()->filter()->all();
        $names = $rids ? Restaurant::whereIn('id', $rids)->pluck('name', 'id') : collect();
        // 进行中工单的待核实纠纷红旗(§H)
        $disputeFlags = [];
        foreach ($active as $s) {
            $disputeFlags[$s->id] = NezhaOffboard::pendingDisputeCount($s->restaurant_id);
        }

        return view('admin-views.nezha-offboard.index', compact('active', 'recent', 'names', 'disputeFlags'));
    }

    /** 审批详情: 净额预览 + 待核实纠纷红旗 + 户名三方核对(legal_name / KYC 户名 / 缴纳凭证付款人)。 */
    public function show($id)
    {
        $s = RestaurantOffboardSettlement::findOrFail($id);
        $restaurant = Restaurant::find($s->restaurant_id);
        $wallet     = RestaurantWallet::where('vendor_id', $s->vendor_id)->first();
        $kyc        = VendorKycProfile::where('restaurant_id', $s->restaurant_id)->first();
        // 缴纳凭证付款人(最近一条押金缴纳的 original_ref) —— §D3 户名三方核对第三方
        $lastGuarantee = RestaurantDepositTransaction::where('vendor_id', $s->vendor_id)
            ->where('type', 'guarantee_deposit')->orderByDesc('id')->first();
        $pendingDisputes = NezhaOffboard::pendingDisputeCount($s->restaurant_id); // §H 红旗
        $payGate = NezhaOffboard::canPayNow($s);

        return view('admin-views.nezha-offboard.show', compact('s', 'restaurant', 'wallet', 'kyc', 'lastGuarantee', 'pendingDisputes', 'payGate'));
    }

    /**
     * 审批: §D1 制裁实时 re-screen(fail-closed) + §D3 户名核对(超管勾选) → NezhaOffboard::approve()。
     *   re-screen possible/hit / 无 KYC → 不放行(fail-closed, 不置 sanction_rescreen_at)。
     *   holder_verified 复选框未勾 → validate 拒(超管须逐字核对 legal_name==KYC 户名==缴纳凭证付款人)。
     */
    public function approve(Request $request, $id)
    {
        $request->validate(
            ['holder_verified' => 'required|accepted'],
            ['holder_verified.required' => '请先逐字核对户名并勾选确认', 'holder_verified.accepted' => '请先逐字核对户名并勾选确认']
        );

        $s = RestaurantOffboardSettlement::findOrFail($id);
        if ($s->status !== 'applied') {
            Toastr::error(translate('该工单当前状态不可审批(须为 applied)'));
            return back();
        }

        // §D1 制裁实时 re-screen(用当前名单 RE-run screen_names, 不读入驻旧列; possible/hit fail-closed)
        $screen = NezhaOffboard::rescreenSanctions($s);
        if (!($screen['ok'] ?? false)) {
            $st = $screen['status'] ?? '';
            if ($st === 'hit') {
                Toastr::error(translate('制裁名单命中: 已拒绝该退出并转人工 AML, 不予放款(L1-6)。'));
            } elseif ($st === 'possible') {
                Toastr::warning(translate('制裁名单疑似命中: 已转人工 AML 核对, 暂不放行放款。'));
            } else {
                Toastr::error(translate('无法完成制裁核验(缺 KYC 或法人姓名), 请先补齐 KYC 再审批。'));
            }
            return back();
        }

        // §D3 户名核对通过(超管已逐字核对) → 置 holder_verified
        $s = $s->fresh();
        $s->holder_verified = true;
        $s->save();

        $adminId = (int) (Auth::guard('admin')->id() ?? 0);
        if (NezhaOffboard::approve($s->fresh(), $adminId)) {
            $fresh = $s->fresh();
            $net = (float) $fresh->net_amount;
            $tail = $net < 0
                ? '净额为负(' . $net . '֏)→ 欠款(owing), 不放款, 走人工追缴。'
                : '净额 ' . $net . '֏ 已锁定快照, 待放款(闸 H)。';
            AdminAuditLog::record('offboard_approved', 'vendor', (int) $s->restaurant_id, null, [
                'settlement_id' => $s->id, 'net' => $net, 'holder_verified' => true, 'admin_id' => $adminId,
            ]);
            Helpers::sendTelegramToAdmin("【退出审批】工单#{$s->id} 店#{$s->restaurant_id} 已审批。{$tail}");
            Toastr::success(translate('审批通过。') . $tail);
        } else {
            Toastr::error(translate('未通过前置门(冷静期未过 / 状态已变 / 存在非终态订单未清), 请查看工单状态。'));
        }
        return back();
    }

    /** 放款: §H 闸时序(高额 T+1) → NezhaOffboard::pay()。三腿原子置零 + 逐腿幂等 + C4 快照守卫。 */
    public function pay(Request $request, $id)
    {
        $s = RestaurantOffboardSettlement::findOrFail($id);

        $gate = NezhaOffboard::canPayNow($s);
        if (!($gate['ok'] ?? false)) {
            Toastr::error($gate['reason'] ?: translate('当前不可放款'));
            return back();
        }

        $res = NezhaOffboard::pay($s);
        AdminAuditLog::record('offboard_pay', 'vendor', (int) $s->restaurant_id, null, [
            'settlement_id' => $s->id, 'result' => $res, 'admin_id' => (int) (Auth::guard('admin')->id() ?? 0),
        ]);
        $map = [
            'paid'    => translate('放款完成, 三账户已结清置零。'),
            'partial' => translate('部分腿已放款, 可再次点击续付剩余腿。'),
            'aborted' => translate('余额与审批快照不一致, 已重算并回到待放款, 请再次放款。'),
            'owing'   => translate('净额为负(欠款), 不放款, 走人工追缴。'),
            'failed'  => translate('连续校验失败已熔断, 转人工核对。'),
            'noop'    => translate('当前工单状态无需放款。'),
        ];
        Helpers::sendTelegramToAdmin("【退出放款】工单#{$s->id} 店#{$s->restaurant_id} 放款结果: {$res}");
        if (in_array($res, ['paid', 'partial'], true)) {
            Toastr::success($map[$res]);
        } else {
            Toastr::warning($map[$res] ?? $res);
        }
        return back();
    }
}
