<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
/**
 * 哪吒: restaurants 加 usdt_bep20_address —— 商家 BEP20(BSC) USDT 收款地址。
 * usdt_address 保持为 TRC20(波场)地址。两者各自可空, 收银台按"配了哪个显示哪个"出砖。
 * L3 表结构。可逆。
 */
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasColumn('restaurants', 'usdt_bep20_address')) {
            Schema::table('restaurants', function (Blueprint $t) {
                $t->string('usdt_bep20_address')->nullable()->after('usdt_network');
            });
        }
    }
    public function down(): void {
        if (Schema::hasColumn('restaurants', 'usdt_bep20_address')) {
            Schema::table('restaurants', function (Blueprint $t) { $t->dropColumn('usdt_bep20_address'); });
        }
    }
};
