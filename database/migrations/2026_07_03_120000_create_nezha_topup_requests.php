<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 预存佣金/广告/押金 自助充值申请流 (A3).
 * 商家自助申请充值 -> 上传凭证 -> 运营审核 -> 复用既有入账核心记账(不造第二套).
 * 一张统一队列表, account_type 分账户, direction 分方向:
 *   direction=topup  : 充值入账(默认); 审核通过走 recordRecharge / recordGuaranteeDeposit / AdBalanceLogic::credit.
 *   direction=refund : 中途退回(押金退口·L1-8·开关 nezha_topup_refund_status 默认关 dormant, 走 L1-8 护栏).
 * 凭证 proof_path = 平台财务凭证: 独立存储, 留存>=5年, 不进 nezha:purge-payment-proofs 90天管道
 *   (该 purge 只扫 offline_payments.payment_info + 其引用文件, 不碰本表, 已核实).
 * 全功能默认关(dormant): 各 nezha_topup_*_status 开关默认0 服务端强制. 可逆: down() 删表.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nezha_topup_requests')) {
            return;
        }
        Schema::create('nezha_topup_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('restaurant_id')->nullable()->index();
            $table->string('account_type', 20)->default('deposit')->comment('deposit=预存佣金/guarantee=押金/ad=广告');
            $table->string('direction', 10)->default('topup')->comment('topup=充值/refund=中途退回(押金退口dormant)');
            $table->decimal('amount_claimed', 24, 2)->default(0)->comment('商家自报额(押金腿=原币额)');
            $table->decimal('amount_credited', 24, 2)->nullable()->comment('运营实际入账/冲减额AMD(以实际到账为准)');
            $table->string('currency', 8)->default('AMD')->comment('押金腿AMD/CNY, 其余AMD');
            $table->decimal('original_amount', 24, 2)->nullable()->comment('押金腿原币金额');
            $table->text('original_ref')->nullable()->comment('押金腿回执号(model加密cast·L1-4留痕)');
            $table->string('proof_path')->nullable()->comment('转账凭证(独立存储·财务凭证>=5年·不进90天purge)');
            $table->string('note')->nullable()->comment('商家备注(选填)');
            $table->string('status', 20)->default('pending')->index()->comment('pending/approved/rejected/cancelled');
            $table->string('reason')->nullable()->comment('运营打回理由');
            $table->unsignedBigInteger('operator_id')->nullable()->comment('审核运营admin id');
            $table->unsignedBigInteger('transaction_id')->nullable()->comment('入账后关联restaurant_deposit_transactions.id(对账)');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_topup_requests');
    }
};