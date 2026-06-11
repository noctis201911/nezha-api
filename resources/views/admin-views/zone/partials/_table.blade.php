
   @php($non_mod = 0)
                                @foreach ($zones as $key => $zone)
                                    @php($non_mod = $zone?->minimum_shipping_charge && $zone?->per_km_shipping_charge && $non_mod == 0 ? $non_mod : $non_mod + 1)

                                    <tr>
                                        <td>{{ $key + $zones?->firstItem() }}</td>
                                        <td class="text-center">
                                            <span class="move-left">
                                                {{ $zone->id }}
                                            </span>
                                        </td>
                                        <td class="pl-5">
                                            <span class="d-block font-size-sm text-body">
                                                {{ $zone['name'] }}
                                            </span>
                                        </td>
                                        <td class="pl-5">
                                            <span class="d-block font-size-sm text-body">
                                                {{ $zone['display_name'] }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="move-left">
                                                {{ $zone->restaurants_count }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="move-left">
                                                {{ $zone->deliverymen_count }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            @if ($zone->is_default)
                                                <button
                                                    class="btn btn-sm btn-ghost-success">{{ translate('messages.default_zone') }}
                                                    <span data-toggle="tooltip" data-placement="right"
                                                        data-original-title="{{ translate('This zone is set as the default for customers who visit the app or website without choosing a location.') }}"
                                                        class="input-label-secondary text-success"><i class="tio-info text-success"></i></span>

                                                </button>
                                            @else
                                                <a href="{{ route('admin.zone.defaultStatus', ['id' => $zone['id']]) }}" class="btn border rounded py-2 px-3">{{ translate('messages.make_default') }}</a>
                                            @endif
                                        </td>
                                        <td>
                                            <label class="toggle-switch toggle-switch-sm">
                                                <input type="checkbox" class="toggle-switch-input dynamic-checkbox"
                                                    id="status-{{ $zone['id'] }}" {{ $zone->status ? 'checked' : '' }}
                                                    data-id="status-{{ $zone['id'] }}" data-type="status"
                                                    data-image-on='{{ dynamicAsset('assets/admin/img/modal') }}/zone-status-on.png'
                                                    data-image-off="{{ dynamicAsset('assets/admin/img/modal') }}/zone-status-off.png"
                                                    data-title-on="{{ translate('Want_to_activate_this_Zone?') }}"
                                                    data-title-off="{{ translate('Want_to_deactivate_this_Zone?') }}"
                                                    data-text-on="<p>{{ translate('If_you_activate_this_zone,_Customers_can_see_all_restaurants_&_products_available_under_this_Zone_from_the_Customer_App_&_Website.') }}</p>"
                                                    data-text-off="<p>{{ translate('If_you_deactivate_this_zone,_Customers_Will_NOT_see_all_restaurants_&_products_available_under_this_Zone_from_the_Customer_App_&_Website.') }}</p>">
                                                <span class="toggle-switch-label">
                                                    <span class="toggle-switch-indicator"></span>
                                                </span>
                                            </label>
                                            <form
                                                action="{{ route('admin.zone.status', [$zone['id'], $zone->status ? 0 : 1]) }}"
                                                method="get" id="status-{{ $zone['id'] }}_form">
                                            </form>
                                        </td>
                                        <td>
                                            <div class="btn--container justify-content-center">
                                                <a class="btn btn-sm btn--primary btn-outline-primary action-btn"
                                                    href="{{ route('admin.zone.edit', [$zone['id']]) }}"
                                                    title="{{ translate('messages.edit_zone') }}"><i
                                                        class="tio-edit"></i>
                                                </a>
                                                <div class="popover-wrapper hide_data {{ $non_mod == 1 ? 'active' : '' }} ">
                                                    <a class="btn active action-btn btn--warning btn-outline-warning"
                                                        href="{{ route('admin.zone.settings', ['id' => $zone['id']]) }}"
                                                        title="{{ translate('messages.zone_settings') }}">
                                                        <i class="tio-settings"></i>
                                                    </a>
                                                    <div class="popover __popover  {{ $non_mod == 1 ? '' : 'd-none' }}">
                                                        <div class="arrow"></div>
                                                        <h3 class="popover-header d-flex justify-content-between">
                                                            <span>{{ translate('messages.Important!') }}</span>
                                                            <span class="tio-clear hide-data"></span>
                                                        </h3>
                                                        <div class="popover-body">
                                                            {{ translate('The_Business_Zone_will_NOT_work_if_you_don’t_add_the_minimum_delivery_charge_&_per_km_delivery_charge.') }}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
