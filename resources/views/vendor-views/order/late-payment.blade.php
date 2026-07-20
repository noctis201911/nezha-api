@extends('layouts.vendor.app')
@section('title', '迟到付款案件')
@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title"><i class="tio-undo"></i> 迟到付款案件</h1>
    </div>
    <div class="alert {{ $featureEnabled ? 'alert-info' : 'alert-warning' }}">
        {{ $featureEnabled ? '请只处理确实收到的款项。' : 'V2 总闸关闭中。' }} 原订单保持取消；确认到账后只能退款，顾客仍需商品则重新下单。
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
                <small class="text-muted">{{ $case['case_id'] }}</small>
            </div>
            <div class="card-body">
                <p><strong>付款凭证：</strong><span class="text-monospace text-break">{{ $case['payment_reference'] ?? '-' }}</span></p>
                <p><strong>实际到账 / 协商退款（原子金额）：</strong>{{ $case['received_amount_atomic'] ?? '-' }} / {{ $case['refund_amount_atomic'] ?? '-' }}</p>
                <p><strong>退款地址：</strong><span class="text-monospace text-break">{{ $case['refund_destination'] ?? '尚未填写' }}</span></p>
                @if($case['case_status'] === 'late_payment_review_pending')
                    <form class="late-case-action mb-2" data-method="PUT" data-url="{{ route('vendor.order.late-payment.attribute', $case['case_id']) }}">
                        @csrf
                        <div class="input-group input-group-sm">
                            <input class="form-control" name="received_amount_atomic" inputmode="numeric" pattern="[1-9][0-9]*" required placeholder="实际到账原子金额">
                            <button class="btn btn-primary">确认已收款</button>
                        </div>
                    </form>
                @endif
                @if($case['case_status'] === 'late_payment_refund_required')
                    <div class="alert alert-light border">退款净额与手续费由您和顾客自行协商；平台不设置默认手续费，也不要求顾客在平台二次确认。</div>
                    <form class="late-case-action mb-2" data-method="PUT" data-url="{{ route('vendor.order.late-payment.terms', $case['case_id']) }}">
                        @csrf
                        <div class="form-row">
                            <div class="col-md-4 mb-2"><input class="form-control form-control-sm" name="refund_amount_atomic" inputmode="numeric" pattern="[1-9][0-9]*" required placeholder="协商退款原子金额"></div>
                            @if(str_starts_with($case['channel'], 'usdt_') && $case['wallet_type'] === 'exchange')
                                <div class="col-md-5 mb-2"><input class="form-control form-control-sm" name="refund_destination" required maxlength="120" placeholder="联系顾客取得的退款地址"></div>
                            @endif
                            <div class="col-md-3 mb-2"><button class="btn btn-sm btn-outline-primary btn-block">保存退款约定</button></div>
                        </div>
                    </form>
                    @if($case['refund_amount_atomic'])
                        <form class="late-case-action" data-method="PUT" data-url="{{ route('vendor.order.late-payment.submit-refund', $case['case_id']) }}">
                            @csrf
                            <div class="form-row">
                                <div class="col-md-5 mb-2"><input class="form-control form-control-sm" name="refund_reference" {{ str_starts_with($case['channel'], 'usdt_') ? 'required' : '' }} maxlength="120" placeholder="{{ str_starts_with($case['channel'], 'usdt_') ? '退款交易哈希' : '支付宝退款流水号（可选）' }}"></div>
                                <div class="col-md-4 mb-2"><input class="form-control form-control-sm" name="note" maxlength="255" placeholder="退款说明（可选）"></div>
                                <div class="col-md-3 mb-2"><button class="btn btn-sm btn-primary btn-block">标记已退款</button></div>
                            </div>
                        </form>
                    @endif
                @endif
                @if($case['case_status'] === 'late_payment_usdt_refund_verification_pending')
                    <div class="alert alert-warning mb-0">退款已提交，平台正在核对链上终局、USDT 合约、地址和协商金额；核验通过前不会关闭。</div>
                @elseif($case['case_status'] === 'late_payment_closed_refunded')
                    <div class="alert alert-success mb-0">案件已关闭（{{ $case['evidence_authority'] }}）。</div>
                @endif
                <details class="mt-3">
                    <summary>案件事件（{{ count($case['events']) }}）</summary>
                    <ul class="mt-2 pl-3">
                    @foreach($case['events'] as $event)
                        <li class="mb-2"><strong>#{{ $event['sequence'] }} {{ $event['type'] }}</strong> · {{ $event['state_to'] }} · {{ $event['evidence_authority'] ?? '无结案权威' }}<br><small class="text-muted">{{ $event['recorded_at'] }}</small></li>
                    @endforeach
                    </ul>
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
