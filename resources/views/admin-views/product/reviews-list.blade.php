@extends('layouts.admin.app')

@section('title',translate('Review_List'))

@push('css_or_js')

@endpush

@section('content')
    <div class="content container-fluid">
        <!-- Page Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-sm mb-2 mb-sm-0">
                    <h1 class="page-header-title">{{translate('messages.food_reviews')}}<span class="badge badge-soft-dark ml-2" id="itemCount">{{$reviews->total()}}</span></h1>
                </div>
            </div>
        </div>
        <!-- End Page Header -->
        <div class="row gx-2 gx-lg-3">
            <div class="col-sm-12 col-lg-12 mb-3 mb-lg-2">
                <!-- Card -->
                <div class="card">

                <!-- Header -->
                <div class="card-header border-0 py-2">
                    <div class="search--button-wrapper justify-content-end">
                        <form  class="search-form">
                            <!-- Search -->
                            <div class="input-group input--group">
                                <input id="datatableSearch" name="search" value="{{ request()?->search ?? null }}" type="search" class="form-control in-height-45" placeholder="{{translate('ex_:_search_item_name')}}" aria-label="{{translate('messages.search_here')}}">
                                <button type="submit" class="btn btn--secondary in-height-45"><i class="tio-search"></i></button>
                            </div>
                            <!-- End Search -->
                        </form>
                        <div class="hs-unfold mr-2">
                            <a class="js-hs-unfold-invoker btn btn-sm btn-white dropdown-toggle min-height-40" href="javascript:"
                                data-hs-unfold-options='{
                                        "target": "#usersExportDropdown",
                                        "type": "css-animation"
                                    }'>
                                <i class="tio-download-to mr-1"></i> {{ translate('messages.export') }}
                            </a>

                            <div id="usersExportDropdown"
                                class="hs-unfold-content dropdown-unfold dropdown-menu dropdown-menu-sm-right">

                                <span class="dropdown-header">{{ translate('messages.download_options') }}</span>
                                <a id="export-excel" class="dropdown-item" href="{{ route('admin.food.reviews_export', ['type' => 'excel', request()->getQueryString()]) }}">
                                    <img class="avatar avatar-xss avatar-4by3 mr-2"
                                        src="{{ dynamicAsset('assets/admin') }}/svg/components/excel.svg"
                                        alt="Image Description">
                                    {{ translate('messages.excel') }}
                                </a>
                                <a id="export-csv" class="dropdown-item" href="{{ route('admin.food.reviews_export', ['type' => 'csv', request()->getQueryString()]) }}">
                                    <img class="avatar avatar-xss avatar-4by3 mr-2"
                                        src="{{ dynamicAsset('assets/admin') }}/svg/components/placeholder-csv-format.svg"
                                        alt="Image Description">
                                    .{{ translate('messages.csv') }}
                                </a>

                            </div>
                        </div>
                    </div>
                </div>
                <!-- End Header -->

                <div class="px-3 py-2 border-bottom d-flex gap-2 align-items-center">
                    @php $sf = $statusFilter ?? 'all'; @endphp
                    <a href="{{ route('admin.food.reviews', ['status_filter'=>'all']) }}" class="btn btn-sm {{ $sf==='all'?'btn--primary':'btn-outline-secondary' }}">{{ translate('messages.all') }}</a>
                    <a href="{{ route('admin.food.reviews', ['status_filter'=>'pending']) }}" class="btn btn-sm {{ $sf==='pending'?'btn--primary':'btn-outline-secondary' }}">{{ translate('messages.pending_review') }} <span class="badge badge-soft-danger">{{ $pendingCount ?? 0 }}</span></a>
                    <a href="{{ route('admin.food.reviews', ['status_filter'=>'rejected']) }}" class="btn btn-sm {{ $sf==='rejected'?'btn--primary':'btn-outline-secondary' }}">{{ translate('messages.review_rejected') }}</a>
                </div>

                    <div class="card-body p-0">
                        <!-- Table -->
                        <div class="table-responsive datatable-custom">
                            <table id="columnSearchDatatable"
                                class="table table-borderless table-thead-bordered table-nowrap card-table"
                                data-hs-datatables-options='{
                                    "order": [],
                                    "orderCellsTop": true,
                                    "paging": false
                                }'>
                                <thead class="thead-light">
                                <tr>
                                    <th>{{ translate('messages.sl') }}</th>
                                    <th class="w-10p">{{translate('messages.food')}}</th>
                                    <th class="w-20p">{{translate('messages.customer')}}</th>
                                    <th class="w-30p">{{translate('messages.review')}}</th>
                                    <th>{{translate('messages.date')}}</th>
                                    <th class="w-30p text-center">{{translate('messages.restaurant_reply')}}</th>
                                    <th>{{translate('messages.action')}}</th>
                                </tr>
                                </thead>

                                <tbody>
                                @foreach($reviews as $key=>$review)
                                    <tr>
                                        <td>{{$key+$reviews->firstItem()}}</td>

                                        <td>
                                            <div class="gap-2 d-flex">
                                                @if ($review->food)
                                                    <a class="media align-items-center mb-1 gap-2 min-w-48" href="{{route('admin.food.view',[$review->food['id']])}}">
                                                        <img class="avatar avatar-lg onerror-image"
                                                             src="{{ $review->food['image_full_url'] }}"
                                                             data-onerror-image="{{dynamicAsset('assets/admin/img/100x100/food-default-image.png')}}"
                                                             alt="{{ $review->food['name'] }} image">

                                                    </a>
                                                <div class="py-2">
                                                    <a class="media align-items-center mb-1 gap-2" href="{{route('admin.food.view',[$review->food['id']])}}">
                                                        <div class="media-body">
                                                            <h5 class="text-hover-primary mb-0">{{Str::limit($review->food['name'],20,'...')}}</h5>
                                                        </div>
                                                    </a>
                                                    @if($review->order_id)
                                                    <a class="mr-5 text-body" href="{{route('admin.order.details',['id'=>$review->order_id])}}"> {{ translate('Order_ID') }}: {{$review->order_id}}</a>
                                                    @endif
                                                </div>
                                                @else
                                                    {{translate('messages.Food_deleted!')}}
                                                @endif
                                            </div>
                                        </td>
                                        <td>
                                            @if ($review->customer)
                                                <a href="{{route('admin.customer.view',[$review->user_id])}}">
                                                    {{$review->customer?$review->customer->f_name:""}} {{$review->customer?$review->customer->l_name:""}}
                                                </a>
                                                <p>
                                                   {{$review->customer?$review->customer->phone:""}}
                                                </p>
                                            @else
                                                {{translate('messages.customer_not_found')}}
                                            @endif
                                        </td>
                                        <td>
                                            <label class="rating">
                                                {{$review->rating}} <i class="tio-star m-sm-auto"></i>
                                            </label>
                                            <p class="text-wrap min-w-170px" data-toggle="tooltip" data-placement="left"
                                               data-original-title="{{ $review?->comment }}">{!! $review->comment?Str::limit($review->comment, 30, '...'):'' !!}</p>
                                            @php $atts = is_array($review->attachment)?$review->attachment:(json_decode($review->attachment,true)?:[]); @endphp
                                            @if(count($atts))
                                                <div class="d-flex gap-1 mt-1 flex-wrap">
                                                    @foreach($atts as $att)
                                                        <a href="{{ \App\CentralLogics\Helpers::get_full_url('review', basename($att), 'public') }}" target="_blank">
                                                            <img class="onerror-image" src="{{ \App\CentralLogics\Helpers::get_full_url('review', basename($att), 'public') }}" data-onerror-image="{{dynamicAsset('assets/admin/img/100x100/food-default-image.png')}}" alt="review image" style="width:48px;height:48px;object-fit:cover;border-radius:6px;">
                                                        </a>
                                                    @endforeach
                                                </div>
                                            @endif
                                            @if($review->status==3)<span class="badge badge-soft-warning mt-1">{{ translate('messages.pending_review') }}</span>@endif
                                            @if($review->status==4)<span class="badge badge-soft-danger mt-1">{{ translate('messages.review_rejected') }}@if($review->reject_reason): {{ $review->reject_reason }}@endif</span>@endif
                                        </td>
                                        <td class="text-uppercase">
                                            <div>
                                                {{ \App\CentralLogics\Helpers::date_format($review->created_at)  }}

                                            </div>
                                            <div>
                                                {{ \App\CentralLogics\Helpers::time_format($review->created_at)  }}
                                            </div>
                                        </td>
                                        <td>
                                            <p class="text-wrap text-center" data-toggle="tooltip" data-placement="top"
                                               data-original-title="{{ $review?->reply }}">{{ $review->reply?Str::limit(strip_tags($review->reply), 50, '...'): translate('messages.Not_replied_Yet') }}</p>
                                        </td>
                                        <td>
                                            @if($review->status==3)
                                                <a href="{{ route('admin.food.reviews.approve',[$review['id']]) }}" class="btn btn-sm btn--primary mb-1 d-block">{{ translate('messages.review_btn_pass') }}</a>
                                                <button type="button" class="btn btn-sm btn-outline-danger mb-1 d-block reject-btn" data-id="{{ $review['id'] }}">{{ translate('messages.review_btn_reject') }}</button>
                                                <form action="{{ route('admin.food.reviews.reject',[$review['id']]) }}" method="post" id="reject-{{$review['id']}}">
                                                    @csrf
                                                    <input type="hidden" name="reject_reason" id="reject-reason-{{$review['id']}}">
                                                </form>
                                            @else
                                                <label class="toggle-switch toggle-switch-sm" for="reviewCheckbox{{$review->id}}">
                                                    <input type="checkbox"
                                                           data-id="status-{{ $review['id'] }}" data-message="{{ $review->status ? translate('messages.you_want_to_hide_this_review_for_customer') : translate('messages.you_want_to_show_this_review_for_customer') }}"
                                                           class="toggle-switch-input status_form_alert" id="reviewCheckbox{{ $review->id }}"
                                                            {{ ($review->status==1) ? 'checked' : '' }}>
                                                    <span class="toggle-switch-label">
                                                        <span class="toggle-switch-indicator"></span>
                                                    </span>
                                                </label>
                                                <form action="{{route('admin.food.reviews.status',[$review['id'],($review->status==1)?0:1])}}" method="get" id="status-{{$review['id']}}">
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            @if(count($reviews) === 0)
                            <div class="empty--data">
                                <img src="{{dynamicAsset('assets/admin/img/empty.png')}}" alt="public">
                                <h5>
                                    {{translate('no_data_found')}}
                                </h5>
                            </div>
                            @endif
                            <div class="page-area px-4 pb-3">
                                <div class="d-flex align-items-center justify-content-end">
                                    <div>
                                        {!! $reviews->links() !!}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- End Table -->
                    </div>
                </div>
                <!-- End Card -->
            </div>
        </div>
    </div>

@endsection

@push('script_2')
    <script>
        "use strict";
        $(document).on('ready', function () {
            // INITIALIZATION OF DATATABLES
            // =======================================================
            let datatable = $.HSCore.components.HSDatatables.init($('#columnSearchDatatable'));

        });

        $(".status_form_alert").on("click", function (e) {
            const id = $(this).data('id');
            const message = $(this).data('message');
            e.preventDefault();
            Swal.fire({
                title: '{{translate('messages.Are_you_sure_?')}}',
                text: message,
                type: 'warning',
                showCancelButton: true,
                cancelButtonColor: 'default',
                confirmButtonColor: '#FC6A57',
                cancelButtonText: '{{ translate('no') }}',
                confirmButtonText: '{{ translate('yes') }}',
                reverseButtons: true
            }).then((result) => {
                if (result.value) {
                    $('#'+id).submit()
                }
            })
        })

        $(".reject-btn").on("click", function (e) {
            const id = $(this).data('id');
            e.preventDefault();
            Swal.fire({
                title: '{{translate('messages.reject_reason')}}',
                input: 'text',
                inputPlaceholder: '{{translate('messages.reject_reason')}}',
                showCancelButton: true,
                cancelButtonColor: 'default',
                confirmButtonColor: '#FC6A57',
                cancelButtonText: '{{ translate('no') }}',
                confirmButtonText: '{{ translate('yes') }}',
                reverseButtons: true
            }).then((result) => {
                if (result.value !== undefined) {
                    $('#reject-reason-'+id).val(result.value || '');
                    $('#reject-'+id).submit();
                }
            })
        })
    </script>
@endpush
