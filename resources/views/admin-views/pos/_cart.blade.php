
<div class="d-flex flex-row initial-47">
    <table class="table table-align-middle">
        <thead class="thead-light border-0 text-center">
        <tr>
            <th class="py-2" scope="col">{{translate('messages.item')}}</th>
            <th class="py-2" scope="col" class="text-center">{{translate('messages.qty')}}</th>
            <th class="py-2" scope="col">{{translate('messages.price')}}</th>
            <th class="py-2" scope="col">{{translate('messages.delete')}}</th>
        </tr>
        </thead>
        <tbody>
        <?php
        use App\CentralLogics\Helpers;
        $subtotal = 0;
        $addon_price = 0;
        $tax = 0;


        $discount_on_product = 0;
        $variation_price = 0;
        $show_ddditional_price = false;
        ?>
        @if(session()->has('cart') && count( session()->get('cart')) > 0 && data_get(session()->get('cart'),0))
                <?php
                $cart = session()->get('cart');
                $show_ddditional_price=true;
                ?>

            @foreach(session()->get('cart') as $key => $cartItem)
                @if(is_array($cartItem))
                        <?php
                        $variation_price += $cartItem['variation_price'];
                        $product_subtotal = ($cartItem['price']) * $cartItem['quantity'];
                        $discount_on_product += $cartItem['discount'];
                        $subtotal += $product_subtotal;
                        $addon_price += $cartItem['addon_price'];
                        ?>
                    <tr>
                        <td class="media cart--media align-items-center cursor-pointer quick-View-Cart-Item"
                            data-product-id="{{$cartItem['id']}}" data-item-key="{{$key}}">
                            <img class="avatar avatar-sm mr-2 onerror-image"
                                 src="{{ $cartItem['image_full_url'] }}"
                             data-onerror-image="{{dynamicAsset('assets/admin/img/160x160/img2.jpg')}}"
                             alt="{{data_get($cartItem,'image')}} image">


                            <div class="media-body">
                                <h5 class="text-hover-primary mb-0">{{Str::limit($cartItem['name'], 10)}}</h5>
                                <small>{{Str::limit($cartItem['variant'], 20)}}</small>
                            </div>
                        </td>
                        <td class="align-items-center text-center">
                            <label>
                                <input type="number" data-key="{{$key}}"
                                data-value="{{$cartItem['quantity']}}"
                                data-option_ids="{{  $cartItem['variation_option_ids']  }}"
                                data-food_id="{{  $cartItem['id']  }}"

                                class="w-50px text-center rounded border  update-Quantity"
                                       value="{{$cartItem['quantity']}}" min="1"
                                       max="{{$cartItem['maximum_cart_quantity'] ?? '9999999999'}}" >
                            </label>
                        </td>
                        <td class="text-center px-0 py-1">
                            <div class="btn">
                                {{Helpers::format_currency($product_subtotal)}}
                            </div>
                        </td>
                        <td class="align-items-center">
                            <div class="btn--container justify-content-center">
                                <a href="javascript:"
                                   data-product-id="{{$key}}"
                                   class="btn btn-sm btn--danger action-btn btn-outline-danger remove-From-Cart"> <i
                                        class="tio-delete-outlined"></i></a>
                            </div>
                        </td>
                    </tr>
                @endif

                @endforeach
                @else

                <tr>
                    <td colspan="4">
                        <div class="py-5 text-center border-bottom">
                             <div class="">
                                 <img src="{{dynamicAsset('assets/admin/img/no-items_add.png')}}" alt="img" class="mb-2">
                                 <div class="mb-0">
                                     <span class="fs-14 text-muted">{{translate('messages.No Items added yet')}}</span>
                                 </div>
                             </div>
                         </div>

                    </td>
                </tr>

                @endif


        </tbody>
    </table>
</div>

<?php
$add = false;

$zone = $restaurant_data ? \App\Models\Zone::find($restaurant_data->zone_id) : null;
$additional_delivery_status = $zone ? $zone->additional_delivery_option_status : false;
$is_delivery = session()->get('order_type') == 'delivery';

$delivery_fee = $show_ddditional_price ? session()->get('delivery_charge') : 0;

// Zero out delivery_type_charge when delivery is free or below zone minimum_delivery_charge
$zone_min_delivery_charge = (float) ($zone?->minimum_delivery_charge ?? 0);
$is_charge_eligible = $delivery_fee > 0 && ($zone_min_delivery_charge <= 0 || $delivery_fee >= $zone_min_delivery_charge);
$delivery_type_charge = ($show_ddditional_price && $additional_delivery_status && $is_delivery && $is_charge_eligible) ? session()->get('delivery_type_charge', 0) : 0;
$total = $subtotal + $addon_price;

$total = $total - $discount_on_product;
$tax_amount = $show_ddditional_price? session()->get('tax_amount') : 0;
$tax_included = $show_ddditional_price? session()->get('tax_included')  ?? 0 : 0;

$additional_charge= 0.00;
if(Helpers::get_business_data('additional_charge_status')){
   $additional_charge= $show_ddditional_price ? Helpers::get_business_data('additional_charge') : 0;
}
$extra_packaging_data  =  Helpers::get_business_settings('extra_packaging_charge')   ?? 0;
$extra_packaging_amount =  ($show_ddditional_price && $extra_packaging_data == 1 && $restaurant_data?->restaurant_config?->is_extra_packaging_active == 1  && $restaurant_data?->restaurant_config?->extra_packaging_status == 1) ? $restaurant_data?->restaurant_config?->extra_packaging_amount : 0;

$total = $total + $delivery_fee + $delivery_type_charge;
if (isset($cart['paid'])) {
    $paid = $cart['paid'];
    $change = $total +  $tax_amount + $additional_charge + $extra_packaging_amount - $paid;
} else {
    $paid = $total +  $tax_amount + $additional_charge + $extra_packaging_amount;
    $change = 0;
}
$delivery_option_order_amount = max(0, $subtotal + $addon_price - $discount_on_product);
?>
<form action="{{ route('admin.pos.order') }}?restaurant_id={{  $restaurant_data?->id ?? '' }}" id='order_place' method="post">
    @csrf
    <input type="hidden" value="{{ $food_id ?? null }}" id="cart_food_id">
    <input type="hidden" name="user_id" id="customer_id">
    <input type="hidden" name="delivery_type" value="{{ session()->get('delivery_type') }}">
    <input type="hidden" name="delivery_type_charge" value="{{ $delivery_type_charge }}">
    <input type="hidden" id="delivery_charge_display" value="{{ session()->get('delivery_charge_display', $delivery_fee) }}">
    <input type="hidden" id="delivery_option_order_amount" value="{{ $delivery_option_order_amount }}">
    <div class="box p-3">
        <dl class="row">

            <dt class="col-6 font-regular">{{translate('messages.addon')}}:</dt>
            <dd class="col-6 text-right">{{Helpers::format_currency($addon_price)}}</dd>

            <dt class="col-6 font-regular">{{translate('messages.subtotal')}}

                @if ($tax_included ==  1)
                    ({{ translate('messages.TAX_Included') }})
                @endif
                :
            </dt>
            <dd class="col-6 text-right">{{Helpers::format_currency($subtotal+$addon_price)}}</dd>

            @if ($delivery_type_charge != 0)
                @php
                    $deliveryTypeLabels = [
                        'standard'      => translate('Standard Delivery'),
                        'express'       => translate('Express Delivery'),
                        'slightly_delay'=> translate('Slightly Delay Delivery'),
                    ];
                    $deliveryTypeLabel = $deliveryTypeLabels[session('delivery_type')] ?? translate('messages.delivery_type_charge');
                @endphp
                <dt class="col-6 font-regular d-none">{{ $deliveryTypeLabel }} :</dt>
                <dd class="col-6 text-right d-none">{{ Helpers::format_currency($delivery_type_charge) }}</dd>
            @endif
            <dt class="col-6 font-regular">{{translate('messages.discount')}} :</dt>
            <dd class="col-6 text-right">- {{Helpers::format_currency(round($discount_on_product,2))}}</dd>
            <dt class="col-6 font-regular">{{ translate('messages.delivery_fee') }} {{ $delivery_type_charge != 0 ? '('.$deliveryTypeLabel.')' : '' }} :</dt>
            <dd class="col-6 text-right" id="delivery_price">
                {{ Helpers::format_currency($delivery_fee + $delivery_type_charge) }}
            </dd>
            @if ($tax_included != 1)
                <dt class="col-6 font-regular">{{ translate('messages.vat/tax') }}:</dt>
                <dd class="col-6 text-right">
                    {{Helpers::format_currency(round($tax_amount,2))}}
                </dd>
            @endif

           @if (Helpers::get_business_data('additional_charge_status'))
               <dt class="col-6 font-regular">{{ Helpers::get_business_data('additional_charge_name')??translate('messages.additional_charge')  }} :</dt>
               <dd class="col-6 text-right">
                   @if ($subtotal + $addon_price > 0)
                   {{ Helpers::format_currency(round($additional_charge, 2)) }}
                   @else
                   {{Helpers::format_currency($additional_charge)  }}
                   @endif
               </dd>
           @endif


            <dt class="col-6 font-regular">{{translate('Extra Packaging Amount')}} :</dt>
            <dd class="col-6 text-right"> {{Helpers::format_currency(round($extra_packaging_amount,2))}}</dd>

            <dd class="col-12">
                <hr class="m-0">
            </dd>
            <dt class="col-6 font-regular">{{ translate('Total') }}:</dt>
            <dd class="col-6 text-right h4 b"> {{Helpers::format_currency(round($total+ $additional_charge + $extra_packaging_amount + $tax_amount, 2))}} </dd>
        </dl>
        <div class="pos--payment-options mt-3 mb-3">
            <h5 class="mb-3">{{ translate($add ? 'messages.Payment Method' : 'Paid by') }}</h5>
            <ul>
                @if ($add)
                    @php($cod=Helpers::get_business_settings('cash_on_delivery'))
                    @if ($cod['status'])
                        <li>
                            <label>
                                <input type="radio" name="type" value="cash" hidden checked>
                                <span>{{ translate('Cash_On_Delivery') }}</span>
                            </label>
                        </li>
                    @endif
                @else
                    <li>
                        <label>
                            <input type="radio" name="type" value="cash" hidden="" checked>
                            <span>{{ translate('messages.Cash') }}</span>
                        </label>
                    </li>
                    <li>
                        <label>
                            <input type="radio" name="type" value="card" hidden="">
                            <span>{{ translate('messages.Card') }}</span>
                        </label>
                    </li>
                    <li id="wallet_payment_li">
                        <label>
                            <input type="radio" name="type" value="wallet" id="wallet_payment" hidden="">
                            <span>{{ translate('messages.wallet') }}</span>
                        </label>
                    </li>
                @endif

            </ul>
        </div>


        <div class="row button--bottom-fixed g-1 bg-white pt-0">
            <div class="col-6">
                <button type="submit"
                        class="btn  btn--primary btn-sm btn-block">{{ translate('place_order') }} </button>
            </div>
            <div class="col-6">
                <a href="#" class="btn btn--reset btn-sm btn-block empty-Cart">{{  translate('Clear_Cart') }}</a>
            </div>
        </div>
    </div>
</form>



<div id="show-address-modal" >
    @includeif('admin-views.pos._address')
</div>
