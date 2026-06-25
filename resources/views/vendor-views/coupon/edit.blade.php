@extends('layouts.vendor.app')

@section('title',translate('coupon_update'))


@section('content')
@php($restaurant_data = \App\CentralLogics\Helpers::get_restaurant_data())
@php($cur = \App\CentralLogics\Helpers::currency_symbol())

    <div class="content container-fluid">
        <!-- Page Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-sm mb-2 mb-sm-0">
                    <h1 class="page-header-title"><i class="tio-edit"></i> {{translate('messages.coupon_update')}}</h1>
                </div>
            </div>
        </div>
        <!-- End Page Header -->
        <div class="card">
            <div class="card-body">
                <form action="{{route('vendor.coupon.update',[$coupon['id']])}}" method="post">
                    @csrf
                    <div class="row">
                        <div class="col-12">
                            @php($language=\App\Models\BusinessSetting::where('key','language')->first())
                            @php($language = $language->value ?? null)
                            @php($default_lang = str_replace('_', '-', app()->getLocale()))
                            @if($language)
                                <div class="js-nav-scroller hs-nav-scroller-horizontal">
                                    <ul class="nav nav-tabs mb-4">
                                        <li class="nav-item">
                                            <a class="nav-link lang_link active"
                                            href="#"
                                            id="default-link">{{translate('messages.default')}}</a>
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
                                <div class="lang_form" id="default-form">
                                    <div class="form-group">
                                        <label class="input-label" for="default_title">{{translate('messages.title')}} ({{translate('messages.default')}}) <span class="text-danger">*</span></label>
                                        <input type="text"  name="title[]" id="default_title" class="form-control" placeholder="{{translate('messages.new_coupon')}}" value="{{$coupon->getRawOriginal('title')}}"  >
                                    </div>
                                    <input type="hidden" name="lang[]" value="default">
                                </div>
                                @foreach(json_decode($language) as $lang)
                                    <?php
                                        if(count($coupon['translations'])){
                                            $translate = [];
                                            foreach($coupon['translations'] as $t)
                                            {
                                                if($t->locale == $lang && $t->key=="title"){
                                                    $translate[$lang]['title'] = $t->value;
                                                }
                                            }
                                        }
                                    ?>
                                    <div class="d-none lang_form" id="{{$lang}}-form">
                                        <div class="form-group">
                                            <label class="input-label" for="{{$lang}}_title">{{translate('messages.title')}} ({{strtoupper($lang)}})</label>
                                            <input type="text" name="title[]" id="{{$lang}}_title" class="form-control" placeholder="{{translate('messages.new_coupon')}}" value="{{$translate[$lang]['title']??''}}"  >
                                        </div>
                                        <input type="hidden" name="lang[]" value="{{$lang}}">
                                    </div>
                                @endforeach
                            @else
                            <div id="default-form">
                                <div class="form-group">
                                    <label class="input-label" for="exampleFormControlInput1">{{translate('messages.title')}} ({{ translate('messages.default') }})</label>
                                    <input type="text" name="title[]" class="form-control" placeholder="{{translate('messages.new_coupon')}}" value="{{$coupon['title']}}" maxlength="100" >
                                </div>
                                <input type="hidden" name="lang[]" value="default">
                            </div>
                            @endif
                        </div>

                        {{-- 哪吒[券重做 2026-06-25]: 券类型=满减券/折扣券, 驱动 discount_type; coupon_type 固定 default --}}
                        <input type="hidden" name="coupon_type" value="default">
                        <div class="col-sm-6 col-lg-3">
                            <div class="form-group">
                                <label class="input-label">券类型 <span class="text-danger">*</span></label>
                                <select id="discount_type" name="discount_type" class="form-control coupon-template-change">
                                    <option value="amount" {{$coupon['discount_type']=='amount'?'selected':''}}>满减券（满 X 减 Y 元）</option>
                                    <option value="percent" {{$coupon['discount_type']=='percent'?'selected':''}}>折扣券（满 X 打折 · 可封顶）</option>
                                </select>
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-3" id="min_purchase_div">
                            <div class="form-group">
                                <label class="input-label" for="min_purchase">满 · 最低消费（{{ $cur }}）
                                    <span class="input-label-secondary" data-toggle="tooltip" title="" data-original-title="{{ translate('填 0 = 无门槛, 任意金额可用') }}">
                                        <i class="tio-info text-gray1 fs-16"></i>
                                    </span>
                                </label>
                                <input required id="min_purchase" type="number" name="min_purchase" step="0.01" value="{{$coupon['min_purchase']}}" min="0" max="999999999999.99" class="form-control" placeholder="例：3000">
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-3" id="discount_div">
                            <div class="form-group">
                                <label class="input-label" for="discount" id="discount_label">减（{{ $cur }}） <span class="text-danger">*</span></label>
                                <input type="number" id="discount" min="1" max="999999999999.99" step="0.01" value="{{$coupon['discount']}}" name="discount" class="form-control" required>
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-3" id="max_discount_div" style="display:none;">
                            <div class="form-group">
                                <label class="input-label" for="max_discount">最高可减（{{ $cur }}）
                                    <span class="input-label-secondary" data-toggle="tooltip" title="" data-original-title="{{ translate('折扣券封顶: 留空或 0 = 不封顶') }}">
                                        <i class="tio-info text-gray1 fs-16"></i>
                                    </span>
                                </label>
                                <input type="number" min="0" max="999999999999.99" step="0.01" value="{{$coupon['max_discount']}}" name="max_discount" id="max_discount" class="form-control">
                            </div>
                        </div>

                        <div class="col-lg-3 col-sm-6">
                            <div class="form-group">
                                <div class="d-flex justify-content-between">
                                    <label class="input-label" for="coupon_code">{{translate('messages.code')}} <span class="text-danger">*</span></label>
                                    <label class="input-label generate-code" id="generate_code"><i class="tio-hand-draw"></i>{{translate('messages.Generate Code')}}</label>
                                </div>
                                <input id="coupon_code" type="text" name="code" class="form-control" value="{{$coupon['code']}}"
                                       placeholder="{{\Illuminate\Support\Str::random(8)}}" required maxlength="100">
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-3">
                            <div class="form-group">
                                <label class="input-label" for="coupon_limit">每人限领次数
                                    <span class="text-danger">*</span>
                                </label>
                                <input required type="number" name="limit" id="coupon_limit" value="{{$coupon['limit']}}" class="form-control" min="1" max="100"
                                        placeholder="例：1">
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-3">
                            <div class="form-group">
                                <label class="input-label">{{translate('messages.start_date')}} <span class="text-danger">*</span></label>
                                <input type="date" name="start_date" class="form-control" id="date_from" placeholder="{{translate('messages.select_date')}}" value="{{date('Y-m-d',strtotime($coupon['start_date']))}}">
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="form-group">
                                <label class="input-label">{{translate('messages.expire_date')}} <span class="text-danger">*</span></label>
                                <input type="date" name="expire_date" class="form-control" placeholder="{{translate('messages.select_date')}}" id="date_to" value="{{date('Y-m-d',strtotime($coupon['expire_date']))}}">
                            </div>
                        </div>
                    </div>
                    <div class="btn--container justify-content-end">
                        <button id="reset_btn" type="button" class="btn btn--reset location-reload">{{translate('messages.reset')}}</button>
                        <button type="submit" class="btn btn--primary">{{translate('messages.update')}}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endsection

@push('script_2')
    <script>
        "use strict";
        $("#date_from").on("change", function () {
            $('#date_to').attr('min',$(this).val());
        });

        $("#date_to").on("change", function () {
            $('#date_from').attr('max',$(this).val());
        });

        // 哪吒[券重做 2026-06-25]: 券类型模板切换——满减券(amount)/折扣券(percent)
        function nezhaApplyCouponTemplate(type){
            if(type === 'percent'){
                $('#discount_label').html('折扣（%） <span class="text-danger">*</span>');
                $('#discount').attr('min',1).attr('max',100);
                $('#max_discount_div').show();
                $('#max_discount').removeAttr('readonly');
            } else {
                $('#discount_label').html('减（{{ $cur }}） <span class="text-danger">*</span>');
                $('#discount').attr('min',1).attr('max',999999999999.99);
                $('#max_discount_div').hide();
                $('#max_discount').val('');
            }
        }

        $(document).on('change', '.coupon-template-change', function () {
            nezhaApplyCouponTemplate($(this).val());
        });

        $(document).on('ready', function () {
            $('#date_from').attr('max','{{date("Y-m-d",strtotime($coupon["expire_date"]))}}');
            $('#date_to').attr('min','{{date("Y-m-d",strtotime($coupon["start_date"]))}}');

            // INITIALIZATION OF FLATPICKR
            // =======================================================
            $('.js-flatpickr').each(function () {
                $.HSCore.components.HSFlatpickr.init($(this));
            });
        });

        $(document).ready(function() {
            // 按当前券类型初始化字段显隐/标签
            nezhaApplyCouponTemplate($('#discount_type').val());

            $('#generate_code').click(function() {
                generateUniqueCode();
            });

            function generateUniqueCode() {
                let code = generateRandomCode();
                checkCodeExists(code, function(exists) {
                    if (exists) {
                        generateUniqueCode();
                    } else {
                        $('#coupon_code').val(code);
                    }
                });
            }

            function generateRandomCode() {
                let length = 8;
                let result = '';
                let characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
                let charactersLength = characters.length;
                for (let i = 0; i < length; i++) {
                    result += characters.charAt(Math.floor(Math.random() * charactersLength));
                }
                return result;
            }

            function checkCodeExists(code, callback) {
                $.ajax({
                    url: '{{ route('vendor.coupon.check.code') }}',
                    method: 'get',
                    data: { code: code },
                    success: function(response) {
                        callback(response.exists);
                    },
                    error: function() {
                        callback(false);
                    }
                });
            }
        });

    </script>
@endpush
