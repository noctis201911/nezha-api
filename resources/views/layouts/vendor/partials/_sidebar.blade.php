@once
    <style>
        .nz-customer-nudge-alert > .nav-link,
        .nz-customer-nudge-alert .text-truncate {
            color: #F04438 !important;
            font-weight: 900;
        }
        .nz-customer-nudge-badge {
            background: #F04438 !important;
            color: #fff !important;
            animation: nzNudgeBadgePulse 1s ease-in-out infinite;
        }
        @keyframes nzNudgeBadgePulse {
            0%, 100% { transform: translateY(0) scale(1); box-shadow: 0 0 0 0 rgba(240, 68, 56, .42); }
            45% { transform: translateY(-2px) scale(1.12); box-shadow: 0 0 0 5px rgba(240, 68, 56, 0); }
        }
        @media (prefers-reduced-motion: reduce) {
            .nz-customer-nudge-badge { animation: none; }
        }
        /* 哪吒P1b-E: 「订单」需动作徽标 —— 稳态 V2 红(#E5484D·同订单页需动作tab/段A退款头), 持续积压计数不脉冲 */
        .nz-order-action-badge { background: #E5484D !important; color: #fff !important; font-weight: 800; }
    </style>
@endonce
<div id="sidebarMain" class="d-none">
    <aside
        class="js-navbar-vertical-aside navbar navbar-vertical-aside navbar-vertical navbar-vertical-fixed navbar-expand-xl navbar-bordered">
        <div class="navbar-vertical-container">
            <div class="navbar-brand-wrapper justify-content-between">
                <!-- Logo -->
                <div class="sidebar-logo-container">
                    @php($restaurant_data=\App\CentralLogics\Helpers::get_restaurant_data())
                    <a class="navbar-brand pt-0 pb-0" href="{{route('vendor.dashboard')}}" aria-label="Front">
                        <img class="navbar-brand-logo"
                             src="{{ $restaurant_data?->logo_full_url }}"
                             alt="image">
                        <img class="navbar-brand-logo-mini"
                             src="{{ $restaurant_data?->logo_full_url }}"
                             alt="image">

                        <div class="ps-2">
                            <h6>
                                {{\Illuminate\Support\Str::limit($restaurant_data->name,15)}}
                            </h6>
                        </div>
                    </a>
                    <!-- End Logo -->

                    <!-- Navbar Vertical Toggle -->
                    <button type="button"
                            class="js-navbar-vertical-aside-toggle-invoker navbar-vertical-aside-toggle btn btn-icon btn-xs btn-ghost-dark">
                        <i class="tio-clear tio-lg"></i>
                    </button>
                    <!-- End Navbar Vertical Toggle -->
                </div>
                <div class="navbar-nav-wrap-content-left ml-auto d-none d-xl-block">
                    <!-- Navbar Vertical Toggle -->
                    <button type="button" class="js-navbar-vertical-aside-toggle-invoker close">
                        <i class="tio-first-page navbar-vertical-aside-toggle-short-align" data-toggle="tooltip"
                           data-placement="right" title="Collapse"></i>
                        <i class="tio-last-page navbar-vertical-aside-toggle-full-align"
                           data-template='<div class="tooltip d-none d-sm-block" role="tooltip"><div class="arrow"></div><div class="tooltip-inner"></div></div>'></i>
                    </button>
                    <!-- End Navbar Vertical Toggle -->
                </div>

            </div>

            <!-- Content -->
            <div class="navbar-vertical-content text-capitalize bg-334257">
                <ul class="navbar-nav navbar-nav-lg nav-tabs mt-3">
                    {{-- 哪吒作业台: 「今天」入口置顶(§6.1 并存试用·登录默认落点暂不切)。指向 vendor.workbench.index。 --}}
                    <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/workbench*')?'active':''}}">
                        <a class="js-navbar-vertical-aside-menu-link nav-link" href="{{route('vendor.workbench.index')}}" title="今天">
                            <i class="tio-home nav-icon"></i>
                            <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">今天</span>
                        </a>
                    </li>

                    <!-- Dashboards -->

                    <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel')?'active':''}}">
                        <a class="js-navbar-vertical-aside-menu-link nav-link"
                           href="{{route('vendor.dashboard')}}" title="{{translate('messages.dashboard')}}">
                            <i class="tio-dashboard nav-icon"></i>
                            <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                {{translate('messages.dashboard')}}
                            </span>
                        </a>
                    </li>

                    <!-- End Dashboards -->

                    <!-- 哪吒 对账中心 -->
                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('nezha_deposit'))
                    <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/nezha-deposit*')?'active':''}}">
                        <a class="js-navbar-vertical-aside-menu-link nav-link" href="{{route('vendor.nezha-deposit.index')}}" title="{{translate('对账中心')}}">
                            <i class="tio-wallet nav-icon"></i>
                            <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{translate('对账中心')}}</span>
                        </a>
                    </li>
                    @endif

                    <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/nezha-consolidation*')?'active':''}}">
                        <a class="js-navbar-vertical-aside-menu-link nav-link" href="{{route('vendor.nezha-consolidation.index')}}" title="{{translate('平台集运申报')}}">
                            <i class="tio-cube nav-icon"></i>
                            <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{translate('平台集运申报')}}</span>
                        </a>
                    </li>

<!-- 哪吒 商家助手 -->
                    <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/nezha-assistant*')?'active':''}}">
                        <a class="js-navbar-vertical-aside-menu-link nav-link" href="{{route('vendor.nezha-assistant.index')}}" title="{{translate('哪吒商家助手')}}">
                            <i class="tio-chat nav-icon"></i>
                            <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{translate('哪吒商家助手')}}</span>
                        </a>
                    </li>

                    <!-- POS -->

                    @if(false && \App\CentralLogics\Helpers::employee_module_permission_check('pos')) {{-- 哪吒停用·StackFood残留 --}}
                        <!-- POS -->
                        <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/pos')?'active':''}}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link" href="{{route('vendor.pos.index')}}" title="{{translate('Point Of Sale')}}"
                            >
                                <i class="tio-shopping nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{translate('Point Of Sale')}}</span>
                            </a>
                        </li>
                        <!-- End POS -->
                    @endif

                    <!-- End POS -->

                    <!--Order Management -->

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('regular_order') || \App\CentralLogics\Helpers::employee_module_permission_check('subscription_order'))
                        <li class="nav-item">
                            <small class="nav-subtitle" title="{{translate('messages.order_section')}}">{{translate('messages.order_management')}}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                    @endif

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('regular_order'))
                        {{-- 哪吒P1b-E: 「普通订单」下 17 状态子项(全部/客户催促/待确认收款/待退款/…)收敛为单条「订单」+ 需动作徽标。
                             各状态改由订单页顶部 4+1 组 tab + 组内 chip 进入(P1b-C)。徽标=需动作(grp_action·按单去重), 读单一真相源
                             NezhaOrderCounts, 与订单页组 tab / 看板待办条同一 provider(消除侧栏内联 count 分叉→根治三处对账漂移)。 --}}
                        @php($__nzActionCount = (\App\CentralLogics\NezhaOrderCounts::forRestaurant(\App\CentralLogics\Helpers::get_restaurant_id())['grp_action'] ?? 0))
                        <li class="nav-item {{ Request::is('restaurant-panel/order/list*') ? 'active' : '' }}">
                            <a class="nav-link" href="{{ route('vendor.order.list', ['grp_action']) }}" title="订单">
                                <i class="tio-shopping-cart nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate sidebar--badge-container">
                                    订单
                                    @if($__nzActionCount > 0)
                                    <span class="badge nz-order-action-badge badge-pill ml-1">{{ $__nzActionCount }}</span>
                                    @endif
                                </span>
                            </a>
                        </li>

                    @endif

                    @if(false && \App\CentralLogics\Helpers::employee_module_permission_check('subscription_order')) {{-- 哪吒停用·StackFood残留 --}}
                        <li class="navbar-vertical-aside-has-menu {{ Request::is('restaurant-panel/order/subscription*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link" href="{{ route('vendor.order.subscription.index') }}" title="{{ translate('messages.subscription_orders') }}">
                                <i class="tio-appointment nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                {{ translate('messages.subscription_orders') }}</span>
                            </a>
                        </li>
                    @endif

                    <!-- End Order Management -->

                    <!-- Food Management -->

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('food') ||
                        \App\CentralLogics\Helpers::employee_module_permission_check('category') ||
                        \App\CentralLogics\Helpers::employee_module_permission_check('addon') ||
                        \App\CentralLogics\Helpers::employee_module_permission_check('reviews'))
                        <li class="nav-item">
                            <small class="nav-subtitle">{{translate('messages.food_management')}}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                    @endif

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('food'))
                        <!-- Food -->
                        <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/food*')?'active':''}}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:" title="{{translate('Food')}}"
                            >
                                <i class="tio-restaurant nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{translate('messages.foods')}}</span>
                            </a>
                            <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                style="display:{{Request::is('restaurant-panel/food*')?'block':'none'}}">
                                <li class="nav-item {{Request::is('restaurant-panel/food/add-new')?'active':''}}">
                                    <a class="nav-link " href="{{route('vendor.food.add-new')}}"
                                       title="{{translate('Add New Food')}}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span
                                            class="text-truncate">{{translate('messages.add_new')}}</span>
                                    </a>
                                </li>
                                <li class="nav-item {{Request::is('restaurant-panel/food/list')?'active':''}}">
                                    <a class="nav-link " href="{{route('vendor.food.list')}}"  title="{{translate('Food List')}}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">{{translate('messages.list')}}</span>
                                    </a>
                                </li>
                                @if(\App\CentralLogics\Helpers::get_restaurant_data()->food_section)
                                    <li class="nav-item {{Request::is('restaurant-panel/food/bulk-import')?'active':''}}">
                                        <a class="nav-link " href="{{route('vendor.food.bulk-import')}}"
                                           title="{{translate('Bulk Import')}}">
                                            <span class="tio-circle nav-indicator-icon"></span>
                                            <span class="text-truncate text-capitalize">{{translate('messages.bulk_import')}}</span>
                                        </a>
                                    </li>
                                    <li class="nav-item {{Request::is('restaurant-panel/food/bulk-export')?'active':''}}">
                                        <a class="nav-link " href="{{route('vendor.food.bulk-export-index')}}"
                                           title="{{translate('Bulk Export')}}">
                                            <span class="tio-circle nav-indicator-icon"></span>
                                            <span class="text-truncate text-capitalize">{{translate('messages.bulk_export')}}</span>
                                        </a>
                                    </li>
                                @endif
                            </ul>
                        </li>
                        <!-- End Food -->
                    @endif

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('category'))
                        <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/category*')?'active':''}}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle"
                               href="javascript:" title="{{translate('messages.categories')}}"
                            >
                                <i class="tio-category nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{translate('messages.categories')}}</span>
                            </a>
                            <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                style="display: {{Request::is('restaurant-panel/category*')?'block':'none'}}">
                                <li class="nav-item {{Request::is('restaurant-panel/category/list')?'active':''}}">
                                    <a class="nav-link " href="{{route('vendor.category.add')}}"
                                       title="{{translate('messages.category')}}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">{{translate('messages.category')}}</span>
                                    </a>
                                </li>

                                <li class="nav-item {{Request::is('restaurant-panel/category/sub-category-list')?'active':''}}">
                                    <a class="nav-link " href="{{route('vendor.category.add-sub-category')}}"
                                       title="{{translate('messages.sub_category')}}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">{{translate('messages.sub_category')}}</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    @endif

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('addon'))
                        <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/addon*')?'active':''}}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                               href="{{route('vendor.addon.add-new')}}" title="{{translate('messages.addons')}}">
                                <i class="tio-add-circle-outlined nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                {{translate('messages.addons')}}
                            </span>
                            </a>
                        </li>
                    @endif

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('reviews'))
                        {{-- 哪吒[差评预警]: 未回复差评数红角标 —— 全站每页可见, 解决"数据看板不常看→功能难发现"。计数收口 NezhaBadReview 单一真相源(与看板卡/深链同源), 0 时不显。 --}}
                        @php($__nzBadReview = \App\CentralLogics\NezhaBadReview::count(\App\CentralLogics\Helpers::get_restaurant_id()))
                        <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/reviews')?'active':''}}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                               href="{{route('vendor.reviews')}}" title="{{translate('messages.Review')}}">
                                <i class="tio-star-outlined nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate sidebar--badge-container">
                                {{translate('messages.Review')}}
                                @if($__nzBadReview > 0)
                                    <span class="badge badge-soft-danger badge-pill ml-1">{{ $__nzBadReview }}</span>
                                @endif
                            </span>
                            </a>
                        </li>
                    @endif

                    <!-- End Food Management -->

                    <!-- Promotion Management -->

                    @if (\App\CentralLogics\Helpers::employee_module_permission_check('campaign') || \App\CentralLogics\Helpers::employee_module_permission_check('coupon'))
                        <li class="nav-item">
                            <small class="nav-subtitle">{{translate('Promotions Management')}}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                    @endif

                    @if(false && \App\CentralLogics\Helpers::employee_module_permission_check('campaign')) {{-- 哪吒停用·StackFood残留 --}}
                        <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/campaign*')?'active':''}}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:"
                               title="{{translate('Campaign')}}">
                                <i class="tio-image nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{translate('Campaign')}}</span>
                            </a>
                            <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                style="display: {{Request::is('restaurant-panel/campaign*') ? 'block' : 'none' }}">
                                <li class="navbar-vertical-aside-has-menu {{ Request::is('restaurant-panel/campaign/list') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.campaign.list') }}" title="{{ translate('messages.basic_campaign') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate text-capitalize">{{ translate('messages.basic_campaign') }}</span>
                                    </a>
                                </li>
                                <li class="navbar-vertical-aside-has-menu @yield('campaign_view') {{ Request::is('restaurant-panel/campaign/item/list') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.campaign.itemlist') }}" title="{{ translate('messages.food_campaign') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate text-capitalize">{{ translate('messages.food_campaign') }}</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    @endif

                    @if (\App\CentralLogics\Helpers::employee_module_permission_check('coupon'))
                        <li class="navbar-vertical-aside-has-menu {{ Request::is('restaurant-panel/coupon*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                               href="{{ route('vendor.coupon.add-new') }}"
                               title="{{ translate('messages.vendor_nav_coupon_management') }}">
                                <i class="tio-ticket nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{ translate('messages.vendor_nav_coupon_management') }}</span>
                            </a>
                        </li>
                    @endif

                    @if ((int) (\App\Models\BusinessSetting::where('key', 'nezha_tiered_discount_status')->value('value') ?? 0) === 1)
                        <li class="navbar-vertical-aside-has-menu {{ Request::is('restaurant-panel/nezha-discount*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                               href="{{ route('vendor.nezha-discount.index') }}"
                               title="多级满减">
                                <i class="tio-percent nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">多级满减</span>
                            </a>
                        </li>
                    @endif

                    <!-- End Promotion Management -->

                    <!-- Help & Support -->

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('chat'))
                        <li class="nav-item">
                            <small class="nav-subtitle"
                                   title="{{translate('messages.help_&_support')}}">{{translate('messages.help_&_support')}}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                    @endif

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('chat'))
                        <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/message*')?'active':''}}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                               href="{{route('vendor.message.list', ['tab' => 'customer'])}}" title="{{translate('messages.vendor_nav_messages')}}">
                                <i class="tio-chat nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                {{translate('messages.vendor_nav_messages')}}
                            </span>
                            </a>
                        </li>
                    @endif

                    <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/feedback*')?'active':''}}">
                        <a class="js-navbar-vertical-aside-menu-link nav-link"
                           href="{{route('vendor.feedback.index')}}" title="{{translate('问题反馈')}}">
                            <i class="tio-help-outlined nav-icon"></i>
                            <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                            {{translate('问题反馈')}}
                        </span>
                        </a>
                    </li>

                    <!-- End Help & Support -->

                    <!-- Ads Management -->

                    @if (\App\CentralLogics\Helpers::employee_module_permission_check('new_ads') || \App\CentralLogics\Helpers::employee_module_permission_check('ads_list'))
                        <li class="nav-item">
                            <small class="nav-subtitle">{{translate('Ads Management')}}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                    @endif

                    @if (\App\CentralLogics\Helpers::employee_module_permission_check('new_ads'))
                        <li class="navbar-vertical-aside-has-menu @yield('advertisement_create')">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                               href="{{ route('vendor.advertisement.create') }}"
                               title="{{ translate('messages.vendor_nav_new_promotion') }}">
                                <i class="tio-tv-old nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{ translate('messages.vendor_nav_new_promotion') }}</span>
                            </a>
                        </li>
                    @endif

                    @if (\App\CentralLogics\Helpers::employee_module_permission_check('ads_list'))
                        <li class="navbar-vertical-aside-has-menu @yield('advertisement')">
                            <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle"
                               href="javascript:" title="{{translate('messages.vendor_nav_promotion_list')}}">
                                <i class="tio-format-bullets nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{translate('messages.vendor_nav_promotion_list')}}</span>
                            </a>
                            <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                style="display: {{ !Request::is('restaurant-panel/advertisement/create*') && Request::is('restaurant-panel/advertisement*')?'block':'none'}}">
                                <li class="nav-item @yield('advertisement_pending_list')">
                                    <a class="nav-link " href="{{route('vendor.advertisement.index',['type'=> 'pending'])}}"
                                       title="{{translate('messages.Pending')}}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">{{translate('messages.Pending')}}</span>
                                    </a>
                                </li>

                                <li class="nav-item @yield('advertisement_list')">
                                    <a class="nav-link " href="{{route('vendor.advertisement.index')}}"
                                       title="{{translate('messages.Ad_List')}}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">{{translate('messages.List')}}</span>
                                    </a>
                                </li>
                                @if ((int) (\App\Models\BusinessSetting::where('key', 'nezha_ad_auction_status')->value('value') ?? 0) === 1)
                                <li class="nav-item @yield('advertisement_promotion')">
                                    <a class="nav-link " href="{{ route('vendor.advertisement.promotion') }}"
                                       title="竞价推广">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">竞价推广</span>
                                    </a>
                                </li>
                                @endif
                            </ul>
                        </li>
                    @endif

                    <!-- End Ads Management -->

                    <!-- Wallet Management -->

                    {{-- 钱包侧边栏入口隐藏: B方案平台不碰钱/系统无提现入口,此为StackFood残留菜单。删掉本@if里的 false && 即可恢复显示;路由与数据均未动 --}}
                    @if(false && (\App\CentralLogics\Helpers::employee_module_permission_check('wallet_method') ||
                        \App\CentralLogics\Helpers::employee_module_permission_check('my_wallet')))
                        <!-- Business Section-->
                        <li class="nav-item">
                            <small class="nav-subtitle"
                                   title="{{translate('messages.wallet_management')}}">{{translate('messages.wallet_management')}}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                    @endif

                    @if(false && \App\CentralLogics\Helpers::employee_module_permission_check('my_wallet'))
                        <!-- RestaurantWallet -->
                        <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/wallet*')?'active':''}}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link" href="{{route('vendor.wallet.index')}}" title="{{translate('messages.my_wallet')}}">
                                <i class="tio-table nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{translate('messages.my_wallet')}}</span>
                            </a>
                        </li>
                    @endif

                    <!-- End Wallet Management -->

                    <!-- Deliveryman Management-->

                    @if(false && \App\CentralLogics\Helpers::employee_module_permission_check('deliveryman')) {{-- 哪吒停用·StackFood残留 --}}
                        <li class="nav-item">
                            <small class="nav-subtitle"
                                   title="{{translate('messages.deliveryman_section')}}">{{translate('messages.deliveryman_management')}}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                        <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/delivery-man/add')?'active':''}}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                               href="{{route('vendor.delivery-man.add')}}"
                               title="{{translate('messages.add_delivery_man')}}">
                                <i class="tio-running nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{translate('messages.add_delivery_man')}}
                                </span>
                            </a>
                        </li>
                        <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/delivery-man/list') || Request::is('restaurant-panel/delivery-man/edit/*') || Request::is('restaurant-panel/delivery-man/preview/*')?'active':''}}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                               href="{{route('vendor.delivery-man.list')}}"
                               title="{{translate('messages.deliveryman_list')}}">
                                <i class="tio-filter-list nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{translate('messages.deliverymen_list')}}
                                </span>
                            </a>
                        </li>

                        {{--<li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/delivery-man/reviews/list')?'active':''}}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                               href="{{route('vendor.delivery-man.reviews.list')}}" title="{{translate('messages.reviews')}}"
                            >
                                <i class="tio-star-outlined nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{translate('messages.reviews')}}
                                </span>
                            </a>
                        </li>--}}
                    @endif

                    <!-- End Deliveryman Management -->

                    <!-- Reports -->

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('expense_report') ||
                        \App\CentralLogics\Helpers::employee_module_permission_check('transaction') ||
                        \App\CentralLogics\Helpers::employee_module_permission_check('disbursement') ||
                        \App\CentralLogics\Helpers::employee_module_permission_check('order_report') ||
                        \App\CentralLogics\Helpers::employee_module_permission_check('food_report'))
                        <li class="nav-item">
                            <small class="nav-subtitle" title="{{translate('messages.Reports')}}">{{translate('messages.Reports')}}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                    @endif

                    @if(false && \App\CentralLogics\Helpers::employee_module_permission_check('expense_report')) {{-- 哪吒停用·StackFood残留 --}}
                        <li class="navbar-vertical-aside-has-menu {{ Request::is('restaurant-panel/report/expense-report') ? 'active' : '' }}">
                            <a class="nav-link " href="{{ route('vendor.report.expense-report') }}" title="{{ translate('messages.expense_report') }}">
                                <span class="tio-money nav-icon"></span>
                                <span class="text-truncate">{{ translate('messages.expense_report') }}</span>
                            </a>
                        </li>
                    @endif

                    @if(false && \App\CentralLogics\Helpers::employee_module_permission_check('transaction')) {{-- 哪吒停用·StackFood残留 --}}
                        <li class="navbar-vertical-aside-has-menu {{ Request::is('restaurant-panel/report/transaction-report') ? 'active' : '' }}">
                            <a class="nav-link " href="{{ route('vendor.report.day-wise-report') }}"
                               title="{{ translate('messages.transaction_report') }}">
                                <span class="tio-chart-pie-1 nav-icon"></span>
                                <span class="text-truncate">{{ translate('messages.transaction_report') }}</span>
                            </a>
                        </li>
                    @endif

                    @if(false) {{-- 哪吒外卖 B方案隐藏「打款报表」: 平台不向商家打款(INVARIANTS L1-5,提现/打款腿已拔),此报表恒空且误导;保留路由 vendor.report.disbursement-report,恢复直付台账时把 false 改回 \App\CentralLogics\Helpers::employee_module_permission_check('disbursement') 即可 --}}
                        <li class="navbar-vertical-aside-has-menu {{ Request::is('restaurant-panel/report/disbursement-report') ? 'active' : '' }}">
                            <a class="nav-link " href="{{ route('vendor.report.disbursement-report') }}"
                               title="{{ translate('messages.disbursement_report') }}">
                                <span class="tio-saving nav-icon"></span>
                                <span class="text-truncate">{{ translate('messages.disbursement_report') }}</span>
                            </a>
                        </li>
                    @endif

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('order_report'))
                        <li class="navbar-vertical-aside-has-menu  {{Request::is('restaurant-panel/report/order-report') || Request::is('restaurant-panel/report/campaign-order-report') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:"
                               title="{{ translate('messages.Order_Report') }}">
                                <i class="tio-report nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{ translate('messages.Order_Report') }}</span>
                            </a>
                            <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                style="display: {{Request::is('restaurant-panel/report/order-report') || Request::is('restaurant-panel/report/campaign-order-report') ? 'block' : 'none' }}">
                                <li class="navbar-vertical-aside-has-menu {{ Request::is('restaurant-panel/report/order-report') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.report.order-report') }}" title="{{ translate('messages.order_report') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate text-capitalize">{{ translate('messages.Regular_order_report') }}</span>
                                    </a>
                                </li>
                                @if(false){{-- 哪吒:活动订单报表停用 --}}
                                <li class="navbar-vertical-aside-has-menu {{ Request::is('restaurant-panel/report/campaign-order-report') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('vendor.report.campaign_order-report') }}" title="{{ translate('messages.Campaign_Order_Report') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate text-capitalize">{{ translate('messages.Campaign_Order_Report') }}</span>
                                    </a>
                                </li>
                                @endif
                            </ul>
                        </li>
                    @endif

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('food_report'))
                        <li class="navbar-vertical-aside-has-menu {{ Request::is('restaurant-panel/report/food-wise-report') ? 'active' : '' }}">
                            <a class="nav-link " href="{{ route('vendor.report.food-wise-report') }}"
                               title="{{ translate('messages.food_report') }}">
                                <span class="tio-fastfood nav-icon"></span>
                                <span class="text-truncate">{{ translate('messages.food_report') }}</span>
                            </a>
                        </li>
                    @endif

                    @if(false && \App\CentralLogics\Helpers::employee_module_permission_check('tax_report')) {{-- 哪吒停用·StackFood残留 --}}
                        <li class="navbar-vertical-aside-has-menu @yield('vendor_tax_report')">
                            <a class="nav-link " href="{{ route('vendor.report.vendorTax') }}"
                               title="{{ translate('messages.Tax_Report') }}">
                                <span class="tio-saving nav-icon"></span>
                                <span class="text-truncate">{{ translate('messages.Tax_Report') }}</span>
                            </a>
                        </li>
                    @endif
                    @if(false) {{-- 哪吒外卖 B方案隐藏「收入报表」: 直付单不记 total_earning(INVARIANTS L1-5),此报表恒空且误导;保留路由 vendor.report.restaurant-earning-report,如改造成真实销售额视图再放出(把 false 改回 \App\CentralLogics\Helpers::employee_module_permission_check('earning_report')) --}}
                        <li class="navbar-vertical-aside-has-menu @yield('restaurant_earning_report')">
                            <a class="nav-link " href="{{ route('vendor.report.restaurant-earning-report') }}"
                               title="{{ translate('messages.Restaurant_Earning_Report') }}">
                                <span class="tio-money nav-icon"></span>
                                <span class="text-truncate">{{ translate('Earning Report') }}</span>
                            </a>
                        </li>
                    @endif

                    <!-- End Reports -->

                    <!-- Business Management -->
                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('business_plan') ||
                        \App\CentralLogics\Helpers::employee_module_permission_check('my_qr_code') ||
                        \App\CentralLogics\Helpers::employee_module_permission_check('my_restaurant') ||
                        \App\CentralLogics\Helpers::employee_module_permission_check('notification_setup') ||
                        \App\CentralLogics\Helpers::employee_module_permission_check('restaurant_config'))
                        <li class="nav-item">
                            <small class="nav-subtitle"
                                   title="{{translate('messages.business_section')}}">{{translate('messages.business_management')}}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                    @endif

                    <?php
                        $restaurantActive = request()->is([
                            'restaurant-panel/restaurant/view',
                            'restaurant-panel/business-settings/restaurant-setup',
                            'restaurant-panel/withdraw-method',
                            'restaurant-panel/restaurant/qr-view',
                            'restaurant-panel/business-settings/notification-setup',
                        ]);
                    ?>

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('my_restaurant'))
                        <li class="navbar-vertical-aside-has-menu {{ $restaurantActive ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                               href="{{ route('vendor.shop.view') }}"
                               title="{{ translate('My Restaurant') }}">
                                <i class="tio-home nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('restaurant_configuration') }}
                                </span>
                            </a>
                        </li>
                    @endif

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('my_restaurant'))
                        <li class="navbar-vertical-aside-has-menu {{ Request::is('restaurant-panel/restaurant/brand') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                               href="{{ route('vendor.shop.brand') }}"
                               title="{{ translate('门店形象') }}">
                                <i class="tio-photo nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('门店形象') }}
                                </span>
                            </a>
                        </li>
                    @endif


                @if(false && \App\CentralLogics\Helpers::employee_module_permission_check('business_plan')) {{-- 哪吒停用·StackFood残留 --}}
                        <li class="navbar-vertical-aside-has-menu @yield('subscriberList')">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                               href="{{route('vendor.subscriptionackage.subscriberDetail')}}"
                               title="{{translate('messages.My_Business_Plan')}}">
                                <i class="tio-crown nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                {{translate('messages.My_Business_Plan')}}
                            </span>
                            </a>
                        </li>
                    @endif

                    <!-- End Business Management -->

                    <!-- Employee Management -->

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('role_management') || \App\CentralLogics\Helpers::employee_module_permission_check('all_employee'))
                        <!-- Employee-->
                        <li class="nav-item">
                            <small class="nav-subtitle" title="{{translate('messages.employee_management')}}">{{translate('messages.employee_management')}}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                    @endif

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('role_management'))
                        <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/custom-role*')?'active':''}}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link" href="{{route('vendor.custom-role.create')}}"
                               title="{{translate('messages.employee_Role')}}">
                                <i class="tio-incognito nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{translate('messages.employee_Role')}}</span>
                            </a>
                        </li>
                    @endif

                    @if(\App\CentralLogics\Helpers::employee_module_permission_check('all_employee'))
                        <li class="navbar-vertical-aside-has-menu {{Request::is('restaurant-panel/employee*')?'active':''}}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:"
                               title="{{translate('messages.employees')}}">
                                <i class="tio-user nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{translate('messages.all_employee')}}</span>
                            </a>
                            <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                style="display: {{Request::is('restaurant-panel/employee*')?'block':'none'}}">
                                <li class="nav-item {{Request::is('restaurant-panel/employee/add-new')?'active':''}}">
                                    <a class="nav-link " href="{{route('vendor.employee.add-new')}}" title="{{translate('messages.add_new_Employee')}}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">{{translate('messages.add_new_employee')}}</span>
                                    </a>
                                </li>
                                <li class="nav-item {{Request::is('restaurant-panel/employee/list')?'active':''}}">
                                    <a class="nav-link " href="{{route('vendor.employee.list')}}" title="{{translate('messages.Employee_list')}}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate">{{translate('messages.list')}}</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    @endif

                    <!-- End Employee Management -->

                    <!-- Ads -->

                    @if (\App\CentralLogics\Helpers::employee_module_permission_check('new_ads'))
                        <li class="nav-item px-20 pb-5">
                            <div class="promo-card">
                                <div class="position-relative">
                                    <img src="{{dynamicAsset('assets/admin/img/promo.png')}}" class="mw-100" alt="">
                                    <h4 class="mb-2 mt-3">{{ translate('Want_to_get_highlighted?') }}</h4>
                                    <p class="mb-4">
                                        {{ translate('Create_ads_to_get_highlighted_on_the_app_and_web_browser') }}
                                    </p>
                                    <a href="{{ route('vendor.advertisement.create') }}" class="btn btn--primary">{{ translate('Create_Ads') }}</a>
                                </div>
                            </div>
                        </li>
                    @endif

                    <!-- End Ads -->
                </ul>
            </div>
        </div>
    </aside>
</div>

<div id="sidebarCompact" class="d-none">

</div>



@push('script_2')
    <script>
        "use strict";
        $(window).on('load' , function() {
            if($(".navbar-vertical-content li.active").length) {
                $('.navbar-vertical-content').animate({
                    scrollTop: $(".navbar-vertical-content li.active").offset().top - 150
                }, 100);
            }
        });
    </script>
@endpush
