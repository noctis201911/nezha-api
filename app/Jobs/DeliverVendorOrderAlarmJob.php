<?php

namespace App\Jobs;

use App\CentralLogics\Helpers;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DeliverVendorOrderAlarmJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $orderId, public int $vendorId) {}

    public function handle(): void
    {
        $order = Order::with('restaurant')->find($this->orderId);
        if (! $order || ! in_array($order->order_status, ['pending', 'confirmed'], true)) {
            DB::table('vendor_alert_outbox')->where('order_id', $this->orderId)->update([
                'status' => 'sent',
                'last_error' => 'stale_or_handled',
                'updated_at' => now(),
            ]);

            return;
        }

        Helpers::deliverVendorAlarmForOrderNow($order, $this->vendorId);
    }
}
