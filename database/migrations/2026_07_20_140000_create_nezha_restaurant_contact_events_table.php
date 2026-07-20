<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 外卖挂牌店「联系意图」埋点（外卖 TG 化 Phase1 · 新表 · 可回滚）。
 *
 * 度量顾客在挂牌店页点了哪个联系渠道、点前想问什么问题——只落聚合事实，
 * 用于判断挂牌形态到底有没有把人送到商家 TG（Phase 1 是否成立的唯一量化依据）。
 *
 * 🔴 为什么不复用 local_life_contact_events：merchant_id 指向 local_life_merchants，
 *   外卖店 id 是另一套主键空间。混写会让本地生活的运营看板出现不存在的商家、
 *   月咨询量凭空膨胀（业主 0720 明确要求外卖统计不得污染本地生活数据）。
 *
 * 🔴 零主体标识：表内【不含】user_id / IP / UA / 设备指纹 / platform（沿用业主 0719 拍板·甲）。
 *   → 非个人数据，L1-7 的 PII 加密+到期删除义务不 attach（无 purge 时钟、无披露义务）。
 *   → 代价：计次含同人重复点击，不能去重/算独立用户（真需要另开包升级，禁就地加列）。
 *
 * 字段：
 *   restaurant_id = 被联系的挂牌店（restaurants.id；无 FK，店删后事件留存做历史统计）
 *   channel       = 联系渠道白名单 wechat/phone/whatsapp/telegram（与 NezhaContacts::METHODS 同源）
 *   question      = 快捷提问 key；wechat/phone 恒 NULL；乱值降级 NULL
 *                   🔴 白名单正本在 RestaurantController::contact_intent 的 $allowedQ，会随文案迭代变；
 *                      本注释不复述具体值（写死会立刻过时——0720 就已从 hours/delivery/recommend
 *                      改成 order/promo/eta/usdt）。查当前值去读那一处。
 *   created_at    = 事件时间（Asia/Yerevan 全站口径）；无 updated_at（append-only 事件流）
 *
 * L1-1 纯信息墙：埋点只是信息层，不含任何交易/下单/收款元素。
 * L1-7：本表无 PII，仍显式 ENCRYPTION='Y'（统一姿态，防未来误存）。
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('nezha_restaurant_contact_events')) {
            Schema::create('nezha_restaurant_contact_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('restaurant_id');
                $table->string('channel', 16);
                $table->string('question', 24)->nullable();       // 快捷提问 key；wechat/phone 恒 NULL
                $table->timestamp('created_at')->nullable();       // append-only，无 updated_at
                $table->index(['restaurant_id', 'created_at']);    // 按店看近 N 天咨询量
            });

            // 显式表空间加密(L1-7 统一姿态)：MySQL 5.7 新表不继承全库加密，须手动开；keyring 未就绪不阻断建表
            try {
                DB::statement("ALTER TABLE `nezha_restaurant_contact_events` ENCRYPTION='Y'");
            } catch (\Throwable $e) {
                // 静默：加密态在收尾脚本 SHOW CREATE TABLE 复核
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('nezha_restaurant_contact_events');
    }
};
