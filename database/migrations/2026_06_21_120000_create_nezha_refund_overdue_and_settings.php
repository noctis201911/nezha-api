<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒 B方案 — 商家「逾期未退款」兜底约束 (L2 风控流程 + 严守 L1 不碰钱).
 *
 * 现状缺口: 平台取消/退款直付单后只生成 nezha_refund_records(status=pending_merchant_refund) 留痕 +
 *   通知商家原路退, 但【无任何机制约束商家真的去退】。商家不退、也不在后台「待退款」标记,
 *   顾客的钱就一直卡着, 平台无强制力, 顾客投诉无门。这是 B 方案(点对点直付、平台不碰钱)的固有缺口。
 *
 * 本兜底: 对 pending_merchant_refund 且生成(created_at)后超过阈值天数仍未 merchant_refunded 的记录,
 *   施加【非资金性】约束: ①写风控记录(nezha_risk_records rule=refund_overdue) ②催办邮件商家
 *   ③告警运营 ④运营后台一键停接单(复用接单闸的「与钱无关挂起标记」)。
 *
 * 🔴 L1 红线: 全程零资金操作。绝不从保证金扣钱赔顾客、不代退、不向顾客打任何钱。
 *   实际退款永远靠商家自己原路退。这里只产出风控/催办/告警/停接单。
 *
 * 本迁移建三样, 全部可逆(down):
 *  1) nezha_refund_overdue_events: 幂等账本(每留痕每动作恰好一次), 仿 nezha_order_timeout_events。
 *  2) restaurants 加 3 列: 与钱无关的接单挂起标记(由运营据退款逾期手动设置, 商家标记退款后自动解除)。
 *  3) business_settings 种入阈值/开关(后台可调, 默认保守 + 总开关默认关)。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) 幂等账本
        if (!Schema::hasTable('nezha_refund_overdue_events')) {
            Schema::create('nezha_refund_overdue_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('refund_record_id')->index()->comment('关联 nezha_refund_records.id');
                $table->unsignedBigInteger('restaurant_id')->nullable()->index();
                $table->string('action', 30)->comment('risk_record/remind_merchant/escalate_t1/escalate_t2');
                $table->timestamp('fired_at')->nullable();
                $table->string('detail')->nullable();
                $table->timestamps();
                $table->unique(['refund_record_id', 'action'], 'nezha_refund_overdue_once');
            });
        }

        // 2) restaurants 接单挂起标记(非资金)
        if (Schema::hasTable('restaurants')) {
            Schema::table('restaurants', function (Blueprint $table) {
                if (!Schema::hasColumn('restaurants', 'nezha_order_suspended')) {
                    $table->boolean('nezha_order_suspended')->default(0)->comment('哪吒: 因退款逾期被运营暂停接单(与钱无关, 1=停接单)');
                }
                if (!Schema::hasColumn('restaurants', 'nezha_suspend_reason')) {
                    $table->string('nezha_suspend_reason')->nullable()->comment('停接单原因(审计可读)');
                }
                if (!Schema::hasColumn('restaurants', 'nezha_suspended_at')) {
                    $table->timestamp('nezha_suspended_at')->nullable()->comment('停接单时间');
                }
            });
        }

        // 3) business_settings 阈值/开关. 已存在则不覆盖(保护后台改过的值)。
        $defaults = [
            // 总开关: 真实影响(会暂停商家经营), 默认关。测试单验证 + 用户批准后再开。
            'nezha_refund_overdue_status'       => '0',
            // 逾期 N 天: 催办商家 + 记风控 + 告警运营
            'nezha_refund_overdue_remind_days'  => '3',
            // 逾期 N 天: 升级告警运营「建议停接单」(实际停接单仍由运营在后台手动一键执行, 留人工复核口子)
            'nezha_refund_overdue_suspend_days' => '7',
        ];
        foreach ($defaults as $key => $value) {
            if (!DB::table('business_settings')->where('key', $key)->exists()) {
                DB::table('business_settings')->insert([
                    'key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_refund_overdue_events');
        if (Schema::hasTable('restaurants')) {
            Schema::table('restaurants', function (Blueprint $table) {
                foreach (['nezha_order_suspended', 'nezha_suspend_reason', 'nezha_suspended_at'] as $col) {
                    if (Schema::hasColumn('restaurants', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
        DB::table('business_settings')->whereIn('key', [
            'nezha_refund_overdue_status', 'nezha_refund_overdue_remind_days', 'nezha_refund_overdue_suspend_days',
        ])->delete();
    }
};
