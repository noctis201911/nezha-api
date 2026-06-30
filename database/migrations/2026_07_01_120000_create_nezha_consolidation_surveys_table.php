<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒 平台集运申报 — 商家需求登记表（意向/品类/货量/频率/物流成本/推荐服务方/建议）。
// 仅采集商家进货需求意向，平台据此评估货量、找货代谈价。平台不碰钱，与 L1 红线无关。
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nezha_consolidation_surveys')) {
            return;
        }
        Schema::create('nezha_consolidation_surveys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('restaurant_id')->nullable()->index();
            $table->string('intent', 16)->default('maybe');         // yes / maybe / no
            $table->text('current_channels')->nullable();           // JSON 现状渠道(多选, 存中文标签)
            $table->text('pain_points')->nullable();                // JSON 痛点(多选, 存中文标签)
            $table->text('categories')->nullable();                 // JSON 品类(多选, 存中文标签)
            $table->string('category_other', 255)->nullable();      // 其它品类自填
            $table->string('category_examples', 255)->nullable();   // 具体品名举例
            $table->string('times_3m', 16)->nullable();             // 过去3个月次数桶: 0 / 1-2 / 3-5 / 6+
            $table->string('volume_unit', 8)->nullable();           // m3 / kg / box
            $table->string('volume_m3', 16)->nullable();            // 体积桶: <1 / 1-3 / 3-5 / 5-10 / >10
            $table->string('weight_kg', 16)->nullable();            // 重量桶: <100 / 100-500 / 500-1000 / >1000
            $table->string('box_count', 32)->nullable();            // 箱数
            $table->string('box_size', 64)->nullable();             // 每箱尺寸
            $table->string('frequency', 16)->nullable();            // weekly/biweekly/monthly/quarterly/irregular
            $table->string('lead_time', 24)->nullable();            // fast / mid / slow
            $table->string('current_cost', 160)->nullable();        // 目前物流成本(自由填)
            $table->string('expected_saving', 16)->nullable();      // little / s15 / s30
            $table->string('refer_provider', 8)->nullable();        // yes / no 是否愿意推荐服务方
            $table->string('refer_provider_info', 255)->nullable(); // 推荐服务商信息
            $table->text('suggestion')->nullable();                 // 其它建议
            $table->timestamps();
            $table->unique('vendor_id'); // 一商家一份, 可随时更新
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_consolidation_surveys');
    }
};
