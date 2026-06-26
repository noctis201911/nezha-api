@extends('layouts.vendor.app')
{{-- 哪吒2026-06-26: 本页订单数据行/操作列禁用 @php() 行内简写(曾触发 Blade 编译畸形致整页500), 一律用 <?php ?> 原生块; 部署侧 nzcheck-blade.php 编译探针兜底 --}}

@section('title',translate('messages.Order List'))

@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- 手机端(<768px)把订单表格重排为卡片, 操作按钮直接露出; PC/平板(>=768px)不命中本媒体查询, 表格原样不变 --}}
    <style>
        @media (max-width: 767.98px) {
            #datatable thead { display: none; }
            #datatable, #datatable tbody { display: block; width: 100%; }
            #datatable tr.class-all {
                display: block;
                background: #fff;
                border: 1px solid #eef0f3;
                border-radius: 12px;
                box-shadow: 0 1px 4px rgba(0,0,0,.04);
                margin-bottom: 12px;
                padding: 4px 14px;
            }
            #datatable tr.class-all td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 12px;
                width: 100%;
                border: 0;
                border-bottom: 1px solid #f3f4f6;
                padding: 9px 0;
                text-align: right;
                white-space: normal;
            }
            #datatable tr.class-all td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #8a94a6;
                text-align: left;
                flex: 0 0 auto;
                white-space: nowrap;
            }
            #datatable tr.class-all td:first-child { display: none; }      /* 序号在卡片里无意义, 隐藏 */
            #datatable tr.class-all td:last-child { border-bottom: 0; }
            #datatable tr.class-all td > * { text-align: right; }
            #datatable tr.class-all td .btn--container { justify-content: flex-end; }
            #datatable tr.class-all td .action-btn { width: 40px; height: 40px; }  /* 加大点击热区 */
        }
    </style>
@endpush

@section('content')
<?php
?>
    <div class="content container-fluid">
        <!-- Page Header -->
        <div class="page-header pt-0 pb-2">
            <div class="d-flex flex-wrap justify-content-between">
                <h2 class="page-header-title align-items-center text-capitalize py-2 mr-2">
                    <div class="card-header-icon d-inline-flex mr-2 img">
                        @if(str_replace('_',' ',$status) == 'All')
                            <img class="mw-24px" src="{{dynamicAsset('assets/admin/img/resturant-panel/page-title/order.png')}}" alt="public">
                        @elseif(str_replace('_',' ',$status) == 'Pending')
                            <img class="mw-24px" src="{{dynamicAsset('assets/admin/img/resturant-panel/page-title/pending.png')}}" alt="public">
                        @elseif(str_replace('_',' ',$status) == 'Confirmed')
                            <img class="mw-24px" src="{{dynamicAsset('assets/admin/img/resturant-panel/page-title/confirm.png')}}" alt="public">
                        @elseif(str_replace('_',' ',$status) == 'Cooking')
                            <img class="mw-24px" src="{{dynamicAsset('assets/admin/img/resturant-panel/page-title/cooking.png')}}" alt="public">
                        @elseif(str_replace('_',' ',$status) == 'Ready for delivery')
                            <img class="mw-24px" src="{{dynamicAsset('assets/admin/img/resturant-panel/page-title/ready.png')}}" alt="public">
                        @elseif(str_replace('_',' ',$status) == 'Food on the way')
                            <img class="mw-24px" src="{{dynamicAsset('assets/admin/img/resturant-panel/page-title/ready.png')}}" alt="public">
                        @elseif(str_replace('_',' ',$status) == 'Delivered')
                            <img class="mw-24px" src="{{dynamicAsset('assets/admin/img/resturant-panel/page-title/ready.png')}}" alt="public">
                        @elseif(str_replace('_',' ',$status) == 'Refunded')
                            <img class="mw-24px" src="{{dynamicAsset('assets/admin/img/resturant-panel/page-title/order.png')}}" alt="public">
                        @elseif(str_replace('_',' ',$status) == 'Scheduled')
                            <img class="mw-24px" src="{{dynamicAsset('assets/admin/img/resturant-panel/page-title/order.png')}}" alt="public">
                        @endif
                    </div>
                    <span>
                        @php $__sl=['All'=>'全部','Pending'=>'待处理','Confirmed'=>'已确认','Cooking'=>'备餐中','Ready_for_delivery'=>'待取餐','Food_on_the_way'=>'配送中','Delivered'=>'已送达','Refunded'=>'已退款','Scheduled'=>'预约中']; @endphp
                        {{$__sl[$status] ?? str_replace('_',' ',$status)}} {{translate('messages.orders')}} <span class="badge badge-soft-dark ml-2">{{$orders->total()}}</span>
                    </span>
                </h2>
            </div>
        </div>
        <!-- End Page Header -->


        <!-- End Page Header -->

        <!-- Card -->
        <div class="card">
            <!-- Header -->
            <div class="card-header py-2">
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
                        <th class="w-90px table-column-pl-0">{{translate('messages.Order_ID')}}</th>
                        <th class="w-140px">{{translate('messages.order_date')}}</th>
                        <th class="text-center w-120px">{{ translate('messages.delivery_type') }}</th>
                        <th class="w-140px">{{translate('messages.customer_information')}}</th>
                        <th class="w-100px">{{translate('messages.total_amount')}}</th>
                        <th class="w-100px text-center">{{translate('messages.order_status')}}</th>
                        <th class="w-100px text-center">{{translate('messages.actions')}}</th>
                    </tr>
                    </thead>

                    <tbody id="set-rows">
                    @foreach($orders as $key=>$order)
                        <tr class="status-{{$order['order_status']}} class-all">
                            <td class="" data-label="{{translate('messages.sl')}}">
                                {{$key+$orders->firstItem()}}
                            </td>
                            <td class="table-column-pl-0" data-label="{{translate('messages.Order_ID')}}">
                                <a href="{{route('vendor.order.details',['id'=>$order['id']])}}" class="text-hover">{{$order['id']}}
                                    @if ($order->is_pos == 1)
                                    <span class="text--warning font-500">({{ translate('POS') }})</span>
                                    @endif
                                </a>
                                @if ($order->edited == 1)
                                <span class="text-info fs-12 d-block font-500">({{ translate('Edited') }})</span>
                                @endif
                                @if($order->details && $order->details->count() > 0)
                                    <div class="text-muted mt-1" style="font-size:11px;max-width:180px;white-space:normal;line-height:1.4">
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
                            <td data-label="{{translate('messages.order_date')}}">
                                <span class="d-block">
                                    {{ Carbon\Carbon::parse($order['created_at'])->locale(app()->getLocale())->translatedFormat('d M Y') }}
                                </span>
                                <span class="d-block text-uppercase">
                                    {{ Carbon\Carbon::parse($order['created_at'])?->locale(app()->getLocale())?->translatedFormat(config('timeformat') ?? 'h:i A') }}
                                </span>
                            </td>
                            @php
                                    $deliveryTypes = [
                                        'express' => ['class' => 'badge-soft-warning', 'label' => 'messages.Express'],
                                        'standard' => ['class' => 'badge-soft-info', 'label' => 'messages.Standard'],
                                        'slightly_delay' => ['class' => 'badge-soft-secondary', 'label' => 'messages.Slightly_Delay'],
                                    ];

                                    $type = $order->delivery_type ? ($deliveryTypes[$order->delivery_type] ?? $deliveryTypes['standard']) : null;
                                @endphp

                                <td class="text-capitalize text-center" data-label="{{translate('messages.delivery_type')}}">
                                    @if($type)
                                        <span class="badge {{ $type['class'] }}">
                                            {{ translate($type['label']) }}
                                        </span>
                                    @endif
                                </td>
                            <td data-label="{{translate('messages.customer_information')}}">
                                @if($order->is_guest)
                                     <?php
                                        $customer_details = json_decode($order['delivery_address'],true);
                                    ?>
                                    <strong>{{$customer_details['contact_person_name']}}</strong>
                                    <div>{{$customer_details['contact_person_number']}}</div>
                                @elseif($order->customer)
                                    <a class="text-body text-capitalize"
                                        href="{{route('vendor.order.details',['id'=>$order['id']])}}">
                                        <span class="d-block font-semibold">
                                                {{$order->customer['f_name'].' '.$order->customer['l_name']}}
                                        </span>
                                        <span class="d-block">
                                                {{$order->customer['phone']}}
                                        </span>
                                    </a>
                                @else
                                    <label
                                        class="badge badge--pending">{{translate('messages.Walk_In_Customer')}}</label>
                                @endif
                            </td>
                            <td data-label="{{translate('messages.total_amount')}}">


                                <div class="text-right mw-85px">
                                    <div>
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
                            <td class="text-capitalize text-center" data-label="{{translate('messages.order_status')}}">
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
                            <td data-label="{{translate('messages.actions')}}">
                                @php
                                    $__os = $order['order_status'];
                                    $__qa = null;
                                    if ($__os === 'pending' && $order->payment_method === 'offline_payment'
                                        && $order->offline_payments && $order->offline_payments->status === 'pending') {
                                        $__qa = ['route' => route('vendor.order.confirm-offline-payment', $order['id']),
                                                  'label' => '确认收款', 'cls' => 'btn-success', 'icon' => 'tio-checkmark-circle',
                                                  'confirm' => '确认：您已在自己的账户收到本单付款？'];
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
                                    }
                                @endphp
                                <div class="btn--container justify-content-center flex-column" style="gap:6px">
                                    @if($__qa)
                                        <form method="POST" action="{{ $__qa['route'] }}" style="margin:0" onsubmit="return confirm('{{ $__qa['confirm'] }}')">
                                            @csrf @method('PUT')
                                            @if(!empty($__qa['extra']))
                                                @foreach($__qa['extra'] as $__k => $__v)
                                                    <input type="hidden" name="{{ $__k }}" value="{{ $__v }}">
                                                @endforeach
                                            @endif
                                            <button type="submit" class="btn btn-sm {{ $__qa['cls'] }} text-nowrap text-white" style="font-size:12px;padding:4px 10px;min-width:90px">
                                                <i class="{{ $__qa['icon'] }} mr-1"></i>{{ $__qa['label'] }}
                                            </button>
                                        </form>
                                    @endif
                                    <div class="d-flex justify-content-center" style="gap:4px">
                                        <a class="btn action-btn btn--warning btn-outline-warning" href="{{route('vendor.order.details',['id'=>$order['id']])}}"><i class="tio-visible-outlined"></i></a>
                                        <a class="btn action-btn btn--primary btn-outline-primary" target="_blank" href="{{route('vendor.order.generate-invoice',[$order['id']])}}"><i class="tio-print"></i></a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if(count($orders) === 0)
            <div class="empty--data">
                <img src="{{dynamicAsset('assets/admin/img/empty.png')}}" alt="public">
                <h5>
                    {{translate('no_data_found')}}
                </h5>
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
