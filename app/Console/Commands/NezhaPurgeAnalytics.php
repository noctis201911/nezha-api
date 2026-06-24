<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 使用埋点维护 (方案C) — 每日:
 *  1) 回填 converted: 登录用户加购后, 若从同一餐厅下过单(下单时间 >= 加购时间)→ 标已转化。
 *  2) 保留期清理(L1-7): 加购事件含 user_id(PII), 超 days 天(默认30)删除; 搜索词聚合无 PII, 超 180 天的冷词清理。
 * 纯维护, 失败不影响主流程。用法: php artisan nezha:purge-analytics [--dry-run] [--days=30]
 */
class NezhaPurgeAnalytics extends Command
{
    protected $signature = 'nezha:purge-analytics {--dry-run : 只预览不写库} {--days=30 : 加购事件保留天数(默认30)}';
    protected $description = '回填加购转化 + 按保留期清理使用埋点(加购30天/搜索词180天)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $days = max(7, (int) $this->option('days'));
        $cutoff = Carbon::now()->subDays($days);

        $marked = 0; $delCart = 0; $delSearch = 0;

        // 1) 回填转化(登录用户 + 同餐厅 + 下单时间不早于加购)
        if (Schema::hasTable('nezha_cart_events') && Schema::hasTable('orders')) {
            try {
                if ($dry) {
                    $marked = (int) DB::table('nezha_cart_events as ce')
                        ->where('ce.converted', 0)->where('ce.is_guest', 0)->whereNotNull('ce.user_id')
                        ->whereExists(function ($q) {
                            $q->select(DB::raw(1))->from('orders as o')
                              ->whereColumn('o.user_id', 'ce.user_id')
                              ->whereColumn('o.restaurant_id', 'ce.restaurant_id')
                              ->whereColumn('o.created_at', '>=', 'ce.created_at');
                        })->count();
                } else {
                    $marked = DB::update(
                        'UPDATE nezha_cart_events ce SET ce.converted = 1, ce.updated_at = NOW() '
                        . 'WHERE ce.converted = 0 AND ce.is_guest = 0 AND ce.user_id IS NOT NULL '
                        . 'AND EXISTS (SELECT 1 FROM orders o WHERE o.user_id = ce.user_id '
                        . 'AND o.restaurant_id = ce.restaurant_id AND o.created_at >= ce.created_at)'
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('NEZHA_PURGE_ANALYTICS: 回填转化失败 ' . $e->getMessage());
            }
        }

        // 2) 保留期清理
        if (Schema::hasTable('nezha_cart_events')) {
            $q = DB::table('nezha_cart_events')->where('created_at', '<', $cutoff);
            $delCart = $dry ? (clone $q)->count() : $q->delete();
        }
        if (Schema::hasTable('nezha_search_misses')) {
            $sCut = Carbon::now()->subDays(180);
            $q = DB::table('nezha_search_misses')->where('last_seen_at', '<', $sCut);
            $delSearch = $dry ? (clone $q)->count() : $q->delete();
        }

        $msg = ($dry ? '[DRY] ' : '') . "回填转化 {$marked} 条; 清理加购事件(>{$days}天) {$delCart} 条; 清理冷搜索词(>180天) {$delSearch} 条。";
        $this->info($msg);
        if (!$dry) {
            Log::info('NEZHA_PURGE_ANALYTICS: ' . $msg);
        }
        return self::SUCCESS;
    }
}
