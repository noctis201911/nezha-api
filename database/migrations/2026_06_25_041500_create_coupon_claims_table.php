<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒[券包 2026-06-25 Slice2]: 顾客「领取到券包」记录表。
// 只记拥有关系(一人一券一条), 不碰资金; 唯一索引 user+coupon = 防重复领的结构墙。
// 真实「每人限领次数」仍由 place_order 既有的按 coupon_code 计数 + lockForUpdate 把守, 此表不做用量真相源。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('coupon_id');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('used_at')->nullable(); // 信息性: 首次使用时间(Slice3 下单时回填), 不作限领判定
            $table->timestamps();
            $table->unique(['user_id', 'coupon_id']);   // 墙: 同一人同一券物理上只能一条
            $table->index('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_claims');
    }
};
