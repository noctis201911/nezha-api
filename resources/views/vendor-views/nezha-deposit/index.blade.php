@extends('layouts.vendor.app')

@section('title', translate('预存佣金'))

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col-sm mb-2 mb-sm-0">
                <h2 class="page-header-title text-capitalize">
                    <span class="card-header-icon d-inline-flex mr-2"><i class="tio-wallet"></i></span>
                    <span>{{ translate('预存佣金') }}</span>
                </h2>
            </div>
        </div>
    </div>

    {{-- 余额卡 --}}
    <div class="row g-3 mb-3">
        <div class="col-md-5">
            <div class="card h-100">
                <div class="card-body text-center">
                    @if($balance < 0)
                        <h6 class="text-danger mb-1">{{ translate('当前欠款') }}</h6>
                        <h2 class="text-danger mb-1">{{ \App\CentralLogics\Helpers::format_currency(abs($balance)) }}</h2>
                        <p class="text-danger small mb-0">{{ translate('您已欠平台佣金, 请尽快充值, 否则无法接收新订单。') }}</p>
                    @else
                        <h6 class="text-muted mb-1">{{ translate('预存佣金余额') }}</h6>
                        <h2 class="mb-1">{{ \App\CentralLogics\Helpers::format_currency($balance) }}</h2>
                        <p class="text-muted small mb-0">{{ translate('平台按每单佣金从这里扣除; 余额不足将暂停接单。') }}</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="mb-2">{{ translate('如何充值?') }}</h6>
                    <p class="text-muted small mb-0">{{ translate('请按平台告知的方式把预存佣金转给平台, 平台运营核实后会为您入账, 余额即时增加。预存佣金是您预付给平台的佣金(B2B), 与顾客货款无关。如需充值请联系平台客服。') }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- 低额告警设置 --}}
    <div class="card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">{{ translate('余额不足邮件提醒') }}</h5></div>
        <div class="card-body">
            <form action="{{ route('vendor.nezha-deposit.update-alert') }}" method="POST">
                @csrf
                @php $r = $restaurant; @endphp
                <div class="form-group">
                    <label class="toggle-switch d-flex align-items-center" for="alert_enabled">
                        <input type="checkbox" class="toggle-switch-input" id="alert_enabled" name="deposit_alert_enabled" value="1" {{ ($r && $r->deposit_alert_enabled) ? 'checked' : '' }}>
                        <span class="toggle-switch-label mr-2"><span class="toggle-switch-indicator"></span></span>
                        <span>{{ translate('开启余额不足邮件提醒') }}</span>
                    </label>
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label class="input-label">{{ translate('当余额低于(含负数告警)') }}</label>
                        <input type="number" step="0.01" min="0" name="deposit_alert_threshold" class="form-control" value="{{ $r->deposit_alert_threshold ?? '' }}" placeholder="{{ translate('如: 100') }}">
                        <small class="text-muted">{{ translate('余额低于这个数(或为负)时, 每天最多给您发一封提醒邮件。') }}</small>
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="input-label">{{ translate('提醒发送到的邮箱') }}</label>
                        <input type="email" name="deposit_alert_email" class="form-control" value="{{ $r->deposit_alert_email ?? '' }}" placeholder="you@example.com">
                        <small class="text-muted">{{ translate('可填与注册邮箱不同的邮箱。') }}</small>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">{{ translate('messages.save') }}</button>
            </form>
        </div>
    </div>

    {{-- 流水 --}}
    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0">{{ translate('预存佣金流水') }}</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('时间') }}</th>
                            <th>{{ translate('类型') }}</th>
                            <th class="text-right">{{ translate('变动') }}</th>
                            <th class="text-right">{{ translate('变动后余额') }}</th>
                            <th>{{ translate('订单') }}</th>
                            <th>{{ translate('备注') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($transactions as $t)
                            @php
                                $label = ['recharge'=>translate('充值'),'commission_deduction'=>translate('扣佣'),'refund_reversal'=>translate('退款返还')][$t->type] ?? $t->type;
                            @endphp
                            <tr>
                                <td><small>{{ \Carbon\Carbon::parse($t->created_at)->format('Y-m-d H:i') }}</small></td>
                                <td>{{ $label }}</td>
                                <td class="text-right {{ $t->amount < 0 ? 'text-danger' : 'text-success' }}">{{ \App\CentralLogics\Helpers::format_currency($t->amount) }}</td>
                                <td class="text-right {{ $t->balance_after < 0 ? 'text-danger font-weight-bold' : '' }}">{{ \App\CentralLogics\Helpers::format_currency($t->balance_after) }}</td>
                                <td>{{ $t->order_id ? ('#'.$t->order_id) : '—' }}</td>
                                <td><small>{{ $t->note }}</small></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center py-4 text-muted">{{ translate('暂无流水') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3 d-flex justify-content-end">{!! $transactions->links() !!}</div>
        </div>
    </div>
</div>
@endsection
