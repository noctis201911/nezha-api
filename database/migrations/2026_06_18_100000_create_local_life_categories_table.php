<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 本地生活「类目」表：把原本写死在前后端的类目常量改成后台可管理的数据。
 * 运营可在后台「本地生活类目」页增/删/改/排序；商家发帖/后台建帖从此表选类目；
 * 前端金刚区(4×2 格子)按 status=1 的类目动态渲染，加新类目无需改代码。
 */
class CreateLocalLifeCategoriesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('local_life_categories')) {
            return; // 幂等：已存在则跳过（多窗口共享工作目录防重复建）
        }
        Schema::create('local_life_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();        // 类目名（同时是 local_life_posts.category 的取值）
            $table->string('emoji', 16)->nullable();     // 金刚区图标 emoji，如 🏠
            $table->string('color', 40)->nullable();     // 图标底色，如 #EAF1FF
            $table->string('tab', 20)->default('推荐');   // 归属前端粗筛频道（推荐/租房/招聘/二手/免费/服务）
            $table->unsignedInteger('sort_order')->default(0); // 金刚区排序，越小越靠前
            $table->boolean('is_sensitive')->default(false);   // 敏感类目(移民/签证/按摩)→后台标红提示+默认人工审核
            $table->boolean('status')->default(true);    // 1=启用(前端可见) 0=停用(隐藏，不影响已发帖)
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('local_life_categories');
    }
}
