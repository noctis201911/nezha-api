<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒[B·打开支付宝直跳]: 存商家支付宝收款码解码出的 qr.alipay.com 收款链接。
// 前端「打开支付宝」深链此 URL 直跳收款页(通用链接·iOS+安卓); 空=回落扫一扫。additive nullable, 生产安全可逆。
return new class extends Migration {
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('rmb_qr_url', 512)->nullable()->after('rmb_qr_image');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('rmb_qr_url');
        });
    }
};
