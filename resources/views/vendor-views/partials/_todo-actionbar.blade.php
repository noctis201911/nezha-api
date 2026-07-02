{{-- 哪吒 M-01 待办行动条: 常驻卡(订单4张+差评预警), 点卡直达对应过滤列表。计数与轮询端点 restaurant_data() 同源(nezha_todo_counts)。
     0 单也显示且置灰不隐藏(让商家确认"系统真没积压"而非"没加载出来", 也让商家平时就知道有这些功能); 仅"配送催办"为边缘提醒卡, 0 时隐藏。
     差评预警常显(0=置灰/有差评=红), 数来自 nezha_today_summary(首屏计算, 不进 6s 轮询), 故不随轮询刷新, 属首屏快照。
     红点/计数只反映真实未读, 不造假(命中在场感知护栏)。 --}}
@php
    $nz_todo = $nz_todo ?? [];
    $nz_today = $nz_today ?? [];
    $nz_offline  = (int) ($nz_todo['offline_pending_display'] ?? $nz_todo['new_offline_order'] ?? 0); // 哪吒P1b-A: 持续待办徽标用全量'有凭证待核'(与侧栏/列表同源), checked=0 仅供新单响铃
    $nz_pending  = (int) ($nz_todo['new_pending_order'] ?? 0);
    $nz_refund   = (int) ($nz_todo['refund_pending'] ?? 0);
    $nz_timeout  = (int) ($nz_todo['timeout_total'] ?? 0);
    $nz_deliv    = (int) ($nz_todo['deliv_link_total'] ?? 0);
    // 哪吒[差评预警]: 最近N天 rating<=3 且未回复的新差评数(来自 nezha_today_summary, 首屏计算不进轮询)。
    $nz_bad_review = (int) ($nz_today['bad_review_count'] ?? 0);
    $nz_bad_days   = (int) ($nz_today['bad_review_days'] ?? 7);
    $nz_bad_from   = $nz_today['bad_review_from'] ?? null;
    $nz_bad_to     = $nz_today['bad_review_to'] ?? null;
@endphp

<div class="card mb-3 nz-todo-actionbar">
    <div class="card-header p-2">
        <h4 class="card-header-title">待办行动</h4>
    </div>
    <div class="card-body p-2">
        <div class="row g-2">
            {{-- 待确认收款 (离线已传凭证待核验) --}}
            <div class="col-6 col-lg">
                <a href="{{ route('vendor.order.list', ['offline_pending']) }}"
                   class="order--card h-100 d-block {{ $nz_offline > 0 ? 'border-danger' : '' }}">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="card-subtitle m-0 d-flex align-items-center">
                            <span class="legend-indicator {{ $nz_offline > 0 ? 'bg-danger' : 'bg-secondary' }}"></span>
                            <span>待确认收款</span>
                        </h6>
                        <span class="card-title h3 mb-0 {{ $nz_offline > 0 ? 'text-danger' : 'text-muted' }}">{{ $nz_offline }}</span>
                    </div>
                </a>
            </div>

            @if(false){{-- 哪吒P1a[2026-07-03]: 「待处理」永空(同列表tabs/侧栏), 整卡封存(业主批复) --}}
            {{-- 待处理 --}}
            <div class="col-6 col-lg">
                <a href="{{ route('vendor.order.list', ['pending']) }}"
                   class="order--card h-100 d-block {{ $nz_pending > 0 ? 'border-success' : '' }}">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="card-subtitle m-0 d-flex align-items-center">
                            <span class="legend-indicator {{ $nz_pending > 0 ? 'bg-success' : 'bg-secondary' }}"></span>
                            <span>待处理</span>
                        </h6>
                        <span class="card-title h3 mb-0 {{ $nz_pending > 0 ? 'text-success' : 'text-muted' }}">{{ $nz_pending }}</span>
                    </div>
                </a>
            </div>

            @endif

            {{-- 待退款 (B方案: 平台已取消/退款, 等商家原路退) --}}
            <div class="col-6 col-lg">
                <a href="{{ route('vendor.order.list', ['refund_pending']) }}"
                   class="order--card h-100 d-block {{ $nz_refund > 0 ? 'border-warning' : '' }}">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="card-subtitle m-0 d-flex align-items-center">
                            <span class="legend-indicator {{ $nz_refund > 0 ? 'bg-warning' : 'bg-secondary' }}"></span>
                            <span>待退款</span>
                        </h6>
                        <span class="card-title h3 mb-0 {{ $nz_refund > 0 ? 'text-warning' : 'text-muted' }}">{{ $nz_refund }}</span>
                    </div>
                </a>
            </div>

            {{-- 超时单 (M-02 虚拟过滤: 落点 /list/timeout, 计数与列表同源 NezhaOrderTimeout::alertOrderIds) --}}
            <div class="col-6 col-lg">
                <a href="{{ route('vendor.order.list', ['timeout']) }}"
                   class="order--card h-100 d-block {{ $nz_timeout > 0 ? 'border-danger' : '' }}">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="card-subtitle m-0 d-flex align-items-center">
                            <i class="tio-time mr-1 {{ $nz_timeout > 0 ? 'text-danger' : 'text-muted' }}"></i>
                            <span>超时单</span>
                        </h6>
                        <span class="card-title h3 mb-0 {{ $nz_timeout > 0 ? 'text-danger' : 'text-muted' }}">{{ $nz_timeout }}</span>
                    </div>
                </a>
            </div>

            {{-- 配送催办 (顾客已催但商家未贴 Yandex 链接); 0 时隐藏(边缘提醒, 留奇数补位空白即可) --}}
            @if($nz_deliv > 0)
            <div class="col-6 col-lg">
                <a href="{{ route('vendor.order.list', ['food_on_the_way']) }}"
                   class="order--card h-100 d-block border-info">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="card-subtitle m-0 d-flex align-items-center">
                            <i class="tio-notifications-on mr-1 text-info"></i>
                            <span>配送催办</span>
                        </h6>
                        <span class="card-title h3 mb-0 text-info">{{ $nz_deliv }}</span>
                    </div>
                </a>
            </div>
            @endif

            {{-- 差评预警 (最近N天新差评 ≤3星 且未回复); 常显不隐藏(0=置灰/有差评=红), 让商家平时就知道有这个功能。
                 点卡直达评价页并预筛(未回复+≤3星+同一日期窗), 与卡上数字同源同集合(ReviewController@index)。
                 纯只读统计, 不造假红点(命中在场感知护栏)。 --}}
            <div class="col-6 col-lg">
                <a href="{{ route('vendor.reviews', ['rating_max' => 3, 'reply_status' => ['no_reply'], 'start_date' => $nz_bad_from, 'end_date' => $nz_bad_to]) }}"
                   class="order--card h-100 d-block {{ $nz_bad_review > 0 ? 'border-danger' : '' }}">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="card-subtitle m-0 d-flex align-items-center">
                            <i class="tio-star mr-1 {{ $nz_bad_review > 0 ? 'text-danger' : 'text-muted' }}"></i>
                            <span>差评预警<small class="text-muted ml-1">近{{ $nz_bad_days }}天{{ $nz_bad_review > 0 ? ' · 去回复' : '' }}</small></span>
                        </h6>
                        <span class="card-title h3 mb-0 {{ $nz_bad_review > 0 ? 'text-danger' : 'text-muted' }}">{{ $nz_bad_review }}</span>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>
