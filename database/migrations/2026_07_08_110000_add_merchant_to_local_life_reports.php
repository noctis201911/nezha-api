<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 本地生活举报表加「商家」维度（additive · 可回滚）。
 * 批3 A6：顾客可举报商家（复用 posts 举报理由白名单+队列+防刷），一条举报指向帖 XOR 商家。
 *   - merchant_id : 被举报商家(local_life_merchants.id)，帖举报时为 null
 *   - post_id     : 改为 nullable（商家举报时无 post_id）
 * L1-1/L1-7 不变：仅举报记录，detail 可能含 PII → 表已 ENCRYPTION='Y'（沿用建表加密）。
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('local_life_reports') && !Schema::hasColumn('local_life_reports', 'merchant_id')) {
            Schema::table('local_life_reports', function (Blueprint $table) {
                $table->unsignedBigInteger('merchant_id')->nullable()->index()->after('post_id');
            });
        }
        // post_id 改 nullable（商家举报无 post_id）；raw SQL 避免 doctrine/dbal 依赖
        try {
            DB::statement("ALTER TABLE `local_life_reports` MODIFY `post_id` BIGINT UNSIGNED NULL");
        } catch (\Throwable $e) {
            // 已是 nullable 或权限异常时静默，不阻断
        }
    }

    public function down()
    {
        if (Schema::hasColumn('local_life_reports', 'merchant_id')) {
            Schema::table('local_life_reports', function (Blueprint $table) {
                $table->dropColumn('merchant_id');
            });
        }
        // 不强制回退 post_id 为 NOT NULL（回退需先清 null 行，避免破坏）
    }
};
