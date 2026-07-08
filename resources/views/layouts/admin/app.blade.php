<!DOCTYPE html>
<?php
$site_direction = session()->get('site_direction');
$country = \App\CentralLogics\Helpers::get_business_settings('country');
$countryCode = strtolower($country ?? 'auto');
?>

<html dir="{{ $site_direction }}" lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="{{ $site_direction === 'rtl' ? 'active' : '' }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <!-- Title -->
    <title>@yield('title')</title>
    <!-- Favicon -->
    @php($logo = \App\CentralLogics\Helpers::get_business_settings('icon'))
    <link rel="shortcut icon" href="">
    <link rel="icon" type="image/x-icon" href="{{ dynamicStorage('storage/app/public/business/' . $logo ?? '') }}">
    <!-- Font -->
    <link href="{{dynamicAsset('assets/admin/css/fonts.css')}}" rel="stylesheet">
    <!-- CSS Implementing Plugins -->
    <link rel="stylesheet" href="{{ dynamicAsset('assets/admin/css/vendor.min.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset('assets/admin/vendor/icon-set/style.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset('assets/admin/css/custom.css') }}">
    <!-- CSS Front Template -->
    <link rel="stylesheet" href="{{dynamicAsset('assets/admin/plugins/lightbox/css/lightbox.css')}}">

    <link rel="stylesheet" href="{{dynamicAsset('assets/admin/css/owl.min.css')}}">
    <link rel="stylesheet" href="{{ dynamicAsset('assets/admin/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset('assets/admin/css/emojionearea.min.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset('assets/admin/css/theme.minc619.css?v=1.0') }}">
    <link rel="stylesheet" href="{{ dynamicAsset('assets/admin/css/style.css') }}">
    <link rel="stylesheet" href="{{dynamicAsset('assets/admin/intltelinput/css/intlTelInput.css')}}">
    @stack('css_or_js')
    <link rel="stylesheet" href="{{ dynamicAsset('assets/admin/css/toastr.css') }}">
    <style>
        /* 哪吒M2: 全站字体清晰度 —— 中文字形回退(Inter 无中文字形, 避免落低质通用 sans-serif) + 灰阶抗锯齿(白字压藏青更锐) */
        body { font-family: "Inter", "PingFang SC", "Microsoft YaHei", "Noto Sans SC", "Hiragino Sans GB", "Heiti SC", sans-serif; }
        html, body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
    </style>
</head>

<body class="footer-offset">

    @if(env('APP_MODE') == 'demo')
        <div id="direction-toggle" class="direction-toggle">
            <i class="tio-settings"></i>
            <span></span>
        </div>
    @endif
    <div id="pre--loader" class="pre--loader">
    </div>
    <div class="container">
        <div class="row">
            <div class="col-md-12">
                <div id="loading" class="initial-hidden">
                    <div class="loading--1">
                        <img width="200" src="{{ dynamicAsset('assets/admin/img/loader.gif') }}">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Builder -->
    @include('layouts.admin.partials._front-settings')
    <!-- End Builder -->

    <!-- JS Preview mode only -->
    @include('layouts.admin.partials._header')
    @include('layouts.admin.partials._sidebar')
    <!-- END ONLY DEV -->

    <main id="content" role="main" class="main pointer-event">
        <!-- Content -->
        @yield('content')
        <!-- End Content -->

        <!-- Footer -->
        @include('layouts.admin.partials._footer')
        <!-- End Footer -->

        <div class="modal fade" id="popup-modal">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-12">
                                <div class="text-center">
                                    <h2 class="color-8a8a8a">
                                        <i class="tio-shopping-cart-outlined"></i>
                                        {{translate('messages.You_have_new_order_Check_Please.')}}
                                    </h2>
                                    <hr>
                                    <button
                                        class="btn btn-primary check-order">{{translate('messages.Ok_let_me_check')}}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 哪吒[2026-06-22]: 超管「异常订单」非阻塞提示条(超时单+逾期退款单, 响一次不反复弹; 集合清零自动收起) -->
        @if(false){{-- 哪吒M2-D3: 旧异常订单浮窗已收编进顶栏铃铛通知栈; 封存不删, 回滚=改回 true --}}
        <div id="nz-admin-abn-toast" style="display:none;position:fixed;right:20px;bottom:20px;z-index:100000;background:#fff;border:1px solid #f0f0f0;border-left:4px solid #C4193E;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.15);padding:14px 16px;min-width:260px;max-width:340px;font-family:'PingFang SC','Microsoft YaHei',sans-serif;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">
                <div>
                    <div style="font-weight:600;color:#1f1f1f;font-size:15px;margin-bottom:2px;"><span id="nz-abn-count">0</span> 个异常订单待处理</div>
                    <div style="color:#888;font-size:12px;" id="nz-abn-detail">超时 0 · 退款逾期 0</div>
                </div>
                <button type="button" id="nz-abn-close" aria-label="关闭" style="border:none;background:none;color:#bbb;font-size:20px;line-height:1;cursor:pointer;padding:0;">&times;</button>
            </div>
            <button type="button" id="nz-abn-go" style="margin-top:10px;width:100%;background:#C4193E;color:#fff;border:none;border-radius:8px;padding:9px 0;font-size:14px;font-weight:600;cursor:pointer;">去处理</button>
        </div>
        @endif
        <div class="modal fade" id="popup-modal-msg">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-12">
                                <div class="text-center">
                                    <h2 class="color-8a8a8a">
                                        <i class="tio-messages"></i> {{ translate('messages.message_description') }}
                                    </h2>
                                    <hr>
                                    <button
                                        class="btn btn-primary check-message">{{ translate('messages.Ok_let_me_check') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

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
                                    <h5 class="modal-title font-medium mb-4" id="toggle-title"></h5>
                                </div>
                                <div class="text-center" id="toggle-message">
                                </div>
                            </div>
                            <div class="btn--container justify-content-center">
                                <button type="button" id="toggle-ok-button"
                                    class="btn btn--primary min-w-120 confirm-Toggle"
                                    data-dismiss="modal">{{translate('Ok')}}</button>
                                <button id="reset_btn" type="reset" class="btn btn--cancel min-w-120"
                                    data-dismiss="modal">
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
                                    <img id="toggle-status-image" alt="" class="mb-20 initial-10">
                                    <h5 class="modal-title" id="toggle-status-title"></h5>
                                </div>
                                <div class="text-center" id="toggle-status-message">
                                </div>
                            </div>
                            <div class="btn--container justify-content-center">
                                <button type="button" id="toggle-status-ok-button"
                                    class="btn btn--primary min-w-120 confirm-Status-Toggle"
                                    data-dismiss="modal">{{translate('Ok')}}</button>
                                <button id="reset_btn" type="reset" class="btn btn--cancel min-w-120"
                                    data-dismiss="modal">
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
                                <textarea class="form-control" placeholder="{{ translate('your_note_here') }}"
                                    id="get-text-note" cols="5"></textarea>
                            </div>
                            <div class="btn--container justify-content-center">
                                <div id="hide-buttons">
                                    <button data-dismiss="modal" id="cancel_btn_text"
                                        class="btn btn-outline-secondary min-w-120">{{translate("Not_Now")}}</button>
                                    &nbsp;
                                    <button type="button" id="new-dynamic-ok-button"
                                        class="btn btn-outline-danger confirm-model min-w-120">{{translate('Yes')}}</button>
                                </div>

                                <button data-dismiss="modal" type="button" id="new-dynamic-ok-button-show"
                                    class="btn btn--primary  d-none min-w-120">{{translate('Okay')}}</button>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!--- Global Image -->
        <div id="imageModal" class="imageModal modal fade" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header justify-content-end gap-3 border-0 p-2">
                        <button type="button"
                            class="modal_img-btn border-0 btn-circle rounded-circle bg-section2 shadow-none fs-8 m-0"
                            data-dismiss="modal" aria-label="Close">
                            <i class="tio-clear"></i>
                        </button>
                    </div>
                    <div class="modal-body text-center p-10 pt-0">
                        <div class="imageModal_img_wrapper">
                            <img src="" class="img-fluid imageModal_img" alt="{{ translate('Preview_Image') }}">
                            <div class="imageModal_btn_wrapper">
                                <a href="javascript:" class="btn icon-btn download_btn"
                                    title="{{ translate('Download') }}" download>
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
    <!-- ========== END MAIN CONTENT ========== -->

    <!-- ========== END SECONDARY CONTENTS ========== -->
    <script src="{{ dynamicAsset('assets/admin/js/custom.js') }}"></script>
    <!-- JS Implementing Plugins -->
    <!-- The core Firebase JS SDK is always required and must be listed first -->
    <script src="{{dynamicAsset('assets/admin/js/jquery.min.js')}}"></script>

    <script>
        let placeholderImageUrl = "{{ dynamicAsset('assets/admin/img/svg/image-upload.svg') }}";
        const iconPath = "{{ dynamicAsset('assets/admin/svg/icons/file.svg') }}";
    </script>

    <script>
        "use strict";
        setTimeout(hide_loader, 1000);
        function hide_loader() {
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
    <script>

    </script>
    <script src="{{dynamicAsset('assets/admin/js/firebase.min.js')}}"></script>

    @stack('script')
    <!-- JS Front -->
    <script
        src="{{ dynamicAsset('assets/admin/vendor/hs-navbar-vertical-aside/hs-navbar-vertical-aside-mini-cache.js') }}"></script>
    <script src="{{ dynamicAsset('assets/admin/js/vendor.min.js') }}"></script>
    <script src="{{ dynamicAsset('assets/admin/js/theme.min.js') }}"></script>
    <script src="{{ dynamicAsset('assets/admin/js/sweet_alert.js') }}"></script>
    <script src="{{ dynamicAsset('assets/admin/js/toastr.js') }}"></script>
    <script src="{{dynamicAsset('assets/admin/js/owl.min.js')}}"></script>
    <script src="{{dynamicAsset('assets/admin/intltelinput/js/intlTelInput.min.js')}}"></script>

    <script src="{{ dynamicAsset('assets/admin/plugins/lightbox/js/lightbox.min.js')}}"></script>
    <script src="{{ dynamicAsset('assets/admin/plugins/file-upload/multiple-file-upload.js')}}"></script>
    <script>
        $(document).ready(function () {
            var $owl = $(".myOffcanvasOwl");
            var $fraction = $(".swiper-pagination-fraction");

            $owl.owlCarousel({
                items: 1,
                margin: 10,
                loop: true,
                dots: false,
                nav: false,
                autoplay: false,
                smartSpeed: 600,
                onInitialized: updateFraction,
                onTranslated: updateFraction,
            });

            function updateFraction(event) {
                var items = event.item.count;
                var item = event.item.index - event.relatedTarget._clones.length / 2;
                if (item > items || item < 1) item = ((item % items) + items) % items;
                $fraction.text(item + "/" + items);
            }

            $(".owl-prev-btn").click(function () {
                $owl.trigger("prev.owl.carousel");
            });

            $(".owl-next-btn").click(function () {
                $owl.trigger("next.owl.carousel");
            });

            $(document).on("shown.bs.collapse", ".collapse", function () {
                $(this).find(".myOffcanvasOwl").each(function () {
                    $(this).trigger("refresh.owl.carousel");
                });
            });
        });
    </script>


    <script>
        "use strict";

        $('.blinkings').on('mouseover', () => $('.blinkings').removeClass('active'))
        $('.blinkings').addClass('open-shadow')
        setTimeout(() => {
            $('.blinkings').removeClass('active')
        }, 10000);
        setTimeout(() => {
            $('.blinkings').removeClass('open-shadow')
        }, 5000);

        $(function () {
            var owl = $('.single-item-slider');
            owl.owlCarousel({
                autoplay: false,
                items: 1,
                onInitialized: counter,
                onTranslated: counter,
                autoHeight: true,
                dots: true,
                rtl: {{ $site_direction == 'rtl' ? "true" : "false"}}
           });

            function counter(event) {
                var element = event.target;         // DOM element, in this example .owl-carousel
                var items = event.item.count;     // Number of items
                var item = event.item.index + 1;     // Position of the current item

                // it loop is true then reset counter from 1
                if (item > items) {
                    item = item - items
                }
                $('.slide-counter').html(+item + "/" + items)
            }
        });
    </script>
    {!! Toastr::message() !!}

    @if ($errors->any())
        <script>
            "use strict";
            @foreach ($errors->all() as $error)
                toastr.error('{{ translate($error) }}');
            @endforeach
        </script>
    @endif

    <script>
        "use strict";
        $(document).on('ready', function () {
            $(".direction-toggle").on("click", function () {
                if ($('html').hasClass('active')) {
                    $('html').removeClass('active')
                    setDirection(1);
                } else {
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
                if (status === 1) {
                    $("html").attr('dir', 'ltr');
                    $(".direction-toggle").find('span').text('Toggle RTL')
                } else {
                    $("html").attr('dir', 'rtl');
                    $(".direction-toggle").find('span').text('Toggle LTR')
                }
                $.get({
                    url: '{{ route('admin.business-settings.site_direction') }}',
                    dataType: 'json',
                    data: {
                        status: status,
                    },
                    success: function () {
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
            $('.js-nav-tooltip-link').tooltip({
                boundary: 'window'
            })

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
                $('#js-daterangepicker-predefined .js-daterangepicker-predefined-preview').html(start.format(
                    'MMM D') + ' - ' + end.format('MMM D, YYYY'));
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
                    'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1,
                        'month').endOf('month')]
                }
            }, cb);

            cb(start, end);


            // INITIALIZATION OF CLIPBOARD
            // =======================================================
            $('.js-clipboard').each(function () {
                var clipboard = $.HSCore.components.HSClipboard.init(this);
            });
        });
    </script>

    @stack('script_2')
    <script>
        "use strict";
        let baseUrl = '{{ url('/') }}';
    </script>
    <script src="{{dynamicAsset('assets/admin/js/view-pages/common.js')}}"></script>
    <script src="{{dynamicAsset('assets/admin/js/keyword-highlighted.js')}}"></script>
    <audio id="myAudio">
        <source src="{{ dynamicAsset('assets/admin/sound/notification.mp3') }}" type="audio/mpeg">
    </audio>

    <script>
        "use strict";
        var audio = document.getElementById("myAudio");

        var nzAudioPrimed = false;
        function nzPrimeAudio(){ if(nzAudioPrimed){return;} nzAudioPrimed = true; try { var p = audio.play(); if(p&&p.then){ p.then(function(){ audio.pause(); audio.currentTime=0; }).catch(function(){}); } } catch(e){} }
        ['click','keydown','touchstart'].forEach(function(ev){ document.addEventListener(ev, nzPrimeAudio); });
        function playAudio() {
            try { audio.currentTime = 0; var p = audio.play(); if(p&&p.catch){ p.catch(function(){}); } } catch(e){}
        }

        function pauseAudio() {
            audio.pause();
        }

        $('.route-alert').on('click', function () {
            let route = $(this).data('url')
            let message = $(this).data('message')
            let title = $(this).data('title')
            let processing = $(this).data('processing')
            route_alert(route, message, title, processing);
        })
        function route_alert(route, message, title = "{{ translate('messages.are_you_sure') }}", processing = false) {
            if (processing) {
                Swal.fire({
                    title: title,
                    type: 'warning',
                    showCancelButton: true,
                    cancelButtonColor: 'default',
                    confirmButtonColor: '#FC6A57',
                    cancelButtonText: "{{ translate('messages.Cancel') }}",
                    confirmButtonText: "{{ translate('messages.Submit') }}",
                    inputPlaceholder: "{{ translate('messages.Enter_processing_time') }}",
                    input: 'text',
                    html: message + '<br/>' + '<label>{{ translate('messages.Enter_Processing_time_in_minutes') }}</label>',
                    inputValue: processing,
                    preConfirm: (processing_time) => {
                        location.href = route + '&processing_time=' + processing_time;
                    },
                    allowOutsideClick: () => !Swal.isLoading()
                })
            } else {
                Swal.fire({
                    title: title,
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

        }

        $('.form-alert').on('click', function () {
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
                    $('#' + id).submit()
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
                        data: { search: searchKeyword, _token: $('input[name="_token"]').val() },
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
                                        url: '{{ route('admin.store.clicked.route') }}',
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

        document.addEventListener('keydown', function (event) {
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
                    url: '{{ route('admin.recent.search') }}',
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
                                    url: '{{ route('admin.store.clicked.route') }}',
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
        searchInput.addEventListener('search', function () {
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
        @if (isset($fcm_credentials['apiKey']) && is_string($fcm_credentials['apiKey']) && strlen($fcm_credentials['apiKey']) > 3)
            firebase.initializeApp(firebaseConfig);
            const messaging = firebase.messaging();
        @endif

            function startFCM() {
                messaging
                    .requestPermission()
                    .then(function () {
                        return messaging.getToken();
                    })
                    .then(function (token) {
                        // console.log('FCM Token:', token);
                        // Send the token to your backend to subscribe to topic
                        subscribeTokenToBackend(token, 'admin_message');
                    }).catch(function (error) {
                        console.error('Error getting permission or token:', error.message);
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
                if (sParameterName[0] == sParam) {
                    return sParameterName[1];
                }
            }
        }

        function conversationList() {
            var tab = getUrlParameter('tab');
            $.ajax({
                url: "{{ route('admin.message.list') }}" + '?tab=' + tab,
                success: function (data) {
                    $('#conversation-list').empty();
                    $("#conversation-list").append(data.html);
                    var user_id = getUrlParameter('user');
                    $('.customer-list').removeClass('conv-active');
                    $('#customer-' + user_id).addClass('conv-active');
                }
            })
        }

        function conversationView() {
            var conversation_id = getUrlParameter('conversation');
            var user_id = getUrlParameter('user');
            var url = '{{url('/')}}/admin/message/view/' + conversation_id + '/' + user_id;
            $.ajax({
                url: url,
                success: function (data) {
                    $('#view-conversation').html(data.view);
                }
            })
        }

        function vendorConversationView() {
            var conversation_id = getUrlParameter('conversation');
            var user_id = getUrlParameter('user');
            var url = '{{url('/')}}/admin/restaurant/message/' + conversation_id + '/' + user_id;
            $.ajax({
                url: url,
                success: function (data) {
                    $('#vendor-view-conversation').html(data.view);
                }
            })
        }

        function dmConversationView() {
            var conversation_id = getUrlParameter('conversation');
            var user_id = getUrlParameter('user');
            var url = '{{url('/')}}/admin/delivery-man/message/' + conversation_id + '/' + user_id;
            $.ajax({
                url: url,
                success: function (data) {
                    $('#dm-view-conversation').html(data.view);
                }
            })
        }
        @php($order_notification_type = \App\CentralLogics\Helpers::get_business_settings('order_notification_type') ?? 'firebase')
        var new_order_type = 'restaurant_order';

        @if (isset($fcm_credentials['apiKey']) && is_string($fcm_credentials['apiKey']) && strlen($fcm_credentials['apiKey']) > 3)

        messaging.onMessage(function (payload) {
            console.log(payload.data);
            if (payload.data.order_id && payload.data.type == "order_request") {
                // 哪吒[2026-06-22]: B方案平台不接单, 超管不报新订单(铃声+居中弹窗已彻底停用); 异常订单提醒走下方 nz-admin-abn-toast。

            } else if (payload.data.type == 'message') {
                var conversation_id = getUrlParameter('conversation');
                var user_id = getUrlParameter('user');
                var url = '{{url('/')}}/admin/message/view/' + conversation_id + '/' + user_id;
                console.log(url);
                $.ajax({
                    url: url,
                    success: function (data) {
                        $('#view-conversation').html(data.view);
                    }
                })
                toastr.success('{{ translate('New_message_arrived') }}', {
                    CloseButton: true,
                    ProgressBar: true
                });

                if ($('#conversation-list').scrollTop() == 0) {
                    conversationList();
                }
            }
        });
        @endif


        {{-- 哪吒[2026-06-22]: 超管新订单轮询弹窗已停用(B方案平台不接单)。异常订单(超时/逾期退款)提醒走 nz-admin-abn-toast 独立轮询块。 --}}

        @if (isset($fcm_credentials['apiKey']) && is_string($fcm_credentials['apiKey']) && strlen($fcm_credentials['apiKey']) > 3)
            startFCM();
        @endif
        conversationList();

        if (getUrlParameter('conversation')) {
            conversationView();
            vendorConversationView();
            dmConversationView();
        }

        $(document).on('click', '.call-demo', function (e) {
            @if(getEnvMode() == 'demo')
                toastr.info('{{ translate('Update option is disabled for demo!') }}', {
                    CloseButton: true,
                    ProgressBar: true
                });
                e.preventDefault();
            @endif
        });
        $(document).on('click', '.check-order', function () {
            location.href = '{{ route('admin.order.list', ['status' => 'all']) }}';
        });
        $(document).on('click', '.check-message', function () {
            var tab = getUrlParameter('tab');
            location.href = '{{ route('admin.message.list') }}' + '?tab=' + tab;
        });

        if (/MSIE \d|Trident.*rv:/.test(navigator.userAgent)) document.write(
            '<script src="{{ dynamicAsset('assets/admin') }}/vendor/babel-polyfill/polyfill.min.js"><\/script>');

        $(window).on('load', () => $('.pre--loader').fadeOut(600))

        $('.log-out').on('click', function () {
            Swal.fire({
                title: '{{ translate('Do_You_Want_To_Sign_Out_?')}}',
                showDenyButton: true,
                showCancelButton: true,
                confirmButtonColor: '#FC6A57',
                cancelButtonColor: '#363636',
                confirmButtonText: `{{ translate('yes')}}`,
                cancelButtonText: `{{ translate('cancel')}}`,
            }).then((result) => {
                if (result.value) {
                    location.href = '{{route('logout')}}';
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


        document.addEventListener('DOMContentLoaded', function () {
            initTelInputs();
            $('body').tooltip({
                selector: 'input[readonly], input[disabled]',
                title: function () {
                    return $(this).attr('disable-title') || "{{ translate('This field is disabled') }}";
                },
                trigger: 'hover',
                placement: 'top',
                html: true
            });
        });

    </script>

    <script>
    @if(false) {{-- 哪吒M2-D3: 旧异常订单轮询已停(收编顶栏铃铛通知栈); 封存回滚=改回 module_permission_check('order') --}}
        "use strict";
        // 哪吒[2026-06-22]: 超管异常订单提醒(超时单/逾期退款单)。B方案平台不接单故不报新订单;
        // 只报需平台介入异常单, 响一次不反复弹(localStorage seen-set), 集合清零自动收起。
        (function(){
            var SEEN_KEY = 'nz_admin_abn_seen_v1';
            var toast   = document.getElementById('nz-admin-abn-toast');
            var countEl = document.getElementById('nz-abn-count');
            var detailEl= document.getElementById('nz-abn-detail');
            var goBtn   = document.getElementById('nz-abn-go');
            var closeBtn= document.getElementById('nz-abn-close');
            var detailsBase = '{{ url('/admin/order/details') }}';
            var firstId = null;
            var dismissed = false;
            var memSeen = new Set();
            function loadSeen(){ try { return new Set(JSON.parse(localStorage.getItem(SEEN_KEY) || '[]')); } catch(e){ return memSeen; } }
            function saveSeen(set){ memSeen = set; try { var arr = Array.from(set); if (arr.length > 200) { arr = arr.slice(arr.length - 200); } localStorage.setItem(SEEN_KEY, JSON.stringify(arr)); } catch(e){} }
            function show(total, tCount, rCount){ if(countEl){countEl.textContent=total;} if(detailEl){detailEl.textContent='超时 '+tCount+' · 退款逾期 '+rCount;} if(toast){toast.style.display='block';} }
            function hide(){ if(toast){toast.style.display='none';} }
            if (goBtn){ goBtn.addEventListener('click', function(){ if(firstId){ location.href = detailsBase + '/' + firstId; } }); }
            if (closeBtn){ closeBtn.addEventListener('click', function(){ dismissed = true; hide(); }); }
            function poll(){
                $.get({
                    url: '{{ route('admin.get-restaurant-data') }}',
                    dataType: 'json',
                    success: function(response){
                        var data = response.data || {};
                        var tIds = (data.abn_timeout_ids || []).map(String);
                        var rIds = (data.abn_refund_ids || []).map(String);
                        var ids = tIds.concat(rIds);
                        var total = data.abn_total || 0;
                        firstId = data.abn_first_order || (ids.length ? ids[0] : null);
                        if (total === 0){ hide(); dismissed = false; return; }
                        var seen = loadSeen();
                        var freshIds = ids.filter(function(id){ return !seen.has(id); });
                        if (freshIds.length > 0){
                            if (typeof playAudio === 'function') { playAudio(); }
                            dismissed = false;
                            freshIds.forEach(function(id){ seen.add(id); });
                            saveSeen(seen);
                            show(total, data.abn_timeout_total||0, data.abn_refund_total||0);
                        } else if (!dismissed) {
                            show(total, data.abn_timeout_total||0, data.abn_refund_total||0);
                        }
                    }
                });
            }
            poll();
            setInterval(poll, 12000);
        })();
    @endif

        @if(\App\CentralLogics\Helpers::module_permission_check('order'))
        // 哪吒M2-D3: 顶栏通知铃铛 poll+render(布局尾·jQuery就位·header已构建; render 每次重查元素防主题移动 header 引用失效)
        (function(){
            var detailsBase='{{ url('/admin/order/details') }}', overdueUrl='{{ route('admin.nezha-refund.overdue') }}', dataUrl='{{ route('admin.get-restaurant-data') }}';
            function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;}
            function humMin(m){ if(m==null)return''; if(m<60)return m+' 分钟'; var h=Math.floor(m/60); return h<48? h+' 小时' : Math.floor(h/24)+' 天'; }
            function humHr(h){ if(h==null)return''; return h<48? h+' 小时' : Math.floor(h/24)+' 天'; }
            function money(n){ try{return String.fromCharCode(1423)+Number(n).toLocaleString();}catch(e){return n;} }
            function render(d){
                var badge=document.getElementById('nzBellBadge'), body=document.getElementById('nzBellBody'), empty=document.getElementById('nzBellEmpty');
                if(!body) return;
                var t=d.abn_timeout_rows||[], r=d.abn_refund_rows||[], total=d.abn_total||0;
                if(badge) badge.style.display= total>0?'block':'none';
                body.querySelectorAll('.nz-bell-sec').forEach(function(s){s.remove();});
                if(total===0){ if(empty)empty.style.display='block'; return; }
                if(empty)empty.style.display='none';
                var html='';
                if(t.length){ html+='<div class="nz-bell-sec"><div class="nz-bell-h">订单异常 · '+(d.abn_timeout_total||t.length)+'</div>';
                    t.forEach(function(o){ html+='<a class="nz-bell-row" href="'+detailsBase+'/'+o.id+'"><div class="nz-bell-row__main"><div class="nz-bell-row__t">#'+esc(o.id)+'</div><div class="nz-bell-row__s">'+esc(o.reason)+' · 卡了 '+humMin(o.wait_min)+'</div></div><span class="nz-bell-cta">处理</span></a>'; });
                    html+='</div>'; }
                if(r.length){ html+='<div class="nz-bell-sec"><div class="nz-bell-h">逾期退款 · '+(d.abn_refund_total||r.length)+'</div>';
                    r.forEach(function(o){ html+='<a class="nz-bell-row" href="'+overdueUrl+'"><div class="nz-bell-row__main"><div class="nz-bell-row__t">'+esc(o.shop||('#'+o.order_id))+' · '+money(o.amount)+'</div><div class="nz-bell-row__s nz-bell-row__s--warn">逾期 '+humHr(o.overdue_hr)+'</div></div><span class="nz-bell-cta">催办</span></a>'; });
                    html+='</div>'; }
                body.insertAdjacentHTML('beforeend', html);
            }
            function poll(){ if(typeof window.jQuery==='undefined')return; window.jQuery.get({url:dataUrl,dataType:'json',success:function(resp){ render(resp.data||{}); }}); }
            poll(); setInterval(poll,15000);
        })();
        @endif

        // 哪吒M2-D5: 侧栏菜单过滤(布局尾·target 可见侧栏·防 #sidebarMain 模板被主题移动致引用失效; 匹配显示并展开所属组, 清空复原折叠态)
        (function(){
            function boot(){
                var input = document.getElementById('search');
                if(!input){ return false; }
                if(input._nzBound){ return true; }
                var content = input.closest('.navbar-vertical-content');
                var list = content ? content.querySelector('.navbar-nav') : null;
                if(!list){ return false; }
                input._nzBound = true;
                input.setAttribute('placeholder','过滤菜单…');
                Array.prototype.forEach.call(list.querySelectorAll('.nav-sub'), function(s){ s.dataset.nzOrig = s.style.display || ''; });
                function norm(v){ return (v||'').toLowerCase().trim(); }
                function apply(q){
                    q = norm(q);
                    var curSub=null, grpVis=false;
                    function flush(){ if(curSub){ curSub.style.display = (q===''||grpVis)?'':'none'; } }
                    Array.prototype.forEach.call(list.children, function(li){
                        if(li.querySelector('.nav-subtitle')){ flush(); curSub=li; grpVis=false; return; }
                        var m = (q==='') || norm(li.textContent).indexOf(q)!==-1;
                        li.style.display = m ? '' : 'none';
                        if(m){ grpVis=true; }
                        var sub = li.querySelector('.nav-sub');
                        if(sub){ if(q!==''&&m){ sub.style.display='block'; } else if(q===''){ sub.style.display = sub.dataset.nzOrig||''; } }
                    });
                    flush();
                }
                var t;
                input.addEventListener('input', function(){ clearTimeout(t); t=setTimeout(function(){ apply(input.value); }, 150); });
                input.addEventListener('search', function(){ apply(input.value); });
                return true;
            }
            var n=0; (function w(){ if(boot()||n++>50){ return; } setTimeout(w, 200); })();
        })();
    </script>
</body>

</html>
