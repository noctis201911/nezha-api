<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒[分类排序] restaurants 加 nezha_category_order = 本店自定义「分类先后顺序」(JSON: 分类id数组)。
// null/空 = 用平台默认分类序(向后兼容零影响)。顾客端菜单分区 + 分类导航条按此序展示; 商家在「菜品排序」页拖分类调整。
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('restaurants', 'nezha_category_order')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->text('nezha_category_order')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('restaurants', 'nezha_category_order')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->dropColumn('nezha_category_order');
            });
        }
    }
};
