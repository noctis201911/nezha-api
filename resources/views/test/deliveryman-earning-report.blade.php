@extends('layouts.admin.app')
@section('title', translate('messages.Delivery_Man_Earning_Report'))

@section('content')

    <div class="content container-fluid">
        <!-- Page Header -->
        <div class="page-header pb-0">
            <div>
                <h1 class="page-header-title text-capitalize">
                    {{translate('messages.Delivery_Man_Earning_Report') }}
                </h1>
                <p>
                    {{translate('messages.Comprehensive_financial_overview_and_analytics')}}
                </p>
            </div>
        </div>
        <!-- End Page Header -->

        <div class="card card-body mb-20">
            <h3 class="mb-20">{{ translate('messages.Filter_Data') }}</h3>
            <form action="">
                <div class="__bg-F8F9FC-card">
                    <div class="row g-3 date-filter-wrapper">
                        <div class="col-lg-6">
                            <label for="" class="input-label text-capitalize">
                                {{ translate('messages.Select_Delivery_Man') }}
                            </label>
                            <select name="" id="" class="form-control js-select2-custom">
                                <option value="" selected>{{ translate('messages.All_Delivery_Man') }}</option>
                                <option value="">Test</option>
                            </select>
                        </div>
                        <div class="col-lg-6">
                            <label for="" class="input-label text-capitalize">
                                {{ translate('messages.Date_Range') }}
                            </label>
                            <select name="" id="" class="form-control custom-select date-type-select">
                                <option value="all" selected>{{ translate('messages.All_Time') }}</option>
                                <option value="month">{{ translate('messages.This_Month') }}</option>
                                <option value="year">{{ translate('messages.This_Year') }}</option>
                                <option value="custom">{{ translate('messages.Custom_Range') }}</option>
                            </select>
                        </div>
                        <div class="col-lg-6 custom-date-div d--none">
                            <label for="" class="input-label text-capitalize">
                                {{ translate('messages.Start_Date') }} <span class="text-danger">*</span> 
                                <span data-toggle="tooltip" data-placement="right"
                                        data-original-title="{{ translate('messages.Enter_Start_Date')}}"
                                        class="input-label-secondary tio-info fs-16 m-0"></span>
                            </label>
                            <input type="date" name="" id="" class="form-control">
                        </div>
                        <div class="col-lg-6 custom-date-div d--none">
                            <label for="" class="input-label text-capitalize">
                                {{ translate('messages.End_Date') }} <span class="text-danger">*</span> 
                                <span data-toggle="tooltip" data-placement="right"
                                        data-original-title="{{ translate('messages.Enter_End_Date')}}"
                                        class="input-label-secondary tio-info fs-16 m-0"></span>
                            </label>
                            <input type="date" name="" id="" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="btn--container mt-4 justify-content-end">
                    <button id="resetbtn" type="reset"
                        class="btn btn--reset">{{ translate('messages.reset') }}</button>
                    <button type="submit" class="btn btn--primary">{{ translate('messages.filter') }}</button>
                </div>
            </form>
        </div>
        
        <div class="card card-body mb-20">
            <div class="mb-3">
                <h3 class="mb-1">{{ translate('messages.Earnings_Summary') }}</h3>
                <p class="fs-12 mb-0">{{ translate('messages.Breakdown of Revenue Sources and Performance') }}</p>
            </div>
            <div class="row g-3 mb-20">
                <div class="col-lg-4">
                    <div class="bg-success-gradient text-white rounded-10 p-3 p-xxl-20 d-flex gap-2 justify-content-between align-items-start overflow-wrap-anywhere cursor-pointer redirect-url" data-url="">
                        <div class="flex-grow-1">
                            <div class="opacity-lg">{{ translate('messages.Total_Earnings') }}</div>
                            <h2 class="font-medium fs-32 fs-18-mobile text-white mb-0">$84,000.00</h2>
                            <div class="opacity-lg">↑ 12.5% {{ translate('messages.vs last period') }}</div>
                        </div>
                        <div class="flex-shrink-0 bg-white rounded-10 w-48 aspect-1-1 d-flex justify-content-center align-items-center" style="--bs-bg-opacity: 0.2;">
                            <img width="24" src="{{dynamicAsset('assets/admin/img/report/new/earning.png')}}" alt="earning">
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="bg-warning-gradient text-white rounded-10 p-3 p-xxl-20 d-flex gap-2 justify-content-between align-items-start overflow-wrap-anywhere cursor-pointer redirect-url" data-url="">
                        <div class="flex-grow-1">
                            <div class="opacity-lg">{{ translate('messages.Commissions_Paid') }}</div>
                            <h2 class="font-medium fs-32 fs-18-mobile text-white mb-0">$4,000.00</h2>
                            <div class="opacity-lg">↓ 3.2% {{ translate('messages.vs last period') }}</div>
                        </div>
                        <div class="flex-shrink-0 bg-white rounded-10 w-48 aspect-1-1 d-flex justify-content-center align-items-center" style="--bs-bg-opacity: 0.2;">
                            <img width="24" src="{{dynamicAsset('assets/admin/img/report/new/earning.png')}}" alt="earning">
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="bg-info-gradient text-white rounded-10 p-3 p-xxl-20 d-flex gap-2 justify-content-between align-items-start overflow-wrap-anywhere cursor-pointer redirect-url" data-url="">
                        <div class="flex-grow-1">
                            <div class="opacity-lg">{{ translate('messages.Net_Profit') }}</div>
                            <h2 class="font-medium fs-32 fs-18-mobile text-white mb-0">$80,000.00</h2>
                            <div class="opacity-lg">↑ 18.7% {{ translate('messages.vs last period') }}</div>
                        </div>
                        <div class="flex-shrink-0 bg-white rounded-10 w-48 aspect-1-1 d-flex justify-content-center align-items-center" style="--bs-bg-opacity: 0.2;">
                            <img width="24" src="{{dynamicAsset('assets/admin/img/report/new/wallet.png')}}" alt="profit">
                        </div>
                    </div>
                </div>
            </div>
            <h4 class="mb-3">{{ translate('messages.Earnings_Breakdown') }}</h4>
            <div class="border rounded-10 p-3 p-xxl-20">
                <div class="row g-lg-5 gx-3 gy-3 earnings-breakdown">
                    <div class="col-lg-4 col-sm-6">
                        <div class="item border-right">
                             <div class="flex-shrink-0 purple rounded-10 w-40px aspect-1-1 d-flex justify-content-center align-items-center mb-3">
                                <img src="{{dynamicAsset('assets/admin/img/report/earning-breakdown/total-delivery-charge.svg')}}" alt="earning">
                            </div>
                            <div class="mb-2">{{ translate('messages.Total Delivery Charge') }}</div>
                            <h2 class="font-medium fs-24 fs-18-mobile mb-2">$42,500.00</h2>
                            <div class="fs-12 bg-light px-2 py-1 rounded-lg w-max-content">50.6% {{ translate('messages.of Total') }}</div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-sm-6">
                        <div class="item border-right">
                             <div class="flex-shrink-0 pink rounded-10 w-40px aspect-1-1 d-flex justify-content-center align-items-center mb-3">
                                <img src="{{dynamicAsset('assets/admin/img/report/earning-breakdown/total-incentives.svg')}}" alt="earning">
                            </div>
                            <div class="mb-2">{{ translate('messages.Total Incentives') }}</div>
                            <h2 class="font-medium fs-24 fs-18-mobile mb-2">$42,500.00</h2>
                            <div class="fs-12 bg-light px-2 py-1 rounded-lg w-max-content">50.6% {{ translate('messages.of Total') }}</div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-sm-6">
                        <div class="item">
                             <div class="flex-shrink-0 success rounded-10 w-40px aspect-1-1 d-flex justify-content-center align-items-center mb-3">
                                <img src="{{dynamicAsset('assets/admin/img/report/earning-breakdown/total-tips.svg')}}" alt="earning">
                            </div>
                            <div class="mb-2">{{ translate('messages.Total Tips') }}</div>
                            <h2 class="font-medium fs-24 fs-18-mobile mb-2">$42,500.00</h2>
                            <div class="fs-12 bg-success text-success bg-opacity-10 font-medium px-2 py-1 rounded-lg w-max-content"><i class="tio-trending-up"></i> 12.5% {{ translate('messages.vs last Period') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card h-100 mb-20">
            <div class="card-header border-0 d-block pb-0">
                <h3 class="mb-1 text-title">{{ translate('messages.Delivery Man Earnings Trend') }}</h3>
                <p class="mb-1">{{ translate('messages.Revenue performance over time') }}</p>
            </div>
            <div class="card-body px-1 px-sm-2 py-0">
                <div id="earning-trend-chart"></div>
            </div>
        </div>
        <div class="card card-body">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap border-0">
                <h3 class="mb-0">{{ translate('messages.Recent_Transactions') }}</h3>
                <div class="search--button-wrapper justify-content-end">
                    <form>
                        <!-- Search -->
                        <div class="input--group input-group input-group-merge input-group-flush">
                            <input id="datatableSearch_" type="search" name="search" class="form-control"
                                value=""
                                placeholder="{{ translate('Search_here') }}" aria-label="Search" required>
                            <button type="submit" class="btn btn--secondary">
                                <i class="tio-search"></i>
                            </button>
                        </div>
                        <!-- End Search -->
                    </form>
                    <div class="d-flex flex-wrap gpa-3 justify-content-sm-end align-items-sm-center ml-0 mr-0 flex-grow-0">
                        <!-- Unfold -->
                        <div class="hs-unfold mr-2">
                            <a class="js-hs-unfold-invoker btn btn-sm btn-outline-primary dropdown-toggle min-height-40 fs-14"
                                href="javascript:;"
                                data-hs-unfold-options='{
                                    "target": "#usersExportDropdown",
                                    "type": "css-animation"
                                }'>
                                <i class="tio-download-to mr-1"></i> {{ translate('messages.export') }}
                            </a>

                            <div id="usersExportDropdown"
                                class="hs-unfold-content dropdown-unfold dropdown-menu dropdown-menu-sm-right">
                                <span class="dropdown-header">{{ translate('messages.download_options') }}</span>
                                <a id="export-excel" class="dropdown-item"
                                    href="{{ route('admin.customer.overview.export', ['type' => 'excel', request()->getQueryString()]) }}">
                                    <img class="avatar avatar-xss avatar-4by3 mr-2"
                                        src="{{ dynamicAsset('assets/admin') }}/svg/components/excel.svg"
                                        alt="Image Description">
                                    {{ translate('messages.excel') }}
                                </a>
                                <a id="export-csv" class="dropdown-item"
                                    href="{{ route('admin.customer.overview.export', ['type' => 'csv', request()->getQueryString()]) }}">
                                    <img class="avatar avatar-xss avatar-4by3 mr-2"
                                        src="{{ dynamicAsset('assets/admin') }}/svg/components/placeholder-csv-format.svg"
                                        alt="Image Description">
                                    .{{ translate('messages.csv') }}
                                </a>
                            </div>
                        </div>
                        <!-- End Unfold -->
                    </div>
                </div>
                <!-- End Row -->
            </div>
            <!-- End Header -->

            <!-- Table -->
            <div class="table-responsive datatable-custom">
                <table id="datatable"
                    class="table table-hover table-borderless table-thead-bordered table-nowrap table-align-middle card-table text-dark"
                    data-hs-datatables-options='{
                    "columnDefs": [{
                        "targets": [0],
                        "orderable": false
                    }],
                    "order": [],
                    "info": {
                    "totalQty": "#datatableWithPaginationInfoTotalQty"
                    },
                    "search": "#datatableSearch",
                    "entries": "#datatableEntries",
                    "pageLength": 25,
                    "isResponsive": false,
                    "isShowPaging": false,
                    "paging":false
                }'>
                    <thead class="thead-light">
                        <tr>
                            <th class="border-0">
                                {{ translate('SL') }}
                            </th>
                            <th class="table-column-pl-0 border-0">{{ translate('messages.Order_ID') }}</th>
                            <th class="border-0">{{ translate('messages.Order_Date') }}</th>
                            <th class="border-0">{{ translate('messages.Delivery_Man') }}</th>
                            <th class="border-0 text-right">{{ translate('messages.Delivery_Charge') }}</th>
                            <th class="border-0 text-right">{{ translate('messages.Incentive') }}</th>
                            <th class="border-0 text-right">{{ translate('messages.Tips') }}</th>
                            <th class="border-0 text-right">{{ translate('messages.Commission_Paid') }}</th>
                            <th class="border-0 text-right">{{ translate('messages.Net_Profit') }}</th>
                        </tr>
                    </thead>
                    <tbody id="set-rows">
                        <tr>
                            <td>1</td>
                            <td class="font-medium">100024</td>
                            <td>
                                28 Dec 2024
                                <br>
                                11:09 pm
                            </td>
                            <td>Wade Wilson</td>
                            <td class="text-right">$450.00</td>
                            <td class="text-right">$450.00</td>
                            <td class="text-right">$450.00</td>
                            <td class="text-right">$450.00</td>
                            <td class="text-right">$450.00</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            {{-- <div class="empty--data">
                <img src="{{ dynamicAsset('assets/admin/img/empty.png') }}" alt="public">
                <h5>
                    {{ translate('no_data_found') }}
                </h5>
            </div> --}}
            <!-- End Table -->
            {{-- <div class="page-area px-4 pb-3">
                <div class="d-flex align-items-center justify-content-end">
                    <div>
                        {!! $customers->withQueryString()->links() !!}
                    </div>
                </div>
            </div> --}}
            <!-- End Footer -->

        </div>
    </div>
@endsection

@push('script_2')
    <script src="{{dynamicAsset('assets/admin/apexcharts/apexcharts.min.js')}}"></script>
    <script src="{{dynamicAsset('assets/admin/js/view-pages/apex-charts.js')}}"></script>
    <script>
        let earningTrendChart;
        
        function initEarningTrendStatisticsChart() {

            const chartElement = document.getElementById('earning-trend-chart');
            if (!chartElement) return;

            if (earningTrendChart) {
                earningTrendChart.destroy();
                earningTrendChart = null;
            }

            // Demo months
            const categories = [
                'Jan','Feb','Mar','Apr','May','Jun',
                'Jul','Aug','Sep','Oct','Nov','Dec'
            ];

            // Demo earnings data
            const seriesData = [120, 180, 150, 220, 260, 210, 300, 280, 320, 350, 370, 390];

            const options = {
                series: [{
                    name: '',
                    data: seriesData
                }],
                chart: {
                    height: 350,
                    type: 'line',
                    toolbar: { show: false }
                },
                colors: ['#019463'],
                stroke: {
                    width: 2,
                    curve: 'smooth'
                },
                dataLabels: {
                    enabled: false
                },
                xaxis: {
                    categories: categories,
                },
                yaxis: {
                    min: 0,
                    max: 400,
                    tickAmount: 4,
                    labels: {
                        offsetX: -10
                    }
                },
                grid: {
                    strokeDashArray: 4
                },
                tooltip: {
                    theme: 'dark',
                    shared: false,
                    x: {
                        show: false
                    },
                    y: {
                        formatter: function (val, opts) {
                            const month = opts.w.globals.categoryLabels[opts.dataPointIndex];
                            return month + ' : ' + val;
                        }
                    }
                }
            };

            earningTrendChart = new ApexCharts(chartElement, options);
            earningTrendChart.render();
        }

        function initEarningVsExpensechart() {
            let earning = [20000, 35000, 42000, 38000, 55000, 60000, 75000, 82000, 70000, 65000, 90000, 95000];
            let expense = [15000, 25000, 30000, 28000, 40000, 42000, 50000, 52000, 48000, 45000, 60000, 65000];

            let columnWidth = window.innerWidth <= 768 ? '8px' : '13px';

            let options = {
                series: [
                    { name: "Earning", data: earning },
                    { name: "Expense", data: expense }
                ],
                chart: {
                    type: 'bar',
                    height: 350,
                    toolbar: { show: false }
                },
                plotOptions: {
                    bar: {
                        horizontal: false,
                        columnWidth: columnWidth,
                        borderRadius: 5,
                        borderRadiusApplication: 'end',
                    }
                },
                colors: ['#059669E5','#D97706E5'],
                xaxis: {
                    categories: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
                },
                yaxis: {
                    min: 0,
                    max: 100000,
                    tickAmount: 4,
                    labels: {
                        formatter: function(val){ return (val/1000) + "k"; }
                    }
                },
                dataLabels: { enabled: false },
                grid: { borderColor:'#e5e7eb' },
                tooltip: {
                    y: {
                        formatter: function(val){ return val.toLocaleString(); }
                    }
                }
            };

            let chart = new ApexCharts(
                document.querySelector("#monthly-earning-expense-graph"),
                options
            );

            chart.render();
        }

        $(document).ready(function () {

            initEarningTrendStatisticsChart();

            function toggleCustomDate(wrapper) {
                let selectValue = wrapper.find('.date-type-select').val();

                if (selectValue === 'custom') {
                    wrapper.find('.custom-date-div').slideDown(200);
                } else {
                    wrapper.find('.custom-date-div').slideUp(200);
                }
            }

            $(document).on('change', '.date-type-select', function () {
                let wrapper = $(this).closest('.date-filter-wrapper');
                toggleCustomDate(wrapper);
            });

            $('.date-filter-wrapper').each(function () {
                toggleCustomDate($(this));
            });

        });

    </script>
@endpush
