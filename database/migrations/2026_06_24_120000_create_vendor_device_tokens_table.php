<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒商家版 App —— 一店多设备的 FCM token 注册表。
 * 现有 vendors.firebase_token / vendor_employees.firebase_token 是单列, 只能记一台设备;
 * 商家漏接报警要覆盖「店主 + 店员各一台」, 故另起一张多设备表, 报警时按
 * 该餐厅 vendor + 其全部 vendor_employees 的所有 is_active token 扇出。
 * 纯新增表、L3、可逆 (down 直接 drop)。不碰任何资金/合规机制 (L1 无关)。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_device_tokens')) {
            return;
        }
        Schema::create('vendor_device_tokens', function (Blueprint $table) {
            $table->id();
            // 归属: vendor_id = restaurants.vendor_id (店主账号); vendor_employee_id 非空=店员设备
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('vendor_employee_id')->nullable()->index();
            $table->string('fcm_token', 512);
            $table->string('platform', 16)->default('android');
            $table->boolean('is_active')->default(true)->index();
            // 同一 token 只存一行; 换账号登录时按 token upsert、改归属
            $table->unique('fcm_token', 'vdt_token_unique');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_device_tokens');
    }
};
