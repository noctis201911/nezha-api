<?php

namespace App\Console\Commands;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaNewOrderNag;
use App\CentralLogics\NezhaNotifyLog;
use App\CentralLogics\NezhaOrderTimeout;
use App\Models\Restaurant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒 — 新单「反复提醒商家接单」(纯通知 · 绝不碰钱/状态/L1)。
 *
 * 与既有两条命令**完全分离、互不影响**:
 *   - OrderTimeoutSweep(L1 敏感): 一次性 remind + 超时自动取消/退款留痕。本命令不碰。
 *   - NezhaVendorAlarmSweep(dormant): FCM 推「商家版 App」的 outbox 兜底网(不同通道)。
 * 本命令 = 只按商家设定间隔, 对**未接单**重复发 Telegram, 到接单/查看/取消/超时/超上限即停。
 *
 * 待催集合口径 = vendor 看板 toast 三桶(checked=0), 见 App\CentralLogics\NezhaNewOrderNag。
 * 在场感知(自然收敛): 商家一「查看」(checked→1) 或一「接单/取消」(离开待办态) → 下轮 sweep 查不到 → 停。
 *
 * 全局杀闸 nezha_new_order_nag_status(默 0 → 整条 return, dormant 零真实影响)。
 * 手机端催单间隔固定 60s 常量(v3.3 业主 0718 定; 受调度每分钟节拍 + Telegram 限流所限, 做不到网页 A 线的自定义间隔);
 * 最长反复时长读商家 max 列(clamp 1-5 分钟), 与网页 A 线同守。interval 列仅供网页 A 线自定义, 本命令不读。
 */
class NezhaNewOrderNagSweep extends Command
{
    protected $signature = 'nezha:new-order-nag-sweep {--dry-run : 只报告不发送}';

    protected $description = '哪吒: 对未接单按商家设定间隔反复 TG 催单, 到接单/查看/超上限即停(纯通知)';

    /** 类别 → 停催文案 + 阶段时钟。 */
    private const CATEGORIES = [
        'accept'  => ['phase' => NezhaOrderTimeout::PHASE_ACCEPT, 'action' => '待接单，请尽快登录商家后台接单'],
        'payment' => ['phase' => NezhaOrderTimeout::PHASE_PROOF,  'action' => '待确认收款，请尽快登录后台核对付款凭证'],
    ];

    /** 手机 Telegram 催单固定间隔(秒)。网页 A 线可 10-120 自定义, 手机受调度节拍 + TG 限流所限固定 60s(业主 0718 定)。 */
    private const MOBILE_INTERVAL_SEC = 60;

    public function handle()
    {
        $dry = (bool) $this->option('dry-run');

        // 总开关关 => 不跑(dormant, 上线前零真实影响)。
        if ((int) Helpers::get_business_settings('nezha_new_order_nag_status') !== 1) {
            $this->info('总开关关(nezha_new_order_nag_status=0), 跳过。');

            return self::SUCCESS;
        }

        $now  = Carbon::now();
        $sent = 0;

        // 只取「开了反复 + 绑了 TG chat + 没关 TG 软提醒」的店, 缩小扫描面。
        $restaurants = Restaurant::where('new_order_repeat_enabled', 1)
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', '!=', '')
            ->where(function ($q) {
                $q->whereNull('timeout_notify_telegram')->orWhere('timeout_notify_telegram', 1);
            })
            ->get();

        foreach ($restaurants as $r) {
            $maxSec   = max(1, min(5, (int) ($r->new_order_repeat_max_minutes ?? 5))) * 60; // clamp 1-5 分钟, 与网页 A 线同守
            $interval = self::MOBILE_INTERVAL_SEC; // 手机端固定 60s 常量(不读 interval 列; 该列仅供网页 A 线自定义间隔)

            $buckets = NezhaNewOrderNag::bucketsForRestaurant(
                $r->id,
                (int) ($r->new_order_repeat_scope_accept ?? 1) === 1,
                (int) ($r->new_order_repeat_scope_payment ?? 0) === 1
            );

            foreach (self::CATEGORIES as $cat => $meta) {
                foreach ($buckets[$cat] as $order) {
                    // 阶段时钟: clockStart 内部 Carbon::parse, 返回 Carbon 或 null(绝不吐 created_at 字符串, 避 Order::getCreatedAtAttribute 坑)。
                    $start  = NezhaOrderTimeout::clockStart($order, $meta['phase']);
                    $key    = 'nezha_new_order_nag_' . $order->id;
                    $lastAt = Cache::get($key);

                    // 判定(窗口未启/超上限/未到间隔)全走 NezhaNewOrderNag::shouldNagNow — 与单测单一真相源。
                    if (! NezhaNewOrderNag::shouldNagNow($start, $lastAt !== null ? (int) $lastAt : null, $interval, $maxSec, $now)) {
                        continue;
                    }

                    $text = "🔔 哪吒｜您有新订单 #{$order->id} {$meta['action']}。";
                    if ($dry) {
                        $this->line("  [DRY] order#{$order->id} nag ({$cat})");
                        $sent++;
                        continue;
                    }

                    $ok = false;
                    try {
                        $ok = (bool) Helpers::sendTelegramToRestaurant($r, $text);
                    } catch (\Throwable $e) {
                        Log::info('NZ_NAG order#' . $order->id . ': ' . $e->getMessage());
                    }

                    // 成功/失败都记时间戳, 免瞬时重试刷屏(与「发送失败重试 tries=3」是两码事)。
                    Cache::put($key, $now->timestamp, now()->addMinutes(30));
                    NezhaNotifyLog::record('telegram', 'merchant', 'new_order_nag', $ok ? 'ok' : 'failed', $order->id, $r->id);
                    if ($ok) {
                        $sent++;
                    }
                }
            }
        }

        $this->info(($dry ? '[DRY] ' : '') . "新单反复催单: 本轮发送 {$sent} 条。");

        return self::SUCCESS;
    }
}
