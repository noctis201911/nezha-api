{{-- 哪吒 M-01 待办行动条: 5 张卡, 点卡直达对应订单过滤列表。计数与轮询端点 restaurant_data() 同源(nezha_todo_counts)。
     0 单也显示且置灰不隐藏(让商家确认"系统真没积压"而非"没加载出来"); 仅"配送催办"卡 0 时隐藏(边缘提醒)。
     红点/计数只反映真实未读, 不造假(命中在场感知护栏)。 --}}
@php
    $nz_todo = $nz_todo ?? [];
    $nz_offline  = (int) ($nz_todo['new_offline_order'] ?? 0);
    $nz_pending  = (int) ($nz_todo['new_pending_order'] ?? 0);
    $nz_refund   = (int) ($nz_todo['refund_pending'] ?? 0);
    $nz_timeout  = (int) ($nz_todo['timeout_total'] ?? 0);
    $nz_deliv    = (int) ($nz_todo['deliv_link_total'] ?? 0);
    $nz_timeout_key = $nz_todo['timeout_list_key'] ?? 'pending';
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

            {{-- 超时单 (过渡期落第一桶主列表; M-02 虚拟过滤上线后改指向它) --}}
            <div class="col-6 col-lg">
                <a href="{{ route('vendor.order.list', [$nz_timeout_key]) }}"
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
        </div>
    </div>
</div>
