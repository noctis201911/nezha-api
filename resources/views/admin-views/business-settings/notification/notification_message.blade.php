@extends('layouts.admin.app')

@section('title', translate('Notification_Messages'))
@section('notification_message')
    active
@endsection

@section('content')
    @php
        $user_type = request('message-type') ?? 'user';
        
        $time = 1;
        $unit = 'min';
        $cron_time = $unit == 'min' ? '*/' . $time . ' * * * *' : '0 */' . $time . ' * * *';
        $cart_abandon_command = '(crontab -l ; echo "' . $cron_time . ' /usr/bin/php ' . str_replace('\\', '/', base_path('artisan')) . ' cart:reminder") | crontab -';
    @endphp
    {{-- Notification Messages Page --}}
    <div class="content">
        <form action="{{ route('admin.business-settings.updateFcmMessages') }}" method="post" enctype="multipart/form-data">
            @csrf
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="page-header pb-0">
                    <div class="d-flex flex-wrap justify-content-between align-items-start">
                        <h1 class="mb-0">{{ translate('messages.Notification_Messages') }}</h1>
                    </div>
                    <div class="d-flex flex-wrap justify-content-between align-items-center my-3 gap-3">
                        <div class="js-nav-scroller hs-nav-scroller-horizontal">
                            <!-- Nav -->
                            @include('admin-views.business-settings.notification._notification_message_urls')
                            <!-- End Nav -->
                        </div>
                    </div>

                </div>
                <!-- End Page Header -->
                <div class="d-flex gap-2 fs-12 text-dark px-3 py-2 rounded bg-warning mb-20" style="--bs-bg-opacity: 0.1;">
                    <span class="text-warning lh-1 fs-14">
                        <i class="tio-info"></i>
                    </span>
                    <span>
                        {{ translate('messages.Setup Push Notification Messages for customer. Must setup') }}
                        <a href="{{ route('admin.business-settings.fcm-index') }}"
                            class="text-underline text-primary font-semibold">{{ translate('messages.Firebase Configuration') }}
                        </a>
                        {{ translate('messages.page to work notifications.') }}
                    </span>
                </div>
                <div class="card">
                    <div class="card-header d-flex gap-3 justify-content-between align-items-center flex-wrap">
                        <div>
                            <h3 class="mb-1">{{ translate('messages.Push_Notification') }}</h3>
                            <p class="fs-12 mb-0">
                                {{ translate('messages.Configure and send real-time push notifications to engage users and share important updates.') }}
                            </p>
                        </div>
                        <button type="button" data-toggle="modal" data-target="#insertVariableModal"
                            class="btn btn--secondary min-w-120">{{ translate('messages.Insert_Variable') }} </button>
                    </div>
                    <div class="card-body pt-2">
                        @php($language = \App\CentralLogics\Helpers::get_business_settings('language'))

                        <div class="mb-3">
                            @if ($language)
                                <ul class="nav nav-tabs border-bottom overflow-x-auto flex-nowrap text-nowrap">
                                    <li class="nav-item">
                                        <a class="nav-link lang_link active" href="#"
                                            id="default-link">{{ translate('Default') }}</a>
                                    </li>
                                    @foreach ($language as $lang)
                                        <li class="nav-item">
                                            <a class="nav-link lang_link" href="#"
                                                id="{{ $lang }}-link">{{ \App\CentralLogics\Helpers::get_language_name($lang) . '(' . strtoupper($lang) . ')' }}</a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        <div class="bg-light rounded p-12 p-xxl-20 mb-20">
                            <div class="lang_form" id="default-form">
                                <input type="hidden" name="lang[]" value="default">
                                <input type="hidden" name="user_type" value="{{ $user_type }}">
                                <div class="row g-3">


                                    @foreach ($messageKey as $key => $item)
                                        @if($item == 'cart_abandon') @continue @endif

                                        <div class="col-lg-6">
                                            <div class="form-group mb-0">
                                                <div class="d-flex flex-wrap justify-content-between mb-2">
                                                    <label
                                                        class="input-label text-capitalize d-flex gap-1 align-items-center mb-0">
                                                        {{ translate($key) }}


                                                             @if ($item == 'deliveryman_new_order')
                                                                        <span class="tio-info text-gray1 fs-16"
                                                                            data-toggle="tooltip" data-placement="right"
                                                                            data-original-title="{{translate('{userName}_,_{orderId}_,_{restaurantName}_are_supported ')}}">
                                                                        </span>
                                                                    @else
                                                                     ({{ translate('messages.Default') }})
                                                                @endif




                                                    </label>

                                                    <label
                                                        class="switch--custom-label toggle-switch toggle-switch-sm d-inline-flex checked">
                                                        <input type="checkbox" name="{{ $item }}_status"
                                                            value="1" id="{{ $item }}_status"
                                                            {{ data_get($notificationMessage, "$item.status") == 1 ? 'checked' : '' }}
                                                            data-id="{{ $item }}_status" data-type="toggle"
                                                            data-image-on="{{ dynamicAsset('assets/admin/img/modal/pending-order-on.png') }}"
                                                            data-image-off="{{ dynamicAsset('assets/admin/img/modal/pending-order-off.png') }}"
                                                            data-title-on="{{ translate('By Turning ON Notification Message For') }} <strong>{{ translate($item) }}</strong>"
                                                            data-title-off="{{ translate('By Turning OFF Notification Message For ') }} <strong>{{ translate($item) }}</strong>"
                                                            data-text-on = "<p>{{ translate($user_type . ' will receive a proper notification message for this event') }}</p>";
                                                            data-text-off = "<p>{{ translate($user_type . ' will not receive any notification message for this event') }}</p>";
                                                            class="toggle-switch-input dynamic-checkbox-toggle">
                                                        <span class="toggle-switch-label text">
                                                            <span class="toggle-switch-indicator"></span>
                                                        </span>

                                                    </label>
                                                </div>
                                                <textarea name="{{ $item }}[]" class="form-control"
                                                    placeholder="{{ translate('Ex :') }} {{  translate($key) }}">{{ data_get($notificationMessage, $item)?->getRawOriginal('message')  }}</textarea>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            @if ($language)
                                @foreach ($language as $lang)
                                <div class="lang_form d-none" id="{{ $lang }}-form">
                                    <div class="row g-3">
                                        <input type="hidden" name="lang[]" value="{{ $lang }}">
                                                @foreach ($messageKey as $key => $item)
                                                    @if($item == 'cart_abandon') @continue @endif

                                                    <?php
            $translate = [];
            $translationData = data_get($notificationMessage, "$item.translations", []);
            if (count($translationData)) {

                foreach ($translationData as $t) {
                    if ($t['locale'] == $lang && $t['key'] == $item) {
                        $translate[$lang]['message'] = $t['value'];
                    }
                }
            }
                                                    ?>
                                                <div class="col-lg-6">
                                                    <div class="form-group mb-0">
                                                        <div class="d-flex flex-wrap justify-content-between mb-2">
                                                            <label
                                                                class="input-label text-capitalize d-flex gap-1 align-items-center mb-0">
                                                               {{  translate($key) }}

                                                                @if ($item == 'deliveryman_new_order')
                                                                        <span class="tio-info text-gray1 fs-16"
                                                                            data-toggle="tooltip" data-placement="right"
                                                                            data-original-title="{{ translate('Multi_Language_Not_Available') }}">
                                                                        </span>
                                                                    @else
                                                                    ({{ $lang }})
                                                                @endif

                                                            </label>

                                                        </div>
                                                        <textarea name="{{ $item }}[]" class="form-control"
                                                            placeholder="{{ translate('Ex :') }} {{  translate($key) }}">{!! isset($translate) && isset($translate[$lang]) ? $translate[$lang]['message'] : '' !!}</textarea>
                                                    </div>
                                                </div>
                                                @endforeach
                                            </div>
                                        </div>
                                @endforeach
                            @endif
                        </div>
                        @if ($user_type == 'user')
                            @php($item = 'cart_abandon')
                            <div class="bg-light rounded p-12 p-xxl-20">
                            <div class="mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                                <div>
                                    <h3 class="mb-1">{{ translate('messages.Behaviour-Based Notification Messages (Customer)') }}</h3>
                                    <p class="fs-12 mb-0">{{ translate('messages.Configure messages sent automatically when customer behaviour matches predefined conditions.') }}</p>
                                </div>
                                @if ($user_type == 'user' && data_get($notificationMessage, "$item.status") == 1)
                                    <div class="text-right">
                                        <button type="button" class="btn btn--primary" data-toggle="modal" data-target="#cartAbandonDependencyModal">{{ translate('messages.Check_Dependencies') }}</button>
                                    </div>
                                @endif
                            </div>

                            <div class="d-flex gap-2 fs-12 text-dark px-3 py-2 rounded bg-warning mb-20" style="--bs-bg-opacity: 0.1;">
                                <span class="text-warning lh-1 fs-14">
                                    <i class="tio-info"></i>
                                </span>
                                <span>
                                    {{ translate('To automatically send this push notification to customers at the specified time, ensure the Cron job is properly configured on the server.') }}
                                </span>
                            </div>
                            
                            <div class="lang_form default-form" id="default-form1">
                                <div class="d-flex gap-2 justify-content-between align-items-center mb-20">
                                    <label
                                        class="input-label text-capitalize d-flex flex-column gap-1 mb-0">
                                        <div class="fs-16 font-semibold text-dark">
                                            {{ translate('messages.Cart Abandon Reminder') }}
                                        </div>
                                        <div class="fs-12">{{ translate('messages.Configure the messages of automatic reminder for customers when items remain in their cart after a set time') }}</div>
                                    </label>

                                    <label
                                        class="switch--custom-label toggle-switch toggle-switch-sm d-inline-flex checked">
                                        <input type="checkbox" name="{{ $item }}_status"
                                            value="1" id="{{ $item }}_status"
                                            {{ data_get($notificationMessage, "$item.status") == 1 ? 'checked' : '' }}
                                            data-id="{{ $item }}_status" data-type="toggle"
                                            data-image-on="{{ dynamicAsset('assets/admin/img/modal/pending-order-on.png') }}"
                                            data-image-off="{{ dynamicAsset('assets/admin/img/modal/pending-order-off.png') }}"
                                            data-title-on="{{ translate('By Turning ON Notification Message For') }} <strong>{{ translate('messages.Cart Abandon Reminder') }}</strong>"
                                            data-title-off="{{ translate('By Turning OFF Notification Message For ') }} <strong>{{ translate('messages.Cart Abandon Reminder') }}</strong>"
                                            data-text-on="<p>{{ translate($user_type . ' will receive a proper notification message for this event') }}</p>"
                                            data-text-off="<p>{{ translate($user_type . ' will not receive any notification message for this event') }}</p>"
                                            class="toggle-switch-input dynamic-checkbox-toggle">
                                        <span class="toggle-switch-label text">
                                            <span class="toggle-switch-indicator"></span>
                                        </span>
                                    </label>
                                </div>
                                <div class="row g-3">
                                    <div class="col-lg-6">
                                        <label for="" class="input-label text-capitalize">
                                            {{ translate('messages.After Add to Cart – Checkout Not Placed Message') }} ({{ translate('messages.Default') }})
                                        </label>
                                        <textarea name="{{ $item }}[]" id="" rows="4" class="form-control" placeholder="{{ translate('messages.Type Checkout Not Placed Message') }}" data-maxlength="250">{{ data_get($notificationMessage, $item)?->getRawOriginal('message') }}</textarea>
                                        <div class="text-right text-counting color-A7A7A7 d-block mt-1">0/250</div>
                                    </div>
                                    <div class="col-lg-6">
                                        <label for="" class="input-label text-capitalize">
                                            {{ translate('messages.Send reminder after') }}
                                        </label>
                                        <div class="custom-group-btn form-control single">
                                            <div class="item flex-sm-grow-1">
                                                <input type="number" name="cart_reminder_after_time" class="form-control border-0 h-100" id="" value="{{ $cart_reminder_after_time }}" min="1" placeholder="{{ translate('messages.Ex: 3') }}">
                                            </div>
                                            <div class="item flex-shrink-0">
                                                <select name="cart_reminder_after" class="custom-select w-90px border-0">
                                                    <option value="min" {{ $cart_reminder_after == 'min' ? 'selected' : '' }}>{{ translate('messages.Minute') }}</option>
                                                    <option value="hour" {{ $cart_reminder_after == 'hour' ? 'selected' : '' }}>{{ translate('messages.Hour') }}</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            @if ($language)
                                @foreach ($language as $lang)
                                    <?php
        $translate = [];
        $translationData = data_get($notificationMessage, "$item.translations", []);
        if (count($translationData)) {
            foreach ($translationData as $t) {
                if ($t['locale'] == $lang && $t['key'] == $item) {
                    $translate[$lang]['message'] = $t['value'];
                }
            }
        }
                                    ?>
                                    <div class="lang_form d-none" id="{{ $lang }}-form1">
                                        <div class="d-flex gap-2 justify-content-between align-items-center mb-20">
                                            <label
                                                class="input-label text-capitalize d-flex flex-column gap-1 mb-0">
                                                <div class="fs-16 font-semibold text-dark">
                                                    {{ translate('messages.Cart Abandon Reminder') }} ({{ strtoupper($lang) }})
                                                </div>
                                                <div class="fs-12">{{ translate('messages.Configure the messages of automatic reminder for customers when items remain in their cart after a set time') }}</div>
                                            </label>
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-lg-6">
                                                <label for="" class="input-label text-capitalize">
                                                    {{ translate('messages.After Add to Cart – Checkout Not Placed Message') }} ({{ strtoupper($lang) }})
                                                </label>
                                                <textarea name="{{ $item }}[]" id="" rows="1" class="form-control" placeholder="{{ translate('messages.Type Checkout Not Placed Message') }}" data-maxlength="250">{!! isset($translate[$lang]) ? $translate[$lang]['message'] : '' !!}</textarea>
                                                <div class="text-right text-counting color-A7A7A7 d-block mt-1">0/250</div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="footer-sticky mt-2">
                <div class="container-fluid">
                    <div class="d-flex flex-wrap gap-3 justify-content-center py-3">
                        <button type="reset" id="reset_btn"
                            class="btn btn--secondary min-w-120">{{ translate('messages.Reset') }} </button>
                        <button type="submit" class="btn btn--primary">
                            <i class="tio-save"></i>
                            {{ translate('Save_Information') }}
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <!-- Firebase Modal -->
        <div class="modal fade" id="insertVariableModal">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal">
                            <span aria-hidden="true" class="tio-clear"></span>
                        </button>
                    </div>
                    <div class="modal-body text-center pt-0">
                        <h3 class="mb-2">{{ translate('messages.Insert Variable') }}</h3>
                        <p class="mb-4">
                            {{ translate('messages.Provide a pop-up title and subtitle for the push notification.') }}</p>
                        <div class="d-flex flex-column gap-2 max-h-250 overflow-y-auto">



                            <div
                                class="bg-light rounded p-2 d-flex justify-content-between align-items-center overflow-wrap-anywhere text-dark">
                                <span class="fs-13 font-semibold">{{ translate('User') }}</span>
                                <span class="fs-12">{{ translate('{userName}') }}</span>
                            </div>
                            {{-- Item --}}
                            <div
                                class="bg-light rounded p-2 d-flex justify-content-between align-items-center overflow-wrap-anywhere text-dark">
                                <span class="fs-13 font-semibold">{{ translate('restaurant') }}</span>
                                <span class="fs-12">{{ translate('{restaurantName}') }}</span>
                            </div>
                            {{-- Item --}}
                            <div
                                class="bg-light rounded p-2 d-flex justify-content-between align-items-center overflow-wrap-anywhere text-dark">
                                <span class="fs-13 font-semibold">{{ translate('OrderId') }}</span>
                                <span class="fs-12">{{ translate('{orderId}') }}</span>
                            </div>
                            <div
                                class="bg-light rounded p-2 d-flex justify-content-between align-items-center overflow-wrap-anywhere text-dark">
                                <span class="fs-13 font-semibold">{{ translate('Delivery Man Name') }}</span>
                                <span class="fs-12">{{ translate('{deliveryManName}') }}</span>
                            </div>
                            <div
                                class="bg-light rounded p-2 d-flex justify-content-between align-items-center overflow-wrap-anywhere text-dark">
                                <span class="fs-13 font-semibold">{{ translate('OTP') }}</span>
                                <span class="fs-12">{{ translate('{otp}') }}</span>
                            </div>

                            <div
                                class="bg-light rounded p-2 d-flex justify-content-between align-items-center overflow-wrap-anywhere text-dark">
                                <span class="fs-13 font-semibold">{{ translate('Token Number') }}</span>
                                <span class="fs-12">{{ translate('{tokenNumber}') }}</span>
                            </div>
                            <div
                                class="bg-light rounded p-2 d-flex justify-content-between align-items-center overflow-wrap-anywhere text-dark">
                                <span class="fs-13 font-semibold">{{ translate('Table Number') }}</span>
                                <span class="fs-12">{{ translate('{tableNumber}') }}</span>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- Guidline Offcanvas Btn -->
        <div class="d-flexgap-2 w-40px gap-2 bg-white position-fixed end-0 translate-middle-y pointer view-guideline-btn flex-column pt-3 px-2 justify-content-center offcanvas-trigger"
            data-toggle="offcanvas" data-target="#offcanvasSetupGuide">
            <span class="arrow bg-primary py-1 px-2 text-white rounded fs-12"><i class="tio-share-vs"></i></span>
            <span class="view-guideline-btn-text text-dark font-semibold pb-2 text-nowrap">
                {{ translate('View_Guideline') }}
            </span>
        </div>

        <!-- Guidline Offcanvas -->
        <div id="offcanvasOverlay" class="offcanvas-overlay"></div>
        <div class="custom-offcanvas" tabindex="-1" id="offcanvasSetupGuide" aria-labelledby="offcanvasSetupGuideLabel"
            style="--offcanvas-width: 500px">
            <div>
                <div
                    class="custom-offcanvas-header bg--secondary d-flex justify-content-between align-items-center px-3 py-3">
                    <h3 class="mb-0">{{ translate('messages.Notification Messages Guideline') }}</h3>
                    <button type="button"
                        class="btn-close w-25px h-25px border rounded-circle d-center bg--secondary offcanvas-close fz-15px p-0"
                        aria-label="Close">&times;</button>
                </div>
                <div class="custom-offcanvas-body offcanvas-height-100 py-3 px-md-4 px-3">
                    <div class="py-3 px-3 bg-light rounded mb-3 mb-sm-20">
                        <div class="d-flex gap-2 align-items-center justify-content-between overflow-hidden">
                            <button
                                class="btn-collapse d-flex gap-2 align-items-center bg-transparent border-0 p-0 collapsed"
                                type="button" data-toggle="collapse" data-target="#firebase_console"
                                aria-expanded="true">
                                <div
                                    class="btn-collapse-icon w-35px h-35px bg-white d-flex align-items-center justify-content-center border icon-btn rounded-circle fs-12 lh-1">
                                    <i class="tio-down-ui"></i>
                                </div>
                                <span
                                    class="font-semibold text-left fs-14 text-title">{{ translate('Firebase Console') }}</span>
                            </button>
                            <a href="{{ route('admin.business-settings.fcm-index') }}"
                                class="text-info text-underline fs-12 text-nowrap">{{ translate('messages.Let’s Setup') }}</a>
                        </div>
                        <div class="collapse mt-3" id="firebase_console">
                            <div class="card card-body">
                                <div class="">
                                    <h5 class="mb-2">{{ translate('Go to Firebase Console') }}</h5>
                                    <p class="fs-12 mb-1">
                                        {{ translate('messages.Firebase Console') }}
                                    </p>
                                    <ul class="mb-0 fs-12">
                                        <li>
                                            {{ translate('messages.Open your web browser and go to the Firebase Console') }}
                                            <a href="#"
                                                class="text-info">({{ translate('messages.https://console.firebase.google.com/') }})</a>.
                                        </li>
                                        <li>
                                            {{ translate('messages.Select the project for which you want to configure FCM from the Firebase Console dashboard.') }}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="py-3 px-3 bg-light rounded mb-3 mb-sm-20">
                        <div class="d-flex gap-2 align-items-center justify-content-between overflow-hidden">
                            <button
                                class="btn-collapse d-flex gap-2 align-items-center bg-transparent border-0 p-0 collapsed"
                                type="button" data-toggle="collapse" data-target="#navigate" aria-expanded="true">
                                <div
                                    class="btn-collapse-icon w-35px h-35px bg-white d-flex align-items-center justify-content-center border icon-btn rounded-circle fs-12 lh-1">
                                    <i class="tio-down-ui"></i>
                                </div>
                                <span class="font-semibold text-left fs-14 text-title">{{ translate('Navigate') }}</span>
                            </button>
                            {{-- <a href="#"
                                class="text-info text-underline fs-12 text-nowrap">{{ translate('messages.Let’s Setup') }}</a> --}}
                        </div>
                        <div class="collapse mt-3" id="navigate">
                            <div class="card card-body">
                                <div class="">
                                    <h5 class="mb-2">{{ translate('Navigate to Project Settings') }}</h5>
                                    <ul class="mb-0 fs-12">
                                        <li>
                                            {{ translate('messages.In the left-hand menu click on the Settings gear icon and then select Project settings from the dropdown.') }}
                                        </li>
                                        <li>
                                            {{ translate('messages.In the Project settings page click on the Cloud Messaging tab from the top menu.') }}
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="py-3 px-3 bg-light rounded mb-3 mb-sm-20">
                        <div class="d-flex gap-2 align-items-center justify-content-between overflow-hidden">
                            <button
                                class="btn-collapse d-flex gap-2 align-items-center bg-transparent border-0 p-0 collapsed"
                                type="button" data-toggle="collapse" data-target="#obtain_info" aria-expanded="true">
                                <div
                                    class="btn-collapse-icon w-35px h-35px bg-white d-flex align-items-center justify-content-center border icon-btn rounded-circle fs-12 lh-1">
                                    <i class="tio-down-ui"></i>
                                </div>
                                <span
                                    class="font-semibold text-left fs-14 text-title">{{ translate('Obtain All The Information Asked!') }}</span>
                            </button>
                            {{-- <a href="#"
                                class="text-info text-underline fs-12 text-nowrap">{{ translate('messages.Let’s Setup') }}</a> --}}
                        </div>
                        <div class="collapse mt-3" id="obtain_info">
                            <div class="card card-body">
                                <div class="">
                                    <h5 class="mb-2">{{ translate('Go to Firebase Console') }}</h5>
                                    <ul class="mb-0 fs-12">
                                        <li>
                                            {{ translate('messages.In the Firebase Project settings page click on the General tab from the top menu.') }}
                                        </li>
                                        <li>
                                            {{ translate('messages.Under the Your apps section click on the Web app for which you want to configure FCM.') }}
                                        </li>
                                        <li>
                                            {{ translate('messages.Then Obtain API Key FCM Project ID Auth DomainStorage Bucket Messaging Sender ID.') }}
                                        </li>
                                    </ul>
                                    <p class="fs-12 mb-0 mt-4">
                                        {{ translate('messages.Note: Please make sure to use the obtained information securely and in accordance with Firebase and FCM documentation terms of service and any applicable laws and regulations') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="py-3 px-3 bg-light rounded mb-3 mb-sm-20">
                        <div class="d-flex gap-2 align-items-center justify-content-between overflow-hidden">
                            <button
                                class="btn-collapse d-flex gap-2 align-items-center bg-transparent border-0 p-0 collapsed"
                                type="button" data-toggle="collapse" data-target="#notification_body"
                                aria-expanded="true">
                                <div
                                    class="btn-collapse-icon w-35px h-35px bg-white d-flex align-items-center justify-content-center border icon-btn rounded-circle fs-12 lh-1">
                                    <i class="tio-down-ui"></i>
                                </div>
                                <span
                                    class="font-semibold text-left fs-14 text-title">{{ translate('Notification Body') }}</span>
                            </button>
                            {{-- <a href="#"
                                class="text-info text-underline fs-12 text-nowrap">{{ translate('messages.Let’s Setup') }}</a> --}}
                        </div>
                        <div class="collapse mt-3" id="notification_body">
                            <div class="card card-body">
                                <div class="">
                                    <h5 class="mb-2">{{ translate('Write a message in the Notification Body') }}</h5>
                                    <p class="fs-12 mb-1">
                                        {{ translate('messages.You can add your message using placeholders to include dynamic content. Here are some examples of placeholders you can use:') }}
                                    </p>
                                    <ul class="mb-0 fs-12">
                                        <li>
                                            {{ translate('messages.{userName}: The name of the user.') }}
                                        </li>
                                        <li>
                                            {{ translate('messages.{restaurantName}: The name of the restaurant.') }}
                                        </li>
                                        <li>
                                            {{ translate('messages.{orderId}: The order id') }}
                                        </li>
                                        <li>
                                            {{ translate('messages.{deliveryManName}: The name of the delivery man.') }}
                                        </li>
                                        <li>
                                            {{ translate('messages.{otp}: The OTP code for order verification') }}
                                        </li>
                                        <li>
                                            {{ translate('messages.{tokenNumber}: The Token Number for dine-in') }}
                                        </li>
                                        <li>
                                            {{ translate('messages.{tableNumber}: The Table Number for dine-in') }}
                                        </li>



                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if ($user_type == 'user')
            <div class="modal" id="cartAbandonDependencyModal" tabindex="-1" role="dialog">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title text-center">{{ translate('messages.Cron_Command_for_Cart_Abandon_Reminder') }}</h5>
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <span class="text--base">
                                    {{ translate('Set up the following cron job on your server to automatically send cart abandon reminder notifications at the configured interval.') }}
                                </span>
                            </div>
                            <div class="mb-3">
                                <span class="text--base">
                                    {{ translate('This command runs the cart abandon reminder process, which checks eligible carts and sends the configured push notification to customers.') }}
                                </span>
                            </div>
                            <label class="form-label text-capitalize">
                                {{ translate('messages.Cart_Abandon_Reminder_Cron_Command') }}
                            </label>
                            <div class="input--group input-group">
                                <input type="text" value="{{ $cart_abandon_command }}" class="form-control pr-10" id="cartAbandonCommand" readonly>
                                <button type="button" class="btn btn-primary cartAbandonCommand">{{ translate('Copy') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
    {{-- Notification Messages Page end --}}


@endsection

@push('script_2')
    @php($cart_abandon_dependency = session('cart_abandon_dependency'))
    <script>
        "use strict";

        $(document).on('ready', function() {
            @if (isset($cart_abandon_dependency) && $cart_abandon_dependency && $user_type == 'user')
                $('#cartAbandonDependencyModal').modal('show');
            @endif

            $(document).on('click', '.cartAbandonCommand', function (e) {
                e.preventDefault();
                const commandElement = document.getElementById('cartAbandonCommand');
                commandElement.select();
                commandElement.setSelectionRange(0, 99999); // For mobile devices
                
                try {
                    document.execCommand("copy");
                    toastr.success('{{ translate('Copied to clipboard!') }}');
                } catch (err) {
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(commandElement.value).then(function() {
                            toastr.success('{{ translate('Copied to clipboard!') }}');
                        });
                    }
                }
            });
        });
    </script>
@endpush
