<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 商家退出结算 step4-3 补丁: restaurant_offboard_settlements 加 frozen_reversal_owed。
 *
 * 语义: 退出冻结期(offboard_status != active)对已扣佣直付单退款时, 平台「应返还商家」的佣金合计
 *   —— 与 shortfall_amount(net<0 = 商家欠平台, 方向相反)分开记, 避免同字段承载两个相反方向。
 *   待人工核算, 不自动进 net / 不自动回充 deposit(DESIGN §C3「记 shortfall 非回充」)。
 * additive · nullable default 0 · 5.7 INPLACE,LOCK=NONE · 可回滚。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('restaurant_offboard_settlements', 'frozen_reversal_owed')) {
            Schema::table('restaurant_offboard_settlements', function (Blueprint $table) {
                $table->decimal('frozen_reversal_owed', 24, 2)->default(0)->after('shortfall_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('restaurant_offboard_settlements', 'frozen_reversal_owed')) {
            Schema::table('restaurant_offboard_settlements', function (Blueprint $table) {
                $table->dropColumn('frozen_reversal_owed');
            });
        }
    }
};
