<?php

namespace App\CentralLogics;

use App\Models\NezhaRefundRecord;
use App\Models\Order;
use App\Models\Restaurant;

class NezhaCustomerNudge
{
    public static function query(int $restaurantId)
    {
        $vendorId = (int) (Restaurant::where('id', $restaurantId)->value('vendor_id') ?: 0);

        return Order::query()
            ->where('restaurant_id', $restaurantId)
            ->Notpos()
            ->HasSubscriptionToday()
            ->where(function ($query) use ($vendorId) {
                $query->where(function ($q) {
                    $q->where('order_type', 'delivery')
                        ->where('order_status', 'picked_up')
                        ->whereNotNull('delivery_link_reminded_at')
                        ->where(function ($link) {
                            $link->whereNull('yandex_tracking_url')
                                ->orWhere('yandex_tracking_url', '');
                        });
                })->orWhere(function ($q) use ($vendorId) {
                    $q->whereIn('order_status', ['confirmed', 'accepted', 'processing'])
                        ->whereIn('id', self::notificationOrderIds($vendorId, ['顾客在催出餐']));
                })->orWhere(function ($q) use ($vendorId) {
                    $q->where(function ($pendingRefund) {
                        $pendingRefund->where('order_status', 'refund_requested')
                            ->orWhereIn('id', NezhaRefundRecord::where('status', 'pending_merchant_refund')->select('order_id'));
                    })->whereIn('id', self::notificationOrderIds($vendorId, ['顾客在催退款']));
                });
            });
    }

    public static function openOrderIds(int $restaurantId): array
    {
        return self::query($restaurantId)->pluck('id')->filter()->values()->all();
    }

    public static function count(int $restaurantId): int
    {
        return self::query($restaurantId)->count();
    }

    private static function notificationOrderIds(int $vendorId, array $titles)
    {
        return \App\Models\UserNotification::query()
            ->selectRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.order_id')) AS UNSIGNED)")
            ->where('vendor_id', $vendorId)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.order_id')) IS NOT NULL")
            ->whereIn(\Illuminate\Support\Facades\DB::raw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.title'))"), $titles);
    }
}
