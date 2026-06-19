<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒外卖 配送申诉留痕表（「没有收到餐 / 配送异常」专用）。
 * 平台不碰钱：仅留痕 + 通知，不触发自动退款。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nezha_delivery_appeals')) {
            return;
        }
        Schema::create('nezha_delivery_appeals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('reason_code', 64)->nullable();   // not_received / wrong_order / late / other
            $table->text('detail')->nullable();              // 顾客描述
            $table->json('evidence')->nullable();            // 证据线索（有无付款凭证、聊天记录等）
            $table->string('status', 32)->default('open');   // open / merchant_contacted / resolved / rejected
            $table->timestamp('sla_due_at')->nullable();     // 申诉处理时限
            $table->text('admin_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_delivery_appeals');
    }
};
