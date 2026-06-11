<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒外卖 B方案 组4: 保证金流水表.
 * 每一笔保证金变动都留账, 后台可查/可对账:
 *  - type=commission_deduction: 订单送达时从商家保证金扣本单佣金(向商家收)
 *  - type=recharge:             管理员后台给商家充值保证金
 *  - type=refund_reversal:      订单退款时返还此前扣的佣金
 *  - type=adjustment:           管理员手动调整(可正可负)
 * amount 带符号: 正=增加保证金, 负=扣减保证金. balance_after=本笔之后的保证金余额快照.
 * 可逆: down() 直接删表.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('restaurant_deposit_transactions')) {
            return;
        }
        Schema::create('restaurant_deposit_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('restaurant_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->string('type', 30)->comment('commission_deduction/recharge/refund_reversal/adjustment');
            $table->decimal('amount', 24, 2)->default(0)->comment('带符号: 正=增加保证金, 负=扣减');
            $table->decimal('commission', 24, 2)->default(0)->comment('该笔涉及的佣金额(扣佣/返还时填)');
            $table->decimal('balance_after', 24, 2)->default(0)->comment('本笔之后的保证金余额快照');
            $table->string('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->comment('管理员id(充值/调整时)');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_deposit_transactions');
    }
};
