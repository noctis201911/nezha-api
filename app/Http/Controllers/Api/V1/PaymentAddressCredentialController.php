<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaPaymentAddressCredentialService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class PaymentAddressCredentialController extends Controller
{
    public function store(Request $request)
    {
        if (! NezhaPaymentAddressCredentialService::enabled()) {
            return $this->error('payment_address_credential_disabled', '该付款保护功能暂未启用', 403);
        }

        // 路由需在总闸关闭时允许游客取得兼容码；一旦开启，必须在此处强制 API 登录。
        $user = $request->user() ?: Auth::guard('api')->user();
        if (! $user) {
            return $this->error('address_credential_login_required', '请先登录，再重新获取本次付款地址', 401);
        }

        $validator = Validator::make($request->all(), [
            'restaurant_id' => 'required|integer|min:1',
            'method_id' => 'required|integer|min:1',
            'existing_credential_token' => 'nullable|string|max:128',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }

        try {
            $issued = NezhaPaymentAddressCredentialService::issue(
                (int) $user->id,
                (int) $request->input('restaurant_id'),
                (int) $request->input('method_id'),
                $request->input('existing_credential_token')
            );
            $credential = $issued['credential'];

            return response()->json([
                'credential_token' => $issued['token'],
                'credential_id' => (string) $credential->public_id,
                'address_version' => substr((string) $credential->address_fingerprint, 0, 16),
                'restaurant_id' => (int) $credential->restaurant_id,
                'method_id' => (int) $credential->method_id,
                'network' => (string) $credential->network,
                'address' => (string) $credential->address_snapshot,
                'issued_at' => $credential->issued_at?->toIso8601String(),
                'expires_at' => $credential->expires_at?->toIso8601String(),
                'reused' => (bool) $issued['reused'],
            ], $issued['reused'] ? 200 : 201);
        } catch (\DomainException $e) {
            $code = $e->getMessage();
            $status = match ($code) {
                'credential_restaurant_not_found', 'credential_method_not_available' => 404,
                'credential_network_unavailable' => 409,
                'credential_feature_disabled' => 403,
                default => 422,
            };

            return $this->error($code, '该 USDT 付款方式暂不可用，请选择其他付款方式', $status);
        }
    }

    private function error(string $code, string $message, int $status)
    {
        return response()->json([
            'errors' => [
                ['code' => $code, 'message' => $message],
            ],
        ], $status);
    }
}
