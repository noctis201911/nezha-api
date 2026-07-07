<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 本地生活 UGC 帖「上架生命周期」批1 存量细节包（HANDOFF_locallife_batch1 §C·纯 additive L3）。
 *
 * 🔴 命名注意: 表已有 status(tinyint: 0草稿/1已发布/2已下线/3待审核/4已驳回)=「审核态」，
 *    本次新增的是「上架生命周期态」，另起列名 listing_status，二者正交，勿混。
 *
 * - listing_status: active(在售) / sold(已成交) / expired(已失效·含用户下架)。默认 active。
 * - contact_method / contact_value: 结构化联系方式(微信/电话/WhatsApp/Telegram + 值)。
 *   控制器仍同时写 contact_info(合并展示串)保证后台展示 / 旧帖详情 / PII 到期清一致。
 *
 * expires_at 沿用建表已有列(本迁移不改列结构)。语义由「30 天 PII 时钟」统一为
 * 「60 天上架寿命 + PII 到期清」单时钟(业主 2026-07-07 批准，见 docs/compliance/CHANGELOG.md L1-7 附注)；
 * 续期重置、180 天总寿命硬顶均在控制器 renew() 落地，非本迁移。
 */
class AddLifecycleToLocalLifePosts extends Migration
{
    public function up()
    {
        Schema::table('local_life_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('local_life_posts', 'listing_status')) {
                $table->string('listing_status', 12)->default('active')->after('status');
            }
            if (!Schema::hasColumn('local_life_posts', 'contact_method')) {
                $table->string('contact_method', 20)->nullable()->after('contact_info');
            }
            if (!Schema::hasColumn('local_life_posts', 'contact_value')) {
                $table->string('contact_value', 200)->nullable()->after('contact_method');
            }
        });

        // 存量帖一律回填「在售」(24 条均为 admin 示范帖，expires_at=null → 永不被 sweep / PII 清)。
        DB::table('local_life_posts')
            ->whereNull('listing_status')
            ->update(['listing_status' => 'active']);
    }

    public function down()
    {
        Schema::table('local_life_posts', function (Blueprint $table) {
            foreach (['listing_status', 'contact_method', 'contact_value'] as $col) {
                if (Schema::hasColumn('local_life_posts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
}
