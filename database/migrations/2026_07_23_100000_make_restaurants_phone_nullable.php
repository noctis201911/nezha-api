<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 餐厅电话字段改为可留空(原 NOT NULL + UNIQUE)。
 * 目的:挂牌占位店(运营代建、无真实商家电话)的假电话清空,
 *      前端「有电话才显示」逻辑据此自动不显示;真号店照常显示。
 * UNIQUE 约束保留(MySQL 唯一索引允许多个 NULL,不冲突)。
 */
return new class extends Migration
{
    private array $placeholders = [
        13 => '+37491000007',
        28 => '+37499000101',
        29 => '+37499000102',
        35 => '+37499000103',
    ];

    public function up(): void
    {
        DB::statement("ALTER TABLE `restaurants` MODIFY `phone` VARCHAR(20) NULL DEFAULT NULL");

        // 清空 4 家挂牌占位店的假电话:id + 现值双校验,防误改(若已非占位则跳过)。
        foreach ($this->placeholders as $id => $ph) {
            DB::table('restaurants')->where('id', $id)->where('phone', $ph)->update(['phone' => null]);
        }
    }

    public function down(): void
    {
        // 回滚:先把清空的恢复成占位电话,再改回 NOT NULL(有 NULL 时无法转 NOT NULL)。
        foreach ($this->placeholders as $id => $ph) {
            DB::table('restaurants')->where('id', $id)->whereNull('phone')->update(['phone' => $ph]);
        }
        DB::statement("ALTER TABLE `restaurants` MODIFY `phone` VARCHAR(20) NOT NULL");
    }
};
