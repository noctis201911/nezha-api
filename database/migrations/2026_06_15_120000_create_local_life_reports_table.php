<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 窗口⑤ UGC 加固: 顾客举报表。
 * - post_id : 被举报的帖子(local_life_posts.id)
 * - user_id : 举报人(auth:api，禁匿名，故非空场景下有值；保留 nullable 防历史/异常)
 * - reason  : 举报理由(白名单文案，见 LocalLifeController::REPORT_REASONS)
 * - detail  : 补充说明(reason=其他时必填)。可能含联系方式 → 视为 PII。
 * - status  : 0待处理 1已处理(已下线) 2驳回
 *
 * L1-7 PII: detail 可能含 PII。MySQL 5.7 下新建 InnoDB 表【不会】自动继承表空间加密，
 * 必须显式 ENCRYPTION='Y'(对齐 users 等已加密表)。同时本迁移顺手补加密 local_life_posts
 * (窗口④建表时未带 ENCRYPTION，contact_info 此前为明文落盘 —— 见 docs/compliance/CHANGELOG)。
 */
class CreateLocalLifeReportsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('local_life_reports')) {
            Schema::create('local_life_reports', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('post_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('reason', 60);
                $table->text('detail')->nullable();
                $table->tinyInteger('status')->default(0)->index();
                $table->timestamps();
            });
            // 显式表空间加密(L1-7)：5.7 新表不继承全库加密，必须手动开
            try {
                DB::statement("ALTER TABLE `local_life_reports` ENCRYPTION='Y'");
            } catch (\Throwable $e) {
                // keyring 未就绪时不阻断建表；加密状态在收尾脚本里复核
            }
        }

        // 补加密 local_life_posts(含 contact_info PII，窗口④建表漏了 ENCRYPTION)
        try {
            $row = DB::selectOne("SELECT CREATE_OPTIONS co FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='local_life_posts'");
            if ($row && stripos($row->co ?? '', 'ENCRYPTION') === false) {
                DB::statement("ALTER TABLE `local_life_posts` ENCRYPTION='Y'");
            }
        } catch (\Throwable $e) {
            // 静默：keyring 异常不应阻断迁移；收尾脚本复核加密态
        }
    }

    public function down()
    {
        Schema::dropIfExists('local_life_reports');
        // 不回退 local_life_posts 的加密(回退=削弱 L1-7，禁止)
    }
}
