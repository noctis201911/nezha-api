<!DOCTYPE html>
<?php
    if (env('APP_MODE') == 'demo') {
        $site_direction = session()->get('site_direction_vendor');
    }else{
        $site_direction = session()->has('vendor_site_direction')?session()->get('vendor_site_direction'):'ltr';
    }
    $country=\App\Models\BusinessSetting::where('key','country')->first();
            $countryCode= strtolower($country?$country->value:'auto');
?>
<html dir="{{ $site_direction }}" lang="{{ str_replace('_', '-', app()->getLocale()) }}"  class="{{ $site_direction === 'rtl'?'active':'' }}"><head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <!-- Title -->
    <title>@yield('title')</title>
    <!-- Favicon -->
    @php($logo=\App\Models\BusinessSetting::where(['key'=>'icon'])->first()->value)
    {{-- A5: 删除空 href 的冗余 favicon link(避免空href向当前页发请求);真 favicon 见下一行 --}}
    <link rel="icon" type="image/x-icon" href="{{ asset(dynamicStorage('storage/app/public/business/'.$logo??'')) }}">
    <!-- Font -->
    <link href="{{dynamicAsset('assets/admin/css/fonts.css')}}" rel="stylesheet">
    <!-- CSS Implementing Plugins -->
    <link rel="stylesheet" href="{{dynamicAsset('assets/admin/css/vendor.min.css')}}">
    <link rel="stylesheet" href="{{dynamicAsset('assets/admin/vendor/icon-set/style.css')}}">
    <!-- CSS Front Template -->
    <link rel="stylesheet" href="{{dynamicAsset('assets/admin/css/owl.min.css')}}">
    <link rel="stylesheet" href="{{ dynamicAsset('assets/admin/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset('assets/admin/css/theme.minc619.css?v=1.0') }}">
    <link rel="stylesheet" href="{{ dynamicAsset('assets/admin/css/style.css') }}">
    <link  rel="stylesheet" href="{{dynamicAsset('assets/admin/plugins/lightbox/css/lightbox.css')}}">
    <!-- Provider Panel Update CSS -->
    <link rel="stylesheet" href="{{dynamicAsset('assets/admin/css/vendor.css')}}">
    <link rel="stylesheet" href="{{dynamicAsset('assets/admin/intltelinput/css/intlTelInput.css')}}">

    @stack('css_or_js')

    <script src="{{dynamicAsset('assets/admin/vendor/hs-navbar-vertical-aside/hs-navbar-vertical-aside-mini-cache.js')}}"></script>
    <link rel="stylesheet" href="{{dynamicAsset('assets/admin/css/toastr.css')}}">
    {{-- 哪吒 UX-1 C: 商家端字体 — 原 style.css --bs-body-font-family=Inter,serif 致中文落宋体发糊(尤其胶囊按钮②)。业主 0708 定:全部(含数字/字母)走华人常用字体。改雅黑/苹方优先栈: Windows→微软雅黑·Mac→苹方,数字字母同字体,֏ 走 Segoe UI/系统兜底防豆腐块。表单/按钮不继承 body 字体故显式覆盖。放 </head> 尾覆盖 style.css。 --}}
    <style>
        :root { --bs-body-font-family: "Microsoft YaHei","PingFang SC","Noto Sans SC","Hiragino Sans GB","Heiti SC","Segoe UI",sans-serif; }
        body, button, input, select, textarea, optgroup, .btn { font-family: var(--bs-body-font-family); }
        html, body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
    </style>
</head>

<body class="footer-offset">

    @if (env('APP_MODE')=='demo')
    <div class="direction-toggle">
        <i class="tio-settings"></i>
        <span></span>
    </div>
    @endif

    <div id="pre--loader" class="pre--loader">
    </div>
{{--loader--}}
<div class="container">
    <div class="row">
        <div class="col-md-12">
            <div id="loading" class="initial-hidden">
                <div class="loading--1">
                    <img width="200" src="{{dynamicAsset('assets/admin/img/loader.gif')}}">
                </div>
            </div>
        </div>
    </div>
</div>
{{--loader--}}

<!-- Builder -->
@include('layouts.vendor.partials._front-settings')
<!-- End Builder -->

<!-- JS Preview mode only -->
@include('layouts.vendor.partials._sidebar')
<!-- END ONLY DEV -->

<main id="content" role="main" class="main pointer-event">
@include('layouts.vendor.partials._header')
    <!-- Content -->
@yield('content')
<!-- End Content -->

    <!-- Footer -->
@include('layouts.vendor.partials._footer')
<!-- End Footer -->

    <div class="modal fade" id="popup-modal">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-12">
                            <div class="text-center">
                                <h2 class="color-8a8a8a">
                                    <i class="tio-shopping-cart-outlined"></i> {{translate('messages.You have new order, Check Please.')}}
                                </h2>
                                <hr>
                                <button  class="btn btn-primary check-order">{{translate('messages.Ok, let me check')}}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- 哪吒#4: 商家面板提示浮层移动端响应式——窄屏改顶部锚定+全宽细条, 绝不遮挡底部操作按钮(确认收款/接单/出餐) --}}
    <style>
    /* 哪吒[2026-07-02]: 后端弹窗默认垂直居中——原 Bootstrap 默认贴顶(margin 1.75rem auto)显得"太高", 商家反馈全后端弹窗都偏高。
       用 Bootstrap modal-dialog-centered 同款机制统一居中; 已显式带 .modal-dialog-centered 的不重复处理。短弹窗居中, 超高弹窗仍可随 .modal 滚动。 */
    .modal-dialog:not(.modal-dialog-centered){ display:flex; align-items:center; min-height:calc(100% - 3.5rem); }
    #nz-alert-stack {
        position: fixed;
        right: 20px;
        bottom: 20px;
        z-index: 100000;
        display: flex;
        flex-direction: column-reverse;
        gap: 12px;
        align-items: flex-end;
        pointer-events: none;
    }
    #nz-alert-stack > #nz-new-order-toast,
    #nz-alert-stack > #nz-timeout-toast {
        position: static !important;
        right: auto !important;
        bottom: auto !important;
        z-index: auto !important;
        pointer-events: auto;
    }
    #nz-alert-stack > #nz-timeout-toast { order: 0; }
    #nz-alert-stack > #nz-new-order-toast { order: 1; }
    @media (max-width: 600px) {
        #nz-alert-stack {
            left: 10px;
            right: 10px;
            top: 66px;
            bottom: auto;
            align-items: stretch;
        }
        #nz-alert-stack > #nz-new-order-toast,
        #nz-alert-stack > #nz-timeout-toast {
            min-width: 0 !important; max-width: none !important; width: auto !important;
            padding: 10px 12px !important;
        }
    }
    </style>
    <div id="nz-alert-stack" aria-live="polite" aria-atomic="false">
        <!-- 哪吒: 新订单非阻塞提示条 (响一次不反复弹窗) -->
        <div id="nz-new-order-toast" style="display:none;background:#fff;border:1px solid #f0f0f0;border-left:4px solid #C4193E;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.15);padding:14px 16px;min-width:248px;max-width:320px;font-family:'PingFang SC','Microsoft YaHei',sans-serif;">
            <div style="display:flex;align-items:flex-start;gap:10px;">
                <div style="font-size:22px;line-height:1;">&#128276;</div>
                <div style="flex:1;">
                    <div style="font-weight:600;color:#1f1f1f;font-size:15px;margin-bottom:2px;"><span id="nz-new-order-count">0</span> 个新订单<span id="nz-new-order-label">待接单</span></div>
                    <div style="color:#8a8a8a;font-size:12px;">点「立即接单」直接进对应订单列表</div>
                </div>
                <button type="button" id="nz-new-order-close" aria-label="关闭" style="border:none;background:none;color:#bbb;font-size:20px;line-height:1;cursor:pointer;padding:0;">&times;</button>
            </div>
            <button type="button" id="nz-new-order-go" style="margin-top:10px;width:100%;background:#C4193E;color:#fff;border:none;border-radius:8px;padding:9px 0;font-size:14px;font-weight:600;cursor:pointer;">立即接单</button>
        </div>
        {{-- 哪吒 C3(W4·告警分级): 超时=不可逆倒计时→保留右下抢占式浮层(仅非作业台页弹); 催配送=辅助类→收进顶栏铃铛栈(_header #nzBellBtn + window.nzBell)。新订单浮层保留(核心唤起)。 --}}
        <!-- 哪吒: 订单超时紧急提示条 (系统/面板渠道, 红色高优先, 独立于新订单提示) -->
        <div id="nz-timeout-toast" style="display:none;background:#fff;border:1px solid #f3c2c2;border-left:4px solid #d32029;border-radius:12px;box-shadow:0 6px 24px rgba(211,32,41,.2);padding:14px 16px;min-width:248px;max-width:320px;font-family:'PingFang SC','Microsoft YaHei',sans-serif;">
            <div style="display:flex;align-items:flex-start;gap:10px;">
                <div style="font-size:22px;line-height:1;">&#9888;&#65039;</div>
                <div style="flex:1;">
                    <div style="font-weight:600;color:#d32029;font-size:15px;margin-bottom:2px;"><span id="nz-timeout-count">0</span> 个订单超时未处理</div>
                    <div style="color:#8a8a8a;font-size:12px;">已超过处理时限，请尽快接单/确认收款/出餐，避免被系统自动取消</div>
                </div>
                <button type="button" id="nz-timeout-close" aria-label="关闭" style="border:none;background:none;color:#bbb;font-size:20px;line-height:1;cursor:pointer;padding:0;">&times;</button>
            </div>
            <button type="button" id="nz-timeout-go" style="margin-top:10px;width:100%;background:#d32029;color:#fff;border:none;border-radius:8px;padding:9px 0;font-size:14px;font-weight:600;cursor:pointer;">立即处理</button>
        </div>
    </div>
    <div class="modal fade" id="popup-modal-msg">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-12">
                            <div class="text-center">
                                <h2 class="8a8a8a">
                                    <i class="tio-messages"></i> {{translate('messages.message_description')}}
                                </h2>
                                <hr>
                                <button class="btn btn-primary check-message">{{translate('messages.Ok, let me check')}}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Image View Modal --}}
    <div id="imageModal" class="imageModal modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header justify-content-end gap-3 border-0 p-2">
                    <button type="button" class="modal_img-btn border-0 btn-circle rounded-circle bg-section2 shadow-none fs-8 m-0"
                            data-dismiss="modal" aria-label="Close">
                            <i class="tio-clear"></i>
                    </button>
                </div>
                <div class="modal-body text-center p-10 pt-0">
                    <div class="imageModal_img_wrapper">
                        <img src="" class="img-fluid imageModal_img" alt="{{ translate('Preview_Image') }}">
                        <div class="imageModal_btn_wrapper">
                            <a href="javascript:" class="btn icon-btn download_btn" title="{{ translate('Download') }}" download>
                                <i class="tio-arrow-large-downward"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

        <div id="password-rules-texts" class="d-none" data-length="{{ translate('7+ characters') }}"
            data-upper="{{ translate('Uppercase letter') }}"
            data-lower="{{ translate('Lowercase letter') }}"
            data-number="{{ translate('Number') }}"
            data-symbol="{{ translate('Symbol') }}"
            data-special-character="{{ translate('messages.password_special_character') }}">

        </div>

</main>
    <div class="modal fade" id="toggle-modal">
        <div class="modal-dialog status-warning-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">
                        <span aria-hidden="true" class="tio-clear"></span>
                    </button>
                </div>
                <div class="modal-body pb-5 pt-0">
                    <div class="max-349 mx-auto mb-20">
                        <div>
                            <div class="text-center">
                                <img id="toggle-image" alt="" class="mb-20">
                                <h5 class="modal-title" id="toggle-title"></h5>
                            </div>
                            <div class="text-center" id="toggle-message">
                            </div>
                        </div>
                        <div class="btn--container justify-content-center">
                            <button type="button" id="toggle-ok-button" class="btn btn--primary min-w-120 confirm-Toggle" data-dismiss="modal" >{{translate('Ok')}}</button>
                            <button id="reset_btn" type="reset" class="btn btn--cancel min-w-120" data-dismiss="modal">
                                {{translate("Cancel")}}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="toggle-status-modal">
        <div class="modal-dialog status-warning-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">
                        <span aria-hidden="true" class="tio-clear"></span>
                    </button>
                </div>
                <div class="modal-body pb-5 pt-0">
                    <div class="max-349 mx-auto mb-20">
                        <div>
                            <div class="text-center">
                                <img id="toggle-status-image" alt="" class="mb-20">
                                <h5 class="modal-title" id="toggle-status-title"></h5>
                            </div>
                            <div class="text-center" id="toggle-status-message">
                            </div>
                        </div>
                        <div class="btn--container justify-content-center">
                            <button type="button" id="toggle-status-ok-button" class="btn btn--primary min-w-120 confirm-Status-Toggle" data-dismiss="modal" >{{translate('Ok')}}</button>
                            <button id="reset_btn" type="reset" class="btn btn--cancel min-w-120" data-dismiss="modal">
                                {{translate("Cancel")}}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <div class="modal fade" id="new-dynamic-submit-model">
        <div class="modal-dialog modal-dialog-centered status-warning-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">
                        <span aria-hidden="true" class="tio-clear"></span>
                    </button>
                </div>
                <div class="modal-body pb-5 pt-0">
                    <div class="max-349 mx-auto mb-20">
                        <div>
                            <div class="text-center">
                                <img id="image-src" class="mb-20">
                                <h5 class="modal-title" id="toggle-title"></h5>
                            </div>
                            <div class="text-center" id="toggle-message">
                                <h3 id="modal-title"></h3>
                                <div id="modal-text"></div>
                            </div>

                            </div>
                            <div class="mb-4 d-none" id="note-data">
                                <textarea class="form-control" placeholder="{{ translate('your_note_here') }}" id="get-text-note" cols="5" ></textarea>
                            </div>
                        <div class="btn--container justify-content-center">
                            <div id="hide-buttons">
                                <button data-dismiss="modal" id="cancel_btn_text" class="btn btn-outline-secondary min-w-120" >{{translate("Not_Now")}}</button> &nbsp;
                                <button type="button" id="new-dynamic-ok-button" class="btn btn-outline-danger confirm-model min-w-120">{{translate('Yes')}}</button>
                            </div>

                            <button data-dismiss="modal"  type="button" id="new-dynamic-ok-button-show" class="btn btn--primary  d-none min-w-120">{{translate('Okay')}}</button>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script src="{{dynamicAsset('assets/admin/js/custom.js')}}"></script>
<script src="{{dynamicAsset('assets/admin/js/jquery.min.js')}}"></script>

    <script>
        "use strict";
        setTimeout(hide_loader, 1000);
            function hide_loader(){
            $('#pre--loader').removeClass("pre--loader");;
        }

        // Open offcanvas
        $(document).ready(function () {
            $('.offcanvas-trigger').on('click', function (e) {
                e.preventDefault();
                var target = $(this).data('target');
                $(target).addClass('open');
                $('#offcanvasOverlay').addClass('show');
            });

            // Close offcanvas on close button or overlay click
            $('.offcanvas-close, #offcanvasOverlay').on('click', function () {
                $('.custom-offcanvas').removeClass('open');
                $('#offcanvasOverlay').removeClass('show');
            });
        });
</script>
<script src="{{dynamicAsset('assets/admin/js/firebase.min.js')}}"></script>

<!-- JS Implementing Plugins -->

@stack('script')
<script src="{{dynamicAsset('assets/admin/js/vendor.min.js')}}"></script>
<script src="{{dynamicAsset('assets/admin/js/theme.min.js')}}"></script>
<script>
    // 全局 DataTables 中文文案默认值（搜索框/分页/统计/空表/导出按钮），
    // 在各页面 @@stack('script_2') 初始化表格之前注入，统一汉化库自带英文。
    if (window.jQuery && jQuery.fn && jQuery.fn.dataTable) {
        jQuery.extend(true, jQuery.fn.dataTable.defaults, {
            language: {
                search: '搜索：',
                searchPlaceholder: '输入关键词',
                lengthMenu: '每页 _MENU_ 条',
                info: '显示第 _START_ 至 _END_ 条，共 _TOTAL_ 条',
                infoEmpty: '共 0 条',
                infoFiltered: '（从 _MAX_ 条中筛选）',
                zeroRecords: '未找到匹配记录',
                emptyTable: '暂无数据',
                loadingRecords: '加载中…',
                processing: '处理中…',
                paginate: { first: '首页', last: '末页', next: '下一页', previous: '上一页' },
                aria: { sortAscending: '：升序排列', sortDescending: '：降序排列' },
                buttons: {
                    copy: '复制',
                    copyTitle: '已复制到剪贴板',
                    copyKeys: '按 Ctrl 或 ⌘ + C 复制表格数据',
                    copySuccess: { _: '已复制 %d 行', 1: '已复制 1 行' },
                    print: '打印',
                    csv: 'CSV',
                    excel: 'Excel',
                    pdf: 'PDF',
                    colvis: '列显示',
                    pageLength: { _: '每页 %d 条', '-1': '显示全部' }
                }
            }
        });
    }
</script>
<script src="{{dynamicAsset('assets/admin/js/sweet_alert.js')}}"></script>
<script src="{{dynamicAsset('assets/admin/js/toastr.js')}}"></script>
<script src="{{dynamicAsset('assets/admin/js/owl.min.js')}}"></script>
<script src="{{ dynamicAsset('assets/admin/plugins/lightbox/js/lightbox.min.js')}}"></script>

<script src="{{dynamicAsset('assets/admin/intltelinput/js/intlTelInput.min.js')}}"></script>



{!! Toastr::message() !!}

@if ($errors->any())
    <script>
            "use strict";
        @foreach($errors->all() as $error)
        toastr.error('{{translate($error)}}');
        @endforeach
    </script>
@endif

<script>
    "use strict";

    $('.blinkings').on('mouseover', ()=> $('.blinkings').removeClass('active'))
    $('.blinkings').addClass('open-shadow')
    setTimeout(() => {
        $('.blinkings').removeClass('active')
    }, 10000);
    setTimeout(() => {
        $('.blinkings').removeClass('open-shadow')
    }, 5000);

    $(function(){
        var owl = $('.single-item-slider');
        owl.owlCarousel({
            autoplay: false,
            items:1,
            onInitialized  : counter,
            onTranslated : counter,
            autoHeight: true,
            dots: true
        });

        function counter(event) {
            var element   = event.target;         // DOM element, in this example .owl-carousel
                var items     = event.item.count;     // Number of items
                var item      = event.item.index + 1;     // Position of the current item

            // it loop is true then reset counter from 1
            if(item > items) {
                item = item - items
            }
            $('.slide-counter').html(+item+"/"+items)
        }
    });
    $(document).on('ready', function(){
        $(".direction-toggle").on("click", function () {
            if($('html').hasClass('active')){
                $('html').removeClass('active')
                setDirection(1);
            }else {
                setDirection(0);
                $('html').addClass('active')
            }
        });
        if ($('html').attr('dir') == "rtl") {
            $(".direction-toggle").find('span').text('Toggle LTR')
        } else {
            $(".direction-toggle").find('span').text('Toggle RTL')
        }

        function setDirection(status) {
            if (status == 1) {
                $("html").attr('dir', 'ltr');
                $(".direction-toggle").find('span').text('Toggle RTL')
            } else {
                $("html").attr('dir', 'rtl');
                $(".direction-toggle").find('span').text('Toggle LTR')
            }
            $.get({
                    url: '{{ route('vendor.business-settings.site_direction_vendor') }}',
                    dataType: 'json',
                    data: {
                        status: status,
                    },
                    success: function() {
                        alert(ok);
                    },

                });
            }
        });

    $(document).on('ready', function () {
        if (window.localStorage.getItem('hs-builder-popover') === null) {
            $('#builderPopover').popover('show')
                .on('shown.bs.popover', function () {
                    $('.popover').last().addClass('popover-dark')
                });

            $(document).on('click', '#closeBuilderPopover', function () {
                window.localStorage.setItem('hs-builder-popover', true);
                $('#builderPopover').popover('dispose');
            });
        } else {
            $('#builderPopover').on('show.bs.popover', function () {
                return false
            });
        }

        // BUILDER TOGGLE INVOKER
        // =======================================================
        $('.js-navbar-vertical-aside-toggle-invoker').click(function () {
            $('.js-navbar-vertical-aside-toggle-invoker i').tooltip('hide');
        });


        // INITIALIZATION OF NAVBAR VERTICAL NAVIGATION
        // =======================================================
        var sidebar = $('.js-navbar-vertical-aside').hsSideNav();


        // INITIALIZATION OF TOOLTIP IN NAVBAR VERTICAL MENU
        // =======================================================
        $('.js-nav-tooltip-link').tooltip({boundary: 'window'})

        $(".js-nav-tooltip-link").on("show.bs.tooltip", function (e) {
            if (!$("body").hasClass("navbar-vertical-aside-mini-mode")) {
                return false;
            }
        });


        // INITIALIZATION OF UNFOLD
        // =======================================================
        $('.js-hs-unfold-invoker').each(function () {
            var unfold = new HSUnfold($(this)).init();
        });


        // INITIALIZATION OF FORM SEARCH
        // =======================================================
        $('.js-form-search').each(function () {
            new HSFormSearch($(this)).init()
        });


        // INITIALIZATION OF SELECT2
        // =======================================================
        $('.js-select2-custom').each(function () {
            var select2 = $.HSCore.components.HSSelect2.init($(this));
        });


        // INITIALIZATION OF DATERANGEPICKER
        // =======================================================
        $('.js-daterangepicker').daterangepicker();

        $('.js-daterangepicker-times').daterangepicker({
            timePicker: true,
            startDate: moment().startOf('hour'),
            endDate: moment().startOf('hour').add(32, 'hour'),
            locale: {
                format: 'M/DD hh:mm A'
            }
        });

        var start = moment();
        var end = moment();

        function cb(start, end) {
            $('#js-daterangepicker-predefined .js-daterangepicker-predefined-preview').html(start.format('MMM D') + ' - ' + end.format('MMM D, YYYY'));
        }

        $('#js-daterangepicker-predefined').daterangepicker({
            startDate: start,
            endDate: end,
            ranges: {
                'Today': [moment(), moment()],
                'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                'Last 7 Days': [moment().subtract(6, 'days'), moment()],
                'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                'This Month': [moment().startOf('month'), moment().endOf('month')],
                'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
            }
        }, cb);

        cb(start, end);

        $('.js-clipboard').each(function () {
            var clipboard = $.HSCore.components.HSClipboard.init(this);
        });
    });
</script>

@stack('script_2')
    <script src="{{dynamicAsset('assets/admin/js/view-pages/common.js')}}"></script>
    <script src="{{dynamicAsset('assets/admin/js/keyword-highlighted.js')}}"></script>
    {{-- 哪吒 W4: 心跳纯函数(真新单去重)——被 Node 单测覆盖的单一真相源, poll() 调它算 freshIds(未加载则内联回退)。 --}}
    <script src="{{dynamicAsset('assets/admin/js/nz-heartbeat-core.js')}}?v=1"></script>
    {{-- 哪吒 UX-1 A+B: 统一操作反馈层(nzConfirm/nzToast/data-nz-ajax 不落屏提交)。须在 toastr 之后。 --}}
    @include('layouts.vendor.partials._nz_ui_kit')

<audio id="myAudio">
    <source src="{{dynamicAsset('assets/admin/sound/new-order-voice.mp3')}}" type="audio/mpeg">
</audio>
<audio id="nzMsgAudio" preload="auto">
    <source src="{{dynamicAsset('assets/admin/sound/new-message-voice.mp3')}}" type="audio/mpeg">
</audio>
{{-- 哪吒: 客服(平台/admin)新消息专用提示音——前置叮咚铃 + 低音色人声，与顾客「新消息」音明显区别 --}}
<audio id="nzAdminMsgAudio" preload="auto">
    <source src="{{dynamicAsset('assets/admin/sound/new-admin-message-voice.wav')}}?v=3" type="audio/wav">
</audio>
{{-- 哪吒: 顾客催「分享配送进度」专用提示音——与新订单/新消息声明显区别, 商家一听就知是催物流 --}}
<audio id="nzDelivAudio" preload="auto">
    <source src="{{dynamicAsset('assets/admin/sound/deliv-link-voice.mp3')}}?v=1" type="audio/mpeg">
</audio>
<script>
/* nz: 商家提示音偏好(分类音量+总开关, 存本机 localStorage) */
(function(){
    var KEY='nz_sound_prefs_v1';
    var CATS=['new_order','timeout','customer_msg','platform_msg','deliv'];
    var DEF={on:1,new_order:90,timeout:90,customer_msg:70,platform_msg:70,deliv:70};
    function load(){var p={};try{p=JSON.parse(localStorage.getItem(KEY)||'{}')||{};}catch(e){p={};}var o={};o.on=(p.on===undefined)?DEF.on:(p.on?1:0);CATS.forEach(function(c){var v=p[c];v=(v===undefined||v===null)?DEF[c]:parseInt(v,10);if(isNaN(v))v=DEF[c];o[c]=Math.max(0,Math.min(100,v));});return o;}
    var prefs=load();
    function save(){try{localStorage.setItem(KEY,JSON.stringify(prefs));}catch(e){}}
    window.nzSound={
        getVol:function(cat){if(!prefs.on)return 0;var v=prefs[cat];if(v===undefined)v=100;return Math.max(0,Math.min(100,v))/100;},
        get:function(){return prefs;},
        setCat:function(cat,val){prefs[cat]=Math.max(0,Math.min(100,parseInt(val,10)||0));save();},
        setOn:function(on){prefs.on=on?1:0;save();}
    };
    document.addEventListener('DOMContentLoaded',function(){
        var btn=document.getElementById('nzSoundBtn');var pop=document.getElementById('nzSoundPop');if(!pop)return;
        var master=document.getElementById('nzSoundMaster');var body=document.getElementById('nzSoundBody');
        function reflect(){if(!body)return;if(prefs.on)body.classList.remove('nz-snd-off');else body.classList.add('nz-snd-off');body.querySelectorAll('input.nz-snd-sl,button.nz-snd-test').forEach(function(el){el.disabled=!prefs.on;});}
        if(master)master.checked=!!prefs.on;
        pop.querySelectorAll('input.nz-snd-sl').forEach(function(sl){var cat=sl.getAttribute('data-cat');sl.value=prefs[cat];var val=pop.querySelector('.nz-snd-val[data-cat="'+cat+'"]');if(val)val.textContent=prefs[cat]+'%';sl.addEventListener('input',function(){if(val)val.textContent=Math.round(sl.value)+'%';window.nzSound.setCat(cat,sl.value);});});
        pop.querySelectorAll('button.nz-snd-test').forEach(function(b){b.addEventListener('click',function(ev){ev.preventDefault();ev.stopPropagation();var cat=b.getAttribute('data-cat');var a=document.getElementById(b.getAttribute('data-el'));if(!a)return;var v=window.nzSound.getVol(cat);try{a.volume=Math.max(0,v);a.currentTime=0;var p=a.play();if(p&&p.catch)p.catch(function(){});}catch(e){}});});
        if(master)master.addEventListener('change',function(){window.nzSound.setOn(master.checked);reflect();});
        reflect();
        if(btn){btn.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();pop.style.display=(pop.style.display==='block')?'none':'block';});document.addEventListener('click',function(e){if(pop.style.display==='block'&&!pop.contains(e.target)&&e.target!==btn&&!btn.contains(e.target))pop.style.display='none';});}
    });
})();
</script>
<script>
    // 哪吒: 首次用户交互时解锁提示音(绕开浏览器自动播放限制)——之后轮询新消息能稳定响铃
    (function(){
        function nzUnlockAudio(){
            ['myAudio','nzMsgAudio','nzAdminMsgAudio','nzDelivAudio'].forEach(function(id){
                var a = document.getElementById(id);
                if(!a) return;
                try {
                    a.muted = true;
                    var p = a.play();
                    if (p && p.then) { p.then(function(){ a.pause(); a.currentTime = 0; a.muted = false; }).catch(function(){ a.muted = false; }); }
                    else { a.pause(); a.currentTime = 0; a.muted = false; }
                } catch(e){ a.muted = false; }
            });
            window.nzAudioUnlocked = true;
            document.removeEventListener('click', nzUnlockAudio);
            document.removeEventListener('keydown', nzUnlockAudio);
            document.removeEventListener('touchstart', nzUnlockAudio);
        }
        document.addEventListener('click', nzUnlockAudio);
        document.addEventListener('keydown', nzUnlockAudio);
        document.addEventListener('touchstart', nzUnlockAudio);
    })();
    // 哪吒: 订单页在场感知——商家正盯着相关页面时抑制冗余弹窗/声音
    function nzViewingOrderDetail() {
        if (document.hidden) return null;
        var m = location.pathname.match(/\/restaurant-panel\/order\/details\/(\d+)/);
        return m ? m[1] : null;
    }
    function nzOnOrderList() {
        if (document.hidden) return false;
        return location.pathname.indexOf('/restaurant-panel/order/list/') !== -1;
    }
    // 哪吒作业台 W4: 是否正停在作业台页(在场感知——页面可见且路径命中)。新单在此页只队列高亮+响铃, 不弹 toast。
    function nzOnWorkbench() {
        if (document.hidden) return false;
        return location.pathname.indexOf('/restaurant-panel/workbench') !== -1;
    }
    // 哪吒作业台 W4: 全局 6s 心跳订阅点。poll() 成功后 fire(data); 作业台页订阅它刷新可刷新分区(不另开轮询)。
    window.nzHeartbeat = window.nzHeartbeat || {
        subs: [],
        on: function (fn) { if (typeof fn === 'function') { this.subs.push(fn); } },
        fire: function (data) { for (var i = 0; i < this.subs.length; i++) { try { this.subs[i](data); } catch (e) {} } }
    };
    // 哪吒 C3(W4): 顶栏铃铛栈 —— 超时/催配送告警的被动收集器(徽标 + 可展开列表·直连订单)。
    // 徽标=当前活动告警数(只反映真实计数·非响铃口径); 响铃仍由 poll 分类去重独立驱动(解耦)。
    window.nzBell = (function () {
        var timeoutIds = [], delivIds = [], topupItems = [];
        var detailsBase = '{{ url('/') }}/restaurant-panel/order/details/';
        function esc(s){ return String(s).replace(/[<>&]/g, function(c){ return c === '<' ? '&lt;' : (c === '>' ? '&gt;' : '&amp;'); }); }
        function render() {
            var badge = document.getElementById('nzBellBadge');
            var body = document.getElementById('nzBellBody');
            var total = timeoutIds.length + delivIds.length + topupItems.length;
            if (badge) {
                if (total > 0) { badge.textContent = total > 99 ? '99+' : total; badge.style.display = 'block'; }
                else { badge.style.display = 'none'; }
            }
            if (!body) { return; }
            var html = '';
            if (timeoutIds.length) {
                html += '<div class="nz-bell-grp">超时未处理 · ' + timeoutIds.length + '</div>';
                timeoutIds.forEach(function (id) {
                    html += '<a class="nz-bell-item" href="' + detailsBase + encodeURIComponent(id) + '"><span class="nz-bell-dot red"></span>订单 #' + esc(id) + ' 已超时 · 请尽快处理 <span class="nz-bell-go">&rarr;</span></a>';
                });
            }
            if (delivIds.length) {
                html += '<div class="nz-bell-grp">顾客催配送 · ' + delivIds.length + '</div>';
                delivIds.forEach(function (id) {
                    html += '<a class="nz-bell-item" href="' + detailsBase + encodeURIComponent(id) + '"><span class="nz-bell-dot blue"></span>订单 #' + esc(id) + ' 顾客在催 · 去贴链接 <span class="nz-bell-go">&rarr;</span></a>';
                });
            }
            // 哪吒 A3·S4: 充值/退款审核结果(入账/打回/退款放款)。绿点=成功·琥珀点=被打回。
            if (topupItems.length) {
                html += '<div class="nz-bell-grp">充值/退款结果 · ' + topupItems.length + '</div>';
                topupItems.forEach(function (it) {
                    var dot = (it && it.status === 'rejected') ? 'amber' : 'green';
                    html += '<a class="nz-bell-item" href="' + ((it && it.href) || '#') + '"><span class="nz-bell-dot ' + dot + '"></span>' + esc(it && it.label ? it.label : '') + ' <span class="nz-bell-go">&rarr;</span></a>';
                });
            }
            body.innerHTML = html || '<div class="nz-bell-empty">暂无待办提醒</div>';
        }
        return {
            setTimeouts: function (ids) { timeoutIds = (ids || []).map(String); render(); },
            setDelivs: function (ids) { delivIds = (ids || []).map(String); render(); },
            setTopups: function (items) { topupItems = (items || []); render(); },
            render: render
        };
    })();
    // 铃铛开合(与提示音面板同款: 点击切换, 点外面收起)。
    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('nzBellBtn');
        var pop = document.getElementById('nzBellPop');
        if (window.nzBell) { window.nzBell.render(); }
        if (!btn || !pop) { return; }
        btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); pop.style.display = (pop.style.display === 'block') ? 'none' : 'block'; });
        document.addEventListener('click', function (e) { if (pop.style.display === 'block' && !pop.contains(e.target) && e.target !== btn && !btn.contains(e.target)) { pop.style.display = 'none'; } });
    });
</script>

<script>
        "use strict";
    let audio = document.getElementById("myAudio");

    function playAudio(cat) {
        try { var v = (window.nzSound ? window.nzSound.getVol(cat || 'new_order') : 1); if (v <= 0) return; audio.volume = v; var p = audio.play(); if (p && p.catch) { p.catch(function(){}); } } catch(e){}
    }

    function pauseAudio() {
        audio.pause();
    }


    function route_alert(route, message) {
        Swal.fire({
            title: '{{ translate('messages.Are you sure ?') }}',
            text: message,
            type: 'warning',
            showCancelButton: true,
            cancelButtonColor: 'default',
            confirmButtonColor: '#FC6A57',
            cancelButtonText: '{{ translate('messages.No') }}',
            confirmButtonText: '{{ translate('messages.Yes') }}',
            reverseButtons: true
        }).then((result) => {
            if (result.value) {
                location.href = route;
            }
        })
    }

    $('.form-alert').on('click',function (){
        let id = $(this).data('id')
        let message = $(this).data('message')
        Swal.fire({
            title: '{{ translate('messages.Are you sure?') }}',
            text: message,
            type: 'warning',
            showCancelButton: true,
            cancelButtonColor: 'default',
            confirmButtonColor: '#FC6A57',
            cancelButtonText: '{{ translate('messages.no') }}',
            confirmButtonText: '{{ translate('messages.Yes') }}',
            reverseButtons: true
        }).then((result) => {
            if (result.value) {
                $('#'+id).submit()
            }
        })
    })

    //search option
    $(document).ready(function () {
        $('#searchForm input[name="search"]').keyup(function () {
            var searchKeyword = $(this).val().trim();

            if (searchKeyword.length >= 1) {
                $.ajax({
                    type: 'POST',
                    url: $('#searchForm').attr('action'),
                    data: {search: searchKeyword, _token: $('input[name="_token"]').val()},
                    success: function (response) {
                        if (response.length === 0) {
                            $('#searchResults').html('<div class="fs-16 fw-500 mb-2">' + @json(translate('Search Result')) + '</div>' +
                                '<div class="search-list h-300 d-flex flex-column gap-2 justify-content-center align-items-center fs-16">' +
                                '<img width="30" src="' + @json(dynamicAsset('assets/admin/img/modal/no-search-found.png')) + '" alt="">' + ' ' +
                                @json(translate('No result found')) +
                                    '</div>');

                        } else {
                            var resultHtml = '';
                            response.forEach(function (route) {
                                var fullRouteWithKeyword = route.fullRoute + '?keyword=' + encodeURIComponent(searchKeyword);
                                resultHtml += '<a href="' + fullRouteWithKeyword + '" class="search-list-item d-flex flex-column" data-route-name="' + route.routeName + '" data-route-uri="' + route.URI + '" data-route-full-url="' + route.fullRoute + '" aria-current="true">';
                                resultHtml += '<h5>' + route.routeName + '</h5>';
                                resultHtml += '<p class="text-muted fs-12 mb-0">' + route.URI + '</p>';
                                resultHtml += '</a>';
                            });
                            $('#searchResults').html('<div class="fs-16 fw-500 mb-2">' + @json(translate('Search Result')) + '</div>' + '<div class="search-list d-flex flex-column">' + resultHtml + '</div>');

                            $('.search-list-item').click(function () {
                                var routeName = $(this).data('route-name');
                                var routeUri = $(this).data('route-uri');
                                var routeFullUrl = $(this).data('route-full-url');

                                $.ajax({
                                    type: 'POST',
                                    url: '{{ route('vendor.store.clicked.route') }}',
                                    data: {
                                        routeName: routeName,
                                        routeUri: routeUri,
                                        routeFullUrl: routeFullUrl,
                                        searchKeyword: searchKeyword,
                                        _token: $('input[name="_token"]').val()
                                    },
                                    success: function (response) {
                                        console.log(response.message);
                                    },
                                    error: function (xhr, status, error) {
                                        console.error(xhr.responseText);
                                    }
                                });
                            });
                        }
                    },
                    error: function (xhr, status, error) {
                        console.error(xhr.responseText);
                    }
                });
            } else {
                $('#searchResults').html('<div class="text-center text-muted py-5">{{translate('Write a minimum of one characters.')}}.</div>');
            }
        });
    });

    document.addEventListener('keydown', function(event) {
        if (event.ctrlKey && event.key === 'k') {
            event.preventDefault();
            document.getElementById('modalOpener').click();
        }
    });

    $(document).ready(function () {
        $("#staticBackdrop").on("shown.bs.modal", function () {
            $(this).find("#searchForm input[type=search]").val('');
            $('#searchResults').html('<div class="text-center text-muted py-5">{{translate('Loading recent searches')}}...</div>');
            $(this).find("#searchForm input[type=search]").focus();

            $.ajax({
                type: 'GET',
                url: '{{ route('vendor.recent.search') }}',
                success: function (response) {
                    if (response.length === 0) {
                        $('#searchResults').html('<div class="text-center text-muted py-5">{{translate('It appears that you have not yet searched.')}}.</div>');
                    } else {
                        var resultHtml = '';
                        response.forEach(function (route) {
                            resultHtml += '<a href="' + route.route_full_url + '" class="search-list-item d-flex flex-column" data-route-name="' + route.route_name + '" data-route-uri="' + route.route_uri + '" data-route-full-url="' + route.route_full_url + '" aria-current="true">';
                            resultHtml += '<h5>' + route.route_name + '</h5>';
                            resultHtml += '<p class="text-muted fs-12  mb-0">' + route.route_uri + '</p>';
                            resultHtml += '</a>';
                        });
                        $('#searchResults').html('<div class="recent-search fs-16 fw-500 animate">' +
                            @json(translate('Recent Search')) + '<div class="search-list d-flex flex-column mt-2">' + resultHtml + '</div></div>');

                        $('.search-list-item').click(function () {
                            var routeName = $(this).data('route-name');
                            var routeUri = $(this).data('route-uri');
                            var routeFullUrl = $(this).data('route-full-url');
                            var searchKeyword = $('input[type=search]').val().trim();

                            $.ajax({
                                type: 'POST',
                                url: '{{ route('vendor.store.clicked.route') }}',
                                data: {
                                    routeName: routeName,
                                    routeUri: routeUri,
                                    routeFullUrl: routeFullUrl,
                                    searchKeyword: searchKeyword,
                                    _token: $('input[name="_token"]').val()
                                },
                                success: function (response) {
                                    console.log(response.message);
                                },
                                error: function (xhr, status, error) {
                                    console.error(xhr.responseText);
                                }
                            });
                        });
                    }
                },
                error: function (xhr, status, error) {
                    console.error(xhr.responseText);
                    $('#searchResults').html('<div class="text-center text-muted py-5">{{translate('Error loading recent searches')}}.</div>');
                }
            });
        });
    });

    $("#staticBackdrop").on("hidden.bs.modal", function () {
        $('#searchResults').empty();
    });

    const searchInput = document.getElementById('searchInput');
    searchInput.addEventListener('search', function() {
        if (!this.value.trim()) {
            $('#searchResults').html('<div class="text-center text-muted py-5"></div>');
        }
    });

    $('#searchForm').submit(function (event) {
        event.preventDefault();
    });

    @php($fcm_credentials = \App\CentralLogics\Helpers::get_business_settings('fcm_credentials'))
    var firebaseConfig = {
        apiKey: "{{isset($fcm_credentials['apiKey']) ? $fcm_credentials['apiKey'] : ''}}",
        authDomain: "{{isset($fcm_credentials['authDomain']) ? $fcm_credentials['authDomain'] : ''}}",
        projectId: "{{isset($fcm_credentials['projectId']) ? $fcm_credentials['projectId'] : ''}}",
        storageBucket: "{{isset($fcm_credentials['storageBucket']) ? $fcm_credentials['storageBucket'] : ''}}",
        messagingSenderId: "{{isset($fcm_credentials['messagingSenderId']) ? $fcm_credentials['messagingSenderId'] : ''}}",
        appId: "{{isset($fcm_credentials['appId']) ? $fcm_credentials['appId'] : ''}}",
        measurementId: "{{isset($fcm_credentials['measurementId']) ? $fcm_credentials['measurementId'] : ''}}"
    };

    @if (isset($fcm_credentials['apiKey']) && is_string($fcm_credentials['apiKey']) && strlen($fcm_credentials['apiKey'])  > 3 )
        firebase.initializeApp(firebaseConfig);
        const messaging = firebase.messaging();
    @endif

        function startFCM() {
            messaging
                .requestPermission()
                .then(function() {
                    return messaging.getToken();
                })
                .then(function(token) {
                    // console.log('FCM Token:', token);
                    @php($restaurant_id=\App\CentralLogics\Helpers::get_restaurant_id())
                    // Send the token to your backend to subscribe to topic
                    subscribeTokenToBackend(token, 'restaurant_panel_{{$restaurant_id}}_message');
                }).catch(function(error) {
                console.error('Error getting permission or token:', error);
            });
        }

        function subscribeTokenToBackend(token, topic) {
            fetch('{{url('/')}}/subscribeToTopic', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ token: token, topic: topic })
            }).then(response => {
                if (response.status < 200 || response.status >= 400) {
                    return response.text().then(text => {
                        throw new Error(`Error subscribing to topic: ${response.status} - ${text}`);
                    });
                }
                console.log(`Subscribed to "${topic}"`);
            }).catch(error => {
                console.error('Subscription error:', error);
            });
        }


    function getUrlParameter(sParam) {
        var sPageURL = window.location.search.substring(1);
        var sURLVariables = sPageURL.split('&');
        for (var i = 0; i < sURLVariables.length; i++) {
            var sParameterName = sURLVariables[i].split('=');
            if (sParameterName[0] === sParam) {
                return sParameterName[1];
            }
        }
    }

    function converationList() {
        var tab = getUrlParameter('tab');
        $.ajax({
            url: "{{ route('vendor.message.list') }}"+ '?tab=' + tab,
            success: function(data) {
                $('#conversation-list').empty();
                $('#admin-conversation-list').empty();
                $("#conversation-list").append(data.html);
                $("#admin-conversation-list").append(data.admin_html);
                var user_id = getUrlParameter('user');
                $('.customer-list').removeClass('conv-active');
                $('#customer-' + user_id).addClass('conv-active');
            }
        })
    }

    function conversationView() {
        var conversation_id = getUrlParameter('conversation');
        var user_id = getUrlParameter('user');
        var url= '{{url('/')}}/restaurant-panel/message/view/'+conversation_id+'/' + user_id;
        $.ajax({
            url: url,
            success: function(data) {
                $('#view-conversation').html(data.view);
            }
        })
    }
    @php($order_notification_type = \App\Models\BusinessSetting::where('key', 'order_notification_type')->first())
    @php($order_notification_type = $order_notification_type ? $order_notification_type->value : 'firebase')
    var order_type = 'all';


    @if (isset($fcm_credentials['apiKey']) && is_string($fcm_credentials['apiKey']) && strlen($fcm_credentials['apiKey'])  > 3 )

    messaging.onMessage(function (payload) {
        console.log(payload.data);

        if(payload.data.order_id && payload.data.type === 'new_order'){
            @if(\App\CentralLogics\Helpers::employee_module_permission_check('order') && $order_notification_type == 'firebase')
            order_type = payload.data.order_type
            playAudio('new_order');
            $('#popup-modal').appendTo("body").modal('show');
            @endif
        }else if(payload.data.type === 'message'){
            var conversation_id = getUrlParameter('conversation');
            var user_id = getUrlParameter('user');
            var url= '{{url('/')}}/restaurant-panel/message/view/'+conversation_id+'/' + user_id;
            $.ajax({
                url: url,
                success: function(data) {
                    $('#view-conversation').html(data.view);
                }
            })
            toastr.success('{{ translate('messages.New message arrived') }}', {
                        CloseButton: true,
                        ProgressBar: true
                    });

            if($('#conversation-list').scrollTop() == 0){
                converationList();
            }
        }
    });

    @endif

    @if(\App\CentralLogics\Helpers::employee_module_permission_check('order') && $order_notification_type == 'manual')
        (function(){
            var SEEN_KEY = 'nz_seen_order_ids_v1';
            var toast    = document.getElementById('nz-new-order-toast');
            var countEl  = document.getElementById('nz-new-order-count');
            var labelEl  = document.getElementById('nz-new-order-label');
            var goBtn    = document.getElementById('nz-new-order-go');
            var closeBtn = document.getElementById('nz-new-order-close');
            var listBase = '{{url('/')}}/restaurant-panel/order/list/';
            var currentTarget = 'pending';
            var dismissed = false;
            var memSeen = new Set();
            // 哪吒: 订单超时紧急提示(系统渠道)——独立 seen-set, 与新订单提示互不干扰
            var TIMEOUT_SEEN_KEY = 'nz_seen_timeout_ids_v1';
            var toToast    = document.getElementById('nz-timeout-toast');
            var toCountEl  = document.getElementById('nz-timeout-count');
            var toGoBtn    = document.getElementById('nz-timeout-go');
            var toCloseBtn = document.getElementById('nz-timeout-close');
            var toTarget   = 'pending';
            var toDismissed = false;
            var toMemSeen  = new Set();
            function toLoadSeen(){ try { return new Set(JSON.parse(localStorage.getItem(TIMEOUT_SEEN_KEY) || '[]')); } catch(e){ return toMemSeen; } }
            function toSaveSeen(set){ toMemSeen = set; try { var arr = Array.from(set); if (arr.length > 200) { arr = arr.slice(arr.length - 200); } localStorage.setItem(TIMEOUT_SEEN_KEY, JSON.stringify(arr)); } catch(e){} }
            function toShow(count){ if (toCountEl) { toCountEl.textContent = count; } if (toToast) { toToast.style.display = 'block'; } }
            function toHide(){ if (toToast) { toToast.style.display = 'none'; } }
            if (toGoBtn) { toGoBtn.addEventListener('click', function(){ location.href = listBase + toTarget; }); }
            if (toCloseBtn) { toCloseBtn.addEventListener('click', function(){ toDismissed = true; toHide(); }); }
            // 哪吒 C3(W4·告警分级): 超时=不可逆倒计时→保留右下抢占式浮层; 作业台页内(队列④+总览条兜底)与正看该单详情时抑制浮层(套新单同款)。
            // 超时不进铃铛栈(铃铛留辅助类·催配送); 响铃仅"正看该单"时抑制(作业台仍响·与新单一致·分类音量)。
            function updateTimeoutToast(data){
                var ids = (data.timeout_order_ids || []).map(String);
                var total = data.timeout_total || 0;
                toTarget = data.timeout_target || 'pending';
                if (total === 0) { toHide(); toDismissed = false; return; }
                var seen = toLoadSeen();
                var freshIds = ids.filter(function(id){ return !seen.has(id); });
                var viewingId = nzViewingOrderDetail();
                var viewingThisOrder = viewingId && ids.indexOf(viewingId) !== -1;
                var hideToastHere = viewingThisOrder || nzOnWorkbench();   // 作业台/正看该单 → 不弹浮层
                if (freshIds.length > 0) {
                    if (!viewingThisOrder) { playAudio('timeout'); }       // 作业台仍响铃(套新单)
                    toDismissed = false;
                    freshIds.forEach(function(id){ seen.add(id); });
                    toSaveSeen(seen);
                    if (!hideToastHere) { toShow(total); }
                } else if (!toDismissed && !hideToastHere) {
                    toShow(total);
                }
            }

            // 哪吒: 顾客催「分享配送进度」告警。C3(W4): 收进顶栏铃铛栈(window.nzBell·直连订单), 不再弹右下浮层; 响铃逻辑(活动告警只响一次)照旧。
            var dvAudioEl  = document.getElementById('nzDelivAudio');
            var dvSounded  = false;
            function dvPlay(){ try { if (dvAudioEl) { var _v=(window.nzSound?window.nzSound.getVol('deliv'):1); if(_v<=0)return; dvAudioEl.volume=_v; dvAudioEl.currentTime = 0; var p = dvAudioEl.play(); if (p && p.catch) { p.catch(function(){}); } } } catch(e){} }
            function updateDelivToast(data){
                var ids = (data.deliv_link_order_ids || []).map(String);
                var total = data.deliv_link_total || 0;
                if (window.nzBell) { window.nzBell.setDelivs(ids); }
                if (total === 0) { dvSounded = false; return; }
                // 声音独立于「已看」去重: 音频解锁后把当前还挂着的催单补响一次; 活动告警只响一次, 清零后重置, 下次新催单再响。
                // 在场感知: 商家正看着该催配送单的详情页时, 不响铃(已在贴链接的页面了)。
                var viewingId = nzViewingOrderDetail();
                var viewingThisOrder = viewingId && ids.indexOf(viewingId) !== -1;
                if (!dvSounded && window.nzAudioUnlocked && !viewingThisOrder) { dvPlay(); dvSounded = true; }
            }

            // 哪吒 A3·S4: 充值/退款审核结果站内信 —— 复用顶栏 nzBell(不造第二套·不响铃只亮徽标)。
            // 在场感知: 商家正看对账中心页 → 状态卡已呈现结果 → 静默标记已读、铃铛不亮不弹;
            //           页外 → 未读进铃铛徽标(持续到商家进对账中心看过为止, 或 7 天后服务端自然移出)。
            var TOPUP_SEEN_KEY = 'nz_seen_topup_ids_v1';
            function tpLoadSeen(){ try { return new Set(JSON.parse(localStorage.getItem(TOPUP_SEEN_KEY) || '[]')); } catch(e){ return new Set(); } }
            function tpSaveSeen(set){ try { var arr = Array.from(set); if (arr.length > 200) { arr = arr.slice(arr.length - 200); } localStorage.setItem(TOPUP_SEEN_KEY, JSON.stringify(arr)); } catch(e){} }
            function nzOnReconciliation(){ return location.pathname.indexOf('/nezha-deposit') !== -1; }
            function updateTopupBell(data){
                if (!window.nzBell) { return; }
                var results = data.topup_results || [];
                var seen = tpLoadSeen();
                if (nzOnReconciliation()) {
                    var changed = false;
                    results.forEach(function (r) { if (r && !seen.has(String(r.id))) { seen.add(String(r.id)); changed = true; } });
                    if (changed) { tpSaveSeen(seen); }
                    window.nzBell.setTopups([]);
                    return;
                }
                var unseen = results.filter(function (r) { return r && !seen.has(String(r.id)); });
                window.nzBell.setTopups(unseen);
            }

            function loadSeen(){
                try { return new Set(JSON.parse(localStorage.getItem(SEEN_KEY) || '[]')); }
                catch(e){ return memSeen; }
            }
            function saveSeen(set){
                memSeen = set;
                try {
                    var arr = Array.from(set);
                    if (arr.length > 200) { arr = arr.slice(arr.length - 200); }
                    localStorage.setItem(SEEN_KEY, JSON.stringify(arr));
                } catch(e){}
            }
            function showToast(count, label){
                // 哪吒#4: 订单详情页/列表页不弹「新订单」浮层(商家已在看订单, 该提示冗余且遮挡操作)
                if (location.pathname.indexOf('/restaurant-panel/order/details/') !== -1) { hideToast(); return; }
                if (nzOnOrderList()) { hideToast(); return; }
                if (nzOnWorkbench()) { hideToast(); return; }   // 哪吒作业台 W4: 作业台内新单只队列高亮+响铃, 不弹浮层(在场感知)
                if (countEl) { countEl.textContent = count; }
                if (labelEl && label) { labelEl.textContent = label; }
                if (toast) { toast.style.display = 'block'; }
            }
            function hideToast(){
                if (toast) { toast.style.display = 'none'; }
            }
            if (goBtn) { goBtn.addEventListener('click', function(){ location.href = listBase + currentTarget; }); }
            if (closeBtn) { closeBtn.addEventListener('click', function(){ dismissed = true; hideToast(); }); }

            // 哪吒 P3 接单机模式: 断连检测。poll 成功即更新时戳; watchdog 每 10s 查, ≥60s 无成功 → 顶部红条(接单机可能收不到新单)。
            var nzLastPollOk = Date.now();
            function nzDiscBar(show){ var b = document.getElementById('nzDisconnectBar'); if (b) b.style.display = show ? 'block' : 'none'; }
            setInterval(function(){ nzDiscBar(Date.now() - nzLastPollOk >= 60000); }, 10000);
            function poll(){
                $.get({
                    url: '{{route('vendor.get-restaurant-data')}}',
                    dataType: 'json',
                    success: function (response) {
                        nzLastPollOk = Date.now(); nzDiscBar(false);   // 哪吒 P3: 轮询成功 → 清断连红条
                        var data = response.data || {};
                        updateTimeoutToast(data);
                        updateDelivToast(data);
                        updateTopupBell(data);   // 哪吒 A3·S4: 充值/退款结果喂 nzBell(在场感知内建)
                        if (window.nzHeartbeat) { window.nzHeartbeat.fire(data); }   // 哪吒 W4: 每次心跳驱动作业台等订阅者刷新(不另开轮询·先于新单早返回, 保证队列态每 6s 都更新)
                        var ids = (data.new_order_ids || []).map(String);
                        var total = data.new_total || 0;
                        currentTarget = data.target || 'pending';

                        if (total === 0) { hideToast(); dismissed = false; return; }

                        var label = data.target_label || '待接单';
                        var seen = loadSeen();
                        // 哪吒 W4: 用被单测覆盖的纯函数算「真新单」(nz-heartbeat-core.js), 回退内联同逻辑; 响铃/去重口径不变(绝不漏单/防双响)。
                        var freshIds = (window.nzHeartbeatCore ? window.nzHeartbeatCore.nzFreshIds(ids, seen) : ids.filter(function(id){ return !seen.has(id); }));

                        if (freshIds.length > 0) {
                            playAudio('new_order');
                            dismissed = false;
                            freshIds.forEach(function(id){ seen.add(id); });
                            saveSeen(seen);
                            showToast(total, label);
                        } else if (!dismissed) {
                            showToast(total, label);
                        }
                    }
                });
            }
            poll();
            setInterval(poll, 6000);
        })();
        @endif

        // 哪吒: 商家面板聊天「新消息轮询」——FCM web push 不稳，这里用后端轮询兜底：
        // 每 12s 查最新「非本人发」消息 id，增长即响铃 + toast + 刷新会话列表/当前会话(保留草稿)。
        @if(\App\CentralLogics\Helpers::employee_module_permission_check('chat'))
        (function(){
            var LS_KEY = 'nz_seen_chat_msg_id_v1';
            var stored = null;
            try { stored = localStorage.getItem(LS_KEY); } catch(e){}
            var lastSeen = stored === null ? null : (parseInt(stored, 10) || 0);
            // 哪吒: 客服(admin)会话单独一条基线，与顾客会话分开计数，方便用不同音色提示
            var ADMIN_LS_KEY = 'nz_seen_admin_msg_id_v1';
            var storedAdmin = null;
            try { storedAdmin = localStorage.getItem(ADMIN_LS_KEY); } catch(e){}
            var lastSeenAdmin = storedAdmin === null ? null : (parseInt(storedAdmin, 10) || 0);
            var baseUrl = '{{url('/')}}';

            function refreshOpenThread(){
                if (typeof getUrlParameter !== 'function') { return; }
                var conversation_id = getUrlParameter('conversation');
                var user_id = getUrlParameter('user');
                if (!conversation_id) { return; }
                var ta = document.getElementById('msgInputValue');
                var draft = ta ? ta.value : '';
                $.get(baseUrl + '/restaurant-panel/message/view/' + conversation_id + '/' + user_id, function(data){
                    $('#view-conversation').html(data.view);
                    var ta2 = document.getElementById('msgInputValue');
                    if (ta2 && draft) { ta2.value = draft; }
                });
            }

            function pollChat(){
                $.get({
                    url: '{{ route('vendor.message.live-status') }}',
                    dataType: 'json',
                    success: function(res){
                        // 顾客会话最新「非本人发」消息 id；旧后端无该字段时回退 latest_incoming_id
                        var latest = parseInt((res && (res.latest_customer_incoming_id !== undefined ? res.latest_customer_incoming_id : res.latest_incoming_id)) || 0, 10) || 0;
                        // 客服(admin)会话最新「非本人发」消息 id
                        var latestAdmin = parseInt((res && res.latest_admin_incoming_id) || 0, 10) || 0;

                        // 本浏览器首次：仅校准基线，不为历史消息响铃
                        if (lastSeenAdmin === null) {
                            lastSeenAdmin = latestAdmin;
                            try { localStorage.setItem(ADMIN_LS_KEY, String(latestAdmin)); } catch(e){}
                        }
                        if (lastSeen === null) {
                            lastSeen = latest;
                            try { localStorage.setItem(LS_KEY, String(latest)); } catch(e){}
                            return;
                        }

                        function nzPlay(id){ try { var a = document.getElementById(id); if (a) { var _c=(id==='nzAdminMsgAudio')?'platform_msg':'customer_msg'; var _v=(window.nzSound?window.nzSound.getVol(_c):1); if(_v<=0)return; a.volume=_v; a.currentTime = 0; var p = a.play(); if (p && p.catch) { p.catch(function(){}); } } } catch(e){} }
                        // 正开着该会话(conversation 参数=新消息所属会话)时不重复响铃/弹窗，仅静默刷新
                        function nzViewing(convId){ if (!convId) return false; if (typeof document !== 'undefined' && document.hidden) return false; var oc = (typeof getUrlParameter === 'function') ? getUrlParameter('conversation') : null; return !!(oc && String(oc) === String(convId)); }
                        function nzRefreshLists(){
                            if (!document.getElementById('conversation-list')) { return; }
                            var ctab = (typeof getUrlParameter === 'function' && getUrlParameter('tab') && getUrlParameter('tab') !== 'undefined') ? getUrlParameter('tab') : 'customer';
                            $.get(baseUrl + '/restaurant-panel/message/list?tab=' + ctab, function(d){
                                $('#conversation-list').empty().append(d.html);
                                $('#admin-conversation-list').empty().append(d.admin_html);
                                var uid = (typeof getUrlParameter === 'function') ? getUrlParameter('user') : null;
                                if (uid) { $('.customer-list').removeClass('conv-active'); $('#customer-' + uid).addClass('conv-active'); }
                            });
                            refreshOpenThread();
                        }

                        // 客服(平台/admin)新消息：专用音色(叮咚铃+低音人声) + 蓝色 toast，与顾客消息区分
                        if (latestAdmin > lastSeenAdmin) {
                            lastSeenAdmin = latestAdmin;
                            try { localStorage.setItem(ADMIN_LS_KEY, String(latestAdmin)); } catch(e){}
                            if (!nzViewing(res.latest_admin_incoming_conv_id)) {
                                nzPlay('nzAdminMsgAudio');
                                if (typeof toastr !== 'undefined') {
                                    toastr.info('客服来消息了', {
                                        CloseButton: true,
                                        ProgressBar: true,
                                        onclick: function(){ location.href = baseUrl + '/restaurant-panel/message/list?tab=admin'; }
                                    });
                                }
                            }
                            nzRefreshLists();
                        }

                        // 顾客新消息：原音色 + 绿色 toast
                        if (latest > lastSeen) {
                            lastSeen = latest;
                            try { localStorage.setItem(LS_KEY, String(latest)); } catch(e){}
                            if (!nzViewing(res.latest_customer_incoming_conv_id)) {
                                nzPlay('nzMsgAudio');
                                if (typeof toastr !== 'undefined') {
                                    toastr.success('{{ translate('messages.New message arrived') }}', {
                                        CloseButton: true,
                                        ProgressBar: true,
                                        onclick: function(){ location.href = baseUrl + '/restaurant-panel/message/list'; }
                                    });
                                }
                            }
                            nzRefreshLists();
                        }
                    }
                });
            }
            pollChat();
            setInterval(pollChat, 12000);
        })();
        @endif

        $(document).on('click', '.check-order', function () {
            location.href = '{{url('/')}}/restaurant-panel/order/list/all';
        });
        $(document).on('click', '.check-message', function () {
            var tab = getUrlParameter('tab');
            location.href = '{{ route('vendor.message.list') }}'+ '?tab=' + tab;
        });


    @if (isset($fcm_credentials['apiKey']) && is_string($fcm_credentials['apiKey']) && strlen($fcm_credentials['apiKey'])  > 3 )
        startFCM();
        @endif
    converationList();

    if(getUrlParameter('conversation')){
        conversationView();
    }

    $(document).on('click', '.call-demo', function () {
            @if(env('APP_MODE') =='demo')
            toastr.info('{{ translate('Update option is disabled for demo!') }}', {
                CloseButton: true,
                ProgressBar: true
            });
            @endif
        });


    if (/MSIE \d|Trident.*rv:/.test(navigator.userAgent)) document.write('<script src="{{dynamicAsset('assets/admin')}}/vendor/babel-polyfill/polyfill.min.js"><\/script>');

    $(window).on('load', ()=> $('.pre--loader').fadeOut(600))

    $('.log-out').on('click',function (){
        Swal.fire({
        title: '{{ translate('Do you want to logout?') }}',
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonColor: '#FC6A57',
        cancelButtonColor: '#363636',
        confirmButtonText: `{{ translate('yes')}}`,
        cancelButtonText: `{{ translate('cancel')}}`,
        }).then((result) => {
        if (result.value) {
            location.href='{{route('logout')}}';
            }
        })
    });


        function initTelInputs() {
            const inputs = document.querySelectorAll('input[type="tel"]');

            inputs.forEach(input => {

                const iti = window.intlTelInput(input, {
                    initialCountry: "{{$countryCode}}",
                    utilsScript: "{{ dynamicAsset('assets/admin/intltelinput/js/utils.js') }}",
                    autoInsertDialCode: true,
                    nationalMode: false,
                    formatOnDisplay: false,
                    strictMode: true,
                    @if (\App\CentralLogics\Helpers::get_business_settings('country_picker_status') != 1)
                        onlyCountries: ["{{$countryCode}}"],
                    @endif
                });

                const restoreDialCode = () => {
                    if (input.value.trim() === '') {
                        input.value = '+' + iti.getSelectedCountryData().dialCode;
                    }
                };

                input.addEventListener('blur', restoreDialCode);
                input.closest('form')?.addEventListener('submit', restoreDialCode);
            });

            $(document).off('keyup.telinput').on('keyup.telinput', 'input[type="tel"]', function () {
                const iti = window.intlTelInputGlobals.getInstance(this);
                if (!iti) return;

                let val = $(this).val();
                if (val.trim() === '') {
                    val = '+' + iti.getSelectedCountryData().dialCode;
                } else {
                    const plus = val.startsWith('+') ? '+' : '';
                    val = plus + val.replace(/[^\d]/g, '');
                }

                $(this).val(val);
            });
        }


    document.addEventListener('DOMContentLoaded', function() {
                initTelInputs();
            });

</script>


    {{-- 哪吒 P3 接单机模式: 断连横幅(轮询≥60s 无成功) + 屏幕常亮状态胶囊(仅作业台显·JS 控制) --}}
    <div id="nzDisconnectBar" style="display:none;position:fixed;top:0;left:0;right:0;z-index:13000;background:#F9EAE8;color:#AE4840;font-size:13.5px;font-weight:600;text-align:center;padding:9px 14px;border-bottom:2px solid #AE4840;font-family:'Noto Sans Armenian','Segoe UI','Microsoft YaHei','PingFang SC',sans-serif;">
        ⚠️ 后台已断开连接，可能收不到新单——请检查网络 / WiFi
    </div>
    <div id="nzWlChip" style="display:none;position:fixed;right:12px;bottom:12px;z-index:12500;align-items:center;gap:5px;background:#E5F1EA;color:#2B7A57;font-size:12px;font-weight:600;border-radius:999px;padding:6px 12px;border:1px solid #CFE6DA;box-shadow:0 2px 8px rgba(23,28,38,.10);font-family:'Noto Sans Armenian','Segoe UI','Microsoft YaHei','PingFang SC',sans-serif;">
        屏幕常亮
    </div>
    <script>
    // 哪吒 P3 接单机模式: 作业台屏幕常亮(Screen Wake Lock)。仅作业台页 + 页面可见时请求; tab 隐藏/锁屏自动释放, 回到前台重申。
    (function(){
        var nzWL = null;
        function nzWLWanted(){ return location.pathname.indexOf('/restaurant-panel/workbench') !== -1 && !document.hidden; }
        function nzWLChip(on){ var c = document.getElementById('nzWlChip'); if (c) c.style.display = on ? 'inline-flex' : 'none'; }
        function nzWLAcquire(){
            if (!('wakeLock' in navigator) || !nzWLWanted()) { return; }
            navigator.wakeLock.request('screen').then(function(s){
                nzWL = s; nzWLChip(true);
                s.addEventListener('release', function(){ nzWL = null; if (!nzWLWanted()) nzWLChip(false); });
            }).catch(function(){ nzWL = null; nzWLChip(false); });
        }
        document.addEventListener('visibilitychange', function(){
            if (document.hidden) { nzWLChip(false); } else { nzWLAcquire(); }   // 隐藏时锁自动释放; 回前台重申
        });
        if (document.readyState !== 'loading') { nzWLAcquire(); } else { document.addEventListener('DOMContentLoaded', nzWLAcquire); }
    })();
    </script>

    {{-- 哪吒商家版App: 在场感知心跳 + FCM token 上报 + 停报警(仅在 App 内激活, 普通浏览器零副作用) --}}
    <script src="{{ dynamicAsset('assets/admin/nezha-app-bridge.js') }}?v=1"></script>
</body>
</html>
