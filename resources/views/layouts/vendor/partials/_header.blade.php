<div id="headerMain" class="d-none">
    <header id="header"
        class="navbar navbar-expand-lg navbar-fixed navbar-height navbar-flush navbar-container navbar-bordered">
        <div class="navbar-nav-wrap">
            <div class="navbar-brand-wrapper">
                <!-- Logo Div-->
                @php($restaurant_logo = \App\CentralLogics\Helpers::get_restaurant_data()?->logo_full_url)
                <a class="navbar-brand" href="{{ route('vendor.dashboard') }}" aria-label="">
                    <img class="navbar-brand-logo logo--design" src="{{ $restaurant_logo }}" alt="image">
                    <img class="navbar-brand-logo-mini logo--design" src="{{ $restaurant_logo }}" alt="image">
                </a>
                <!-- End Logo -->
            </div>
            <div class="navbar-nav-wrap-content-left ml-auto d--xl-none">
                <!-- Navbar Vertical Toggle -->
                <button type="button" class="js-navbar-vertical-aside-toggle-invoker close">
                    <i class="tio-first-page navbar-vertical-aside-toggle-short-align" data-toggle="tooltip"
                        data-placement="right" title="Collapse"></i>
                    <i class="tio-last-page navbar-vertical-aside-toggle-full-align"
                        data-template='<div class="tooltip d-none d-sm-block" role="tooltip"><div class="arrow"></div><div class="tooltip-inner"></div></div>'></i>
                </button>
                <!-- End Navbar Vertical Toggle -->
            </div>






            <!-- Secondary Content -->
            <div class="navbar-nav-wrap-content-right flex-grow-1">
                <!-- Navbar -->
                <ul class="navbar-nav align-items-center flex-row justify-content-end">

                    <li class="nav-item max-sm-m-0 w-md-200px">
                        <button type="button" id="modalOpener" class="title-color bg--secondary border-0 rounded justify-content-between w-100 align-items-center py-2 px-2 px-md-3 d-flex gap-1" data-toggle="modal" data-target="#staticBackdrop">
                            <div class="d-flex gap-1 align-items-center">
                                <i class="tio-search"></i>
                                <span class="d-none d-md-block text-muted">{{translate('Search')}}</span>
                            </div>
                            <span class="bg-card text-muted border rounded-3 p-1 fs-12 fw-bold lh-1 ms-1 ctrlplusk d-none d-md-block">Ctrl+K</span>
                        </button>
                    </li>

                    <li class="nav-item max-sm-m-0">
                        <div class="hs-unfold">
                            <div>
                                @php($local = session()->has('vendor_local') ? session('vendor_local') : null)
                                @php($lang = \App\CentralLogics\Helpers::get_business_settings('system_language'))
                                {{-- 哪吒商家端全中文: 隐藏语言切换器(已强制zh) --}}
                                @if (false && $lang)
                                    <div class="topbar-text dropdown disable-autohide text-capitalize d-flex">
                                        <a class="text-dark dropdown-toggle d-flex align-items-center nav-link"
                                            href="#" data-toggle="dropdown">
                                            @foreach ($lang ??[] as $data)
                                                @if ($data['code'] == $local)
                                                    <img class="rounded mr-1" width="20"
                                                        src="{{ dynamicAsset('assets/admin/img/lang.png') }}"
                                                        alt="">
                                                    {{ $data['code'] }}
                                                @elseif(!$local && $data['default'] == true)
                                                    <img class="rounded mr-1" width="20"
                                                        src="{{ dynamicAsset('assets/admin/img/lang.png') }}"
                                                        alt="">
                                                    {{ $data['code'] }}
                                                @endif
                                            @endforeach
                                        </a>
                                        <ul class="dropdown-menu">
                                            @foreach ($lang ??[] as $key => $data)
                                                @if ($data['status'] == 1)
                                                    <li>
                                                        <a class="dropdown-item py-1"
                                                            href="{{ route('vendor.lang', [$data['code']]) }}">

                                                            <span class="text-capitalize">{{ $data['code'] }}</span>
                                                        </a>
                                                    </li>
                                                @endif
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </li>

                    {{-- 哪吒 C3(W4): 待办提醒铃铛栈 —— 超时/催配送告警收进这里(徽标+可展开·直连订单)。由 app.blade window.nzBell 驱动。 --}}
                    <li class="nav-item mr-3" style="position:relative;">
                        <div class="hs-unfold" style="position:relative;">
                            <a id="nzBellBtn" class="btn btn-icon btn-soft-secondary rounded-circle" href="javascript:;" data-toggle="tooltip" title="待办提醒" aria-label="待办提醒" style="position:relative;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                                <span id="nzBellBadge" style="display:none;position:absolute;top:-3px;right:-3px;min-width:16px;height:16px;padding:0 4px;background:#E5484D;color:#fff;border-radius:9px;font-size:10px;line-height:16px;text-align:center;font-weight:700;box-shadow:0 0 0 2px #fff;">0</span>
                            </a>
                            <div id="nzBellPop" style="display:none;position:absolute;right:0;top:calc(100% + 8px);width:320px;max-width:88vw;background:#fff;border:1px solid #ededed;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.16);z-index:100001;font-family:'PingFang SC','Microsoft YaHei',sans-serif;text-align:left;">
                                <style>
                                #nzBellPop .nz-bell-hd{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid #f0f0f0;font-weight:600;font-size:15px;color:#1f1f1f;}
                                #nzBellPop .nz-bell-body{max-height:62vh;overflow-y:auto;}
                                #nzBellPop .nz-bell-grp{font-size:12px;color:#9aa0a6;padding:10px 14px 4px;}
                                #nzBellPop .nz-bell-item{display:flex;align-items:center;gap:8px;padding:9px 14px;font-size:13px;color:#42505F;text-decoration:none;border-top:1px solid #f6f6f6;}
                                #nzBellPop .nz-bell-item:hover{background:#f7f8fa;}
                                #nzBellPop .nz-bell-dot{width:8px;height:8px;border-radius:50%;flex:none;}
                                #nzBellPop .nz-bell-dot.red{background:#E5484D;}
                                #nzBellPop .nz-bell-dot.blue{background:#1f6fd0;}
                                #nzBellPop .nz-bell-dot.green{background:#2F9E44;}
                                #nzBellPop .nz-bell-dot.amber{background:#F3A429;}
                                #nzBellPop .nz-bell-go{margin-left:auto;color:#98A2B3;}
                                #nzBellPop .nz-bell-empty{padding:20px 16px;color:#9aa0a6;font-size:13px;text-align:center;}
                                </style>
                                <div class="nz-bell-hd">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#E5484D" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                                    待办提醒
                                </div>
                                <div class="nz-bell-body" id="nzBellBody"><div class="nz-bell-empty">暂无待办提醒</div></div>
                            </div>
                        </div>
                    </li>

                    {{-- nz: 提示音设置(分类音量+总开关, 存本机) --}}
                    <li class="nav-item mr-3" style="position:relative;">
                        <div class="hs-unfold" style="position:relative;">
                            <a id="nzSoundBtn" class="btn btn-icon btn-soft-secondary rounded-circle" href="javascript:;" data-toggle="tooltip" title="提示音设置" aria-label="提示音设置">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z"></path><path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path><path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path></svg>
                            </a>
                            <div id="nzSoundPop" style="display:none;position:absolute;right:0;top:calc(100% + 8px);width:352px;max-width:88vw;background:#fff;border:1px solid #E7EAEF;border-radius:14px;box-shadow:0 12px 40px rgba(23,28,38,.10);z-index:100001;font-family:'PingFang SC','Microsoft YaHei',sans-serif;text-align:left;color:#1F2329;overflow:hidden;">
    <style>
    #nzSoundPop *{box-sizing:border-box;}
    #nzSoundPop .nz-snd-sw{position:relative;display:inline-block;width:40px;height:22px;flex:none;vertical-align:middle;}
    #nzSoundPop .nz-snd-sw input{opacity:0;width:0;height:0;position:absolute;margin:0;}
    #nzSoundPop .nz-snd-sw .nz-snd-track{position:absolute;inset:0;background:#D6DAE0;border-radius:99px;transition:.15s;cursor:pointer;}
    #nzSoundPop .nz-snd-sw .nz-snd-track:before{content:"";position:absolute;width:18px;height:18px;left:2px;top:2px;background:#fff;border-radius:50%;box-shadow:0 1px 2px rgba(23,28,38,.18);transition:.15s;}
    #nzSoundPop .nz-snd-sw input:checked + .nz-snd-track{background:#1F2329;}
    #nzSoundPop .nz-snd-sw input:checked + .nz-snd-track:before{transform:translateX(18px);}
    #nzSoundPop .nz-hd{display:flex;align-items:center;justify-content:space-between;padding:14px 16px 10px;}
    #nzSoundPop .nz-hd-t{display:flex;align-items:center;gap:7px;font-size:15px;font-weight:600;color:#1F2329;}
    #nzSoundPop .nz-hd-m{display:flex;align-items:center;gap:7px;font-size:12px;color:#5A6069;margin:0;cursor:pointer;}
    #nzSoundPop .nz-vrows{padding:4px 16px 0;}
    #nzSoundPop .nz-vrow{display:flex;align-items:center;gap:10px;height:44px;}
    #nzSoundPop .nz-vname{width:104px;flex:none;font-size:13px;color:#1F2329;line-height:1.3;}
    #nzSoundPop .nz-vname i{font-style:normal;font-size:10px;color:#9AA0A8;display:block;}
    #nzSoundPop .nz-snd-sl{-webkit-appearance:none;appearance:none;flex:1;min-width:0;height:14px;background:transparent;cursor:pointer;margin:0;}
    #nzSoundPop .nz-snd-sl:focus{outline:none;}
    #nzSoundPop .nz-snd-sl::-webkit-slider-runnable-track{height:4px;border-radius:99px;background:linear-gradient(to right,#A9AFB8 var(--p,0%),#EEF0F3 var(--p,0%));}
    #nzSoundPop .nz-snd-sl::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;width:14px;height:14px;margin-top:-5px;border-radius:50%;background:#1F2329;border:2.5px solid #fff;box-shadow:0 1px 3px rgba(23,28,38,.28);}
    #nzSoundPop .nz-snd-sl::-moz-range-track{height:4px;border-radius:99px;background:#EEF0F3;}
    #nzSoundPop .nz-snd-sl::-moz-range-progress{height:4px;border-radius:99px;background:#A9AFB8;}
    #nzSoundPop .nz-snd-sl::-moz-range-thumb{width:14px;height:14px;border:2.5px solid #fff;border-radius:50%;background:#1F2329;box-shadow:0 1px 3px rgba(23,28,38,.28);}
    #nzSoundPop .nz-vval{width:28px;flex:none;text-align:right;font-size:12px;color:#9AA0A8;font-variant-numeric:tabular-nums;}
    #nzSoundPop .nz-snd-test{flex:none;width:26px;height:26px;border-radius:8px;border:none;background:transparent;color:#5A6069;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;padding:0;}
    #nzSoundPop .nz-snd-test:hover{background:#F3F4F6;}
    #nzSoundPop .nz-snd-off .nz-vrow{opacity:.4;}
    #nzSoundPop .nz-vnotes{padding:8px 16px 14px;}
    #nzSoundPop .nz-vnotes p{font-size:11.5px;line-height:1.65;color:#9AA0A8;margin:0;}
    #nzSoundPop .nz-vnotes p + p{margin-top:2px;}
    #nzSoundPop .nz-div{height:1px;background:#F0F2F4;}
    #nzSoundPop .nz-nag{padding:13px 16px 16px;}
    #nzSoundPop .nz-nag-hd{display:flex;align-items:center;justify-content:space-between;}
    #nzSoundPop .nz-nag-t{font-size:14px;font-weight:600;color:#1F2329;}
    #nzSoundPop .nz-nag-sub{margin-top:5px;font-size:12px;color:#5A6069;line-height:1.6;}
    #nzSoundPop .nz-nag-body{margin-top:12px;}
    #nzSoundPop .nz-setrow{display:flex;align-items:center;justify-content:space-between;}
    #nzSoundPop .nz-setrow + .nz-setrow{margin-top:10px;}
    #nzSoundPop .nz-setrow .nz-lb{font-size:13px;color:#1F2329;}
    #nzSoundPop .nz-numin{display:flex;align-items:center;gap:2px;width:110px;height:32px;padding:0 10px 0 12px;border:1px solid #DDE1E7;border-radius:8px;background:#fff;}
    #nzSoundPop .nz-numin input{flex:1;min-width:0;border:none;outline:none;background:transparent;font-size:13.5px;color:#1F2329;font-variant-numeric:tabular-nums;text-align:right;padding:0;-moz-appearance:textfield;}
    #nzSoundPop .nz-numin input::-webkit-outer-spin-button,#nzSoundPop .nz-numin input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
    #nzSoundPop .nz-numin i{font-style:normal;font-size:12px;color:#9AA0A8;flex:none;}
    #nzSoundPop .nz-setnote{margin-top:8px;font-size:11.5px;line-height:1.6;color:#9AA0A8;}
    #nzSoundPop .nz-phrow{display:flex;align-items:center;justify-content:space-between;margin-top:13px;padding-top:13px;border-top:1px solid #F0F2F4;cursor:pointer;}
    #nzSoundPop .nz-phrow .nz-lb{font-size:13px;font-weight:600;color:#1F2329;}
    #nzSoundPop .nz-phrow .nz-chev{font-size:12px;color:#9AA0A8;}
    #nzSoundPop .nz-guide{margin-top:11px;background:#FAFBFC;border:1px solid #F0F2F4;border-radius:10px;padding:13px 13px 14px;}
    #nzSoundPop .nz-glead{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
    #nzSoundPop .nz-chip{display:inline-block;padding:3px 8px;border-radius:99px;background:#E5F1EA;color:#2B7A57;font-size:11px;font-weight:600;}
    #nzSoundPop .nz-glead .nz-gt{font-size:12px;color:#5A6069;}
    #nzSoundPop .nz-seg{margin-top:12px;display:flex;background:#F0F2F4;border-radius:8px;padding:2px;}
    #nzSoundPop .nz-seg span{flex:1;text-align:center;font-size:11.5px;padding:5px 0;border-radius:6px;color:#5A6069;cursor:pointer;user-select:none;}
    #nzSoundPop .nz-seg span.nz-sel{background:#1F2329;color:#fff;font-weight:600;}
    #nzSoundPop .nz-steps{margin-top:12px;}
    #nzSoundPop .nz-step{display:flex;gap:9px;}
    #nzSoundPop .nz-step + .nz-step{margin-top:10px;}
    #nzSoundPop .nz-stepn{width:17px;height:17px;flex:none;margin-top:1px;border-radius:50%;background:#E9EBEF;color:#5A6069;font-size:10.5px;font-weight:600;display:flex;align-items:center;justify-content:center;}
    #nzSoundPop .nz-stept{font-size:12.5px;line-height:1.65;color:#1F2329;}
    #nzSoundPop .nz-kw{display:inline-block;padding:0 6px;border-radius:4px;background:#F0F2F4;font-weight:600;}
    #nzSoundPop .nz-altnote{margin-top:11px;padding-top:10px;border-top:1px dashed #E7EAEF;font-size:11px;color:#9AA0A8;line-height:1.6;}
    #nzSoundPop .nz-altnote a{color:#1F2329;font-weight:600;text-decoration:none;}
    #nzSoundPop .nz-bind{margin-top:11px;position:relative;background:#fff;border:1px solid #E7EAEF;border-radius:10px;padding:13px 14px 13px 17px;overflow:hidden;}
    #nzSoundPop .nz-bind:before{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:#D9A521;}
    #nzSoundPop .nz-bind .nz-bt{font-size:13px;font-weight:600;color:#1F2329;}
    #nzSoundPop .nz-bind .nz-bd{margin-top:5px;font-size:12px;color:#5A6069;line-height:1.65;}
    #nzSoundPop .nz-bind .nz-bl{display:inline-block;margin-top:9px;font-size:12.5px;font-weight:600;color:#1F2329;text-decoration:none;}
    #nzSoundPop .nz-save{margin-top:14px;width:100%;height:38px;border:none;border-radius:10px;background:#1F2329;color:#fff;font-size:13.5px;font-weight:600;cursor:pointer;letter-spacing:2px;}
    #nzSoundPop .nz-saved{margin-top:14px;display:flex;align-items:center;justify-content:center;gap:6px;height:24px;font-size:12.5px;color:#2B7A57;}
    </style>

    @php($nzSndR = \App\CentralLogics\Helpers::get_restaurant_data())
    @php($nzTgBound = !empty(optional($nzSndR)->telegram_chat_id))

    <div class="nz-hd">
        <span class="nz-hd-t">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1F2329" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z"></path><path d="M15.5 8.5a5 5 0 0 1 0 7"></path></svg>
            提示音
        </span>
        <label class="nz-hd-m">总开关
            <span class="nz-snd-sw"><input type="checkbox" id="nzSoundMaster" checked><span class="nz-snd-track"></span></span>
        </label>
    </div>

    <div id="nzSoundBody">
        <div class="nz-vrows">
            <div class="nz-vrow">
                <span class="nz-vname">订单<i>新订单 · 超时</i></span>
                <input type="range" class="nz-snd-sl" data-cat="new_order" data-cats="new_order,timeout" min="0" max="100" step="1" value="90">
                <span class="nz-vval" data-cat="new_order">90</span>
                <button type="button" class="nz-snd-test" data-cat="new_order" data-el="myAudio" aria-label="试听订单提示音"><svg width="9" height="10" viewBox="0 0 9 10"><path d="M0 0 L9 5 L0 10 Z" fill="currentColor"/></svg></button>
            </div>
            <div class="nz-vrow">
                <span class="nz-vname">消息与配送<i>顾客 · 客服 · 配送</i></span>
                <input type="range" class="nz-snd-sl" data-cat="customer_msg" data-cats="customer_msg,platform_msg,deliv" min="0" max="100" step="1" value="70">
                <span class="nz-vval" data-cat="customer_msg">70</span>
                <button type="button" class="nz-snd-test" data-cat="customer_msg" data-el="nzMsgAudio" aria-label="试听消息提示音"><svg width="9" height="10" viewBox="0 0 9 10"><path d="M0 0 L9 5 L0 10 Z" fill="currentColor"/></svg></button>
            </div>
        </div>
        <div class="nz-vnotes">
            <p>静音仅关闭声音——新订单弹窗与订单列表不受影响，不会漏单。</p>
            <p>音量调整即时生效，仅保存在本设备。</p>
        </div>
    </div>

    <div class="nz-div"></div>

    {{-- 哪吒 · 新单循环提醒(方案A网页循环播报 + B手机TG反复补发 · 共读一份·此处保存写后端5列) --}}
    <div class="nz-nag">
        <div class="nz-nag-hd">
            <span class="nz-nag-t">新单循环提醒</span>
            <span class="nz-snd-sw"><input type="checkbox" id="nzRepeatEnabled" {{ !empty(optional($nzSndR)->new_order_repeat_enabled) ? 'checked' : '' }}><span class="nz-snd-track"></span></span>
        </div>
        <div class="nz-nag-sub">新订单未处理时按设定间隔循环响铃，处理后自动停止。</div>
        <div class="nz-nag-body" id="nzRepeatBody">
            <div class="nz-setrow">
                <span class="nz-lb">提醒间隔</span>
                <span class="nz-numin"><input type="number" id="nzRepeatInterval" min="10" max="120" step="1" value="{{ (int)(optional($nzSndR)->new_order_repeat_interval_sec ?? 20) }}"><i>秒</i></span>
            </div>
            <div class="nz-setrow">
                <span class="nz-lb">最长提醒</span>
                <span class="nz-numin"><input type="number" id="nzRepeatMax" min="1" max="5" step="1" value="{{ (int)(optional($nzSndR)->new_order_repeat_max_minutes ?? 5) }}"><i>分钟</i></span>
            </div>
            <div class="nz-setnote">间隔可设 10–120 秒，最长提醒 1–5 分钟；手机 Telegram 固定约 60 秒提醒一次。</div>

            <div class="nz-phrow" id="nzRepeatMobileToggle">
                <span class="nz-lb">手机 Telegram 提醒</span>
                <span class="nz-chev" id="nzRepeatMobileChev">展开 ›</span>
            </div>

            <div id="nzRepeatMobileSteps" style="display:none;">
                @if($nzTgBound)
                <div class="nz-guide">
                    <div class="nz-glead">
                        <span class="nz-chip">免费 · 一次设置长期有效</span>
                        <span class="nz-gt">为 @@Nz_order_bot 的新单通知设置专属提示音</span>
                    </div>
                    <div class="nz-seg" id="nzTgSeg">
                        <span class="nz-sel" data-os="ios">iPhone</span><span data-os="android">安卓</span>
                    </div>
                    <div class="nz-steps">
                        <div class="nz-step"><span class="nz-stepn">1</span><span class="nz-stept">在 Telegram 中向 <span class="nz-kw">@@Nz_order_bot</span> 发送 <span class="nz-kw">语音</span>，机器人将回复 3 秒提示音文件</span></div>
                        <div class="nz-step"><span class="nz-stepn">2</span><span class="nz-stept">长按该语音 → 选择「保存为提示音（Save for Notifications）」</span></div>
                        <div class="nz-step"><span class="nz-stepn">3</span><span class="nz-stept"><span class="nz-os" data-os="ios">在该聊天中点击顶部名称 →「静音」→「自定义」，在「声音」中选中该提示音，点击「完成」</span><span class="nz-os" data-os="android" style="display:none;">在该聊天中点击右上「⋮」→「静音」→「自定义」→ 打开「自定义通知」，在「声音」中选中该提示音</span></span></div>
                    </div>
                    <div class="nz-altnote">使用电脑？可 <a href="{{ dynamicAsset('assets/admin/sound/new-order-voice.mp3') }}" download>下载 mp3 ›</a>，在通知设置的「上传声音」中手动添加。</div>
                </div>
                @else
                <div class="nz-bind">
                    <div class="nz-bt">手机提醒需先绑定 Telegram</div>
                    <div class="nz-bd">前往 业务设置 → 通知设置，按提示完成绑定（约 1 分钟），之后手机即可接收新单提醒。</div>
                    <a class="nz-bl" href="{{ route('vendor.business-settings.notification-setup') }}">前往绑定 ›</a>
                </div>
                @endif
            </div>

            <button type="button" id="nzRepeatSave" class="nz-save" style="display:none;">保存设置</button>
            <div id="nzRepeatSaved" class="nz-saved" style="display:none;">
                <svg width="12" height="10" viewBox="0 0 12 10"><path d="M1 5 L4.4 8.4 L11 1" stroke="#2B7A57" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>已保存，网页与手机端同步生效</span>
            </div>
            <div id="nzRepeatMsg" style="font-size:12px;margin-top:6px;min-height:0;"></div>
        </div>
    </div>

    <script>
    (function(){
        var enEl = document.getElementById('nzRepeatEnabled');
        if (!enEl) { return; }
        var body = document.getElementById('nzRepeatBody');
        var ivEl = document.getElementById('nzRepeatInterval');
        var mxEl = document.getElementById('nzRepeatMax');
        var saveBtn = document.getElementById('nzRepeatSave');
        var savedLine = document.getElementById('nzRepeatSaved');
        var msg = document.getElementById('nzRepeatMsg');
        var mob = document.getElementById('nzRepeatMobileToggle');
        var mobChev = document.getElementById('nzRepeatMobileChev');
        var mobSteps = document.getElementById('nzRepeatMobileSteps');
        var seg = document.getElementById('nzTgSeg');

        function clampVal(el){ var v = parseInt(el.value, 10); var mn = parseInt(el.min,10), mx = parseInt(el.max,10); if (isNaN(v)) v = mn; v = Math.max(mn, Math.min(mx, v)); el.value = v; return v; }
        var baseline = { enabled: enEl.checked, interval: clampVal(ivEl), max: clampVal(mxEl) };
        function current(){ return { enabled: enEl.checked, interval: clampVal(ivEl), max: clampVal(mxEl) }; }
        function dirty(){ var c = current(); return c.enabled !== baseline.enabled || c.interval !== baseline.interval || c.max !== baseline.max; }
        function syncBody(){ if (body) { body.style.opacity = enEl.checked ? '1' : '.55'; } }
        function refreshSave(){ if (!saveBtn) return; if (dirty()) { saveBtn.style.display = 'block'; if (savedLine) savedLine.style.display = 'none'; } else { saveBtn.style.display = 'none'; } }

        enEl.addEventListener('change', function(){ syncBody(); if (msg) msg.textContent=''; refreshSave(); });
        [ivEl, mxEl].forEach(function(el){ el.addEventListener('input', function(){ if (msg) msg.textContent=''; refreshSave(); }); el.addEventListener('blur', function(){ clampVal(el); refreshSave(); }); });
        syncBody(); refreshSave();

        if (mob && mobSteps) { mob.addEventListener('click', function(){
            var open = mobSteps.style.display === 'block';
            mobSteps.style.display = open ? 'none' : 'block';
            if (mobChev) mobChev.textContent = open ? '展开 ›' : '收起 ‹';
        }); }
        if (seg) { seg.querySelectorAll('span').forEach(function(sp){ sp.addEventListener('click', function(){
            var os = sp.getAttribute('data-os');
            seg.querySelectorAll('span').forEach(function(x){ x.classList.toggle('nz-sel', x === sp); });
            if (mobSteps) mobSteps.querySelectorAll('.nz-os').forEach(function(x){ x.style.display = (x.getAttribute('data-os') === os) ? '' : 'none'; });
        }); }); }

        if (saveBtn) { saveBtn.addEventListener('click', function(){
            saveBtn.disabled = true; if (msg) { msg.style.color = '#9AA0A8'; msg.textContent = '保存中…'; }
            var iv = clampVal(ivEl), mx = clampVal(mxEl);
            $.post('{{ route('vendor.business-settings.nezha-new-order-repeat') }}', {
                _token: '{{ csrf_token() }}',
                enabled: enEl.checked ? 1 : 0,
                interval_sec: iv,
                max_minutes: mx
            }).done(function(r){
                if (r && r.ok) {
                    if (msg) msg.textContent = '';
                    if (r.data) {
                        baseline = { enabled: !!r.data.enabled, interval: parseInt(r.data.interval_sec,10), max: parseInt(r.data.max_minutes,10) };
                        enEl.checked = !!r.data.enabled; ivEl.value = r.data.interval_sec; mxEl.value = r.data.max_minutes;
                        if (window.nzOrderRepeat) {
                            window.nzOrderRepeat.enabled = !!r.data.enabled;
                            window.nzOrderRepeat.intervalSec = r.data.interval_sec;
                            window.nzOrderRepeat.maxMinutes = r.data.max_minutes;
                            window.nzOrderRepeat.scopes = { accept: !!r.data.scope_accept, payment: !!r.data.scope_payment };
                        }
                    }
                    if (saveBtn) saveBtn.style.display = 'none';
                    if (savedLine) { savedLine.style.display = 'flex'; setTimeout(function(){ if (!dirty()) savedLine.style.display = 'none'; }, 3200); }
                } else if (msg) { msg.style.color = '#C0392B'; msg.textContent = (r && r.msg) || '保存失败'; }
            }).fail(function(){ if (msg) { msg.style.color = '#C0392B'; msg.textContent = '保存失败，请重试'; }
            }).always(function(){ saveBtn.disabled = false; });
        }); }
    })();
    </script>
</div>
                        </div>
                    </li>
                    <li class="nav-item nav--item">
                        <!-- Account -->
                        <div class="hs-unfold">
                            <a class="js-hs-unfold-invoker navbar-dropdown-account-wrapper p-0" href="javascript:;"
                                data-hs-unfold-options='{
                                     "target": "#accountNavbarDropdown",
                                     "type": "css-animation"
                                   }'>

                                <div class="cmn--media right-dropdown-icon d-flex align-items-center">
                                    <div class="media-body pl-0 pr-2">
                                        <span class="card-title h5 text-right pr-2">
                                            {{ \App\CentralLogics\Helpers::get_loggedin_user()->f_name }}
                                        </span>
                                        <span
                                            class="card-text card--text">{{ \App\CentralLogics\Helpers::get_loggedin_user()->email }}</span>
                                    </div>
                                    <div class="">
                                        <img class="avatar avatar-sm avatar-circle"
                                            src="{{ \App\CentralLogics\Helpers::get_loggedin_user()?->image_full_url ?? dynamicAsset('assets/admin/img/160x160/img1.jpg') }}"
                                            alt="image">

                                        <span class="avatar-status avatar-sm-status avatar-status-success"></span>
                                    </div>
                                </div>

                            </a>

                            <div id="accountNavbarDropdown"
                                class="hs-unfold-content dropdown-unfold dropdown-menu dropdown-menu-right navbar-dropdown-menu navbar-dropdown-account w-16rem">
                                <div class="dropdown-item-text">
                                    <div class="media cmn--media align-items-center">
                                        <div class="avatar avatar-sm avatar-circle mr-2">
                                            <img class="avatar-img"
                                                src="{{ \App\CentralLogics\Helpers::get_loggedin_user()?->image_full_url ?? dynamicAsset('assets/admin/img/160x160/img1.jpg') }}"
                                                alt="image">
                                        </div>
                                        <div class="media-body">
                                            <span
                                                class="card-title h5">{{ \App\CentralLogics\Helpers::get_loggedin_user()->f_name }}</span>
                                            <span
                                                class="card-text">{{ \App\CentralLogics\Helpers::get_loggedin_user()->email }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="dropdown-divider"></div>

                                <a class="dropdown-item" href="{{ route('vendor.profile.view') }}">
                                    <span class="text-truncate pr-2"
                                        title="Settings">{{ translate('messages.settings') }}</span>
                                </a>

                                <div class="dropdown-divider"></div>

                                <a class="dropdown-item" href="javascript:"
                                    onclick="Swal.fire({
                                    title: '{{ translate('Are you Sure want to sign-out?') }}',
                                    imageUrl: `{{ dynamicAsset('assets/admin/img/modal/logout.png') }}`,   // 👈 your custom image path
                                    imageWidth: 80,
                                    imageHeight: 80,
                                    imageAlt: 'Logout Image',
                                    showDenyButton: true,
                                    showCancelButton: true,
                                    confirmButtonColor: '#FF4040',
                                    cancelButtonColor: '#363636',
                                    confirmButtonText: '{{ translate('messages.Yes') }}',
                                    cancelButtonText: '{{ translate('messages.cancel') }}',
                                    }).then((result) => {
                                    if (result.value) {
                                        location.href='{{ route('logout') }}';
                                    }
                                    })">
                                    <span class="text-truncate pr-2"
                                        title="Sign out">{{ translate('messages.sign_out') }}</span>
                                </a>
                            </div>
                        </div>
                        <!-- End Account -->
                    </li>
                </ul>
                <!-- End Navbar -->
            </div>
            <!-- End Secondary Content -->
        </div>
    </header>
</div>
<div id="headerFluid" class="d-none"></div>
<div id="headerDouble" class="d-none"></div>

<?php
$wallet = \App\Models\RestaurantWallet::where('vendor_id', \App\CentralLogics\Helpers::get_vendor_id())->first();
$Payable_Balance = $wallet?->collected_cash > 0 ? 1 : 0;

$cash_in_hand_overflow =    \App\Models\BusinessSetting::where('key', 'cash_in_hand_overflow_restaurant')->first()?->value ?? 0;
$cash_in_hand_overflow_restaurant_amount = (float) \App\Models\BusinessSetting::where('key', 'cash_in_hand_overflow_restaurant_amount')->first()?->value;
$val = round($cash_in_hand_overflow_restaurant_amount - ($cash_in_hand_overflow_restaurant_amount * 10) / 100, 8);
?>

@if ($Payable_Balance == 1 && $cash_in_hand_overflow && $wallet?->balance < 0 && $val <= abs($wallet?->collected_cash))
    <div class="alert __alert-2 alert-warning m-0 py-1 px-2" role="alert">
        <img class="rounded mr-1" width="25"
            src="{{ dynamicAsset('assets/admin/img/header_warning.png') }}" alt="">
        <div class="cont">
            <h4 class="m-0">{{ translate('Attention_Please') }} </h4>
            {{ translate('The_Cash_in_Hand_amount_is_about_to_exceed_the_limit._Please_pay_the_due_amount._If_the_limit_exceeds,_your_account_will_be_suspended.') }}
        </div>
    </div>
@endif

@if (
    $Payable_Balance == 1 &&
        $cash_in_hand_overflow &&
        $wallet?->balance < 0 &&
        $cash_in_hand_overflow_restaurant_amount < $wallet?->collected_cash)
    <div class="alert __alert-2 alert-warning m-0 py-1 px-2" role="alert">
        <img class="mr-1" width="25" src="{{ dynamicAsset('assets/admin/img/header_warning.png') }}"
            alt="">
        <div class="cont">
            <h4 class="m-0">{{ translate('Attention_Please') }} </h4>
            {{ translate('The_Cash_in_Hand_amount_limit_is_exceeded._Your_account_is_now_suspended._Please_pay_the_due_amount_to_receive_new_order_requests_again.') }}<a
                href="{{ route('vendor.wallet.index') }}" class="alert-link"> &nbsp;
                {{ translate('Pay_the_due') }}</a>
        </div>
    </div>
@endif

<?php
$restaurant_data = \App\CentralLogics\Helpers::get_restaurant_data();
$subscription_deadline_warning_days = (int) \App\Models\BusinessSetting::where('key', 'subscription_deadline_warning_days')->first()?->value ?? 7;
$subscription_deadline_warning_message = \App\Models\BusinessSetting::where('key', 'subscription_deadline_warning_message')->first()?->value ?? null;
?>


<div id="hide-subscription-warnings">



    @if (
        !in_array($restaurant_data->restaurant_model, ['none', 'commission']) &&
            !Request::is('restaurant-panel/subscription/*'))

        <?php
        $pers = 10;
        if ($restaurant_data?->restaurant_sub) {
            $validity = $restaurant_data?->restaurant_sub?->validity;
            $remaining_days = Carbon\Carbon::now()->diffInDays($restaurant_data?->restaurant_sub?->expiry_date_parsed->format('Y-m-d'), false);
            $pers = $validity - $remaining_days > 0 ? (($validity - $remaining_days) / $validity) * 100 : 1;
            $pers = (439.6 * $pers) / 100;
        }
        ?>
@if (
    $restaurant_data?->restaurant_sub?->is_trial == 0 &&
        $restaurant_data?->restaurant_sub?->expiry_date_parsed &&
        $restaurant_data?->restaurant_sub->expiry_date_parsed->subDays($subscription_deadline_warning_days)->isBefore(now()) &&
        Request::is('restaurant-panel'))

    <!--Always in header Renew -->
    <div class="renew-badge mx-3 mt-3" id="renew-badge">
        <div class="renew-content d-flex align-items-center">

            <img src="{{ dynamicAsset('assets/admin/img/timer.svg') }}" alt="">
            <div class="txt">
                {{ $subscription_deadline_warning_message != null ? $subscription_deadline_warning_message : translate('Your subscription ending soon. Please renew to continue access') }}
            </div>
        </div>
        <div>
            <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['renew_now' => true]) }}"
                class="btn btn--danger">{{ translate('Renew') }}</a>
        </div>
    </div>
@elseif (Session::get('subscription_renew_close_btn') !== true &&
        $restaurant_data?->restaurant_sub?->is_trial == 0 &&
        $restaurant_data?->restaurant_sub?->expiry_date_parsed &&
        $restaurant_data?->restaurant_sub->expiry_date_parsed->subDays($subscription_deadline_warning_days)->isBefore(now()) &&
        !Request::is('restaurant-panel'))
    <div class="renew-badge mx-3 mt-3 hide-warning" id="renew-badge">
        <div class="renew-content d-flex align-items-center">

            <img src="{{ dynamicAsset('assets/admin/img/timer.svg') }}" alt="">
            <div class="txt">
                {{ $subscription_deadline_warning_message != null ? $subscription_deadline_warning_message : translate('Your subscription ending soon. Please renew to continue access') }}
            </div>
        </div>
        <div>
            @if ($restaurant_data?->restaurant_sub?->is_canceled == 1)
                <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                    class="btn btn--danger">{{ translate('Change_Subscription') }}</a>
            @else
                <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['renew_now' => true]) }}"
                    class="btn btn--danger">{{ translate('Renew') }}</a>
            @endif
            <button data-id="subscription_renew_close_btn" id="subs-hide-warning"
                class="btn btn-sm btn-primary add-to-session">{{ translate('remind_me_later') }}</button>
        </div>
    </div>
    <!-- Renew -->


@endif




        @if (Session::get('subscription_free_trial_close_btn') !== true &&
                $restaurant_data?->restaurant_sub?->status == 1 &&
                $restaurant_data?->restaurant_sub?->is_trial == 1 &&
                $restaurant_data?->restaurant_sub?->is_canceled == 0)
            <div class="free-trial trial success-bg">
                <div class="inner-div">
                    <div class="left">
                        <img src="{{ dynamicAsset('assets/admin/img/icon-puck.svg') }}" alt="">
                        <div class="left-content">
                            <h6>{{ translate('Get the best experience of your business') }}</h6>
                            <div>{{ translate('Run your business with the most popular platform') }}</div>
                        </div>
                    </div>
                    <div class="right">
                        <a href="#" class="btn btn-2">
                            <span class="circle-progress-container">
                                <svg width="40" viewBox="0 0 160 160">
                                    <circle r="70" cx="80" cy="80" fill="transparent"
                                        stroke="#ffffff20" stroke-width="12px"></circle>
                                    <circle r="70" cx="80" cy="80" fill="transparent" stroke="#ffffff"
                                        stroke-width="12px" stroke-dasharray="439.6px"
                                        stroke-dashoffset="{{ $pers }}px"></circle>
                                </svg>
                                {{1+ Carbon\Carbon::now()->diffInDays($restaurant_data?->restaurant_sub?->expiry_date_parsed->format('Y-m-d'), false) }}
                            </span>
                            {{ translate('Days_left_in_free_trial') }}
                        </a>
                        <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                            class="btn btn-light">{{ translate('Choose_Subscription_Plan') }} <i
                                class="tio-arrow-forward"></i></a>
                    </div>

                    <button type="button" data-id="subscription_free_trial_close_btn"
                        class="trial-close add-to-session ">
                        <i class="tio-clear-circle"></i>
                    </button>
                </div>
            </div>
        @elseif ($restaurant_data?->restaurant_sub == null && $restaurant_data?->restaurant_sub_update_application?->is_trial == 1)
            <div class="modal fade show trial-ended-modal" id="free-trial-modal">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-body p-0">
                            <div class="trial-ended-modal-wrapper">
                                {{-- <button type="button" class="trial-ended-close-btn text-md-white" data-dismiss="modal">
                                <i class="tio-clear-circle"></i>
                            </button> --}}
                                <div class="trial-ended-modal-content align-self-center">
                                    <h3 class="title">{{ translate('Your_Free_Trial_Has_Been_Ended') }}</h3>
                                    <p class="mb-4">
                                        {{ translate('Purchase a subscription plan or contact with the admin to settle the payment and unblock the access to service.') }}
                                    </p>
                                    <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                                        class="btn btn--primary">{{ translate('Choose Subscription Plan') }} <i
                                            class="tio-arrow-forward"></i></a>
                                    <div class="blocked-subscription mt-5">
                                        <img src="{{ dynamicAsset('assets/admin/img/WarningOctagon.svg') }}"
                                            alt="">
                                        <span>{{ translate('All Access to service has been blocked due to no active subscription') }}</span>
                                    </div>
                                </div>
                                <div class="trial-ended-modal-img d-none d-md-block">
                                    <img src="{{ dynamicAsset('assets/admin/img/trial-ended-bg.png') }}"
                                        alt="">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>



            <div class="free-trial trial danger-bg">
                <div class="inner-div">
                    <div class="left">
                        <img src="{{ dynamicAsset('assets/admin/img/timer-2.svg') }}" alt="">
                        <div class="left-content">
                            <h6>{{ translate('Free_Trial_Has_Been_Ended') }}</h6>
                            <div>{{ translate('Get_a_subscription_plan_to_continue_with_your_business') }}</div>
                        </div>
                    </div>
                    <div class="right">
                        <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                            class="btn btn-light">{{ translate('Choose_Subscription_Plan') }} <i
                                class="tio-arrow-forward"></i></a>
                    </div>
                    {{-- <button type="button" class="trial-close">
                    <i class="tio-clear-circle"></i>
                </button> --}}
                </div>
            </div>
        @elseif (Session::get('subscription_cancel_close_btn') !== true &&
                $restaurant_data?->restaurant_sub &&
                $restaurant_data?->restaurant_sub?->is_canceled == 1)
            <div class="free-trial trial danger-bg">
                <div class="inner-div">
                    <div class="left">
                        <img src="{{ dynamicAsset('assets/admin/img/timer-2.svg') }}" alt="">
                        <div class="left-content">
                            <h6>{{ translate('Your_Subscription_Has_Been_Cnaceled_by') }}
                                {{ $restaurant_data?->restaurant_sub?->canceled_by == 'admin' ? translate($restaurant_data?->restaurant_sub?->canceled_by) : translate('Yourself') }}
                            </h6>
                            <div>{{ translate('You_can_not_consume_your_subscription_after') }}
                                {{ \App\CentralLogics\Helpers::date_format($restaurant_data?->restaurant_sub?->expiry_date_parsed) }}
                            </div>
                        </div>
                    </div>
                    <div class="right">
                        <a href="#" class="btn btn-2">
                            <span class="circle-progress-container">
                                <svg width="40" viewBox="0 0 160 160">
                                    <circle r="70" cx="80" cy="80" fill="transparent"
                                        stroke="#ffffff20" stroke-width="12px"></circle>
                                    <circle r="70" cx="80" cy="80" fill="transparent" stroke="#ffffff"
                                        stroke-width="12px" stroke-dasharray="439.6px"
                                        stroke-dashoffset="{{ $pers }}px"></circle>
                                </svg>
                                {{1+ Carbon\Carbon::now()->diffInDays($restaurant_data?->restaurant_sub?->expiry_date_parsed->format('Y-m-d'), false) }}
                            </span>
                            {{ translate('Days_left_in_this_subscription') }}
                        </a>
                        <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                            class="btn btn-light">{{ translate('Change_Subscription_Plan') }} <i
                                class="tio-arrow-forward"></i></a>
                    </div>

                    <button type="button" data-id="subscription_cancel_close_btn"
                        class="trial-close add-to-session ">
                        <i class="tio-clear-circle"></i>
                    </button>
                </div>
            </div>
        @elseif (Session::get('subscription_plan_update_close_btn') !== true &&
                $restaurant_data?->restaurant_sub &&
                $restaurant_data?->restaurant_sub?->package?->status != 1)
            <div class="free-trial trial danger-bg">
                <div class="inner-div">
                    <div class="left">
                        <img src="{{ dynamicAsset('assets/admin/img/timer-2.svg') }}" alt="">
                        <div class="left-content">
                            <h6>{{ translate('Your_Current_Subscription_Package_has_been_Disable_By_Admin.') }} </h6>
                            <div>{{ translate('You_can_not_renew_this_Package_after') }}
                                {{ \App\CentralLogics\Helpers::date_format($restaurant_data?->restaurant_sub?->expiry_date_parsed) }}.
                                {{ translate('to_continue_your_subscription_please_chose_another_package.') }}</div>
                        </div>
                    </div>
                    <div class="right">
                        <a href="#" class="btn btn-2">
                            <span class="circle-progress-container">
                                <svg width="40" viewBox="0 0 160 160">
                                    <circle r="70" cx="80" cy="80" fill="transparent"
                                        stroke="#ffffff20" stroke-width="12px"></circle>
                                    <circle r="70" cx="80" cy="80" fill="transparent" stroke="#ffffff"
                                        stroke-width="12px" stroke-dasharray="439.6px"
                                        stroke-dashoffset="{{ $pers }}px"></circle>
                                </svg>
                                {{1+ Carbon\Carbon::now()->diffInDays($restaurant_data?->restaurant_sub?->expiry_date_parsed->format('Y-m-d'), false) }}
                            </span>
                            {{ translate('Days_left_in_this_subscription') }}
                        </a>
                        <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                            class="btn btn-light">{{ translate('Change_Subscription_Plan') }} <i
                                class="tio-arrow-forward"></i></a>
                    </div>

                    <button type="button" data-id="subscription_plan_update_close_btn"
                        class="trial-close add-to-session ">
                        <i class="tio-clear-circle"></i>
                    </button>
                </div>
            </div>
        @elseif ($restaurant_data?->restaurant_model == 'unsubscribed' && !$restaurant_data?->restaurant_sub_update_application )
            <div class="free-trial trial danger-bg">
                <div class="inner-div">
                    <div class="left">
                        <img src="{{ dynamicAsset('assets/admin/img/timer-2.svg') }}" alt="">
                        <div class="left-content">
                            <h6>{{ translate('Your_are_not_subscribed') }}
                                {{-- {{ \App\CentralLogics\Helpers::date_format($restaurant_data?->restaurant_sub_update_application?->expiry_date_parsed) }} --}}
                            </h6>
                            <div>
                                {{ translate('Purchase a subscription plan or contact with the admin to settle the payment and unblock the access to service') }}
                            </div>
                        </div>
                    </div>
                    <div class="right">

                        <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                            class="btn btn-light">{{ translate('Choose Subscription_Plan') }} <i
                                class="tio-arrow-forward"></i></a>
                    </div>

                </div>
            </div>

        @elseif ($restaurant_data?->restaurant_sub == null)
            <div class="free-trial trial danger-bg">
                <div class="inner-div">
                    <div class="left">
                        <img src="{{ dynamicAsset('assets/admin/img/timer-2.svg') }}" alt="">
                        <div class="left-content">
                            <h6>{{ translate('Your_Subscription_Has_Been_Expired_on') }}
                                {{ \App\CentralLogics\Helpers::date_format($restaurant_data?->restaurant_sub_update_application?->expiry_date_parsed) }}
                            </h6>
                            <div>
                                {{ translate('Purchase a subscription plan or contact with the admin to settle the payment and unblock the access to service') }}
                            </div>
                        </div>
                    </div>
                    <div class="right">

                        <a href="{{ route('vendor.subscriptionackage.subscriberDetail', ['open_plans' => true]) }}"
                            class="btn btn-light">{{ translate('Change/Renew Subscription_Plan') }} <i
                                class="tio-arrow-forward"></i></a>
                    </div>

                </div>
            </div>
        @endif

    @endif
</div>


<div class="modal fade removeSlideDown" id="staticBackdrop" tabindex="-1" aria-labelledby="staticBackdropLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered max-w-520">
        <div class="modal-content modal-content__search border-0">
            <div class="d-flex flex-column gap-3 rounded-20 bg-card py-2 px-3">
                <div class="d-flex gap-2 align-items-center position-relative">
                    <form class="flex-grow-1" id="searchForm" action="{{ route('vendor.search.routing') }}">
                        @csrf
                        <div class="d-flex align-items-center global-search-container">
                            <input class="form-control flex-grow-1 rounded-10 search-input" id="searchInput" name="search" type="search" placeholder="Search" aria-label="Search" autofocus>
                        </div>
                    </form>
                    <div class="position-absolute right-0 pr-2">
                        <button class="border-0 rounded px-2 py-1" type="button" data-dismiss="modal">{{ translate('Esc') }}</button>
                    </div>
                </div>

                <div class="min-h-350">
                    <div class="search-result" id="searchResults">
                        <div class="text-center text-muted py-5">{{translate('It appears that you have not yet searched.')}}.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
    document.addEventListener('DOMContentLoaded', function() {
        $(document).on('click', '.add-to-session', function() {
            var session_data = $(this).data("id");
            $.ajax({
                url: '{{ route('vendor.subscriptionackage.addToSession') }}',
                method: 'POST',
                data: {
                    value: session_data,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    $('#hide-subscription-warnings').addClass('d-none')
                }
            });
        });
    });
</script>
