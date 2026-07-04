<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒 · denied 凭证争议流 R1(P2 报批件·业主 2026-07-03 批准 §7)。
 *
 * 1) nezha_refund_disputes: 商家对「待退款」留痕记录发起的争议 + 运营裁决留痕。
 *    - 只增不改不删既有字段/行(nezha_refund_records 仍 append-only·仅 status UPDATE)。
 *    - refund_record_id UNIQUE = 单条记录争议上限 1 次的结构墙(驳回即终局·不可再发起)。
 *    - 审计记录: 留存 ≥5 年、免于 PII 自动清除(同 L1-4 nezha_refund_records 管道; 无 purge 任务消费本表)。
 * 2) nezha_refund_records 补 overdue_anchor_at(nullable): 逾期计时锚点; 争议维持裁决后置为裁决时刻;
 *    null=回退 created_at。R4 逾期联动消费; 本次仅加列(dormant)。
 * 3) 开关 nezha_refund_dispute_status 默认 0(dormant): 整包 R1-R4 就绪 + 业主翻开前不受理任何争议。
 *
 * 全程幂等(hasTable/hasColumn 守卫), 可重跑。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nezha_refund_disputes')) {
            Schema::create('nezha_refund_disputes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('refund_record_id')->unique()->comment('nezha_refund_records.id; UNIQUE=单记录争议上限1次(结构墙)');
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->text('merchant_statement')->comment('商家核实陈述(必填)');
                $table->string('status', 20)->default('open')->comment('open/resolved');
                $table->string('resolution', 30)->nullable()->comment('裁决: upheld(维持退款义务)/closed_no_payment(核实未收款)');
                $table->text('operator_reason')->nullable()->comment('运营裁决理由');
                $table->unsignedBigInteger('operator_id')->nullable()->comment('裁决运营 id');
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->foreign('refund_record_id')->references('id')->on('nezha_refund_records')->onDelete('restrict');
            });
        }

        if (Schema::hasTable('nezha_refund_records') && !Schema::hasColumn('nezha_refund_records', 'overdue_anchor_at')) {
            Schema::table('nezha_refund_records', function (Blueprint $table) {
                $table->timestamp('overdue_anchor_at')->nullable()->after('merchant_refund_note')
                    ->comment('逾期计时锚点; 争议维持裁决后置为裁决时刻; null=回退 created_at(R4 消费)');
            });
        }

        if (!DB::table('business_settings')->where('key', 'nezha_refund_dispute_status')->exists()) {
            DB::table('business_settings')->insert([
                'key'        => 'nezha_refund_dispute_status',
                'value'      => '0', // dormant: 默认关, R1-R4 就绪+业主翻开前不受理争议
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_refund_disputes');
        if (Schema::hasTable('nezha_refund_records') && Schema::hasColumn('nezha_refund_records', 'overdue_anchor_at')) {
            Schema::table('nezha_refund_records', function (Blueprint $table) {
                $table->dropColumn('overdue_anchor_at');
            });
        }
        DB::table('business_settings')->where('key', 'nezha_refund_dispute_status')->delete();
    }
};
