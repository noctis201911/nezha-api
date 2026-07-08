<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 商家助手会话消息到期清扫（含偶发非顾客 PII）。
 * 默认保留 180 天（business_settings.nezha_assistant_retention_days 可调，业主 0708 定）。
 * 整行删除（对齐聊天日志语义；动作执行的权威审计另在 Log::info(nezha_store_status_toggle) 等处，不依赖本表）。
 * 分批删避免大表长锁。
 */
class NezhaPurgeAssistantMessages extends Command
{
    protected $signature = 'nezha:purge-assistant-messages {--dry-run}';
    protected $description = '删除超过保留期的商家助手会话消息（含偶发非顾客 PII）';

    public function handle()
    {
        $days = (int) (DB::table('business_settings')->where('key', 'nezha_assistant_retention_days')->value('value') ?? 180);
        $days = $days > 0 ? $days : 180;
        $cutoff = now()->subDays($days);

        $total = DB::table('nezha_assistant_messages')->where('created_at', '<', $cutoff)->count();
        if ($this->option('dry-run')) {
            $this->info("[dry-run] 将删除 {$total} 条超过 {$days} 天的助手会话消息");
            return 0;
        }

        $deleted = 0;
        do {
            $n = DB::table('nezha_assistant_messages')->where('created_at', '<', $cutoff)->limit(2000)->delete();
            $deleted += $n;
        } while ($n > 0);

        $this->info("已删除 {$deleted} 条超过 {$days} 天的助手会话消息");
        return 0;
    }
}
