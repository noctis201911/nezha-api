<?php

namespace Tests\Unit;

use App\CentralLogics\DeliveryOptionLogic;
use App\CentralLogics\PosOrderInput;
use PHPUnit\Framework\TestCase;

class NezhaMerchantMoneyIntegrityTest extends TestCase
{
    public function test_delivery_adjustment_comes_from_the_zone_option(): void
    {
        $zone = $this->zone([
            $this->option(11, 'express', extraCharge: 7),
            $this->option(12, 'slightly_delay', reduceCharge: 9),
        ]);

        $this->assertSame([
            'delivery_type' => 'express',
            'delivery_type_charge' => 7.0,
        ], DeliveryOptionLogic::resolve($zone, 'express', 40));

        $this->assertSame([
            'delivery_type' => 'slightly_delay',
            'delivery_type_charge' => 9.0,
        ], DeliveryOptionLogic::resolve($zone, 'slightly_delay', 40));
    }

    public function test_delay_reduction_cannot_exceed_the_delivery_charge(): void
    {
        $zone = $this->zone([$this->option(12, 'slightly_delay', reduceCharge: 999)]);

        $this->assertSame([
            'delivery_type' => 'slightly_delay',
            'delivery_type_charge' => 25.0,
        ], DeliveryOptionLogic::resolve($zone, 'slightly_delay', 25));
    }

    public function test_unknown_disabled_or_ineligible_delivery_options_are_ignored(): void
    {
        $zone = $this->zone([$this->option(11, 'express', extraCharge: 7)]);

        $this->assertSame($this->noDeliveryAdjustment(), DeliveryOptionLogic::resolve($zone, 999, 40));
        $this->assertSame($this->noDeliveryAdjustment(), DeliveryOptionLogic::resolve($this->zone([], false), 'express', 40));
        $this->assertSame($this->noDeliveryAdjustment(), DeliveryOptionLogic::resolve($zone, 'express', 0));
        $this->assertSame($this->noDeliveryAdjustment(), DeliveryOptionLogic::resolve($this->zone([$this->option(11, 'express', extraCharge: 7)], true, 50), 'express', 40));
    }

    public function test_pos_cart_rejects_non_positive_fractional_and_oversized_quantities(): void
    {
        $this->assertSame('quantity', PosOrderInput::validateCart([['quantity' => -1, 'add_ons' => [], 'add_on_qtys' => []]]));
        $this->assertSame('quantity', PosOrderInput::validateCart([['quantity' => '1.5', 'add_ons' => [], 'add_on_qtys' => []]]));
        $this->assertSame('quantity', PosOrderInput::validateCart([['quantity' => 100, 'add_ons' => [], 'add_on_qtys' => []]]));
    }

    public function test_pos_cart_rejects_invalid_addon_quantities_but_accepts_valid_cart_metadata(): void
    {
        $this->assertSame('addon_quantity', PosOrderInput::validateCart([['quantity' => 1, 'add_ons' => [4], 'add_on_qtys' => [-2]]]));
        $this->assertSame('addon_quantity', PosOrderInput::validateCart([['quantity' => 1, 'add_ons' => [4, 5], 'add_on_qtys' => [1]]]));
        $this->assertSame('addon_quantity', PosOrderInput::validateCart([['quantity' => 1, 'add_ons' => [4, 4], 'add_on_qtys' => [1, 2]]]));
        $this->assertNull(PosOrderInput::validateCart([
            ['quantity' => '2', 'add_ons' => [4, 5], 'add_on_qtys' => ['1', 3]],
            'paid' => 100,
            'restaurant_id' => 6,
        ]));
    }

    private function zone(array $options, bool $enabled = true, float $minimumCharge = 0): object
    {
        return (object) [
            'additional_delivery_option_status' => $enabled,
            'minimum_delivery_charge' => $minimumCharge,
            'deliveryOptions' => $options,
        ];
    }

    private function option(int $id, string $type, float $extraCharge = 0, float $reduceCharge = 0): object
    {
        return (object) [
            'id' => $id,
            'delivery_type' => $type,
            'extra_charge' => $extraCharge,
            'reduce_charge' => $reduceCharge,
        ];
    }

    private function noDeliveryAdjustment(): array
    {
        return ['delivery_type' => null, 'delivery_type_charge' => 0.0];
    }
}
