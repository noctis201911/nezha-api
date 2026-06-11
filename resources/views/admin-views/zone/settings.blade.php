@extends('layouts.admin.app')
@section('title', translate('messages.zone_settings'))

@section('content')

    <div class="content container-fluid">
        <!-- Page Header -->
        <div class="page-header pb-0">
            <div class="d-flex flex-wrap justify-content-between align-items-start">
                <div class="d-flex align-items-start __gap-12px">
                    <img src="{{dynamicAsset('assets/admin/img/zone.png')}}" alt="">
                    <div>
                        <h1 class="page-header-title text-capitalize">
                            {{translate('messages.Business_Zone_settings') }} : {{ $zone->name }}
                        </h1>
                        <p>
                            {{translate('messages.Set_zone-wise_delivery_fees_and_incentives')}}
                        </p>
                    </div>
                </div>
                <div class="text--primary-2 py-1 d-flex flex-wrap align-items-center gap-1" type="button" data-toggle="modal" data-target="#how-it-works">
                    <div>
                        <i class="tio-info fs-16"></i>
                    </div>
                    <strong>{{translate('See_how_it_works')}}</strong>
                </div>
            </div>
        </div>
        <!-- End Page Header -->
        <form action="{{ route('admin.zone.zone_settings_update', $zone->id) }}" method="post" class="card p-0 border-0 shadow--card">
            @csrf
            <div class="card card-body">
                <div class="mb-20">
                    <h3 class="mb-1">{{ translate('messages.Delivery_Charges_Settings') }}</h3>
                    <p class="fs-12 mb-0">{{ translate('messages.Manage_delivery_charge_here') }}</p>
                </div>
                <div class="__bg-F8F9FC-card mb-0">
                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-4">
                            <div class="form-group mb-0">
                                <label class="input-label text-capitalize d-inline-flex align-items-center">
                                    {{ translate('messages.minimum_delivery_charge') }}
                                    ({{ \App\CentralLogics\Helpers::currency_symbol() }})&nbsp;
                                    <span data-toggle="tooltip" data-placement="right"
                                        data-original-title="{{ translate('messages.Set_the_minimum_delivery_for_each_order_in_this_business_zone.')}}"
                                        class="input-label-secondary tio-info fs-16"></span>
                                </label>
                                <input id="min_delivery_charge" name="minimum_shipping_charge" type="number"
                                    min=".001" step=".001" class="form-control h--45px" required
                                    placeholder="{{ translate('messages.Ex:_100') }}"
                                    value="{{ $zone->minimum_shipping_charge }}">
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="form-group mb-0">
                                <label class="input-label text-capitalize d-inline-flex align-items-center">
                                    {{ translate('messages.maximum_delivery_charge') }}
                                    ({{ \App\CentralLogics\Helpers::currency_symbol() }})&nbsp;
                                    <span data-toggle="tooltip" data-placement="right"
                                        data-original-title="{{ translate('messages.Set_the_maximum_limit_for_the_total_delivery_charge._If_the_delivery_charge_crosses_the_limit,_it_will_not_add_any_extra_charge._Leave_it_empty_if_you_don’t_want_to_limit_the_delivery_charge.')}}"
                                        class="input-label-secondary tio-info fs-16"></span>
                                </label>
                                <input id="maximum_shipping_charge" name="maximum_shipping_charge" type="number"
                                    class="form-control h--45px"
                                    placeholder="{{ translate('messages.Ex:_10000') }} " min="0"
                                    step=".001" value="{{ $zone->maximum_shipping_charge ?? '' }}">
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="form-group mb-0">
                                <label class="input-label text-capitalize d-inline-flex align-items-center">
                                    {{ translate('messages.delivery_charge_per_km') }}
                                    ({{ \App\CentralLogics\Helpers::currency_symbol() }})&nbsp;
                                    <span data-toggle="tooltip" data-placement="right"
                                    data-original-title="{{ translate('messages.Set_a_delivery_charge_for_each_kilometer_for_this_business_zone.')}}"
                                    class="input-label-secondary tio-info fs-16"></span>
                                </label>
                                <input id="delivery_charge_per_km" name="per_km_delivery_charge" type="number"
                                    min=".001" step=".001" class="form-control h--45px" required
                                    placeholder="{{ translate('messages.Ex:_100') }}"
                                    value="{{ $zone->per_km_shipping_charge }}">
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="form-group mb-0">
                                <label class="input-label text-capitalize d-inline-flex align-items-center">
                                    {{ translate('messages.maximum_COD_order_amount') }}
                                    ({{ \App\CentralLogics\Helpers::currency_symbol() }})&nbsp;
                                    <span data-toggle="tooltip" data-placement="right"
                                        data-original-title="{{ translate('messages.Add_the_maximum_Cash_On_Delivery_order_limit_for_this_business_zone._Leave_it_empty_if_you_don’t_want_to_limit_the_COD_order_amount') }}"
                                        class="input-label-secondary tio-info fs-16"></span>
                                </label>
                                <input id="max_cod_order_amount" name="max_cod_order_amount" min="0"
                                    step=".001" type="number" class="form-control h--45px"
                                    placeholder="{{ translate('messages.Ex:_10000') }} "
                                    value="{{ $zone->max_cod_order_amount ?? '' }}">
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="form-group mb-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <label class="input-label text-capitalize d-inline-flex align-items-center"
                                        for="increased_delivery_fee">
                                        <span class="line--limit-1">{{ translate('messages.increase_delivery_charge') }} (%)
                                        <span data-toggle="tooltip" data-placement="right" data-original-title="{{translate('messages.Set_an_additional_delivery_charge_in_percentage_for_any_emergency_situations._This_amount_will_be_added_to_the_delivery_charge.')}}" class="input-label-secondary tio-info fs-16"></span>
                                    </label>
                                    <label class="toggle-switch toggle-switch-sm">
                                        <input type="checkbox" class="toggle-switch-input" name="increased_delivery_fee_status"
                                            id="increased_delivery_fee_status" value="1"
                                            {{ $zone->increased_delivery_fee_status == 1 ? 'checked' : '' }}>
                                            <span class="toggle-switch-label">
                                                <div class="toggle-switch-indicator"></div>
                                            </span>
                                    </label>
                                </div>
                                <input type="number" name="increased_delivery_fee" class="form-control"
                                    id="increased_delivery_fee"
                                    value="{{ $zone->increased_delivery_fee ? $zone->increased_delivery_fee : '' }}" min="0"
                                    step=".001" placeholder="{{ translate('messages.Ex:_100') }}" {{ ($zone->increased_delivery_fee_status == 1) ? ' ' : 'readonly' }}>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <div class="form-group mb-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <label class="input-label text-capitalize d-inline-flex align-items-center"
                                        for="increased_delivery_fee">
                                        <span class="line--limit-1">{{ translate('messages.increase_delivery_charge_message') }}
                                            <span data-toggle="tooltip" data-placement="right" data-original-title="{{translate('messages.Customers_will_see_the_delivery_charge_increased_reason_on_the_website_and_customer_app.')}}" class="input-label-secondary tio-info fs-16"></span>
    
                                    </label>
                                </div>
                                <input type="text" name="increase_delivery_charge_message" class="form-control"
                                    id="increase_delivery_charge_message"
                                    value="{{ $zone->increase_delivery_charge_message ? $zone->increase_delivery_charge_message : '' }}"
                                        placeholder="{{ translate('messages.Ex:_Rainy_season') }} " {{ ($zone->increased_delivery_fee_status == 1) ? ' ' : 'readonly' }}>
                            </div>
                        </div>
    
                    </div>
                </div>
                <div class="card card-body mt-3">
                    <div class="view-details-container">
                        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap flex-sm-nowrap">
                            <div>
                                <h4 class="mb-1">{{ translate('messages.Additional Delivery Charge Setup') }}</h4>
                                <p class="mb-0 fs-12">
                                    {{ translate('Enabling this feature allows customers to choose their preferred delivery type') }}
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <div
                                    class="view-btn order-sm-0 order-3 fz--14px text-primary cursor-pointer text-decoration-underline font-semibold d-flex align-items-center gap-1 {{ $zone->additional_delivery_option_status ? 'active' : '' }}">
                                    {{ translate('messages.view') }}
                                    <i class="tio-chevron-down"></i>
                                </div>
                                <label class="toggle-switch toggle-switch-sm mb-0">
                                    <input type="checkbox" data-id="additional_delivery_option_status"
                                        data-type="toggle"
                                        data-image-on="{{ dynamicAsset('assets/admin/img/svg/') }}"
                                        data-image-off="{{ dynamicAsset('assets/admin/img/svg/') }}"
                                        data-title-on="<strong>{{ translate('turn_on_') }}?</strong>"
                                        data-title-off="<strong>{{ translate('turn_off_') }}?</strong>"
                                        data-text-on="<p>{{ translate('are_you_sure_to_turn_on_the_') }}? {{ translate('enable_this_option_to_make_the_marketing_tool_available_for_website_utilization.') }}</p>"
                                        data-text-off="<p>{{ translate('are_you_sure_to_turn_off_the_') }}? {{ translate('disable_this_option_to_make_the_marketing_tool_unavailable_for_website_utilization.') }}</p>"
                                        class="status toggle-switch-input dynamic-checkbox" name="additional_delivery_option_status"
                                        id="additional_delivery_option_status" value="1" {{ $zone->additional_delivery_option_status ? 'checked' : ''}}>
                                    <span class="toggle-switch-label text mb-0">
                                        <span class="toggle-switch-indicator"></span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="view-details mt-3 mt-sm-4" {{ $zone->additional_delivery_option_status ? 'style=display:block' : '' }}>
                            <div class="__bg-F8F9FC-card mb-20">
                                <div class="row g-3 align-items-center">
                                    <div class="col-lg-4">
                                        <div>
                                            <h5 class="mb-1">{{ translate('messages.Minimum Delivery Time Limit') }}</h5>
                                            <p class="fs-12 mb-0">{{ translate('messages.Set the lowest delivery time allowed in this zone.The final delivery time cannot be reduced below this limit.') }}</p>
                                        </div>
                                    </div>
                                    <div class="col-lg-8">
                                        <div class="card card-body border-0 shadow-none">
                                            <label for="minimum_delivery_time" class="input-label text-capitalize">
                                                {{ translate('messages.Set minimum delivery time limit') }} <span class="text-danger">*</span>
                                            </label>
                                            <div class="custom-group-btn form-control single">
                                                <div class="item flex-sm-grow-1">
                                                    <input type="number" name="minimum_delivery_time" class="form-control border-0 h-100" id="minimum_delivery_time" value="{{ $zone->minimum_delivery_time['value'] ?? '' }}" min="0" placeholder="{{ translate('messages.Ex: 20') }}">
                                                </div>
                                                <div class="item flex-shrink-0">
                                                    <select name="minimum_delivery_time_unit" id="minimum_delivery_time_unit" class="custom-select w-90px border-0">
                                                        <option value="min" {{ $zone->minimum_delivery_time['unit'] == 'min' ? 'selected' : '' }}>{{ translate('messages.Min') }}</option>
                                                        <option value="hour" {{ $zone->minimum_delivery_time['unit'] == 'hour' ? 'selected' : '' }}>{{ translate('messages.Hour') }}</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div id="minimum_delivery_time_error" class="text-danger mt-1 d-none">{{ translate('messages.minimum_delivery_time_can_not_be_less_than_reduce_delivery_time') }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="__bg-F8F9FC-card mb-20">
                                <div class="row g-3 align-items-center">
                                    <div class="col-lg-4">
                                        <div>
                                            <h5 class="mb-1">{{ translate('messages.Minimum Delivery Charge For Delivery Type') }}</h5>
                                            <p class="fs-12 mb-0">{{ translate('messages.Set the minimum delivery charge allowed in this zone. The "Reduce Charge" cannot exceed this limit.') }}</p>
                                        </div>
                                    </div>
                                    <div class="col-lg-8">
                                        <div class="card card-body border-0 shadow-none">
                                            <label for="minimum_delivery_charge" class="input-label text-capitalize">
                                                {{ translate('messages.Set minimum delivery charge for delivery type') }} <span class="text-danger">*</span>
                                            </label>
                                            <input type="number" step=".001" name="minimum_delivery_charge" class="form-control" id="minimum_delivery_charge" value="{{ $zone->minimum_delivery_charge ? number_format($zone->minimum_delivery_charge, 2, '.', '') : '' }}" placeholder="{{ translate('messages.Ex : 5.00') }}">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @php($express_option = $zone->deliveryOptions->where('delivery_type', 'express')->first())
                            @php($delayed_option = $zone->deliveryOptions->where('delivery_type', 'slightly_delay')->first())
                            <div class="__bg-F8F9FC-card">
                                <div class="bg-info bg-opacity-10 d-flex fs-12 gap-2 px-3 py-2 rounded text-dark mb-20">
                                    <span class="text-info lh-1 fs-14 flex-shrink-0">
                                        <img src="{{dynamicAsset('assets/admin/img/svg/bulb.svg')}}" class="svg" alt="">
                                    </span>
                                    <span>
                                        {{ translate('The standard delivery time follows the restaurant’s registered estimated delivery time, and the Zone rules are applied to all restaurants within this zone.') }}
                                    </span>
                                </div>
                                <div class="row g-3 align-items-center">
                                    <div class="col-lg-4">
                                        <div>
                                            <h5 class="mb-1">{{ translate('messages.Express Delivery') }}</h5>
                                            <p class="fs-12 mb-0">{{ translate('messages.Deliver faster by reducing delivery time with an additional charge.') }}</p>
                                        </div>
                                    </div>
                                    <div class="col-lg-8">
                                        <div class="card card-body border-0 shadow-none">
                                            <div class="row g-3">
                                                <div class="col-lg-6">
                                                    <label for="extra_charge" class="input-label text-capitalize d-inline-flex align-items-center">
                                                        {{ translate('messages.Add Extra Charge') }} ($) <span class="text-danger">*</span>&nbsp;
                                                        <span data-toggle="tooltip" data-placement="right"
                                                            data-original-title="This extra charge will be added to the standard delivery fee when the customer selects Express Delivery"
                                                            class="input-label-secondary tio-info fs-16"></span>
                                                    </label>
                                                    <input type="number" step="{{ \App\CentralLogics\Helpers::getDecimalPlaces() }}" min="{{ \App\CentralLogics\Helpers::getDecimalPlaces() }}" name="extra_charge" id="extra_charge" class="form-control" value="{{ $express_option ? $express_option->extra_charge : '' }}" placeholder="{{ translate('messages.Ex : 5.00') }}">
                                                </div>
                                                <div class="col-lg-6">
                                                    <label for="reduce_delivery_time" class="input-label text-capitalize d-inline-flex align-items-center">
                                                        {{ translate('messages.Reduce Delivery Time') }} <span class="text-danger">*</span>&nbsp;
                                                        <span data-toggle="tooltip" data-placement="right"
                                                            data-original-title="This Delivery time will be reduced from the standard delivery time of the restaurant when the customer selects Express Delivery"
                                                            class="input-label-secondary tio-info fs-16"></span>
                                                    </label>
                                                    <div class="custom-group-btn form-control single">
                                                        <div class="item flex-sm-grow-1">
                                                            <input type="number" name="reduce_delivery_time" class="form-control border-0 h-100" id="reduce_delivery_time" value="{{ $express_option ? $express_option->reduce_delivery_time['value'] : '' }}" min="0" placeholder="{{ translate('messages.Ex: 20') }}">
                                                        </div>
                                                        <div class="item flex-shrink-0">
                                                            <select name="reduce_delivery_time_unit" id="reduce_delivery_time_unit" class="custom-select w-90px border-0">
                                                                <option value="min" {{ isset($express_option) && $express_option->reduce_delivery_time['unit'] == 'min' ? 'selected' : '' }}>{{ translate('messages.Min') }}</option>
                                                                <option value="hour" {{ isset($express_option) && $express_option->reduce_delivery_time['unit'] == 'hour' ? 'selected' : '' }}>{{ translate('messages.Hour') }}</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-4">
                                        <div>
                                            <h5 class="mb-1">{{ translate('messages.Slightly Delay Delivery') }}</h5>
                                            <p class="fs-12 mb-0">{{ translate('messages.Deliver a bit later and offer a reduced delivery charge.') }}</p>
                                        </div>
                                    </div>
                                    <div class="col-lg-8">
                                        <div class="card card-body border-0 shadow-none">
                                            <div class="row g-3">
                                                <div class="col-lg-6">
                                                    <label for="reduce_charge" class="input-label text-capitalize d-inline-flex align-items-center">
                                                        {{ translate('messages.Reduce Charge') }} ($) <span class="text-danger">*</span>&nbsp;
                                                        <span data-toggle="tooltip" data-placement="right"
                                                            data-original-title="This charge will be reduced from the standard delivery fee when the customer selects Slightly Delay Delivery"
                                                            class="input-label-secondary tio-info fs-16"></span>
                                                    </label>
                                                    <input type="number" step="{{ \App\CentralLogics\Helpers::getDecimalPlaces() }}" min="{{ \App\CentralLogics\Helpers::getDecimalPlaces() }}" name="reduce_charge" id="reduce_charge" class="form-control" value="{{ $delayed_option ? $delayed_option->reduce_charge : '' }}" placeholder="{{ translate('messages.Ex : 5.00') }}">
                                                    <div id="reduce_charge_error" class="text-danger mt-1 d-none">{{ translate('messages.reduce_charge_can_not_be_greater_than_minimum_delivery_charge') }}</div>
                                                </div>
                                                <div class="col-lg-6">
                                                    <label for="add_delivery_time" class="input-label text-capitalize d-inline-flex align-items-center">
                                                        {{ translate('messages.Add Extra Delivery Time') }} <span class="text-danger">*</span>&nbsp;
                                                        <span data-toggle="tooltip" data-placement="right"
                                                            data-original-title="This Delivery time will be added to the standard delivery time of the restaurant when the customer selects Slightly Delay Delivery"
                                                            class="input-label-secondary tio-info fs-16"></span>
                                                    </label>
                                                    <div class="custom-group-btn form-control single">
                                                        <div class="item flex-sm-grow-1">
                                                            <input type="number" name="add_delivery_time" class="form-control border-0 h-100" id="add_delivery_time" value="{{ $delayed_option ? $delayed_option->add_delivery_time['value'] : '' }}" min="0" placeholder="{{ translate('messages.Ex: 20') }}">
                                                        </div>
                                                        <div class="item flex-shrink-0">
                                                            <select name="add_delivery_time_unit" class="custom-select w-90px border-0">
                                                                <option value="min" {{ isset($delayed_option) && $delayed_option->add_delivery_time['unit'] == 'min' ? 'selected' : '' }}>{{ translate('messages.Min') }}</option>
                                                                <option value="hour" {{ isset($delayed_option) && $delayed_option->add_delivery_time['unit'] == 'hour' ? 'selected' : '' }}>{{ translate('messages.Hour') }}</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="btn--container mt-4 justify-content-end">
                    <button id="resetbtn" type="reset"
                        class="btn btn--reset">{{ translate('messages.reset') }}</button>
                    <button type="submit" id="submit_btn" class="btn btn--primary">{{ translate('messages.save') }}</button>
                </div>
            </div>
        </form>
        <div class="card shadow--card border-0 mt-3 p-0">
            <div class="card-body">
                <div class="mb-20">
                    <h3 class="mb-1">{{ translate('messages.Incentive_Settings') }}</h3>
                    <p class="fs-12 mb-0">{{ translate('messages.Motivate_deliverymen_to_achieve_daily_earning_targets_and_provide_additional_incentives_to_encourage_increased_deliveries.') }}</p>
                </div>
            <!-- Incentive Item -->
                <div class="__bg-F8F9FC-card">
                    @forelse ($zone->incentives as $key => $incentive)
                    <div class="d-flex align-items-end __gap-15px mb-2">
                        <div class="row g-3 w-0 flex-grow-1">
                            <div class="col-sm-6">
                                @if ($key == 0)
                                <label class="form-label">{{translate('Daily_Earning_Target')}} {{ \App\CentralLogics\Helpers::currency_symbol() }}


                                    <span data-toggle="tooltip" data-placement="right"
                                    data-original-title="{{ translate('messages.Set_the_daily_earning_target_for_deliverymen_for_this_business_zone.')}}"
                                    class="input-label-secondary tio-info fs-16"></span>

                                </label>
                                @endif
                                <input type="number" readonly value="{{ \App\CentralLogics\Helpers::format_currency($incentive->earning) }}"  placeholder="{{ \App\CentralLogics\Helpers::format_currency($incentive->earning) }}" class="form-control">
                            </div>
                            <div class="col-sm-6">
                                @if ($key == 0)
                                <label class="form-label">{{translate('Incentive_for_Completing_Target')}} {{ \App\CentralLogics\Helpers::currency_symbol() }}

                                    <span data-toggle="tooltip" data-placement="right"
                                    data-original-title="{{ translate('messages.Set_the_incentive_amount_for_deliverymen_on_completing_the_daily_earning_target_for_this_business_zone.')}}"
                                    class="input-label-secondary tio-info fs-16"></span>
                                </label>
                                @endif
                                <input  readonly  type="number" value="{{ \App\CentralLogics\Helpers::format_currency($incentive->incentive) }}" placeholder="{{ \App\CentralLogics\Helpers::format_currency($incentive->incentive) }}" class="form-control">
                            </div>
                        </div>
                        <div class="mb-1">
                            <a class="btn action-btn btn--danger btn-outline-danger form-alert" href="javascript:"
                            data-id="attribute-{{ $incentive->id }}" data-message="{{ translate('messages.want_to_delece_this_incentive') }}"
                            title="{{ translate('messages.delete') }}"><i class="tio-delete-outlined"></i></a>
                        </div>
                            <form
                            action="{{ route('admin.zone.incentive.destory', ['id' => $incentive->id]) }}"
                            method="post" id="attribute-{{ $incentive->id }}">
                            @csrf @method('delete')
                            </form>
                    </div>
                    @empty


                    @endforelse
                    <div class="text-right mt-3">
                        <button  type="button"  id="show_incentive_button" class="btn text--primary py-1 ml-auto show-incentive">{{translate('Add_New_Incentive_+')}}</button>
                    </div>
                    <div class="d-none" id="show_incentive">
                        <!-- Incentive Item -->
                        <form action="{{ route('admin.zone.incentive.store', ['zone_id' => $zone->id]) }}"
                            method="POST">
                            @csrf
                            <div class="d-flex div_size align-items-end __gap-16px mb-2">
                                <div class="row g-3 w-0 flex-grow-1">
                                    <div class="col-sm-6">
                                        @if (count($zone->incentives) == 0)
                                        <label class="form-label">{{translate('Daily_Earning_Target')}} {{ \App\CentralLogics\Helpers::currency_symbol() }}</label>
                                        @endif
                                        <input type="number" name="earning" step=".01"  min="1" max="99999999999.999" class="form-control" required>
                                    </div>
                                    <div class="col-sm-6">
                                        @if (count($zone->incentives) == 0)
                                            <label class="form-label">{{translate('Incentive_for_Completing_Target')}} {{ \App\CentralLogics\Helpers::currency_symbol() }} </label>
                                        @endif
                                        <input type="number" name="incentive" id="" min="1" max="99999999999.999"
                                            class="form-control" step=".01"
                                            placeholder="{{ translate('messages.enter_incentive') }}" required>
                                    </div>
                                </div>
                            </div>
                            <div class="btn--container mt-4 justify-content-end">
                                <button id="reset_btn" type="reset"
                                    class="btn btn--reset hide-incentive">{{ translate('messages.reset') }}</button>
                                <button type="submit" class="btn btn--primary">{{ translate('messages.save') }}</button>
                            </div>

                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- How it Works -->
    <div class="modal fade" id="how-it-works">
        <div class="modal-dialog modal-md">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">
                        <span aria-hidden="true" class="tio-clear"></span>
                    </button>
                </div>
                <div class="modal-body pb-5 pt-0">
                    <div class="single-item-slider owl-carousel">
                        <div class="item">
                            <div class="max-544 mx-auto mb-20 text-center">
                                <img src="{{dynamicAsset('assets/admin/img/modal/zone1.png')}}" alt="" class="mb-20">
                                <h5 class="modal-title">{{translate('messages.Zone_wise_delivery_charge_settings')}}</h5>
                                <p>
                                    {{translate("messages.You_can_set_a_different_delivery_charge,_order_limit_for_COD,_increase_delivery_charge_percentage,_etc.,_for_this_business_zone.")}}
                                </p>
                                <p>
                                    {{translate("messages.Note:_Leave_this_section_empty_if_you_want_to_keep_the_default_charges_for_this_zone.")}}
                                </p>
                            </div>
                        </div>
                        <div class="item">
                            <div class="max-544 mx-auto mb-20 text-center">
                                <img src="{{dynamicAsset('assets/admin/img/modal/zone1.png')}}" alt="" class="mb-20">
                                <h5 class="modal-title">{{translate('messages.Zone_wise_Incentives_for_Deliverymen')}}</h5>
                                <p>
                                    {{translate("messages.You_can_provide_a_certain_amount_of_incentives_to_deliverymen_of_this_zone_only.")}}
                                </p>
                                <p>
                                    {{translate("messages.Note:_You_will_receive_an_instant_request_to_pay_the_incentive_amount_whenever_a_deliveryman_completes_his_target._To_see_the_incentive_requests_click_on_the_View_Incentive_Requests_button_below.")}}
                                </p>
                                <a  href="{{ route('admin.delivery-man.incentive')  }}" type="button"  class="btn btn--primary">{{translate('View_Incentive_Requests')}}</a>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-center">
                        <div class="slide-counter"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script_2')
    <script>
        "use strict";
        $('.show-incentive').click(function() {
            $('#show_incentive').removeClass('d-none');
            $('#show_incentive_button').addClass('d-none');
        })
        $('.hide-incentive').click(function() {
            $('#show_incentive').addClass('d-none');
            $('#show_incentive_button').removeClass('d-none');
        })

        $('#resetbtn').click(function() {
              setTimeout(function () {
                $("#increased_delivery_fee_status").trigger('change');
            }, 100);
        })
        $(document).on('ready', function() {


            $("#increased_delivery_fee_status").on('change', function() {
                if ($("#increased_delivery_fee_status").is(':checked')) {
                    $('#increased_delivery_fee').removeAttr('readonly');
                    $('#increase_delivery_charge_message').removeAttr('readonly');
                } else {
                    $('#increased_delivery_fee').attr('readonly', true);
                    $('#increase_delivery_charge_message').attr('readonly', true);
                    $('#increased_delivery_fee').val('Ex : 0');
                    $('#increase_delivery_charge_message').val('');
                }
            });

            $("#additional_delivery_option_status").on('change', function() {
                if ($(this).is(':checked')) {
                    $(this).closest(".view-details-container").find(".view-details").slideDown(300);
                    $(this).closest(".view-details-container").find(".view-btn").addClass("active");
                } else {
                    $(this).closest(".view-details-container").find(".view-details").slideUp(300);
                    $(this).closest(".view-details-container").find(".view-btn").removeClass("active");
                }
            });

            $(document).on('click', '.confirm-Status-Toggle', function() {
                let Status_toggle = $("#toggle-status-ok-button").attr("toggle-ok-button");
                if (Status_toggle === 'additional_delivery_option_status') {
                    setTimeout(() => {
                        $("#additional_delivery_option_status").trigger('change');
                    }, 50);
                }
            });


            function validateCharges() {
                let reduce_charge = parseFloat($('#reduce_charge').val()) || 0;
                let minimum_delivery_charge = parseFloat($('#minimum_delivery_charge').val()) || 0;
                let isValid = true;

                if (reduce_charge > minimum_delivery_charge) {
                    $('#reduce_charge_error').removeClass('d-none');
                    isValid = false;
                } else {
                    $('#reduce_charge_error').addClass('d-none');
                }

                return isValid;
            }

            function convertToMinutes(value, unit) {
                let timeValue = parseFloat(value) || 0;
                return unit === 'hour' ? timeValue * 60 : timeValue;
            }

            function validateDeliveryTimes() {
                let minimumDeliveryTime = convertToMinutes($('#minimum_delivery_time').val(), $('#minimum_delivery_time_unit').val());
                let reduceDeliveryTime = convertToMinutes($('#reduce_delivery_time').val(), $('#reduce_delivery_time_unit').val());
                let isValid = true;

                if (reduceDeliveryTime > minimumDeliveryTime) {
                    $('#minimum_delivery_time_error').removeClass('d-none');
                    isValid = false;
                } else {
                    $('#minimum_delivery_time_error').addClass('d-none');
                }

                return isValid;
            }

            function validateZoneSettingsForm() {
                const hasValidCharges = validateCharges();
                const hasValidDeliveryTimes = validateDeliveryTimes();

                if (hasValidCharges && hasValidDeliveryTimes) {
                    $('#submit_btn').removeAttr('disabled');
                } else {
                    $('#submit_btn').attr('disabled', true);
                }
            }

            $('#reduce_charge, #minimum_delivery_charge').on('keyup input change', function() {
                validateZoneSettingsForm();
            });

            $('#minimum_delivery_time, #minimum_delivery_time_unit, #reduce_delivery_time, #reduce_delivery_time_unit').on('keyup input change', function() {
                validateZoneSettingsForm();
            });

            validateZoneSettingsForm();
        });
    </script>
@endpush
