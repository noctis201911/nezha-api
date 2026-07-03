<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaOffboard;
use App\Models\Restaurant;
use App\Models\NezhaTopupRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Brian2694\Toastr\Facades\Toastr;

/**
 * 哪吒 预存佣金/广告/押金 自助充值申请 (A3) — 商家侧提交/撤回.
 * 商家在对账中心提交充值申请(自报额+凭证) -> 落一行 pending -> 运营在超管队列审核入账(S3).
 * 本控制器【只写 pending 申请, 完全不碰余额】; 入账发生在审核通过(S3·复用既有入账核心).
 *
 * 开关(服务端强制·默认关 dormant): nezha_topup_status 总闸 /
 *   nezha_topup_ad_status 广告腿(计费未上线前不亮) / nezha_topup_guarantee_status 押金腿.
 * 金额上下限(后台可调): nezha_topup_min_amd(默5000) / nezha_topup_max_amd(默2000000).
 * 频率限: 同商家同账户 10 分钟内至多 1 笔未决. 凭证存独立目录(不进顾客支付凭证90天purge).
 */
class NezhaTopupController extends Controller
{
    private const ACCOUNTS = ['deposit', 'guarantee', 'ad'];

    private function setting(string $key, $default = 0)
    {
        $v = DB::table('business_settings')->where('key', $key)->value('value');
        return ($v === null || $v === '') ? $default : $v;
    }

    /** 某账户腿自助充值是否开放(总闸 && 该腿闸). deposit 只受总闸; ad/guarantee 另有独立闸. */
    private function accountOpen(string $account): bool
    {
        if (!in_array($account, self::ACCOUNTS, true)) {
            return false;
        }
        if ((int) $this->setting('nezha_topup_status', 0) !== 1) {
            return false;
        }
        if ($account === 'ad') {
            return (int) $this->setting('nezha_topup_ad_status', 0) === 1;
        }
        if ($account === 'guarantee') {
            return (int) $this->setting('nezha_topup_guarantee_status', 0) === 1;
        }
        return true;
    }

    public function topupApply(Request $request)
    {
        $account = in_array($request->get('account_type'), self::ACCOUNTS, true) ? $request->get('account_type') : 'deposit';

        if (!$this->accountOpen($account)) {
            Toastr::error(translate('该账户的自助充值暂未开放'));
            return back();
        }

        $min = (float) $this->setting('nezha_topup_min_amd', 5000);
        $max = (float) $this->setting('nezha_topup_max_amd', 2000000);
        $request->validate([
            'amount' => 'required|numeric|min:' . $min . '|max:' . $max,
            'note'   => 'nullable|string|max:255',
            'proof'  => 'required|image|max:5120',
        ]);

        $vendorId   = Helpers::get_vendor_id();
        $restaurant = Restaurant::where('vendor_id', $vendorId)->firstOrFail();

        if (NezhaOffboard::is_frozen($restaurant)) {
            Toastr::error(translate('店铺正在办理退出结算, 期间不可充值'));
            return back();
        }

        $recentPending = NezhaTopupRequest::where('vendor_id', $vendorId)
            ->where('account_type', $account)
            ->where('direction', 'topup')
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();
        if ($recentPending) {
            Toastr::error(translate('您有一笔充值申请正在审核, 请稍后再提交'));
            return back();
        }

        $proofPath = $request->file('proof')->store('restaurant/topup-proof', 'public');

        NezhaTopupRequest::create([
            'vendor_id'      => $vendorId,
            'restaurant_id'  => $restaurant->id,
            'account_type'   => $account,
            'direction'      => 'topup',
            'amount_claimed' => (float) $request->amount,
            'currency'       => 'AMD',
            'proof_path'     => $proofPath,
            'note'           => $request->note ?: null,
            'status'         => 'pending',
        ]);

        Toastr::success(translate('充值申请已提交, 平台核对到账后为您入账'));
        return back();
    }

    /** 商家撤回未审核(pending)申请. IDOR: 只能撤自己名下的. */
    public function topupCancel(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        $vendorId = Helpers::get_vendor_id();

        $req = NezhaTopupRequest::where('id', $request->id)
            ->where('vendor_id', $vendorId)
            ->where('status', 'pending')
            ->first();

        if (!$req) {
            Toastr::warning(translate('该申请无法撤回(可能已审核或不存在)'));
            return back();
        }

        $req->status = 'cancelled';
        $req->reviewed_at = now();
        $req->save();

        Toastr::success(translate('已撤回充值申请'));
        return back();
    }
}