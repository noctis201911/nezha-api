<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\NezhaPaymentAddressChangeService;
use App\CentralLogics\NezhaPaymentAddressReviewQueue;
use App\Http\Controllers\Controller;
use App\Models\NezhaPaymentAddressChange;
use App\Models\Restaurant;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NezhaPaymentAddressChangeController extends Controller
{
    public function pending(Request $request)
    {
        if (! NezhaPaymentAddressChangeService::enabled()) {
            abort(404);
        }

        $changes = NezhaPaymentAddressReviewQueue::get();

        $resources = $changes
            ->map(fn (NezhaPaymentAddressChange $change): array => $this->resource($change))
            ->values();

        if ($request->expectsJson()) {
            return response()->json([
                'data' => $resources,
                'count' => $resources->count(),
            ]);
        }

        return view('admin-views.nezha-payment-address-review', [
            'changes' => $changes,
            'currentAdminId' => (int) auth('admin')->id(),
            'reviewError' => session('payment_address_review_error'),
        ]);
    }

    public function store(Request $request, Restaurant $restaurant)
    {
        $validator = Validator::make($request->all(), [
            'network' => 'required|string|max:8',
            'new_address' => 'required|string|max:191',
            'reason' => 'required|string|max:500',
            'totp_code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'idempotency_key' => 'required|string|min:8|max:191',
        ]);
        if ($validator->fails()) {
            return $this->validationError($request, $validator);
        }

        try {
            $change = NezhaPaymentAddressChangeService::requestChange(
                auth('admin')->user(),
                (int) $restaurant->id,
                (string) $request->input('network'),
                (string) $request->input('new_address'),
                (string) $request->input('reason'),
                (string) $request->input('totp_code'),
                (string) $request->input('idempotency_key')
            );

            if ($request->expectsJson()) {
                return response()->json($this->resource($change), 201);
            }

            return $this->success((int) $restaurant->id, '地址变更申请已创建；当前地址未改变，正在等待商家 owner 核对。');
        } catch (\DomainException $e) {
            return $this->domainError($request, $e);
        }
    }

    public function show(Request $request, NezhaPaymentAddressChange $change)
    {
        if (! NezhaPaymentAddressChangeService::enabled()) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'address_change_feature_disabled'], 404);
            }
            abort(404);
        }
        abort_unless($change->state === 'pending_distinct_admin', 404);
        $change->loadMissing(['restaurant:id,name', 'requestedByAdmin:id,f_name,l_name,email']);

        return response()->json($this->resource($change));
    }

    public function approve(Request $request, NezhaPaymentAddressChange $change)
    {
        $validator = Validator::make($request->all(), [
            'new_fingerprint' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'totp_code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($request, $validator);
        }

        try {
            $approved = NezhaPaymentAddressChangeService::approveChange(
                auth('admin')->user(),
                (string) $change->public_id,
                (string) $request->input('new_fingerprint'),
                (string) $request->input('totp_code')
            );

            if ($request->expectsJson()) {
                return response()->json($this->resource($approved));
            }

            return $this->reviewSuccess(
                '独立复核已通过，新地址已用于新付款；已签发的旧地址凭据只保留到各自到期。'
            );
        } catch (\DomainException $e) {
            return $this->domainError($request, $e, $change);
        }
    }

    public function reject(Request $request, NezhaPaymentAddressChange $change)
    {
        $validator = Validator::make($request->all(), [
            'new_fingerprint' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'totp_code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'reason' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return $this->validationError($request, $validator);
        }

        try {
            $rejected = NezhaPaymentAddressChangeService::rejectChange(
                auth('admin')->user(),
                (string) $change->public_id,
                (string) $request->input('new_fingerprint'),
                (string) $request->input('totp_code'),
                $request->input('reason')
            );

            if ($request->expectsJson()) {
                return response()->json($this->resource($rejected));
            }

            return $this->reviewSuccess('独立复核已驳回；当前地址未改变，商家通知将按实际渠道投递并留痕。');
        } catch (\DomainException $e) {
            return $this->domainError($request, $e, $change);
        }
    }

    public function cancel(Request $request, NezhaPaymentAddressChange $change)
    {
        $validator = Validator::make($request->all(), [
            'totp_code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($request, $validator);
        }

        try {
            $canceled = NezhaPaymentAddressChangeService::cancelChange(
                auth('admin')->user(),
                (string) $change->public_id,
                (string) $request->input('totp_code')
            );

            if ($request->expectsJson()) {
                return response()->json($this->resource($canceled));
            }

            return $this->success((int) $canceled->restaurant_id, '地址变更申请已取消；当前地址未改变。');
        } catch (\DomainException $e) {
            return $this->domainError($request, $e);
        }
    }

    public function pause(Request $request, Restaurant $restaurant)
    {
        $validator = Validator::make($request->all(), [
            'network' => 'required|string|max:8',
            'reason' => 'required|string|max:500',
            'totp_code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($request, $validator);
        }

        try {
            $state = NezhaPaymentAddressChangeService::emergencyPause(
                auth('admin')->user(),
                (int) $restaurant->id,
                (string) $request->input('network'),
                (string) $request->input('reason'),
                (string) $request->input('totp_code')
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'restaurant_id' => (int) $state->restaurant_id,
                    'network' => (string) $state->network,
                    'state' => (string) $state->state,
                    'paused_at' => $state->paused_at?->toIso8601String(),
                ]);
            }

            return $this->success(
                (int) $state->restaurant_id,
                $state->network.' 收款已紧急暂停；未消费的地址凭据已撤销。'
            );
        } catch (\DomainException $e) {
            return $this->domainError($request, $e);
        }
    }

    private function resource(NezhaPaymentAddressChange $change): array
    {
        $requester = $change->requestedByAdmin;
        $requesterName = trim((string) ($requester?->f_name.' '.$requester?->l_name));

        return [
            'change_id' => (string) $change->public_id,
            'restaurant_id' => (int) $change->restaurant_id,
            'restaurant_name' => (string) ($change->restaurant?->name ?? '商家#'.$change->restaurant_id),
            'network' => (string) $change->network,
            'state' => (string) $change->state,
            'old_address' => (string) $change->old_address,
            'new_address' => (string) $change->new_address,
            'old_fingerprint' => (string) $change->old_fingerprint,
            'new_fingerprint' => (string) $change->new_fingerprint,
            'requested_by_admin_id' => (int) $change->requested_by_admin_id,
            'requested_by_admin_name' => $requesterName !== ''
                ? $requesterName
                : (string) ($requester?->email ?? '管理员#'.$change->requested_by_admin_id),
            'merchant_confirmed_at' => $change->merchant_confirmed_at?->toIso8601String(),
            'approved_by_admin_id' => $change->approved_by_admin_id !== null
                ? (int) $change->approved_by_admin_id
                : null,
            'drain_until' => $change->drain_until?->toIso8601String(),
            'expires_at' => $change->expires_at?->toIso8601String(),
            'failure_code' => $change->failure_code,
            'approve_url' => route('admin.restaurant.payment-address-change.approve', $change),
            'reject_url' => route('admin.restaurant.payment-address-change.reject', $change),
        ];
    }

    private function validationError(Request $request, $validator)
    {
        if ($request->expectsJson()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        return back()->withErrors($validator)->withInput();
    }

    private function success(int $restaurantId, string $message)
    {
        Toastr::success($message);

        return redirect()->route('admin.restaurant.view', [$restaurantId, 'payment_info']);
    }

    private function reviewSuccess(string $message)
    {
        Toastr::success($message);

        return back();
    }

    private function domainError(
        Request $request,
        \DomainException $e,
        ?NezhaPaymentAddressChange $reviewChange = null
    )
    {
        $code = $e->getMessage();
        $status = match ($code) {
            'address_change_not_found', 'address_change_restaurant_not_found' => 404,
            'address_change_totp_rate_limited' => 429,
            'address_change_feature_disabled' => 403,
            'address_change_totp_invalid', 'address_change_step_up_required' => 401,
            default => 409,
        };

        if ($request->expectsJson()) {
            return response()->json([
                'error' => $code,
                'message' => '收款地址变更未执行，请核对当前状态与验证条件',
            ], $status);
        }

        $message = match ($code) {
            'address_change_feature_disabled' => '地址变更状态机尚未启用。',
            'address_change_step_up_required' => '当前管理员尚未启用 TOTP，不能执行资金地址操作。',
            'address_change_totp_invalid' => 'TOTP 验证码无效，地址未变更。',
            'address_change_totp_replayed' => '该 TOTP 时间步已用于其它高风险操作，请等待下一组验证码。',
            'address_change_totp_rate_limited' => 'TOTP 尝试过多，请稍后再试。',
            'address_change_distinct_admin_required' => '申请人不能自批，必须由另一名管理员复核。',
            'address_change_network_state_missing' => '该网络尚未完成受控状态初始化，不能从此页面发起变更。',
            'address_change_current_address_invalid' => '当前地址不符合严格格式，不能进入自动变更流程。',
            'address_change_already_pending' => '该网络已有未完成的地址变更申请。',
            'address_change_fingerprint_mismatch' => '候选地址指纹不一致，操作已拒绝。',
            'address_change_state_invalid', 'address_change_expired' => '申请状态已变化，请刷新页面后重新核对。',
            default => '收款地址操作未执行；当前地址未改变，请刷新后核对状态。',
        };
        Toastr::error($message);

        $response = back();
        if ($reviewChange !== null) {
            $response->with('payment_address_review_error', [
                'change_id' => (string) $reviewChange->public_id,
                'code' => $code,
                'message' => $message,
                'status' => $status,
            ]);
        }

        return $response;
    }
}
