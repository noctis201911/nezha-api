<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 本地生活「联系意图」埋点（A · 新表 · 可回滚）。
 *
 * 度量顾客在商家页点了哪个联系渠道、点前想问什么问题——只落聚合事实，
 * 用于运营看冷热 / 月咨询量 / 顾客最想知道什么。
 *
 * 🔴 零主体标识：表内【不含】user_id / IP / UA / 设备指纹 / platform（业主 0719 拍板·甲）。
 *   → 非个人数据，L1-7 的 PII 加密+到期删除义务不attach（无 purge 时钟、无披露义务）。
 *   → 代价：计次含同人重复点击，不能去重/算独立用户（真需要另开包升级，禁就地加列）。
 *
 * 字段：
 *   merchant_id  = 被联系的本地生活商家（local_life_merchants.id；无 FK，商家删后事件留存做历史统计）
 *   channel      = 联系渠道白名单 wechat/phone/whatsapp/telegram（与 LocalLifeMerchant 白名单同 4 值）
 *   question     = 快捷提问 key（promo/price/hours/booking）；wechat/phone 恒 NULL；乱值降级 NULL
 *   created_at   = 事件时间（Asia/Yerevan 全站口径）；无 updated_at（append-only 事件流）
 *
 * L1-1 纯信息墙：埋点只是信息层，不含任何交易/下单/收款元素。
 * L1-7：本表无 PII，仍显式 ENCRYPTION='Y'（统一姿态，防未来误存 + 对齐 local_life_* 加密线）。
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('local_life_contact_events')) {
            Schema::create('local_life_contact_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('merchant_id');
                $table->string('channel', 16);
                $table->string('question', 24)->nullable();      // 快捷提问 key；wechat/phone 恒 NULL
                $table->timestamp('created_at')->nullable();      // append-only，无 updated_at
                $table->index(['merchant_id', 'created_at']);     // admin 近30天 withCount + 月咨询 SQL
            });

            // 显式表空间加密(L1-7 统一姿态)：MySQL 5.7 新表不继承全库加密，须手动开；keyring 未就绪不阻断建表
            try {
                DB::statement("ALTER TABLE `local_life_contact_events` ENCRYPTION='Y'");
            } catch (\Throwable $e) {
                // 静默：加密态在收尾脚本 SHOW CREATE TABLE 复核
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('local_life_contact_events');
    }
};
