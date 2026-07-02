<?php

namespace App\Observers;

use App\CentralLogics\NezhaOrderCounts;
use App\Models\Order;

/**
 * 哪吒 P1b-A: 订单写入(建/改/删)时失效该店计数缓存, 保证侧栏/看板待办条/列表组tab 计数新鲜。
 * 仅 Cache::forget(单店键), 不干预订单逻辑; 跨表(offline_payments/退款记录)变更由 TTL(20s) 兜底。
 * 遵循本项目既有 Observer 注册模式(见 EventServiceProvider: BusinessSetting/DataSetting)。
 */
class NezhaOrderCountObserver
{
    public function created(Order $order): void
    {
        NezhaOrderCounts::forget($order->restaurant_id);
    }

    public function updated(Order $order): void
    {
        NezhaOrderCounts::forget($order->restaurant_id);
    }

    public function deleted(Order $order): void
    {
        NezhaOrderCounts::forget($order->restaurant_id);
    }
}
