<?php

namespace App\Console\Commands;

use App\CentralLogics\Helpers;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒商家版 App —— 新订单报警「兜底网」。每分钟跑一次。
 * 下单路径只负责把报警放入队列，不执行外部 FCM 请求；
 * 真正保底靠本 sweep: 凡 outbox 里未发成功(pending/failed)、或 queued 租约超时、重试未超上限、且单仍待商家处理的,
 * 重新入队报警(覆盖「商家还没登录 App / FCM 临时失败 / worker 租约丢失」等情况)。
 * 商家一旦接单(状态离开 pending/confirmed)或单消失 → 收尾, 停止重试, 不骚扰。
 * 受总开关 nezha_alert_push_status 控制(默认关 → 整个 sweep 直接 return)。L1 无涉。
 */
class NezhaVendorAlarmSweep extends Command
{
    protected $signature = 'nezha:vendor-alarm-sweep';

    protected $description = '哪吒商家版App: 重试未送达的新单报警(outbox 兜底网)';

    public function handle()
    {
        // 总开关关 => 不跑(与 dispatchVendorOrderAlarm 一致, 上线前零真实影响)
        if ((int) Helpers::get_business_settings('nezha_alert_push_status') !== 1) {
            return 0;
        }

        // 只重试: 未发成功(pending/failed) 或 queued 超过 2 分钟 + 重试未超 30 次 + 近 30 分钟内的单。
        $rows = DB::table('vendor_alert_outbox')
            ->where(function ($query) {
                $query->whereIn('status', ['pending', 'failed'])
                    ->orWhere(function ($query) {
                        $query->where('status', 'queued')
                            ->where('updated_at', '<=', now()->subMinutes(2));
                    });
            })
            ->where('attempts', '<', 30)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->orderBy('id')
            ->limit(200)
            ->get();

        $retried = 0;
        $closed = 0;
        foreach ($rows as $row) {
            $order = Order::with('restaurant')->find($row->order_id);
            // 单已不存在 或 商家已处理(不再是待商家处理态) => 收尾, 停止重试
            if (! $order || ! in_array($order->order_status, ['pending', 'confirmed'], true)) {
                DB::table('vendor_alert_outbox')->where('id', $row->id)->update([
                    'status' => 'sent',
                    'last_error' => 'stale_or_handled',
                    'updated_at' => now(),
                ]);
                $closed++;

                continue;
            }
            if ($row->status === 'queued') {
                DB::table('vendor_alert_outbox')
                    ->where('id', $row->id)
                    ->where('status', 'queued')
                    ->update(['status' => 'failed', 'last_error' => 'queue_lease_expired', 'updated_at' => now()]);
            }
            Helpers::deliverVendorAlarmForOrder($order, $order->restaurant?->vendor_id);
            $retried++;
        }

        if ($retried || $closed) {
            Log::info("nezha vendor-alarm-sweep retried={$retried} closed={$closed}");
        }

        return 0;
    }
}
