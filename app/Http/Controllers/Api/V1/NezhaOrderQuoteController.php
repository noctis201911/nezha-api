<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;
use App\CentralLogics\Helpers;
use App\CentralLogics\CouponLogic;
use App\Models\Cart;
use App\Models\Food;
use App\Models\ItemCampaign;
use App\Models\Restaurant;
use App\Models\AddOn;

// 哪吒[多级满减·Phase4] 结算「满减/券 取更优」只读预览端点。
//   触点3 结算满减单列要显"本单最终减多少 / 满减 vs 券谁赢"——这是 place_order 用【非对称基数】(券含加料·满减不含加料)
//   现算的决策, 前端无法可靠复现。本端点复用 place_order 完全相同的口径与原语(getTieredDiscount / CouponLogic::get_discount /
//   coupon_check / calculate_addon_price / get_varient / food_discount_calculate)只读现算返回, 前端只渲染不复算金钱逻辑。
//   取优规则与 OrderController::place_order 券取优段同源, 由 tests/Feature/NezhaTieredQuoteParityTest 锁死等价
//   (place_order 改取优而不同步本端点 → 测试红)。
//   ⛔ 只读: 不建单 / 不落库 / 不增库存 / 不发通知。灰度 nezha_tiered_discount_status 关时 getTieredDiscount 返回 null
//   → tiered 恒 inert(prod 不可见, 与下单路径一致)。
class NezhaOrderQuoteController extends Controller
{
    public function quote(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'restaurant_id' => 'required',
            'guest_id' => $request->user ? 'nullable' : 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $restaurant = Restaurant::find($request->restaurant_id);
        if (!$restaurant) {
            return response()->json(['errors' => [['code' => 'restaurant', 'message' => translate('messages.restaurant_not_found')]]], 404);
        }

        $owner_id = $request->user ? $request->user->id : $request->guest_id;
        $is_guest = $request->user ? 0 : 1;

        // 与 place_order 同一车口径: 只取本店车项(多店购物车不混算)
        $carts = Cart::where('user_id', $owner_id)->where('is_guest', $is_guest)
            ->where('restaurant_id', $request['restaurant_id'])
            ->get()->map(function ($data) {
                $data->add_on_ids = (is_string($data->add_on_ids) ? json_decode($data->add_on_ids, true) : $data->add_on_ids) ?? [];
                $data->add_on_qtys = (is_string($data->add_on_qtys) ? json_decode($data->add_on_qtys, true) : $data->add_on_qtys) ?? [];
                $data->variations = is_string($data->variations) ? json_decode($data->variations, true) : $data->variations;
                return $data;
            });

        $digits = config('round_up_to_digit');

        $product_price = 0;
        $total_addon_price = 0;
        $food_discount_sum = 0;

        foreach ($carts as $c) {
            if ($c['item_type'] === 'App\Models\ItemCampaign' || $c['item_type'] === 'AppModelsItemCampaign') {
                $product = ItemCampaign::active()->find($c['item_id']);
            } else {
                $product = Food::active()->find($c['item_id']);
            }
            if (!$product || $product->restaurant_id != $request['restaurant_id']) {
                continue;
            }

            $qty = (int) data_get($c, 'quantity', 1);
            if ($qty < 1) {
                $qty = 1;
            }

            $addon_data = Helpers::calculate_addon_price(
                addons: AddOn::whereIn('id', (gettype($c['add_on_ids']) == 'array' ? $c['add_on_ids'] : json_decode($c['add_on_ids'], true)) ?? [])->get(),
                add_on_qtys: (gettype($c['add_on_qtys']) == 'array' ? $c['add_on_qtys'] : json_decode($c['add_on_qtys'], true)) ?? []
            );

            $product_variations = json_decode($product->variations, true);
            if (is_array($product_variations) && count($product_variations)) {
                $variation_data = Helpers::get_varient($product_variations, isset($c['variations']) ? $c['variations'] : []);
                $price = $product['price'] + $variation_data['price'];
            } else {
                $price = $product['price'];
            }

            // food 自身折扣(单品·与满减独立)。与 place_order 同一算法, 供无满减命中时的商品级折扣基线。
            $fmt = Helpers::product_data_formatting(data: $product, multi_data: false, trans: false, local: app()->getLocale(), maxDiscount: false);
            $pd = Helpers::food_discount_calculate($fmt, $price, $restaurant, false);

            $product_price += $price * $qty;
            $total_addon_price += $addon_data['total_add_on_price'];
            $food_discount_sum += (data_get($pd, 'discount_amount', 0)) * $qty;
        }

        if ($product_price <= 0) {
            return response()->json($this->emptyQuote());
        }

        // 券(可选): 复用 place_order 同一 coupon_check(过期/归属/限领/门槛)。无效则软标记, 不 403。
        $coupon = null;
        $coupon_invalid = null;
        if ($request['coupon_code']) {
            $coupon_check = Helpers::coupon_check(coupon_code: $request['coupon_code'], restaurant_id: $restaurant->id, user_id: $request?->user?->id, is_guest: $request->user ? false : true);
            if (data_get($coupon_check, 'code') === 'coupon') {
                $coupon_invalid = data_get($coupon_check, 'message');
            } else {
                $coupon = data_get($coupon_check, 'coupon');
            }
        }

        // 商品级折扣: 满减(vendor·灰度门内)覆盖单品 food 折扣; 无满减走 legacy admin(生产空)或 food 折扣。同 place_order:431-485
        $tiered = Helpers::getTieredDiscount($restaurant, $product_price);
        $tiered_would_be = $tiered ? $tiered['discount_amount'] : 0;
        $product_discount = $food_discount_sum;
        if ($tiered) {
            $product_discount = $tiered['discount_amount'];
        } else {
            $restaurantDiscount = Helpers::get_restaurant_discount($restaurant);
            if (isset($restaurantDiscount)) {
                $product_discount = Helpers::checkAdminDiscount(price: $product_price, discount: $restaurantDiscount['discount'], max_discount: $restaurantDiscount['max_discount'], min_purchase: $restaurantDiscount['min_purchase']);
            }
        }

        // 券 vs 满减「取更优」不叠加。券基数=商品+加料(不含满减) · 满减基数=商品(不含加料), 非对称。同 place_order 券取优段。
        $winner = $tiered ? 'tiered' : 'none';
        if ($tiered && $coupon) {
            $coupon_basis_no_tiered = $product_price + $total_addon_price;
            $coupon_if_win = ($coupon->min_purchase > 0 && $coupon_basis_no_tiered < $coupon->min_purchase)
                ? 0
                : CouponLogic::get_discount(coupon: $coupon, order_amount: $coupon_basis_no_tiered);
            if ($coupon_if_win > $tiered['discount_amount']) {
                $tiered = null;
                $product_discount = 0; // 满减让位; food 折扣此前已被满减覆盖 → 同 place_order 一并归零
                $winner = 'coupon';
            } else {
                $coupon = null; // 券让位
                $winner = 'tiered';
            }
        } elseif (!$tiered && $coupon) {
            $winner = 'coupon';
        }

        // 券实减额: 基数=商品+加料-商品级折扣(同 place_order:526 nezha_coupon_basis)。不达门槛软标记(前端提示), 不 403。
        $coupon_discount = 0;
        $coupon_below_min = false;
        if ($coupon) {
            $nezha_coupon_basis = $product_price + $total_addon_price - $product_discount;
            if ($coupon->min_purchase > 0 && $nezha_coupon_basis < $coupon->min_purchase) {
                $coupon_below_min = true;
            } else {
                $coupon_discount = CouponLogic::get_discount(coupon: $coupon, order_amount: $nezha_coupon_basis);
            }
        }

        if ($winner === 'coupon' && $coupon_discount <= 0) {
            $winner = 'none';
        }
        if ($winner === 'tiered' && $product_discount <= 0) {
            $winner = 'none';
        }

        return response()->json([
            'tiered' => [
                'active'        => $tiered_would_be > 0,
                'won'           => $winner === 'tiered',
                'amount'        => $winner === 'tiered' ? round($product_discount, $digits) : 0,
                'would_be'      => round($tiered_would_be, $digits),
                'discount_type' => data_get($tiered, 'discount_type'),
                'min_purchase'  => data_get($tiered, 'min_purchase'),
            ],
            'coupon' => [
                'code'      => $request['coupon_code'] ?? null,
                'won'       => $winner === 'coupon',
                'amount'    => round($coupon_discount, $digits),
                'below_min' => $coupon_below_min,
                'invalid'   => $coupon_invalid,
            ],
            'winner'           => $winner,
            'product_discount' => round($product_discount, $digits),
            'coupon_discount'  => round($coupon_discount, $digits),
            'product_price'    => round($product_price, $digits),
            'addon_price'      => round($total_addon_price, $digits),
        ]);
    }

    private function emptyQuote()
    {
        return [
            'tiered' => ['active' => false, 'won' => false, 'amount' => 0, 'would_be' => 0, 'discount_type' => null, 'min_purchase' => null],
            'coupon' => ['code' => null, 'won' => false, 'amount' => 0, 'below_min' => false, 'invalid' => null],
            'winner' => 'none',
            'product_discount' => 0,
            'coupon_discount' => 0,
            'product_price' => 0,
            'addon_price' => 0,
        ];
    }
}
