<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒[外卖TG化 Phase1·挂牌态] 总闸 business_settings.nezha_listing_status。
 *
 * 默认写 '0'（关）：新开关一律默认关，代码层不做「有店挂牌就自动开」这种隐式魔法。
 * 🔴 **已存在同名键则不动**——这不是可有可无的细节：本功能上线时现网已有 10 家 nezha_listing_only=1
 *    的店（id=13 自建种子店 status=0，另 9 家 status=1 已上架真实商家），故上线流程是
 *    **部署前先手工把该键置 '1'**（现行线上代码不读此键，预置零影响），本迁移随后判定键已存在即跳过，
 *    上线行为 diff=0。若让它以 '0' 落地，那 9 家已上架店会在部署→翻闸之间恢复站内接单而无人接单
 *    （业主 2026-07-21 拍板改用预置方案；原「先 0 后翻」方案经部署前 GATE 复核发现该窗口后废弃）。
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
