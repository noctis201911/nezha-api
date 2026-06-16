@extends('layouts.admin.app')

@section('title', translate('佣金充值管理'))

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h2 class="page-header-title">
            <span class="page-header-icon"><i class="tio-wallet"></i></span>
            <span>{{ translate('佣金充值管理') }}（{{ translate('商家预存佣金一览') }}）</span>
        </h2>
        <p class="text-muted mb-0">{{ translate('预存佣金 = 商家预付给平台的佣金, 平台按单从中扣除; 余额不足将停止接单。负余额=商家欠平台佣金。') }}</p>
    </div>

    {{-- 全局汇总 --}}
    <div class="row g-2 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('全平台预存佣金总额') }}</h6>
                <span class="h3">{{ \App\CentralLogics\Helpers::format_currency($summary['total_balance']) }}</span>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('欠款商家数') }}（{{ translate('余额为负') }}）</h6>
                <span class="h3 {{ $summary['negative_count'] > 0 ? 'text-danger' : '' }}">{{ $summary['negative_count'] }}</span>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('累计充值') }}</h6>
                <span class="h3 text-success">{{ \App\CentralLogics\Helpers::format_currency($summary['total_recharge']) }}</span>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('累计扣佣') }}</h6>
                <span class="h3">{{ \App\CentralLogics\Helpers::format_currency($summary['total_deduction']) }}</span>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header flex-between flex-wrap gap-2">
            <h5 class="card-title mb-0">{{ translate('各商家预存佣金') }}</h5>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.nezha-deposit.transactions') }}" class="btn btn-sm btn-outline-primary">{{ translate('全部流水') }}</a>
                <form action="{{ route('admin.nezha-deposit.index') }}" method="GET" class="d-flex gap-1">
                    <input type="search" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="{{ translate('搜索商家名') }}">
                    <button class="btn btn-sm btn-primary">{{ translate('messages.search') }}</button>
                </form>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('商家') }}</th>
                            <th class="text-right">{{ translate('当前余额') }}</th>
                            <th class="text-right">{{ translate('累计充值') }}</th>
                            <th class="text-right">{{ translate('累计扣佣') }}</th>
                            <th class="text-right">{{ translate('累计退还') }}</th>
                            <th>{{ translate('上次充值') }}</th>
                            <th class="text-center">{{ translate('messages.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($restaurants as $r)
                            @php
                                $bal = (float) ($balances[$r->vendor_id] ?? 0);
                                $st = $stats[$r->vendor_id] ?? null;
                            @endphp
                            <tr>
                                <td>
                                    <span class="d-block font-weight-bold">{{ $r->name }}</span>
                                    <small class="text-muted">ID {{ $r->id }} · vendor {{ $r->vendor_id }}</small>
                                </td>
                                <td class="text-right">
                                    @if($bal < 0)
                                        <span class="badge badge-soft-danger">{{ translate('欠款') }} {{ \App\CentralLogics\Helpers::format_currency(abs($bal)) }}</span>
                                    @else
                                        <span class="font-weight-bold">{{ \App\CentralLogics\Helpers::format_currency($bal) }}</span>
                                    @endif
                                </td>
                                <td class="text-right text-success">{{ \App\CentralLogics\Helpers::format_currency($st->total_recharge ?? 0) }}</td>
                                <td class="text-right">{{ \App\CentralLogics\Helpers::format_currency($st->total_deduction ?? 0) }}</td>
                                <td class="text-right">{{ \App\CentralLogics\Helpers::format_currency($st->total_reversal ?? 0) }}</td>
                                <td><small>{{ ($st && $st->last_recharge) ? \Carbon\Carbon::parse($st->last_recharge)->format('Y-m-d H:i') : '—' }}</small></td>
                                <td class="text-center text-nowrap">
                                    <button type="button" class="btn btn-sm btn-primary recharge-btn"
                                        data-id="{{ $r->id }}" data-name="{{ $r->name }}"
                                        data-toggle="modal" data-target="#rechargeModal">{{ translate('记录充值') }}</button>
                                    <a href="{{ route('admin.nezha-deposit.transactions', ['restaurant_id' => $r->id]) }}" class="btn btn-sm btn-outline-secondary">{{ translate('流水') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center py-4 text-muted">{{ translate('暂无商家') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3 d-flex justify-content-end">{!! $restaurants->links() !!}</div>
        </div>
    </div>
</div>

{{-- 记录充值 弹窗 --}}
<div class="modal fade" id="rechargeModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form action="{{ route('admin.nezha-deposit.store-recharge') }}" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ translate('记录商家充值') }}</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="restaurant_id" id="recharge_restaurant_id">
                    <div class="form-group">
                        <label class="input-label">{{ translate('商家') }}</label>
                        <input type="text" id="recharge_restaurant_name" class="form-control" disabled>
                    </div>
                    <div class="form-group">
                        <label class="input-label">{{ translate('充值金额') }} <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required placeholder="{{ translate('商家实际打给平台的预存佣金金额') }}">
                    </div>
                    <div class="form-group">
                        <label class="input-label">{{ translate('备注') }}</label>
                        <input type="text" name="note" class="form-control" maxlength="255" placeholder="{{ translate('如: 微信转账 2026-06-16 / 凭证号') }}">
                    </div>
                    <p class="text-muted small mb-0">{{ translate('提示: 本操作只在商家已把钱打给平台后据实入账; 平台对商家收的是佣金预存(B2B), 不涉及顾客资金。') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ translate('messages.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ translate('确认入账') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('script_2')
<script>
    "use strict";
    document.querySelectorAll('.recharge-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('recharge_restaurant_id').value = this.dataset.id;
            document.getElementById('recharge_restaurant_name').value = this.dataset.name;
        });
    });
</script>
@endpush
