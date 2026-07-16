<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒 平台集运 — 每店「集运资格」标记(运营手动逐家开·业主 2026-07-16 定)。
// 集运仅面向经营达标的深度合作商家(v1 问卷页现行文案已声明「定向开放」)，故报名入口/报名动作/开期通知按本列收口。
// 默认 0 = 未开通(全关不误放)。提示卡与 v1 问卷不受本列限制(需求摸底面向全体·业主裁决)。
// 平台不碰钱, 与 L1 红线无关。MySQL 5.7 兼容。
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('restaurants', 'nezha_consolidation_eligible')) {
            return;
        }
        Schema::table('restaurants', function (Blueprint $table) {
            $table->boolean('nezha_consolidation_eligible')->default(0)->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('restaurants', 'nezha_consolidation_eligible')) {
            return;
        }
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('nezha_consolidation_eligible');
        });
    }
};
