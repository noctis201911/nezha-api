<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒外卖: 微信收款独立成单独方式.
 * 此前微信/支付宝共用一张人民币码 (restaurants.rmb_qr_image).
 * 新增 wechat_qr_image: 商家本人微信收款二维码图片路径, 独立于 rmb_qr_image.
 * L1-1: 仍是商家本人收款码, 顾客直付商家, 平台不碰钱.
 * L1-7: 该列落在已启用表空间静态加密(at-rest)的 restaurants 表内, 自动随全表加密;
 *       属商家常驻展示码(非顾客付款凭证), 不进 nezha:purge-payment-proofs 到期清除范围.
 * 可逆: down() 删列.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurants', 'wechat_qr_image')) {
                $table->string('wechat_qr_image')->nullable()->comment('微信收款码图片路径(独立于rmb_qr_image)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            if (Schema::hasColumn('restaurants', 'wechat_qr_image')) {
                $table->dropColumn('wechat_qr_image');
            }
        });
    }
};
