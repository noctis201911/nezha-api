<?php

namespace App\Traits;

use App\Models\Cart;
use App\Models\Food;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\Order;
use App\Models\Coupon;
use App\Models\ItemCampaign;
use App\Scopes\RestaurantScope;
use App\CentralLogics\Helpers;
use App\Models\BusinessSetting;
use App\CentralLogics\CouponLogic;
use App\Models\AddOn;
use App\Models\CustomerAddress;
use App\Models\OrderDetail;
use App\Models\OrderEditLog;
use App\Models\OrderReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use App\CentralLogics\CustomerLogic;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

trait PlaceNewOrder
{
    private function makeOrderDetails($carts, $order, $restaurant)
    {
        $total_addon_price = 0;
        $product_price = 0;
        $restaurant_discount_amount = 0;
        $product_data = [];
        $order_details = [];
        $variations = [];
        $discount_on_product_by = 'vendor';
        foreach ($carts as $c) {
            $isCampaign = false;
            if ($c['item_type'] === 'App\Models\ItemCampaign' || $c['item_type'] === 'AppModelsItemCampaign') {
                $product = ItemCampaign::active()->find($c['item_id']);
                $isCampaign = true;
            } else {
                $product = Food::withoutGlobalScope(RestaurantScope::class)->active()->find($c['item_id']);
            }
            if ($product) {
                if ($product->restaurant_id != $order->restaurant_id) {
                    return [
                        'status_code' => 403,
                        'code' => 'different_restaurants',
                        'message' => translate('messages.Please_select_items_from_the_same_restaurant'),
                    ];
                }

                if ($product?->maximum_cart_quantity && $c['quantity'] > $product?->maximum_cart_quantity) {
                    return [
                        'status_code' => 403,
                        'code' => 'quantity',
                        'message' => translate('messages.maximum_cart_quantity_limit_over'),
                    ];
                }

                $product_variations = json_decode($product->variations, true);

                if (count($product_variations)) {
                    $variation_data = Helpers::get_varient($product_variations, gettype($c['variations']) == 'array' ? $c['variations'] : json_decode($c['variations'], true));
                    $price = $product['price'] + $variation_data['price'];
                    $variations = $variation_data['variations'];
                } else {
                    $price = $product['price'];
                }

                $product = Helpers::product_data_formatting(data: $product, multi_data: false, trans: false, local: app()->getLocale(), maxDiscount: false);
                $addon_data = Helpers::calculate_addon_price(AddOn::withoutGlobalScope(RestaurantScope::class)->whereIn('id', (gettype($c['add_on_ids']) == 'array' ? $c['add_on_ids'] : json_decode($c['add_on_ids'], true)) ?? [])->get(), (gettype($c['add_on_qtys']) == 'array' ? $c['add_on_qtys'] : json_decode($c['add_on_qtys'], true)) ?? []);
                $product_discount = Helpers::food_discount_calculate($product, $price, $restaurant, false);

                $or_d = [
                    'food_id' => $isCampaign ? null : $c['item_id'],
                    'item_campaign_id' => $isCampaign ? $c['item_id'] : null,
                    'food_details' => json_encode($product),
                    'quantity' => $c['quantity'],
                    'price' => round($price, config('round_up_to_digit')),

                    'category_id' => collect(is_string($product->category_ids) ? json_decode($product->category_ids, true) : $product->category_ids)->firstWhere('position', 1)['id'] ?? null,
                    'tax_amount' => 0,
                    'tax_status' => null,

                    'discount_on_product_by' => $product_discount['discount_type'],
                    'discount_type' => $product_discount['discount_type'],
                    'discount_on_food' => $product_discount['discount_amount'],
                    'discount_percentage' => $product_discount['discount_percentage'],

                    // 'variant' => json_encode($c['variant']),
                    'variation' => json_encode($variations),
                    'add_ons' => json_encode($addon_data['addons']),

                    'total_add_on_price' => round($addon_data['total_add_on_price'], config('round_up_to_digit')),
                    'addon_discount' => 0,

                    'created_at' => now(),
                    'updated_at' => now()
                ];


                $total_addon_price += $or_d['total_add_on_price'];
                $product_price += $price * $or_d['quantity'];
                $restaurant_discount_amount += $or_d['discount_on_food'] * $or_d['quantity'] ?? 0;
                $order_details[] = $or_d;
                $addon_data[] = $addon_data['addons'];
            } else {
                return [
                    'status_code' => 403,
                    'code' => 'not_found',
                    'message' => translate('messages.product_not_found'),
                ];
            }
        }


        $discount_on_product_by = 'vendor';
        $discount = $restaurant_discount_amount;
        $restaurantDiscount = Helpers::get_restaurant_discount($restaurant);
        if (isset($restaurantDiscount)) {
            $admin_discount = Helpers::checkAdminDiscount(price: $product_price, discount: $restaurantDiscount['discount'], max_discount: $restaurantDiscount['max_discount'], min_purchase: $restaurantDiscount['min_purchase']);
            $discount = $admin_discount;
            $discount_on_product_by = 'admin';
            foreach ($order_details as $key => $detail_data) {
                if ($admin_discount > 0) {
                    $order_details[$key]['discount_on_product_by'] = $discount_on_product_by;
                    $order_details[$key]['discount_type'] = 'percentage';
                    $order_details[$key]['discount_percentage'] = $restaurantDiscount['discount'];
                    $order_details[$key]['discount_on_food'] =  Helpers::checkAdminDiscount(price: $product_price, discount: $restaurantDiscount['discount'], max_discount: $restaurantDiscount['max_discount'], min_purchase: $restaurantDiscount['min_purchase'], item_wise_price: $detail_data['price'] * $detail_data['quantity']);
                } else {
                    $order_details[$key]['discount_on_product_by'] = null;
                    $order_details[$key]['discount_type'] = 'percentage';
                    $order_details[$key]['discount_percentage'] = 0;
                    $order_details[$key]['discount_on_food'] =  0;
                }
            }
        }

        return [
            'order_details' => $order_details,
            'total_addon_price' => $total_addon_price,
            'product_price' => $product_price,
            'restaurant_discount_amount' => $discount,
            'discount_on_product_by' => $discount_on_product_by,
            'product_data' => $product_data

        ];
    }

    private function makePosOrderDetails($carts, $restaurant)
    {
        $total_addon_price = 0;
        $product_price = 0;
        $restaurant_discount_amount = 0;
        $product_data = [];
        $order_details = [];
        $variations = [];
        $discount_on_product_by = 'vendor';
        foreach ($carts as $c) {
            if (is_array($c)) {
                $isCampaign = false;
                if (isset($c['item_type']) && ($c['item_type'] === 'App\Models\ItemCampaign' || $c['item_type'] === 'AppModelsItemCampaign')) {
                    $product = ItemCampaign::with('module')->active()->find($c['item_id']);
                    $isCampaign = true;
                } else {
                    $product = Food::withoutGlobalScope(RestaurantScope::class)->active()->find(isset($c['item_id']) ?: $c['id']);
                }

                if ($product) {
                    if ($product->restaurant_id != $restaurant->id) {
                        return [
                            'status_code' => 403,
                            'code' => 'different_restaurants',
                            'message' => translate('messages.Please_select_food_from_the_same_restaurant'),
                        ];
                    }

                    if ($product?->maximum_cart_quantity && $c['quantity'] > $product?->maximum_cart_quantity) {
                        return [
                            'status_code' => 403,
                            'code' => 'quantity',
                            'message' => translate('messages.maximum_cart_quantity_limit_over'),
                        ];
                    }

                    $product_variations = json_decode($product->variations, true);

                    if (count($product_variations)) {
                        $variation_data = Helpers::get_varient($product_variations, $c['variations']);
                        $price = $product['price'] + $variation_data['price'];
                        $variations = $variation_data['variations'];
                    } else {
                        $price = $product['price'];
                    }
                    $product = Helpers::product_data_formatting(data: $product, multi_data: false, trans: false, local: app()->getLocale(), maxDiscount: false);
                    $addon_data = Helpers::calculate_addon_price(AddOn::whereIn('id', $c['add_ons'])->get(), $c['add_on_qtys']);
                    $product_discount = Helpers::food_discount_calculate($product, $price, $restaurant, false);

                    $or_d = [
                        'food_id' => $isCampaign ? null : $c['id'],
                        'item_campaign_id' => $isCampaign ? $c['id'] : null,
                        'food_details' => json_encode($product),
                        'quantity' => $c['quantity'],
                        'price' => round($price, config('round_up_to_digit')),

                        'category_id' => collect(is_string($product->category_ids) ? json_decode($product->category_ids, true) : $product->category_ids)->firstWhere('position', 1)['id'] ?? null,
                        'tax_amount' => 0,
                        'tax_status' => null,

                        'discount_on_product_by' => $product_discount['discount_type'],
                        'discount_type' => $product_discount['discount_type'],
                        'discount_on_food' => $product_discount['discount_amount'],
                        'discount_percentage' => $product_discount['discount_percentage'],

                        'variant' => json_encode($c['variant']),
                        'variation' => json_encode($variations),
                        'add_ons' => json_encode($addon_data['addons']),

                        'total_add_on_price' => round($addon_data['total_add_on_price'], config('round_up_to_digit')),
                        'addon_discount' => 0,

                        'created_at' => now(),
                        'updated_at' => now()
                    ];


                    $total_addon_price += $or_d['total_add_on_price'];
                    $product_price += $price * $or_d['quantity'];
                    $restaurant_discount_amount += $or_d['discount_on_food'] * $or_d['quantity'] ?? 0;
                    $order_details[] = $or_d;
                    $addon_data[] = $addon_data['addons'];
                } else {
                    return [
                        'status_code' => 403,
                        'code' => 'not_found',
                        'message' => translate('messages.product_not_found'),
                    ];
                }
            }
        }



        $discount_on_product_by = 'vendor';
        $discount = $restaurant_discount_amount;
        $restaurantDiscount = Helpers::get_restaurant_discount($restaurant);
        if (isset($restaurantDiscount)) {
            $admin_discount = Helpers::checkAdminDiscount(price: $product_price, discount: $restaurantDiscount['discount'], max_discount: $restaurantDiscount['max_discount'], min_purchase: $restaurantDiscount['min_purchase']);
            $discount = $admin_discount;
            $discount_on_product_by = 'admin';
            foreach ($order_details as $key => $detail_data) {
                if ($admin_discount > 0) {
                    $order_details[$key]['discount_on_product_by'] = $discount_on_product_by;
                    $order_details[$key]['discount_type'] = 'percentage';
                    $order_details[$key]['discount_percentage'] = $restaurantDiscount['discount'];
                    $order_details[$key]['discount_on_food'] =  Helpers::checkAdminDiscount(price: $product_price, discount: $restaurantDiscount['discount'], max_discount: $restaurantDiscount['max_discount'], min_purchase: $restaurantDiscount['min_purchase'], item_wise_price: $detail_data['price'] * $detail_data['quantity']);
                } else {
                    $order_details[$key]['discount_on_product_by'] = null;
                    $order_details[$key]['discount_type'] = 'percentage';
                    $order_details[$key]['discount_percentage'] = 0;
                    $order_details[$key]['discount_on_food'] =  0;
                }
            }
        }

        $finalCart = [];
        foreach ($carts as $key => $cart) {
            if (is_array($cart)) {
                $cart['discount'] = $order_details[$key]['discount_on_product_by'] == 'admin'
                    ? $order_details[$key]['discount_on_food']
                    : ($order_details[$key]['discount_on_food'] ?? 0) * ($order_details[$key]['quantity'] ?? 1);
            }
            $finalCart[] = $cart;
        }
        $finalCart['restaurant_id'] = $product?->restaurant_id;

        session()->put('cart', collect($finalCart));

        $total_price = $product_price + $total_addon_price - $discount;
        session()->put('cart_total_price', $total_price);

        return [
            'order_details' => $order_details,
            'total_addon_price' => $total_addon_price,
            'product_price' => $product_price,
            'restaurant_discount_amount' => $discount,
            'discount_on_product_by' => $discount_on_product_by,
            'product_data' => $product_data
        ];
    }


    private function makeEditOrderDetails($order, $carts, $restaurant, $editedBy, $editLogs = null)
    {
        DB::beginTransaction();
        Helpers::decreaseSellCount(order_details: $order->details);
        $total_addon_price = 0;
        $product_price = 0;
        $restaurant_discount_amount = 0;
        $order_details = [];
        $variations = [];
        $coupon_created_by = null;
        $coupon = null;
        $free_delivery_by = null;
        $has_new_item = false;

        $discount_on_product_by = 'vendor';
        if ($order->coupon_code) {
            $coupon_check = Helpers::coupon_check(coupon_code: $order->coupon_code, restaurant_id: $restaurant->id, user_id: $order->user_id, is_guest: $order->is_guest);
            $coupon = data_get($coupon_check, 'coupon');
            $coupon_created_by = data_get($coupon_check, 'coupon_created_by', null);
            $free_delivery_by = data_get($coupon_check, 'free_delivery_by', null);
        }

        if (count($carts) == 0) {
            return ['status_code' => 403, 'code' => 'cart', 'message' => translate('You_can_not_place_an_empty_order')];
        }
        foreach ($carts as $cart) {

            if ($cart['item_type'] === 'App\Models\ItemCampaign' || $cart['item_type'] === 'AppModelsItemCampaign') {
                $product = ItemCampaign::active()->find($cart['item_id']);
                $campaign_id = $cart['item_id'];
                $code = 'campaign';
            } else {
                $product = Food::active()->with('restaurant')->find($cart['item_id']);
                $food_id = $cart['item_id'];
                $code = 'food';
            }
            if (data_get($cart, 'new_item') == true) {
                $has_new_item = true;
            }

            if ($product->restaurant_id != $restaurant->id) {
                DB::rollBack();
                return ['status_code' => 403, 'code' => 'restaurant', 'message' => translate('messages.you_need_to_order_food_from_single_restaurant')];
            }

            if ($product) {
                if ($product->maximum_cart_quantity && ($cart['quantity'] > $product->maximum_cart_quantity)) {
                    DB::rollBack();
                    return ['status_code' => 403, 'code' => 'quantity', 'message' => $product?->name ?? $product?->title ?? $code . ' ' . translate('messages.has_reached_the_maximum_cart_quantity_limit')];
                }

                $addon_data = Helpers::calculate_addon_price(addons: AddOn::whereIn('id', $cart['add_on_ids'])->get(), add_on_qtys: $cart['add_on_qtys']);

                if ($code == 'food') {
                    $variation_options =  is_string(data_get($cart, 'variation_options')) ? json_decode(data_get($cart, 'variation_options'), true) : [];
                    $addonAndVariationStock = Helpers::addonAndVariationStockCheck(product: $product, quantity: $cart['quantity'], add_on_qtys: $cart['add_on_qtys'], variation_options: $variation_options, add_on_ids: $cart['add_on_ids'], incrementCount: true);
                    if (data_get($addonAndVariationStock, 'out_of_stock') != null) {
                        DB::rollBack();
                        return ['status_code' => 403, 'code' => data_get($addonAndVariationStock, 'type') ?? 'food', 'message' => data_get($addonAndVariationStock, 'out_of_stock')];
                    }
                }

                $product_variations = json_decode($product->variations, true);
                $variations = [];
                if (count($product_variations)) {

                    if ($editedBy == 'vendor_app') {
                        $variation_data = Helpers::get_varient($product_variations, isset($cart['variations']) ? $cart['variations'] : []);
                    } else {
                        $variation_data = Helpers::get_edit_varient($product_variations, isset($cart['variations']) ? $cart['variations'] : []);
                    }

                    $price = $product['price'] + $variation_data['price'];
                    $variations = $variation_data['variations'];
                } else {
                    $price = $product['price'];
                }


                $product = Helpers::product_data_formatting(data: $product, multi_data: false, trans: false, local: app()->getLocale(), maxDiscount: false);
                $product_discount = Helpers::food_discount_calculate($product, $price, $restaurant, false);
                $order_detail = [
                    'food_id' => $food_id ??  null,
                    'item_campaign_id' => $campaign_id ?? null,
                    'food_details' => json_encode($product),
                    'quantity' => $cart['quantity'],
                    'price' => round($price, config('round_up_to_digit')),
                    'category_id' => collect(is_string($product->category_ids) ? json_decode($product->category_ids, true) : $product->category_ids)->firstWhere('position', 1)['id'] ?? null,
                    'tax_amount' => 0,
                    'tax_status' => null,
                    'discount_type' => 'discount_on_product',
                    'discount_on_product_by' => 'vendor',
                    'discount_on_food' => $product_discount['discount_amount'],
                    'discount_percentage' => $product_discount['discount_percentage'],
                    'variation' => json_encode($variations),
                    'add_ons' => json_encode($addon_data['addons']),
                    'total_add_on_price' => $addon_data['total_add_on_price'],
                    'created_at' => now(),
                    'updated_at' => now()
                ];
                $order_details[] = $order_detail;
                $total_addon_price += $order_detail['total_add_on_price'];
                $product_price += $price * $order_detail['quantity'];
                $restaurant_discount_amount += $order_detail['discount_on_food'] * $order_detail['quantity'];
            } else {
                DB::rollBack();
                return ['status_code' => 404, 'code' => $code ?? null, 'message' => translate('messages.product_unavailable_warning')];
            }
        }

        $discount = $restaurant_discount_amount;
        $restaurantDiscount = Helpers::get_restaurant_discount($restaurant);
        if (isset($restaurantDiscount)) {
            $admin_discount = Helpers::checkAdminDiscount(price: $product_price, discount: $restaurantDiscount['discount'], max_discount: $restaurantDiscount['max_discount'], min_purchase: $restaurantDiscount['min_purchase']);
            $discount = $admin_discount;
            $discount_on_product_by = 'admin';
            foreach ($order_details as $key => $detail_data) {
                if ($admin_discount > 0) {
                    $order_details[$key]['discount_on_product_by'] = $discount_on_product_by;
                    $order_details[$key]['discount_type'] = 'percentage';
                    $order_details[$key]['discount_percentage'] = $restaurantDiscount['discount'];
                    $order_details[$key]['discount_on_food'] =  Helpers::checkAdminDiscount(price: $product_price, discount: $restaurantDiscount['discount'], max_discount: $restaurantDiscount['max_discount'], min_purchase: $restaurantDiscount['min_purchase'], item_wise_price: $detail_data['price'] * $detail_data['quantity']);
                } else {
                    $order_details[$key]['discount_on_product_by'] = null;
                    $order_details[$key]['discount_type'] = 'percentage';
                    $order_details[$key]['discount_percentage'] = 0;
                    $order_details[$key]['discount_on_food'] =  0;
                }
            }
        }

        $restaurant_discount_amount = $discount;

        $order->delivery_charge = $order->delivery_charge;
        $order->discount_on_product_by = $discount_on_product_by;

        if ($free_delivery_by) {
            $order->delivery_charge = 0;
        }

        if ($restaurant->free_delivery) {
            $order->delivery_charge = 0;
        }
        if (in_array($order->free_delivery_by, ['admin'])) {
            $order->delivery_charge = 0;
        }

        $coupon_discount_amount = $coupon ? CouponLogic::get_discount(coupon: $coupon, order_amount: $product_price + $total_addon_price - $restaurant_discount_amount) : 0;
        $order->coupon_discount_amount = round($coupon_discount_amount, config('round_up_to_digit'));
        $order->coupon_discount_title = $coupon ? $coupon->title : '';
        $order->coupon_code = $coupon_created_by ? $order->coupon_code : null;
        $order->coupon_created_by = $coupon_created_by;
        $total_price = $product_price + $total_addon_price - $restaurant_discount_amount - $coupon_discount_amount;

        if ($order->ref_bonus_amount > 0) {
            $user = User::find($order->user_id);
            $discount_data = Helpers::getCusromerFirstOrderDiscount(order_count: 0, user_creation_date: $user->created_at,  refby: $user->ref_by, price: $total_price);
            if (data_get($discount_data, 'is_valid') == true &&  data_get($discount_data, 'calculated_amount') > 0) {
                $total_price = $total_price - data_get($discount_data, 'calculated_amount');
                $order->ref_bonus_amount = data_get($discount_data, 'calculated_amount');
            }
        }

        $total_price = max($total_price, 0);
        $totalDiscount = $restaurant_discount_amount + $coupon_discount_amount +  $order->ref_bonus_amount;

        $additionalCharges = [];

        $settings = BusinessSetting::whereIn('key', [
            'additional_charge_status',
            'additional_charge',
            'extra_packaging_charge',
            'free_delivery_over',
            'free_delivery_distance',
            'admin_free_delivery_status',
            'admin_free_delivery_option',
        ])->pluck('value', 'key');

        $additional_charge_status  = $settings['additional_charge_status'] ?? null;
        $additional_charge         = $settings['additional_charge'] ?? null;
        $extra_packaging_data  = $settings['extra_packaging_charge'] ?? 0;

        $order->dm_tips = $order->dm_tips;
        $order->additional_charge = $order->additional_charge;

        if ($additional_charge_status == 1) {
            $order->additional_charge = $additional_charge ?? 0;
        }

        $order->extra_packaging_amount =  ($extra_packaging_data == 1 && $restaurant?->restaurant_config?->is_extra_packaging_active == 1  && $order?->extra_packaging_amount > 0) ? $restaurant?->restaurant_config?->extra_packaging_amount : $order->extra_packaging_amount;

        if ($order->extra_packaging_amount > 0) {
            $additionalCharges['tax_on_packaging_charge'] =  $order->extra_packaging_amount;
        }


        $finalCalculatedTax =  Helpers::getFinalCalculatedTax($order_details, $additionalCharges, $totalDiscount, $total_price, $restaurant->id);
        $taxType =  data_get($finalCalculatedTax, 'taxType');
        $tax_amount = $finalCalculatedTax['tax_amount'];
        $tax_status = $finalCalculatedTax['tax_status'];
        $taxMap = $finalCalculatedTax['taxMap'];
        $orderTaxIds = data_get($finalCalculatedTax, 'taxData.orderTaxIds', []);

        $order->tax_status = $tax_status;
        $order->tax_type = $taxType;

        if ($restaurant->minimum_order > $product_price + $total_addon_price) {
            DB::rollBack();
            return ['status_code' => 404, 'code' => $code ?? null, 'message' => translate('messages.you_need_to_order_at_least', ['amount' => $restaurant->minimum_order . ' ' . Helpers::currency_code()])];
        }


        $free_delivery_over = (float) ($settings['free_delivery_over'] ?? 0);
        $free_delivery_distance = (float) ($settings['free_delivery_distance'] ?? 0);
        $admin_free_delivery_status = (int) ($settings['admin_free_delivery_status'] ?? 0);
        $admin_free_delivery_option = $settings['admin_free_delivery_option'] ?? null;


        if ($admin_free_delivery_status === 1) {
            $eligibleAmount = $total_price;
            if ($admin_free_delivery_option === 'free_delivery_to_all_store' || ($admin_free_delivery_option === 'free_delivery_by_specific_criteria' && ($free_delivery_distance > 0 && $order->distance <= $free_delivery_distance) || ($free_delivery_over > 0  && $eligibleAmount >= $free_delivery_over))) {
                $order->delivery_charge = 0;
                $free_delivery_by = 'admin';
            }
        }

        if ($restaurant->free_delivery) {
            $order->delivery_charge = 0;
            $free_delivery_by = 'vendor';
        }

        if ($restaurant->self_delivery_system == 1 && $restaurant->free_delivery_distance_status == 1 && $restaurant->free_delivery_distance_value && ($order->distance <= $restaurant->free_delivery_distance_value)) {
            $order->delivery_charge = 0;
            $free_delivery_by = 'vendor';
        }

        $order->free_delivery_by = $free_delivery_by;
        $order_amount = round($total_price + $tax_amount + $order->delivery_charge + $order->additional_charge + $order->extra_packaging_amount, config('round_up_to_digit'));
        $order->total_tax_amount = round($tax_amount, config('round_up_to_digit'));
        $order->order_amount = $order_amount + $order->dm_tips;
        $order->restaurant_discount_amount = round($restaurant_discount_amount, config('round_up_to_digit'));

        // $order->adjusment = 0;
        $order->edited = true;

        $order->save();

        $taxMapCollection = collect($taxMap);
        foreach ($order_details as $key => $item) {
            $order_details[$key]['order_id'] = $order->id;


            if ($item['food_id']) {
                $item_id = $item['food_id'];
            } else {
                $item_id = $item['item_campaign_id'];
            }
            $index = $taxMapCollection->search(function ($tax) use ($item_id) {
                return $tax['product_id'] == $item_id;
            });
            if ($index !== false) {
                $matchedTax = $taxMapCollection->pull($index);
                $order_details[$key]['tax_status'] = $matchedTax['include'] == 1 ? 'included' : 'excluded';
                $order_details[$key]['tax_amount'] = $matchedTax['totalTaxamount'];
            }
        }
        $order->details()->delete();
        $order->orderTaxes()->delete();
        OrderDetail::insert($order_details);

        if (count($orderTaxIds)) {
            \Modules\TaxModule\Services\CalculateTaxService::updateOrderTaxData(
                orderId: $order->id,
                orderTaxIds: $orderTaxIds,
            );
        }

        if ($editLogs && is_array($editLogs)) {
            $editLogs = array_values(array_unique($editLogs));
            if ($has_new_item == false  && in_array('add_new_item', $editLogs)) {
                $index = array_search('add_new_item', $editLogs);
                if ($index !== false) {
                    unset($editLogs[$index]);
                }
            }

            foreach ($editLogs as $log) {
                $this->makeEditOrderLogs($order->id, $log, $editedBy == 'vendor_app' ? 'vendor' : $editedBy);
            }
        }

        DB::commit();
        return  ['status_code' => 200, 'message' => translate('messages.order_has_been_updated_successfully')];
    }


    public function getCalculatedTax($request)
    {
        $product_price = $request->order_amount ?? 0;
        $coupon = null;
        $ref_bonus_amount = 0;
        $total_addon_price = 0;
        $restaurant_discount_amount = 0;
        $coupon_discount_amount = 0;
        $order_details = [];


        $order = new Order();
        $order->user_id = $request->user ? $request->user->id : $request['guest_id'];
        $order->is_guest = $request->user ? 0 : 1;
        $order->restaurant_id = $request['restaurant_id'];



        $additionalCharges = [];
        $settings = BusinessSetting::whereIn('key', [
            'additional_charge_status',
            'additional_charge',
            'extra_packaging_charge',
        ])->pluck('value', 'key');


        $extra_packaging_data  = $settings['extra_packaging_charge'] ?? 0;

        $restaurant = Restaurant::with(['discount'])->where('id', $request->restaurant_id)->first();

        if ($request['coupon_code']) {
            $couponData =  $this->getCouponData($request);
            if (data_get($couponData, 'status_code') === 403) {

                return response()->json([
                    'errors' => [
                        ['code' => data_get($couponData, 'code'), 'message' => data_get($couponData, 'message')]
                    ]
                ], data_get($couponData, 'status_code'));
            } else {
                $coupon = data_get($couponData, 'coupon');
            }
        }
        $extra_packaging_amount =  ($extra_packaging_data == 1 && $restaurant?->restaurant_config?->is_extra_packaging_active == 1  && $request?->extra_packaging_amount > 0) ? $restaurant?->restaurant_config?->extra_packaging_amount : 0;

        if ($extra_packaging_amount > 0) {
            $additionalCharges['tax_on_packaging_charge'] =  $extra_packaging_amount;
        }

        $carts = Cart::where('user_id', $order->user_id)->where('is_guest', $order->is_guest)
            // 哪吒[多店购物车]: 算税只取本次结算餐厅的车项(登录账号后端车可并存多家),防多店撞「单一餐厅」校验
            ->where('restaurant_id', $order->restaurant_id)
            ->when(isset($request->is_buy_now) && $request->is_buy_now == 1 && $request->cart_id, function ($query) use ($request) {
                return $query->where('id', $request->cart_id);
            })
            ->get()->map(function ($data) {
                $data->add_on_ids = is_string($data->add_on_ids) ? json_decode($data->add_on_ids, true) : $data->add_on_ids;
                $data->add_on_qtys = is_string($data->add_on_qtys) ? json_decode($data->add_on_qtys, true) : $data->add_on_qtys;
                $data->variation = is_string($data->variation) ? json_decode($data->variation, true) : $data->variation;
                return $data;
            });

        if (isset($request->is_buy_now) && $request->is_buy_now == 1) {
            $carts = gettype($request['cart']) == 'array' ? $request['cart'] : json_decode($request['cart'], true);
        }

        $order_details = $this->makeOrderDetails($carts, $order, $restaurant);
        if (data_get($order_details, 'status_code') === 403) {

            return response()->json([
                'errors' => [
                    ['code' => data_get($order_details, 'code'), 'message' => data_get($order_details, 'message')]
                ]
            ], data_get($order_details, 'status_code'));
        }

        $total_addon_price = $order_details['total_addon_price'];
        $product_price = $order_details['product_price'];
        $restaurant_discount_amount = $order_details['restaurant_discount_amount'];
        $order_details = $order_details['order_details'];

        $coupon_discount_amount = $coupon ? CouponLogic::get_discount($coupon, $product_price + $total_addon_price - $restaurant_discount_amount) : 0;

        $total_price = $product_price + $total_addon_price - $restaurant_discount_amount - $coupon_discount_amount;


        if ($order->is_guest  == 0 && $order->user_id) {
            $user = User::withcount('orders')->find($order->user_id);
            $discount_data = Helpers::getCusromerFirstOrderDiscount(order_count: $user->orders_count, user_creation_date: $user->created_at,  refby: $user->ref_by, price: $total_price);
            if (data_get($discount_data, 'is_valid') == true &&  data_get($discount_data, 'calculated_amount') > 0) {
                $total_price = $total_price - data_get($discount_data, 'calculated_amount');
                $ref_bonus_amount = data_get($discount_data, 'calculated_amount');
            }
        }

        $totalDiscount = $restaurant_discount_amount  + $coupon_discount_amount +  $ref_bonus_amount;
        $finalCalculatedTax =  Helpers::getFinalCalculatedTax($order_details, $additionalCharges, $totalDiscount, $total_price, $order->restaurant_id, false);

        $data = [
            'tax_amount' => $finalCalculatedTax['tax_amount'],
            'tax_status' => $finalCalculatedTax['tax_status'],
            'tax_included' => $finalCalculatedTax['tax_included'],
        ];

        return response()->json($data, 200);
    }
    public function setPosCalculatedTax($restaurant, $restaurantData = false)
    {

        $sessionCart = session()->get('cart');
        $carts = collect($sessionCart)->filter(fn($item, $key) => is_numeric($key))->values()->all();

        $order_details = $this->makePosOrderDetails($carts, $restaurant);
        $total_addon_price = $order_details['total_addon_price'];
        $product_price = $order_details['product_price'];
        $restaurant_discount_amount = $order_details['restaurant_discount_amount'];
        $order_details = $order_details['order_details'];

        $totalDiscount = $restaurant_discount_amount;
        $price = $product_price + $total_addon_price - $totalDiscount ?? 0;

        $additionalCharges = [];
        $extra_packaging_data  =  Helpers::get_business_settings('extra_packaging_charge')   ?? 0;
        $extra_packaging_amount =  ($extra_packaging_data == 1 && $restaurant?->restaurant_config?->is_extra_packaging_active == 1  && $restaurant?->restaurant_config?->extra_packaging_status == 1) ? $restaurant?->restaurant_config?->extra_packaging_amount : 0;

        if ($extra_packaging_amount > 0) {
            $additionalCharges['tax_on_packaging_charge'] =  $extra_packaging_amount;
        }

        $finalCalculatedTax =  Helpers::getFinalCalculatedTax(
            $order_details,
            $additionalCharges,
            $totalDiscount,
            $price,
            $restaurant->id,
            $restaurantData
        );

        session()->put('tax_amount', $finalCalculatedTax['tax_amount']);
        session()->put('tax_included', $finalCalculatedTax['tax_included']);

        $data = [
            'tax_amount' => $finalCalculatedTax['tax_amount'],
            'tax_status' => $finalCalculatedTax['tax_status'],
            'tax_included' => $finalCalculatedTax['tax_included'],
        ];
        return $data;
    }


    private function getCouponData($request)
    {

        if ($request['coupon_code']) {
            $coupon = Coupon::active()->where(['code' => $request['coupon_code']])->first();

            if (!$coupon) {
                return [
                    'status_code' => 403,
                    'code' => 'coupon',
                    'message' => translate('messages.coupon_expire'),
                ];
            }

            $status = $request->is_guest
                ? CouponLogic::is_valid_for_guest($coupon, $request['restaurant_id'])
                : CouponLogic::is_valide($coupon, $request->user->id, $request['restaurant_id']);

            $validationError = match ($status) {
                407 => [
                    'status_code' => 403,
                    'code' => 'coupon',
                    'message' => translate('messages.coupon_expire'),
                ],
                408 => [
                    'status_code' => 403,
                    'code' => 'coupon',
                    'message' => translate('messages.You_are_not_eligible_for_this_coupon'),
                ],
                406 => [
                    'status_code' => 403,
                    'code' => 'coupon',
                    'message' => translate('messages.coupon_usage_limit_over'),
                ],
                404 => [
                    'status_code' => 403,
                    'code' => 'coupon',
                    'message' => translate('messages.not_found'),
                ],
                default => null,
            };

            if ($validationError) {
                return $validationError;
            }

            $coupon_created_by = $coupon->created_by;

            if ($coupon->coupon_type === 'free_delivery') {
                $delivery_charge = 0;
                $free_delivery_by = $coupon_created_by;
                $coupon_created_by = null;
            }
        }

        return [
            'coupon' => $coupon ?? null,
            'coupon_created_by' => $coupon_created_by ?? null,
            'delivery_charge' => $delivery_charge ?? null,
            'free_delivery_by' => $free_delivery_by ?? null,
        ];
    }



    private function makeEditOrderLogs($order_id, $log, $edited_by)
    {
        $editLog = new OrderEditLog();
        $editLog->order_id = $order_id;
        $editLog->log = $log;
        $editLog->edited_by = $edited_by;
        $editLog->save();
        return true;
    }

    public function placePosOrder($cart, $request, $restaurant, $distance)
    {
        try {
            if (count($cart) == 0 || !data_get($cart,0) ) {
                return ['code' => 'cart', 'status_code' => 403, 'message' => translate('cart_is_empty')];
            }
            
            $total_addon_price = 0;
            $product_price = 0;
            $restaurant_discount_amount = 0;

            $order_details = [];
            $lastId = Order::max('id') ?? 99999;
            $order = new Order();
            $order->id = $lastId + 1;

            $customer_id = Session::get('customer_id');
            $user = null;

            if ($customer_id) {
                $user = User::where('id', $customer_id)->first();
                $address = CustomerAddress::where('user_id', $customer_id)->where('id', Session::get('address_id'))->first();

                $address = [
                    'contact_person_number' => $address?->contact_person_number ?? $user?->phone,
                    'contact_person_name' => $address?->contact_person_name ?? $user?->full_name,
                    'address_type' => $address?->address_type ?? 'delivery',
                    'address' => $address?->address,
                    'latitude' => $address?->latitude,
                    'longitude' => $address?->longitude,
                    'floor' => $address?->floor,
                    'road' => $address?->road,
                    'house' => $address?->house,
                ];
            }


            $order->payment_method = $request->type == 'cash' ?  'cash_on_delivery' : $request->type;

            if ($order->payment_method == 'wallet' && !$user) {
                return ['code' => 'wallet', 'status_code' => 403, 'message' => translate('messages.wallet_not_found')];
            }

            $order->payment_status = !$customer_id  || $request->type == 'wallet' ? 'paid' : 'unpaid';
            $order->order_status = !$customer_id || $request->type == 'wallet' ? 'delivered' : 'confirmed';
            $order->order_type = Session::get('order_type') ?? 'take_away';
            $order->delivered = $order->order_status ==  'delivered' ?  now() : null;


            $order->scheduled = 0;
            $order->is_pos = 1;
            $order->restaurant_id = $restaurant->id;
            $order->user_id = $customer_id;
            $order->zone_id = $restaurant->zone_id;
            $order->delivery_address = isset($customer_id) ? json_encode($address) : null;
            $order->checked = 1;
            $order->created_at = now();
            $order->schedule_at = now();
            $order->updated_at = now();
            $order->otp = rand(1000, 9999);




            $additionalCharges = [];
            $additional_charge_status = Helpers::get_business_settings('additional_charge_status');
            $additional_charge = Helpers::get_business_settings('additional_charge');

            if ($additional_charge_status == 1) {
                $order->additional_charge = $additional_charge ?? 0;
            } else {
                $order->additional_charge = 0;
            }

            $extra_packaging_data  =  Helpers::get_business_settings('extra_packaging_charge')   ?? 0;
            $extra_packaging_amount =  ($extra_packaging_data == 1 && $restaurant?->restaurant_config?->is_extra_packaging_active == 1  && $restaurant?->restaurant_config?->extra_packaging_status == 1) ? $restaurant?->restaurant_config?->extra_packaging_amount : 0;

            if ($extra_packaging_amount > 0) {
                $additionalCharges['tax_on_packaging_charge'] =  $extra_packaging_amount;
                $order->extra_packaging_amount = $extra_packaging_amount;
            } else {
                $order->extra_packaging_amount = 0;
            }

            $order_details = $this->makePosOrderDetails($cart, $restaurant);

            if (data_get($order_details, 'status_code') === 403) {
                return ['code' => data_get($order_details, 'code'), 'status_code' => data_get($order_details, 'status_code'), 'message' => data_get($order_details, 'message')];
            }

            $total_addon_price = $order_details['total_addon_price'];
            $product_price = $order_details['product_price'];
            $restaurant_discount_amount = $order_details['restaurant_discount_amount'];
            $order_details = $order_details['order_details'];

            $total_price = $product_price + $total_addon_price - $restaurant_discount_amount;



            if ($order->order_type == 'delivery') {
                $calculateDeliveryCharge = $this->calculateDeliveryCharge($distance, $restaurant, $total_price);

                $order->distance = $calculateDeliveryCharge['distance'];
                $order->vehicle_id =  $calculateDeliveryCharge['vehicle_id'];
                $order->delivery_charge = $calculateDeliveryCharge['delivery_charge'];
                $order->original_delivery_charge = $calculateDeliveryCharge['original_delivery_charge'];
                $order->free_delivery_by = $calculateDeliveryCharge['free_delivery_by'];
                $order->delivery_type = $request->delivery_type;
                $order->delivery_type_charge = abs($request->delivery_type_charge ?? 0);
            } elseif (in_array($order->order_type, ['dine_in', 'take_away'])) {
                $order->distance = 0;
                $order->vehicle_id = null;
                $order->delivery_charge = 0;
                $order->original_delivery_charge = 0;
                $order->delivery_type = null;
                $order->delivery_type_charge = 0;
            }

            

            $totalDiscount = $restaurant_discount_amount;
            $finalCalculatedTax =  Helpers::getFinalCalculatedTax($order_details, $additionalCharges, $totalDiscount, $total_price, $restaurant->id);


            $tax_amount = $finalCalculatedTax['tax_amount'];
            $tax_status = $finalCalculatedTax['tax_status'];
            $taxMap = $finalCalculatedTax['taxMap'];
            $orderTaxIds = data_get($finalCalculatedTax, 'taxData.orderTaxIds', []);
            $taxType =  data_get($finalCalculatedTax, 'taxType');
            $order->tax_type = $taxType;
            $order->tax_status = $tax_status;


            $order->restaurant_discount_amount = $restaurant_discount_amount;
            $order->total_tax_amount = $tax_amount;

            $order->order_amount = $total_price + $tax_amount + $order->delivery_charge + $order->additional_charge + $order->extra_packaging_amount;
            if($request->delivery_type == 'slightly_delay'){
                $order->order_amount -= $order->delivery_type_charge ?? 0;
            }else{
                $order->order_amount += $order->delivery_type_charge ?? 0;
            }
            $order->adjusment = $request?->amount ?? $order->order_amount;


            $max_cod_order_amount_value =  BusinessSetting::where('key', 'max_cod_order_amount')->first()?->value ?? 0;
            if ($max_cod_order_amount_value > 0 && $order->payment_method == 'cash_on_delivery' && $order->order_amount > $max_cod_order_amount_value) {
                return ['code' => 403, 'status_code' => 403, 'message' => translate('messages.You can not Order more then ') . $max_cod_order_amount_value . Helpers::currency_symbol() . ' ' . translate('messages.on COD order.')];
            }

            if ($order->payment_method == 'wallet' && $user && $user?->wallet_balance < $order->order_amount) {
                return ['code' => 'order_amount', 'status_code' => 403, 'message' => translate('insufficient_balance')];
            } elseif ($order->payment_method == 'wallet' && $user && $user?->wallet_balance >= $order->order_amount) {
                CustomerLogic::create_wallet_transaction(user_id: $order->user_id, amount: $order->order_amount, transaction_type: 'order_place', referance: $order->id);
            }

            $order->save();
            $taxMapCollection = collect($taxMap);
            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;

                if ($item['food_id']) {
                    $item_id = $item['food_id'];
                } else {
                    $item_id = $item['item_campaign_id'];
                }
                $index = $taxMapCollection->search(function ($tax) use ($item_id) {
                    return $tax['product_id'] == $item_id;
                });
                if ($index !== false) {
                    $matchedTax = $taxMapCollection->pull($index);
                    $order_details[$key]['tax_status'] = $matchedTax['include'] == 1 ? 'included' : 'excluded';
                    $order_details[$key]['tax_amount'] = $matchedTax['totalTaxamount'];
                }
            }

            OrderDetail::insert($order_details);

            if (count($orderTaxIds)) {
                \Modules\TaxModule\Services\CalculateTaxService::updateOrderTaxData(
                    orderId: $order->id,
                    orderTaxIds: $orderTaxIds,
                );
            }


            Session::forget('cart');
            Session::forget('address');
            Session::forget('tax_amount');
            Session::forget('tax_included');
            Session::forget('delivery_charge');
            Session::forget('delivery_charge_display');
            Session::forget('order_type');
            Session::forget('customer_id');
            Session::forget('address_id');
            Session::forget('pos_restaurant_id');
            Session::forget('cart_total_price');

            session(['last_order' => $order->id]);

            if ($restaurant->restaurant_model == 'subscription' && isset($rest_sub)) {
                if ($rest_sub->max_order != "unlimited" && $rest_sub->max_order > 0) {
                    $rest_sub->decrement('max_order', 1);
                }
            }

            if (in_array($order->order_type, ['dine_in'])) {
                $OrderReference = new OrderReference();
                $OrderReference->order_id = $order->id;
                $OrderReference->save();
            }

            return ['code' => 'success', 'order' => $order, 'status_code' => 200, 'message' => translate('messages.Order has been placed successfully.')];
        } catch (\Exception $exception) {
            info([$exception->getFile(), $exception->getLine(), $exception->getMessage()]);
            return ['code' => 'error', 'status_code' => 500, 'message' => $exception->getMessage()];
        }
    }
    public function calculateDeliveryCharge($distance_data, $restaurant_data, $totalProductPrice = 0)
    {

        $businessSettings = BusinessSetting::whereIn('key', ['free_delivery_over', 'free_delivery_distance', 'admin_free_delivery_status', 'admin_free_delivery_option'])->pluck('value', 'key');

        $free_delivery_over = (float) ($businessSettings['free_delivery_over'] ?? 0);
        $free_delivery_distance = (float) ($businessSettings['free_delivery_distance'] ?? 0);
        $admin_free_delivery_status = (int) ($businessSettings['admin_free_delivery_status'] ?? 0);
        $admin_free_delivery_option = $businessSettings['admin_free_delivery_option'] ?? null;

        $free_delivery_by = null;

        if ($admin_free_delivery_status === 1) {
            $eligibleAmount = $totalProductPrice;
            if ($admin_free_delivery_option === 'free_delivery_to_all_store' || ($admin_free_delivery_option === 'free_delivery_by_specific_criteria' && ($free_delivery_distance > 0 && $distance_data <= $free_delivery_distance) || ($free_delivery_over > 0  && $eligibleAmount >= $free_delivery_over))) {
                $free_delivery_by = 'admin';
            }
        }

        if (($restaurant_data->sub_self_delivery)) {
            $per_km_shipping_charge = (float)$restaurant_data->per_km_shipping_charge;
            $minimum_shipping_charge = (float)$restaurant_data->minimum_shipping_charge;
            $maximum_shipping_charge = (float)$restaurant_data->maximum_shipping_charge;
            $increased = 0;
            $extra_charge = 0;
            if ($restaurant_data->free_delivery) {
                $free_delivery_by = 'vendor';
            }
            if ($restaurant_data->self_delivery_system == 1 && $restaurant_data->free_delivery_distance_status == 1 && $restaurant_data->free_delivery_distance_value && ($restaurant_data <= $restaurant_data->free_delivery_distance_value)) {
                $free_delivery_by = 'vendor';
            }
        } else {
            $per_km_shipping_charge = $restaurant_data->zone->per_km_shipping_charge ?? 0;
            $minimum_shipping_charge = $restaurant_data->zone->minimum_shipping_charge ?? 0;
            $maximum_shipping_charge = $restaurant_data->zone->maximum_shipping_charge ?? 0;
            $increased = 0;
            if ($restaurant_data->zone->increased_delivery_fee_status == 1) {
                $increased = $restaurant_data->zone->increased_delivery_fee ?? 0;
            }

            $data = Helpers::vehicle_extra_charge(distance_data: $distance_data);
            $vehicle_id = (isset($data) ? $data['vehicle_id']  : null);
            $extra_charge = (float) (isset($data) ? $data['extra_charge']  : 0);
        }
        $original_delivery_charge = ($distance_data * $per_km_shipping_charge > $minimum_shipping_charge) ? $distance_data * $per_km_shipping_charge : $minimum_shipping_charge;
        $delivery_amount = ($maximum_shipping_charge  >  $minimum_shipping_charge  && $original_delivery_charge + $extra_charge >  $maximum_shipping_charge  ?  $maximum_shipping_charge  : $original_delivery_charge + $extra_charge);
        $with_increased_fee = ($delivery_amount *  $increased) / 100;
        $delivery_charge = $delivery_amount + $with_increased_fee;
        // info(['delivery_charge' => $delivery_charge,'distance_data'=> $distance_data , 'free_delivery_by' => $free_delivery_by,  'free_delivery_by' =>$free_delivery_by,'totalProductPrice'=>$totalProductPrice]);
        return ['delivery_charge' => $free_delivery_by ? 0 : $delivery_charge, 'free_delivery_by' => $free_delivery_by, 'original_delivery_charge' => $delivery_charge, 'vehicle_id' => $vehicle_id ?? null, 'distance' => $distance_data];
    }

    public function getDistance($restaurant_data)
    {
        $address = CustomerAddress::where('id', Session::get('address_id'))->first();

        if (!$address || !$restaurant_data || Session::get('order_type') != 'delivery') {
            return 0;
        }

        $cacheKey = 'distance_data_' . $restaurant_data['id'] . '_' . $address->id;
        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($restaurant_data, $address) {
            try {
                $map_api_key = Helpers::get_business_settings('map_api_key_server');

                $response = Http::get('https://maps.googleapis.com/maps/api/distancematrix/json', [
                    'origins' => $restaurant_data['latitude'] . ',' . $restaurant_data['longitude'],
                    'destinations' => $address->latitude . ',' . $address->longitude,
                    'key' => $map_api_key,
                    'mode' => 'walking'
                ]);

                $data = $response->json();
                if (
                    isset($data['rows'][0]['elements'][0]['status']) &&
                    $data['rows'][0]['elements'][0]['status'] === 'OK'
                ) {
                    $meters = $data['rows'][0]['elements'][0]['distance']['value'] ?? 0;
                    return round($meters / 1000, 4);
                }
                throw new \Exception('Invalid distance matrix response');
            } catch (\Throwable $th) {
                $origin = [$restaurant_data['latitude'], $restaurant_data['longitude']];
                $destination = [$address->latitude, $address->longitude];
                return Helpers::get_distance($origin, $destination);
            }
        });
    }
}
