<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 本地生活商家「门面图」列（additive · 可回滚）。
 * 运营在 admin 可指定某张相册图为门面（详情页 hero + 分享卡背景同用）；不设=系统自动挑第一张横图。
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('local_life_merchants') && !Schema::hasColumn('local_life_merchants', 'cover_image')) {
            Schema::table('local_life_merchants', function (Blueprint $table) {
                $table->string('cover_image', 191)->nullable()->after('images');
            });
        }
    }
    public function down()
    {
        if (Schema::hasColumn('local_life_merchants', 'cover_image')) {
            Schema::table('local_life_merchants', function (Blueprint $table) {
                $table->dropColumn('cover_image');
            });
        }
    }
};
