<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaRefundReconfirmationService;
use App\Http\Controllers\Controller;
use App\Models\NezhaRefundRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RefundReconfirmationController extends Controller
{
    public function challenge(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|integer|min:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }

        $record = NezhaRefundRecord::where('order_id', $request->integer('order_id'))
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->first();
        if (! $record) {
            return $this->error('refund_record_not_found', '未找到可确认的退款记录', 404);
        }

        try {
            return response()->json(
                NezhaRefundReconfirmationService::issueChallenge(
                    $record,
                    $request->user(),
                    $request
                )
            );
        } catch (\DomainException $e) {
            return $this->domainError($e);
        }
    }

    public function confirm(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refund_record_id' => 'required|integer|min:1',
            'challenge_token' => 'required|string|size:64',
            'password' => 'nullable|string|max:200',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 422);
        }

        try {
            $record = NezhaRefundReconfirmationService::confirm(
                $request->integer('refund_record_id'),
                $request->user(),
                $request,
                (string) $request->input('challenge_token'),
                $request->input('password')
            );

            $this->notifyMerchant($record);

            return response()->json([
                'message' => '退款目标已再次确认，商家现在只能向该冻结地址退款',
                'refund' => $record->customerProjection(),
            ]);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        }
    }

    private function notifyMerchant(NezhaRefundRecord $record): void
    {
        try {
            $record->loadMissing('order.restaurant.vendor');
            $order = $record->order;
            if (! $order || ! $order->restaurant) {
                return;
            }
            $message = "✅ 顾客已完成退款安全确认\n订单 #{$order->id}\n"
                ."请只向本单冻结的 {$record->asset_network} 地址发送精确 USDT 数量，"
                .'并在链上终局后回填交易哈希。';
            Helpers::sendTelegramToRestaurant($order->restaurant, $message);
            $vendorId = $order->restaurant?->vendor_id;
            if ($vendorId) {
                $data = Helpers::makeDataForPushNotification(
                    title: '顾客已确认USDT退款目标',
                    message: '订单 #'.$order->id.' 已进入待退款，请按冻结地址和精确数量操作。',
                    orderId: $order->id,
                    type: 'order_status',
                    orderStatus: 'refunded'
                );
                Helpers::insertDataOnNotificationTable($data, 'vendor', $vendorId);
            }
        } catch (\Throwable $e) {
            info('refund reconfirm merchant notification failed: '.$e->getMessage());
        }
    }

    private function domainError(\DomainException $e)
    {
        $messages = [
            'refund_reconfirm_expired' => '确认已超时，请重新开始',
            'refund_reconfirm_invalid' => '确认凭据无效，请重新开始',
            'refund_reconfirm_already_consumed' => '该确认已使用，不能重放',
            'fresh_auth_failed' => '密码不正确，退款仍保持等待确认',
            'fresh_auth_required' => '请先用原登录方式重新登录，再确认本单退款目标',
            'fresh_auth_method_mismatch' => '请使用该账号原来的登录方式重新认证',
            'fresh_auth_method_unavailable' => '该账号当前登录方式不支持退款确认，请联系平台处理',
            'refund_destination_hold' => '退款目标当前不可执行，请联系平台处理',
            'refund_mode_closed' => '退款执行当前已暂停',
            'refund_reconfirm_not_available' => '该退款当前无需或无法再次确认',
            'refund_snapshot_changed' => '退款快照校验失败，已保持挂起',
        ];
        $status = $e->getMessage() === 'refund_record_not_found' ? 404 : 409;

        return $this->error(
            $e->getMessage(),
            $messages[$e->getMessage()] ?? '退款确认未完成',
            $status
        );
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
