<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒 平台集运申报(阶段 A · A-1) — 商家 dashboard 集运登记提示卡的 per-vendor 关闭状态。
// 用独立小表而非在 surveys 加列: "未提交问卷"的商家点"暂不需要"时没有 survey 行可存,
// 且不应为记录 dismiss 而造 survey 桩行(会污染管理端提交计数/未填名单口径)。
// 平台不碰钱, 与 L1 红线无关。
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nezha_consolidation_promos')) {
            return;
        }
        Schema::create('nezha_consolidation_promos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->unique(); // 一 vendor 一行
            $table->timestamp('dismissed_at')->nullable();     // 最近一次关闭提示卡的时刻
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_consolidation_promos');
    }
};
