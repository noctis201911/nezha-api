<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * 本地生活类目「合规等级」三级旗标：
 *   0 = 可上线
 *   1 = 需牌照 / 人工审（如正规移民、签证、按摩、美发——可做信息墙，但每个商家逐个人工核）
 *   2 = 硬禁（坚决不能上线：换汇、加密买卖、医美注射、性服务、赌博、制裁规避等）——不可启用、前端不渲染
 *
 * 回填：现有 is_sensitive=1（移民/签证/按摩）视为「需牌照人工审」→ compliance_level=1。
 * is_sensitive 保留向后兼容（= compliance_level >= 1，由控制器同步维护）。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('local_life_categories', 'compliance_level')) {
            Schema::table('local_life_categories', function (Blueprint $table) {
                $table->unsignedTinyInteger('compliance_level')->default(0)->after('is_sensitive');
            });
        }
        // 回填现有敏感类目为「需牌照人工审」
        DB::table('local_life_categories')->where('is_sensitive', 1)->update(['compliance_level' => 1]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('local_life_categories', 'compliance_level')) {
            Schema::table('local_life_categories', function (Blueprint $table) {
                $table->dropColumn('compliance_level');
            });
        }
    }
};
