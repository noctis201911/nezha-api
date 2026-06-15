<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 窗口④ UGC 发帖: 给 local_life_posts 加用户归属 / 图片 / 驳回理由。
 * - user_id: UGC 帖归属的顾客(admin 录入的为 null)
 * - images : json 数组, 存 Helpers::upload 返回的文件名(不存完整URL, 输出时再拼)
 * - reject_reason: 后台驳回时填的理由(对发帖用户在"我的发布"可见)
 * 状态值在 Model 层扩展: 0草稿 1已发布 2已下线 3待审核 4已驳回 (本表 status 仍是 tinyint, 无需改列)
 */
class AddUgcFieldsToLocalLifePosts extends Migration
{
    public function up()
    {
        Schema::table('local_life_posts', function (Blueprint $table) {
            if (!Schema::hasColumn('local_life_posts', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->index()->after('id');
            }
            if (!Schema::hasColumn('local_life_posts', 'images')) {
                $table->text('images')->nullable()->after('cover_color'); // json: ["local-life/xxx.webp", ...]
            }
            if (!Schema::hasColumn('local_life_posts', 'reject_reason')) {
                $table->string('reject_reason', 255)->nullable()->after('status');
            }
        });
    }

    public function down()
    {
        Schema::table('local_life_posts', function (Blueprint $table) {
            foreach (['user_id', 'images', 'reject_reason'] as $col) {
                if (Schema::hasColumn('local_life_posts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
}
