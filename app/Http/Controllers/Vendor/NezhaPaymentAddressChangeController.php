<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\NezhaPaymentAddressChangeService;
use App\Http\Controllers\Controller;
use App\Models\NezhaPaymentAddressChange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NezhaPaymentAddressChangeController extends Controller
{
    public function show(NezhaPaymentAddressChange $change)
    {
        $vendor = $this->owner();
        if (! $vendor) {
            return response()->json(['error' => 'address_change_owner_required'], 403);
        }
        if (! NezhaPaymentAddressChangeService::enabled()) {
            return response()->json(['error' => 'address_change_feature_disabled'], 404);
        }
        if (! $this->owns($vendor->id, $change)) {
            return response()->json(['error' => 'address_change_not_found'], 404);
        }

        return response()->json($this->resource($change));
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
            return response()->json(['error' => 'address_change_owner_required'], 403);
        }
        $validator = Validator::make($request->all(), [
            'new_fingerprint' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        if (! $this->owns($vendor->id, $change)) {
            return response()->json(['error' => 'address_change_not_found'], 404);
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

            return response()->json($this->resource($updated));
        } catch (\DomainException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'message' => '收款地址确认未执行，请刷新后核对完整地址',
            ], 409);
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
}
