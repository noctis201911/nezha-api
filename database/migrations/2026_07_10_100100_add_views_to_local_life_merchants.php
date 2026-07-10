<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商户浏览数（HANDOFF_rental_structured_post §2b）。
 * 计数机制与 local_life_posts.views 完全同套（show +1 · IP 6h 去重 · 从 1 显示）。
 * 只加列不动存量；回滚零成本。
 * 注：商户「房型卡」的图片/attrs 存在既有 services JSON 列内（每项可选 image + attrs），无需新列。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_life_merchants', function (Blueprint $table) {
            if (!Schema::hasColumn('local_life_merchants', 'views')) {
                $table->unsignedInteger('views')->default(0)->after('sort_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('local_life_merchants', function (Blueprint $table) {
            if (Schema::hasColumn('local_life_merchants', 'views')) {
                $table->dropColumn('views');
            }
        });
    }
};
