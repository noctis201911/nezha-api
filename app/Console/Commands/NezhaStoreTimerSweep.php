<?php

namespace App\Console\Commands;

use App\CentralLogics\Helpers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒 忙碌模式 / 定时挂起 —— 到期兜底清扫。每分钟跑一次。
 * 顾客端"有效状态"已由懒判定(OrderController::nezha_store_paused / Helpers::nezha_store_extra)保证正确;
 * 本 sweep 负责把 DB flag 也翻正, 让商家后台状态一致 + 释放到期态:
 *   ① 定时挂起到期(nezha_pause_until<now 且 nezha_temp_closed=1) → 真正恢复营业(temp_closed=0, pause_until=null)。
 *   ② 忙碌到期(nezha_busy_until<now) → 清 busy_until/min/reason。
 * 受总开关 nezha_busy_mode_status 控制(默认0关 → 整个 sweep 直接 return, 上线前零真实影响)。
 * 🔴 只翻本功能自己的状态位, 不碰钱 / 订单 / L1 留存, 无涉合规。query builder update 不触发 observer(无额外副作用)。
 */
class NezhaStoreTimerSweep extends Command
{
    protected $signature = 'nezha:store-timer-sweep';

    protected $description = '哪吒: 忙碌模式/定时挂起到期兜底(自动恢复接单 + 清忙碌态)';

    public function handle()
    {
        // 总开关关 => 不跑(与顾客端懒判定的 gate 一致, 上线前零真实影响)
        if ((int) Helpers::get_business_settings('nezha_busy_mode_status') !== 1) {
            return 0;
        }

        $now = now();

        // ① 定时挂起到期 → 自动恢复接单(仅清"带到期时间"的暂停; null=无限期手动暂停不动)
        $resumed = DB::table('restaurants')
            ->where('nezha_temp_closed', 1)
            ->whereNotNull('nezha_pause_until')
            ->where('nezha_pause_until', '<', $now)
            ->update(['nezha_temp_closed' => 0, 'nezha_pause_until' => null, 'updated_at' => $now]);

        // ② 忙碌到期 → 清忙碌态
        $unbusied = DB::table('restaurants')
            ->whereNotNull('nezha_busy_until')
            ->where('nezha_busy_until', '<', $now)
            ->update(['nezha_busy_until' => null, 'nezha_busy_min' => null, 'nezha_busy_reason' => null, 'updated_at' => $now]);

        if ($resumed || $unbusied) {
            Log::info('nezha_store_timer_sweep', ['resumed' => $resumed, 'unbusied' => $unbusied, 'at' => $now->toIso8601String()]);
        }

        return 0;
    }
}
