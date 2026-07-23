<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaCustomerRefundAddressCredentialService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class RefundAddressCredentialController extends Controller
{
    public function store(Request $request)
    {
        if (! NezhaCustomerRefundAddressCredentialService::acceptingNewPayments()) {
            return $this->error(
                'refund_binding_not_accepting_new_payments',
                'USDT 付款暂未开放，请选择其他付款方式',
                409
            );
        }

        $user = $request->user() ?: Auth::guard('api')->user();
        if (! $user) {
            return $this->error(
                'refund_address_login_required',
                '请先登录，再绑定本单退款接收地址',
                401
            );
        }

        $validator = Validator::make($request->all(), [
            'restaurant_id' => 'required|integer|min:1',
            'method_id' => 'required|integer|min:1',
            'address' => 'required|string|max:120',
            'confirmed' => 'required|boolean',
            'existing_credential_token' => 'nullable|string|max:128',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }

        try {
            $issued = NezhaCustomerRefundAddressCredentialService::issue(
                (int) $user->id,
                (int) $request->input('restaurant_id'),
                (int) $request->input('method_id'),
                (string) $request->input('address'),
                (bool) $request->boolean('confirmed'),
                $request->input('existing_credential_token')
            );
            $credential = $issued['credential'];

            return response()->json([
                'credential_token' => $issued['token'],
                'credential_id' => (string) $credential->public_id,
                'network' => (string) $credential->network,
                'address' => (string) $credential->address_snapshot,
                'address_fingerprint' => (string) $credential->address_fingerprint,
                'verification_status' => 'customer_attested',
                'route_policy_version' => (string) $credential->route_policy_version,
                'issued_at' => $credential->issued_at?->toIso8601String(),
                'expires_at' => $credential->expires_at?->toIso8601String(),
                'reused' => (bool) $issued['reused'],
            ], $issued['reused'] ? 200 : 201);
        } catch (\DomainException $e) {
            $messages = [
                'refund_address_confirmation_required' => '请核对并确认完整退款地址',
                'refund_address_invalid' => '退款地址格式与所选网络不匹配',
                'refund_address_matches_merchant_receive_address' => '退款地址不能与本单商家收款地址相同',
                'refund_credential_method_not_available' => '该 USDT 付款方式暂不可用',
                'refund_credential_restaurant_not_available' => '商家当前无法使用该 USDT 网络',
            ];

            return $this->error(
                $e->getMessage(),
                $messages[$e->getMessage()] ?? '退款地址凭据签发失败，请重新核对',
                422
            );
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
