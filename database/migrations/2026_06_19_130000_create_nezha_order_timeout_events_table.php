<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 — 订单超时动作幂等账本 + 超时阈值 business_settings 默认值。
 * 规则见 docs/ORDER_TIMEOUT_RULES.md。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('nezha_order_timeout_events')) {
            Schema::create('nezha_order_timeout_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('order_id')->index();
                $t->string('action', 64);          // email_merchant / cancel_unpaid / cancel_paid_refund / prep_escalate
                $t->timestamp('fired_at')->nullable();
                $t->text('detail')->nullable();
                $t->timestamps();
                // 幂等核心: 每单每动作至多一行
                $t->unique(['order_id', 'action'], 'nezha_oto_order_action_uq');
            });
        }

        $defaults = [
            'nezha_timeout_status'             => '1',
            'nezha_timeout_remind_min'         => '5',
            'nezha_timeout_email_merchant_min' => '10',
            'nezha_timeout_unpaid_cancel_min'  => '10',
            'nezha_timeout_cancel_min'         => '20',
            'nezha_timeout_prep_orange_min'    => '5',
            'nezha_timeout_prep_red_min'       => '15',
        ];
        foreach ($defaults as $k => $v) {
            if (!DB::table('business_settings')->where('key', $k)->exists()) {
                DB::table('business_settings')->insert([
                    'key'        => $k,
                    'value'      => $v,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_order_timeout_events');
        DB::table('business_settings')->whereIn('key', [
            'nezha_timeout_status',
            'nezha_timeout_remind_min',
            'nezha_timeout_email_merchant_min',
            'nezha_timeout_unpaid_cancel_min',
            'nezha_timeout_cancel_min',
            'nezha_timeout_prep_orange_min',
            'nezha_timeout_prep_red_min',
        ])->delete();
    }
};
