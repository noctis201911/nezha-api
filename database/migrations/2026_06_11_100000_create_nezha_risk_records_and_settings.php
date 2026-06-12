<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒外卖 风控① 交易红旗风控 — 地基.
 *
 * 1) nezha_risk_records: 风控命中记录表(同时是 审计日志 + 人工审核队列).
 *    - action=reject : 系统自动拒单(如单笔超限), status=auto, 无需人工处理.
 *    - action=review : 转人工审核, status=pending → approved(放行)/rejected(清退)/cleared(已退款).
 *    每条记录订单号/账号/命中规则/金额/时间/处置结果, 满足合规留痕.
 *
 * 2) business_settings 种入风控阈值配置项 — 全部由后台「风控设置」页可调, 不硬编码.
 *    已存在的 key 不覆盖(保护管理员后台改过的值).
 *
 * 可逆: down() 删表 + 删本次新增的配置项.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nezha_risk_records')) {
            Schema::create('nezha_risk_records', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('order_id')->nullable()->index()->comment('成单后回填订单号');
                $table->unsignedBigInteger('user_id')->nullable()->index()->comment('登录顾客id');
                $table->string('guest_id', 100)->nullable()->index()->comment('游客id(未登录)');
                $table->unsignedBigInteger('restaurant_id')->nullable()->index();
                $table->string('payment_channel', 20)->default('other')->comment('rmb/usdt/other');
                $table->decimal('order_amount', 24, 2)->default(0)->comment('订单金额(美元基准)');
                $table->json('hit_rules')->nullable()->comment('命中的规则列表[{rule,detail}]');
                $table->string('action', 20)->comment('reject=自动拒单 / review=转人工审核');
                $table->string('status', 20)->default('pending')->comment('auto/pending/approved/rejected/cleared');
                $table->json('snapshot')->nullable()->comment('下单意图快照: 联系方式/地址/购物车摘要');
                $table->string('ip_address', 64)->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable()->comment('处理的管理员id');
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note')->nullable()->comment('审核/处置留痕备注');
                $table->string('disposal_result')->nullable()->comment('处置结果: 放行/退款/清退');
                $table->timestamps();
            });
        }

        // 风控阈值配置项 — 全部后台「风控设置」页可调. 已存在则不覆盖(保护后台改过的值).
        $defaults = [
            'nezha_risk_control_status'         => '1',   // 风控总开关 (1=开, 0=关)
            'nezha_risk_single_order_limit'     => '100', // 单笔订单上限(美元) → 超过拒单
            'nezha_risk_daily_cumulative_limit' => '300', // 单账号单日累计(美元) → 超过转人工审核
            'nezha_risk_freq_24h_count'         => '5',   // 24小时单数阈值 → 超过转审核
            'nezha_risk_freq_10min_count'       => '2',   // 10分钟单数阈值 → 超过转审核
            'nezha_risk_round_amount_flag'      => '1',   // 整百/整千金额标记开关 (1=开)
            'nezha_risk_large_amount_threshold' => '80',  // 大额特征阈值(美元) → 标记审核
            // USDT 通道独立阈值(组②用, 此处先种好默认值)
            'nezha_risk_usdt_single_limit'      => '200', // USDT 单笔上限(美元)
            'nezha_risk_usdt_daily_limit'       => '500', // USDT 单账号单日累计(美元)
            'nezha_risk_contact_info'           => '',    // 拒单提示里展示的客服联系方式
        ];
        foreach ($defaults as $key => $value) {
            $exists = DB::table('business_settings')->where('key', $key)->exists();
            if (!$exists) {
                DB::table('business_settings')->insert([
                    'key'        => $key,
                    'value'      => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_risk_records');
        DB::table('business_settings')->whereIn('key', [
            'nezha_risk_control_status', 'nezha_risk_single_order_limit', 'nezha_risk_daily_cumulative_limit',
            'nezha_risk_freq_24h_count', 'nezha_risk_freq_10min_count', 'nezha_risk_round_amount_flag',
            'nezha_risk_large_amount_threshold', 'nezha_risk_usdt_single_limit', 'nezha_risk_usdt_daily_limit',
            'nezha_risk_contact_info',
        ])->delete();
    }
};
