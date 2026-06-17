<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒 制裁筛查② (L1-6) — 新增「来源地址反查不出时的处置」配置项.
 *
 * nezha_sanction_inconclusive_action:
 *   'hold'  (默认, fail-closed) — 无 tx / 链上 API 不可达导致反查不出来源地址时, 不放行出餐、中止确认、
 *           转人工复核(更符合制裁筛查"查不出即不放行"的通用准则)。订单保持 pending, 来源核实后可重新确认。
 *   'allow' (fail-open)         — 查不出仍放行出餐, 仅留一条待人工复核记录。
 * 后台「风控设置→制裁名单筛查」可调。已存在则不覆盖。
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('business_settings')->where('key', 'nezha_sanction_inconclusive_action')->exists();
        if (!$exists) {
            DB::table('business_settings')->insert([
                'key'        => 'nezha_sanction_inconclusive_action',
                'value'      => 'hold',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('business_settings')->where('key', 'nezha_sanction_inconclusive_action')->delete();
    }
};
