<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒外卖 F-4 — 直付单退款「通知商家退款 + 留痕 + 商家标记已退款」闭环.
 *
 * 给 nezha_refund_records 补 2 列, 支撑商家侧标记退款的留痕:
 *   merchant_refunded_at  商家点「已退款」的时间
 *   merchant_refund_note  商家退款备注(可选)
 *
 * 新状态 pending_merchant_refund / merchant_refunded 复用既有 status string(20) 列, 无需改列。
 * USDT 退款 tx hash 复用既有 refund_tx_hash 列; 退款凭证复用既有 refund_proof_image 列。
 * 平台全程不碰钱(L1-1): 本表仅留痕, 不参与任何资金记账。
 *
 * 可逆: down() 删这 2 列。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nezha_refund_records')) {
            return; // 主表迁移未跑时不做任何事(防越序执行报错)
        }
        Schema::table('nezha_refund_records', function (Blueprint $table) {
            if (!Schema::hasColumn('nezha_refund_records', 'merchant_refunded_at')) {
                $table->timestamp('merchant_refunded_at')->nullable()->comment('商家标记已退款的时间');
            }
            if (!Schema::hasColumn('nezha_refund_records', 'merchant_refund_note')) {
                $table->string('merchant_refund_note')->nullable()->comment('商家退款备注(可选)');
            }
        });

        // F-4 新状态 pending_merchant_refund(23字符) 超过原 status varchar(20), 加宽到 40。MODIFY 幂等可重跑。
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE nezha_refund_records MODIFY status VARCHAR(40) NOT NULL DEFAULT 'recorded'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('nezha_refund_records')) {
            return;
        }
        Schema::table('nezha_refund_records', function (Blueprint $table) {
            foreach (['merchant_refunded_at', 'merchant_refund_note'] as $col) {
                if (Schema::hasColumn('nezha_refund_records', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
