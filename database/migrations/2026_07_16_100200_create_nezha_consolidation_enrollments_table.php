<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒 平台集运申报(阶段 B) — 期次报名表。一店一期一份(唯一键 round_id+vendor_id)。平台不碰钱。MySQL 5.7 兼容。
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nezha_consolidation_enrollments')) {
            return;
        }
        Schema::create('nezha_consolidation_enrollments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('round_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('restaurant_id')->nullable();
            $table->decimal('est_volume_value', 10, 2)->nullable();
            $table->enum('est_volume_unit', ['m3', 'kg', 'box'])->nullable();
            $table->json('categories')->nullable();
            $table->string('note', 500)->nullable();
            $table->enum('status', ['enrolled', 'canceled'])->default('enrolled');
            $table->timestamps();
            $table->unique(['round_id', 'vendor_id']); // 一店一期一份
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_consolidation_enrollments');
    }
};
