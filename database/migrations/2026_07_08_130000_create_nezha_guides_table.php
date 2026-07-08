<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 本地生活「生活攻略」表（批2 · PGC 攻略 MVP）。
 * 平台整理的落地/租房/换汇/中餐/居留等攻略长文；纯信息展示，合规 L1-1，无 PII、不涉 L1 留存。
 * 正文 body_md 走 Markdown，服务端 League CommonMark 渲染成 body_html 随 API 下发；
 * 正文内独占一行的 {{restaurant:ID}} / {{merchant:ID}} shortcode 由后端解析成内嵌店卡。
 * 总开关 business_settings.nezha_guides_status（默认 0 封印），开关=0 时三端点均空/404 语义。
 * additive migration，可 down。
 */
class CreateNezhaGuidesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('nezha_guides')) {
            return; // 幂等（多窗口共享工作目录防重复建）
        }
        Schema::create('nezha_guides', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);                          // 攻略标题
            $table->string('slug', 191)->unique();                 // URL 唯一 slug（/local-life/guides/{slug}）
            $table->string('cover_url', 255)->nullable();          // 封面图 URL（可空 → 前端走米金渐变兜底）
            $table->string('summary', 300)->nullable();            // 一句话摘要（列表卡 2 行 clamp）
            $table->mediumText('body_md')->nullable();             // 正文 Markdown 源
            $table->string('info_as_of', 7);                       // 信息截至 YYYY-MM（必填，时效锚点）
            $table->boolean('is_sensitive_topic')->default(false); // level1 话题（签证/居留/移民）→ 文末专用免责
            $table->unsignedInteger('helpful_count')->default(0);  // 「有用」计数（真实零态前端不显数）
            $table->tinyInteger('status')->default(0);             // 0=隐藏 1=上架（默认隐，配开关双封印）
            $table->unsignedInteger('sort')->default(0);           // 列表排序，越小越靠前（sort 第一篇=新来必读）
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'sort']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('nezha_guides');
    }
}
