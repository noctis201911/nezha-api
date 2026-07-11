<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 本地生活商家「店内视频」外链卡（档1·additive·可回滚）。
 * 商家提供的外部平台视频（抖音/小红书/TikTok/Instagram）以封面卡展示、点击外跳观看。
 * 存储格式 JSON 数组 [{platform,url,cover(文件名),title?}]；封面走 local-life-merchant/ 目录（与相册同）。
 * L1-1 纯信息墙：仅展示 + 外跳，不嵌 iframe、不碰钱、不接单。
 * 总闸 nezha_merchant_video_status=0 时 API 不透出（前端整卡不显）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('local_life_merchants') && !Schema::hasColumn('local_life_merchants', 'video_links')) {
            Schema::table('local_life_merchants', function (Blueprint $table) {
                // [{platform: douyin|xiaohongshu|tiktok|instagram, url, cover, title?}]，上限 6 条
                $table->json('video_links')->nullable()->after('services');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('local_life_merchants', 'video_links')) {
            Schema::table('local_life_merchants', function (Blueprint $table) {
                $table->dropColumn('video_links');
            });
        }
    }
};
