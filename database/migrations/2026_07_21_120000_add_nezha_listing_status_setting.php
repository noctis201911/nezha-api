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

    /**
     * 🔴 刻意不删键（部署前 GATE 复核指出）：删掉 nezha_listing_status 会让 NezhaListing::enabled()
     * 降级为 false —— 那等同于关总闸，会让现网 9 家 status=1 的挂牌店立刻恢复站内接单而无人接单。
     * 这跟「回滚一个新加的设置项」的直觉相反，所以这里做成不动数据、只留说明：
     * 真要停用挂牌态，请到后台「商家 → 挂牌态管理」看清受影响家数后手动关闸；
     * 真要清理这个键，请在确认无挂牌店（或已接受上述后果）后手动 DELETE。
     */
    public function down(): void
    {
        // no-op by design
    }
};
