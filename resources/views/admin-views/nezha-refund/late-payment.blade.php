@extends('layouts.admin.app')
@section('title', '迟到付款证据台')
@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title"><i class="tio-search"></i> 迟到付款证据台</h1>
    </div>
    <div class="alert {{ $featureEnabled ? 'alert-success' : 'alert-warning' }}">
        V2 总闸：{{ $featureEnabled ? '已开启' : '关闭中' }}。这里核验取消订单后的迟到付款；原订单永不恢复，平台不经手款项。
    </div>
    <div id="late-case-alert" class="alert d-none"></div>
    @forelse($cases as $case)
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
                <div>
                    <strong>订单 #{{ $case['order_id'] }}</strong>
                    <span class="badge badge-soft-dark ml-2">{{ $case['case_status'] }}</span>
                    <span class="badge badge-soft-info ml-1">{{ strtoupper($case['channel']) }}</span>
                </div>
                <small class="text-muted">案件 {{ $case['case_id'] }}</small>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-lg-6">
                        <dl class="row mb-2">
                            <dt class="col-4">商家 / 顾客</dt><dd class="col-8">{{ $case['restaurant_id'] }} / {{ $case['user_id'] ?? ('guest '.$case['guest_id']) }}</dd>
                            <dt class="col-4">到账金额(原子)</dt><dd class="col-8">{{ $case['received_amount_atomic'] ?? '-' }}</dd>
                            <dt class="col-4">协商退款(原子)</dt><dd class="col-8">{{ $case['refund_amount_atomic'] ?? '-' }}</dd>
                            <dt class="col-4">结案权威</dt><dd class="col-8">{{ $case['evidence_authority'] ?? '尚未结案' }}</dd>
                            <dt class="col-4">付款凭证</dt><dd class="col-8 text-monospace text-break">{{ $case['payment_reference'] ?? '-' }}</dd>
                            <dt class="col-4">退款地址</dt><dd class="col-8 text-monospace text-break">{{ $case['refund_destination'] ?? '-' }}</dd>
                            <dt class="col-4">退款凭证</dt><dd class="col-8 text-monospace text-break">{{ $case['refund_reference'] ?? '-' }}</dd>
                        </dl>
                    </div>
                    <div class="col-lg-6">
                        @if($case['case_status'] === 'late_payment_review_pending')
                            <form class="late-case-action mb-2" data-method="POST" data-url="{{ route('admin.nezha-refund.late-payment.attribute', $case['case_id']) }}">
                                @csrf
                                <div class="input-group input-group-sm">
                                    <input class="form-control" name="received_amount_atomic" inputmode="numeric" pattern="[1-9][0-9]*" required placeholder="实际到账原子金额">
                                    <button class="btn btn-primary">确认到账并进入退款</button>
                                </div>
                            </form>
                            <form class="late-case-action" data-method="POST" data-url="{{ route('admin.nezha-refund.late-payment.close-no-payment', $case['case_id']) }}">
                                @csrf
                                <div class="input-group input-group-sm">
                                    <input class="form-control" name="reason" required maxlength="1000" placeholder="未确认到账的核验说明">
                                    <button class="btn btn-outline-secondary">关闭为未到账</button>
                                </div>
                            </form>
                        @elseif($case['case_status'] === 'late_payment_usdt_refund_verification_pending')
                            <form class="late-case-action" data-method="POST" data-url="{{ route('admin.nezha-refund.late-payment.retry-usdt', $case['case_id']) }}">
                                @csrf
                                <button class="btn btn-sm btn-primary">重新读取链上证据</button>
                            </form>
                        @else
                            <p class="text-muted mb-0">当前状态无需平台动作；如顾客反馈未收到退款，案件会进入争议。</p>
                        @endif
                    </div>
                </div>
                <details class="mt-2">
                    <summary class="font-weight-bold">结构化事件链（{{ count($case['events']) }}）</summary>
                    <div class="table-responsive mt-2">
                        <table class="table table-sm table-bordered">
                            <thead><tr><th>#</th><th>事件 / 状态</th><th>参与方</th><th>证据权威</th><th>结构化载荷</th><th>时间</th></tr></thead>
                            <tbody>
                            @foreach($case['events'] as $event)
                                <tr>
                                    <td>{{ $event['sequence'] }}</td>
                                    <td>{{ $event['type'] }}<br><small>{{ $event['state_from'] ?? '-' }} → {{ $event['state_to'] }}</small></td>
                                    <td>{{ $event['actor_type'] }} {{ $event['actor_id'] }}</td>
                                    <td>{{ $event['evidence_authority'] ?? '-' }}</td>
                                    <td><pre class="mb-0 text-wrap">{{ json_encode($event['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></td>
                                    <td>{{ $event['recorded_at'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        </div>
    @empty
        <div class="card"><div class="card-body text-center text-muted py-5">暂无迟到付款案件。</div></div>
    @endforelse
</div>
@endsection
@push('script_2')
<script>
document.querySelectorAll('.late-case-action').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const button = form.querySelector('button');
        button.disabled = true;
        try {
            const response = await fetch(form.dataset.url, {
                method: form.dataset.method,
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name=_token]').value },
                body: new FormData(form)
            });
            const body = await response.json();
            if (!response.ok) throw new Error(body?.errors?.[0]?.message || '操作失败');
            window.location.reload();
        } catch (error) {
            const alert = document.getElementById('late-case-alert');
            alert.className = 'alert alert-danger';
            alert.textContent = error.message || '操作失败';
            button.disabled = false;
        }
    });
});
</script>
@endpush
