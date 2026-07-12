<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒 — 通知投递结果日志(P4 outbox 轻量踏脚石)。
 * 每次对外通知(TG/邮件)发送后记一行结果, 供日常运营检查「通知有没有送达」。
 *
 * 🔴 铁律:
 *  - best-effort: 整个 record() 包在 try/catch, 绝不抛异常、绝不阻断真实通知或任何 L1 流程。
 *  - 零 PII: 只存渠道/角色/事件/结果 + 内部 order_id/restaurant_id + 无 PII 短原因; 禁存 chat_id/邮箱/电话/顾客信息。
 *  - 杀掉开关 nezha_notif_log_status(默认 1); 置 0 即停记, 真实通知不受影响。
 *  - 当前同步模式(nezha_notif_async_status=0)下 outcome=ok 即送达真值; 若日后切异步, ok=入队成功(完整送达真值属 P4)。
 */
class NezhaNotifyLog
{
    /**
     * @param string   $channel  telegram|email
     * @param string   $target   merchant|owner|support
     * @param string   $event    new_order|remind|prep_overtime|cancel_refund|owner_escalate ...
     * @param string   $outcome  ok|failed|skipped|no_recipient
     * @param int|null $orderId
     * @param int|null $restaurantId
     * @param string|null $detail 无 PII 短原因/标记
     */
    public static function record(string $channel, string $target, string $event, string $outcome, ?int $orderId = null, ?int $restaurantId = null, ?string $detail = null): void
    {
        try {
            if ((int) (Helpers::get_business_settings('nezha_notif_log_status') ?? 1) !== 1) {
                return; // 杀掉开关: 停记, 不影响任何真实通知
            }
            DB::table('nezha_notification_log')->insert([
                'channel'       => substr($channel, 0, 16),
                'target'        => substr($target, 0, 16),
                'event_type'    => substr($event, 0, 40),
                'outcome'       => substr($outcome, 0, 16),
                'order_id'      => $orderId,
                'restaurant_id' => $restaurantId,
                'detail'        => $detail !== null ? substr($detail, 0, 255) : null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            // 概率性保活清理(~1/1000 写触发·免调度器/命令), 保留 90 天。
            if (random_int(1, 1000) === 1) {
                DB::table('nezha_notification_log')->where('created_at', '<', now()->subDays(90))->delete();
            }
        } catch (\Throwable $e) {
            // 日志表本身故障绝不影响真实通知
            Log::info('NezhaNotifyLog record skipped: ' . $e->getMessage());
        }
    }
}
