<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 租房民宿结构化发帖（HANDOFF_rental_structured_post §3.1）。
 * 只加列不动存量：
 *   - attrs JSON NULL：结构化字段（出租形式/户型/押金/设施/租客要求/沟通语言/街道…），enum 存英文 key。
 *     为什么 JSON 不逐列：字段集会演化（二手车/民宿细分在后），帖量小无筛选压力；
 *     将来要按户型/区筛选时再抽热字段成索引列（登记远期）。
 *   - views INT UNSIGNED default 0：真实浏览计数（show 端点 +1，redis IP 6h 去重）。
 * 回滚零成本：两列可留空。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_life_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('local_life_posts', 'attrs')) {
                $table->json('attrs')->nullable()->after('area_label');
            }
            if (!Schema::hasColumn('local_life_posts', 'views')) {
                $table->unsignedInteger('views')->default(0)->after('want_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('local_life_posts', function (Blueprint $table) {
            if (Schema::hasColumn('local_life_posts', 'attrs')) {
                $table->dropColumn('attrs');
            }
            if (Schema::hasColumn('local_life_posts', 'views')) {
                $table->dropColumn('views');
            }
        });
    }
};
