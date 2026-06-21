<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒 AI 客服 顾客服务评价：定期看负反馈、整理问题。
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nezha_cs_feedback')) {
            return;
        }
        Schema::create('nezha_cs_feedback', function (Blueprint $table) {
            $table->id();
            $table->string('sentiment', 16)->index();  // positive / negative
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('comment', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_cs_feedback');
    }
};
