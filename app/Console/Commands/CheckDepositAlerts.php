<?php

namespace App\Console\Commands;

use App\Mail\DepositLowBalanceMail;
use App\Models\Restaurant;
use App\Models\RestaurantWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * 哪吒 B方案 组4 — 每日检查商家预存佣金, 低于商家自设阈值(或为负)则发提醒邮件.
 * 冷却: 同一商家 24h 内最多一封; 余额回升至阈值以上则清冷却, 下次再低可再次提醒.
 * 用法: php artisan nezha:check-deposit-alerts [--dry-run]
 */
class CheckDepositAlerts extends Command
{
    protected $signature = 'nezha:check-deposit-alerts {--dry-run : 只预览不发送/不写库}';
    protected $description = '检查商家预存佣金低额并发送提醒邮件(商家自设阈值+邮箱)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $restaurants = Restaurant::where('deposit_alert_enabled', 1)
            ->whereNotNull('deposit_alert_threshold')
            ->whereNotNull('deposit_alert_email')
            ->get();

        $checked = 0; $sent = 0; $skipped = 0; $cleared = 0;

        foreach ($restaurants as $r) {
            $checked++;
            $balance = (float) (RestaurantWallet::where('vendor_id', $r->vendor_id)->value('deposit_balance') ?? 0);
            $threshold = (float) $r->deposit_alert_threshold;
            $shouldAlert = $balance < $threshold;

            if (!$shouldAlert) {
                // 余额已回到阈值以上: 清冷却, 以便下次跌破再提醒
                if ($r->deposit_alert_last_sent_at !== null) {
                    if (!$dry) { $r->deposit_alert_last_sent_at = null; $r->save(); }
                    $cleared++;
                }
                continue;
            }

            // 冷却: 24h 内已发过则跳过
            if ($r->deposit_alert_last_sent_at && \Carbon\Carbon::parse($r->deposit_alert_last_sent_at)->gt(now()->subDay())) {
                $skipped++;
                $this->line("  跳过(冷却中) {$r->name} 余额=".round($balance,2)." 阈值={$threshold}");
                continue;
            }

            $this->line(($dry?'[dry] ':'').'告警 '.$r->name.' 余额='.round($balance,2).' 阈值='.$threshold.' → '.$r->deposit_alert_email);
            if (!$dry) {
                try {
                    Mail::to($r->deposit_alert_email)->send(new DepositLowBalanceMail($r->name, $balance, $threshold));
                    $r->deposit_alert_last_sent_at = now();
                    $r->save();
                    $sent++;
                } catch (\Throwable $e) {
                    info('CheckDepositAlerts mail failed for restaurant '.$r->id.': '.$e->getMessage());
                    $this->error('  发信失败: '.$e->getMessage());
                }
            } else {
                $sent++;
            }
        }

        $this->info(($dry?'[dry-run] ':'')."完成: 检查{$checked} 发送{$sent} 跳过(冷却){$skipped} 清冷却{$cleared}");
        return self::SUCCESS;
    }
}
