<?php

namespace App\Http\Controllers\Vendor;

use App\Models\DataSetting;
use App\Models\Zone;
use App\Scopes\RestaurantScope;
use App\Traits\PlaceNewOrder;
use Carbon\Carbon;
use App\Models\Food;
use App\Models\User;
use App\Models\Order;
use App\Mail\PlaceOrder;
use App\Models\Category;
use Illuminate\Http\Request;
use App\CentralLogics\DeliveryOptionLogic;
use App\CentralLogics\Helpers;
use App\CentralLogics\PosOrderInput;
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
        $category = $request->query('category_id', 0);
        $categories = Category::active()->without('storage')->select('id', 'name')->get();
        $keyword = $request->query('keyword', false);
        $key = explode(' ', $keyword);
        $products = Food::active()->when($category, function ($query) use ($category) {
                $query->whereHas('category', function ($q) use ($category) {
                    return $q->whereId($category)->orWhere('parent_id', $category);
                });
            })
            ->when($keyword, function ($query) use ($key) {
                return $query->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('name', 'like', "%{$value}%");
                    }
                });
            })
            ->available($time)
            ->latest()->paginate(10);
        $order = Order::with('restaurant')->find(session('last_order'));
        $restaurant_data = Helpers::get_restaurant_data();
        $customer = null;

        if (Session::get('customer_id')) {
            $customer = User::find(Session::get('customer_id'));
        }
        $invoiceSettings = DataSetting::invoiceSettings();

        return view('vendor-views.pos.index', compact('categories', 'products', 'category', 'keyword', 'restaurant_data', 'order', 'customer','invoiceSettings'));
    }

    public function quick_view(Request $request)
    {
        $product = Food::findOrFail($request->product_id);

        return response()->json([
            'success' => 1,
            'view' => view('vendor-views.pos._quick-view-data', compact('product'))->render(),
        ]);
    }

    public function quick_view_card_item(Request $request)
    {
        $product = Food::findOrFail($request->product_id);
        $item_key = $request->item_key;
        $cart_item = session()->get('cart')[$item_key];

        return response()->json([
            'success' => 1,
            'view' => view('vendor-views.pos._quick-view-cart-item', compact('product', 'cart_item', 'item_key'))->render(),
        ]);
    }

    public function variant_price(Request $request)
    {
        $quantity = PosOrderInput::positiveInteger($request->quantity);
        if ($quantity === null) {
            return response()->json(['data' => 'quantity_error', 'message' => translate('Invalid quantity')], 422);
        }

        $product = Food::find($request->id);
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

        $addonAndVariationStock = Helpers::addonAndVariationStockCheck(product: $product, quantity: $quantity, add_on_qtys: $add_on_qtys, variation_options: explode(',', $request?->option_ids), add_on_ids: $add_on_ids);
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
            $price = $price_total - Helpers::product_discount_calculate(product: $product, price: $price_total, restaurant: Helpers::get_restaurant_data());
        } else {
            $price = $product->price - Helpers::product_discount_calculate(product: $product, price: $product->price, restaurant: Helpers::get_restaurant_data());
        }
        return array('price' => Helpers::format_currency(($price * $quantity) + $addon_price));
    }



    public function addToCart(Request $request)
    {
        $quantity = PosOrderInput::positiveInteger($request->quantity);
        if ($quantity === null) {
            return response()->json(['data' => 'quantity_error', 'message' => translate('Invalid quantity')], 422);
        }

        $product = Food::find($request->id);

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
            if (isset($request->cart_item_key)) {
                $cart[$request->cart_item_key] = $data;
                $data = 2;
            } else {
                $cart->push($data);
            }
        } else {
            $cart = collect([$data]);
            $request->session()->put('cart', $cart);
        }

        $this->setPosCalculatedTax($product->restaurant);

        return response()->json([
            'data' => $data
        ]);
    }

    public function cart_items()
    {
        if (Session::get('order_type') == 'delivery') {
            $deliveryChargeData = $this->calculateDeliveryCharge($this->getDistance(Helpers::get_restaurant_data()), Helpers::get_restaurant_data(),Session::get('cart_total_price')??0);
            Session::put('delivery_charge', $deliveryChargeData['delivery_charge']);
            Session::put('delivery_charge_display', $deliveryChargeData['original_delivery_charge']);
        }
        $restaurant_data = Helpers::get_restaurant_data();
        return response()->json([
            'pos_mobile_menu' => view('vendor-views.pos._pos_mobile_menu',compact('restaurant_data'))->render(),
            'cart' => view('vendor-views.pos._cart')->render(),
        ]);
    }

    //removes from Cart
    public function removeFromCart(Request $request)
    {
        if ($request->session()->has('cart')) {
            $cart = $request->session()->get('cart', collect([]));

            $cart->forget($request->key);
            $request->session()->put('cart', $cart);
            if (isset($cart[$request->key])) {
                $item_id = $cart[$request->key]['id'];
                $product = Food::withoutGlobalScope(RestaurantScope::class)->find($item_id);
            }

            if ( isset($product) && $product->restaurant) {
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

        $product = Food::find($request->food_id);
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
            info((string)[$exception->getFile(), $exception->getLine(), $exception->getMessage()]);
        }

        return response()->json([], 200);
    }

    //empty Cart
    public function emptyCart()
    {
        session()->forget('cart');
        session()->forget('tax_amount');
        session()->forget('tax_included');
        $this->clearSessionData();
        return response()->json([], 200);
    }

    public function update_paid(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'paid' => 'required|numeric|min:0',
        ], [
            'paid.required' => translate('Payment amount is required'),
            'paid.numeric' => translate('Payment amount must be a number'),
            'paid.min' => translate('Payment amount must be greater than 0'),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }


        if(round($request->paid, 2) < round($request->total_amount, 2)){
                $validator->getMessageBag()->add('name', translate('messages.paid_amount_can_not_be_less_then_total_amount'));
                    return response()->json(['errors' => Helpers::error_processor($validator)]);
        }

        $cart = $request->session()->get('cart', collect([]));
        $cart['paid'] = $request->paid;
        $request->session()->put('cart', $cart);
        return back();
    }

    public function get_customers(Request $request)
    {
        $key = explode(' ', $request['q']);
        $restaurantId = Helpers::get_restaurant_id();
        $data = User::whereHas('orders', function ($query) use ($restaurantId) {
                $query->where('restaurant_id', $restaurantId);
            })
            ->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('f_name', 'like', "%{$value}%")
                        ->orWhere('l_name', 'like', "%{$value}%")
                        ->orWhere('phone', 'like', "%{$value}%");
                }
            })
            ->limit(8)
            ->get([DB::raw('id, CONCAT(f_name, " ", l_name, " (", phone ,")") as text')]);

        $data[] = (object)['id' => false, 'text' => translate('messages.walk_in_customer')];

        $reversed = $data->toArray();

        $data = array_reverse($reversed);


        return response()->json($data);
    }

    public function place_order(Request $request)
    {
      $request->validate([
            'type' => 'required',
        ], [
            'type.required' => translate('Payment method is required'),
        ]);

        if ($request->session()->has('cart')) {
            if (count($request->session()->get('cart')) < 1 ) {
                Toastr::error(translate('Your cart is empty'));
                return back();
            }
        } else {
            Toastr::error(translate('Your cart is empty'));
            return back();
        }
        if($request->type != 'wallet' && $request->amount && round($request->amount, 2) < round($request->total_amount, 2)){
            Toastr::error(translate('messages.paid_amount_can_not_be_less_then_total_amount'));
            return back();
        }
        $restaurant = Helpers::get_restaurant_data();

        // [哪吒 退出结算] 门店退出进行中(settling)停止 POS 建单(DESIGN §C1)。
        if (\App\CentralLogics\NezhaOffboard::is_frozen($restaurant)) {
            Toastr::error(translate('门店正在办理退出结算, 已停止接单'));
            return back();
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
            'delivery_type' => session('delivery_type', 'standard'),
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
        // Toastr::warning(translate('messages.failed_to_place_order'));
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
        Session::put('customer_id', $customer->id);
        try {
            $notification_status = Helpers::getNotificationStatusData('customer', 'customer_pos_registration');

            if ($notification_status?->mail_status == 'active' && config('mail.status') && $request->email && Helpers::get_mail_status('pos_registration_mail_status_user') == '1') {
                Mail::to($request->email)->send(new \App\Mail\CustomerRegistrationPOS($request->f_name . ' ' . $request->l_name, $request['email'], 'password'));
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
            if(!\App\Models\Order::where('user_id', $request->customer_id)->where('restaurant_id', \App\CentralLogics\Helpers::get_restaurant_id())->exists()){ return response()->json(['user'=>null,'view'=>''], 200); }
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
            $deliveryChargeData = $this->calculateDeliveryCharge($this->getDistance(Helpers::get_restaurant_data()), Helpers::get_restaurant_data(),Session::get('cart_total_price')??0);
            Session::put('delivery_charge', $deliveryChargeData['delivery_charge']);
            Session::put('delivery_charge_display', $deliveryChargeData['original_delivery_charge']);



            return response()->json([
                'user' => $user,
                'view' => view('vendor-views.pos._customer_address', compact('address'))->render(),
            ], 200);
        }
        return response()->json([], 200);
    }
    public function getUserAddress(Request $request)
    {
        if(!\App\Models\Order::where('user_id', $request->customer_id)->where('restaurant_id', \App\CentralLogics\Helpers::get_restaurant_id())->exists()){ return response()->json(['view'=>'','cart'=>''], 200); }
        $address = CustomerAddress::where('user_id', $request->customer_id)->get();
        $selectedAddress = $address->sortByDesc('created_at')->first();
        Session::put('address_id', $selectedAddress?->id);
        Session::put('order_type', 'delivery');
        $deliveryChargeData = $this->calculateDeliveryCharge($this->getDistance(Helpers::get_restaurant_data()), Helpers::get_restaurant_data(),Session::get('cart_total_price')??0);
        Session::put('delivery_charge', $deliveryChargeData['delivery_charge']);
        Session::put('delivery_charge_display', $deliveryChargeData['original_delivery_charge']);

        return response()->json([
            'view' => view('vendor-views.pos._customer_address', compact('address'))->render(),
            'cart' => view('vendor-views.pos._cart')->render(),

        ]);
    }

    public function chooseAddress(Request $request)
    {
        if(!\App\Models\Order::where('user_id', $request->customer_id)->where('restaurant_id', \App\CentralLogics\Helpers::get_restaurant_id())->exists()){ return response()->json(['id'=>null,'view'=>''], 200); }
        $address = CustomerAddress::where('user_id', $request->customer_id)->get();
        $selectedAddress = $address->where('id', $request->address_id)->first();
        Session::put('address_id', $selectedAddress?->id);
        $deliveryChargeData = $this->calculateDeliveryCharge($this->getDistance(Helpers::get_restaurant_data()), Helpers::get_restaurant_data(),Session::get('cart_total_price')??0);
        Session::put('delivery_charge', $deliveryChargeData['delivery_charge']);
        Session::put('delivery_charge_display', $deliveryChargeData['original_delivery_charge']);

        return response()->json([
            'id' => $selectedAddress?->id,
            'view' => view('vendor-views.pos._customer_address', compact('address', 'selectedAddress'))->render(),
        ]);
    }
    public function editAddress(Request $request)
    {
        $address = CustomerAddress::where('id', $request->address_id)->first();
        if($address && !\App\Models\Order::where('user_id', $address->user_id)->where('restaurant_id', \App\CentralLogics\Helpers::get_restaurant_id())->exists()){ return response()->json(['latitude'=>null,'longitude'=>null,'view'=>''], 200); }
        return response()->json([
            'latitude' => $address?->latitude,
            'longitude' => $address?->longitude,
            'view' => view('vendor-views.pos._address', compact('address'))->render(),
        ]);
    }
    public function clearUserData()
    {
        $this->clearSessionData();
        return response()->json(200);
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

    private function clearSessionData(){
        Session::forget('cart_total_price');
        Session::forget('delivery_charge');
        Session::forget('delivery_charge_display');
        Session::forget('delivery_type');
        Session::forget('delivery_type_charge');
        Session::forget('order_type');
        Session::forget('customer_id');
        Session::forget('address_id');
        return true;
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

        $customerId = (int) $request->customer_id;
        abort_unless($customerId > 0 && (int) Session::get('customer_id') === $customerId, 404);

        if ($request->filled('address_id')) {
            CustomerAddress::where('id', $request->address_id)
                ->where('user_id', $customerId)
                ->firstOrFail();
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
                'user_id' => $customerId
            ],
            $data
        );

        $address = CustomerAddress::where('user_id', $customerId)->get();
        Session::put('address_id', $selectedAddress->id);
        $deliveryChargeData = $this->calculateDeliveryCharge($this->getDistance(Helpers::get_restaurant_data()), Helpers::get_restaurant_data(),Session::get('cart_total_price')??0);
        Session::put('delivery_charge', $deliveryChargeData['delivery_charge']);
        Session::put('delivery_charge_display', $deliveryChargeData['original_delivery_charge']);

        return response()->json([
            'data' => $data,
            'view' => view('vendor-views.pos._customer_address', compact('address', 'selectedAddress'))->render(),
        ]);
    }

    public function setDeliveryType(Request $request)
    {
        $restaurant = Helpers::get_restaurant_data();
        $resolution = DeliveryOptionLogic::resolve(
            $restaurant?->zone,
            $request->delivery_type,
            (float) Session::get('delivery_charge', 0)
        );

        Session::put('delivery_type', $resolution['delivery_type']);
        Session::put('delivery_type_charge', $resolution['delivery_type_charge']);

        return response()->json(['status' => 'success'] + $resolution);
    }

    public function getDeliveryTypes(Request $request)
    {
        $restaurant = Helpers::get_restaurant_data();
        $zone = Zone::with('deliveryOptions')->active()->find($restaurant?->zone_id);

        if (!$zone || !$zone->additional_delivery_option_status) {
            return response()->json([]);
        }

        $currentDeliveryCharge = (float) (Session::get('delivery_charge') ?? 0);
        $zoneMinDeliveryCharge = (float) ($zone->minimum_delivery_charge ?? 0);

        // Mirror the final server-side resolver: do not offer an option that cannot persist.
        if ($currentDeliveryCharge <= 0 || ($zoneMinDeliveryCharge > 0 && $currentDeliveryCharge < $zoneMinDeliveryCharge)) {
            return response()->json([]);
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

        return response()->json($options);
    }


}
