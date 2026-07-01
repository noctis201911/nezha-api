<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 哪吒[拖拽排序] food 加 nezha_order_column = 商家自定义「陈列顺序」(分类内排序, 无符号 int)。
// null = 未排序(退化为 created_at DESC = 今天的默认序, 向后兼容); 有值 = 该分类内自定义序号(升序), 排在未排序菜之前。
// 仅在顾客端「默认/综合」排序档生效; 顾客切快送/A-Z/Z-A 时被 applySorting()->reorder() 覆盖。默认 null -> 现有菜品不受影响。
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('food', 'nezha_order_column')) {
            Schema::table('food', function (Blueprint $table) {
                $table->unsignedInteger('nezha_order_column')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('food', 'nezha_order_column')) {
            Schema::table('food', function (Blueprint $table) {
                $table->dropColumn('nezha_order_column');
            });
        }
    }
};
