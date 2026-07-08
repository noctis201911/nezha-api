<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 本地生活商家「结构化联系方式」列（additive · 可回滚）。
 * 批3：商家需多渠道并存（WhatsApp/Telegram/电话/微信），做成前端可点 deep link。
 * 与 posts 侧 contact_method/contact_value 两列「有意不同构」——商家一家可有多条联系方式，
 * 故用 JSON 数组 [{method: wechat|phone|whatsapp|telegram, value, label?}]；
 * 既有 wechat_qr（微信二维码图）列保留并存（微信条目点开显 QR）。
 * L1-1 纯信息墙：仅展示联系方式，不碰钱、不接单。
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('local_life_merchants') && !Schema::hasColumn('local_life_merchants', 'contacts')) {
            Schema::table('local_life_merchants', function (Blueprint $table) {
                // [{method,value,label?}]，method ∈ wechat|phone|whatsapp|telegram
                $table->json('contacts')->nullable()->after('wechat_qr');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('local_life_merchants', 'contacts')) {
            Schema::table('local_life_merchants', function (Blueprint $table) {
                $table->dropColumn('contacts');
            });
        }
    }
};
