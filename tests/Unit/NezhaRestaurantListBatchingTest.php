<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\OrderController;
use App\Models\Restaurant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NezhaRestaurantListBatchingTest extends TestCase
{
    public function test_deposit_context_preserves_switch_threshold_and_store_semantics_without_queries(): void
    {
        $restaurant = new Restaurant;
        $restaurant->forceFill([
            'vendor_id' => 7,
            'nezha_commission_enabled' => 1,
            'nezha_temp_closed' => 0,
            'offboard_status' => 'active',
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertFalse(OrderController::nezha_store_paused($restaurant, [
            'mode' => 0,
            'threshold' => 100,
            'balance' => 0,
        ]));
        $this->assertTrue(OrderController::nezha_store_paused($restaurant, [
            'mode' => 1,
            'threshold' => 100,
            'balance' => 100,
        ]));
        $this->assertFalse(OrderController::nezha_store_paused($restaurant, [
            'mode' => 1,
            'threshold' => 100,
            'balance' => 100.01,
        ]));

        $restaurant->nezha_commission_enabled = 0;
        $this->assertFalse(OrderController::nezha_store_paused($restaurant, [
            'mode' => 1,
            'threshold' => 100,
            'balance' => 0,
        ]));

        $restaurant->nezha_commission_enabled = 1;
        $restaurant->nezha_temp_closed = 1;
        $this->assertTrue(OrderController::nezha_store_paused($restaurant, [
            'mode' => 0,
            'threshold' => 100,
            'balance' => 1000,
        ]));
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_multi_restaurant_formatter_contains_no_relationship_queries_in_its_item_loop(): void
    {
        $source = file_get_contents(base_path('app/CentralLogics/Helpers.php'));
        $methodStart = strpos($source, 'public static function restaurant_data_formatting');
        $multiStart = strpos($source, 'if ($multi_data == true)', $methodStart);
        $singleStart = strpos($source, '} else {', $multiStart);
        $multiBranch = substr($source, $multiStart, $singleStart - $multiStart);

        $this->assertStringContainsString('prepareRestaurantListFormattingData', $multiBranch);
        $this->assertDoesNotMatchRegularExpression('/\$item->foods\(\)/', $multiBranch);
        $this->assertDoesNotMatchRegularExpression('/\$item->reviews\(\)/', $multiBranch);
        $this->assertDoesNotMatchRegularExpression('/\$item->characteristics\(\)/', $multiBranch);
        $this->assertDoesNotMatchRegularExpression('/Coupon::/', $multiBranch);
        $this->assertDoesNotMatchRegularExpression('/BusinessSetting::/', $multiBranch);
        $this->assertDoesNotMatchRegularExpression('/RestaurantWallet::/', $multiBranch);
        $this->assertStringContainsString("restaurant_model === 'subscription'", $source);
        $this->assertStringContainsString("->load('restaurant_sub')", $source);
        $this->assertStringContainsString('nz_delivery_fee_business_settings', $source);
        $this->assertStringContainsString('nz_active_vehicles_', $source);
    }
}
