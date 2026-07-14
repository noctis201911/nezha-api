<?php

namespace App\CentralLogics;

use App\Models\NezhaPaymentAddressChange;
use App\Models\NezhaPaymentAddressChangeEvent;
use App\Models\NezhaPaymentNetworkState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Best-effort security notifications for the address-change state machine.
 *
 * Messages deliberately omit full addresses, reasons and authentication data.
 * The state-machine event table records one dispatch summary per transition;
 * NezhaNotifyLog records the real channel outcome without recipient PII.
 */
class NezhaPaymentAddressChangeNotifier
{
    private const MERCHANT_EVENTS = [
        'requested',
        'distinct_admin_approved',
        'distinct_admin_rejected',
        'admin_canceled',
        'expired',
        'applied',
        'apply_failed',
    ];

    private const ADMIN_EVENTS = [
        'merchant_confirmed',
        'merchant_rejected',
        'applied',
        'apply_failed',
    ];

    public static function change(NezhaPaymentAddressChange $change, string $event): void
    {
        try {
            if (! Schema::hasTable('nezha_payment_address_change_events')
                || ! Schema::hasTable('nezha_payment_network_states')) {
                return;
            }

            $auditType = self::auditType($event);
            if ($auditType === null) {
                return;
            }
            $lock = Cache::lock('payment-address-notify:'.$change->id.':'.$auditType, 20);
            if (! $lock->get()) {
                return;
            }

            try {
                if (NezhaPaymentAddressChangeEvent::where('change_id', $change->id)
                    ->where('event_type', $auditType)
                    ->exists()) {
                    return;
                }

                $state = NezhaPaymentNetworkState::where('restaurant_id', $change->restaurant_id)
                    ->where('network', $change->network)
                    ->first();
                if (! $state) {
                    return;
                }

                $outcomes = [];
                if (in_array($event, self::MERCHANT_EVENTS, true)) {
                    $outcomes += self::notifyMerchant(
                        (int) $change->restaurant_id,
                        (string) $change->network,
                        (string) $change->public_id,
                        $event
                    );
                }
                if (in_array($event, self::ADMIN_EVENTS, true)) {
                    $outcomes['admin_telegram'] = self::notifyAdmin(
                        (int) $change->restaurant_id,
                        (string) $change->network,
                        (string) $change->public_id,
                        $event
                    );
                }

                NezhaPaymentAddressChangeEvent::create([
                    'change_id' => $change->id,
                    'network_state_id' => $state->id,
                    'event_type' => $auditType,
                    'state_from' => $change->state,
                    'state_to' => $change->state,
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'totp_counter' => null,
                    'context' => ['channel_outcomes' => $outcomes],
                ]);
            } finally {
                optional($lock)->release();
            }
        } catch (\Throwable $e) {
            // Notification failure must never roll back or disguise a funds-address transition.
            Log::warning('payment address notification failed change#'.$change->id.' event='.$event);
        }
    }

    public static function emergencyPause(NezhaPaymentNetworkState $state): void
    {
        try {
            $event = 'emergency_paused';
            $outcomes = self::notifyMerchant(
                (int) $state->restaurant_id,
                (string) $state->network,
                'network-'.$state->id,
                $event
            );
            $outcomes['admin_telegram'] = self::notifyAdmin(
                (int) $state->restaurant_id,
                (string) $state->network,
                'network-'.$state->id,
                $event
            );

            if (Schema::hasTable('nezha_payment_address_change_events')) {
                NezhaPaymentAddressChangeEvent::create([
                    'change_id' => null,
                    'network_state_id' => $state->id,
                    'event_type' => 'notify_emergency_paused',
                    'state_from' => 'paused',
                    'state_to' => 'paused',
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'totp_counter' => null,
                    'context' => ['channel_outcomes' => $outcomes],
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('payment address notification failed state#'.$state->id.' event=emergency_paused');
        }
    }

    private static function notifyMerchant(int $restaurantId, string $network, string $reference, string $event): array
    {
        $restaurant = DB::table('restaurants')->where('id', $restaurantId)->first();
        if (! $restaurant) {
            return [
                'site' => 'no_recipient',
                'telegram' => 'no_recipient',
                'email' => 'no_recipient',
                'push' => 'no_recipient',
            ];
        }
        $vendorId = (int) ($restaurant->vendor_id ?? 0);
        $vendor = $vendorId > 0 ? DB::table('vendors')->where('id', $vendorId)->first() : null;
        [$title, $body] = self::merchantCopy($network, $event);
        $shortReference = substr($reference, 0, 12);
        $detail = 'address_change:'.$shortReference;
        $data = Helpers::makeDataForPushNotification(
            title: $title,
            message: $body,
            type: 'nezha_payment_address_security',
            dataId: $reference
        );
        $data['click_action'] = url('/restaurant-panel/withdraw-method');

        $site = 'no_recipient';
        if ($vendorId > 0 && Schema::hasTable('user_notifications')) {
            try {
                $site = Helpers::insertDataOnNotificationTable($data, 'vendor', $vendorId) ? 'ok' : 'failed';
            } catch (\Throwable $e) {
                $site = 'failed';
            }
        }
        NezhaNotifyLog::record('site', 'merchant', 'address_'.$event, $site, null, $restaurantId, $detail);

        $telegram = 'no_recipient';
        if (! empty($restaurant->telegram_chat_id)) {
            try {
                $telegram = Helpers::sendTelegramToRestaurant(
                    $restaurant,
                    "🔐 哪吒资金安全｜{$title}\n{$body}\n编号：{$shortReference}\n请只在商家后台核对完整地址。"
                ) ? 'ok' : 'failed';
            } catch (\Throwable $e) {
                $telegram = 'failed';
            }
        }
        NezhaNotifyLog::record('telegram', 'merchant', 'address_'.$event, $telegram, null, $restaurantId, $detail);

        $email = 'no_recipient';
        $emailAddress = trim((string) (
            $restaurant->nezha_notify_email
            ?? $restaurant->email
            ?? $vendor->email
            ?? ''
        ));
        if (! config('mail.status')) {
            $email = 'skipped';
        } elseif ($emailAddress !== '') {
            try {
                Mail::raw(
                    $body."\n\n编号：{$shortReference}\n请直接登录哪吒商家后台核对完整地址，不要回复或使用邮件中的任何其它地址。",
                    static function ($message) use ($emailAddress, $title): void {
                        $message->to($emailAddress)->subject('【哪吒资金安全】'.$title);
                    }
                );
                $email = 'ok';
            } catch (\Throwable $e) {
                $email = 'failed';
            }
        }
        NezhaNotifyLog::record('email', 'merchant', 'address_'.$event, $email, null, $restaurantId, $detail);

        $push = 'no_recipient';
        if ($vendor && ! empty($vendor->firebase_token)) {
            try {
                $push = Helpers::send_push_notif_to_device(
                    $vendor->firebase_token,
                    $data,
                    url('/restaurant-panel/withdraw-method')
                ) ? 'ok' : 'failed';
            } catch (\Throwable $e) {
                $push = 'failed';
            }
        }
        NezhaNotifyLog::record('push', 'merchant', 'address_'.$event, $push, null, $restaurantId, $detail);

        return compact('site', 'telegram', 'email', 'push');
    }

    private static function notifyAdmin(int $restaurantId, string $network, string $reference, string $event): string
    {
        $restaurant = DB::table('restaurants')->where('id', $restaurantId)->first();
        $chatId = Helpers::get_business_settings('nezha_risk_admin_chat_id', false);
        $shortReference = substr($reference, 0, 12);
        $detail = 'address_change:'.$shortReference;
        if (! $chatId) {
            NezhaNotifyLog::record('telegram', 'owner', 'address_'.$event, 'no_recipient', null, $restaurantId, $detail);

            return 'no_recipient';
        }
        $shop = trim((string) ($restaurant->name ?? '商家#'.$restaurantId));
        $eventText = match ($event) {
            'merchant_confirmed' => '商家 owner 已确认，等待不同管理员复核',
            'merchant_rejected' => '商家 owner 已拒绝该申请',
            'applied' => '地址变更已完成并写入新版本',
            'apply_failed' => '地址变更因漂移失败，网络已保持/进入暂停',
            'emergency_paused' => '管理员已紧急暂停该网络并撤销未消费凭据',
            default => '地址变更状态已更新',
        };
        try {
            $sent = Helpers::sendTelegramToAdmin(
                "🔐 哪吒地址治理｜{$shop}\n网络：{$network}\n状态：{$eventText}\n编号：{$shortReference}\n请登录总后台查看完整审计。"
            );
            $outcome = $sent ? 'ok' : 'failed';
        } catch (\Throwable $e) {
            $outcome = 'failed';
        }
        NezhaNotifyLog::record('telegram', 'owner', 'address_'.$event, $outcome, null, $restaurantId, $detail);

        return $outcome;
    }

    private static function merchantCopy(string $network, string $event): array
    {
        return match ($event) {
            'requested' => [
                '收款地址变更待确认',
                "平台管理员申请更换 {$network} 收款地址。当前地址尚未改变，请登录商家后台逐字核对。",
            ],
            'distinct_admin_approved' => [
                '收款地址变更已通过独立复核',
                "{$network} 候选地址已获不同管理员批准；当前地址仍未改变，系统正等待旧地址凭据排空。",
            ],
            'distinct_admin_rejected' => [
                '收款地址变更已被独立复核驳回',
                "{$network} 地址变更申请已被独立复核员驳回，当前地址未改变。",
            ],
            'admin_canceled' => [
                '收款地址变更已取消',
                "{$network} 地址变更申请已由管理员取消，当前地址未改变。",
            ],
            'expired' => [
                '收款地址变更已驳回（超时）',
                "{$network} 地址变更申请因未在有效期内完成而由系统自动驳回，当前地址未改变。",
            ],
            'applied' => [
                '新收款地址已生效',
                "{$network} 收款地址已按审批流程切换。请立即登录商家后台核对当前完整地址。",
            ],
            'apply_failed' => [
                '收款地址变更未生效',
                "{$network} 地址变更因状态漂移未执行，当前数据库地址未被覆盖；该网络已暂停等待人工核对。",
            ],
            'emergency_paused' => [
                'USDT 收款网络已紧急暂停',
                "{$network} 收款已被管理员紧急暂停，尚未消费的地址凭据已撤销。请立即登录商家后台核对。",
            ],
            default => ['收款地址安全状态已更新', "{$network} 收款地址安全状态已更新，请登录商家后台核对。"],
        };
    }

    private static function auditType(string $event): ?string
    {
        return match ($event) {
            'requested' => 'notify_requested',
            'merchant_confirmed' => 'notify_merchant_confirmed',
            'merchant_rejected' => 'notify_merchant_rejected',
            'distinct_admin_approved' => 'notify_admin_approved',
            'distinct_admin_rejected' => 'notify_admin_rejected',
            'admin_canceled' => 'notify_admin_canceled',
            'expired' => 'notify_expired',
            'applied' => 'notify_applied',
            'apply_failed' => 'notify_apply_failed',
            default => null,
        };
    }
}
