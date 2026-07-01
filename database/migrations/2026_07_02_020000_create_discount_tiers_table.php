<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒[多级满减] discount_tiers: 店铺满减活动(discounts)下的多档门槛。
// 每档 满 min_purchase 享 discount(amount=减固定额 / percent=减百分比·封顶 max_discount)。
// 下单取"订单额满足的、实得减额最大"的一档, 不叠加。灰度开关 nezha_tiered_discount_status(默认关)。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('discount_id');
            $table->decimal('min_purchase', 24, 2)->default(0);
            $table->enum('discount_type', ['amount', 'percent'])->default('amount');
            $table->decimal('discount', 24, 2)->default(0);
            $table->decimal('max_discount', 24, 2)->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['discount_id', 'min_purchase']);
            $table->foreign('discount_id')->references('id')->on('discounts')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_tiers');
    }
};
