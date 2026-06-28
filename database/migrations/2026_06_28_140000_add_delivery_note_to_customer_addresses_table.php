<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
// 哪吒[加地址-备注 2026-06-28]: customer_addresses 加可空「备注」列(给商家叫 Yandex/司机的指路/交代)。
// 加地址表单(饭团式重做)写入,商家后台订单地址展示用。nullable,旧地址不受影响。
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('customer_addresses', 'delivery_note')) {
            Schema::table('customer_addresses', function (Blueprint $table) {
                $table->string('delivery_note', 500)->nullable()->after('house');
            });
        }
    }
    public function down(): void {
        if (Schema::hasColumn('customer_addresses', 'delivery_note')) {
            Schema::table('customer_addresses', function (Blueprint $table) {
                $table->dropColumn('delivery_note');
            });
        }
    }
};
