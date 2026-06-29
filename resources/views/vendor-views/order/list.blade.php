@extends('layouts.vendor.app')
{{-- 哪吒2026-06-26: 本页订单行/操作列改用原生 PHP 块输出变量, 勿改回 Blade 行内简写(曾致编译畸形整页500); 部署侧 nzcheck-blade 编译探针兜底 --}}

@section('title',translate('messages.Order List'))

@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- 手机端(<768px)把订单表格重排为卡片, 操作按钮直接露出; PC/平板(>=768px)不命中本媒体查询, 表格原样不变 --}}
    <style>
        .nz-order-table-card { border: 1px solid #E6EAF0; border-radius: 10px; box-shadow: 0 1px 4px rgba(16,24,40,.04); overflow: hidden; }
        .nz-order-table-card .card-header { border-bottom: 1px solid #EDF1F5; background: #fff; }
        .nz-order-table-card #datatable { table-layout: fixed; min-width: 1180px; }
        .nz-order-table-card #datatable th:nth-child(1), .nz-order-table-card #datatable td:nth-child(1) { width: 4%; }
        .nz-order-table-card #datatable th:nth-child(2), .nz-order-table-card #datatable td:nth-child(2) { width: 24%; }
        .nz-order-table-card #datatable th:nth-child(3), .nz-order-table-card #datatable td:nth-child(3) { width: 13%; }
        .nz-order-table-card #datatable th:nth-child(4), .nz-order-table-card #datatable td:nth-child(4) { width: 16%; }
        .nz-order-table-card #datatable th:nth-child(5), .nz-order-table-card #datatable td:nth-child(5) { width: 12%; }
        .nz-order-table-card #datatable th:nth-child(6), .nz-order-table-card #datatable td:nth-child(6) { width: 12%; }
        .nz-order-table-card #datatable th:nth-child(7), .nz-order-table-card #datatable td:nth-child(7) { width: 10%; }
        .nz-order-table-card #datatable th:nth-child(8), .nz-order-table-card #datatable td:nth-child(8) { width: 5%; }
        .nz-order-table-card #datatable th:nth-child(9), .nz-order-table-card #datatable td:nth-child(9) { width: 4%; }
        .nz-print-settings { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 10px 16px; border-bottom: 1px solid #EDF1F5; background: #FFF7F8; color: #7c1228; font-size: 13px; }
        .nz-print-settings label { display: inline-flex; align-items: center; gap: 6px; margin: 0; font-weight: 700; }
        .nz-print-settings input { accent-color: #C4193E; }
        .nz-print-settings .btn { border-radius: 7px; }
        .nz-order-id { font-size: 16px; font-weight: 800; color: #102A4C; }
        .nz-order-foods { font-size: 12px; color: #6B7280; max-width: 220px; white-space: normal; line-height: 1.4; margin-top: 4px; }
        .nz-order-time strong { display: block; font-size: 14px; color: #102A4C; }
        .nz-order-money { font-size: 15px; font-weight: 800; color: #102A4C; }
        .nz-order-status-muted { color: #8A94A6; font-size: 12px; font-weight: 600; }
        .nz-step-empty { color: #98A2B3; font-size: 12px; font-weight: 700; }
        .nz-step-btn { border-radius: 7px !important; font-size: 12px !important; padding: 6px 12px !important; min-width: 86px; font-weight: 800 !important; }
        .nz-action-icon { width: 38px; height: 36px; border-radius: 7px !important; display: inline-flex; align-items: center; justify-content: center; }
        .nz-order-status-hero { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; padding: 14px 16px; margin-bottom: 12px; border: 1px solid #E6EAF0; border-radius: 10px; background: #fff; box-shadow: 0 1px 4px rgba(16,24,40,.04); }
        .nz-order-status-hero h2 { margin: 0; font-size: 21px; line-height: 1.25; font-weight: 900; color: #102A4C; letter-spacing: 0; }
        .nz-order-status-hero p { margin: 5px 0 0; color: #667085; font-size: 13px; line-height: 1.45; max-width: 680px; }
        .nz-status-count { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 24px; padding: 0 9px; margin-left: 7px; border-radius: 999px; background: #EFF6FF; color: #1D4ED8; font-size: 13px; font-weight: 900; }
        .nz-status-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        .nz-status-tabs a { display: inline-flex; align-items: center; gap: 6px; min-height: 34px; padding: 7px 11px; border: 1px solid #E6EAF0; border-radius: 8px; background: #fff; color: #344054; font-size: 12px; font-weight: 800; }
        .nz-status-tabs a.active { border-color: #C4193E; background: #FFF1F3; color: #A41435; }
        .nz-status-tabs i { font-size: 14px; }
        .nz-status-hero-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
        .nz-status-empty-copy { color: #102A4C; font-weight: 900; }
        .nz-status-empty-help { color: #667085; font-size: 13px; margin-top: -4px; }
        .nz-mobile-print-toggle, .nz-order-mobile-amount, .nz-mobile-action-label { display: none; }
        @media (max-width: 767.98px) {
            .content.container-fluid { padding-left: 10px; padding-right: 10px; }
            .page-header { margin-bottom: 6px; }
            .nz-order-status-hero { display: block; padding: 11px 12px; margin-bottom: 8px; border-radius: 9px; }
            .nz-order-status-hero h2 { font-size: 18px; }
            .nz-order-status-hero p { font-size: 12px; line-height: 1.35; margin-top: 4px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
            .nz-status-hero-actions { justify-content: flex-start; margin-top: 9px; }
            .nz-status-hero-actions .btn { min-height: 34px; padding-left: 12px; padding-right: 12px; }
            .nz-mobile-status-strip { flex-wrap: nowrap; overflow-x: auto; padding: 0 1px 6px; margin: 0 -1px 8px; scrollbar-width: none; }
            .nz-mobile-status-strip::-webkit-scrollbar { display: none; }
            .nz-mobile-status-strip a { flex: 0 0 auto; min-height: 36px; padding: 8px 11px; border-radius: 7px; }
            .nz-order-table-card { border-radius: 9px; overflow: visible; }
            .nz-mobile-toolbar { padding: 10px 10px 8px !important; }
            .nz-mobile-toolbar .search--button-wrapper { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: start; }
            .nz-mobile-toolbar form { min-width: 0; }
            .nz-mobile-toolbar .input--group { width: 100%; }
            .nz-mobile-toolbar .hs-unfold { margin-right: 0 !important; }
            .nz-mobile-toolbar .hs-unfold > .btn { min-height: 42px; border-radius: 8px; }
            .nz-print-settings { display: block; padding: 8px 10px; }
            .nz-mobile-print-toggle { display: flex; align-items: center; justify-content: space-between; gap: 8px; width: 100%; min-height: 38px; padding: 0; border: 0; background: transparent; color: #7c1228; font-weight: 900; text-align: left; }
            .nz-mobile-print-toggle .nz-mobile-print-state { margin-left: auto; color: #667085; font-size: 12px; font-weight: 800; }
            .nz-print-settings:not(.nz-print-open) .nz-print-title,
            .nz-print-settings:not(.nz-print-open) label,
            .nz-print-settings:not(.nz-print-open) #nzTestPrintBtn,
            .nz-print-settings:not(.nz-print-open) > .text-muted { display: none; }
            .nz-print-settings.nz-print-open { display: flex; align-items: center; gap: 8px; padding-bottom: 10px; }
            .nz-print-settings.nz-print-open .nz-mobile-print-toggle { flex-basis: 100%; }
            .nz-print-settings.nz-print-open label { min-height: 34px; }
            #datatable thead { display: none; }
            #datatable, #datatable tbody { display: block; width: 100%; }
            #datatable tr.class-all {
                display: block;
                background: #fff;
                border: 1px solid #eef0f3;
                border-radius: 10px;
                box-shadow: 0 1px 4px rgba(0,0,0,.04);
                margin-bottom: 10px;
                padding: 0 12px;
            }
            #datatable tr.class-all td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 10px;
                width: 100%;
                border: 0;
                border-bottom: 1px solid #f3f4f6;
                padding: 8px 0;
                text-align: right;
                white-space: normal;
                font-size: 13px;
            }
            #datatable tr.class-all td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #8a94a6;
                text-align: left;
                flex: 0 0 72px;
                white-space: nowrap;
            }
            #datatable tr.class-all td:first-child { display: none; }      /* 序号在卡片里无意义, 隐藏 */
            #datatable tr.class-all td:last-child { border-bottom: 0; }
            #datatable tr.class-all td > * { text-align: right; }
            #datatable tr.class-all td[data-label="订单"] { display: block; padding: 12px 0 10px; }
            #datatable tr.class-all td[data-label="订单"]::before { display: none; }
            .nz-order-primary-line { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
            .nz-order-id { font-size: 18px; line-height: 1.15; }
            .nz-order-mobile-amount { display: block; flex: 0 0 auto; text-align: right; font-size: 15px; font-weight: 900; color: #102A4C; }
            .nz-order-mobile-amount small { display: block; margin-top: 2px; font-size: 12px; font-weight: 900; }
            .nz-order-foods { max-width: none; margin-top: 5px; }
            .nz-order-time strong { display: inline; margin-right: 8px; }
            .nz-order-time { white-space: nowrap !important; }
            .nz-order-time strong, .nz-order-time .text-muted { white-space: nowrap !important; }
            .nz-order-time .text-muted { display: inline !important; }
            .nz-order-amount-cell { display: none !important; }
            #datatable tr.class-all td .btn--container { justify-content: flex-end; }
            #datatable tr.class-all td.nz-order-status-cell { align-items: flex-start; }
            #datatable tr.class-all td.nz-order-status-cell > * { max-width: 70%; }
            #datatable tr.class-all td.nz-order-status-cell .badge,
            #datatable tr.class-all td.nz-order-status-cell .text-capitalze { white-space: nowrap; }
            #datatable tr.class-all td.nz-order-mobile-actions { display: block; padding: 10px 0 8px; }
            #datatable tr.class-all td.nz-order-mobile-actions::before { display: block; margin-bottom: 8px; }
            #datatable tr.class-all td.nz-order-mobile-actions form,
            #datatable tr.class-all td.nz-order-mobile-actions .nz-step-btn { width: 100%; }
            #datatable tr.class-all td.nz-order-mobile-actions .nz-step-btn { min-height: 42px; display: inline-flex; align-items: center; justify-content: center; }
            #datatable tr.class-all td.nz-print-action-cell,
            #datatable tr.class-all td.nz-detail-action-cell { display: inline-flex !important; width: 50% !important; max-width: 50%; box-sizing: border-box; justify-content: center; padding: 8px 4px 12px; border-bottom: 0; }
            #datatable tr.class-all td.nz-print-action-cell::before,
            #datatable tr.class-all td.nz-detail-action-cell::before { display: none; }
            #datatable tr.class-all td .action-btn { display: inline-flex !important; align-items: center; width: 100%; height: 42px; gap: 6px; font-size: 13px; font-weight: 800; justify-content: center !important; }  /* 加大点击热区 */
            .nz-mobile-action-label { display: inline; }
        }
    </style>
@endpush

@section('content')
@php
    $nzRawStatus = $st ?? 'all';
    $nzStatusMeta = [
        'all' => ['label' => '全部订单', 'hint' => '集中查看当前仍需履约或复核的订单，按下一步操作推进。', 'empty' => '当前没有需要处理的订单。', 'icon' => 'tio-shopping-cart'],
        'offline_pending' => ['label' => '待确认收款', 'hint' => '顾客已提交直付凭证，商家确认自己账户已到账后再出餐。', 'empty' => '暂无待确认收款订单。', 'icon' => 'tio-checkmark-circle'],
        'refund_pending' => ['label' => '待退款', 'hint' => '平台不经手货款；请商家按原路退还顾客后在此标记已退款。', 'empty' => '暂无待退款订单。', 'icon' => 'tio-receipt-outlined'],
        'pending' => ['label' => '待处理', 'hint' => '新订单在这里接单；直付待核验订单请优先到待确认收款处理。', 'empty' => '暂无待处理订单。', 'icon' => 'tio-timer'],
        'confirmed' => ['label' => '已接单', 'hint' => '已确认的订单请尽快开始备餐，避免超时影响体验。', 'empty' => '暂无已接单订单。', 'icon' => 'tio-checkmark-circle-outlined'],
        'cooking' => ['label' => '备餐中', 'hint' => '备餐完成后标记配送中，顾客侧会同步看到进度。', 'empty' => '暂无备餐中订单。', 'icon' => 'tio-restaurant'],
        'ready_for_delivery' => ['label' => '待配送', 'hint' => '已出餐、等待配送流转的订单会显示在这里。', 'empty' => '暂无待配送订单。', 'icon' => 'tio-directions'],
        'food_on_the_way' => ['label' => '配送中', 'hint' => '配送中的订单送达后请及时完成，减少顾客等待不确定性。', 'empty' => '暂无配送中订单。', 'icon' => 'tio-send'],
        'delivered' => ['label' => '已送达', 'hint' => '已完成订单用于核对履约结果，可查看详情或补打小票。', 'empty' => '暂无已送达订单。', 'icon' => 'tio-done-all'],
        'refunded' => ['label' => '已退款', 'hint' => '已关闭的退款订单仅供核对记录。', 'empty' => '暂无已退款订单。', 'icon' => 'tio-receipt-outlined'],
        'refund_requested' => ['label' => '退款申请中', 'hint' => '顾客发起退款申请的订单，请进入详情核对原因和凭证后处理。', 'empty' => '暂无退款申请中的订单。', 'icon' => 'tio-help-outlined'],
        'scheduled' => ['label' => '已预订', 'hint' => '预约订单按预约时间履约，接近出餐时再推进状态。', 'empty' => '暂无预约订单。', 'icon' => 'tio-calendar-month'],
        'payment_failed' => ['label' => '支付失败', 'hint' => '支付失败订单已关闭，通常无需商家继续履约。', 'empty' => '暂无支付失败订单。', 'icon' => 'tio-warning-outlined'],
        'canceled' => ['label' => '已取消', 'hint' => '已取消订单用于核对取消原因和退款留痕。', 'empty' => '暂无已取消订单。', 'icon' => 'tio-clear-circle-outlined'],
    ];
    $nzStatusTabs = ['all','offline_pending','refund_pending','pending','confirmed','cooking','ready_for_delivery','food_on_the_way','delivered','refunded','refund_requested','scheduled','payment_failed','canceled'];
    $nzCurrentMeta = $nzStatusMeta[$nzRawStatus] ?? ['label' => str_replace('_', ' ', $nzRawStatus), 'hint' => '查看该状态下的订单。', 'empty' => '暂无该状态订单。', 'icon' => 'tio-shopping-cart'];
@endphp
    <div class="content container-fluid">
        <!-- Page Header -->
        <div class="page-header pt-0 pb-2">
            <div class="nz-order-status-hero">
                <div>
                    <h2>
                        <i class="{{ $nzCurrentMeta['icon'] }} mr-1"></i>{{ $nzCurrentMeta['label'] }}
                        <span class="nz-status-count">{{$orders->total()}}</span>
                    </h2>
                    <p>{{ $nzCurrentMeta['hint'] }}</p>
                </div>
                <div class="nz-status-hero-actions">
                    <a class="btn btn-sm btn-white" href="{{route('vendor.order.list',['all'])}}">
                        <i class="tio-refresh mr-1"></i>全部
                    </a>
                    <a class="btn btn-sm btn--primary" href="{{route('vendor.order.list',['offline_pending'])}}">
                        <i class="tio-checkmark-circle mr-1"></i>收款
                    </a>
                </div>
            </div>
            <div class="nz-status-tabs nz-mobile-status-strip d-print-none">
                @foreach($nzStatusTabs as $__statusKey)
                    <a href="{{route('vendor.order.list',[$__statusKey])}}" class="{{ $nzRawStatus === $__statusKey ? 'active' : '' }}">
                        <i class="{{ $nzStatusMeta[$__statusKey]['icon'] ?? 'tio-circle' }}"></i>
                        <span>{{ $nzStatusMeta[$__statusKey]['label'] ?? $__statusKey }}</span>
                    </a>
                @endforeach
            </div>
        </div>
        <!-- End Page Header -->


        <!-- End Page Header -->

        <!-- Card -->
        <div class="card nz-order-table-card">
            <!-- Header -->
            <div class="card-header py-2 nz-mobile-toolbar">
                <div class="search--button-wrapper justify-content-end max-sm-flex-100">
                    <form >
                        <!-- Search -->
                        <div class="input-group input--group">
                            <input id="datatableSearch_" type="search" name="search" class="form-control" value="{{ request()?->search ?? null}}"
                                    placeholder="{{ translate('Ex : Search by Order Id') }}" aria-label="{{translate('messages.search')}}">
                            <button type="submit" class="btn btn--secondary">
                                <i class="tio-search"></i>
                            </button>
                        </div>
                        <!-- End Search -->
                    </form>

                    <div class="d-sm-flex justify-content-sm-end align-items-sm-center m-0">


                        <!-- Unfold -->
                        <div class="hs-unfold mr-2">
                            <a class="js-hs-unfold-invoker btn btn-sm btn-white dropdown-toggle" href="javascript:;"
                                data-hs-unfold-options='{
                                    "target": "#usersExportDropdown",
                                    "type": "css-animation"
                                }'>
                                <i class="tio-download-to mr-1"></i> {{translate('messages.export')}}
                            </a>

                            <div id="usersExportDropdown"
                                    class="hs-unfold-content dropdown-unfold dropdown-menu dropdown-menu-sm-right">

                                <span
                                    class="dropdown-header">{{translate('messages.download_options')}}</span>
                                <a id="export-excel" class="dropdown-item" href="{{route("vendor.order.export",['status'=>$st,'type'=>'excel',request()->getQueryString() ])}}">
                                    <img class="avatar avatar-xss avatar-4by3 mr-2"
                                            src="{{dynamicAsset('assets/admin')}}/svg/components/excel.svg"
                                            alt="Image Description">
                                    {{translate('messages.excel')}}
                                </a>
                                <a id="export-csv" class="dropdown-item" href="{{route("vendor.order.export",['status'=>$st,'type'=>'csv',request()->getQueryString() ])}}">
                                    <img class="avatar avatar-xss avatar-4by3 mr-2"
                                            src="{{dynamicAsset('assets/admin')}}/svg/components/placeholder-csv-format.svg"
                                            alt="Image Description">
                                    {{translate('messages.csv')}}
                                </a>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="nz-print-settings d-print-none" id="nzPrintSettings">
                <button type="button" class="nz-mobile-print-toggle" id="nzMobilePrintToggle">
                    <span><i class="tio-print mr-1"></i>打印设置</span>
                    <span class="nz-mobile-print-state" id="nzMobilePrintState">未开启</span>
                </button>
                <span class="nz-print-title" style="font-weight:800;">打印小票</span>
                <label>
                    <input type="checkbox" id="nzPrintReady">
                    已接入并测试打印机
                </label>
                <label>
                    <input type="checkbox" id="nzAutoPrintReady">
                    确认收款/接单后自动打单
                </label>
                <button type="button" class="btn btn-sm btn-outline-primary" id="nzTestPrintBtn">
                    <i class="tio-print mr-1"></i>测试打印
                </button>
                <span class="text-muted" style="font-weight:600;">未确认接入时不会自动弹打印，避免误触。</span>
            </div>
            <!-- End Header -->

            <!-- Table -->
            <div class="table-responsive datatable-custom">
                <table id="datatable"
                       class="table table-hover table-borderless table-thead-bordered table-nowrap table-align-middle card-table"
                       data-hs-datatables-options='{
                                 "order": [],
                                 "orderCellsTop": true,
                                 "paging":false
                               }'>
                    <thead class="thead-light">
                    <tr>
                        <th class="w-60px">
                            {{ translate('messages.sl') }}
                        </th>
                        <th class="w-180px table-column-pl-0">订单</th>
                        <th class="w-140px">{{translate('messages.order_date')}}</th>
                        <th class="w-160px">{{translate('messages.customer_information')}}</th>
                        <th class="w-110px">{{translate('messages.total_amount')}}</th>
                        <th class="w-110px text-center">{{translate('messages.order_status')}}</th>
                        <th class="w-130px text-center">下一步操作</th>
                        <th class="w-80px text-center">打印小票</th>
                        <th class="w-80px text-center">订单详情</th>
                    </tr>
                    </thead>

                    <tbody id="set-rows">
                    @foreach($orders as $key=>$order)
                        <tr class="status-{{$order['order_status']}} class-all">
                            <td class="" data-label="{{translate('messages.sl')}}">
                                {{$key+$orders->firstItem()}}
                            </td>
                            <td class="table-column-pl-0" data-label="订单">
                                <div class="nz-order-primary-line">
                                    <a href="{{route('vendor.order.details',['id'=>$order['id']])}}" class="text-hover nz-order-id">#{{$order['id']}}
                                        @if ($order->is_pos == 1)
                                        <span class="text--warning font-500">({{ translate('POS') }})</span>
                                        @endif
                                    </a>
                                    <span class="nz-order-mobile-amount">
                                        {{\App\CentralLogics\Helpers::format_currency($order['order_amount'])}}
                                        @if($order->payment_status=='paid')
                                            <small class="text-success">{{translate('messages.paid')}}</small>
                                        @elseif($order->payment_status=='partially_paid')
                                            <small class="text-success">{{translate('messages.partially_paid')}}</small>
                                        @else
                                            <small class="text-danger">{{translate('messages.unpaid')}}</small>
                                        @endif
                                    </span>
                                </div>
                                @if ($order->edited == 1)
                                <span class="text-info fs-12 d-block font-500">({{ translate('Edited') }})</span>
                                @endif
                                @if($order->details && $order->details->count() > 0)
                                    <div class="nz-order-foods">
                                        @foreach($order->details->take(3) as $__d)
                                            <?php $__fd = is_string($__d->food_details) ? json_decode($__d->food_details, true) : $__d->food_details; ?>
                                            {{ $__fd['name'] ?? '—' }}@if($__d->quantity > 1)<span class="text-body">×{{ $__d->quantity }}</span>@endif{{ !$loop->last ? '、' : '' }}
                                        @endforeach
                                        @if($order->details->count() > 3)
                                            <span class="text-body">等{{ $order->details->count() }}样</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td data-label="{{translate('messages.order_date')}}" class="nz-order-time">
                                <strong>{{ Carbon\Carbon::parse($order['created_at'])->format('Y-m-d') }}</strong>
                                <span class="d-block text-muted">{{ Carbon\Carbon::parse($order['created_at'])->format('H:i') }}</span>
                            </td>
                            <td data-label="{{translate('messages.customer_information')}}">
                                @if($order->is_guest)
                                     <?php
                                        $customer_details = json_decode($order['delivery_address'],true);
                                    ?>
                                    <strong>{{$customer_details['contact_person_name']}}</strong>
                                    <div class="text-muted">{{\App\CentralLogics\Helpers::mask_phone($customer_details['contact_person_number'] ?? '')}}</div>
                                @elseif($order->customer)
                                    <a class="text-body text-capitalize"
                                        href="{{route('vendor.order.details',['id'=>$order['id']])}}">
                                        <span class="d-block font-semibold">
                                                {{$order->customer['f_name'].' '.$order->customer['l_name']}}
                                        </span>
                                        <span class="d-block text-muted">
                                                {{\App\CentralLogics\Helpers::mask_phone($order->customer['phone'] ?? '')}}
                                        </span>
                                    </a>
                                @else
                                    <label
                                        class="badge badge--pending">{{translate('messages.Walk_In_Customer')}}</label>
                                @endif
                            </td>
                            <td class="nz-order-amount-cell" data-label="{{translate('messages.total_amount')}}">


                                <div class="text-right mw-85px">
                                    <div class="nz-order-money">
                                        {{\App\CentralLogics\Helpers::format_currency($order['order_amount'])}}
                                    </div>
                                    @if($order->payment_status=='paid')
                                    <strong class="text-success">
                                        {{translate('messages.paid')}}
                                    </strong>
                                    @elseif($order->payment_status=='partially_paid')
                                        <strong class="text-success">
                                            {{translate('messages.partially_paid')}}
                                        </strong>
                                    @else
                                        <strong class="text-danger">
                                            {{translate('messages.unpaid')}}
                                        </strong>
                                    @endif
                                </div>

                            </td>
                            <td class="text-capitalize text-center nz-order-status-cell" data-label="{{translate('messages.order_status')}}">
                                @if (isset($order->subscription)  && $order->subscription->status != 'canceled' )
                                    @php
                                        $order->order_status = $order->subscription_log ? $order->subscription_log->order_status : $order->order_status;
                                    @endphp
                                @endif
                                    @if($order['order_status']=='pending')
                                        <span class="badge badge-soft-info mb-1">
                                            {{translate('messages.pending')}}
                                        </span>
                                    @elseif($order['order_status']=='confirmed')
                                        <span class="badge badge-soft-info mb-1">
                                        {{translate('messages.confirmed')}}
                                        </span>
                                    @elseif($order['order_status']=='processing')
                                        <span class="badge badge-soft-warning mb-1">
                                        {{translate('messages.processing')}}
                                        </span>
                                    @elseif($order['order_status']=='picked_up')
                                        <span class="badge badge-soft-warning mb-1">
                                        {{translate('messages.out_for_delivery')}}
                                        </span>
                                    @elseif($order['order_status']=='delivered')
                                        <span class="badge badge-soft-success mb-1">
                                            {{$order?->order_type == 'dine_in' ? translate('messages.Completed') : translate('messages.delivered')}}
                                        </span>
                                    @elseif($order['order_status']=='handover')
                                        <span class="badge badge-soft-warning mb-1">
                                            {{translate('messages.handover')}}
                                        </span>
                                    @elseif($order['order_status']=='accepted')
                                        <span class="badge badge-soft-info mb-1">
                                            {{translate('messages.accepted')}}
                                        </span>
                                    @elseif($order['order_status']=='refund_request_canceled')
                                        <span class="badge badge-soft-info mb-1">
                                            退款申请已撤销
                                        </span>
                                    @else
                                        <span class="badge badge-soft-danger mb-1">
                                            {{translate(str_replace('_',' ',$order['order_status']))}}
                                        </span>
                                    @endif


                                <?php $nzTo = \App\CentralLogics\NezhaOrderTimeout::describe($order); ?>
                                @if($nzTo && in_array($nzTo['severity'], ['warning','error']) && !empty($nzTo['elapsed_minutes']))
                                    <span class="badge {{ $nzTo['severity']==='error' ? 'badge-soft-danger' : 'badge-soft-warning' }} d-block mb-1" title="{{ $nzTo['title'] }}">
                                        ⏱ {{ \App\CentralLogics\NezhaOrderTimeout::humanDuration($nzTo['elapsed_minutes']) }}
                                    </span>
                                @endif
                                <div class="text-capitalze opacity-7">
                                    @if($order['order_type']=='take_away')
                                        <span>
                                            {{translate('messages.take_away')}}
                                        </span>
                                        @elseif ($order['order_type'] == 'dine_in')
                                            <span>
                                                {{ translate('Dine_in') }}
                                            </span>
                                        @else
                                        <span>
                                            {{translate('messages.delivery')}}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="text-center nz-order-mobile-actions" data-label="下一步操作">
                                @php
                                    $__os = $order['order_status'];
                                    $__qa = null;
                                    if ($__os === 'pending' && $order->payment_method === 'offline_payment'
                                        && $order->offline_payments && $order->offline_payments->status === 'pending') {
                                        $__qa = ['route' => route('vendor.order.confirm-offline-payment', $order['id']),
                                                  'label' => '确认收款', 'cls' => 'btn-success', 'icon' => 'tio-checkmark-circle',
                                                  'confirm' => '确认：您已在自己的账户收到本单付款？',
                                                  'auto_print' => true];
                                    } elseif ($__os === 'pending') {
                                        $__qa = ['route' => route('vendor.order.status-update', $order['id']),
                                                  'label' => '接单', 'cls' => 'btn-success', 'icon' => 'tio-checkmark-circle',
                                                  'confirm' => '确认接单？',
                                                  'auto_print' => true,
                                                  'extra' => ['order_status'=>'confirmed','id'=>$order['id']]];
                                    } elseif (in_array($__os, ['confirmed','accepted'], true)) {
                                        $__qa = ['route' => route('vendor.order.status-update', $order['id']),
                                                  'label' => '开始备餐', 'cls' => 'btn-info', 'icon' => 'tio-restaurant',
                                                  'confirm' => '开始备餐？',
                                                  'extra' => ['order_status'=>'processing','id'=>$order['id'],
                                                              'processing_time'=>explode('-',$restaurant->delivery_time ?? '30-60')[0]]];
                                    } elseif (in_array($__os, ['processing','handover'], true) && ($order['order_type'] ?? '') === 'delivery') {
                                        $__qa = ['route' => route('vendor.order.mark-dispatched', $order['id']),
                                                  'label' => '标记配送中', 'cls' => 'btn-warning', 'icon' => 'tio-send',
                                                  'confirm' => '确认出餐完成、标记为配送中？'];
                                    } elseif ($__os === 'picked_up' && ($order['order_type'] ?? '') === 'delivery') {
                                        $__qa = ['route' => route('vendor.order.mark-delivered', $order['id']),
                                                  'label' => '已送达', 'cls' => 'btn-success', 'icon' => 'tio-done-all',
                                                  'confirm' => '确认本单已送达顾客？确认后不可撤销。'];
                                    } else {
                                        $__refundPending = \App\Models\NezhaRefundRecord::where('order_id', $order['id'])
                                            ->where('restaurant_id', \App\CentralLogics\Helpers::get_restaurant_id())
                                            ->where('status', 'pending_merchant_refund')
                                            ->exists();
                                        if ($__refundPending) {
                                            $__qa = ['route' => route('vendor.order.mark-refunded', ['id' => $order['id']]),
                                                      'label' => '标记已退款', 'cls' => 'btn-warning', 'icon' => 'tio-receipt-outlined',
                                                      'confirm' => '请确认：您已在自己的账户按原路退还本单顾客的付款？'];
                                        } elseif ($__os === 'refund_requested') {
                                            $__qa = ['type' => 'link', 'route' => route('vendor.order.details',['id'=>$order['id']]),
                                                      'label' => '处理退款申请', 'title' => '查看详情处理退款申请',
                                                      'cls' => 'btn-warning', 'icon' => 'tio-open-in-new'];
                                        } elseif (in_array($__os, ['delivered','canceled','failed','refunded','refund_request_canceled'], true)) {
                                            $__qa = ['type' => 'closed', 'label' => '订单已关闭'];
                                        }
                                    }
                                @endphp
                                @if($__qa && (($__qa['type'] ?? 'form') === 'link'))
                                    <a class="btn btn-sm {{ $__qa['cls'] }} nz-step-btn text-nowrap text-white" href="{{ $__qa['route'] }}" title="{{ $__qa['title'] ?? $__qa['label'] }}">
                                        <i class="{{ $__qa['icon'] }} mr-1"></i>{{ $__qa['label'] }}
                                    </a>
                                @elseif($__qa && (($__qa['type'] ?? 'form') === 'closed'))
                                    <span class="nz-step-empty">{{ $__qa['label'] }}</span>
                                @elseif($__qa)
                                    <form class="nz-order-step-form" method="POST" action="{{ $__qa['route'] }}" style="margin:0"
                                        data-nz-invoice-url="{{route('vendor.order.generate-invoice',[$order['id']])}}?nz_auto_print=1"
                                        data-nz-order-id="{{$order['id']}}"
                                        data-nz-auto-print-action="{{ !empty($__qa['auto_print']) ? '1' : '0' }}"
                                        onsubmit="return confirm('{{ $__qa['confirm'] }}')">
                                        @csrf @method('PUT')
                                        @if(!empty($__qa['extra']))
                                            @foreach($__qa['extra'] as $__k => $__v)
                                                <input type="hidden" name="{{ $__k }}" value="{{ $__v }}">
                                            @endforeach
                                        @endif
                                        <button type="submit" class="btn btn-sm {{ $__qa['cls'] }} nz-step-btn text-nowrap text-white">
                                            <i class="{{ $__qa['icon'] }} mr-1"></i>{{ $__qa['label'] }}
                                        </button>
                                    </form>
                                @else
                                    <span class="nz-step-empty">无需操作</span>
                                @endif
                            </td>
                            <td class="text-center nz-print-action-cell" data-label="打印小票">
                                <a class="btn action-btn btn--primary btn-outline-primary nz-action-icon" target="_blank"
                                    title="打印小票"
                                    href="{{route('vendor.order.generate-invoice',[$order['id']])}}">
                                    <i class="tio-print"></i><span class="nz-mobile-action-label">打印</span>
                                </a>
                            </td>
                            <td class="text-center nz-detail-action-cell" data-label="订单详情">
                                <a class="btn action-btn btn--warning btn-outline-warning nz-action-icon"
                                    title="订单详情"
                                    href="{{route('vendor.order.details',['id'=>$order['id']])}}">
                                    <i class="tio-open-in-new"></i><span class="nz-mobile-action-label">详情</span>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if(count($orders) === 0)
            <div class="empty--data">
                <img src="{{dynamicAsset('assets/admin/img/empty.png')}}" alt="public">
                <h5 class="nz-status-empty-copy">{{ $nzCurrentMeta['empty'] }}</h5>
                <div class="nz-status-empty-help">可切换上方状态，或用订单号搜索历史订单。</div>
            </div>
            @endif
            <!-- End Table -->

            <!-- Footer -->
            <div class="card-footer">
                <!-- Pagination -->
                <div class="row justify-content-center justify-content-sm-between align-items-sm-center">
                    <div class="col-sm-auto">
                        <div class="d-flex justify-content-center justify-content-sm-end">
                            <!-- Pagination -->
                            {!! $orders->links() !!}
                        </div>
                    </div>
                </div>
                <!-- End Pagination -->
            </div>
            <!-- End Footer -->
        </div>
        <!-- End Card -->
    </div>

@endsection

@push('script_2')
    <script>
        "use strict";
        (function(){
            var READY_KEY = 'nzPrintReady';
            var AUTO_KEY = 'nzAutoPrintReady';

            function $(id){ return document.getElementById(id); }

            function isPrintReady(){
                return localStorage.getItem(READY_KEY) === '1';
            }

            function isAutoPrintReady(){
                return isPrintReady() && localStorage.getItem(AUTO_KEY) === '1';
            }

            function applyPrintSettings(){
                var ready = $('nzPrintReady');
                var auto = $('nzAutoPrintReady');
                var panel = $('nzPrintSettings');
                var state = $('nzMobilePrintState');
                if (!ready || !auto) return;
                ready.checked = isPrintReady();
                auto.checked = localStorage.getItem(AUTO_KEY) === '1';
                auto.disabled = !ready.checked;
                if (!ready.checked) {
                    auto.checked = false;
                    localStorage.setItem(AUTO_KEY, '0');
                }
                if (state) {
                    state.textContent = isAutoPrintReady() ? '自动打单已开' : (isPrintReady() ? '已接打印机' : '未开启');
                }
                if (panel && (isPrintReady() || localStorage.getItem(AUTO_KEY) === '1')) {
                    panel.classList.add('nz-print-open');
                }
            }

            function openInvoiceForPrint(url){
                if (!url) return;
                var w = window.open(url, '_blank', 'noopener');
                if (!w) {
                    alert('浏览器拦截了打印窗口，请允许本站弹出窗口后重试。');
                }
            }

            window.nzMaybeAutoPrintAfterOrderAction = function(invoiceUrl){
                if (!isAutoPrintReady() || !invoiceUrl) return;
                sessionStorage.setItem('nzAutoPrintInvoiceUrl', invoiceUrl);
            };

            document.addEventListener('DOMContentLoaded', function(){
                applyPrintSettings();

                var ready = $('nzPrintReady');
                var auto = $('nzAutoPrintReady');
                var testBtn = $('nzTestPrintBtn');
                var mobileToggle = $('nzMobilePrintToggle');

                if (ready) {
                    ready.addEventListener('change', function(){
                        localStorage.setItem(READY_KEY, ready.checked ? '1' : '0');
                        applyPrintSettings();
                    });
                }
                if (auto) {
                    auto.addEventListener('change', function(){
                        if (auto.checked && !isPrintReady()) {
                            alert('请先勾选“已接入并测试打印机”。');
                            auto.checked = false;
                        }
                        localStorage.setItem(AUTO_KEY, auto.checked ? '1' : '0');
                        applyPrintSettings();
                    });
                }
                if (testBtn) {
                    testBtn.addEventListener('click', function(){
                        if (!isPrintReady()) {
                            alert('请先确认本机/云打印机已接入。');
                            return;
                        }
                        var firstPrint = document.querySelector('a[href*="/generate-invoice/"]');
                        if (!firstPrint) {
                            alert('当前没有可测试打印的订单。');
                            return;
                        }
                        openInvoiceForPrint(firstPrint.href + (firstPrint.href.indexOf('?') === -1 ? '?nz_auto_print=1&nz_test_print=1' : '&nz_auto_print=1&nz_test_print=1'));
                    });
                }
                if (mobileToggle) {
                    mobileToggle.addEventListener('click', function(){
                        var panel = $('nzPrintSettings');
                        if (panel) panel.classList.toggle('nz-print-open');
                    });
                }

                var pending = sessionStorage.getItem('nzAutoPrintInvoiceUrl');
                if (pending) {
                    sessionStorage.removeItem('nzAutoPrintInvoiceUrl');
                    if (isAutoPrintReady()) {
                        openInvoiceForPrint(pending);
                    }
                }
            });

            document.addEventListener('submit', function(e){
                var form = e.target;
                if (!form || !form.classList || !form.classList.contains('nz-order-step-form')) return;
                if (e.defaultPrevented) return;
                e.preventDefault();

                var btn = form.querySelector('button[type="submit"]');
                var orig = btn ? btn.innerHTML : '';
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '处理中...';
                }

                var fd = new FormData(form);
                fetch(form.action, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'text/html,application/xhtml+xml,application/xml',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    redirect: 'follow'
                }).then(function(resp){
                    if (!resp.ok) throw new Error('HTTP ' + resp.status);
                    if (form.getAttribute('data-nz-auto-print-action') === '1') {
                        window.nzMaybeAutoPrintAfterOrderAction(form.getAttribute('data-nz-invoice-url'));
                    }
                    setTimeout(function(){ window.location.reload(); }, 60);
                }).catch(function(err){
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = orig;
                    }
                    alert('操作失败：' + (err && err.message ? err.message : '网络错误，请重试'));
                });
            }, false);
        })();

        $(document).on('ready', function () {
            // INITIALIZATION OF NAV SCROLLER
            // =======================================================
            $('.js-nav-scroller').each(function () {
                new HsNavScroller($(this)).init()
            });

            // INITIALIZATION OF SELECT2
            // =======================================================
            $('.js-select2-custom').each(function () {
                let select2 = $.HSCore.components.HSSelect2.init($(this));
            });


            // INITIALIZATION OF DATATABLES
            // =======================================================
            let datatable = $.HSCore.components.HSDatatables.init($('#datatable'), {
                dom: 'Bfrtip',
                buttons: [
                    {
                        extend: 'copy',
                        className: 'd-none'
                    },
                    {
                        extend: 'pdf',
                        className: 'd-none'
                    },
                    {
                        extend: 'print',
                        className: 'd-none'
                    },
                ],
                select: {
                    style: 'multi',
                    selector: 'td:first-child input[type="checkbox"]',
                    classMap: {
                        checkAll: '#datatableCheckAll',
                        counter: '#datatableCounter',
                        counterInfo: '#datatableCounterInfo'
                    }
                },
                language: {
                    zeroRecords: '<div class="text-center p-4">' +
                        '<img class="mb-3 w-7rem" src="{{dynamicAsset('assets/admin')}}/svg/illustrations/sorry.svg" alt="Image Description">' +
                        '<p class="mb-0">{{ translate('No_data_to_show') }}</p>' +
                        '</div>'
                }
            });

            $('#export-copy').click(function () {
                datatable.button('.buttons-copy').trigger()
            });

            $('#export-excel').click(function () {
                datatable.button('.buttons-excel').trigger()
            });

            $('#export-csv').click(function () {
                datatable.button('.buttons-csv').trigger()
            });

            $('#export-pdf').click(function () {
                datatable.button('.buttons-pdf').trigger()
            });

            $('#export-print').click(function () {
                datatable.button('.buttons-print').trigger()
            });

            $('#toggleColumn_order').change(function (e) {
                datatable.columns(1).visible(e.target.checked)
            })

            $('#toggleColumn_date').change(function (e) {
                datatable.columns(2).visible(e.target.checked)
            })

            $('#toggleColumn_customer').change(function (e) {
                datatable.columns(3).visible(e.target.checked)
            })

            $('#toggleColumn_order_status').change(function (e) {
                datatable.columns(5).visible(e.target.checked)
            })


            $('#toggleColumn_total').change(function (e) {
                datatable.columns(4).visible(e.target.checked)
            })

            $('#toggleColumn_actions').change(function (e) {
                datatable.columns(6).visible(e.target.checked)
            })


            // INITIALIZATION OF TAGIFY
            // =======================================================
            $('.js-tagify').each(function () {
                let tagify = $.HSCore.components.HSTagify.init($(this));
            });
        });
    </script>
@endpush
