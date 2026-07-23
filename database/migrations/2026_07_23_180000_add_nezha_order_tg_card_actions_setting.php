<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 外卖 TG P2.2a 动作闸：默认关闭，发布迁移不会改变 P2.1 只读卡片行为。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('business_settings')->where('key', 'nezha_order_tg_card_actions_status')->exists()) {
            DB::table('business_settings')->insert([
                'key' => 'nezha_order_tg_card_actions_status',
                'value' => '0',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('business_settings')
            ->where('key', 'nezha_order_tg_card_actions_status')
            ->delete();
    }
};
