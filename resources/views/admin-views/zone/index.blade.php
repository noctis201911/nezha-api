@extends('layouts.admin.app')

@section('title', translate('Add_new_zone'))

@push('css_or_js')
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-sm mb-2 mb-sm-0">
                    <h1 class="page-header-title">
                        <img src="{{ dynamicAsset('assets/admin/img/zone.png') }}" alt="" class="mr-2">
                        {{ translate('messages.Add_New_Business_Zone') }}
                    </h1>
                </div>
            </div>
        </div>
        <div class="row gx-2 gx-lg-3">
            <div class="col-sm-12 col-lg-12 mb-3 mb-lg-2">
                <form action="javascript:" method="post" id="zone_form" class="shadow--card">
                    @csrf
                    <div class="row justify-content-between">
                        <div class="col-md-5">
                            <div class="zone-setup-instructions">
                                <div class="zone-setup-top">
                                    <h6 class="subtitle">{{ translate('messages.instructions') }}</h6>
                                    <p>
                                        {{ translate('messages.Create_&_connect_dots_in_a_specific_area_on_the_map_to_add_a_new_business_zone.') }}
                                    </p>
                                </div>
                                <div class="zone-setup-item">
                                    <div class="zone-setup-icon">
                                        <i class="tio-hand-draw"></i>
                                    </div>
                                    <div class="info">
                                        {{ translate('messages.Use_this_‘Hand_Tool’_to_find_your_target_zone.') }}
                                    </div>
                                </div>
                                <div class="zone-setup-item">
                                    <div class="zone-setup-icon">
                                        <i class="tio-free-transform"></i>
                                    </div>
                                    <div class="info">
                                        {{ translate('messages.Use_this_‘Shape_Tool’_to_point_out_the_areas_and_connect_the_dots._A_minimum_of_3_points/dots_is_required.') }}
                                    </div>
                                </div>
                                <div class="instructions-image mt-4">
                                    <img src="{{ dynamicAsset('assets/admin/img/instructions.gif') }}" alt="instructions">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 col-xl-7 zone-setup">
                            <div class="pl-xl-5 pl-xxl-0">
                                @php($language = \App\Models\BusinessSetting::where('key', 'language')->first())
                                @php($language = $language?->value)
                                @php($default_lang = str_replace('_', '-', app()->getLocale()))
                                <div class="js-nav-scroller hs-nav-scroller-horizontal">
                                    <ul class="nav nav-tabs mb-4">
                                        <li class="nav-item">
                                            <a class="nav-link lang_link active" href="#" id="default-link">
                                                {{ translate('messages.default') }} 
                                                <span class="form-label-secondary text-danger mt-2" data-toggle="tooltip" data-placement="right" data-original-title="{{ translate('Choose_your_preferred_language_&_set_your_zone_name.') }}">
                                                    <i class="tio-info text-muted"></i>
                                                </span>
                                            </a>
                                        </li>
                                        @if ($language)
                                            @forelse (json_decode($language) as $lang)
                                                <li class="nav-item">
                                                    <a class="nav-link lang_link" href="#" id="{{ $lang }}-link">
                                                        {{ \App\CentralLogics\Helpers::get_language_name($lang) . '(' . strtoupper($lang) . ')' }}
                                                    </a>
                                                </li>
                                            @empty
                                            @endforelse
                                        @endif
                                    </ul>
                                </div>
                                <div class="tab-content">
                                    <div class="lang_form" id="default-form">
                                        <div class="form-group mb-3">
                                            <div class="row g-3">
                                                <div class="col-sm-6">
                                                    <label class="input-label" for="default-form-input">
                                                        {{ translate('messages.business_Zone_name') }} ({{ translate('messages.default') }})
                                                    </label>
                                                    <input type="text" name="name[]" class="form-control mb-3" placeholder="{{ translate('messages.Type_new_zone_name_here') }}" maxlength="191" id="default-form-input" oninvalid="document.getElementById('default-form-input').click()" required>
                                                </div>
                                                <div class="col-sm-6">
                                                    <label class="input-label">
                                                        {{ translate('messages.Zone_Display_Name') }} ({{ translate('messages.default') }})
                                                    </label>
                                                    <input type="text" name="display_name[]" class="form-control" placeholder="{{ translate('messages.Write_a_New_Display_Zone_Name') }}" maxlength="191">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <input type="hidden" name="lang[]" value="default">
                                    @if ($language)
                                        @forelse (json_decode($language) as $lang)
                                            <div class="d-none lang_form" id="{{ $lang }}-form">
                                                <div class="form-group mb-3">
                                                    <div class="row g-3">
                                                        <div class="col-sm-6">
                                                            <label class="input-label">{{ translate('messages.business_Zone_name') }} ({{ strtoupper($lang) }})</label>
                                                            <input type="text" name="name[]" class="form-control mb-3 h--45px" placeholder="{{ translate('messages.Type_new_zone_name_here') }}">
                                                        </div>
                                                        <div class="col-sm-6">
                                                            <label class="input-label">{{ translate('messages.Zone_Display_Name') }} ({{ strtoupper($lang) }})</label>
                                                            <input type="text" name="display_name[]" class="form-control" placeholder="{{ translate('messages.Write_a_New_Display_Zone_Name') }}" maxlength="191">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <input type="hidden" name="lang[]" value="{{ $lang }}">
                                        @empty
                                        @endforelse
                                    @endif
                                </div>

                                <div class="form-group mb-3 d-none">
                                    <label class="input-label" for="coordinates">{{ translate('Coordinates') }}</label>
                                    <textarea type="text" rows="8" name="coordinates" id="coordinates" class="form-control" readonly></textarea>
                                </div>

                                <div class="map-warper overflow-hidden rounded">
                                    <input id="pac-input" class="controls rounded initial-8" title="{{ translate('messages.search_your_location_here') }}" type="text" placeholder="{{ translate('messages.search_here') }}" />
                                    <div id="map-canvas" class="h-100 m-0 p-0" style="min-height: 300px;"></div>
                                </div>
                                <div class="btn--container mt-3 justify-content-end">
                                    <button id="reset_btn" type="button" class="btn btn--reset">{{ translate('messages.reset') }}</button>
                                    <button type="submit" class="btn btn--primary">{{ translate('messages.submit') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="col-sm-12 col-lg-12 mb-3 my-lg-2">
                <div class="card">
                    <div class="card-header py-2 flex-wrap border-0 align-items-center">
                        <div class="search--button-wrapper">
                            <h5 class="card-title">{{ translate('messages.zone_list') }}<span class="badge badge-soft-dark ml-2" id="itemCount">{{ $zones->total() }}</span></h5>
                            <form class="my-2 mr-sm-2 mr-xl-4 ml-sm-auto flex-grow-1 flex-grow-sm-0">
                                <div class="input--group input-group input-group-merge input-group-flush">
                                    <input id="datatableSearch_" type="search" name="search" class="form-control" value="{{ request()?->search ?? null }}" placeholder="{{ translate('messages.Search_by_name') }}" aria-label="{{ translate('messages.search') }}">
                                    <button type="submit" class="btn btn--secondary"><i class="tio-search"></i></button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="table-responsive datatable-custom">
                        <table id="columnSearchDatatable" class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                            <thead class="thead-light">
                                <tr>
                                    <th>{{ translate('messages.sl') }}</th>
                                    <th class="text-center">{{ translate('messages.zone_id') }}</th>
                                    <th class="pl-5">{{ translate('messages.name') }}</th>
                                    <th class="pl-5">{{ translate('messages.Zone_Display_Name') }}</th>
                                    <th class="text-center">{{ translate('messages.restaurants') }}</th>
                                    <th class="text-center">{{ translate('messages.deliverymen') }}</th>
                                    <th class="text-center">{{ translate('Default_Status') }}</th>
                                    <th>{{ translate('messages.status') }}</th>
                                    <th class="w-40px text-center">{{ translate('messages.action') }}</th>
                                </tr>
                            </thead>
                            <tbody id="set-rows">
                             @includeIf('admin-views.zone.partials._table')
                            </tbody>
                        </table>
                        @if (count($zones) === 0)
                            <div class="empty--data">
                                <img src="{{ dynamicAsset('assets/admin/img/empty.png') }}" alt="public">
                                <h5>{{ translate('no_data_found') }}</h5>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script_2')
    @php($map_api_key = \App\Models\BusinessSetting::where('key', 'map_api_key')->first())
    <script src="https://maps.googleapis.com/maps/api/js?key={{ $map_api_key ? $map_api_key->value : '' }}&libraries=drawing,places&v=3.50"></script>
    
    <script>
        "use strict";
        let map; 
        let drawingManager;
        let lastpolygon = null;
        let polygons = [];

        $(document).ready(function() {
            // 修复1：多语言 Tab 切换引擎
            $(".lang_link").click(function(e){
                e.preventDefault();
                $(".lang_link").removeClass('active');
                $(".lang_form").addClass('d-none');
                $(this).addClass('active');

                let form_id = this.id;
                let lang = form_id.substring(0, form_id.length - 5); 
                $("#"+lang+"-form").removeClass('d-none');
            });

            // 修复2：防止表单回车意外提交
            $("#zone_form").on('keydown', function(e) {
                if (e.keyCode === 13) {
                    e.preventDefault();
                }
            });

            // 修复3：安全的 AJAX 提交逻辑，带错误捕捉
            $('#zone_form').on('submit', function(e) {
                e.preventDefault(); // 阻断原生提交
                
                let coords = $('#coordinates').val();
                if (!coords || coords.trim() === "") {
                    toastr.error("{{ translate('请在地图上画出配送区域的闭合多边形！') }}", { CloseButton: true, ProgressBar: true });
                    return;
                }

                let formData = new FormData(this);
                $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });
                
                $.ajax({
                    type: 'POST',
                    url: '{{ route('admin.zone.store') }}',
                    data: formData,
                    cache: false,
                    contentType: false,
                    processData: false,
                    beforeSend: function() { $('#loading').show(); },
                    success: function(data) {
                        if (data.errors) {
                            for (let i = 0; i < data.errors.length; i++) {
                                toastr.error(data.errors[i].message, { CloseButton: true, ProgressBar: true });
                            }
                        } else {
                            $('.tab-content').find('input:text').val('');
                            $('input[name="name"]').val(null);
                            if(lastpolygon) { lastpolygon.setMap(null); }
                            $('#coordinates').val(null);
                            toastr.success("{{ translate('messages.New_Business_Zone_Created_Successfully!') }}", { CloseButton: true, ProgressBar: true });
                            setTimeout(() => { location.reload(); }, 1500); // 物理强制刷新，最稳妥的重置
                        }
                    },
                    error: function(xhr) {
                        let err = JSON.parse(xhr.responseText);
                        if (err.errors) {
                            for (let i = 0; i < err.errors.length; i++) {
                                toastr.error(err.errors[i].message, { CloseButton: true, ProgressBar: true });
                            }
                        } else {
                            toastr.error("提交失败，请检查填写内容或地图坐标。", { CloseButton: true, ProgressBar: true });
                        }
                    },
                    complete: function() { $('#loading').hide(); }
                });
            });

            $('#reset_btn').click(function() {
                $('.tab-content').find('input:text').val('');
                if(lastpolygon) { lastpolygon.setMap(null); }
                $('#coordinates').val(null);
            });

            set_all_zones();
        });

        function initialize() {
            @php($default_location = \App\Models\BusinessSetting::where('key', 'default_location')->first())
            @php($default_location = $default_location && $default_location->value ? json_decode($default_location->value, true) : null)
            let myLatlng = {
                lat: {{ $default_location ? $default_location['lat'] : 23.757989 }},
                lng: {{ $default_location ? $default_location['lng'] : 90.360587 }}
            };

            let myOptions = {
                zoom: 13,
                center: myLatlng,
                mapTypeId: google.maps.MapTypeId.ROADMAP
            }
            map = new google.maps.Map(document.getElementById("map-canvas"), myOptions);
            drawingManager = new google.maps.drawing.DrawingManager({
                drawingMode: google.maps.drawing.OverlayType.POLYGON,
                drawingControl: true,
                drawingControlOptions: {
                    position: google.maps.ControlPosition.TOP_CENTER,
                    drawingModes: [google.maps.drawing.OverlayType.POLYGON]
                },
                polygonOptions: { editable: true }
            });
            drawingManager.setMap(map);

            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition((position) => {
                    const pos = { lat: position.coords.latitude, lng: position.coords.longitude };
                    map.setCenter(pos);
                });
            }

            google.maps.event.addListener(drawingManager, "overlaycomplete", function(event) {
                if (lastpolygon) { lastpolygon.setMap(null); }
                $('#coordinates').val(event.overlay.getPath().getArray());
                lastpolygon = event.overlay;
            });

            const input = document.getElementById("pac-input");
            const searchBox = new google.maps.places.SearchBox(input);
            map.controls[google.maps.ControlPosition.TOP_CENTER].push(input);
            
            map.addListener("bounds_changed", () => {
                searchBox.setBounds(map.getBounds());
            });

            let markers = [];
            searchBox.addListener("places_changed", () => {
                const places = searchBox.getPlaces();
                if (places.length == 0) return;
                
                markers.forEach(m => m.setMap(null));
                markers = [];
                const bounds = new google.maps.LatLngBounds();
                
                places.forEach((place) => {
                    if (!place.geometry || !place.geometry.location) return;
                    
                    // 【修复核心】：降级回最基础的 Marker，杜绝兼容性崩溃
                    let marker = new google.maps.Marker({
                        map: map,
                        title: place.name,
                        position: place.geometry.location
                    });
                    markers.push(marker);

                    if (place.geometry.viewport) {
                        bounds.union(place.geometry.viewport);
                    } else {
                        bounds.extend(place.geometry.location);
                    }
                });
                map.fitBounds(bounds);
            });
        }

        if (typeof google !== 'undefined') {
            google.maps.event.addDomListener(window, 'load', initialize);
        }

        function set_all_zones() {
            $.get({
                url: '{{ route('admin.zone.zoneCoordinates') }}',
                dataType: 'json',
                success: function(data) {
                    if(typeof google === 'undefined') return;
                    for (let i = 0; i < data.length; i++) {
                        let poly = new google.maps.Polygon({
                            paths: data[i],
                            strokeColor: "#FF0000", strokeOpacity: 0.8, strokeWeight: 2,
                            fillColor: "#FF0000", fillOpacity: 0.1,
                        });
                        poly.setMap(map);
                        polygons.push(poly);
                    }
                },
            });
        }
    </script>
@endpush