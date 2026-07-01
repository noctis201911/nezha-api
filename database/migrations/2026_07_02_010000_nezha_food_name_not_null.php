<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒[数据完整性墙 W4 · 2026-07-01] food.name 补 NOT NULL。
 *
 * 定级 L3(表结构/实现细节, 见 INVARIANTS.md)。审计(生产 sql_api_nezha_am, 80 行 food):
 * name 现 0 空(NULL 或 '')。菜品无名是明确脏数据。
 *
 * 意义: 标注 schema 意图 + 挡「显式塞 NULL」。
 * ⚠️ 本站 App 连接 strict=false(config/database.php:59) -> 仅拦显式 NULL; 省略列在非严格
 *    模式会被 coerce 成 ''(仍非 NULL)。真正"名字非空/去控制字符"应用层校验另做, 非本墙职责。
 *
 * 幂等: 仅当当前可空时才 MODIFY(重跑安全)。可逆: down() 还原为可空。
 */
return new class extends Migration
{
    public function up(): void
    {
        $col = collect(DB::select(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'food' AND COLUMN_NAME = 'name'"
        ))->first();

        if ($col && $col->IS_NULLABLE === 'YES') {
            // 加墙前最后一道自检: 若存在 NULL 名(审计为 0), 先回填占位, 避免非严格模式静默转 '' 丢失可见性
            DB::statement("UPDATE `food` SET `name` = '未命名商品' WHERE `name` IS NULL");
            DB::statement("ALTER TABLE `food` MODIFY `name` VARCHAR(191) COLLATE utf8mb4_unicode_ci NOT NULL");
        }
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `food` MODIFY `name` VARCHAR(191) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL");
    }
};
