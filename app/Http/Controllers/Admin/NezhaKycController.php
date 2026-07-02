<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\VendorKycProfile;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 哪吒 商家 KYC 资料 后台（轻量·方案B, 只存核验结论, 默认不存扫描件）。
 *
 *  - index()  : 商家 KYC 状态列表(只显示状态徽章, 不在列表暴露 PII)。
 *  - edit()   : 单店 KYC 录入/审核页(运营当面/视频核验后录入结论)。
 *  - save()   : 保存核验结论 → kyc_status=pending(待审核)。
 *  - review() : 审核 通过 / 拒绝(拒绝写 closed_at 作留存锚点)。
 *
 * 合规: 录入字段为 AML/CDD 核验记录(PII, 模型层 encrypted), 表已 ENCRYPTION='Y'。
 * 阶段1(制裁名字筛查)接入后, save() 会对 legal_name/beneficial_owner_name 过筛查。
 */
class NezhaKycController extends Controller
{
    /** 录入表单允许写入的字段(全部来自运营当面核验)。 */
    private array $fillable = [
        'legal_name', 'legal_name_local', 'beneficial_owner_name',
        'id_doc_type', 'id_doc_number', 'bank_account', 'contact_phone',
        'verify_method', 'note',
    ];

    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $status = (string) $request->get('status', '');
        $queue  = (string) $request->get('queue', ''); // 'offboard' = 待退出核验队列(D2)

        // D2: 退出中 kyc_pending 的活跃工单(offboard 前置身份核验) —— 队列筛选 + 超期红旗数据源。
        $offboardKyc = \App\Models\RestaurantOffboardSettlement::where('active_uniq', 1)
            ->where('status', 'kyc_pending')->get()->keyBy('restaurant_id');
        $offboardPendingCount = $offboardKyc->count();
        $offboardSlaDays = 3; // 核验 SLA(工作日近似); 超期在列表红旗(被动查询, 无 cron)

        $query = Restaurant::query()
            ->select('id', 'name', 'phone', 'email', 'status', 'created_at')
            ->orderByDesc('id');

        if ($queue === 'offboard') {
            $query->whereIn('id', $offboardKyc->keys()->all() ?: [0]);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $restaurants = $query->paginate(config('default_pagination', 25))->appends($request->all());

        // 批量取 KYC 状态(不取 PII 字段, 只要状态), 避免 N+1。
        $ids = $restaurants->getCollection()->pluck('id')->all();
        $profiles = VendorKycProfile::whereIn('restaurant_id', $ids)
            ->get(['restaurant_id', 'kyc_status', 'screen_status', 'reviewed_at'])
            ->keyBy('restaurant_id');

        return view('admin-views.nezha-kyc.index', compact(
            'restaurants', 'profiles', 'search', 'status',
            'queue', 'offboardKyc', 'offboardPendingCount', 'offboardSlaDays'
        ));
    }

    public function edit($restaurant_id)
    {
        $restaurant = Restaurant::select('id', 'name', 'phone', 'email', 'address', 'status')->findOrFail($restaurant_id);
        $profile = VendorKycProfile::where('restaurant_id', $restaurant_id)->first();

        return view('admin-views.nezha-kyc.edit', compact('restaurant', 'profile'));
    }

    public function save(Request $request, $restaurant_id)
    {
        $restaurant = Restaurant::findOrFail($restaurant_id);

        $request->validate([
            'legal_name'            => 'required|string|max:191',
            'legal_name_local'      => 'nullable|string|max:191',
            'beneficial_owner_name' => 'nullable|string|max:191',
            'id_doc_type'           => 'nullable|in:passport,national_id,residence_permit,business_license,other',
            'id_doc_number'         => 'nullable|string|max:191',
            'bank_account'          => 'nullable|string|max:500',
            'contact_phone'         => 'nullable|string|max:60',
            'verify_method'         => 'nullable|in:in_person,video,document',
            'note'                  => 'nullable|string|max:1000',
        ]);

        $profile = VendorKycProfile::firstOrNew(['restaurant_id' => $restaurant->id]);

        foreach ($this->fillable as $f) {
            $profile->{$f} = $request->input($f);
        }

        // 录入/更新即转「待审核」(除非已通过则保持, 让运营可在不改状态下补字段)
        if ($profile->kyc_status !== 'approved') {
            $profile->kyc_status = 'pending';
        }
        // 重新录入时清掉旧的拒绝锚点
        $profile->reject_reason = null;
        $profile->closed_at = null;

        // E2: 证件号指纹(HMAC 明文索引, 辅助跨 vendor 身份红标; 无密钥/无证件号则 null)
        $profile->id_doc_fingerprint = \App\CentralLogics\NezhaKycScreen::doc_fingerprint($request->input('id_doc_type'), $request->input('id_doc_number'));

        $profile->save();

        // 阶段1 制裁名字筛查(L1-6): 对法人 + 受益人姓名比对 OFAC SDN 人名名单 →
        // 写回 screen_*; possible/hit 进风控审核队列/日志。失败不阻断录入(纯告知)。
        try {
            if (\App\CentralLogics\NezhaKycScreen::enabled()) {
                $names  = array_filter([$request->input('legal_name'), $request->input('beneficial_owner_name')]);
                $screen = \App\CentralLogics\NezhaKycScreen::screen_names($names);
                \App\CentralLogics\NezhaKycScreen::apply_to_profile($profile, $screen);
                \App\CentralLogics\NezhaKycScreen::record_risk($restaurant->id, null, $screen, 'kyc_save');

                if (($screen['status'] ?? '') === 'hit') {
                    Toastr::error(translate('⚠️ 制裁名单命中: 该姓名与 OFAC SDN 名单完全一致, 平台不得与受制裁主体合作, 建议拒绝。已记风控日志。'));
                } elseif (($screen['status'] ?? '') === 'possible') {
                    Toastr::warning(translate('制裁名单疑似命中(姓名近似), 已转风控审核队列, 请人工核对后据实处置。'));
                }
            }
        } catch (\Throwable $e) {
            info(['kyc-screen-save', $e->getMessage()]);
        }

        Toastr::success(translate('KYC 资料已保存, 状态: 待审核'));
        return back();
    }

    public function review(Request $request, $restaurant_id)
    {
        $request->validate([
            'decision'      => 'required|in:approved,rejected',
            'reject_reason' => 'nullable|string|max:500',
        ]);

        $profile = VendorKycProfile::where('restaurant_id', $restaurant_id)->firstOrFail();

        if ($profile->kyc_status === 'none') {
            Toastr::warning(translate('请先录入 KYC 资料再审核'));
            return back();
        }

        $admin = Auth::guard('admin')->user();
        $reviewer = $admin?->email ?? ($admin?->f_name ?? 'admin');

        if ($request->decision === 'approved') {
            $profile->kyc_status   = 'approved';
            $profile->reject_reason = null;
            $profile->closed_at     = null;
            Toastr::success(translate('KYC 审核通过'));
        } else {
            $profile->kyc_status   = 'rejected';
            $profile->reject_reason = $request->input('reject_reason');
            $profile->closed_at     = now();   // 留存倒计时锚点(>=5 年后才清, 当前不删)
            Toastr::success(translate('KYC 已拒绝'));
        }

        $profile->reviewer    = $reviewer;
        $profile->reviewed_at = now();
        $profile->save();

        // [offboard D2] KYC 结论回流退出状态机(仅影响退出中 kyc_pending 的店; onKyc* 内幂等守卫, 勿删此块)
        $restaurant = Restaurant::find($restaurant_id);
        if ($restaurant) {
            if ($request->decision === 'approved') {
                \App\CentralLogics\NezhaOffboard::onKycApproved($restaurant);
            } else {
                \App\CentralLogics\NezhaOffboard::onKycRejected($restaurant);
            }
        }

        return back();
    }
}
