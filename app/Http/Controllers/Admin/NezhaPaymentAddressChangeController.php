<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\NezhaPaymentAddressChangeService;
use App\Http\Controllers\Controller;
use App\Models\NezhaPaymentAddressChange;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NezhaPaymentAddressChangeController extends Controller
{
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
            return response()->json(['errors' => $validator->errors()], 422);
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

            return response()->json($this->resource($change), 201);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        }
    }

    public function show(NezhaPaymentAddressChange $change)
    {
        if (! NezhaPaymentAddressChangeService::enabled()) {
            return response()->json(['error' => 'address_change_feature_disabled'], 404);
        }

        return response()->json($this->resource($change));
    }

    public function approve(Request $request, NezhaPaymentAddressChange $change)
    {
        $validator = Validator::make($request->all(), [
            'new_fingerprint' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'totp_code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $approved = NezhaPaymentAddressChangeService::approveChange(
                auth('admin')->user(),
                (string) $change->public_id,
                (string) $request->input('new_fingerprint'),
                (string) $request->input('totp_code')
            );

            return response()->json($this->resource($approved));
        } catch (\DomainException $e) {
            return $this->domainError($e);
        }
    }

    public function cancel(Request $request, NezhaPaymentAddressChange $change)
    {
        $validator = Validator::make($request->all(), [
            'totp_code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $canceled = NezhaPaymentAddressChangeService::cancelChange(
                auth('admin')->user(),
                (string) $change->public_id,
                (string) $request->input('totp_code')
            );

            return response()->json($this->resource($canceled));
        } catch (\DomainException $e) {
            return $this->domainError($e);
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
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $state = NezhaPaymentAddressChangeService::emergencyPause(
                auth('admin')->user(),
                (int) $restaurant->id,
                (string) $request->input('network'),
                (string) $request->input('reason'),
                (string) $request->input('totp_code')
            );

            return response()->json([
                'restaurant_id' => (int) $state->restaurant_id,
                'network' => (string) $state->network,
                'state' => (string) $state->state,
                'paused_at' => $state->paused_at?->toIso8601String(),
            ]);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        }
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
            'requested_by_admin_id' => (int) $change->requested_by_admin_id,
            'merchant_confirmed_at' => $change->merchant_confirmed_at?->toIso8601String(),
            'approved_by_admin_id' => $change->approved_by_admin_id !== null
                ? (int) $change->approved_by_admin_id
                : null,
            'drain_until' => $change->drain_until?->toIso8601String(),
            'expires_at' => $change->expires_at?->toIso8601String(),
            'failure_code' => $change->failure_code,
        ];
    }

    private function domainError(\DomainException $e)
    {
        $code = $e->getMessage();
        $status = match ($code) {
            'address_change_not_found', 'address_change_restaurant_not_found' => 404,
            'address_change_totp_rate_limited' => 429,
            'address_change_feature_disabled' => 403,
            'address_change_totp_invalid', 'address_change_step_up_required' => 401,
            default => 409,
        };

        return response()->json([
            'error' => $code,
            'message' => '收款地址变更未执行，请核对当前状态与验证条件',
        ], $status);
    }
}
