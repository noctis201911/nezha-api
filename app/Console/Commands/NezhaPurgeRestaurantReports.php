<?php

namespace App\Console\Commands;

use App\Models\RestaurantReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * L1-7: 顾客举报商家记录的 description(自由文本, 可能含 PII)到期清除。
 * 默认保留 180 天(business_settings.nezha_restaurant_report_retention_days 可调),
 * 超期置空 description, 保留举报行 / reason / status 供审计
 * (对齐 local_life_reports.detail 的到期处置)。
 */
class NezhaPurgeRestaurantReports extends Command
{
    protected $signature = 'nezha:purge-restaurant-reports {--dry-run}';
    protected $description = '清除超过保留期的顾客举报商家记录中的 description(PII), 保留审计行';

    public function handle()
    {
        $days = (int) (DB::table('business_settings')->where('key', 'nezha_restaurant_report_retention_days')->value('value') ?? 180);
        $days = $days > 0 ? $days : 180;
        $cutoff = now()->subDays($days);

        $query = RestaurantReport::where('created_at', '<', $cutoff)
            ->whereNotNull('description')
            ->where('description', '!=', '');

        $count = $query->count();
        if ($this->option('dry-run')) {
            $this->info("[dry-run] 将清空 {$count} 条超过 {$days} 天的举报 description(PII)，保留审计行");
            return 0;
        }
        $affected = $query->update(['description' => null]);
        $this->info("已清空 {$affected} 条超过 {$days} 天的举报 description(PII)，保留审计行");
        return 0;
    }
}
