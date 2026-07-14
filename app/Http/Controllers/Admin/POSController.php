<?php

namespace App\Http\Controllers\Admin;

use App\Models\DataSetting;
use App\Traits\PlaceNewOrder;
use Carbon\Carbon;
use App\Models\Food;
use App\Models\User;
use App\Models\Zone;
use App\Models\Order;
use App\Mail\PlaceOrder;
use App\Models\Category;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use App\CentralLogics\DeliveryOptionLogic;
use App\CentralLogics\Helpers;
use App\CentralLogics\PosOrderInput;
use App\Scopes\RestaurantScope;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Session;




class POSController extends Controller
{
    use PlaceNewOrder;
    public function index(Request $request)
    {
        $time = Carbon::now()->toTimeString();
        $zone_id = $request->query('zone_id', null);
        $restaurant_id = $request->query('restaurant_id', null);
        $zone = is_numeric($zone_id) ? Zone::findOrFail($zone_id) : null;
        $restaurant_data = Restaurant::active()->with('restaurant_sub')->where('zone_id', $zone_id)->find($restaurant_id);
        $category = $request->query('category_id', null);
        $categories = Category::active()->get(['id', 'name']);
        $keyword = $request->query('keyword', false);
        $key = explode(' ', $keyword);

        if ($request->session()->has('cart')) {
            $cart = $request->session()->get('cart', collect([]));
            if (!isset($cart['restaurant_id']) || $cart['restaurant_id'] != $restaurant_id) {
                session()->forget('cart');
                $this->clearSessionData();
            }
        }


        $products = Food::withoutGlobalScope(RestaurantScope::class)->active()
            ->when(is_numeric($category), function ($query) use ($category) {
                $query->whereHas('category', function ($q) use ($category) {
                    return $q->whereId($category)->orWhere('parent_id', $category);
                });
            })
            ->where(['restaurant_id' => $restaurant_id])
            ->when(isset($zone), function ($query) use ($zone) {
                return $query->whereIn('restaurant_id', $zone->restaurants->pluck('id'));
            })
            ->when($keyword, function ($query) use ($key) {
                return $query->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('name', 'like', "%{$value}%");
                    }
                });
            })
            ->orderByRaw("FIELD(name, ?) DESC", [$request->name])
            ->available($time)
            ->latest()->paginate(12);
        Session::put('pos_restaurant_id', $restaurant_data?->id);
        $customer = null;
        $order = Order::with('restaurant')->find(session('last_order'));
        if (Session::get('customer_id')) {
            $customer = User::find(Session::get('customer_id'));
        }

        $invoiceSettings = DataSetting::invoiceSettings();

        return view('admin-views.pos.index', compact('categories', 'products', 'category', 'customer', 'keyword', 'restaurant_data', 'order', 'zone','invoiceSettings'));
    }

    public function quick_view(Request $request)
    {
        $product = Food::withoutGlobalScope(RestaurantScope::class)->with('restaurant')->findOrFail($request->product_id);
        return response()->json([
            'success' => 1,
            'view' => view('admin-views.pos._quick-view-data', compact('product'))->render(),
        ]);
    }


    public function quick_view_card_item(Request $request)
    {
        $product = Food::withoutGlobalScope(RestaurantScope::class)->findOrFail($request->product_id);
        $item_key = $request->item_key;
        $cart_item = session()->get('cart')[$item_key];

        return response()->json([
            'success' => 1,
            'view' => view('admin-views.pos._quick-view-cart-item', compact('product', 'cart_item', 'item_key'))->render(),
        ]);
    }

    public function variant_price(Request $request)
    {
        $quantity = PosOrderInput::positiveInteger($request->quantity);
        if ($quantity === null) {
            return response()->json(['data' => 'quantity_error', 'message' => translate('Invalid quantity')], 422);
        }

        $old_selected_addons = [];
        $old_selected_variations = [];
        $old_selected_without_variation = $request?->old_selected_without_variation ?? 0;

        if ($request?->old_selected_variations) {
            $old_selected_variations = json_decode($request->old_selected_variations, true) ?? [];
        }
        if ($request?->old_selected_addons) {
            $old_selected_addons = json_decode($request->old_selected_addons, true) ?? [];
        }


        $product = Food::withoutGlobalScope(RestaurantScope::class)->with('restaurant')->where(['id' => $request->id])->first();

        $price = $product->price;
        $addon_price = 0;
        $add_on_ids = [];
        $add_on_qtys = [];
        if ($request['addon_id']) {
            foreach ($request['addon_id'] as $id) {
                $add_on_ids[] = $id;
                $add_on_qtys[] = $request['addon-quantity' . $id];
            }
        }

        if (PosOrderInput::validateCart([['quantity' => $quantity, 'add_ons' => $add_on_ids, 'add_on_qtys' => $add_on_qtys]]) !== null) {
            return response()->json(['data' => 'quantity_error', 'message' => translate('Invalid quantity')], 422);
        }

        $addonData = PosOrderInput::resolveAddons((int) $product->restaurant_id, $add_on_ids, $add_on_qtys);
        if ($addonData === null) {
            return response()->json(['data' => 'addon_error', 'message' => translate('Invalid addon')], 422);
        }
        if (data_get($addonData, 'out_of_stock') !== null) {
            return response()->json([
                'error' => 'stock_out',
                'message' => data_get($addonData, 'out_of_stock'),
                'current_stock' => data_get($addonData, 'current_stock'),
                'id' => data_get($addonData, 'id'),
                'type' => data_get($addonData, 'type'),
            ], 203);
        }
        $addon_price = (float) $addonData['total_add_on_price'];

        $addonAndVariationStock = Helpers::addonAndVariationStockCheck(product: $product, quantity: $quantity, add_on_qtys: $add_on_qtys, variation_options: explode(',', $request?->option_ids), add_on_ids: $add_on_ids, old_selected_variations: $old_selected_variations, old_selected_without_variation: $old_selected_without_variation, old_selected_addons: $old_selected_addons);
        if (data_get($addonAndVariationStock, 'out_of_stock') != null) {
            return response()->json([
                'error' => 'stock_out',
                'message' => data_get($addonAndVariationStock, 'out_of_stock'),
                'current_stock' => data_get($addonAndVariationStock, 'current_stock'),
                'id' => data_get($addonAndVariationStock, 'id'),
                'type' => data_get($addonAndVariationStock, 'type'),
            ], 203);
        }


        $product_variations = json_decode($product->variations, true);
        if ($request->variations && count($product_variations)) {

            $price_total =  $price + Helpers::variation_price(product: $product_variations, variations: $request->variations);
            $price = $price_total - Helpers::product_discount_calculate(product: $product, price: $price_total, restaurant: $product->restaurant);
        } else {
            $price = $product->price - Helpers::product_discount_calculate(product: $product, price: $product->price, restaurant: $product->restaurant);
        }
        return array('price' => Helpers::format_currency(($price * $quantity) + $addon_price));
    }

    public function addToCart(Request $request)
    {
        $quantity = PosOrderInput::positiveInteger($request->quantity);
        if ($quantity === null) {
            return response()->json(['data' => 'quantity_error', 'message' => translate('Invalid quantity')], 422);
        }

        $product = Food::with('restaurant')->withoutGlobalScope(RestaurantScope::class)->where(['id' => $request->id])->first();
        $data = array();
        $data['id'] = $product->id;
        $str = '';
        $variations = [];
        $price = 0;
        $addon_price = 0;
        $variation_price = 0;
        $add_on_ids = [];
        $add_on_qtys = [];

        $product_variations = json_decode($product->variations, true);
        if ($request->variations && count($product_variations)) {
            foreach ($request->variations  as $key => $value) {

                if ($value['required'] == 'on' &&  isset($value['values']) == false) {
                    return response()->json([
                        'data' => 'variation_error',
                        'message' => translate('Please select items from') . ' ' . $value['name'],
                    ]);
                }
                if (isset($value['values'])  && $value['min'] != 0 && $value['min'] > count($value['values']['label'])) {
                    return response()->json([
                        'data' => 'variation_error',
                        'message' => translate('Please select minimum ') . $value['min'] . translate(' For ') . $value['name'] . '.',
                    ]);
                }
                if (isset($value['values']) && $value['max'] != 0 && $value['max'] < count($value['values']['label'])) {
                    return response()->json([
                        'data' => 'variation_error',
                        'message' => translate('Please select maximum ') . $value['max'] . translate(' For ') . $value['name'] . '.',
                    ]);
                }
            }
            $variation_data = Helpers::get_varient(product_variations: $product_variations, variations: $request->variations);
            $variation_price = $variation_data['price'];
            $variations = $request->variations;
        }
        $data['variations'] = $variations;
        $data['variant'] = $str;

        $price = $product->price + $variation_price;
        $data['variation_price'] = $variation_price;
        $data['quantity'] = $quantity;
        $data['price'] = $price;
        $data['name'] = $product->name;
        $product_discount =  Helpers::food_discount_calculate($product, $price, $product->restaurant, false);

        $data['discount'] = $product_discount['discount_amount'];
        $data['image'] = $product->image;
        $data['image_full_url'] = $product->image_full_url;
        $data['add_ons'] = [];
        $data['add_on_qtys'] = [];
        $data['maximum_cart_quantity'] = $product->maximum_cart_quantity;
        $data['variation_option_ids'] = $request?->option_ids ?? null;


        if ($request['addon_id']) {
            foreach ($request['addon_id'] as $id) {
                $add_on_ids[] = $id;
                $add_on_qtys[] = $request['addon-quantity' . $id];
            }
        }

        if (PosOrderInput::validateCart([['quantity' => $quantity, 'add_ons' => $add_on_ids, 'add_on_qtys' => $add_on_qtys]]) !== null) {
            return response()->json(['data' => 'quantity_error', 'message' => translate('Invalid quantity')], 422);
        }

        $addonData = PosOrderInput::resolveAddons((int) $product->restaurant_id, $add_on_ids, $add_on_qtys);
        if ($addonData === null) {
            return response()->json(['data' => 'addon_error', 'message' => translate('Invalid addon')], 422);
        }
        if (data_get($addonData, 'out_of_stock') !== null) {
            return response()->json([
                'data' => 'stock_out',
                'message' => data_get($addonData, 'out_of_stock'),
                'current_stock' => data_get($addonData, 'current_stock'),
                'id' => data_get($addonData, 'id'),
                'type' => data_get($addonData, 'type'),
            ], 203);
        }
        $addon_price = (float) $addonData['total_add_on_price'];
        $data['add_ons'] = array_column($addonData['addons'], 'id');
        $data['add_on_qtys'] = array_column($addonData['addons'], 'quantity');
        $add_on_ids = $data['add_ons'];
        $add_on_qtys = $data['add_on_qtys'];

        $addonAndVariationStock = Helpers::addonAndVariationStockCheck(product: $product, quantity: $quantity, add_on_qtys: $add_on_qtys, variation_options: explode(',', $request?->option_ids), add_on_ids: $add_on_ids);
        if (data_get($addonAndVariationStock, 'out_of_stock') != null) {
            return response()->json([
                'data' => 'stock_out',
                'message' => data_get($addonAndVariationStock, 'out_of_stock'),
                'current_stock' => data_get($addonAndVariationStock, 'current_stock'),
                'id' => data_get($addonAndVariationStock, 'id'),
                'type' => data_get($addonAndVariationStock, 'type'),
            ], 203);
        }




        $data['addon_price'] = $addon_price;
        if ($request->session()->has('cart')) {
            $cart = $request->session()->get('cart', collect([]));
            if (!isset($request->cart_item_key)) {

                foreach ($cart as $key => $cartItems) {
                    if ($key != 'restaurant_id'  &&  $cartItems['id'] == $request->id && (strcmp(json_encode($cartItems['variations']), json_encode($request['variations'])) === 0 || !isset($request['variations']))) {

                        if ($cartItems['maximum_cart_quantity'] >= $cartItems['quantity'] + $quantity
                            && PosOrderInput::positiveInteger($cartItems['quantity'] + $quantity) !== null) {
                            $cart = $cart->map(function ($object, $cartkey) use ($key, $quantity) {
                                if ($cartkey == $key) {
                                    $object['quantity'] = $object['quantity'] + $quantity;
                                }
                                return $object;
                            });
                            $request->session()->put('cart', $cart);

                            return response()->json([
                                'data' => 'cart_readded'
                            ]);
                        }

                        return response()->json([
                            'data' => 1
                        ]);
                    }
                }
            }

            if (isset($request->cart_item_key)) {
                $cart[$request->cart_item_key] = $data;
                $data = 2;
            } else {
                $cart->push($data);
            }
        } else {
            $cart = collect([$data, 'restaurant_id' => $product->restaurant_id]);
            $request->session()->put('cart', $cart);
        }

        $this->setPosCalculatedTax($product->restaurant);

        return response()->json([
            'data' => $data
        ]);
    }

    public function cart_items(Request $request)
    {
        $restaurant_data = Restaurant::find($request->restaurant_id);
        if ($restaurant_data && Session::get('order_type') == 'delivery') {
            $deliveryChargeData = $this->calculateDeliveryCharge(distance_data: $this->getDistance(restaurant_data: $restaurant_data), restaurant_data: $restaurant_data, totalProductPrice: Session::get('cart_total_price')??0);
            Session::put('delivery_charge', $deliveryChargeData['delivery_charge']);
            Session::put('delivery_charge_display', $deliveryChargeData['original_delivery_charge']);
        }
        return response()->json([
            'pos_mobile_menu' => view('admin-views.pos._pos_mobile_menu', compact('restaurant_data'))->render(),
            'cart' => view('admin-views.pos._cart', compact('restaurant_data'))->render(),
        ]);
    }


    //removes from Cart
    public function removeFromCart(Request $request)
    {
        if ($request->session()->has('cart')) {
            $cart = $request->session()->get('cart', collect([]));
            if (isset($cart[$request->key])) {
                $item_id = $cart[$request->key]['id'];
                $product = Food::withoutGlobalScope(RestaurantScope::class)->find($item_id);
            }
            $cart->forget($request->key);
            $restaurant_id = $cart['restaurant_id'] ?? null;
            $cart = $cart->except('restaurant_id')->values();
            if ($restaurant_id !== null) {
                $cart->put('restaurant_id', $restaurant_id);
            }

            $request->session()->put('cart', $cart);
            if ($cart->except('restaurant_id')->isNotEmpty() && isset($product) && $product->restaurant) {
                $this->setPosCalculatedTax($product->restaurant);
            }
        }

        return response()->json([], 200);
    }

    //updated the quantity for a cart item
    public function updateQuantity(Request $request)
    {
        $quantity = PosOrderInput::positiveInteger($request->quantity);
        if ($quantity === null) {
            return response()->json(['data' => 'quantity_error', 'message' => translate('Invalid quantity')], 422);
        }

        $product = Food::withoutGlobalScope(RestaurantScope::class)->find($request->food_id);
        if ($request->option_ids) {
            $addonAndVariationStock = Helpers::addonAndVariationStockCheck(product: $product, quantity: $quantity, variation_options: explode(',', $request?->option_ids));
            if (data_get($addonAndVariationStock, 'out_of_stock') != null) {
                return response()->json([
                    'data' => 'stock_out',
                    'message' => data_get($addonAndVariationStock, 'out_of_stock'),
                    'current_stock' => data_get($addonAndVariationStock, 'current_stock'),
                    'id' => data_get($addonAndVariationStock, 'id'),
                    'type' => data_get($addonAndVariationStock, 'type'),
                ], 203);
            }
        }
        $cart = $request->session()->get('cart', collect([]));
        $cart = $cart->map(function ($object, $key) use ($request, $quantity) {
            if ($key == $request->key) {
                $object['quantity'] = $quantity;
            }
            return $object;
        });
        $request->session()->put('cart', $cart);



        try {
            $product_id = $cart[$request->key]['id'];
            $product = Food::withoutGlobalScope(RestaurantScope::class)->with('restaurant')->find($product_id);
            if ($product && $product->restaurant) {
                $this->setPosCalculatedTax($product->restaurant);
            }
        } catch (\Exception $exception) {
            info([$exception->getFile(), $exception->getLine(), $exception->getMessage()]);
        }

        return response()->json([], 200);
    }

    //empty Cart
    public function emptyCart(Request $request)
    {
        session()->forget('cart');
        session()->forget('tax_amount');
        session()->forget('tax_included');
        session()->forget('address');

        $this->clearSessionData();
        return response()->json([], 200);
    }

    public function get_customers(Request $request)
    {
        $key = explode(' ', $request['q']);
        $data = User::where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('f_name', 'like', "%{$value}%")
                    ->orWhere('l_name', 'like', "%{$value}%")
                    ->orWhere('phone', 'like', "%{$value}%");
            }
        })
            ->where('status', 1)
            ->limit(8)
            ->get([DB::raw('id, CONCAT(f_name, " ", l_name, " (", phone ,")") as text')]);

        return response()->json($data);
    }

    public function place_order(Request $request)
    {
        $request->validate([
            'type' => 'required',
            'user_id' => 'required',
        ], [
            'type.required' => translate('Payment method is required'),
            'user_id.required' => translate('no_customer_is_selected'),
        ]);

        if ($request->session()->has('cart')) {
            if (count($request->session()->get('cart')) < 1) {
                Toastr::error(translate('Your cart is empty'));
                return back();
            }
        } else {
            Toastr::error(translate('Your cart is empty'));
            return back();
        }

        $customer = User::find($request->user_id);

        if ($request->type == 'wallet' && Helpers::get_business_settings('wallet_status', false) != 1) {
            Toastr::error(translate('messages.customer_wallet_is_disable'));
            return back()->withInput()->with('customer', $customer);
        }
        $restaurant = Restaurant::find($request->restaurant_id);
        if (!$restaurant) {
            Toastr::error(translate('messages.Sorry_the_restaurant_is_not_available'));
            return back()->withInput()->with('customer', $customer);
        }

        $rest_sub = $restaurant?->restaurant_sub;
        if ($restaurant->restaurant_model == 'subscription' && isset($rest_sub)) {
            if ($rest_sub->max_order != "unlimited" && $rest_sub->max_order <= 0) {
                Toastr::error(translate('messages.You_have_reached_the_maximum_number_of_orders'));
                return back();
            }
        } elseif ($restaurant->restaurant_model == 'unsubscribed') {
            Toastr::error(translate('messages.You_are_not_subscribed_or_your_subscription_has_expired'));
            return back();
        }

        $cart = $request->session()->get('cart');
        $request->merge([
            'delivery_type' => Session::get('delivery_type', 'standard'),
        ]);

        DB::beginTransaction();
        $order = $this->placePosOrder($cart, $request, $restaurant, $this->getDistance($restaurant));

        if (data_get($order, 'status_code') === 200) {
            DB::commit();
            $order = data_get($order, 'order');
            try {
                if ($order?->customer) {
                    Helpers::send_order_notification($order);
                }

                $notification_status = Helpers::getNotificationStatusData('customer', 'customer_order_notification');

                if ($notification_status?->mail_status == 'active' && $order->order_status == 'pending' && config('mail.status') &&  Helpers::get_mail_status('place_order_mail_status_user') == '1' && $order?->customer?->email) {
                    Mail::to($order?->customer?->getRawOriginal('email'))->send(new PlaceOrder($order->id));
                }
            } catch (\Exception $exception) {
                info([$exception->getFile(), $exception->getLine(), $exception->getMessage()]);
            }
            Toastr::success(translate('messages.order_placed_successfully'));
            return back();
        } else {
            DB::rollBack();
        }

        Toastr::error(data_get($order, 'message'));
        return back();
    }


    public function customer_store(Request $request)
    {
        $request->validate([
            'f_name' => 'required',
            'l_name' => 'required',
            'email' => 'required|email|unique:users',
            'phone' => 'required|unique:users',
        ]);
        $customer = new User();
        $customer->f_name = $request['f_name'];
        $customer->l_name = $request['l_name'];
        $customer->email = $request['email'];
        $customer->phone = $request['phone'];
        $customer->password = bcrypt('password');
        $customer->save();

        try {
            $notification_status = Helpers::getNotificationStatusData('customer', 'customer_pos_registration');

            if ($notification_status?->mail_status == 'active' && config('mail.status') && $request->email && Helpers::get_mail_status('pos_registration_mail_status_user') == '1') {
                Mail::to($request?->getRawOriginal('email'))->send(new \App\Mail\CustomerRegistrationPOS($request->f_name . ' ' . $request->l_name, $request['email'], 'password'));
                Toastr::success(translate('mail_sent_to_the_user'));
            }
        } catch (\Exception $ex) {
            info($ex->getMessage());
        }
        Toastr::success(translate('customer_added_successfully'));
        return back()->with('customer', $customer);
    }


    public function extra_charge(Request $request)
    {
        $distance_data = $request->distancMileResult ?? 1;
        $self_delivery_status = $request->self_delivery_status;
        $extra_charges = 0;
        if ($self_delivery_status != 1) {
            $data = Helpers::vehicle_extra_charge(distance_data: $distance_data);
            $vehicle_id = (isset($data) ? $data['vehicle_id']  : null);
            $extra_charges = (float) (isset($data) ? $data['extra_charge']  : 0);
        }
        return response()->json($extra_charges, 200);
    }


    public function getUserData(Request $request)
    {
        if ($request->customer_id) {
            $user = User::where('id', $request->customer_id)->select('id', 'f_name', 'l_name', 'phone', 'wallet_balance', 'email')->first();
            if ($user) {
                $user = [
                    'id' => $user->id,
                    'customer_email' => $user->email,
                    'customer_name' => $user->f_name . ' ' . $user->l_name,
                    'customer_phone' => ' (' . $user->phone . ')',
                    'contact_person_number' => $user->phone,
                    'customer_wallet' => Helpers::format_currency($user->wallet_balance),
                ];
            }
            Session::put('customer_id', $request->customer_id);

            $address = CustomerAddress::where('user_id', $request->customer_id)->get();
            $selectedAddress = $address->sortByDesc('created_at')->first();
            Session::put('address_id', $selectedAddress?->id);

            if (Session::get('pos_restaurant_id')) {
                $restaurant =  Restaurant::where('id', Session::get('pos_restaurant_id'))->first();
                $deliveryChargeData = $this->calculateDeliveryCharge(distance_data: $this->getDistance($restaurant), restaurant_data: $restaurant, totalProductPrice: Session::get('cart_total_price')??0);
                Session::put('delivery_charge', $deliveryChargeData['delivery_charge']);
                Session::put('delivery_charge_display', $deliveryChargeData['original_delivery_charge']);
            }

            return response()->json([
                'user' => $user,
                'view' => view('admin-views.pos._customer_address', compact('address'))->render(),
            ], 200);
        }
        return response()->json([], 200);
    }
    public function getUserAddress(Request $request)
    {
        $address = CustomerAddress::where('user_id', $request->customer_id)->get();
        $selectedAddress = $address->sortByDesc('created_at')->first();
        Session::put('address_id', $selectedAddress?->id);
        Session::put('order_type', 'delivery');
        $restaurant =  Restaurant::where('id', Session::get('pos_restaurant_id'))->first();
        if ($restaurant) {
            $deliveryChargeData = $this->calculateDeliveryCharge(distance_data: $this->getDistance($restaurant), restaurant_data: $restaurant, totalProductPrice: Session::get('cart_total_price')??0);
            Session::put('delivery_charge', $deliveryChargeData['delivery_charge']);
            Session::put('delivery_charge_display', $deliveryChargeData['original_delivery_charge']);
        }

        return response()->json([
            'view' => view('admin-views.pos._customer_address', compact('address'))->render(),
            // 'cart' => view('admin-views.pos._cart')->render(),

        ]);
    }

    public function chooseAddress(Request $request)
    {
        $address = CustomerAddress::where('user_id', $request->customer_id)->get();
        $selectedAddress = $address->where('id', $request->address_id)->first();
        Session::put('address_id', $selectedAddress?->id);
        $restaurant =  Restaurant::where('id', Session::get('pos_restaurant_id'))->first();
        if ($restaurant) {
            $deliveryChargeData = $this->calculateDeliveryCharge(distance_data: $this->getDistance($restaurant), restaurant_data: $restaurant, totalProductPrice: Session::get('cart_total_price')??0);
            Session::put('delivery_charge', $deliveryChargeData['delivery_charge']);
            Session::put('delivery_charge_display', $deliveryChargeData['original_delivery_charge']);
        }
        return response()->json([
            'id' => $selectedAddress?->id,
            'view' => view('admin-views.pos._customer_address', compact('address', 'selectedAddress'))->render(),
        ]);
    }
    public function editAddress(Request $request)
    {
        $address = CustomerAddress::where('id', $request->address_id)->first();
        return response()->json([
            'latitude' => $address?->latitude,
            'longitude' => $address?->longitude,
            'view' => view('admin-views.pos._address', compact('address'))->render(),
        ]);
    }
    public function clearUserData()
    {
        $this->clearSessionData();
        return response()->json(200);
    }

    private function clearSessionData()
    {
        Session::forget('cart_total_price');
        Session::forget('delivery_charge');
        Session::forget('delivery_charge_display');
        Session::forget('delivery_type');
        Session::forget('delivery_type_charge');
        Session::forget('restaurant_id_pos');
        Session::forget('order_type');
        Session::forget('customer_id');
        Session::forget('address_id');
        return true;
    }

    public function setOrderType(Request $request)
    {
        Session::put('order_type', $request->type);
        if ($request->type != 'delivery') {
            Session::forget('address_id');
            Session::forget('delivery_charge');
            Session::forget('delivery_charge_display');
            Session::forget('delivery_type');
            Session::forget('delivery_type_charge');
        }

        return response()->json([$request->type], 200);
    }


    public function addDeliveryInfo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'contact_person_name' => 'required|max:50',
            'contact_person_number' => 'required|max:20',
            'longitude' => 'required',
            'customer_id' => 'required',
            'latitude' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }

        $data = [
            'contact_person_name' => $request->contact_person_name,
            'contact_person_number' => $request->contact_person_number,
            'address_type' => $request->address_type ?? 'delivery',
            'address' => $request->address,
            'floor' => $request->floor,
            'road' => $request->road,
            'house' => $request->house,
            'longitude' => (string)$request->longitude,
            'latitude' => (string)$request->latitude,
        ];

        $selectedAddress = CustomerAddress::updateOrCreate(
            [
                'id' => $request->address_id,
                'user_id' => $request->customer_id
            ],
            $data
        );

        $address = CustomerAddress::where('user_id', $request->customer_id)->get();
        Session::put('address_id', $selectedAddress->id);

        $restaurant =  Restaurant::where('id', Session::get('pos_restaurant_id'))->first();
        if ($restaurant) {
            $deliveryChargeData = $this->calculateDeliveryCharge(distance_data: $this->getDistance($restaurant), restaurant_data: $restaurant, totalProductPrice: Session::get('cart_total_price')??0);
            Session::put('delivery_charge', $deliveryChargeData['delivery_charge']);
            Session::put('delivery_charge_display', $deliveryChargeData['original_delivery_charge']);
        }
        
        return response()->json([
            'data' => $data,
            'view' => view('admin-views.pos._customer_address', compact('address', 'selectedAddress'))->render(),
        ]);
    }

    public function getDeliveryTypes(Request $request)
    {
        $restaurant = Restaurant::find(Session::get('pos_restaurant_id'));
        $zone = Zone::with('deliveryOptions')->active()->find($restaurant?->zone_id);

        if (!$zone || !$zone->additional_delivery_option_status) {
            return response()->json([]);
        }

        $currentDeliveryCharge = (float) (Session::get('delivery_charge') ?? 0);
        $zoneMinDeliveryCharge = (float) ($zone->minimum_delivery_charge ?? 0);
        
        if ($currentDeliveryCharge > 0 && $zoneMinDeliveryCharge > 0 && $currentDeliveryCharge < $zoneMinDeliveryCharge) {
            return response()->json([
                'options' => [],
                'current_delivery_charge' => $currentDeliveryCharge
            ]);
        }

        $options = $zone->deliveryOptions->map(function ($option) {
            return [
                'id' => $option->id,
                'delivery_type' => $option->delivery_type,
                'delivery_type_text' => translate($option->delivery_type),
                'extra_charge' => $option->extra_charge,
                'reduce_charge' => $option->reduce_charge,
                'add_delivery_time' => $option->add_delivery_time,
                'reduce_delivery_time' => $option->reduce_delivery_time,
            ];
        });

        return response()->json([
            'options' => $options,
            'current_delivery_charge' => $currentDeliveryCharge
        ]);
    }

    public function setDeliveryType(Request $request)
    {
        $restaurant = Restaurant::find(Session::get('pos_restaurant_id'));
        $resolution = DeliveryOptionLogic::resolve(
            $restaurant?->zone,
            $request->delivery_type,
            (float) Session::get('delivery_charge', 0)
        );

        Session::put('delivery_type', $resolution['delivery_type']);
        Session::put('delivery_type_charge', $resolution['delivery_type_charge']);

        return response()->json($resolution, 200);
    }
}
