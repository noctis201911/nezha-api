<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * 评价区 Phase2 (UGC 图审核 + 举报) 数据层
 * - reviews 加 reject_reason(驳回理由) + reviewed_at(审核时间)
 *   status 语义在应用层扩展: 1=已通过/公开(active 作用域) | 3=待审核 | 4=已驳回
 *   (active() 作用域只取 status=1, 故待审核/驳回的带图评价不公开)
 * - 新建 nezha_review_reports 举报表(对齐 local_life_reports), 显式加密(L1-7)
 */
class CreateReviewModerationTables extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('reviews', 'reject_reason')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->string('reject_reason', 255)->nullable()->after('status');
            });
        }
        if (!Schema::hasColumn('reviews', 'reviewed_at')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->dateTime('reviewed_at')->nullable()->after('reject_reason');
            });
        }

        if (!Schema::hasTable('nezha_review_reports')) {
            Schema::create('nezha_review_reports', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('review_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('reason', 60);
                $table->text('detail')->nullable();
                $table->tinyInteger('status')->default(0)->index(); // 0待处理 1已处理(下线评价) 2已驳回举报
                $table->timestamps();
            });
            // 显式表空间加密(L1-7): 5.7 新表不继承全库加密, detail 可能含 PII
            try {
                DB::statement("ALTER TABLE `nezha_review_reports` ENCRYPTION='Y'");
            } catch (\Throwable $e) {
                // keyring 未就绪不阻断建表; 加密态收尾复核
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('nezha_review_reports');
        if (Schema::hasColumn('reviews', 'reviewed_at')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->dropColumn('reviewed_at');
            });
        }
        if (Schema::hasColumn('reviews', 'reject_reason')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->dropColumn('reject_reason');
            });
        }
    }
}
