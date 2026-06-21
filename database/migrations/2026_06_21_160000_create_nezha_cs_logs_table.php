<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒 AI 客服审计日志：只记分类/动作/模型/tokens，不存任何消息正文（无 PII，规避 L1-7 留存义务）。
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nezha_cs_logs')) {
            return;
        }
        Schema::create('nezha_cs_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('category', 32)->nullable();   // answer / sensitive / handoff / error / relay
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('tokens')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_cs_logs');
    }
};
