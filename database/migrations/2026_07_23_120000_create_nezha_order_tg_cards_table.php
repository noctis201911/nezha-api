<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒外卖 TG Phase 2 P2.1：一单一卡片的 Telegram message_id 运行态索引。
 *
 * 只保存商家 chat/message 坐标与订单内部状态，不保存任何顾客 PII。
 * 功能总闸默认 0；迁移落地不会改变现有纯文本新单通知。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nezha_order_tg_cards')) {
            Schema::create('nezha_order_tg_cards', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id')->unique();
                $table->string('chat_id');
                $table->string('message_id');
                $table->string('last_state')->default('new');
                $table->string('last_action_by_tg_uid')->nullable();
                $table->timestamps();
            });
        }

        if (! DB::table('business_settings')->where('key', 'nezha_order_tg_card_status')->exists()) {
            DB::table('business_settings')->insert([
                'key' => 'nezha_order_tg_card_status',
                'value' => '0',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_order_tg_cards');
        DB::table('business_settings')->where('key', 'nezha_order_tg_card_status')->delete();
    }
};
