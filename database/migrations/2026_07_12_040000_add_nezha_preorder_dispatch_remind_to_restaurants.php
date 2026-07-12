<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 预约配送 v2 单点 · 阶段③ B · 叫车提醒改「每商家自选」(业主 2026-07-12)。
 *
 * 每商家一格「预约单叫车提醒」开关(默认 1 开·同 timeout_notify_telegram/email 模式)。商家在「通知设置」页各管各的:
 *   A 关不影响 B(原平台级全局 param nezha_preorder_dispatch_remind_push 降为「平台 killswitch」·关掉=所有人停推)。
 * 叫车推送三门: 总闸 nezha_preorder_status + 平台 killswitch + 本店此列。
 *
 * 🔴 L2/L3 展示层: 与钱无关的通知偏好。additive · nullable-safe · 默认 1(缺列时消费方 `?? 1` 回落开)· dormant。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('restaurants') && !Schema::hasColumn('restaurants', 'nezha_preorder_dispatch_remind')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->boolean('nezha_preorder_dispatch_remind')->default(1)->comment('哪吒: 本店预约单叫车提醒推送(1开·商家自选·07稿·阶段③)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('restaurants') && Schema::hasColumn('restaurants', 'nezha_preorder_dispatch_remind')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropColumn('nezha_preorder_dispatch_remind');
            });
        }
    }
};
