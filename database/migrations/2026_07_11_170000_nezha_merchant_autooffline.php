<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 B方案 — 商家「长期不确认订单 → 自动暂停接单(auto-offline)」(L2·严守 L1 不碰钱).
 *
 * 保护顾客不被继续喂给失联/不响应的商家: 滚动窗口内商家责任超时取消达阈值、且期间无成功接单(行为=不在场)
 *   → 自动置「接单挂起」标记停止接新单; 商家自助一键恢复 / 运营后台恢复(🔴 无冷却自动恢复·业主 2026-07-11 拍板)。
 *
 * 🔴 L1: 零资金。只置/读 restaurants 的「与钱无关」接单挂起标记; 不取消存量单/不碰保证金/不代退/不打钱。
 * 与退款逾期挂起(nezha_order_suspended)互相独立: 各用各的列, 接单闸 OR 两信号, 恢复各管各的(不误伤对方来源)。
 *
 * 建三样, 全部可逆(down):
 *  1) restaurants 加 3 列: 自动下线标记(与钱无关)。
 *  2) nezha_auto_offline_events: 审计留痕(auto_offline/self_recover/ops_recover/escalate_warn)。
 *  3) business_settings 种入总闸(默认关)+ 阈值/窗口(后台可调)。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('restaurants')) {
            Schema::table('restaurants', function (Blueprint $table) {
                if (!Schema::hasColumn('restaurants', 'nezha_auto_offline')) {
                    $table->boolean('nezha_auto_offline')->default(0)->comment('哪吒: 因长期不确认订单被自动停接单(与钱无关, 1=停接单)');
                }
                if (!Schema::hasColumn('restaurants', 'nezha_auto_offline_reason')) {
                    $table->string('nezha_auto_offline_reason')->nullable()->comment('自动下线原因(审计可读)');
                }
                if (!Schema::hasColumn('restaurants', 'nezha_auto_offline_at')) {
                    $table->timestamp('nezha_auto_offline_at')->nullable()->comment('自动下线时间');
                }
            });
        }

        if (!Schema::hasTable('nezha_auto_offline_events')) {
            Schema::create('nezha_auto_offline_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->string('action', 32)->comment('auto_offline/self_recover/ops_recover/escalate_warn');
                $table->string('detail')->nullable();
                $table->timestamp('fired_at')->nullable();
                $table->timestamps();
            });
        }

        // business_settings 种入阈值/开关。已存在则不覆盖(保护后台改过的值)。
        $defaults = [
            'nezha_autooffline_status'       => '0', // 总闸: 真实影响(暂停商家经营), 默认关 dormant。
            'nezha_autooffline_strike_count' => '3', // 触发阈值 N: 窗口内商家责任超时取消单数。
            'nezha_autooffline_window_hours' => '2', // 滚动窗口 H(小时)。
        ];
        foreach ($defaults as $key => $value) {
            if (!DB::table('business_settings')->where('key', $key)->exists()) {
                DB::table('business_settings')->insert([
                    'key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_auto_offline_events');
        if (Schema::hasTable('restaurants')) {
            Schema::table('restaurants', function (Blueprint $table) {
                foreach (['nezha_auto_offline', 'nezha_auto_offline_reason', 'nezha_auto_offline_at'] as $col) {
                    if (Schema::hasColumn('restaurants', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
        DB::table('business_settings')->whereIn('key', [
            'nezha_autooffline_status', 'nezha_autooffline_strike_count', 'nezha_autooffline_window_hours',
        ])->delete();
    }
};
