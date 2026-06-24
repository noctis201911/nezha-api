<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nezha_feedback_digests', function (Blueprint $table) {
            $table->id();
            $table->date('digest_date')->index();           // 被总结的那一天(窗口起始日)
            $table->unsignedSmallInteger('window_days')->default(1);
            $table->mediumText('summary')->nullable();       // AI 生成的摘要正文(仅产出, 不含原始PII)
            $table->json('top_issues')->nullable();          // 预留: 结构化TOP问题
            $table->json('counts')->nullable();              // 确定性统计(评价/差评/退款/工单等真实数字)
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('tokens')->default(0);
            $table->boolean('degraded')->default(false);     // true=AI未启用/失败,仅统计数字
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_feedback_digests');
    }
};
