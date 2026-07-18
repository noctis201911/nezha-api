<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 · 新单「反复提醒商家接单」商家级设置(方案 A 网页 + B 手机 TG 共读一份)。
 * 全部默认 dormant: 总开关默认关(保持现状「响一次」), 商家自行开启并勾选覆盖类别。
 * hasColumn 幂等守卫(仿 2026_06_21_add_timeout_notify_email_to_restaurants)。L3 · 不碰 L1。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (! Schema::hasColumn('restaurants', 'new_order_repeat_enabled')) {
                $table->boolean('new_order_repeat_enabled')->default(0)->after('timeout_notify_telegram'); // 反复提醒总开关(默关)
            }
            if (! Schema::hasColumn('restaurants', 'new_order_repeat_interval_sec')) {
                $table->unsignedSmallInteger('new_order_repeat_interval_sec')->default(20)->after('new_order_repeat_enabled'); // 间隔秒(网页10-120; 手机取max(设定,60))
            }
            if (! Schema::hasColumn('restaurants', 'new_order_repeat_max_minutes')) {
                $table->unsignedSmallInteger('new_order_repeat_max_minutes')->default(5)->after('new_order_repeat_interval_sec'); // 最长反复分钟(防死循环)
            }
            if (! Schema::hasColumn('restaurants', 'new_order_repeat_scope_accept')) {
                $table->boolean('new_order_repeat_scope_accept')->default(1)->after('new_order_repeat_max_minutes'); // 覆盖「待接单」(pending+confirmed) 默勾
            }
            if (! Schema::hasColumn('restaurants', 'new_order_repeat_scope_payment')) {
                $table->boolean('new_order_repeat_scope_payment')->default(0)->after('new_order_repeat_scope_accept'); // 覆盖「待收款」(离线待核凭证) 默不勾
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            foreach ([
                'new_order_repeat_scope_payment',
                'new_order_repeat_scope_accept',
                'new_order_repeat_max_minutes',
                'new_order_repeat_interval_sec',
                'new_order_repeat_enabled',
            ] as $col) {
                if (Schema::hasColumn('restaurants', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
