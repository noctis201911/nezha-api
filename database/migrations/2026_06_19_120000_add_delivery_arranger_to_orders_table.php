<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒外卖: 把"谁呼叫 Yandex 配送"从脆弱的 delivery_instruction 中文字符串判断,
 * 固化成结构化字段 delivery_arranger:
 *   merchant  = 商家代叫 Yandex
 *   customer  = 顾客自行呼叫 Yandex
 *   null      = take_away / dine_in (完全不涉及 Yandex)
 *
 * nullable 且不设 DB 默认值, 避免静默把订单标成错误责任方(需求8)。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'delivery_arranger')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('delivery_arranger', 20)->nullable()->after('delivery_instruction');
            });
        }

        // 回填历史订单: 显式映射, 不允许默认到错误责任方(需求8)。
        // 仅 delivery 单需要 arranger; take_away/dine_in 保持 null。
        DB::table('orders')
            ->where('order_type', 'delivery')
            ->whereNull('delivery_arranger')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('orders')
                        ->where('id', $row->id)
                        ->update(['delivery_arranger' => self::deriveArranger($row->delivery_instruction)]);
                }
            });
    }

    /**
     * 历史 delivery_instruction 字符串 -> 责任方。与 Order::resolvedDeliveryArranger()
     * 及前端 resolveDeliveryArranger() 保持同一套规则。
     */
    private static function deriveArranger($instruction): string
    {
        $ins = (string) ($instruction ?? '');
        if (mb_strpos($ins, '商家') !== false || stripos($ins, 'merchant') !== false) {
            return 'merchant';
        }
        if (mb_strpos($ins, '自行') !== false
            || mb_strpos($ins, '自己') !== false
            || stripos($ins, 'self') !== false
            || stripos($ins, 'customer') !== false) {
            return 'customer';
        }
        // 无法判断的历史配送单 -> 按"顾客自叫"处理(给诚实可行动的一键 Yandex,
        // 而不是谎称商家已安排)。用户 2026-06-19 拍板。
        return 'customer';
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'delivery_arranger')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('delivery_arranger');
            });
        }
    }
};
