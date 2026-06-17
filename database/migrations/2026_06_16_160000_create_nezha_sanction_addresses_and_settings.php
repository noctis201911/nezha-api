<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒外卖 制裁筛查机制② (L1-6) — OFAC SDN 数字货币地址名单 地基.
 *
 * 1) nezha_sanction_addresses: 制裁/黑名单地址库(本地缓存, 由定时任务从 OFAC 公开 SDN 名单刷新).
 *    - addr_kind: evm / tron / other  —— 决定地址规范化方式(EVM 小写; Tron base58 大小写敏感原样).
 *    - address  : 已规范化后的地址(EVM 小写; Tron 原样), 查询时用同样规范化命中.
 *    - source/sdn_uid/currency_type: 来源溯源(满足"留痕可追溯").
 *    合规留存(L1-4 类比): 名单本身长期保留, 不随 PII 清除.
 *
 * 2) business_settings 种入制裁筛查配置项 — 后台「风控设置」可调, 不硬编码.
 *    nezha_sanction_screen_status 默认 1(开): 命中即拒收(L1-6 红线)。
 *    已存在的 key 不覆盖(保护后台改过的值).
 *
 * 可逆: down() 删表 + 删本次新增配置项.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('nezha_sanction_addresses')) {
            Schema::create('nezha_sanction_addresses', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('addr_kind', 12)->default('other')->comment('evm/tron/other — 决定规范化方式');
                $table->string('address', 128)->comment('已规范化地址(EVM小写/Tron原样)');
                $table->string('source', 32)->default('OFAC_SDN')->comment('名单来源');
                $table->string('sdn_uid', 32)->nullable()->comment('OFAC SDN 实体 uid(溯源)');
                $table->string('currency_type', 32)->nullable()->comment('OFAC 标注币种, 如 ETH/USDT/TRX/XBT');
                $table->timestamp('added_at')->nullable()->comment('首次入库时间');
                $table->timestamp('last_seen_sync')->nullable()->comment('最近一次同步仍出现在名单中的时间');
                $table->timestamps();

                // 同一来源同一地址唯一(addr_kind 区分 EVM/Tron 同形地址)
                $table->unique(['addr_kind', 'address', 'source'], 'uniq_kind_addr_source');
                $table->index(['addr_kind', 'address']);
            });
        }

        // 制裁筛查配置项 — 后台「风控设置」页可调. 已存在则不覆盖.
        $defaults = [
            // 制裁筛查总开关. 默认 1(开): L1-6 命中即拒. 独立于下单风控/退款护栏总开关.
            'nezha_sanction_screen_status' => '1',
            // 名单来源(OFAC 公开 SDN XML). 可改为其它镜像/端点; 定时任务从此处拉取.
            'nezha_sanction_source_url'    => 'https://sanctionslistservice.ofac.treas.gov/api/PublicationPreview/exports/SDN.XML',
            // 最近一次名单同步状态(JSON: at/status/detail/count), 后台展示("告警等你看").
            'nezha_sanction_last_sync'     => '',
        ];
        foreach ($defaults as $key => $value) {
            $exists = DB::table('business_settings')->where('key', $key)->exists();
            if (!$exists) {
                DB::table('business_settings')->insert([
                    'key'        => $key,
                    'value'      => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nezha_sanction_addresses');
        DB::table('business_settings')->whereIn('key', [
            'nezha_sanction_screen_status', 'nezha_sanction_source_url', 'nezha_sanction_last_sync',
        ])->delete();
    }
};
