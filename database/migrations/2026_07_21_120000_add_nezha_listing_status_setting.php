<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒[外卖TG化 Phase1·挂牌态] 总闸 business_settings.nezha_listing_status。
 *
 * 默认写 '0'（关）：新开关一律默认关，代码层不做「有店挂牌就自动开」这种隐式魔法。
 * ⚠️ 现网上线时点有 1 家试点店（id=13 北方烧烤，status=0 不对外）开着 nezha_listing_only=1，
 *    本迁移落地后它的直链会失效（≤60 秒 ISR）——业主 2026-07-21 拍板：部署后立刻在后台
 *    「商家 → 挂牌态管理」把总闸翻 1 恢复。这是本次上线唯一一处行为 diff，已知且经批准。
 * 已存在同名键则不动（不覆盖运营已翻好的值，迁移可重复跑）。
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('business_settings')
            ->where('key', 'nezha_listing_status')
            ->exists();

        if (! $exists) {
            DB::table('business_settings')->insert([
                'key'        => 'nezha_listing_status',
                'value'      => '0',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // 删键 = 总闸读不到 = 按关处理（NezhaListing::enabled() 降级 false），与 up 前语义一致。
        DB::table('business_settings')->where('key', 'nezha_listing_status')->delete();
    }
};
