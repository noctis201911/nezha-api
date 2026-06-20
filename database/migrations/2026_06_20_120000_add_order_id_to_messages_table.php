<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒: 顾客聊天「一键发送订单卡片」——消息可引用一笔订单 (nullable, 不影响历史消息)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'order_id')) {
                $table->unsignedBigInteger('order_id')->nullable()->after('message')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'order_id')) {
                $table->dropColumn('order_id');
            }
        });
    }
};
