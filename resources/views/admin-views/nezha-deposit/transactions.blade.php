@extends('layouts.admin.app')

@section('title', translate('预存佣金流水'))

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h2 class="page-header-title">
            <span class="page-header-icon"><i class="tio-receipt"></i></span>
            <span>{{ translate('预存佣金流水') }}</span>
        </h2>
        <a href="{{ route('admin.nezha-deposit.index') }}" class="btn btn-sm btn-outline-secondary">&larr; {{ translate('返回一览') }}</a>
    </div>

    <div class="card">
        <div class="card-header">
            <form action="{{ route('admin.nezha-deposit.transactions') }}" method="GET" class="row g-2 align-items-end">
                <div class="col-sm-4">
                    <label class="input-label">{{ translate('商家') }}</label>
                    <select name="restaurant_id" class="form-control">
                        <option value="">{{ translate('全部商家') }}</option>
                        @foreach($restaurants as $r)
                            <option value="{{ $r->id }}" {{ (string)$restaurant_id === (string)$r->id ? 'selected' : '' }}>{{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-4">
                    <label class="input-label">{{ translate('类型') }}</label>
                    <select name="type" class="form-control">
                        <option value="">{{ translate('全部类型') }}</option>
                        <option value="recharge" {{ $type === 'recharge' ? 'selected' : '' }}>{{ translate('充值') }}</option>
                        <option value="commission_deduction" {{ $type === 'commission_deduction' ? 'selected' : '' }}>{{ translate('扣佣') }}</option>
                        <option value="refund_reversal" {{ $type === 'refund_reversal' ? 'selected' : '' }}>{{ translate('退款返还') }}</option>
                    </select>
                </div>
                <div class="col-sm-4">
                    <button class="btn btn-primary">{{ translate('messages.filter') }}</button>
                    <a href="{{ route('admin.nezha-deposit.transactions') }}" class="btn btn-outline-secondary">{{ translate('messages.reset') }}</a>
                </div>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('时间') }}</th>
                            <th>{{ translate('商家') }}</th>
                            <th>{{ translate('类型') }}</th>
                            <th class="text-right">{{ translate('变动') }}</th>
                            <th class="text-right">{{ translate('佣金') }}</th>
                            <th class="text-right">{{ translate('变动后余额') }}</th>
                            <th>{{ translate('订单') }}</th>
                            <th>{{ translate('备注') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($transactions as $t)
                            @php
                                $label = ['recharge'=>translate('充值'),'commission_deduction'=>translate('扣佣'),'refund_reversal'=>translate('退款返还')][$t->type] ?? $t->type;
                                $cls = $t->type==='recharge' ? 'badge-soft-success' : ($t->type==='commission_deduction' ? 'badge-soft-warning' : 'badge-soft-info');
                            @endphp
                            <tr>
                                <td><small>{{ \Carbon\Carbon::parse($t->created_at)->format('Y-m-d H:i') }}</small></td>
                                <td>{{ $t->restaurant->name ?? ('vendor '.$t->vendor_id) }}</td>
                                <td><span class="badge {{ $cls }}">{{ $label }}</span></td>
                                <td class="text-right {{ $t->amount < 0 ? 'text-danger' : 'text-success' }}">{{ \App\CentralLogics\Helpers::format_currency($t->amount) }}</td>
                                <td class="text-right">{{ $t->commission ? \App\CentralLogics\Helpers::format_currency($t->commission) : '—' }}</td>
                                <td class="text-right {{ $t->balance_after < 0 ? 'text-danger font-weight-bold' : '' }}">{{ \App\CentralLogics\Helpers::format_currency($t->balance_after) }}</td>
                                <td>{{ $t->order_id ? ('#'.$t->order_id) : '—' }}</td>
                                <td><small>{{ $t->note }}</small></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center py-4 text-muted">{{ translate('暂无流水') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3 d-flex justify-content-end">{!! $transactions->links() !!}</div>
        </div>
    </div>
</div>
@endsection
