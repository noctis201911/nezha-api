<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 本地生活「商家」表（对标饭团商家店铺页）。
 * 与个人发帖(local_life_posts)分开：商家=运营后台录入的服务型商户（移民/签证/美容美发/按摩/包车出行/本地旅游…），
 * 顾客点商家类目→商家列表→商家店铺页（评分/营业时间/地址/导航/介绍/服务）。
 * 合规 L1-1：纯信息墙，只展示，不碰钱、不接预订下单。
 * 同时给 local_life_categories 加 kind 字段：ugc=个人发帖(留信息流) / merchant=商家服务(跳商家页)。
 */
class CreateLocalLifeMerchantsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('local_life_categories', 'kind')) {
            Schema::table('local_life_categories', function (Blueprint $table) {
                // ugc=个人发帖(信息流) / merchant=商家服务(跳商家列表)。默认 ugc，新建类目运营自选。
                $table->string('kind', 12)->default('ugc')->after('tab');
            });
        }

        if (Schema::hasTable('local_life_merchants')) {
            return; // 幂等（多窗口共享工作目录防重复建）
        }
        Schema::create('local_life_merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);                       // 商家名
            $table->string('category', 60)->index();           // 归属类目名（= local_life_categories.name）
            $table->string('logo', 191)->nullable();           // logo/头像图（文件名）
            $table->json('images')->nullable();                // 相册（文件名数组）
            $table->string('wechat_qr', 191)->nullable();      // 微信二维码图（文件名）
            $table->decimal('rating', 2, 1)->default(5.0);     // 平台星级（运营手填）
            $table->decimal('google_rating', 2, 1)->nullable();// Google 评分
            $table->string('google_rating_url', 255)->nullable();
            $table->string('area', 60)->nullable()->index();   // 区域（列表「全部区域」筛选用）
            $table->string('address', 255)->nullable();        // 详细地址
            $table->decimal('latitude', 10, 7)->nullable();    // 导航用
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('open_days')->nullable();             // 营业星期 [0..6]（0=周日，同 JS getDay/埃里温时区）
            $table->string('open_time', 5)->nullable();        // 09:00
            $table->string('close_time', 5)->nullable();       // 18:00
            $table->string('hours_note', 120)->nullable();     // 营业时间补充文字（如「周末休息」）
            $table->text('intro')->nullable();                 // 商家介绍
            $table->json('services')->nullable();              // 服务项 [{title,desc,price_text}]
            $table->boolean('has_offer')->default(false);      // 到店优惠（列表筛选标记，不做核销）
            $table->string('offer_text', 120)->nullable();     // 优惠文字（如「到店出示立减」）
            $table->boolean('is_sensitive')->default(false);   // 敏感类目（移民/签证/按摩）→后台标红重点审核
            $table->unsignedInteger('sort_order')->default(0); // 列表排序，越小越靠前
            $table->boolean('status')->default(true);          // 1=上线可见 0=隐藏
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('local_life_merchants');
        if (Schema::hasColumn('local_life_categories', 'kind')) {
            Schema::table('local_life_categories', function (Blueprint $table) {
                $table->dropColumn('kind');
            });
        }
    }
}
