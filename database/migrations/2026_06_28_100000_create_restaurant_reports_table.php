<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 顾客举报商家(餐厅)表。
 * - restaurant_id : 被举报餐厅(restaurants.id)
 * - vendor_id     : 餐厅所属商家(服务端由 restaurant 派生, 不信客户端入参)
 * - user_id       : 登录举报人(apiGuestCheck 取自 token, 不信 body)。游客举报时为 null。
 * - guest_id      : 游客举报人标识(localStorage guest_id)。登录举报时为 null。
 * - reason        : 举报理由(白名单, 见 Api\V1\RestaurantReportController::REASONS)
 * - description   : 补充说明(reason=其他时必填)。自由文本, 可能含 PII → 视为 PII。
 * - status        : 0待处理 1已处理 2驳回
 *
 * L1-7 PII: description 可能含 PII。MySQL 5.7 新建 InnoDB 表【不继承】全库表空间加密,
 * 必须显式 ENCRYPTION='Y'(对齐 users / local_life_reports 等已加密表)。
 * 到期清除见 nezha:purge-restaurant-reports(默认 180 天置空 description, 保留行供审计)。
 * L1-1: 全程不含任何资金字段, 平台不碰钱。
 */
class CreateRestaurantReportsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('restaurant_reports')) {
            Schema::create('restaurant_reports', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('restaurant_id')->index();
                $table->unsignedBigInteger('vendor_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('guest_id', 64)->nullable()->index();
                $table->string('reason', 60);
                $table->text('description')->nullable();
                $table->tinyInteger('status')->default(0)->index();
                $table->timestamps();
            });
            // 显式表空间加密(L1-7): 5.7 新表不继承全库加密, 必须手动开
            try {
                DB::statement("ALTER TABLE `restaurant_reports` ENCRYPTION='Y'");
            } catch (\Throwable $e) {
                // keyring 未就绪时不阻断建表; 加密态在收尾脚本复核
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('restaurant_reports');
    }
}
