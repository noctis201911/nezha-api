<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒商家版 App —— 新订单报警「发件箱」。
 * 设计目的: 报警不靠在每条造单代码路径上各挂一刀(会漏 vendor/POS/wallet 代下单),
 * 而是任何「需商家处理的新单」出现时, 无条件写一行 outbox(按 order_id 幂等去重),
 * 由现有每分钟 sweep 兜底重试发送 → 可重试 + 可审计 + 不漏。
 * 内联快速通道只是 best-effort 秒级提醒, 失败不阻塞下单、由本表 + sweep 保底。
 * 纯新增表、L3、可逆。order_id 唯一 = 多个钩子点写同一单不会重复报警。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_alert_outbox')) {
            return;
        }
        Schema::create('vendor_alert_outbox', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();          // 幂等键: 一单一行
            $table->unsignedBigInteger('restaurant_id')->nullable()->index();
            $table->string('status', 16)->default('pending')->index(); // pending | queued | sent | failed
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_alert_outbox');
    }
};
