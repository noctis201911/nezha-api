<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaRiskControl;
use App\Models\BusinessSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * 哪吒风控① 顾客端预检 API.
 * 顾客在「看到商家收款码之前」调用本接口:
 *   pass   → 正常显示收款码
 *   reject → 金额超限, 不显示码, 提示联系客服 (HTTP 200, 前端按 risk_action 处理)
 *   review → 转人工审核, 不显示码, 提示等待客服; 已写入后台审核队列
 */
class NezhaRiskController extends Controller
{
    public function risk_check(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'restaurant_id' => 'required',
            'order_amount'  => 'required|numeric',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $ctx    = self::build_context($request);
        $result = NezhaRiskControl::evaluate($ctx);

        if ($result['action'] === 'pass') {
            return response()->json(['risk_action' => 'pass', 'message' => ''], 200);
        }

        // 命中即落库 (审计日志 + 进审核队列)
        NezhaRiskControl::record($ctx, $result);

        if ($result['action'] === 'reject') {
            $contact = (string) (BusinessSetting::where('key', 'nezha_risk_contact_info')->first()?->value ?? '');
            $msg = $result['message'] . ($contact !== '' ? '（客服：' . $contact . '）' : '');
            return response()->json(['risk_action' => 'reject', 'message' => $msg], 200);
        }

        return response()->json(['risk_action' => 'review', 'message' => $result['message']], 200);
    }

    /** 组装风控上下文 (登录用户 + 游客都兼容) */
    public static function build_context(Request $request): array
    {
        $user = $request->user ?? null;

        return [
            'user_id'         => $user?->id,
            'guest_id'        => $request->guest_id ?? $request->header('guest-id'),
            'restaurant_id'   => $request->restaurant_id,
            'order_amount'    => (float) $request->order_amount,
            'payment_channel' => NezhaRiskControl::detect_channel($request),
            'ip_address'      => $request->ip(),
            'snapshot'        => [
                'name'  => $user ? trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? '')) : null,
                'phone' => $user?->phone,
                'email' => $user?->email,
            ],
        ];
    }
}
