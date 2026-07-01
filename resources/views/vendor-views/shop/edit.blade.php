
@extends('layouts.vendor.app')
@section('title',translate('messages.edit_restaurant'))
@push('css_or_js')
    <!-- Custom styles for this page -->
    <link href="{{dynamicAsset('assets/admin')}}/vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
     <!-- Custom styles for this page -->
     <link href="{{dynamicAsset('assets/admin/css/croppie.css')}}" rel="stylesheet">
     <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush
@section('content')
    <div class="content container-fluid">
        @include('vendor-views.partials.business_setup_header')
        @php($language=\App\Models\BusinessSetting::where('key','language')->first())
        @php($language = $language->value ?? null)
        @php($default_lang = str_replace('_', '-', app()->getLocale()))
        <form action="{{route('vendor.shop.update')}}" method="post"
        enctype="multipart/form-data">
        @csrf
            <div class="card mb-20">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div>
                        <h3 class="mb-1">{{ translate('messages.Edit_Restaurant') }}</h3>
                        <p class="fs-12 mb-0">{{ translate('messages.Here you setup your all business information.') }}</p>
                    </div>
                    <a href="{{route('vendor.shop.view')}}" class="text-primary font-semibold d-flex gap-1 align-items-center">
                        <i class="tio-arrow-backward"></i>
                        {{ translate('messages.Back to Restaurant Settings') }}
                    </a>
                </div>
                <div class="card-body">
                    <div class="card card-body mb-20">
                        <div class="mb-20">
                            <h4 class="mb-1">{{ translate('messages.Restaurant_Name') }}</h4>
                            <p class="fs-12 mb-0">{{ translate('messages.Here you setup your all business information.') }}</p>
                        </div>
                        <div class="__bg-F8F9FC-card mb-20">
                            @if($language)
                            <div class="js-nav-scroller hs-nav-scroller-horizontal">
                                <ul class="nav nav-tabs mb-4">
                                    <li class="nav-item">
                                        <a class="nav-link lang_link active"
                                        href="#"
                                        id="default-link">{{ translate('Default') }}</a>
                                    </li>
                                    @foreach (json_decode($language) as $lang)
                                        <li class="nav-item">
                                            <a class="nav-link lang_link"
                                                href="#"
                                                id="{{ $lang }}-link">{{ \App\CentralLogics\Helpers::get_language_name($lang) . '(' . strtoupper($lang) . ')' }}</a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                            @endif
                            <div class="row g-3">
                                <div class="col-md-6">
                                        <div class="form-group mb-0 lang_form" id="default-form">
                                                <label class="input-label d-flex gap-1" for="exampleFormControlInput1">
                                                    {{ translate('messages.restaurant') }}
                                                    {{ translate('messages.name') }} ({{translate('messages.default')}})
                                                    <span class="text-danger">*</span>
                                                </label>
                                            <input type="text" name="name[]" class="form-control" placeholder="{{ translate('messages.restaurant_name') }}" maxlength="191" value="{{$shop?->getRawOriginal('name')}}"  >
                                        </div>
                                        @if ($language)
                                            <input type="hidden" name="lang[]" value="default">
                                            @foreach(json_decode($language) as $lang)
                                                <?php
                                                    if(count($shop['translations'])){
                                                        $translate = [];
                                                        foreach($shop['translations'] as $t)
                                                        {
                                                            if($t->locale == $lang && $t->key=="name"){
                                                                $translate[$lang]['name'] = $t->value;
                                                            }

                                                        }
                                                    }
                                                ?>
                                                <div class="form-group mb-0 d-none lang_form" id="{{$lang}}-form">
                                                    <label class="input-label d-flex gap-1" for="exampleFormControlInput1">
                                                        {{ translate('messages.restaurant_name') }} ({{strtoupper($lang)}})
                                                        <span class="text-danger">*</span>
                                                    </label>
                                                    <input type="text" name="name[]" class="form-control" placeholder="{{ translate('messages.restaurant_name') }}" maxlength="191" value="{{$translate[$lang]['name']??''}}"  >
                                                </div>
                                                <input type="hidden" name="lang[]" value="{{$lang}}">
                                            @endforeach
                                        @endif
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-0  lang_form default-form"  >
                                        <label for="address" class="input-label d-flex gap-1">
                                            {{ translate('messages.restaurant_address')}} ({{translate('messages.default')}})
                                            <span class="text-danger">*</span>
                                        </label>
                                        <textarea type="text" rows="1" name="address[]" value="" placeholder="{{ translate('Ex : House-45, Road-08, Sector-12, Mirupara, Test City') }}"
                                            class="form-control" id="address">{{$shop->address}}</textarea>
                                    </div>
                                    @if ($language)
                                    @foreach(json_decode($language) as $lang)
                                        <?php
                                            if(count($shop['translations'])){
                                                $translate = [];
                                                foreach($shop['translations'] as $t)
                                                {
                                                    if($t->locale == $lang && $t->key=="address"){
                                                        $translate[$lang]['address'] = $t->value;
                                                    }

                                                }
                                            }
                                        ?>
                                        <div class="form-group mb-0  d-none lang_form" id="{{$lang}}-form1">
                                                <label class="input-label d-flex gap-1" for="exampleFormControlInput1">
                                                    {{ translate('messages.restaurant_address') }} ({{strtoupper($lang)}})
                                                    <span class="text-danger">*</span>
                                                </label>
                                            <textarea type="text" rows="1" name="address[]" value="" placeholder="{{ translate('Ex : House-45, Road-08, Sector-12, Mirupara, Test City') }}"
                                                class="form-control" id="address" >{{  $translate[$lang]['address'] ?? ''}}</textarea>
                                        </div>
                                    @endforeach
                                @endif
                                </div>
                            </div>
                        </div>
                        <div class="form-group mb-0 pt-lg-1">
                            <label for="contact" class="input-label d-flex gap-1">{{translate('messages.contact_number')}}<span class="text-danger">*</span></label>
                            <input type="tel" name="contact" value="{{$shop->phone}}" placeholder="{{ translate('Ex : +880 123456789') }}" class="form-control h--45px" id="contact"
                                    required>
                        </div>
                    </div>
                    <div class="card card-body mb-20">
                        <div class="mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <h4 class="mb-1">{{ translate('messages.Logo_&_Cover') }}</h4>
                                <p class="fs-12 mb-0">{{ translate('店铺 Logo 和封面已统一在「门店形象」页管理，这里只看不改。') }}</p>
                            </div>
                            <a href="{{ route('vendor.shop.brand') }}" class="btn btn--primary" style="color:#fff;">
                                <i class="tio-photo"></i> {{ translate('去门店形象页') }}
                            </a>
                        </div>
                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="p-12 global-bg-box rounded text-center h-100">
                                    <p class="fs-12 gray-dark mb-2">{{ translate('店铺 Logo') }}</p>
                                    <img src="{{ $shop?->logo_full_url ?? dynamicAsset('assets/admin/img/image-place-holder.png') }}" alt="logo" style="width:110px;height:110px;object-fit:cover;border-radius:10px;border:1px solid #e7eaf3;">
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="p-12 global-bg-box rounded text-center h-100">
                                    <p class="fs-12 gray-dark mb-2">{{ translate('店铺封面') }}</p>
                                    <img src="{{ $shop?->cover_photo_full_url ?? dynamicAsset('assets/admin/img/restaurant_cover.jpg') }}" alt="cover" style="width:100%;max-width:300px;height:88px;object-fit:cover;border-radius:10px;border:1px solid #e7eaf3;">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="btn--container justify-content-end">
                        <a class="btn btn--reset min-w-120" href="{{route('vendor.shop.view')}}">{{translate('messages.cancel')}}</a>
                        <button type="submit" class="btn btn--primary min-w-120" id="btn_update">{{translate('messages.update')}}</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('script_2')
@endpush
