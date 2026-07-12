@extends('layouts.vendor.app')

@section('title', translate('messages.Notification_Setup'))
@section('content')
    <div class="content container-fluid">
        @include('vendor-views.partials.business_setup_header')

        {{-- 哪吒外卖: 接单/超时提醒通道(商家自助) --}}
        @if($restaurant ?? null)
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                    <h3 class="mb-0">🔔 哪吒接单 / 超时提醒通道</h3>
                    <span class="badge badge-soft-info">建议至少保留一种，避免漏单</span>
                </div>
                <p class="fs-12 text-muted mb-3">选择用哪些方式接收「新订单」和「订单超时」提醒。下面的设置同时作用于新订单提醒和超时提醒。</p>
                <form action="{{ route('vendor.business-settings.nezha-notify') }}" method="post" id="nezha-notify-form">
                    @csrf
                    <input type="hidden" name="ack_all_off" id="nezha_ack_all_off" value="0">
                    <input type="hidden" name="tg_unbind" id="nezha_tg_unbind" value="0">
                    {{-- Telegram --}}
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" name="timeout_notify_telegram" id="nz_tg_toggle" value="1" {{ ($restaurant->timeout_notify_telegram ?? 1) ? 'checked' : '' }}>
                                <label class="form-check-label fw-medium" for="nz_tg_toggle">接收 Telegram 提醒（手机推送，推荐）</label>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="nzToggleHelp('nz_tg_help')">？怎么用</button>
                        </div>
                        <div id="nz_tg_help" class="alert alert-soft-info fs-12 mt-2" style="display:none">
                            Telegram 是一款免费聊天 App。连接后，新订单和超时提醒会直接推送到你手机，比邮箱更快、更不容易漏。<br>
                            连接方法：① 点下面「打开机器人」并把验证码发送给它 → ② 回来点「我已发送，检测」，系统会自动把这台 Telegram 绑定到你的店。
                        </div>
                        <div class="mt-2">
                            @if($restaurant->telegram_chat_id)
                                <span class="badge badge-soft-success">✓ 已连接（会话 {{ $restaurant->telegram_chat_id }}）</span>
                                <button type="button" class="btn btn-sm btn-link text-danger" onclick="nzUnbindTg()">断开</button>
                            @else
                                <span class="badge badge-soft-warning">未连接</span>
                            @endif
                        </div>
                        <div class="bg-light rounded p-2 mt-2 fs-12">
                            <div class="mb-1">第 1 步：<a href="https://t.me/Nz_order_bot" target="_blank" rel="noopener">打开机器人 {{ $botUser }}</a>，把下面这串验证码发给它：</div>
                            <div class="mb-2"><code class="fs-14 fw-bold">{{ $tgCode }}</code> <button type="button" class="btn btn-sm btn-outline-primary" onclick="nzCopyCode('{{ $tgCode }}')">复制</button></div>
                            <div class="mb-1">第 2 步：发完后点这里 →
                                <button type="button" class="btn btn-sm btn--primary" id="nz_detect_btn" onclick="nzDetectTg()">我已发送，检测</button>
                                <span id="nz_detect_result" class="ms-2"></span>
                            </div>
                        </div>
                    </div>
                    {{-- 邮箱 --}}
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" name="timeout_notify_email" id="nz_mail_toggle" value="1" {{ ($restaurant->timeout_notify_email ?? 1) ? 'checked' : '' }}>
                                <label class="form-check-label fw-medium" for="nz_mail_toggle">接收邮箱提醒</label>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="nzToggleHelp('nz_mail_help')">？怎么用</button>
                        </div>
                        <div id="nz_mail_help" class="alert alert-soft-info fs-12 mt-2" style="display:none">
                            填一个你常看的邮箱，提醒会发到这里；留空则用店铺默认邮箱。注意邮箱通知可能进垃圾箱、也比手机推送慢，建议配合 Telegram 一起用。
                        </div>
                        <div class="mt-2" style="max-width:360px">
                            <input type="email" name="nezha_notify_email" class="form-control" placeholder="例如 you@example.com" value="{{ $restaurant->nezha_notify_email ?: ($restaurant->email ?? '') }}">
                        </div>
                    </div>
                    @if(!empty($preorderOn))
                    {{-- 预约单叫车提醒(07 稿·到建议叫车时间推送摘要一条·防轰炸三件套)。总闸 nezha_preorder_status 关时整块不出(dormant)。 --}}
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" name="nezha_preorder_dispatch_remind" id="nz_po_remind" value="1" {{ !empty($poRemindOn) ? 'checked' : '' }}>
                                <label class="form-check-label fw-medium" for="nz_po_remind">预约单叫车提醒</label>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="nzToggleHelp('nz_po_help')">？怎么用</button>
                        </div>
                        <div id="nz_po_help" class="alert alert-soft-info fs-12 mt-2" style="display:none">
                            到每单的「建议叫车时间」（送达点前固定提前量）推送提醒：多单合并成一条摘要，同一批最多提醒一次；你正开着作业台时不推送、只在作业台里亮提醒。关闭后不再推送，作业台内的提醒横幅照常显示。
                        </div>
                    </div>
                    @endif
                    <div class="alert alert-soft-warning fs-12">⚠️ 为保障顾客权益，「订单超时被自动取消、需你原路退款」这条通知<strong>始终会发送</strong>，不受上面开关影响；它同时也会出现在后台「订单 → 待退款」里。</div>
                    <button type="submit" class="btn btn--primary">保存设置</button>
                </form>
            </div>
        </div>
        <script>
            function nzToggleHelp(id){var e=document.getElementById(id);if(e)e.style.display=(e.style.display==='none'||!e.style.display)?'block':'none';}
            function nzCopyCode(c){if(navigator.clipboard){navigator.clipboard.writeText(c);}}
            function nzUnbindTg(){if(confirm('断开后将不再通过 Telegram 提醒你，确定吗？')){document.getElementById('nezha_tg_unbind').value='1';document.getElementById('nezha-notify-form').submit();}}
            function nzDetectTg(){
                var btn=document.getElementById('nz_detect_btn');var res=document.getElementById('nz_detect_result');
                btn.disabled=true;res.innerHTML='检测中…';
                fetch("{{ route('vendor.business-settings.nezha-telegram-detect') }}",{headers:{'X-Requested-With':'XMLHttpRequest'}})
                .then(function(r){return r.json();})
                .then(function(d){btn.disabled=false;
                    if(d.ok){res.innerHTML='<span class="text-success">✓ '+(d.msg||'已连接')+'</span>';setTimeout(function(){location.reload();},900);}
                    else{res.innerHTML='<span class="text-danger">'+(d.msg||'检测失败')+'</span>';}
                }).catch(function(){btn.disabled=false;res.innerHTML='<span class="text-danger">网络错误，请重试</span>';});
            }
            (function(){
                var f=document.getElementById('nezha-notify-form');if(!f)return;
                f.addEventListener('submit',function(e){
                    var tg=document.getElementById('nz_tg_toggle').checked;
                    var mail=document.getElementById('nz_mail_toggle').checked;
                    if(!tg&&!mail){
                        if(!confirm('你即将关闭全部接单/超时提醒。这样只能靠电脑常开后台页面接单，手机不会再收到任何提醒，容易漏单。\n\n确定你的店用「常开电脑后台」接单吗？')){e.preventDefault();return false;}
                        document.getElementById('nezha_ack_all_off').value='1';
                    }
                });
            })();
        </script>
        @endif

        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-2">
                    <div>
                        <h3 class="mb-1">{{ translate('messages.Restaurant Notification Channels') }}</h3>
                        <p class="fs-12 mb-0">
                            {{ translate('messages.From here you setup who can see what types of notification from') }} {{ $business_name }}
                        </p>
                    </div>
                    <form >
                        <!-- Search -->
                        <div class="input-group input--group flex-nowrap">
                            <input id="datatableSearch_" type="search" name="search" class="form-control w-260 w-100-mobile" placeholder="{{ translate('messages.Search by topics') }}"  value="{{ request()?->search ?? null }}" aria-label="Search">
                            <button type="submit" class="btn btn--secondary">
                                <i class="tio-search"></i>
                            </button>
                        </div>
                        <!-- End Search -->
                    </form>
                </div>
                <div class="table-responsive datatable-custom">
                    <table class="font-size-sm table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                        <thead class="text-nowrap">
                        <tr>
                            <th class="text-dark fw-medium bg-light p-0">
                                <div class="table-custom-td gap-2 d-flex align-items-center justify-content-between p-3 py-2">
                                    <div class="d-flex sl-topics">
                                        <span class="text-dark fw-medium">{{ translate('messages.Sl') }}</span>
                                        <div class="table-cont">
                                            <span class="text-dark fw-medium fs-12">{{ translate('messages.Topics') }}</span>
                                        </div>
                                    </div>
                                    <span class="text-dark fw-medium w-120 text-center">{{ translate('messages.Push Notification') }}</span>
                                    <span class="text-dark fw-medium w-120 text-center">{{ translate('messages.Mail') }}</span>
                                    <span class="text-dark fw-medium w-120 text-center">{{ translate('messages.SMS') }}</span>
                                </div>
                            </th>
                        </tr>
                        </thead>

                        <tbody>
                        <tr>
                            <td colspan="5" class="p-0">
                                @php
                                    $grouped = collect($data)->groupBy(function ($item) {
                                        $parts = explode('_', (string) $item->key);
                                        return $parts[1] ?? 'other';
                                    });
                                    $titleFromKey = function ($g) {
                                        return ucwords(str_replace('_', ' ', $g));
                                    };
                                    // 哪吒 B方案: 隐藏停用功能的通知类目(订阅/提现/活动), 仅留 账户/订单/广告(广告计费启用中)
                                    $grouped = $grouped->except(['subscription', 'withdraw', 'campaign']);
                                    // 哪吒: 保留类目的组头 + 逐项中文标签(原 translate($item->title) 缺译回退英文)
                                    $nzGroupZh = ['account' => '账户', 'order' => '订单', 'advertisement' => '广告', 'other' => '其他'];
                                    $nzKeyZh = [
                                        'restaurant_account_block' => ['账户被封禁', '当你的店铺账户被平台封禁时通知你'],
                                        'restaurant_account_unblock' => ['账户已解封', '当你的店铺账户被解封、恢复经营时通知你'],
                                        'restaurant_order_notification' => ['新订单通知', '有新订单时第一时间通知你'],
                                        'restaurant_advertisement_create_by_admin' => ['平台为你创建广告', '平台为你的店铺创建广告时通知你'],
                                        'restaurant_advertisement_approval' => ['广告审核通过', '你提交的广告审核通过、开始投放时通知你'],
                                        'restaurant_advertisement_deny' => ['广告审核未通过', '你提交的广告被驳回时通知你'],
                                        'restaurant_advertisement_resume' => ['广告恢复投放', '广告恢复投放时通知你'],
                                        'restaurant_advertisement_pause' => ['广告暂停投放', '广告暂停投放时通知你'],
                                    ];
                                    $sl = 0;
                                @endphp

                                @foreach ($grouped as $groupKey => $items)
                                    <div class="d-flex gap-2 p-3 py-2 table-toggle-btn cursor-pointer transition active">
                                        <span class="btn-circle text-primary bg-primary" style="--size: 18px; --bs-bg-opacity: 0.1;">
                                            <i class="tio-down-ui fs-12"></i>
                                        </span>
                                        <h5 class="fs-16 text-capitalize">{{ $nzGroupZh[$groupKey] ?? $titleFromKey($groupKey) }}</h5>
                                    </div>

                                    <div class="table-custom-wrap open">
                                        @foreach ($items as $key => $item)
                                            @php
                                                $sl++;
                                                $item_admin_data = \App\CentralLogics\Helpers::getNotificationStatusData('restaurant', $item->key);
                                            @endphp
                                            <div class="table-custom-td gap-2 d-flex align-items-center justify-content-between border-bottom p-3 py-2">
                                                <div class="d-flex sl-topics">
                                                    <span class="text-dark">{{ $sl }}</span>
                                                    <div class="table-cont">
                                                        <h5 class="mb-1">{{ $nzKeyZh[$item->key][0] ?? translate($item->title) }}</h5>
                                                        <p class="fs-12 mb-0">{{ $nzKeyZh[$item->key][1] ?? translate($item->sub_title) }}</p>
                                                    </div>
                                                </div>
                                                <div class="w-120 text-center">
                                                    <div class="d-flex justify-content-center align-content-center">
                                                        @if ($item_admin_data->push_notification_status == 'disable')
                                                            <span>{{ translate('messages.N/A') }}</span>

                                                        {{-- @elseif($item_admin_data->push_notification_status == 'inactive')
                                                            <label class="toggle-switch toggle-switch-sm" data-toggle="tooltip"
                                                                   title="{{ translate('This_notification_turned_off_by_admin.') }}">
                                                                <input type="checkbox"
                                                                       class="status toggle-switch-input dynamic-checkbox" disabled>
                                                                <span class="toggle-switch-label text">
                                                                    <span class="toggle-switch-indicator"></span>
                                                                </span>
                                                            </label> --}}
                                                        @else
                                                            <label class="toggle-switch toggle-switch-sm" data-toggle="tooltip"
                                                                   title="{{ translate('toggle_push_notification_for').' '.translate($item->title) }}">
                                                                <input type="checkbox"
                                                                       id="push_notification_{{$item->key}}"
                                                                       data-id="push_notification_{{$item->key}}"
                                                                       data-type="toggle"
                                                                       data-image-on="{{dynamicAsset('assets/admin/img/modal/mail-success.png')}}"
                                                                       data-image-off="{{dynamicAsset('assets/admin/img/modal/mail-warning.png')}}"
                                                                       data-title-on="{{ translate('Want to enable the Push Notification For').' '.translate($item->title) }} ?"
                                                                       data-title-off="{{ translate('Want to disable the Push Notification For').' '.translate($item->title) }} ?"
                                                                       data-text-on="<p>{{ translate('Push Notification Will Be Enabled For').' '.translate($item->title) }}</p>"
                                                                       data-text-off="<p>{{ translate('Push Notification Will Be disabled For').' '.translate($item->title) }}</p>"
                                                                       class="status toggle-switch-input dynamic-checkbox"
                                                                    {{ $item->push_notification_status == 'active' ? 'checked' : '' }}>
                                                                <span class="toggle-switch-label text">
                                                                    <span class="toggle-switch-indicator"></span>
                                                                </span>
                                                            </label>
                                                            <form action="{{ route('vendor.business-settings.notification_status_change', ['key'=> $item->key, 'type' => 'push_notification']) }}"
                                                                  method="get" id="push_notification_{{$item->key}}_form"></form>
                                                        @endif
                                                    </div>
                                                </div>

                                                <div class="w-120 text-center">
                                                    <div class="d-flex justify-content-center align-content-center">
                                                        @if ($item_admin_data->mail_status == 'disable')
                                                            <span>{{ translate('messages.N/A') }}</span>

                                                        @elseif($item_admin_data->mail_status == 'inactive')
                                                            <label class="toggle-switch toggle-switch-sm" data-toggle="tooltip"
                                                                   title="{{ translate('This_mail_turned_off_by_admin') }}">
                                                                <input type="checkbox"
                                                                       class="status toggle-switch-input dynamic-checkbox" disabled>
                                                                <span class="toggle-switch-label text">
                                                                    <span class="toggle-switch-indicator"></span>
                                                                </span>
                                                            </label>
                                                        @else
                                                            <label class="toggle-switch toggle-switch-sm" data-toggle="tooltip"
                                                                   title="{{ translate('toggle_Mail_for').' '.translate($item->title) }}">

                                                                <input type="checkbox"
                                                                       data-type="toggle"
                                                                       id="mail_{{ $item->key }}"
                                                                       data-id="mail_{{ $item->key }}"
                                                                       data-image-on="{{dynamicAsset('assets/admin/img/modal/mail-success.png')}}"
                                                                       data-image-off="{{dynamicAsset('assets/admin/img/modal/mail-warning.png')}}"
                                                                       data-title-on="{{ translate('Want to enable the Mail For').' '.translate($item->title) }} ?"
                                                                       data-title-off="{{ translate('Want to disable the Mail For').' '.translate($item->title) }} ?"
                                                                       data-text-on="<p>{{ translate('Mail Will Be Enabled For').' '.translate($item->title) }}</p>"
                                                                       data-text-off="<p>{{ translate('Mail Will Be disabled For').' '.translate($item->title) }}</p>"
                                                                       class="status toggle-switch-input dynamic-checkbox"
                                                                    {{ $item->mail_status == 'active' ? 'checked' : '' }}>
                                                                <span class="toggle-switch-label text">
                                                                    <span class="toggle-switch-indicator"></span>
                                                                </span>
                                                            </label>
                                                            <form action="{{ route('vendor.business-settings.notification_status_change', ['key'=> $item->key, 'type' => 'Mail']) }}"
                                                                  method="get" id="mail_{{$item->key}}_form"></form>
                                                        @endif
                                                    </div>
                                                </div>

                                                <div class="w-120 text-center">
                                                    <div class="d-flex justify-content-center align-content-center">
                                                        @if ($item_admin_data->sms_status == 'disable')
                                                            <span>{{ translate('messages.N/A') }}</span>

                                                        @elseif($item_admin_data->sms_status == 'inactive')
                                                            <label class="toggle-switch toggle-switch-sm" data-toggle="tooltip"
                                                                   title="{{ translate('This_sms_turned_off_by_admin') }}">
                                                                <input type="checkbox"
                                                                       class="status toggle-switch-input dynamic-checkbox" disabled>
                                                                <span class="toggle-switch-label text">
                                                                    <span class="toggle-switch-indicator"></span>
                                                                </span>
                                                            </label>
                                                        @else
                                                            <label class="toggle-switch toggle-switch-sm" data-toggle="tooltip"
                                                                   title="{{ translate('toggle_SMS_for').' '.translate($item->title) }}">
                                                                <input type="checkbox"
                                                                       id="SMS_{{ $item->key }}"
                                                                       data-id="SMS_{{ $item->key }}"
                                                                       data-type="toggle"
                                                                       data-image-on="{{dynamicAsset('assets/admin/img/modal/mail-success.png')}}"
                                                                       data-image-off="{{dynamicAsset('assets/admin/img/modal/mail-warning.png')}}"
                                                                       data-title-on="{{ translate('Want to disable the SMS For').' '.translate($item->title) }} ?"
                                                                       data-title-off="{{ translate('Want to disable the SMS For').' '.translate($item->title) }} ?"
                                                                       data-text-on="<p>{{ translate('SMS Will Be Enabled For').' '.translate($item->title) }}</p>"
                                                                       data-text-off="<p>{{ translate('SMS Will Be disabled For').' '.translate($item->title) }}</p>"
                                                                       class="status toggle-switch-input dynamic-checkbox"
                                                                    {{ $item->sms_status == 'active' ? 'checked' : '' }}>
                                                                <span class="toggle-switch-label text">
                                                                    <span class="toggle-switch-indicator"></span>
                                                                </span>
                                                            </label>
                                                            <form action="{{ route('vendor.business-settings.notification_status_change', ['key'=> $item->key, 'type' => 'SMS']) }}"
                                                                  method="get" id="SMS_{{$item->key}}_form"></form>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </td>
                        </tr>

                        </tbody>
                    </table>
                </div>
            </div>
        </div>
@endsection

@push('script_2')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.table-custom-wrap.open').forEach(function (wrap) {
                wrap.style.maxHeight = wrap.scrollHeight + 'px';
                wrap.addEventListener('transitionend', function () {
                    if (wrap.classList.contains('open')) wrap.style.maxHeight = 'none';
                });
            });

            document.querySelectorAll('.table-toggle-btn').forEach(function (btn) {
                const wrap = btn.nextElementSibling;
                if (wrap && wrap.classList.contains('open')) btn.classList.add('active');
                else btn.classList.remove('active');
            });

            document.querySelectorAll('.table-toggle-btn').forEach(function (button) {
                button.addEventListener('click', function () {
                    const tableWrap = this.nextElementSibling;
                    if (!tableWrap) return;

                    const isOpen = tableWrap.classList.contains('open');

                    if (isOpen) {
                        tableWrap.style.maxHeight = tableWrap.scrollHeight + 'px';
                        requestAnimationFrame(() => {
                            tableWrap.style.maxHeight = '0px';
                        });
                        tableWrap.classList.remove('open');
                    } else {
                        tableWrap.classList.add('open');
                        tableWrap.style.maxHeight = tableWrap.scrollHeight + 'px';
                    }

                    this.classList.toggle('active');
                });
            });
        });
    </script>
@endpush

