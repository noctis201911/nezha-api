<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒 AI 客服工单：顾客「联系不上商家」等需后台人工跟进的事项，留单给运营。
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nezha_cs_tickets')) {
            return;
        }
        Schema::create('nezha_cs_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32)->default('cant_reach'); // cant_reach / other
            $table->string('status', 16)->default('open');      // open / closed
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_cs_tickets');
    }
};
