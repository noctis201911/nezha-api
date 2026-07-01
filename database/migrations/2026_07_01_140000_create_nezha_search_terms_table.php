<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 方案C 全量搜索埋点: 记录每一次搜索(含搜到的), 聚合去重(不存 user_id, 天然匿名)。
        if (!Schema::hasTable('nezha_search_terms')) {
            Schema::create('nezha_search_terms', function (Blueprint $t) {
                $t->id();
                $t->string('keyword', 80);
                $t->string('search_type', 20)->default('product'); // product / restaurant
                $t->unsignedInteger('zone_id')->default(0);
                $t->unsignedInteger('hit_count')->default(1);         // 该词被搜总次数
                $t->unsignedInteger('zero_result_count')->default(0); // 其中"搜了没结果"的次数
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();
                $t->unique(['keyword', 'search_type', 'zone_id'], 'nst_kw_type_zone_uq'); // 原子 upsert + 去重防刷
                $t->index(['search_type', 'zone_id']);
                $t->index('last_seen_at');
                $t->index('hit_count');
            });
        }

        // 全量搜索埋点总开关(默认开; 将来流量大可关掉、只留 miss 埋点)
        if (!\App\Models\BusinessSetting::where('key', 'nezha_search_log_status')->exists()) {
            \App\CentralLogics\Helpers::insert_business_settings_key('nezha_search_log_status', 1);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_search_terms');
    }
};
