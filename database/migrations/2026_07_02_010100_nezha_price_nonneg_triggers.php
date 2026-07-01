<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒[数据完整性墙 W2 · 2026-07-01] 价格/数量非负 触发器墙。
 *
 * 定级 L3(实现细节)。审计(生产 sql_api_nezha_am)确认相关列当前 0 负值。
 *
 * 为什么用触发器, 不用 CHECK / UNSIGNED:
 *   - MySQL 5.7.43 解析 CHECK 但静默忽略(8.0.16+ 才执行) → CHECK 等于没写。
 *   - App 连接 strict=false(config/database.php:59) → 负数进 UNSIGNED 被静默钳成 0(coerce), 不拒绝。
 *   - 触发器 SIGNAL SQLSTATE '45000' 不受 sql_mode 影响 = 这套栈上唯一能"真拒绝"负价的机制。
 *   - 5.7 每表每(事件,时机)只允许一个触发器; 已核 DB 现 0 触发器, 无冲突。
 *
 * 守护列(BEFORE INSERT + BEFORE UPDATE, 负值即 SIGNAL, 拦真实写入路径):
 *   food(price, tax, discount)
 *   add_ons(price)
 *   variation_options(option_price)
 *   item_campaigns(price, tax, discount)
 *   order_details(price, tax_amount, total_add_on_price ≥0; discount_on_food ≥0 允空; quantity ≥1)
 * 未纳入(有意): orders 聚合金额(adjusment 合法可负 + 多为服务端派生); 钱包/余额流水(可负)。
 * 名字消毒/状态机等非"价格≥0"范畴, 见 W1/W3 及应用层。
 *
 * 不误伤既有写路径: 只改 price 之外字段的 UPDATE(如 food.order_count++)因 NEW.price=OLD.price≥0 照过。
 * 幂等: 先 DROP IF EXISTS 再 CREATE(重跑安全)。可逆: down() DROP 全部。
 */
return new class extends Migration
{
    private array $triggers = [
        'nz_food_price_bi', 'nz_food_price_bu',
        'nz_addons_price_bi', 'nz_addons_price_bu',
        'nz_varopt_price_bi', 'nz_varopt_price_bu',
        'nz_itemcamp_price_bi', 'nz_itemcamp_price_bu',
        'nz_orderdetails_price_bi', 'nz_orderdetails_price_bu',
    ];

    public function up(): void
    {
        foreach ($this->triggers as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$t}`");
        }

        $food = "IF NEW.price < 0 OR NEW.tax < 0 OR NEW.discount < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nezha wall: food price/tax/discount must be >= 0';
        END IF;";
        DB::unprepared("CREATE TRIGGER `nz_food_price_bi` BEFORE INSERT ON `food` FOR EACH ROW BEGIN {$food} END");
        DB::unprepared("CREATE TRIGGER `nz_food_price_bu` BEFORE UPDATE ON `food` FOR EACH ROW BEGIN {$food} END");

        $addon = "IF NEW.price < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nezha wall: add_ons.price must be >= 0';
        END IF;";
        DB::unprepared("CREATE TRIGGER `nz_addons_price_bi` BEFORE INSERT ON `add_ons` FOR EACH ROW BEGIN {$addon} END");
        DB::unprepared("CREATE TRIGGER `nz_addons_price_bu` BEFORE UPDATE ON `add_ons` FOR EACH ROW BEGIN {$addon} END");

        $varopt = "IF NEW.option_price < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nezha wall: variation_options.option_price must be >= 0';
        END IF;";
        DB::unprepared("CREATE TRIGGER `nz_varopt_price_bi` BEFORE INSERT ON `variation_options` FOR EACH ROW BEGIN {$varopt} END");
        DB::unprepared("CREATE TRIGGER `nz_varopt_price_bu` BEFORE UPDATE ON `variation_options` FOR EACH ROW BEGIN {$varopt} END");

        $camp = "IF NEW.price < 0 OR NEW.tax < 0 OR NEW.discount < 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nezha wall: item_campaigns price/tax/discount must be >= 0';
        END IF;";
        DB::unprepared("CREATE TRIGGER `nz_itemcamp_price_bi` BEFORE INSERT ON `item_campaigns` FOR EACH ROW BEGIN {$camp} END");
        DB::unprepared("CREATE TRIGGER `nz_itemcamp_price_bu` BEFORE UPDATE ON `item_campaigns` FOR EACH ROW BEGIN {$camp} END");

        $od = "IF NEW.price < 0 OR NEW.tax_amount < 0 OR NEW.total_add_on_price < 0
                OR (NEW.discount_on_food IS NOT NULL AND NEW.discount_on_food < 0)
                OR NEW.quantity < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nezha wall: order_details price/tax/addon >=0 & quantity >=1';
        END IF;";
        DB::unprepared("CREATE TRIGGER `nz_orderdetails_price_bi` BEFORE INSERT ON `order_details` FOR EACH ROW BEGIN {$od} END");
        DB::unprepared("CREATE TRIGGER `nz_orderdetails_price_bu` BEFORE UPDATE ON `order_details` FOR EACH ROW BEGIN {$od} END");
    }

    public function down(): void
    {
        foreach ($this->triggers as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$t}`");
        }
    }
};
