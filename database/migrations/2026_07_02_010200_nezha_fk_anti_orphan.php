<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒[数据完整性墙 W1 · 2026-07-01] 外键防孤儿。
 *
 * 定级 L3(表结构)。审计(生产)确认相关关系 0 孤儿。全库原 0 外键(StackFood 设计)。
 * 外键不受 sql_mode 影响, 是"防孤儿"的精确机制。
 *
 * 三条 FK(均 ON DELETE RESTRICT ON UPDATE CASCADE):
 *   1. order_details.order_id  -> orders.id       (防孤儿行项; order_id 可空, NULL 免约束)
 *   2. food.restaurant_id      -> restaurants.id  (防菜品指向已删店)
 *   3. orders.restaurant_id    -> restaurants.id  (防订单指向已删店, 顺带保护 L1-4 订单留存)
 *
 * RESTRICT 选型理由:
 *   - 订单永不硬删(全码审计: 无 Order::delete, 仅 order-edit 删子行 detail 再重建), 故 FK1/FK3 对下单/改单
 *     零影响; 只在"删餐厅"时把原本静默产生孤儿的行为改为"挡住删除"——符合留存与完整性。
 *   - orders.restaurant_id NOT NULL 无法 SET NULL; 订单有留存义务不能 CASCADE 删 => RESTRICT 唯一正确。
 *   ⚠️ 后果: 有订单/菜品的餐厅将不能被 admin 一键删除(VendorController::destroy 的裸 $restaurant->delete()
 *      会抛 FK 异常)。删店本就是低频动作, 且删掉带订单历史的店本不应允许。删店 UX 的优雅化(先清菜品/
 *      对有订单的店给友好提示)在配套 controller 改动处理, 与本墙分开评审。
 *
 * 幂等: 存在即跳过。可逆: down() DROP 三条 FK(注意 food 的 FK 会连带其自动索引, MySQL 自动处理)。
 */
return new class extends Migration
{
    private array $fks = [
        // table, constraint_name, column, ref_table
        ['order_details', 'nz_fk_od_order', 'order_id', 'orders'],
        ['food', 'nz_fk_food_restaurant', 'restaurant_id', 'restaurants'],
        ['orders', 'nz_fk_orders_restaurant', 'restaurant_id', 'restaurants'],
    ];

    private function hasFk(string $table, string $name): bool
    {
        return collect(DB::select(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$table, $name]
        ))->isNotEmpty();
    }

    public function up(): void
    {
        foreach ($this->fks as [$table, $name, $col, $ref]) {
            if (! $this->hasFk($table, $name)) {
                DB::statement("ALTER TABLE `{$table}`
                    ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$col}`) REFERENCES `{$ref}`(`id`)
                    ON DELETE RESTRICT ON UPDATE CASCADE");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->fks as [$table, $name, $col, $ref]) {
            if ($this->hasFk($table, $name)) {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
            }
        }
    }
};
