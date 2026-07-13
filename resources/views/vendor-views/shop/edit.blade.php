
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
                        <div class="mb-3">
                            <h4 class="mb-1">{{ translate('门店地图定位') }}</h4>
                            <p class="fs-12 mb-0">{{ translate('拖动地图上的图钉，精确对准你的门店位置。这个定位决定顾客看到的门店位置，以及配送时呼叫 Yandex 的取车点。图钉必须留在你的配送区（蓝色区域）内。') }}</p>
                        </div>
                        <input id="nz-map-search" class="form-control mb-2" type="text" placeholder="{{ translate('搜索地点，例如街道名或地标') }}" autocomplete="off">
                        <div id="nz-map" style="height:320px;width:100%;border-radius:10px;overflow:hidden;background:#eef1f6;"></div>
                        <div id="nz-out-of-zone" class="mt-2 px-3 py-2 rounded" style="display:none;background:#FDECEE;color:#b3261e;font-size:13px;">
                            ⚠️ {{ translate('图钉超出了你的配送区范围，请把它拖回蓝色区域内再保存。') }}
                        </div>
                        <div class="d-flex align-items-center gap-2 mt-2 fs-12 text-muted">
                            <span>{{ translate('当前坐标') }}:</span>
                            <span id="nz-latlng-text">{{ $shop->latitude }}, {{ $shop->longitude }}</span>
                        </div>
                        <input type="hidden" name="latitude" id="nz-latitude" value="{{ $shop->latitude }}">
                        <input type="hidden" name="longitude" id="nz-longitude" value="{{ $shop->longitude }}">
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
    <script>
        window.__nzZonePolygon = {!! json_encode($zonePolygon ?? []) !!};
        window.__nzStart = {
            lat: parseFloat("{{ $shop->latitude }}") || {{ isset($zoneCenter['lat']) ? $zoneCenter['lat'] : 40.1872 }},
            lng: parseFloat("{{ $shop->longitude }}") || {{ isset($zoneCenter['lng']) ? $zoneCenter['lng'] : 44.5152 }}
        };
        function nzInitMap() {
            var poly = (window.__nzZonePolygon || []).map(function (p) { return { lat: parseFloat(p.lat), lng: parseFloat(p.lng) }; });
            var start = window.__nzStart;
            var mapEl = document.getElementById('nz-map');
            if (!mapEl) { return; }
            var map = new google.maps.Map(mapEl, { center: start, zoom: 16, mapTypeControl: false, streetViewControl: false, fullscreenControl: false });
            var zone = null;
            if (poly.length > 2) {
                zone = new google.maps.Polygon({ paths: poly, strokeColor: '#2f6fed', strokeOpacity: 0.8, strokeWeight: 2, fillColor: '#2f6fed', fillOpacity: 0.08, map: map });
            }
            var marker = new google.maps.Marker({ position: start, map: map, draggable: true });
            var latEl = document.getElementById('nz-latitude');
            var lngEl = document.getElementById('nz-longitude');
            var txtEl = document.getElementById('nz-latlng-text');
            var warnEl = document.getElementById('nz-out-of-zone');
            var btn = document.getElementById('btn_update');
            var moved = false;
            function inZone(pos) {
                if (!zone) { return true; }
                return google.maps.geometry.poly.containsLocation(new google.maps.LatLng(pos.lat, pos.lng), zone);
            }
            function apply(pos, userMoved) {
                if (userMoved) { moved = true; }
                latEl.value = pos.lat.toFixed(6);
                lngEl.value = pos.lng.toFixed(6);
                txtEl.textContent = pos.lat.toFixed(6) + ', ' + pos.lng.toFixed(6);
                var ok = inZone(pos);
                warnEl.style.display = ok ? 'none' : 'block';
                // 只有商家真的挪动过图钉且挪出区外, 才禁用保存(防旧数据本身越界时连改名都存不了)
                var block = moved && !ok;
                if (btn) { btn.disabled = block; btn.style.opacity = block ? '0.5' : ''; }
            }
            marker.addListener('dragend', function (e) { apply({ lat: e.latLng.lat(), lng: e.latLng.lng() }, true); });
            map.addListener('click', function (e) { marker.setPosition(e.latLng); apply({ lat: e.latLng.lat(), lng: e.latLng.lng() }, true); });
            apply(start, false);
            var input = document.getElementById('nz-map-search');
            if (input && google.maps.places) {
                var ac = new google.maps.places.Autocomplete(input, { fields: ['geometry'] });
                ac.addListener('place_changed', function () {
                    var pl = ac.getPlace();
                    if (!pl.geometry) { return; }
                    var loc = pl.geometry.location;
                    var pos = { lat: loc.lat(), lng: loc.lng() };
                    map.setCenter(pos); map.setZoom(17); marker.setPosition(pos);
                    apply(pos, true);
                });
            }
        }
    </script>
    <script src="https://maps.googleapis.com/maps/api/js?key={{ \App\CentralLogics\Helpers::get_business_settings('map_api_key') }}&loading=async&libraries=places,geometry&language={{ str_replace('_', '-', app()->getLocale()) }}&callback=nzInitMap"></script>
@endpush
