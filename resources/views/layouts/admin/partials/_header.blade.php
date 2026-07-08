<div id="headerMain" class="d-none">
    <header id="header"
            class="navbar navbar-expand-lg navbar-fixed navbar-height navbar-flush navbar-container navbar-bordered">
        <div class="navbar-nav-wrap">
            <div class="navbar-brand-wrapper">
                <!-- Logo -->
                {{-- @php($restaurant_logo=\App\Models\BusinessSetting::where(['key'=>'logo'])->first()) --}}
                @php($restaurant_logo=\App\CentralLogics\Helpers::getSettingsDataFromConfig(settings:'logo',relations:['storage']))

                <a class="navbar-brand d-none d-md-block" href="{{route('admin.dashboard')}}" aria-label="">
                         <img class="navbar-brand-logo brand--logo-design-2"
                         src="{{ \App\CentralLogics\Helpers::get_full_url('business',$restaurant_logo?->value,$restaurant_logo?->storage[0]?->value ?? 'public', 'favicon') }}"
                         alt="image">
                         <img class="navbar-brand-logo-mini brand--logo-design-2"
                         src="{{ \App\CentralLogics\Helpers::get_full_url('business',$restaurant_logo?->value,$restaurant_logo?->storage[0]?->value ?? 'public', 'favicon') }}"
                         alt="image">
                </a>
                <!-- End Logo -->
            </div>

            <div class="navbar-nav-wrap-content-left d--xl-none">
                <!-- Navbar Vertical Toggle -->
                <button type="button" class="js-navbar-vertical-aside-toggle-invoker close mr-3">
                    <i class="tio-first-page navbar-vertical-aside-toggle-short-align" data-toggle="tooltip"
                       data-placement="right" title="Collapse"></i>
                    <i class="tio-last-page navbar-vertical-aside-toggle-full-align"
                       data-template='<div class="tooltip d-none d-sm-block" role="tooltip"><div class="arrow"></div><div class="tooltip-inner"></div></div>'
                       data-toggle="tooltip" data-placement="right" title="Expand"></i>
                </button>
                <!-- End Navbar Vertical Toggle -->
            </div>

            <!-- Secondary Content -->
            <div class="navbar-nav-wrap-content-right">
                <!-- Navbar -->
                <ul class="navbar-nav align-items-center flex-row">
                    <li class="nav-item max-sm-m-0 w-md-200px">
                        <button type="button" id="modalOpener" class="title-color bg--secondary border-0 rounded justify-content-between w-100 align-items-center py-2 px-2 px-md-3 d-flex gap-1" data-toggle="modal" data-target="#staticBackdrop">
                            <div class="d-flex gap-1 align-items-center">
                                <i class="tio-search"></i>
                                <span class="d-none d-md-block text-muted">{{translate('Search')}}</span>
                            </div>
                            <span class="bg-card text-muted border rounded-3 p-1 fs-12 fw-bold lh-1 ms-1 ctrlplusk d-none d-md-block">Ctrl+K</span>
                        </button>
                    </li>
                    <li class="nav-item d-none d-sm-inline-block mr-2">
                        <div class="hs-unfold">
                            <div>
                                @php( $local = session()->has('local')?session('local'):null)
                                @php($lang = \App\CentralLogics\Helpers::get_business_settings('system_language'))
                                @if ($lang)
                                <div
                                    class="topbar-text dropdown disable-autohide text-capitalize d-flex">
                                    <a class=" text-dark dropdown-toggle d-flex align-items-center nav-link "
                                    href="#" data-toggle="dropdown">
                                    @foreach($lang??[] as $data)
                                        @if($data['code']==$local)
                                            <img class="rounded mr-1"  width="20" src="{{ dynamicAsset('assets/admin/img/lang.png') }}" alt="">
                                            {{$data['code']}}
                                        @elseif(!$local &&  $data['default'] == true)
                                                <img class="rounded mr-1"  width="20" src="{{ dynamicAsset('assets/admin/img/lang.png') }}" alt="">
                                                    {{$data['code']}}
                                        @endif
                                    @endforeach
                                    </a>
                                    <ul class="dropdown-menu">
                                        @foreach($lang??[] as $key =>$data)
                                            @if($data['status']==1)
                                                <li>
                                                    <a class="dropdown-item py-1"
                                                        href="{{route('admin.lang',[$data['code']])}}">
                                                        <span class="text-capitalize">{{$data['code']}}</span>
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
                    <li class="nav-item d-none d-sm-inline-block mr-4">
                        <!-- Notification -->
                        <div class="hs-unfold">
                            <a class="js-hs-unfold-invoker btn btn-icon btn-soft-secondary rounded-circle"
                                href="{{route('admin.message.list', ['tab'=> 'customer'])}}">
                                <i class="tio-messages-outlined"></i>
                                @php($message=\App\Models\Conversation::whereUserType('admin')->whereHas('last_message', function($query) {
                                    $query->whereColumn('conversations.sender_id', 'messages.sender_id');
                                })->where('unread_message_count', '>', 0)->count())

                                @if($message!=0)
                                    <span class="btn-status btn-sm-status btn-status-danger"></span>
                                @endif
                            </a>
                        </div>
                        <!-- End Notification -->
                    </li>
                    {{-- 哪吒M2-D3: 顶栏通知铃铛(收编异常订单+逾期退款浮窗·数据源 admin.get-restaurant-data·行内跳转不新造端点) --}}
                    <li class="nav-item d-none d-sm-inline-block mr-4">
                        <div class="hs-unfold">
                            <a id="nzBellInvoker" class="js-hs-unfold-invoker btn btn-icon btn-soft-secondary rounded-circle position-relative" href="javascript:;"
                                data-hs-unfold-options='{"target": "#nzBellDropdown","type": "css-animation"}'>
                                <i class="tio-notifications-outlined"></i>
                                <span id="nzBellBadge" class="btn-status btn-status-danger" style="display:none;"></span>
                            </a>
                            <div id="nzBellDropdown" class="hs-unfold-content dropdown-unfold dropdown-menu dropdown-menu-right nz-bell-dd">
                                <div class="nz-bell-top">
                                    <span class="nz-bell-title">通知</span>
                                    <a class="nz-bell-link" href="{{ route('admin.order.list', ['grp_pending']) }}">查看订单 ›</a>
                                </div>
                                <div id="nzBellBody" class="nz-bell-body">
                                    <div id="nzBellEmpty" class="nz-bell-empty">暂无待处理异常</div>
                                </div>
                            </div>
                        </div>
                    </li>
                    <style>
                        .nz-bell-dd{width:340px;max-width:92vw;padding:0;}
                        .nz-bell-top{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #E4E7EC;}
                        .nz-bell-title{font-weight:600;color:#1A2233;}
                        .nz-bell-link{font-size:12px;color:#102A4C;text-decoration:none;}
                        .nz-bell-body{max-height:60vh;overflow-y:auto;}
                        .nz-bell-empty{text-align:center;color:#8A8F98;padding:30px 16px;font-size:14px;}
                        .nz-bell-h{font-size:12px;color:#5B6472;font-weight:600;padding:10px 16px 4px;background:#F5F6F8;}
                        .nz-bell-row{display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid #F1F3F6;text-decoration:none;color:#1A2233;}
                        .nz-bell-row:hover{background:#F5F6F8;}
                        .nz-bell-row__main{flex:1;min-width:0;}
                        .nz-bell-row__t{font-size:13px;font-weight:600;color:#1A2233;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
                        .nz-bell-row__s{font-size:12px;color:#8A8F98;margin-top:1px;}
                        .nz-bell-row__s--warn{color:#D97A08;}
                        .nz-bell-cta{flex-shrink:0;background:#102A4C;color:#fff;border-radius:8px;padding:4px 12px;font-size:12px;font-weight:600;}
                    </style>
                    <script>
                        (function init(){
                            if(typeof window.jQuery==='undefined'){ return setTimeout(init,200); }
                            var $=window.jQuery;
                            var badge=document.getElementById('nzBellBadge'), body=document.getElementById('nzBellBody'), empty=document.getElementById('nzBellEmpty');
                            if(!body) return;
                            var detailsBase='{{ url('/admin/order/details') }}', overdueUrl='{{ route('admin.nezha-refund.overdue') }}';
                            function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':s);return d.innerHTML;}
                            function humMin(m){ if(m==null)return''; if(m<60)return m+' 分钟'; var h=Math.floor(m/60); return h<48? h+' 小时' : Math.floor(h/24)+' 天'; }
                            function humHr(h){ if(h==null)return''; return h<48? h+' 小时' : Math.floor(h/24)+' 天'; }
                            function money(n){ try{return String.fromCharCode(1423)+Number(n).toLocaleString();}catch(e){return n;} }
                            function render(d){
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
                            function poll(){ $.get({url:'{{ route('admin.get-restaurant-data') }}',dataType:'json',success:function(resp){ render(resp.data||{}); }}); }
                            poll(); setInterval(poll,15000);
                        })();
                    </script>
                    <li class="nav-item d-none d-sm-inline-block mr-4">
                        <!-- Notification -->
                        <div class="hs-unfold">
                            <a class="js-hs-unfold-invoker btn btn-icon btn-soft-secondary rounded-circle"
                                href="{{route('admin.order.list',['status'=>'pending'])}}">
                                <i class="tio-shopping-cart-outlined"></i>
                                @php($count=\App\CentralLogics\NezhaAdminCounts::get('grp_pending'))
                                    @if($count > 0)
                                    <span class="btn-status btn-status-danger">{{ $count > 9 ? '9+' : $count }}</span>
                                    @endif
                            </a>
                        </div>
                        <!-- End Notification -->
                    </li>
                    <li class="nav-item">
                        <!-- Account -->
                        <div class="hs-unfold">
                            <a class="js-hs-unfold-invoker navbar-dropdown-account-wrapper" href="javascript:;"
                               data-hs-unfold-options='{
                                     "target": "#accountNavbarDropdown",
                                     "type": "css-animation"
                                   }'>
                                <div class="cmn--media dropdown-toggle d-flex align-items-center">
                                    <div class="avatar avatar-sm avatar-circle">
                                            <img class="avatar-img"
                                            src="{{ auth('admin')?->user()?->image_full_url }}"
                                            alt="image">
                                        <span class="avatar-status avatar-sm-status avatar-status-success"></span>
                                    </div>
                                    <div class="media-body pl-2">
                                        <span class="card-title h5 text-right line--limit-1">
                                            {{auth('admin')->user()->f_name}}
                                            {{auth('admin')->user()->l_name}}
                                        </span>
                                        <span class="card-text overflow-wrap-anywhere max-w-130px line--limit-1">{{auth('admin')->user()->email}}</span>
                                    </div>
                                </div>
                            </a>

                            <div id="accountNavbarDropdown"
                                 class="hs-unfold-content dropdown-unfold dropdown-menu dropdown-menu-right navbar-dropdown-menu navbar-dropdown-account w-16rem">
                                <div class="dropdown-item-text">
                                    <div class="media align-items-center">
                                        <div class="avatar avatar-sm avatar-circle mr-2 flex-shrink-0">
                                            <img class="avatar-img"
                                            src="{{ auth('admin')?->user()?->image_full_url ?? dynamicAsset('assets/admin/img/160x160/img1.jpg') }}"
                                            alt="image">
                                        </div>
                                        <div class="media-body">
                                            <span class="card-title h5 line-limit-1">{{auth('admin')->user()->f_name}}
                                            {{auth('admin')->user()->l_name}}</span>
                                            <span class="card-text overflow-wrap-anywhere line--limit-1">{{auth('admin')->user()->email}}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="dropdown-divider"></div>

                                <a class="dropdown-item" href="{{route('admin.settings')}}">
                                    <span class="text-truncate pr-2" title="Settings">{{translate('messages.settings')}}</span>
                                </a>

                                <div class="dropdown-divider"></div>

                                <a class="dropdown-item" href="javascript:" onclick="Swal.fire({
                                    title: `{{ translate('messages.Do_You_Want_To_Sign_Out_?') }}`,
                                    showDenyButton: true,
                                    showCancelButton: true,
                                    confirmButtonColor: `#FC6A57`,
                                    cancelButtonColor: `#363636`,
                                    confirmButtonText: `{{ translate('messages.Yes') }}`,
                                    cancelButtonText: `{{ translate('messages.cancel') }}`,
                                    }).then((result) => {
                                    if (result.value) {
                                    location.href=`{{route('logout')}}`;
                                    }
                                    })">
                                    <span class="text-truncate pr-2" title="Sign out">{{translate('messages.sign_out')}}</span>
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

<div class="modal fade removeSlideDown" id="staticBackdrop" tabindex="-1" aria-labelledby="staticBackdropLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered max-w-520">
        <div class="modal-content modal-content__search border-0">
            <div class="d-flex flex-column gap-3 rounded-20 bg-card py-2 px-3">
                <div class="d-flex gap-2 align-items-center position-relative">
                    <form class="flex-grow-1" id="searchForm" action="{{ route('admin.search.routing') }}">
                        @csrf
                        <div class="d-flex align-items-center global-search-container">
                            <input class="form-control flex-grow-1 rounded-10 search-input" id="searchInput" name="search" type="search" placeholder="搜索" aria-label="搜索" autofocus>
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
