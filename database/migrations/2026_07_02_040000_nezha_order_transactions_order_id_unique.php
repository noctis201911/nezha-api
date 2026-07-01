<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒[双扣佣底座 · task_fb41eea8 / DESIGN_merchant_offboard A6·C5] — order_transactions.order_id 唯一约束。
 *
 * 背景: create_transaction()(OrderLogic:83) 对每单记一条 order_transaction, 并在(直付单+开抽佣时)从
 * deposit_balance 扣一次 commission_deduction。并发结算(顾客确认 settle_delivered / 超时兜底 cron /
 * 商家 status())可能同时越过 exists() 幂等闸(check-then-insert 无锁) → 双记流水 + 双扣佣金。
 * 加 UNIQUE(order_id) 作 DB 底座: 后到者的 insert 撞唯一键抛 1062 → create_transaction 内 catch 幂等跳过,
 * 保证每单恰好扣一次。定级 L3(结构完整性墙, 同 task9), 但守护资金正确性 → 上线记 CHANGELOG。
 *
 * 现网核实(2026-07-02, prod sql_api_nezha_am): order_transactions 11 行 / 11 distinct order_id / 0 重复;
 * commission_deduction 0 行(抽佣开关 nezha_deposit_mode_status=0 从未真扣) → 加约束零数据清理、零资金影响, 纯预防。
 */
return new class extends Migration
{
    public function up(): void
    {
        $name = 'order_transactions_order_id_unique';

        // 幂等: 已存在则跳过, 可安全重跑。
        if ($this->indexExists($name)) {
            return;
        }

        // 防御: UNIQUE 遇重复会以晦涩的 1062 失败。先显式检测——有重复就停下并说清原因,
        // 绝不静默硬加(重复 order_id 可能对应真实双扣佣, 须先清理重复行 + 对账退还再加约束)。
        $dups = DB::table('order_transactions')
            ->select('order_id', DB::raw('count(*) as c'))
            ->whereNotNull('order_id')
            ->groupBy('order_id')
            ->havingRaw('count(*) > 1')
            ->get();
        if ($dups->isNotEmpty()) {
            throw new \RuntimeException(
                'order_transactions 存在 ' . $dups->count() . ' 组重复 order_id (首个 order_id=' . $dups->first()->order_id .
                '); 加 UNIQUE 前必须先清理重复行并核对/退还可能的双扣佣金 (commission_deduction)。已中止迁移。'
            );
        }

        Schema::table('order_transactions', function (Blueprint $table) use ($name) {
            $table->unique('order_id', $name);
        });
    }

    public function down(): void
    {
        $name = 'order_transactions_order_id_unique';
        if (!$this->indexExists($name)) {
            return;
        }
        Schema::table('order_transactions', function (Blueprint $table) use ($name) {
            $table->dropUnique($name);
        });
    }

    private function indexExists(string $name): bool
    {
        foreach (DB::select('SHOW INDEX FROM order_transactions') as $row) {
            if ($row->Key_name === $name) {
                return true;
            }
        }
        return false;
    }
};
