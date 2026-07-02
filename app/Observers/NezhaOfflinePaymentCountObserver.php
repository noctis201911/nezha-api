<?php

namespace App\Observers;

use App\CentralLogics\NezhaOrderCounts;
use App\Models\OfflinePayments;

/**
 * 哪吒 P1b-A(裁决追加): 顾客提交/修改直付凭证(offline_payments 写入)时, 失效该单所属店的计数缓存。
 * 「待确认收款」是唯一由 offline_payments 写驱动、Order 表不写的增量事件, Order observer 会漏,
 * 故单挂此 observer 消除该边缘(而非接受 ≤20s TTL 兜底)。仅 Cache::forget, 不干预支付逻辑。
 */
class NezhaOfflinePaymentCountObserver
{
    public function created(OfflinePayments $offlinePayment): void
    {
        NezhaOrderCounts::forgetByOrderId($offlinePayment->order_id);
    }

    public function updated(OfflinePayments $offlinePayment): void
    {
        NezhaOrderCounts::forgetByOrderId($offlinePayment->order_id);
    }

    public function deleted(OfflinePayments $offlinePayment): void
    {
        NezhaOrderCounts::forgetByOrderId($offlinePayment->order_id);
    }
}
