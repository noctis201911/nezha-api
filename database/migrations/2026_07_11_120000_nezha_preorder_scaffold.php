<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 预约下单/集中配送 — Phase 1 additive 地基（dormant · 零行为 · 可回滚）。
 * 只建结构，不插数据、不改既有列语义；总闸 nezha_preorder_status 缺省即关（代码 ?? 0 读默认），
 * 故本迁移上线后无任何 live 行为变化。正本 fable-brief/PLAN_preorder_scheduled_delivery.md。
 */
return new class extends Migration
{
    public function up(): void
    {
        // ① 商家配送时段（结构参照 restaurant_schedule；day 0-6 与 now()->dayOfWeek / schedule_at->format('w') 对齐，0=周日）
        if (!Schema::hasTable('nezha_delivery_windows')) {
            Schema::create('nezha_delivery_windows', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('restaurant_id');
                $table->tinyInteger('day');                 // 0-6，0=周日
                $table->time('start_time');
                $table->time('end_time');
                $table->integer('capacity')->nullable();     // null=不限；Phase 2 才启用业务容量
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index(['restaurant_id', 'day', 'active'], 'nezha_delivery_windows_lookup');
            });
        }

        // ② 订单挂窗口引用（作业台按窗口分组稳定键；schedule_at 沿用不变）
        if (Schema::hasTable('orders') && !Schema::hasColumn('orders', 'nezha_delivery_window_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('nezha_delivery_window_id')->nullable()->after('schedule_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'nezha_delivery_window_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('nezha_delivery_window_id');
            });
        }
        Schema::dropIfExists('nezha_delivery_windows');
    }
};
