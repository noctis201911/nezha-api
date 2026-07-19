<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\NezhaMerchantTwoFactor;
use App\CentralLogics\NezhaPaymentAddressChangeService;
use App\Http\Controllers\Controller;
use App\Models\NezhaPaymentAddressChange;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class NezhaPaymentAddressChangeController extends Controller
{
    public function show(Request $request, NezhaPaymentAddressChange $change)
    {
        $vendor = $this->owner();
        if (! $vendor) {
            return $this->error($request, 'address_change_owner_required', 403);
        }
        if (! NezhaPaymentAddressChangeService::enabled()) {
            return $this->error($request, 'address_change_feature_disabled', 404);
        }
        if (! $this->owns($vendor->id, $change)) {
            return $this->error($request, 'address_change_not_found', 404);
        }

        if ($request->expectsJson()) {
            return response()->json($this->resource($change));
        }

        return redirect()->route('vendor.wallet-method.index');
    }

    public function confirm(Request $request, NezhaPaymentAddressChange $change)
    {
        return $this->decide($request, $change, true);
    }

    public function reject(Request $request, NezhaPaymentAddressChange $change)
    {
        return $this->decide($request, $change, false);
    }

    private function decide(Request $request, NezhaPaymentAddressChange $change, bool $confirm)
    {
        $vendor = $this->owner();
        if (! $vendor) {
            return $this->error($request, 'address_change_owner_required', 403);
        }
        $validator = Validator::make($request->all(), [
            'new_fingerprint' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'current_password' => ['required', 'string'],
            'two_factor_code' => ['required', 'string', 'max:16'],
        ]);
        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            return back()->withErrors($validator)->withInput();
        }
        if (! $this->owns($vendor->id, $change)) {
            return $this->error($request, 'address_change_not_found', 404);
        }

        $rateKeys = [
            'merchant-address-step-up:ip:'.NezhaMerchantTwoFactor::requestHash($request->ip()),
            'merchant-address-step-up:owner:'.$vendor->id,
        ];
        if (collect($rateKeys)->contains(fn (string $key): bool => RateLimiter::tooManyAttempts($key, 5))) {
            return $this->error($request, 'address_change_step_up_failed', 429);
        }
        try {
            NezhaMerchantTwoFactor::verifySensitiveStepUp(
                $vendor,
                (string) $request->input('current_password'),
                (string) $request->input('two_factor_code'),
                [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'metadata' => ['channel' => 'web', 'route' => optional($request->route())->getName()],
                ]
            );
            foreach ($rateKeys as $rateKey) {
                RateLimiter::clear($rateKey);
            }
        } catch (\DomainException) {
            foreach ($rateKeys as $rateKey) {
                RateLimiter::hit($rateKey, 120);
            }

            return $this->error($request, 'address_change_step_up_failed', 403);
        }

        try {
            $updated = $confirm
                ? NezhaPaymentAddressChangeService::merchantConfirm(
                    $vendor,
                    (string) $change->public_id,
                    (string) $request->input('new_fingerprint')
                )
                : NezhaPaymentAddressChangeService::merchantReject(
                    $vendor,
                    (string) $change->public_id,
                    (string) $request->input('new_fingerprint')
                );

            if ($request->expectsJson()) {
                return response()->json($this->resource($updated));
            }

            Toastr::success($confirm
                ? '地址已确认；当前地址尚未切换，正在等待不同管理员复核。'
                : '地址变更申请已拒绝；当前地址未改变。');

            return redirect()->route('vendor.wallet-method.index');
        } catch (\DomainException $e) {
            return $this->error($request, $e->getMessage(), 409);
        }
    }

    private function owner()
    {
        // VendorMiddleware also admits employees; this funds-address action must not.
        return auth('vendor')->check() ? auth('vendor')->user() : null;
    }

    private function owns(int $vendorId, NezhaPaymentAddressChange $change): bool
    {
        return DB::table('restaurants')
            ->where('id', $change->restaurant_id)
            ->where('vendor_id', $vendorId)
            ->exists();
    }

    private function resource(NezhaPaymentAddressChange $change): array
    {
        return [
            'change_id' => (string) $change->public_id,
            'restaurant_id' => (int) $change->restaurant_id,
            'network' => (string) $change->network,
            'state' => (string) $change->state,
            'old_address' => (string) $change->old_address,
            'new_address' => (string) $change->new_address,
            'old_fingerprint' => (string) $change->old_fingerprint,
            'new_fingerprint' => (string) $change->new_fingerprint,
            'expires_at' => $change->expires_at?->toIso8601String(),
        ];
    }

    private function error(Request $request, string $code, int $status)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => $code,
                'message' => '收款地址确认未执行，请刷新后核对完整地址',
            ], $status);
        }

        if ($code === 'address_change_step_up_failed') {
            Toastr::error('The current password or authenticator code could not be verified. No address action was performed.');

            return back();
        }

        $message = match ($code) {
            'address_change_owner_required' => '只有商家 owner 可以查看或确认资金地址变更。',
            'address_change_feature_disabled' => '地址变更确认功能尚未启用。',
            'address_change_not_found' => '未找到属于本商家的地址变更申请。',
            'address_change_fingerprint_mismatch' => '候选地址指纹不一致，操作已拒绝。',
            'address_change_expired' => '该地址变更申请已过期，当前地址未改变。',
            'address_change_state_invalid' => '申请状态已变化，请刷新页面重新核对。',
            default => '收款地址确认未执行；当前地址未改变，请刷新后重试。',
        };
        Toastr::error($message);

        return back();
    }
}
