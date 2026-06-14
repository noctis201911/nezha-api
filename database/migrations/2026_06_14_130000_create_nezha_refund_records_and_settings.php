<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒外卖 退款机制② — 退款原路锁定 + 退款限额/限笔 地基.
 *
 * 1) nezha_refund_records: 退款留痕表(合规留存≥5年, 免于 PII 自动清除).
 *    每条: 订单/金额/通道/原因 + USDT(原始tx/锁定地址/退款tx/链上校验) + 法币(退款凭证)
 *    + 限额风控命中 + 处置状态 + 操作人. 满足 L1-2/L1-3/L1-4 留痕义务.
 *
 * 2) business_settings 种入退款控制配置项 — 全部后台「风控设置」页可调, 不硬编码.
 *    退款总开关 nezha_refund_control_status 独立于下单风控总开关, 默认关(0):
 *    上线不立即改变现网退款行为; 经测试单验证 + 用户批准后再开(real-impact 开关).
 *    已存在的 key 不覆盖(保护后台改过的值).
 *
 * 可逆: down() 删表 + 删本次新增配置项.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nezha_refund_records')) {
            Schema::create('nezha_refund_records', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('refund_id')->nullable()->index()->comment('关联 refunds 表 id');
                $table->unsignedBigInteger('restaurant_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('guest_id', 100)->nullable();
                $table->string('payment_channel', 20)->default('other')->comment('rmb/usdt/other');
                $table->decimal('order_amount', 24, 2)->default(0)->comment('订单实付(美元基准)快照');
                $table->decimal('refund_amount', 24, 2)->default(0)->comment('本次退款金额, 服务端强校验 ≤ order_amount');
                $table->string('reason_category', 30)->nullable()->comment('missing_item/out_of_stock/quality/other');
                $table->string('reason_note')->nullable();

                // —— 原路锁定留痕 ——
                $table->string('route_locked_note')->nullable()->comment('锁定的原路描述(审计可读)');

                // —— USDT 链上 (L1-3/L1-4) ——
                $table->string('chain', 16)->nullable()->comment('bsc/trc20');
                $table->string('original_tx_hash', 120)->nullable()->comment('原始付款哈希(从 payment_info 取)');
                $table->string('locked_to_address', 120)->nullable()->comment('反查出的原 from 地址 = 退款目标(锁死)');
                $table->string('refund_tx_hash', 120)->nullable()->comment('商家退款后回填的退款哈希');
                $table->string('chain_verify_status', 20)->default('na')->comment('na/unverified/verified/failed/manual');
                $table->json('chain_verify_detail')->nullable()->comment('链上校验结果: 金额/地址/链');

                // —— 法币凭证闭环 ——
                $table->string('refund_proof_image')->nullable()->comment('退款截图(法币必传/USDT辅助)');
                $table->boolean('customer_confirmed')->default(false)->comment('顾客确认已收到退款(可选双向闭环)');
                $table->timestamp('customer_confirmed_at')->nullable();

                // —— 限额风控 (L2) ——
                $table->string('risk_action', 20)->default('pass')->comment('pass/over_limit');
                $table->json('risk_hit')->nullable()->comment('命中的限额规则列表');

                // —— 处置 ——
                $table->string('status', 20)->default('recorded')->comment('recorded/pending_admin/approved/rejected');
                $table->unsignedBigInteger('operator_id')->nullable()->comment('发起/记录的管理员 id');
                $table->unsignedBigInteger('reviewed_by')->nullable()->comment('超限审核的管理员 id');
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note')->nullable();

                $table->timestamps();
            });
        }

        // 退款控制配置项 — 后台「风控设置」页可调. 已存在则不覆盖.
        $defaults = [
            'nezha_refund_control_status'     => '0',   // 退款护栏总开关(独立于下单风控). 默认关: 上线不改变现网退款.
            'nezha_refund_single_limit'       => '100', // 单笔退款上限(美元) → 超过转 admin 审核
            'nezha_refund_daily_total_limit'  => '300', // 单商家单日退款累计上限(美元) → 超过转审核
            'nezha_refund_daily_count_limit'  => '5',   // 单商家单日退款笔数上限 → 超过转审核
            'nezha_refund_window_days'        => '7',   // 退款窗口: 交付后 N 天内可退(0=不限)
            'nezha_refund_usdt_verify_status' => '1',   // USDT 退款链上校验(1=尝试自动校验, 0=只锁定+人工核)
            'nezha_refund_bscscan_api_key'    => '',    // 选填: BscScan API key(空则用公共 RPC 节点, 免密钥)
            'nezha_refund_trongrid_api_key'   => '',    // 选填: TronGrid API key(空则用公共端点)
            'nezha_refund_chain_rpc_bsc'      => 'https://bsc-dataseed.binance.org', // BSC 公共 RPC(可改)
            'nezha_refund_tron_api_base'      => 'https://api.trongrid.io',          // TronGrid 公共端点(可改)
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
        Schema::dropIfExists('nezha_refund_records');
        DB::table('business_settings')->whereIn('key', [
            'nezha_refund_control_status', 'nezha_refund_single_limit', 'nezha_refund_daily_total_limit',
            'nezha_refund_daily_count_limit', 'nezha_refund_window_days', 'nezha_refund_usdt_verify_status',
            'nezha_refund_bscscan_api_key', 'nezha_refund_trongrid_api_key',
        ])->delete();
    }
};
