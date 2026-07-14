<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class NezhaMerchantMoneyIntegrityContractTest extends TestCase
{
    public function test_pos_and_customer_orders_use_server_authoritative_delivery_adjustments(): void
    {
        $root = dirname(__DIR__, 2);
        $vendorPos = file_get_contents($root.'/app/Http/Controllers/Vendor/POSController.php');
        $adminPos = file_get_contents($root.'/app/Http/Controllers/Admin/POSController.php');
        $placeOrder = file_get_contents($root.'/app/Traits/PlaceNewOrder.php');
        $customerOrder = file_get_contents($root.'/app/Http/Controllers/Api/V1/OrderController.php');

        $this->assertStringContainsString('DeliveryOptionLogic::resolve', $vendorPos);
        $this->assertStringContainsString('DeliveryOptionLogic::resolve', $adminPos);
        $this->assertStringContainsString('DeliveryOptionLogic::resolve', $placeOrder);
        $this->assertStringContainsString('DeliveryOptionLogic::resolve', $customerOrder);

        $this->assertStringNotContainsString("Session::put('delivery_type_charge', \$request->delivery_type_charge)", $vendorPos);
        $this->assertStringNotContainsString("Session::put('delivery_type_charge', \$request->delivery_type_charge)", $adminPos);
        $this->assertStringNotContainsString('abs($request->delivery_type_charge', $placeOrder);
        $this->assertStringContainsString("if (\$order->delivery_type === 'slightly_delay')", $placeOrder);
        $this->assertStringContainsString("if (\$order->delivery_type === 'slightly_delay')", $customerOrder);
    }

    public function test_final_pos_order_validates_cart_quantities_before_calculation(): void
    {
        $root = dirname(__DIR__, 2);
        $placeOrder = file_get_contents($root.'/app/Traits/PlaceNewOrder.php');

        $validation = strpos($placeOrder, 'PosOrderInput::validateCart($cart)');
        $calculation = strpos($placeOrder, '$this->makePosOrderDetails($cart, $restaurant)');

        $this->assertNotFalse($validation);
        $this->assertNotFalse($calculation);
        $this->assertLessThan($calculation, $validation);
    }

    public function test_pos_addon_prices_and_tenant_scope_are_resolved_on_the_server(): void
    {
        $root = dirname(__DIR__, 2);
        $vendorPos = file_get_contents($root.'/app/Http/Controllers/Vendor/POSController.php');
        $adminPos = file_get_contents($root.'/app/Http/Controllers/Admin/POSController.php');
        $placeOrder = file_get_contents($root.'/app/Traits/PlaceNewOrder.php');

        $this->assertStringNotContainsString('addon-price', $vendorPos);
        $this->assertStringNotContainsString('addon-price', $adminPos);
        $this->assertStringContainsString('PosOrderInput::resolveAddons', $vendorPos);
        $this->assertStringContainsString('PosOrderInput::resolveAddons', $adminPos);
        $this->assertStringContainsString('PosOrderInput::resolveAddons', $placeOrder);
    }
}
