<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 忙碌模式 / 定时挂起: restaurants 加 4 列 + 灰度总闸 nezha_busy_mode_status(默认0关)。
 * - nezha_busy_until   忙碌模式(仍接单·"出餐约需X分钟"横幅)到期时刻; null=未忙碌。
 * - nezha_busy_min     忙碌横幅显示的 X(分钟)。
 * - nezha_busy_reason  忙碌原因预设(peak/prep/short), 拼进顾客端模板文案。
 * - nezha_pause_until  定时挂起(暂停接单·到点自动恢复)到期时刻; 配合现有 nezha_temp_closed; null=无限期手动暂停。
 * additive · nullable · 5.7 INPLACE,LOCK=NONE · 可回滚。全程 dormant(开关默认0, 无控制写入这些列)。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurants', 'nezha_busy_until')) {
                $table->dateTime('nezha_busy_until')->nullable()->after('nezha_temp_closed');
            }
            if (!Schema::hasColumn('restaurants', 'nezha_busy_min')) {
                $table->smallInteger('nezha_busy_min')->nullable()->after('nezha_busy_until');
            }
            if (!Schema::hasColumn('restaurants', 'nezha_busy_reason')) {
                $table->string('nezha_busy_reason', 20)->nullable()->after('nezha_busy_min');
            }
            if (!Schema::hasColumn('restaurants', 'nezha_pause_until')) {
                $table->dateTime('nezha_pause_until')->nullable()->after('nezha_busy_reason');
            }
        });

        if (!DB::table('business_settings')->where('key', 'nezha_busy_mode_status')->exists()) {
            $row = ['key' => 'nezha_busy_mode_status', 'value' => '0'];
            if (Schema::hasColumn('business_settings', 'created_at')) {
                $row['created_at'] = now();
                $row['updated_at'] = now();
            }
            DB::table('business_settings')->insert($row);
        }
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            foreach (['nezha_busy_until', 'nezha_busy_min', 'nezha_busy_reason', 'nezha_pause_until'] as $c) {
                if (Schema::hasColumn('restaurants', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        DB::table('business_settings')->where('key', 'nezha_busy_mode_status')->delete();
    }
};
