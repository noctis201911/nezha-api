<?php

namespace Tests\Feature;

use App\CentralLogics\OrderLogic;
use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 哪吒 B方案/组4 — 「平台应收佣金」只读展示计算 OrderLogic::nezha_commissionable_amount() 测试。
 * 锁定该方法与 create_transaction() 佣金公式一致: 佣金 = 净商品额(计佣基数) × 费率。
 * 🔴 本仓 APP_ENV=testing 仍连生产库: 仅 DatabaseTransactions(回滚) + 不入库 Order/Restaurant 实例, 零写入。
 */
class NezhaCommissionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSetting('admin_commission', '10'); // 全局费率固定10%(测完随事务回滚)
    }

    private function setSetting(string $key, $value): void
    {
        if (DB::table('business_settings')->where('key', $key)->exists()) {
            DB::table('business_settings')->where('key', $key)->update(['value' => $value]);
        } else {
            DB::table('business_settings')->insert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** 不入库订单 + 不入库餐馆。$comission=null 用全局费率; $model 餐馆模式; $hasSub 是否订阅。 */
    private function mkOrder(array $fields, $comission = null, string $model = 'commission', bool $hasSub = false): Order
    {
        $rest = new Restaurant();
        $rest->comission = $comission;
        $rest->restaurant_model = $model;
        $rest->setRelation('restaurant_sub', $hasSub ? new Restaurant() : null);

        $o = new Order();
        $defaults = [
            'order_amount' => 0, 'additional_charge' => 0, 'extra_packaging_amount' => 0, 'delivery_charge' => 0,
            'total_tax_amount' => 0, 'dm_tips' => 0, 'delivery_type_charge' => 0, 'coupon_discount_amount' => 0,
            'restaurant_discount_amount' => 0, 'ref_bonus_amount' => 0, 'discount_on_product_by' => null, 'delivery_type' => 'standard',
        ];
        foreach (array_merge($defaults, $fields) as $k => $v) {
            $o->{$k} = $v;
        }
        $o->setRelation('restaurant', $rest);
        return $o;
    }

    public function test_simple_order_10_percent(): void
    {
        $r = OrderLogic::nezha_commissionable_amount($this->mkOrder(['order_amount' => 3800]));
        $this->assertEqualsWithDelta(3800, $r['base'], 0.01);
        $this->assertEqualsWithDelta(10, $r['rate'], 0.01);
        $this->assertEqualsWithDelta(380, $r['amount'], 0.01);
        $this->assertFalse($r['subscription']);
    }

    public function test_fees_tax_tips_excluded_from_base(): void
    {
        // 5000 - 500(配送) - 200(税) - 100(小费) - 50(打包) = 4150
        $r = OrderLogic::nezha_commissionable_amount($this->mkOrder([
            'order_amount' => 5000, 'delivery_charge' => 500, 'total_tax_amount' => 200, 'dm_tips' => 100, 'extra_packaging_amount' => 50,
        ]));
        $this->assertEqualsWithDelta(4150, $r['base'], 0.01);
        $this->assertEqualsWithDelta(415, $r['amount'], 0.01);
    }

    public function test_coupon_added_back_to_base(): void
    {
        // order_amount 已扣券(2700), 佣金基数加回券300 = 3000
        $r = OrderLogic::nezha_commissionable_amount($this->mkOrder([
            'order_amount' => 2700, 'coupon_discount_amount' => 300,
        ]));
        $this->assertEqualsWithDelta(3000, $r['base'], 0.01);
        $this->assertEqualsWithDelta(300, $r['amount'], 0.01);
    }

    public function test_admin_funded_discount_added_back_vendor_not(): void
    {
        $admin = OrderLogic::nezha_commissionable_amount($this->mkOrder([
            'order_amount' => 2600, 'restaurant_discount_amount' => 400, 'discount_on_product_by' => 'admin',
        ]));
        $this->assertEqualsWithDelta(3000, $admin['base'], 0.01); // admin 出资折扣加回 2600+400

        $vendor = OrderLogic::nezha_commissionable_amount($this->mkOrder([
            'order_amount' => 2600, 'restaurant_discount_amount' => 400, 'discount_on_product_by' => 'vendor',
        ]));
        $this->assertEqualsWithDelta(2600, $vendor['base'], 0.01); // vendor 出资折扣不加回
    }

    public function test_restaurant_custom_rate_overrides_global(): void
    {
        $r = OrderLogic::nezha_commissionable_amount($this->mkOrder(['order_amount' => 1000], 15));
        $this->assertEqualsWithDelta(15, $r['rate'], 0.01);
        $this->assertEqualsWithDelta(150, $r['amount'], 0.01);
    }

    public function test_express_subtracts_type_charge_twice(): void
    {
        // 4000 - 200(基数内) - 200(express 再扣) = 3600 (镜像引擎行为)
        $r = OrderLogic::nezha_commissionable_amount($this->mkOrder([
            'order_amount' => 4000, 'delivery_type' => 'express', 'delivery_type_charge' => 200,
        ]));
        $this->assertEqualsWithDelta(3600, $r['base'], 0.01);
    }

    public function test_subscription_restaurant_zero_commission(): void
    {
        $r = OrderLogic::nezha_commissionable_amount($this->mkOrder(['order_amount' => 5000], null, 'subscription', true));
        $this->assertTrue($r['subscription']);
        $this->assertEqualsWithDelta(0, $r['amount'], 0.01);
    }

    public function test_slightly_delay_addss_type_charge_back(): void
    {
        // 4000 - 200(基数内) + 200(slightly_delay 加回) = 4000 (镜像引擎)
        $r = OrderLogic::nezha_commissionable_amount($this->mkOrder([
            'order_amount' => 4000, 'delivery_type' => 'slightly_delay', 'delivery_type_charge' => 200,
        ]));
        $this->assertEqualsWithDelta(4000, $r['base'], 0.01);
    }

    public function test_ref_bonus_added_back_to_base(): void
    {
        // 首单返 ref_bonus 加回基数: order_amount 2900 + 100 = 3000
        $r = OrderLogic::nezha_commissionable_amount($this->mkOrder([
            'order_amount' => 2900, 'ref_bonus_amount' => 100,
        ]));
        $this->assertEqualsWithDelta(3000, $r['base'], 0.01);
        $this->assertEqualsWithDelta(300, $r['amount'], 0.01);
    }

    /** 结构性同源断言: 引擎 create_transaction 必须委托给唯一公式源 nezha_commissionable_amount, 防两份公式漂移。 */
    public function test_engine_create_transaction_delegates_to_pure_function(): void
    {
        $rm = new \ReflectionMethod(OrderLogic::class, 'create_transaction');
        $lines = file($rm->getFileName());
        $body = implode('', array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1));
        $this->assertStringContainsString('nezha_commissionable_amount(', $body,
            '引擎必须调用 nezha_commissionable_amount() 单一公式源, 否则显示≠实扣会复活');
    }
}
