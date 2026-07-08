<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 seed-home 灰度总闸 nezha_seedhome_status(默认 0 关)。
 * 分享深链落地垫一格 /home, 让 iOS 左滑/返回回首页而非出站; 前端 seedHomeUnder() 门面受此 flag gate。
 * additive · 幂等 · 可回滚。全程 dormant(默认0)。翻 1 须 cache:clear + php-fpm restart(见 PRELAUNCH_SWITCHES §busy 同理)。
 */
return new class extends Migration {
    public function up(): void
    {
        if (!DB::table('business_settings')->where('key', 'nezha_seedhome_status')->exists()) {
            $row = ['key' => 'nezha_seedhome_status', 'value' => '0'];
            if (Schema::hasColumn('business_settings', 'created_at')) {
                $row['created_at'] = now();
                $row['updated_at'] = now();
            }
            DB::table('business_settings')->insert($row);
        }
    }

    public function down(): void
    {
        DB::table('business_settings')->where('key', 'nezha_seedhome_status')->delete();
    }
};
