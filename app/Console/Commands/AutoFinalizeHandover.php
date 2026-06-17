<?php

namespace App\Console\Commands;

use App\CentralLogics\OrderLogic;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒 B方案 收尾兜底(C) — handover 超过 N 小时无人「确认收货」的配送/自取单自动判为已送达并结算佣金。
 *
 * 背景: 平台不配送(顾客自叫 Yandex/自取), 既无骑手点「已送达」, 商家也不知 Yandex 何时送达。
 * 顾客忘了点「确认收货」(方案A)时, 订单会永远卡 handover → 佣金永不入账 + 顾客端永远「未送达」。
 * 本命令兜底: handover 满 N 小时仍无人确认即自动收尾(与顾客/商家路径共用 OrderLogic::settle_delivered 幂等闸, 恰好结算一次)。
 *
 * 阈值 N: business_settings.nezha_auto_finalize_handover_hours(默认 24)。
 * 开关:   business_settings.nezha_auto_finalize_handover_status(默认 1 开; 设 0 停用本兜底)。
 * 订阅单不在范围(settle_delivered 内部已排除)。每小时由调度触发(bootstrap/app.php)。
 */
class AutoFinalizeHandover extends Command
{
    protected $signature = 'nezha:auto-finalize-handover {--dry-run : 只报告将收尾哪些单, 不实际改动}';

    protected $description = '哪吒: handover 超过 N 小时(默认24h)无人确认收货的配送/自取单自动判为已送达并结算佣金(恰好一次)';

    public function handle()
    {
        $enabled = DB::table('business_settings')->where('key', 'nezha_auto_finalize_handover_status')->value('value');
        if ($enabled !== null && (int) $enabled !== 1) {
            $this->info('自动收尾兜底开关关闭(nezha_auto_finalize_handover_status=0), 跳过。');
            return self::SUCCESS;
        }

        $hours = (int) (DB::table('business_settings')->where('key', 'nezha_auto_finalize_handover_hours')->value('value') ?? 24);
        if ($hours < 1) {
            $hours = 24;
        }
        $cutoff = Carbon::now()->subHours($hours);
        $dry = (bool) $this->option('dry-run');

        $orders = Order::whereNull('delivered')
            ->where('order_status', 'handover')
            ->whereNull('subscription_id')
            ->where('handover', '<', $cutoff)
            ->get();

        $this->info('哪吒自动收尾: 阈值=' . $hours . 'h, 截止=' . $cutoff->toDateTimeString() . ', 命中=' . $orders->count() . ', 模式=' . ($dry ? 'DRY-RUN(只报告)' : '实跑'));

        $done = 0;
        foreach ($orders as $order) {
            if ($dry) {
                $this->line('  [DRY] 将自动收尾 order#' . $order->id . ' (handover@' . $order->handover . ')');
                $done++;
                continue;
            }
            try {
                if (OrderLogic::settle_delivered($order, 'auto', null)) {
                    $done++;
                    $this->line('  已收尾 order#' . $order->id);
                }
            } catch (\Throwable $e) {
                Log::error('NEZHA_AUTO_FINALIZE order#' . $order->id . ' 失败: ' . $e->getMessage());
                $this->error('  order#' . $order->id . ' 失败: ' . $e->getMessage());
            }
        }

        $msg = ($dry ? '[DRY-RUN] 将自动收尾 ' : '已自动收尾 ') . $done . ' 单(handover 超 ' . $hours . 'h 无人确认)。';
        $this->info($msg);
        Log::info('NEZHA_AUTO_FINALIZE_HANDOVER: ' . $msg);

        return self::SUCCESS;
    }
}