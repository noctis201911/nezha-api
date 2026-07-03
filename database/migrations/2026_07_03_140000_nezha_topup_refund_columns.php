<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 中途退回押金 (A3 · S3-B) — 给 nezha_topup_requests 补退款流所需列 + 结构墙.
 * 定级 L1-8(押金退还) 的实现载体; /debate 三路红队硬化后经业主批准(2026-07-03·记 CHANGELOG)。
 * 全功能默认关(dormant): nezha_topup_refund_status 默认0 服务端强制. 可逆: down() 删列/索引。
 *
 * 补列:
 *  - sanction_rescreen_at: 制裁实时复筛通过时点(approve/pay 各置; L1-8③ · fail-closed 门)
 *  - holder_verified:      户名核对通过标志(代码置位, 非人肉勾; L1-8②)
 *  - approved_at:          审批通过(锁定快照)时点
 *  - scheduled_pay_at:     可放款时点(高额/单运营超日额 → 次日转 anti-hijack; §H 异步二次闸)
 *  - guarantee_snapshot:   审批当刻 guarantee_balance 快照(pay 时 C4 校验防竞态)
 *  - payout_ref:           放款回执号
 *  - active_refund_uniq:   结构墙 —— UNIQUE(vendor_id, active_refund_uniq) 保证同店至多一笔待处理退款
 *                          (对齐 offboard uq_active; 退款 pending 置1, 离开 pending 置 NULL)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nezha_topup_requests')) {
            return;
        }
        Schema::table('nezha_topup_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('nezha_topup_requests', 'sanction_rescreen_at')) {
                $table->timestamp('sanction_rescreen_at')->nullable()->comment('制裁实时复筛通过时点(L1-8③)');
            }
            if (!Schema::hasColumn('nezha_topup_requests', 'holder_verified')) {
                $table->boolean('holder_verified')->default(false)->comment('户名核对通过(代码置位·L1-8②)');
            }
            if (!Schema::hasColumn('nezha_topup_requests', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->comment('审批锁定快照时点');
            }
            if (!Schema::hasColumn('nezha_topup_requests', 'scheduled_pay_at')) {
                $table->timestamp('scheduled_pay_at')->nullable()->comment('可放款时点(高额/超日额次日转)');
            }
            if (!Schema::hasColumn('nezha_topup_requests', 'guarantee_snapshot')) {
                $table->decimal('guarantee_snapshot', 24, 2)->nullable()->comment('审批当刻押金余额快照(C4 防竞态)');
            }
            if (!Schema::hasColumn('nezha_topup_requests', 'payout_ref')) {
                $table->string('payout_ref')->nullable()->comment('放款回执号');
            }
            if (!Schema::hasColumn('nezha_topup_requests', 'active_refund_uniq')) {
                $table->unsignedBigInteger('active_refund_uniq')->nullable()->comment('结构墙:同店至多一笔待处理退款');
            }
        });

        // 唯一结构墙(单独 try, 已存在则跳过)
        try {
            Schema::table('nezha_topup_requests', function (Blueprint $table) {
                $table->unique(['vendor_id', 'active_refund_uniq'], 'uq_active_refund');
            });
        } catch (\Throwable $e) {
            // 索引已存在 → 幂等跳过
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('nezha_topup_requests')) {
            return;
        }
        try {
            Schema::table('nezha_topup_requests', function (Blueprint $table) {
                $table->dropUnique('uq_active_refund');
            });
        } catch (\Throwable $e) {
        }
        Schema::table('nezha_topup_requests', function (Blueprint $table) {
            foreach ([
                'sanction_rescreen_at', 'holder_verified', 'approved_at',
                'scheduled_pay_at', 'guarantee_snapshot', 'payout_ref', 'active_refund_uniq',
            ] as $col) {
                if (Schema::hasColumn('nezha_topup_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
