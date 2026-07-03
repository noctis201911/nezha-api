<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒[AI 归因 §6.8/§8]: 标记客服(admin)会话每条消息是 AI(小哪) 还是人工(超管转接)。
// 'ai' = NezhaCsAssistant 自动回复/欢迎语; 'human' = postHumanReply(超管 Telegram 转接);
// null = 顾客消息/商家消息/历史消息(不臆断, 前端不显归因 chip)。additive nullable, 生产安全可逆。
return new class extends Migration {
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('cs_source', 12)->nullable()->after('is_seen');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('cs_source');
        });
    }
};
