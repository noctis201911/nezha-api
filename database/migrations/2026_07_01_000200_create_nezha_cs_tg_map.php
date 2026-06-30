<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒 阶段D: Telegram 消息 ↔ 平台会话 映射。推消息到超管/商家 TG 时记一行，回复(reply_to)按此找回会话。
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('nezha_cs_tg_map')) {
            Schema::create('nezha_cs_tg_map', function (Blueprint $table) {
                $table->id();
                $table->string('tg_chat_id', 32);
                $table->string('tg_message_id', 32);
                $table->unsignedBigInteger('conversation_id');
                $table->string('scope', 16)->default('admin'); // admin=客服 / vendor=商家
                $table->timestamp('created_at')->nullable();
                $table->index(['tg_chat_id', 'tg_message_id']);
                $table->index('conversation_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_cs_tg_map');
    }
};
