<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\Helpers;
use App\CentralLogics\VendorDeviceTokenSessions;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * 哪吒商家版 App —— FCM 报警 token 注册/注销。
 * 由 App 的 WebView 在商家登录后(带 vendor/vendor_employee session cookie)调用。
 * 写多设备表 vendor_device_tokens, 归到「该餐厅 owner 的 vendor_id」, 店员设备另带 vendor_employee_id。
 * 报警扇出按 vendor_id 聚合 → 店主 + 全部店员设备一起收到(满足「一店多设备」)。L1 无涉(只存 token)。
 */
class NezhaAlarmTokenController extends Controller
{
    private const MAX_ACTIVE_DEVICES = 20;

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'min:20', 'max:512'],
            'platform' => ['required', 'in:android'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'invalid token'], 422);
        }

        try {
            $token = trim((string) $request->input('token'));
            $tokenHash = hash('sha256', $token);
            // 两种守卫都返回当前餐厅; vendor_id = owner 的 vendor id = 扇出聚合键
            $restaurant = Helpers::get_restaurant_data();
            $vendorId = $restaurant?->vendor_id;
            if (! $vendorId) {
                return response()->json(['message' => 'no restaurant'], 403);
            }
            $employeeId = auth('vendor_employee')->check() ? auth('vendor_employee')->id() : null;

            $now = now();
            DB::transaction(function () use ($employeeId, $now, $request, $token, $tokenHash, $vendorId): void {
                DB::table('vendor_device_tokens')->upsert([[
                    'vendor_id' => $vendorId,
                    'vendor_employee_id' => $employeeId,
                    'fcm_token' => Crypt::encryptString($token),
                    'token_hash' => $tokenHash,
                    'platform' => $request->input('platform'),
                    'is_active' => 1,
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]], ['token_hash'], [
                    'vendor_id',
                    'vendor_employee_id',
                    'fcm_token',
                    'platform',
                    'is_active',
                    'last_seen_at',
                    'updated_at',
                ]);

                $keptIds = DB::table('vendor_device_tokens')
                    ->where('vendor_id', $vendorId)
                    ->where('is_active', 1)
                    ->orderByDesc('last_seen_at')
                    ->orderByDesc('id')
                    ->limit(self::MAX_ACTIVE_DEVICES)
                    ->pluck('id');
                DB::table('vendor_device_tokens')
                    ->where('vendor_id', $vendorId)
                    ->where('is_active', 1)
                    ->when($keptIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $keptIds))
                    ->update(['is_active' => 0, 'updated_at' => $now]);
            }, 3);

            VendorDeviceTokenSessions::remember($tokenHash);

            return response()->json(['message' => 'ok']);
        } catch (\Throwable $e) {
            Log::warning('nezha alarm token register failed: '.$e->getMessage());

            return response()->json(['message' => 'registration unavailable'], 503);
        }
    }

    public function deregister(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'min:20', 'max:512'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'invalid token'], 422);
        }

        try {
            $token = trim((string) $request->input('token'));
            $restaurant = Helpers::get_restaurant_data();
            $vendorId = $restaurant?->vendor_id;
            if (! $vendorId) {
                return response()->json(['message' => 'no restaurant'], 403);
            }
            $tokenHash = hash('sha256', $token);
            DB::table('vendor_device_tokens')
                ->where('vendor_id', $vendorId)
                ->where('token_hash', $tokenHash)
                ->update(['is_active' => 0, 'updated_at' => now()]);
            VendorDeviceTokenSessions::forgetIfMatches($tokenHash);

            return response()->json(['message' => 'ok']);
        } catch (\Throwable $e) {
            Log::warning('nezha alarm token deregister failed: '.$e->getMessage());

            return response()->json(['message' => 'deregistration unavailable'], 503);
        }
    }
}
