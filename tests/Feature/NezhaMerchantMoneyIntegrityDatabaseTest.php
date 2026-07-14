<?php

namespace Tests\Feature;

use App\CentralLogics\PosOrderInput;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NezhaMerchantMoneyIntegrityDatabaseTest extends TestCase
{
    public function test_pos_addons_are_priced_and_scoped_by_the_current_restaurant(): void
    {
        DB::table('add_ons')->insert([
            [
                'id' => 901,
                'name' => 'Current restaurant addon',
                'price' => 3.50,
                'restaurant_id' => 10,
                'status' => 1,
                'stock_type' => 'unlimited',
                'addon_stock' => 0,
                'sell_count' => 0,
            ],
            [
                'id' => 902,
                'name' => 'Foreign restaurant addon',
                'price' => 99.00,
                'restaurant_id' => 20,
                'status' => 1,
                'stock_type' => 'unlimited',
                'addon_stock' => 0,
                'sell_count' => 0,
            ],
            [
                'id' => 903,
                'name' => 'Inactive addon',
                'price' => 1.00,
                'restaurant_id' => 10,
                'status' => 0,
                'stock_type' => 'unlimited',
                'addon_stock' => 0,
                'sell_count' => 0,
            ],
        ]);

        $resolved = PosOrderInput::resolveAddons(10, [901], ['2']);

        $this->assertSame(7.0, $resolved['total_add_on_price']);
        $this->assertSame([
            ['id' => 901, 'name' => 'Current restaurant addon', 'price' => 3.5, 'quantity' => 2],
        ], $resolved['addons']);
        $this->assertNull(PosOrderInput::resolveAddons(10, [902], [1]));
        $this->assertNull(PosOrderInput::resolveAddons(10, [903], [1]));
    }
}
