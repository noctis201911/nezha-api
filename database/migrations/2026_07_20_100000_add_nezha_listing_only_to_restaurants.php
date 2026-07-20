<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 · 外卖 TG 化 Phase 1「预建店挂牌试点」逐店开关 + 顾客侧联系方式。
 *
 * nezha_listing_only=1「挂牌态」: 店铺只展示不接单 —— 菜单可浏览、可经 TG 联系商家,
 *   但平台侧下单入口整体不渲染, 且后端 place_order 直接拒单(前端藏 + 后端拒 双闸)。
 *   ⚠️ 与 nezha_order_suspended 语义正交, 禁复用: suspended = 被拦下不可点(异常态);
 *      挂牌 = 可浏览可联系、只是不走平台单(正常态)。
 *   Phase 1 挂牌店保持 status=0 → 天然不进任何列表/搜索/附近/推荐, 仅直链可达。
 *
 * nezha_contacts: 顾客侧公开联系方式, 与 local_life_merchants.contacts **同构**
 *   ([{method,value,label?}], method ∈ wechat|phone|whatsapp|telegram), 前端复用同一 consumer。
 *
 * 默认 0/NULL = 全功能店, 对现网行为 diff=0。hasColumn 幂等守卫。L3 · 不碰 L1。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (! Schema::hasColumn('restaurants', 'nezha_listing_only')) {
                $table->boolean('nezha_listing_only')->default(0)->comment('哪吒挂牌态: 1=只展示不接单(TG联系下单)');
            }
            if (! Schema::hasColumn('restaurants', 'nezha_contacts')) {
                $table->json('nezha_contacts')->nullable()->comment('顾客侧公开联系方式 [{method,value,label}]');
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            foreach (['nezha_contacts', 'nezha_listing_only'] as $col) {
                if (Schema::hasColumn('restaurants', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
