@extends('layouts.vendor.app')
@section('title',translate('messages.my_restaurant'))
@section('content')
    <div class="content container-fluid">
        @include('vendor-views.partials.business_setup_header')
        <div class="card card-body mb-3">
            <div class="row g-3 justify-content-between">
                <div class="col-xxl-9 col-lg-8 col-md-7 col-sm-6">
                    <div class="">
                        <h3 class="mb-1">{{ translate('messages.Restaurant_Availability') }}</h3>
                        <p class="fs-12 mb-0">
                            关闭后顾客端会显示「商家休息中」、暂时无法下单；但店铺仍然可见、不会从列表消失。随时可重新开业。
                        </p>
                    </div>
                </div>
                <div class="col-xxl-3 col-lg-4 col-md-5 col-sm-6">
                    <div class="maintainance-mode-toggle-bar rounded d-flex justify-content-between border align-items-center w-100">
                        <span class="text-dark">{{ translate('messages.Active_Status') }}</span>

                        <label class="toggle-switch toggle-switch-sm">
                            <input type="checkbox" id="" class="status toggle-switch-input restaurant-open-status" {{ !$shop->nezha_temp_closed ? 'checked' : '' }}>
                            <span class="toggle-switch-label text">
                                <span class="toggle-switch-indicator"></span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <div class="card card-from-sm mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h3 class="mb-1"> {{translate('Restaurant_Details')}} </h3>
                    <p class="fs-12 mb-0">{{translate('Created_at')}} {{ \App\CentralLogics\Helpers::time_date_format($shop->created_at) }}</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{route('vendor.shop.brand')}}" class="btn btn--primary d-flex gap-2 align-items-baseline" style="color:#fff;">
                        <i class="tio-photo"></i> {{translate('门店形象')}}
                    </a>
                    <a href="{{route('vendor.shop.edit')}}" class="btn btn-outline-primary d-flex gap-2 align-items-baseline">
                        <i class="tio-open-in-new"></i> {{translate('Edit_Information')}}
                    </a>
                </div>
            </div>
            <div class="card-body">
                <!-- Banner -->
                <section class="shop-details-banner">
                    <div class="card mb-3">
                        <div class="card-body px-0 pt-0">
                            <!-- <img  class="shop-details-banner-img"
                                src="{{ $shop?->cover_photo_full_url ?? dynamicAsset('assets/admin/img/900x400/img1.jpg') }}"
                                alt="image"> -->

                            <div class="shop-details__thumb-wrap block-size-custom position-relative">
                                <img class="shop-details-banner-img w-100" style="height:100%;object-fit:cover;border-radius:8px;"
                                     src="{{ $shop?->cover_photo_full_url ?? dynamicAsset('assets/admin/img/restaurant_cover.jpg') }}" alt="cover">
                            </div>

                            <div class="shop-details-banner-content px-3 px-xxl-4 z-index-2 position-relative">
                                <div class="shop-details-banner-content-thumbnail w-100px rounded bg-white">
                                    <!-- <img class="thumbnail rounded"
                                    src="{{ $shop?->logo_full_url ?? dynamicAsset('assets/admin/img/160x160/img1.jpg') }}"
                                    alt="image"> -->
                                    <div class="shop-details__thumb-wrap2 position-relative">
                                        <img class="w-100 ratio-1" style="object-fit:cover;border-radius:8px;display:block;"
                                             src="{{ $shop?->logo_full_url ?? dynamicAsset('assets/admin/img/image-place-holder.png') }}" alt="logo">
                                    </div>
                                </div>
                                <div class="shop-details-banner-content-content">
                                    <div class="mt-sm-4 pt-sm-3 mb-4 d-none d-sm-block">
                                        <div class="d-flex align-items-center flex-wrap gap-3 justify-content-between">
                                            <div>
                                                <h2 class="h3 mb-1">{{$shop->name}}</h2>
                                                {{-- <p class="fs-12 mb-0">{{translate('Created_at')}} {{ \App\CentralLogics\Helpers::time_date_format($shop->created_at) }}</p> --}}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="shop-details-model flex-xxl-nowrap">
                                        <div class="shop-details-model-item align-items-start flex-grow-1 flex-shrink-0">
                                            <img src="{{dynamicAsset('assets/admin/new-img/icon-3.png')}}" alt="">
                                            <div class="shop-details-model-item-content fs-13">
                                                <h5 class="mb-1 text-nowrap">{{ translate('Phone') }} </h5>
                                                <div>{{$shop->phone}}</div>
                                            </div>
                                        </div>
                                        <div class="shop-details-model-item align-items-start flex-grow-1">
                                            <img src="{{dynamicAsset('assets/admin/new-img/icon-4.png')}}" alt="">
                                            <div class="shop-details-model-item-content fs-13">
                                                <h5 class="mb-1 text-nowrap"> {{ translate('Address') }} </h5>
                                                <div class="overflow-wrap-anywhere">{{ \Illuminate\Support\Str::limit($shop->address, 50) }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
                <!-- Banner -->
            </div>
        </div>
        <div class="card card-body">
            <div class="view-details-container">
                <div class="d-flex justify-content-between align-items-center gap-3">
                    <div>
                        <h3 class="mb-1">{{ translate('messages.Announcement') }}</h3>
                        <p class="mb-0 fs-12">
                            {{ translate('Enable this feature to share my announcements with customers.') }}
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <div
                            class="view-btn text-primary cursor-pointer font-semibold d-flex align-items-center gap-1">
                            <span class="text-underline">{{ translate('messages.view') }}</span>
                            <i class="tio-down-ui fs-12"></i>
                        </div>
                        <label class="toggle-switch toggle-switch-sm m-0">
                            <input type="checkbox"  name="announcement" class="toggle-switch-input update-status" data-url="{{route('vendor.business-settings.toggle-settings',[$shop->id,$shop->announcement?0:1, 'announcement'])}}" id="announcement" {{$shop->announcement?'checked':''}} >
                            <span class="toggle-switch-label text">
                                <span class="toggle-switch-indicator"></span>
                            </span>
                        </label>
                    </div>
                </div>
                <div class="view-details mt-4">
                    <form action="{{route('vendor.shop.update-message')}}" method="post">
                        @csrf
                        <div class="__bg-F8F9FC-card mb-20">
                             <label class="input-label text-capitalize d-flex gap-1 align-items-center mb-0">
                                {{ translate('Announcement_Text') }}
                                <span class="tio-info text-gray1 fs-16" data-toggle="tooltip" data-placement="right"
                                    data-original-title="{{ translate('messages.This_announcement_shown_in_the_user_app/web') }}">
                                </span>
                            </label>
                            <textarea name="announcement_message" id="" maxlength="254" class="form-control h-100px" placeholder="{{ translate('messages.ex_:_ABC_Company') }}">{{ $shop->announcement_message??'' }}</textarea>
                        </div>
                        <div class="btn--container justify-content-end">
                            <button type="reset" class="btn btn--reset min-w-120">{{translate('messages.reset')}}</button>
                            <button type="submit" class="btn btn--primary min-w-120">{{translate('messages.publish')}}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>


    </div>


@endsection


@push('script_2')
    <script>
        "use strict";
        $('.update-status').on('click', function (){
            let route = $(this).data('url');
            let code = $(this).data('code');
            updateStatus(route, code);
        })

        function updateStatus(route, code) {
            $.get({
                url: route,
                data: {
                    code: code,
                },
                success: function (data) {
                    if (data.error == 403) {
                        toastr.error('{{translate('status_can_not_be_updated')}}');
                        location.reload();
                    }
                    else{
                        toastr.success('{{translate('messages.Restaurant settings updated!')}}');
                    }
                }
            });
        }

        $(document).on('click', '.restaurant-open-status', function (event) {
            Swal.fire({
                title: '{{ $shop->nezha_temp_closed ? '确认重新开业？' : '确认暂停营业（打烊）？' }}',
                text: '{{ $shop->nezha_temp_closed ? '开业后顾客可以正常下单。' : '顾客端会显示「商家休息中」、暂时无法下单；店铺仍然可见、不会从列表消失。随时可重新开业。' }}',
                type: 'warning',
                showCancelButton: true,
                cancelButtonColor: 'default',
                confirmButtonColor: '#377dff',
                cancelButtonText: '{{translate('messages.no')}}',
                confirmButtonText: '{{translate('messages.yes')}}',
                reverseButtons: true
            }).then((result) => {
                if (result.value) {
                    $.get({
                        url: '{{route('vendor.business-settings.update-active-status')}}',
                        contentType: false,
                        processData: false,
                        beforeSend: function () {
                            $('#loading').show();
                        },
                        success: function (data) {
                            toastr.success(data.message);
                        },
                        complete: function () {
                            $('#loading').hide();
                            location.reload();
                        },
                    });
                } else {
                    event.checked = !event.checked;
                }
            })
        });
    </script>
@endpush
