<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaAdminDashboard;
use App\Http\Controllers\Controller;
use App\Models\LocalLifeMerchant;
use App\Models\LocalLifeMerchantChange;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * 本地生活商户「资料变更」复审台（全复审）。
 * 商户自助面提交只写待审快照(status=0)，此处超管逐条 diff 审核：
 *   通过 → 复用 admin update 语义把快照应用到线上商户（地址变更重跑 geocode，不绕过）。
 *   驳回 → 附理由，保留快照（留证，不删）。
 * L1-1：纯信息墙，只改展示字段，不碰钱/订单/合规运营字段（category/rating/status/is_sensitive 等商户本就改不了）。
 */
class LocalLifeMerchantChangeController extends Controller
{
    /** 可应用字段（= 商户自助白名单；运营字段不在内，绝不被商户提交改动） */
    private const APPLY_KEYS = [
        'name', 'address', 'intro', 'open_days', 'open_time', 'close_time', 'hours_note',
        'has_offer', 'offer_text', 'services', 'contacts', 'logo', 'wechat_qr', 'images',
    ];

    public function list(Request $request)
    {
        $pending = LocalLifeMerchantChange::with(['merchant', 'account'])
            ->where('status', LocalLifeMerchantChange::STATUS_PENDING)
            ->orderBy('id')->get();
        $recent = LocalLifeMerchantChange::with('merchant')
            ->where('status', '!=', LocalLifeMerchantChange::STATUS_PENDING)
            ->orderByDesc('reviewed_at')->limit(30)->get();

        return view('admin-views.local-life.merchant-changes.list', compact('pending', 'recent'));
    }

    public function approve(Request $request, $id)
    {
        $change = LocalLifeMerchantChange::where('status', LocalLifeMerchantChange::STATUS_PENDING)->findOrFail($id);
        $merchant = LocalLifeMerchant::find($change->merchant_id);

        if (!$merchant) {
            $change->update([
                'status' => LocalLifeMerchantChange::STATUS_REJECTED,
                'review_note' => '商户条目已不存在，提交自动作废',
                'reviewed_by' => auth('admin')->id(),
                'reviewed_at' => now(),
            ]);
            NezhaAdminDashboard::forget();
            Toastr::warning('商户条目已不存在，已作废该提交');
            return back();
        }

        $payload = (array) $change->payload;
        $apply = [];
        foreach (self::APPLY_KEYS as $k) {
            if (array_key_exists($k, $payload)) {
                $apply[$k] = $payload[$k];
            }
        }

        // 地址变更 → 重跑 geocode（best-effort，失败保留原坐标；不绕过既有 geocode 语义）
        if (array_key_exists('address', $apply) && (string) $apply['address'] !== (string) $merchant->address) {
            $geo = $this->geocode((string) ($apply['address'] ?? ''));
            if ($geo) {
                $apply['latitude'] = $geo['lat'];
                $apply['longitude'] = $geo['lng'];
            }
        }

        $merchant->update($apply);
        $change->update([
            'status' => LocalLifeMerchantChange::STATUS_APPROVED,
            'review_note' => null,
            'reviewed_by' => auth('admin')->id(),
            'reviewed_at' => now(),
        ]);
        NezhaAdminDashboard::forget();

        Toastr::success('已通过，已更新到顾客端：' . $merchant->name);
        return back();
    }

    public function reject(Request $request, $id)
    {
        $request->validate(['review_note' => 'nullable|string|max:255'], [], ['review_note' => '驳回理由']);
        $change = LocalLifeMerchantChange::where('status', LocalLifeMerchantChange::STATUS_PENDING)->findOrFail($id);

        $change->update([
            'status' => LocalLifeMerchantChange::STATUS_REJECTED,
            'review_note' => $request->review_note ?: '未通过（未注明原因）',
            'reviewed_by' => auth('admin')->id(),
            'reviewed_at' => now(),
        ]);
        NezhaAdminDashboard::forget();

        Toastr::success('已驳回，商户可在提交记录看到理由');
        return back();
    }

    /** 地址→坐标（复用 LocalLifeMerchantController::geocode 核心；best-effort 软失败） */
    private function geocode(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }
        $key = Helpers::get_business_settings('map_api_key_server');
        if (!$key) {
            return null;
        }
        try {
            $resp = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address' => $address, 'key' => $key, 'region' => 'am',
            ]);
            $json = $resp->json();
            if (($json['status'] ?? '') === 'OK' && !empty($json['results'][0]['geometry']['location'])) {
                $loc = $json['results'][0]['geometry']['location'];
                return ['lat' => round((float) $loc['lat'], 7), 'lng' => round((float) $loc['lng'], 7)];
            }
        } catch (\Throwable $e) {
            // 软失败：保留原坐标
        }
        return null;
    }
}
