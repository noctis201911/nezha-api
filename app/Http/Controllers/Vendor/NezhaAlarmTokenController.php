<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒商家版 App —— FCM 报警 token 注册/注销。
 * 由 App 的 WebView 在商家登录后(带 vendor/vendor_employee session cookie)调用。
 * 写多设备表 vendor_device_tokens, 归到「该餐厅 owner 的 vendor_id」, 店员设备另带 vendor_employee_id。
 * 报警扇出按 vendor_id 聚合 → 店主 + 全部店员设备一起收到(满足「一店多设备」)。L1 无涉(只存 token)。
 */
class NezhaAlarmTokenController extends Controller
{
    public function register(Request $request)
    {
        try {
            $token = trim((string) $request->input('token'));
            if ($token === '' || strlen($token) < 20) {
                return response()->json(['message' => 'invalid token'], 422);
            }
            // 两种守卫都返回当前餐厅; vendor_id = owner 的 vendor id = 扇出聚合键
            $restaurant = Helpers::get_restaurant_data();
            $vendorId = $restaurant?->vendor_id;
            if (! $vendorId) {
                return response()->json(['message' => 'no restaurant'], 403);
            }
            $employeeId = auth('vendor_employee')->check() ? auth('vendor_employee')->id() : null;

            $now = now();
            $exists = DB::table('vendor_device_tokens')->where('fcm_token', $token)->first();
            if ($exists) {
                // 换账号同机登录: token 归到新店主名下并激活(防串店/前员工继续收单)
                DB::table('vendor_device_tokens')->where('fcm_token', $token)->update([
                    'vendor_id' => $vendorId,
                    'vendor_employee_id' => $employeeId,
                    'platform' => $request->input('platform', 'android'),
                    'is_active' => 1,
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('vendor_device_tokens')->insert([
                    'vendor_id' => $vendorId,
                    'vendor_employee_id' => $employeeId,
                    'fcm_token' => $token,
                    'platform' => $request->input('platform', 'android'),
                    'is_active' => 1,
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return response()->json(['message' => 'ok']);
        } catch (\Throwable $e) {
            Log::info('nezha alarm token register failed: ' . $e->getMessage());

            return response()->json(['message' => 'error'], 200); // 静默, 不让 App 因此弹错
        }
    }

    public function deregister(Request $request)
    {
        try {
            $token = trim((string) $request->input('token'));
            if ($token !== '') {
                // 登出/退出账号: 停用本机 token, 该设备不再收报警(防退出后仍被吵)
                DB::table('vendor_device_tokens')->where('fcm_token', $token)->update([
                    'is_active' => 0,
                    'updated_at' => now(),
                ]);
            }

            return response()->json(['message' => 'ok']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'ok']);
        }
    }
}
