<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒[数据完整性墙 W3(宽松版) · 2026-07-01] orders 状态字段非空白 触发器。
 *
 * 定级 L3。为什么是宽松版而非严格词表 ENUM/白名单:
 *   代码审计(2026-07-01)发现 order_status 跨 6+ 端点「动态从 $request->status 写入」
 *   (Vendor/Deliveryman/Admin/OrderSubscription 各控制器), 合法值 ≥13 个且散落——含极易漏的
 *   Admin/OrderController:925 条件设的 'accepted'、OrderSubscription 的 'paused';
 *   且 Api/V1/Vendor/VendorController:301 有未加 in: 约束的 'status' 写入端点。
 *   => 严格 DB 词表墙 = 高破坏风险(漏一个合法值即拒掉该端点正当写入, 骑手/商家/订阅流程炸)
 *      × 低价值(order_status 无匿名顾客输入面, 写入方是已认证商家/骑手/管理员经多数已校验端点)。
 *   完整词表合法性的正确防线 = 应用层 in: 校验(StackFood 多数端点已有)。
 *
 * 本墙只拦「明确脏态」: order_status / payment_status 为空白 ''(coerce 或 bug 才产生, 无合法流程写空白)。
 *   NOT NULL DEFAULT 已挡 NULL/省略(默认 pending/unpaid, 先于 BEFORE 触发器应用, 不误伤省略写)。
 *
 * 5.7 每表每(事件,时机)一个触发器: orders 表原无触发器(W2 只加在 food/add_ons/varopt/itemcamp/order_details)。
 * 幂等: 先 DROP IF EXISTS。可逆: down() DROP。
 */
return new class extends Migration
{
    private array $triggers = ['nz_orders_status_bi', 'nz_orders_status_bu'];

    public function up(): void
    {
        foreach ($this->triggers as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$t}`");
        }

        $body = "IF TRIM(COALESCE(NEW.order_status, '')) = '' OR TRIM(COALESCE(NEW.payment_status, '')) = '' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'nezha wall: orders order_status/payment_status must not be blank';
        END IF;";

        DB::unprepared("CREATE TRIGGER `nz_orders_status_bi` BEFORE INSERT ON `orders` FOR EACH ROW BEGIN {$body} END");
        DB::unprepared("CREATE TRIGGER `nz_orders_status_bu` BEFORE UPDATE ON `orders` FOR EACH ROW BEGIN {$body} END");
    }

    public function down(): void
    {
        foreach ($this->triggers as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$t}`");
        }
    }
};
