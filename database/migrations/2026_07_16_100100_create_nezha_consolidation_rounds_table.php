<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒 平台集运申报(阶段 B · 期次撮合骨架) — 拼柜期次表。dormant(总闸 nezha_consolidation_rounds_status 默认 0)。
// 平台只组织撮合、公示货代报价, 付款商家直付货代, 平台不碰钱。与 L1 红线无关。MySQL 5.7 兼容(enum/json)。
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nezha_consolidation_rounds')) {
            return;
        }
        Schema::create('nezha_consolidation_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('round_no', 32)->unique();               // 期次号 YYYYMM-N
            $table->string('title', 191);
            $table->enum('status', ['draft', 'open', 'closed', 'canceled'])->default('draft')->index();
            $table->dateTime('cutoff_at')->nullable();              // 截止收货
            $table->date('etd')->nullable();                        // 预计发运
            $table->date('eta')->nullable();                        // 预计到仓
            $table->json('forwarder_info')->nullable();             // 货代信息(json)
            $table->json('pricing_info')->nullable();               // 报价: 单价/时效/申报方式/货代联系方式(json)
            $table->decimal('min_volume_value', 10, 2)->nullable(); // 成团门槛值
            $table->enum('min_volume_unit', ['m3', 'kg', 'box'])->default('m3');
            $table->text('notes')->nullable();
            $table->dateTime('notified_at')->nullable();            // 包3 开期通知幂等锚
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_consolidation_rounds');
    }
};
