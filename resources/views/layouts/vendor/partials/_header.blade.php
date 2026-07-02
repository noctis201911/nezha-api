<div id="headerMain" class="d-none">
    <header id="header"
        class="navbar navbar-expand-lg navbar-fixed navbar-height navbar-flush navbar-container navbar-bordered">
        <div class="navbar-nav-wrap">
            <div class="navbar-brand-wrapper">
                <!-- Logo Div-->
                @php($restaurant_logo = \App\CentralLogics\Helpers::get_restaurant_data()?->logo_full_url)
                <a class="navbar-brand" href="{{ route('vendor.dashboard') }}" aria-label="">
                    <img class="navbar-brand-logo logo--design" src="{{ $restaurant_logo }}" alt="image">
                    <img class="navbar-brand-logo-mini logo--design" src="{{ $restaurant_logo }}" alt="image">
                </a>
                <!-- End Logo -->
            </div>
            <div class="navbar-nav-wrap-content-left ml-auto d--xl-none">
                <!-- Navbar Vertical Toggle -->
                <button type="button" class="js-navbar-vertical-aside-toggle-invoker close">
                    <i class="tio-first-page navbar-vertical-aside-toggle-short-align" data-toggle="tooltip"
                        data-placement="right" title="Collapse"></i>
                    <i class="tio-last-page navbar-vertical-aside-toggle-full-align"
                        data-template='<div class="tooltip d-none d-sm-block" role="tooltip"><div class="arrow"></div><div class="tooltip-inner"></div></div>'></i>
                </button>
                <!-- End Navbar Vertical Toggle -->
            </div>






            <!-- Secondary Content -->
            <div class="navbar-nav-wrap-content-right flex-grow-1">
                <!-- Navbar -->
                <ul class="navbar-nav align-items-center flex-row justify-content-end">

                    <li class="nav-item max-sm-m-0 w-md-200px">
                        <button type="button" id="modalOpener" class="title-color bg--secondary border-0 rounded justify-content-between w-100 align-items-center py-2 px-2 px-md-3 d-flex gap-1" data-toggle="modal" data-target="#staticBackdrop">
                            <div class="d-flex gap-1 align-items-center">
                                <i class="tio-search"></i>
                                <span class="d-none d-md-block text-muted">{{translate('Search')}}</span>
                            </div>
                            <span class="bg-card text-muted border rounded-3 p-1 fs-12 fw-bold lh-1 ms-1 ctrlplusk d-none d-md-block">Ctrl+K</span>
                        </button>
                    </li>

                    <li class="nav-item max-sm-m-0">
                        <div class="hs-unfold">
                            <div>
                                @php($local = session()->has('vendor_local') ? session('vendor_local') : null)
                                @php($lang = \App\CentralLogics\Helpers::get_business_settings('system_language'))
                                {{-- 哪吒商家端全中文: 隐藏语言切换器(已强制zh) --}}
                                @if (false && $lang)
                                    <div class="topbar-text dropdown disable-autohide text-capitalize d-flex">
                                        <a class="text-dark dropdown-toggle d-flex align-items-center nav-link"
                                            href="#" data-toggle="dropdown">
                                            @foreach ($lang ??[] as $data)
                                                @if ($data['code'] == $local)
                                                    <img class="rounded mr-1" width="20"
                                                        src="{{ dynamicAsset('assets/admin/img/lang.png') }}"
                                                        alt="">
                                                    {{ $data['code'] }}
                                                @elseif(!$local && $data['default'] == true)
                                                    <img class="rounded mr-1" width="20"
                                                        src="{{ dynamicAsset('assets/admin/img/lang.png') }}"
                                                        alt="">
                                                    {{ $data['code'] }}
                                                @endif
                                            @endforeach
                                        </a>
                                        <ul class="dropdown-menu">
                                            @foreach ($lang ??[] as $key => $data)
                                                @if ($data['status'] == 1)
                                                    <li>
                                                        <a class="dropdown-item py-1"
                                                            href="{{ route('vendor.lang', [$data['code']]) }}">

                                                            <span class="text-capitalize">{{ $data['code'] }}</span>
                                                        </a>
                                                    </li>
                                                @endif
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </li>

                    {{-- nz: 提示音设置(分类音量+总开关, 存本机) --}}
                    <li class="nav-item mr-3" style="position:relative;">
                        <div class="hs-unfold" style="position:relative;">
                            <a id="nzSoundBtn" class="btn btn-icon btn-soft-secondary rounded-circle" href="javascript:;" data-toggle="tooltip" title="提示音设置" aria-label="提示音设置">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z"></path><path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path><path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path></svg>
                            </a>
                            <div id="nzSoundPop" style="display:none;position:absolute;right:0;top:calc(100% + 8px);width:340px;max-width:88vw;background:#fff;border:1px solid #ededed;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.16);z-index:100001;font-family:'PingFang SC','Microsoft YaHei',sans-serif;text-align:left;">
                                <style>
                                #nzSoundPop .nz-snd-grp{font-size:12px;color:#9aa0a6;padding:10px 14px 4px;}
                                #nzSoundPop .nz-snd-row{display:flex;align-items:center;gap:10px;padding:6px 14px;}
                                #nzSoundPop .nz-snd-name{width:78px;flex:none;font-size:13.5px;color:#1f1f1f;}
                                #nzSoundPop .nz-snd-sl{flex:1;min-width:0;accent-color:#C4193E;height:20px;}
                                #nzSoundPop .nz-snd-val{width:36px;flex:none;text-align:right;font-size:12.5px;color:#6b7075;}
                                #nzSoundPop .nz-snd-test{flex:none;width:28px;height:28px;border-radius:7px;border:1px solid #e6e6e6;background:#fafafa;color:#6b7075;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;padding:0;font-size:10px;line-height:1;}
                                #nzSoundPop .nz-snd-test:hover{background:#f0f0f0;}
                                #nzSoundPop .nz-snd-off .nz-snd-row{opacity:.4;}
                                #nzSoundPop .nz-snd-sw{position:relative;display:inline-block;width:42px;height:24px;vertical-align:middle;}
                                #nzSoundPop .nz-snd-sw input{opacity:0;width:0;height:0;position:absolute;margin:0;}
                                #nzSoundPop .nz-snd-sw .nz-snd-track{position:absolute;inset:0;background:#ccc;border-radius:20px;transition:.15s;cursor:pointer;}
                                #nzSoundPop .nz-snd-sw .nz-snd-track:before{content:"";position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.15s;}
                                #nzSoundPop .nz-snd-sw input:checked + .nz-snd-track{background:#C4193E;}
                                #nzSoundPop .nz-snd-sw input:checked + .nz-snd-track:before{transform:translateX(18px);}
                                </style>
                                <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-bottom:1px solid #f0f0f0;">
                                    <span style="display:flex;align-items:center;gap:8px;font-weight:600;font-size:15px;color:#1f1f1f;">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#C4193E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z"></path><path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path><path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path></svg>
                                        提示音
                                    </span>
                                    <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:#6b7075;cursor:pointer;margin:0;">全部开启
                                        <span class="nz-snd-sw"><input type="checkbox" id="nzSoundMaster" checked><span class="nz-snd-track"></span></span>
                                    </label>
                                </div>
                                <div id="nzSoundBody">
                                    <div class="nz-snd-grp">接单提醒</div>
                                    <div class="nz-snd-row"><span class="nz-snd-name">新订单</span><input type="range" class="nz-snd-sl" data-cat="new_order" min="0" max="100" step="1" value="90"><span class="nz-snd-val" data-cat="new_order">90%</span><button type="button" class="nz-snd-test" data-cat="new_order" data-el="myAudio" aria-label="试听新订单">&#9654;</button></div>
                                    <div class="nz-snd-row"><span class="nz-snd-name">订单超时</span><input type="range" class="nz-snd-sl" data-cat="timeout" min="0" max="100" step="1" value="90"><span class="nz-snd-val" data-cat="timeout">90%</span><button type="button" class="nz-snd-test" data-cat="timeout" data-el="myAudio" aria-label="试听订单超时">&#9654;</button></div>
                                    <div class="nz-snd-grp">消息提醒</div>
                                    <div class="nz-snd-row"><span class="nz-snd-name">顾客消息</span><input type="range" class="nz-snd-sl" data-cat="customer_msg" min="0" max="100" step="1" value="70"><span class="nz-snd-val" data-cat="customer_msg">70%</span><button type="button" class="nz-snd-test" data-cat="customer_msg" data-el="nzMsgAudio" aria-label="试听顾客消息">&#9654;</button></div>
                                    <div class="nz-snd-row"><span class="nz-snd-name">平台客服</span><input type="range" class="nz-snd-sl" data-cat="platform_msg" min="0" max="100" step="1" value="70"><span class="nz-snd-val" data-cat="platform_msg">70%</span><button type="button" class="nz-snd-test" data-cat="platform_msg" data-el="nzAdminMsgAudio" aria-label="试听平台客服">&#9654;</button></div>
                                    <div class="nz-snd-grp">物流提醒</div>
                                    <div class="nz-snd-row"><span class="nz-snd-name">催配送</span><input type="range" class="nz-snd-sl" data-cat="deliv" min="0" max="100" step="1" value="70"><span class="nz-snd-val" data-cat="deliv">70%</span><button type="button" class="nz-snd-test" data-cat="deliv" data-el="nzDelivAudio" aria-label="试听催配送">&#9654;</button></div>
                                </div>
                                <div style="padding:10px 14px;background:#FFF7E6;color:#8a6d1b;font-size:12px;line-height:1.55;border-top:1px solid #f5ecd0;">静音只关声音——新订单提示弹窗和订单列表照常显示，不会漏单。</div>
                            </div>
                        </div>
                    </li>
                    <li class="nav-item nav--item">
                        <!-- Account -->
                        <div class="hs-unfold">
                            <a class="js-hs-unfold-invoker navbar-dropdown-account-wrapper p-0" href="javascript:;"
                                data-hs-unfold-options='{
                                     "target": "#accountNavbarDropdown",
                                     "type": "css-animation"
                                   }'>

                                <div class="cmn--media right-dropdown-icon d-flex align-items-center">
                                    <div class="media-body pl-0 pr-2">
                                        <span class="card-title h5 text-right pr-2">
                                            {{ \App\CentralLogics\Helpers::get_loggedin_user()->f_name }}
                                        </span>
                                        <span
                                            class="card-text card--text">{{ \App\CentralLogics\Helpers::get_loggedin_user()->email }}</span>
                                    </div>
                                    <div class="">
                                        <img class="avatar avatar-sm avatar-circle"
                                            src="{{ \App\CentralLogics\Helpers::get_loggedin_user()?->image_full_url ?? dynamicAsset('assets/admin/img/160x160/img1.jpg') }}"
                                            alt="image">

                                        <span class="avatar-status avatar-sm-status avatar-status-success"></span>
                                    </div>
                                </div>

                            </a>

                            <div id="accountNavbarDropdown"
                                class="hs-unfold-content dropdown-unfold dropdown-menu dropdown-menu-right navbar-dropdown-menu navbar-dropdown-account w-16rem">
                                <div class="dropdown-item-text">
                                    <div class="media cmn--media align-items-center">
                                        <div class="avatar avatar-sm avatar-circle mr-2">
                                            <img class="avatar-img"
                                                src="{{ \App\CentralLogics\Helpers::get_loggedin_user()?->image_full_url ?? dynamicAsset('assets/admin/img/160x160/img1.jpg') }}"
                                                alt="image">
                                        </div>
                                        <div class="media-body">
                                            <span
                                                class="card-title h5">{{ \App\CentralLogics\Helpers::get_loggedin_user()->f_name }}</span>
                                            <span
                                                class="card-text">{{ \App\CentralLogics\Helpers::get_loggedin_user()->email }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="dropdown-divider"></div>

                                <a class="dropdown-item" href="{{ route('vendor.profile.view') }}">
                                    <span class="text-truncate pr-2"
                                        title="Settings">{{ translate('messages.settings') }}</span>
                                </a>

                                <div class="dropdown-divider"></div>

                                <a class="dropdown-item" href="javascript:"
                                    onclick="Swal.fire({
                                    title: '{{ translate('Are you Sure want to sign-out?') }}',
                                    imageUrl: `{{ dynamicAsset('assets/admin/img/modal/logout.png') }}`,   // 👈 your custom image path
                                    imageWidth: 80,
                                    imageHeight: 80,
                                    imageAlt: 'Logout Image',
                                    showDenyButton: true,
                                    showCancelButton: true,
                                    confirmButtonColor: '#FF4040',
                                    cancelButtonColor: '#363636',
                                    confirmButtonText: '{{ translate('messages.Yes') }}',
                                    cancelButtonText: '{{ translate('messages.cancel') }}',
                                    }).then((result) => {
                                    if (result.value) {
                                        location.href='{{ route('logout') }}';
                                    }
                                    })">
                                    <span class="text-truncate pr-2"
                                        title="Sign out">{{ translate('messages.sign_out') }}</span>
                                </a>
                            </div>
                        </div>
                        <!-- End Account -->
                    </li>
                </ul>
                <!-- End Navbar -->
            </div>
            <!-- End Secondary Content -->
        </div>
    </header>
</div>
<div id="headerFluid" class="d-none"></div>
<div id="headerDouble" class="d-none"></div>

<?php
$wallet = \App\Models\RestaurantWallet::where('vendor_id', \App\CentralLogics\Helpers::get_vendor_id())->first();
$Payable_Balance = $wallet?->collected_cash > 0 ? 1 : 0;

$cash_in_hand_overflow =    \App\Models\BusinessSetting::where('key', 'cash_in_hand_overflow_restaurant')->first()?->value ?? 0;
$cash_in_hand_overflow_restaurant_amount = (float) \App\Models\BusinessSetting::where('key', 'cash_in_hand_overflow_restaurant_amount')->first()?->value;
$val = round($cash_in_hand_overflow_restaurant_amount - ($cash_in_hand_overflow_restaurant_amount * 10) / 100, 8);
?>

@if ($Payable_Balance == 1 && $cash_in_hand_overflow && $wallet?->balance < 0 && $val <= abs($wallet?->collected_cash))
    <div class="alert __alert-2 alert-warning m-0 py-1 px-2" role="alert">
        <img class="rounded mr-1" width="25"
            src="{{ dynamicAsset('assets/admin/img/header_warning.png') }}" alt="">
        <div class="cont">
            <h4 class="m-0">{{ translate('Attention_Please') }} </h4>
            {{ translate('The_Cash_in_Hand_amount_is_about_to_exceed_the_limit._Please_pay_the_due_amount._If_the_limit_exceeds,_your_account_will_be_suspended.') }}
        </div>
    </div>
@endif

@if (
    $Payable_Balance == 1 &&
        $cash_in_hand_overflow &&
        $wallet?->balance < 0 &&
        $cash_in_hand_overflow_restaurant_amount < $wallet?->collected_cash)
    <div class="alert __alert-2 alert-warning m-0 py-1 px-2" role="alert">
        <img class="mr-1" width="25" src="{{ dynamicAsset('assets/admin/img/header_warning.png') }}"
            alt="">
        <div class="cont">
            <h4 class="m-0">{{ translate('Attention_Please') }} </h4>
            {{ translate('The_Cash_in_Hand_amount_limit_is_exceeded._Your_account_is_now_suspended._Please_pay_the_due_amount_to_receive_new_order_requests_again.') }}<a
                href="{{ route('vendor.wallet.index') }}" class="alert-link"> &nbsp;
                {{ translate('Pay_the_due') }}</a>
        </div>
    </div>
@endif

<?php
$restaurant_data = \App\CentralLogics\Helpers::get_restaurant_data();
$subscription_deadline_warning_days = (int) \App\Models\BusinessSetting::where('key', 'subscription_deadline_warning_days')->first()?->value ?? 7;
$subscription_deadline_warning_message = \App\Models\BusinessSetting::where('key', 'subscription_deadline_warning_message')->first()?->value ?? null;
?>


<div id="hide-subscription-warnings">



    @if (
        !in_array($restaurant_data->restaurant_model, ['none', 'commission']) &&
            !Request::is('restaurant-panel/subscription/*'))

        <?php
        $pers = 10;
        if ($restaurant_data?->restaurant_sub) {
            $validity = $restaurant_data?->restaurant_sub?->validity;
            $remaining_days = Carbon\Carbon::now()->diffInDays($restaurant_data?->restaurant_sub?->expiry_date_parsed->format('Y-m-d'), false);
            $pers = $validity - $remaining_days > 0 ? (($validity - $remaining_days) / $validity) * 100 : 1;
            $pers = (439.6 * $pers) / 100;
        }
        ?>
@if (
    $restaurant_data?->restaurant_sub?->is_trial == 0 &&
        $restaurant_data?->restaurant_sub?->expiry_date_parsed &&
        $restaurant_data?->restaurant_sub->expiry_date_parsed->subDays($subscription_deadline_warning_days)->isBefore(now()) &&
        Request::is('restaurant-panel'))

    <!--Always in header Renew -->
    <div class="renew-badge mx-3 mt-3" id="renew-badge">
        <div class="renew-content d-flex align-items-center">

            <img src="{{ dynamicAsset('assets/admin/img/timer.svg') }}" alt="">
            <div class="txt">
                {{ $subscription_deadline_warning_message != null ? $subscription_deadline_warning_message : translate('Your subscription ending soon. Please renew to continue access') }}
            </div>
        </div>
        <div>
            <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['renew_now' => true]) }}"
                class="btn btn--danger">{{ translate('Renew') }}</a>
        </div>
    </div>
@elseif (Session::get('subscription_renew_close_btn') !== true &&
        $restaurant_data?->restaurant_sub?->is_trial == 0 &&
        $restaurant_data?->restaurant_sub?->expiry_date_parsed &&
        $restaurant_data?->restaurant_sub->expiry_date_parsed->subDays($subscription_deadline_warning_days)->isBefore(now()) &&
        !Request::is('restaurant-panel'))
    <div class="renew-badge mx-3 mt-3 hide-warning" id="renew-badge">
        <div class="renew-content d-flex align-items-center">

            <img src="{{ dynamicAsset('assets/admin/img/timer.svg') }}" alt="">
            <div class="txt">
                {{ $subscription_deadline_warning_message != null ? $subscription_deadline_warning_message : translate('Your subscription ending soon. Please renew to continue access') }}
            </div>
        </div>
        <div>
            @if ($restaurant_data?->restaurant_sub?->is_canceled == 1)
                <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                    class="btn btn--danger">{{ translate('Change_Subscription') }}</a>
            @else
                <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['renew_now' => true]) }}"
                    class="btn btn--danger">{{ translate('Renew') }}</a>
            @endif
            <button data-id="subscription_renew_close_btn" id="subs-hide-warning"
                class="btn btn-sm btn-primary add-to-session">{{ translate('remind_me_later') }}</button>
        </div>
    </div>
    <!-- Renew -->


@endif




        @if (Session::get('subscription_free_trial_close_btn') !== true &&
                $restaurant_data?->restaurant_sub?->status == 1 &&
                $restaurant_data?->restaurant_sub?->is_trial == 1 &&
                $restaurant_data?->restaurant_sub?->is_canceled == 0)
            <div class="free-trial trial success-bg">
                <div class="inner-div">
                    <div class="left">
                        <img src="{{ dynamicAsset('assets/admin/img/icon-puck.svg') }}" alt="">
                        <div class="left-content">
                            <h6>{{ translate('Get the best experience of your business') }}</h6>
                            <div>{{ translate('Run your business with the most popular platform') }}</div>
                        </div>
                    </div>
                    <div class="right">
                        <a href="#" class="btn btn-2">
                            <span class="circle-progress-container">
                                <svg width="40" viewBox="0 0 160 160">
                                    <circle r="70" cx="80" cy="80" fill="transparent"
                                        stroke="#ffffff20" stroke-width="12px"></circle>
                                    <circle r="70" cx="80" cy="80" fill="transparent" stroke="#ffffff"
                                        stroke-width="12px" stroke-dasharray="439.6px"
                                        stroke-dashoffset="{{ $pers }}px"></circle>
                                </svg>
                                {{1+ Carbon\Carbon::now()->diffInDays($restaurant_data?->restaurant_sub?->expiry_date_parsed->format('Y-m-d'), false) }}
                            </span>
                            {{ translate('Days_left_in_free_trial') }}
                        </a>
                        <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                            class="btn btn-light">{{ translate('Choose_Subscription_Plan') }} <i
                                class="tio-arrow-forward"></i></a>
                    </div>

                    <button type="button" data-id="subscription_free_trial_close_btn"
                        class="trial-close add-to-session ">
                        <i class="tio-clear-circle"></i>
                    </button>
                </div>
            </div>
        @elseif ($restaurant_data?->restaurant_sub == null && $restaurant_data?->restaurant_sub_update_application?->is_trial == 1)
            <div class="modal fade show trial-ended-modal" id="free-trial-modal">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-body p-0">
                            <div class="trial-ended-modal-wrapper">
                                {{-- <button type="button" class="trial-ended-close-btn text-md-white" data-dismiss="modal">
                                <i class="tio-clear-circle"></i>
                            </button> --}}
                                <div class="trial-ended-modal-content align-self-center">
                                    <h3 class="title">{{ translate('Your_Free_Trial_Has_Been_Ended') }}</h3>
                                    <p class="mb-4">
                                        {{ translate('Purchase a subscription plan or contact with the admin to settle the payment and unblock the access to service.') }}
                                    </p>
                                    <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                                        class="btn btn--primary">{{ translate('Choose Subscription Plan') }} <i
                                            class="tio-arrow-forward"></i></a>
                                    <div class="blocked-subscription mt-5">
                                        <img src="{{ dynamicAsset('assets/admin/img/WarningOctagon.svg') }}"
                                            alt="">
                                        <span>{{ translate('All Access to service has been blocked due to no active subscription') }}</span>
                                    </div>
                                </div>
                                <div class="trial-ended-modal-img d-none d-md-block">
                                    <img src="{{ dynamicAsset('assets/admin/img/trial-ended-bg.png') }}"
                                        alt="">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>



            <div class="free-trial trial danger-bg">
                <div class="inner-div">
                    <div class="left">
                        <img src="{{ dynamicAsset('assets/admin/img/timer-2.svg') }}" alt="">
                        <div class="left-content">
                            <h6>{{ translate('Free_Trial_Has_Been_Ended') }}</h6>
                            <div>{{ translate('Get_a_subscription_plan_to_continue_with_your_business') }}</div>
                        </div>
                    </div>
                    <div class="right">
                        <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                            class="btn btn-light">{{ translate('Choose_Subscription_Plan') }} <i
                                class="tio-arrow-forward"></i></a>
                    </div>
                    {{-- <button type="button" class="trial-close">
                    <i class="tio-clear-circle"></i>
                </button> --}}
                </div>
            </div>
        @elseif (Session::get('subscription_cancel_close_btn') !== true &&
                $restaurant_data?->restaurant_sub &&
                $restaurant_data?->restaurant_sub?->is_canceled == 1)
            <div class="free-trial trial danger-bg">
                <div class="inner-div">
                    <div class="left">
                        <img src="{{ dynamicAsset('assets/admin/img/timer-2.svg') }}" alt="">
                        <div class="left-content">
                            <h6>{{ translate('Your_Subscription_Has_Been_Cnaceled_by') }}
                                {{ $restaurant_data?->restaurant_sub?->canceled_by == 'admin' ? translate($restaurant_data?->restaurant_sub?->canceled_by) : translate('Yourself') }}
                            </h6>
                            <div>{{ translate('You_can_not_consume_your_subscription_after') }}
                                {{ \App\CentralLogics\Helpers::date_format($restaurant_data?->restaurant_sub?->expiry_date_parsed) }}
                            </div>
                        </div>
                    </div>
                    <div class="right">
                        <a href="#" class="btn btn-2">
                            <span class="circle-progress-container">
                                <svg width="40" viewBox="0 0 160 160">
                                    <circle r="70" cx="80" cy="80" fill="transparent"
                                        stroke="#ffffff20" stroke-width="12px"></circle>
                                    <circle r="70" cx="80" cy="80" fill="transparent" stroke="#ffffff"
                                        stroke-width="12px" stroke-dasharray="439.6px"
                                        stroke-dashoffset="{{ $pers }}px"></circle>
                                </svg>
                                {{1+ Carbon\Carbon::now()->diffInDays($restaurant_data?->restaurant_sub?->expiry_date_parsed->format('Y-m-d'), false) }}
                            </span>
                            {{ translate('Days_left_in_this_subscription') }}
                        </a>
                        <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                            class="btn btn-light">{{ translate('Change_Subscription_Plan') }} <i
                                class="tio-arrow-forward"></i></a>
                    </div>

                    <button type="button" data-id="subscription_cancel_close_btn"
                        class="trial-close add-to-session ">
                        <i class="tio-clear-circle"></i>
                    </button>
                </div>
            </div>
        @elseif (Session::get('subscription_plan_update_close_btn') !== true &&
                $restaurant_data?->restaurant_sub &&
                $restaurant_data?->restaurant_sub?->package?->status != 1)
            <div class="free-trial trial danger-bg">
                <div class="inner-div">
                    <div class="left">
                        <img src="{{ dynamicAsset('assets/admin/img/timer-2.svg') }}" alt="">
                        <div class="left-content">
                            <h6>{{ translate('Your_Current_Subscription_Package_has_been_Disable_By_Admin.') }} </h6>
                            <div>{{ translate('You_can_not_renew_this_Package_after') }}
                                {{ \App\CentralLogics\Helpers::date_format($restaurant_data?->restaurant_sub?->expiry_date_parsed) }}.
                                {{ translate('to_continue_your_subscription_please_chose_another_package.') }}</div>
                        </div>
                    </div>
                    <div class="right">
                        <a href="#" class="btn btn-2">
                            <span class="circle-progress-container">
                                <svg width="40" viewBox="0 0 160 160">
                                    <circle r="70" cx="80" cy="80" fill="transparent"
                                        stroke="#ffffff20" stroke-width="12px"></circle>
                                    <circle r="70" cx="80" cy="80" fill="transparent" stroke="#ffffff"
                                        stroke-width="12px" stroke-dasharray="439.6px"
                                        stroke-dashoffset="{{ $pers }}px"></circle>
                                </svg>
                                {{1+ Carbon\Carbon::now()->diffInDays($restaurant_data?->restaurant_sub?->expiry_date_parsed->format('Y-m-d'), false) }}
                            </span>
                            {{ translate('Days_left_in_this_subscription') }}
                        </a>
                        <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                            class="btn btn-light">{{ translate('Change_Subscription_Plan') }} <i
                                class="tio-arrow-forward"></i></a>
                    </div>

                    <button type="button" data-id="subscription_plan_update_close_btn"
                        class="trial-close add-to-session ">
                        <i class="tio-clear-circle"></i>
                    </button>
                </div>
            </div>
        @elseif ($restaurant_data?->restaurant_model == 'unsubscribed' && !$restaurant_data?->restaurant_sub_update_application )
            <div class="free-trial trial danger-bg">
                <div class="inner-div">
                    <div class="left">
                        <img src="{{ dynamicAsset('assets/admin/img/timer-2.svg') }}" alt="">
                        <div class="left-content">
                            <h6>{{ translate('Your_are_not_subscribed') }}
                                {{-- {{ \App\CentralLogics\Helpers::date_format($restaurant_data?->restaurant_sub_update_application?->expiry_date_parsed) }} --}}
                            </h6>
                            <div>
                                {{ translate('Purchase a subscription plan or contact with the admin to settle the payment and unblock the access to service') }}
                            </div>
                        </div>
                    </div>
                    <div class="right">

                        <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                            class="btn btn-light">{{ translate('Choose Subscription_Plan') }} <i
                                class="tio-arrow-forward"></i></a>
                    </div>

                </div>
            </div>

        @elseif ($restaurant_data?->restaurant_sub == null)
            <div class="free-trial trial danger-bg">
                <div class="inner-div">
                    <div class="left">
                        <img src="{{ dynamicAsset('assets/admin/img/timer-2.svg') }}" alt="">
                        <div class="left-content">
                            <h6>{{ translate('Your_Subscription_Has_Been_Expired_on') }}
                                {{ \App\CentralLogics\Helpers::date_format($restaurant_data?->restaurant_sub_update_application?->expiry_date_parsed) }}
                            </h6>
                            <div>
                                {{ translate('Purchase a subscription plan or contact with the admin to settle the payment and unblock the access to service') }}
                            </div>
                        </div>
                    </div>
                    <div class="right">

                        <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                            class="btn btn-light">{{ translate('Change/Renew Subscription_Plan') }} <i
                                class="tio-arrow-forward"></i></a>
                    </div>

                </div>
            </div>
        @endif

    @endif
</div>


<div class="modal fade removeSlideDown" id="staticBackdrop" tabindex="-1" aria-labelledby="staticBackdropLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered max-w-520">
        <div class="modal-content modal-content__search border-0">
            <div class="d-flex flex-column gap-3 rounded-20 bg-card py-2 px-3">
                <div class="d-flex gap-2 align-items-center position-relative">
                    <form class="flex-grow-1" id="searchForm" action="{{ route('vendor.search.routing') }}">
                        @csrf
                        <div class="d-flex align-items-center global-search-container">
                            <input class="form-control flex-grow-1 rounded-10 search-input" id="searchInput" name="search" type="search" placeholder="Search" aria-label="Search" autofocus>
                        </div>
                    </form>
                    <div class="position-absolute right-0 pr-2">
                        <button class="border-0 rounded px-2 py-1" type="button" data-dismiss="modal">{{ translate('Esc') }}</button>
                    </div>
                </div>

                <div class="min-h-350">
                    <div class="search-result" id="searchResults">
                        <div class="text-center text-muted py-5">{{translate('It appears that you have not yet searched.')}}.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
    document.addEventListener('DOMContentLoaded', function() {
        $(document).on('click', '.add-to-session', function() {
            var session_data = $(this).data("id");
            $.ajax({
                url: '{{ route('vendor.subscriptionackage.addToSession') }}',
                method: 'POST',
                data: {
                    value: session_data,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    $('#hide-subscription-warnings').addClass('d-none')
                }
            });
        });
    });
</script>
