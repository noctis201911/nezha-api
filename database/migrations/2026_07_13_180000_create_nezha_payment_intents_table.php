<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 顾客直付商家前的不可变付款快照。
 *
 * 该表独立于 offline_payments：prepared 只表示平台已锁定本单金额/汇率/收款信息，
 * 不表示顾客已经付款或提交付款信息，避免污染既有付款状态机。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nezha_payment_intents')) {
            Schema::create('nezha_payment_intents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->unique();
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->string('status', 24)->default('prepared')->index();
                $table->json('snapshot');
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();

                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
                $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_payment_intents');
    }
};
