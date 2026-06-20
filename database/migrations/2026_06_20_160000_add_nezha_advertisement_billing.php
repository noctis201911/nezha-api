<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒外卖 商家广告计费系统 (L2 业务参数, 复用保证金引擎).
 * 见 docs/PLAN_advertisement_billing.md.
 *
 * - advertisements: price(本单锁定的广告费, 德拉姆) + paid_at(实际扣费时间) + deposit_transaction_id(关联保证金流水)
 * - business_settings: 计费总开关(默认关=现有免费流程零变化) + 单价(默认1000德拉姆/天) + 曝光加权系数 + 平台主动下架退费开关
 *
 * 资金性质: 平台向商家收自己的广告服务费, 从商家预存保证金扣, 不碰顾客钱 → 非二清, 定级 L2.
 * 可逆: down() 移除字段 + 删配置行. 计费开关默认 0, 不影响现有免费审核流程.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advertisements', function (Blueprint $table) {
            if (!Schema::hasColumn('advertisements', 'price')) {
                $table->decimal('price', 24, 2)->nullable()->comment('本单广告费(德拉姆), 提交时按单价×天数锁定, 投放起始日实扣');
            }
            if (!Schema::hasColumn('advertisements', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->comment('正式投放扣费时间; 非空=已扣费, 不可取消不可退');
            }
            if (!Schema::hasColumn('advertisements', 'deposit_transaction_id')) {
                $table->unsignedBigInteger('deposit_transaction_id')->nullable()->comment('关联 restaurant_deposit_transactions.id 扣费流水');
                $table->index('deposit_transaction_id');
            }
        });

        // 广告计费业务设置 (key/value). insertOrIgnore 防重复.
        $settings = [
            // 0=免费投广(现有审核流程零变化); 1=开启按天计费+保证金扣费. 默认关, 可灰度.
            ['key' => 'nezha_ad_billing_status', 'value' => '0'],
            // 广告单价 (德拉姆/天). 费用 = 单价 × 投放天数.
            ['key' => 'nezha_ad_price_per_day', 'value' => '1000'],
            // 曝光加权系数: 已扣费且在投放期内的餐馆, 综合排序分数 + 此值 (基础分0~1, 默认0.5=明显但不强制置顶). L3可调.
            ['key' => 'nezha_ad_boost_weight', 'value' => '0.5'],
            // 1=平台/超管强制下架已扣费广告时按未投放天数比例退回保证金; 商家自停不退.
            ['key' => 'nezha_ad_refund_on_platform_takedown', 'value' => '1'],
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
        Schema::table('advertisements', function (Blueprint $table) {
            if (Schema::hasColumn('advertisements', 'deposit_transaction_id')) {
                $table->dropIndex(['deposit_transaction_id']);
                $table->dropColumn('deposit_transaction_id');
            }
            foreach (['price', 'paid_at'] as $c) {
                if (Schema::hasColumn('advertisements', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        DB::table('business_settings')->whereIn('key', [
            'nezha_ad_billing_status',
            'nezha_ad_price_per_day',
            'nezha_ad_boost_weight',
            'nezha_ad_refund_on_platform_takedown',
        ])->delete();
    }
};
