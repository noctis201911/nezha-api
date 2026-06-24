<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1) nezha_search_misses: 先去重(每组保留最小 id), 再加复合唯一索引 —— 支撑原子 upsert(ON DUPLICATE KEY)
        //    去重防刷 + 消竞态 + 覆盖 keyword 查找。
        if (Schema::hasTable('nezha_search_misses')) {
            DB::statement(
                'DELETE t1 FROM nezha_search_misses t1 INNER JOIN nezha_search_misses t2 '
                . 'ON t1.keyword = t2.keyword AND t1.search_type = t2.search_type AND t1.zone_id = t2.zone_id AND t1.id > t2.id'
            );
            $has = collect(DB::select("SHOW INDEX FROM nezha_search_misses WHERE Key_name = 'nsm_kw_type_zone_uq'"))->isNotEmpty();
            if (!$has) {
                Schema::table('nezha_search_misses', function (Blueprint $t) {
                    $t->unique(['keyword', 'search_type', 'zone_id'], 'nsm_kw_type_zone_uq');
                });
            }
        }

        // 2) orders: 复合索引加速 nezha:purge-analytics 回填转化的 EXISTS 子查询(user_id+restaurant_id+created_at)
        if (Schema::hasTable('orders')) {
            $has = collect(DB::select("SHOW INDEX FROM orders WHERE Key_name = 'orders_user_rest_created_idx'"))->isNotEmpty();
            if (!$has) {
                Schema::table('orders', function (Blueprint $t) {
                    $t->index(['user_id', 'restaurant_id', 'created_at'], 'orders_user_rest_created_idx');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('nezha_search_misses')) {
            try {
                Schema::table('nezha_search_misses', fn (Blueprint $t) => $t->dropUnique('nsm_kw_type_zone_uq'));
            } catch (\Throwable $e) {
            }
        }
        if (Schema::hasTable('orders')) {
            try {
                Schema::table('orders', fn (Blueprint $t) => $t->dropIndex('orders_user_rest_created_idx'));
            } catch (\Throwable $e) {
            }
        }
    }
};
