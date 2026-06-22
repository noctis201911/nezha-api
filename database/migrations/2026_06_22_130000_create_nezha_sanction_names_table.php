<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 制裁筛查③ — OFAC SDN 人名/实体名 + 别名 本地表 (L1-6, 阶段1).
 *
 * 背景: 现有 nezha:sync-sanction-list 解析 SDN XML 时只取了「数字货币地址」, 人名/别名/实体名全丢了
 *   → 入驻环节「制裁名单命中即拒」对人名空跑。本表补这个洞。
 * 公开制裁名单, 非 PII, 比照 nezha_sanction_addresses 【不加密】。
 * 由 nezha:sync-sanction-list 解析 firstName/lastName/akaList 填充;
 * NezhaKycScreen::screen_name() 据此比对法人/受益人姓名(规范化精确 + token 近似)。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nezha_sanction_names')) {
            Schema::create('nezha_sanction_names', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name_norm', 191)->comment('规范化姓名(大写/去音标/去标点/压空白) — 比对键');
                $table->string('name_raw', 250)->nullable()->comment('原始姓名(展示用)');
                $table->string('name_type', 16)->default('individual')->comment('individual/entity/other');
                $table->string('sdn_uid', 32)->default('')->comment('OFAC SDN 实体 uid(溯源)');
                $table->string('programs', 250)->nullable()->comment('制裁项目(逗号分隔)');
                $table->string('source', 32)->default('OFAC_SDN');
                $table->timestamp('added_at')->nullable()->comment('首次入库时间');
                $table->timestamp('last_seen_sync')->nullable()->comment('最近一次同步仍在名单中的时间');
                $table->timestamps();

                $table->unique(['name_norm', 'name_type', 'sdn_uid', 'source'], 'uniq_name_type_uid_source');
                $table->index('name_norm');
            });
        }

        // 阶段1 开关登记: 制裁名字筛查默认【开】; 强制KYC默认【关】(本模块不强制, 仅登记可发现)。
        foreach ([
            'nezha_kyc_sanction_screen_status' => '1',
            'nezha_kyc_required_status'        => '0',
        ] as $k => $v) {
            if (!DB::table('business_settings')->where('key', $k)->exists()) {
                DB::table('business_settings')->insert([
                    'key'        => $k,
                    'value'      => $v,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_sanction_names');
    }
};
