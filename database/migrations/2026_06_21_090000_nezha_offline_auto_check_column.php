<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒外卖: offline_payments 加 nezha_auto_check (JSON) —— 存自动核验结果。
 * 结构: { paid_amount, amount_match(法币顾客自报vs应付), chain{status,to_match,amount,confirmed,...}, image_flags{...} }
 * 纯辅助判断, 不改资金机制(L3)。可逆。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('offline_payments', 'nezha_auto_check')) {
            Schema::table('offline_payments', function (Blueprint $table) {
                $table->json('nezha_auto_check')->nullable()->after('method_fields');
            });
        }
    }
    public function down(): void
    {
        if (Schema::hasColumn('offline_payments', 'nezha_auto_check')) {
            Schema::table('offline_payments', function (Blueprint $table) {
                $table->dropColumn('nezha_auto_check');
            });
        }
    }
};
