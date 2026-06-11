<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒外卖 B 方案 (顾客直付商家本人, 平台不碰钱, 保证金单独扣佣) DB 地基.
 * - restaurants: 每家餐馆自己的收款账户 (去二清的前提: 顾客付进商家自己的码)
 * - restaurant_wallets: deposit_balance 保证金余额
 * - business_settings: 保证金扣佣模式开关(默认关=一阶段免佣免押) + 阈值 + 服务费率
 * 可逆: down() 会移除字段; 业务设置行用 insertOrIgnore, down 删回.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurants', 'rmb_qr_image')) {
                $table->string('rmb_qr_image')->nullable()->comment('人民币收款码图片路径');
            }
            if (!Schema::hasColumn('restaurants', 'usdt_address')) {
                $table->string('usdt_address')->nullable()->comment('USDT收款地址');
            }
            if (!Schema::hasColumn('restaurants', 'usdt_network')) {
                $table->string('usdt_network', 50)->nullable()->comment('USDT网络 TRC20/BSC');
            }
            if (!Schema::hasColumn('restaurants', 'payee_name')) {
                $table->string('payee_name')->nullable()->comment('收款人姓名(顾客核对用)');
            }
        });

        Schema::table('restaurant_wallets', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurant_wallets', 'deposit_balance')) {
                $table->decimal('deposit_balance', 24, 2)->default(0)->comment('商家保证金余额');
            }
        });

        // B 方案业务设置 (key/value). insertOrIgnore 防重复.
        $settings = [
            // 0=一阶段免佣免押(不扣佣不停接单); 1=二阶段开启保证金扣佣+停接单
            ['key' => 'nezha_deposit_mode_status', 'value' => '0'],
            // 保证金低于此值则停止接新单 (扣佣模式开启后才生效)
            ['key' => 'nezha_min_deposit_threshold', 'value' => '0'],
            // 平台服务费率 % (佣金率沿用现成 admin_commission)
            ['key' => 'nezha_service_fee_percent', 'value' => '5'],
        ];
        foreach ($settings as $s) {
            DB::table('business_settings')->insertOrIgnore([
                'key' => $s['key'],
                'value' => $s['value'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            foreach (['rmb_qr_image', 'usdt_address', 'usdt_network', 'payee_name'] as $c) {
                if (Schema::hasColumn('restaurants', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('restaurant_wallets', function (Blueprint $table) {
            if (Schema::hasColumn('restaurant_wallets', 'deposit_balance')) {
                $table->dropColumn('deposit_balance');
            }
        });
        DB::table('business_settings')->whereIn('key', [
            'nezha_deposit_mode_status',
            'nezha_min_deposit_threshold',
            'nezha_service_fee_percent',
        ])->delete();
    }
};
