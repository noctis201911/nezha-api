<?php

namespace App\Console\Commands;

use App\Models\UserNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 数据最小化(业主 0708): 顾客个人系统通知(UserNotification·订单状态推送)到期删除。
 * 默认保留 30 天(business_settings.nezha_notification_retention_days 可调),超期整行删。
 * 依据: 这是纯展示态通知,无审计价值(真正的记录是订单/退款本身);顾客端只显最近 5 天、
 * 后端查询 15 天窗,30 天保留留足缓冲。治通知表随下单量无限膨胀
 * (其它表 restaurant_reports/assistant_messages/yandex-links 均有 purge, 唯此表原缺)。
 * 平台公告 Notification(广播·数量少)不在此清理范围。
 */
class NezhaPurgeNotifications extends Command
{
    protected $signature = 'nezha:purge-notifications {--dry-run}';
    protected $description = '删除超过保留期的顾客个人系统通知(UserNotification), 治通知表膨胀';

    public function handle()
    {
        $days = (int) (DB::table('business_settings')->where('key', 'nezha_notification_retention_days')->value('value') ?? 30);
        $days = $days > 0 ? $days : 30;
        $cutoff = now()->subDays($days);

        $count = UserNotification::where('created_at', '<', $cutoff)->count();
        if ($this->option('dry-run')) {
            $this->info("[dry-run] 将删除 {$count} 条超过 {$days} 天的个人系统通知(UserNotification)");
            return 0;
        }

        // 分批删, 避免通知量大时单事务长锁表
        $deleted = 0;
        do {
            $batch = UserNotification::where('created_at', '<', $cutoff)->limit(2000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("已删除 {$deleted} 条超过 {$days} 天的个人系统通知(UserNotification), 保留最近 {$days} 天");
        return 0;
    }
}
