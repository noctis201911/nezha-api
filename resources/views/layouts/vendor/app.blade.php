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
    <!-- 哪吒: 新订单非阻塞提示条 (响一次不反复弹窗) -->
    <div id="nz-new-order-toast" style="display:none;position:fixed;right:20px;bottom:20px;z-index:100000;background:#fff;border:1px solid #f0f0f0;border-left:4px solid #C4193E;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.15);padding:14px 16px;min-width:248px;max-width:320px;font-family:'PingFang SC','Microsoft YaHei',sans-serif;">
        <div style="display:flex;align-items:flex-start;gap:10px;">
            <div style="font-size:22px;line-height:1;">&#128276;</div>
            <div style="flex:1;">
                <div style="font-weight:600;color:#1f1f1f;font-size:15px;margin-bottom:2px;"><span id="nz-new-order-count">0</span> 个新订单<span id="nz-new-order-label">待处理</span></div>
                <div style="color:#8a8a8a;font-size:12px;">点「立即接单」直接进对应订单列表</div>
            </div>
            <button type="button" id="nz-new-order-close" aria-label="关闭" style="border:none;background:none;color:#bbb;font-size:20px;line-height:1;cursor:pointer;padding:0;">&times;</button>
        </div>
        <button type="button" id="nz-new-order-go" style="margin-top:10px;width:100%;background:#C4193E;color:#fff;border:none;border-radius:8px;padding:9px 0;font-size:14px;font-weight:600;cursor:pointer;">立即接单</button>
    </div>
    <!-- 哪吒: 订单超时紧急提示条 (系统/面板渠道, 红色高优先, 独立于新订单提示) -->
    <div id="nz-timeout-toast" style="display:none;position:fixed;right:20px;bottom:96px;z-index:100001;background:#fff;border:1px solid #f3c2c2;border-left:4px solid #d32029;border-radius:12px;box-shadow:0 6px 24px rgba(211,32,41,.2);padding:14px 16px;min-width:248px;max-width:320px;font-family:'PingFang SC','Microsoft YaHei',sans-serif;">
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
<script>
    // 哪吒: 首次用户交互时解锁提示音(绕开浏览器自动播放限制)——之后轮询新消息能稳定响铃
    (function(){
        function nzUnlockAudio(){
            ['myAudio','nzMsgAudio','nzAdminMsgAudio'].forEach(function(id){
                var a = document.getElementById(id);
                if(!a) return;
                try {
                    a.muted = true;
                    var p = a.play();
                    if (p && p.then) { p.then(function(){ a.pause(); a.currentTime = 0; a.muted = false; }).catch(function(){ a.muted = false; }); }
                    else { a.pause(); a.currentTime = 0; a.muted = false; }
                } catch(e){ a.muted = false; }
            });
            document.removeEventListener('click', nzUnlockAudio);
            document.removeEventListener('keydown', nzUnlockAudio);
            document.removeEventListener('touchstart', nzUnlockAudio);
        }
        document.addEventListener('click', nzUnlockAudio);
        document.addEventListener('keydown', nzUnlockAudio);
        document.addEventListener('touchstart', nzUnlockAudio);
    })();
</script>

<script>
        "use strict";
    let audio = document.getElementById("myAudio");

    function playAudio() {
        try { var p = audio.play(); if (p && p.catch) { p.catch(function(){}); } } catch(e){}
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
            playAudio();
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
            function updateTimeoutToast(data){
                var ids = (data.timeout_order_ids || []).map(String);
                var total = data.timeout_total || 0;
                toTarget = data.timeout_target || 'pending';
                if (total === 0) { toHide(); toDismissed = false; return; }
                var seen = toLoadSeen();
                var freshIds = ids.filter(function(id){ return !seen.has(id); });
                if (freshIds.length > 0) {
                    playAudio();
                    toDismissed = false;
                    freshIds.forEach(function(id){ seen.add(id); });
                    toSaveSeen(seen);
                    toShow(total);
                } else if (!toDismissed) {
                    toShow(total);
                }
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
                if (countEl) { countEl.textContent = count; }
                if (labelEl && label) { labelEl.textContent = label; }
                if (toast) { toast.style.display = 'block'; }
            }
            function hideToast(){
                if (toast) { toast.style.display = 'none'; }
            }
            if (goBtn) { goBtn.addEventListener('click', function(){ location.href = listBase + currentTarget; }); }
            if (closeBtn) { closeBtn.addEventListener('click', function(){ dismissed = true; hideToast(); }); }

            function poll(){
                $.get({
                    url: '{{route('vendor.get-restaurant-data')}}',
                    dataType: 'json',
                    success: function (response) {
                        var data = response.data || {};
                        updateTimeoutToast(data);
                        var ids = (data.new_order_ids || []).map(String);
                        var total = data.new_total || 0;
                        currentTarget = data.target || 'pending';

                        if (total === 0) { hideToast(); dismissed = false; return; }

                        var label = data.target_label || '待处理';
                        var seen = loadSeen();
                        var freshIds = ids.filter(function(id){ return !seen.has(id); });

                        if (freshIds.length > 0) {
                            playAudio();
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

                        function nzPlay(id){ try { var a = document.getElementById(id); if (a) { a.currentTime = 0; var p = a.play(); if (p && p.catch) { p.catch(function(){}); } } } catch(e){} }
                        // 正开着该会话(conversation 参数=新消息所属会话)时不重复响铃/弹窗，仅静默刷新
                        function nzViewing(convId){ if (!convId) return false; var oc = (typeof getUrlParameter === 'function') ? getUrlParameter('conversation') : null; return !!(oc && String(oc) === String(convId)); }
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


</body>
</html>
