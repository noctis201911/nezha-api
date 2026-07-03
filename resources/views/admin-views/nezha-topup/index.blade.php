@extends('layouts.admin.app')

@section('title', translate('充值申请'))

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h2 class="page-header-title">
            <span class="page-header-icon"><i class="tio-add-circle"></i></span>
            <span>{{ translate('充值申请') }}</span>
        </h2>
        <p class="text-muted mb-0">{{ translate('商家自助提交的充值申请在此审核。核对平台收款账户实际到账后, 填【实际入账金额】确认入账(以实际到账为准, 可与申请额不同); 入账走既有记账, 商家余额与对账中心即时增加。本页仅处理充值方向。') }}</p>
    </div>

    {{-- 状态筛选 --}}
    @php
        $tabs = [
            'pending'  => translate('待审') . ' (' . $counts['pending'] . ')',
            'approved' => translate('已入账') . ' (' . $counts['approved'] . ')',
            'rejected' => translate('已打回') . ' (' . $counts['rejected'] . ')',
        ];
    @endphp
    <div class="mb-3 d-flex gap-2 flex-wrap">
        @foreach($tabs as $st => $label)
            <a href="{{ route('admin.nezha-topup.index', ['status' => $st]) }}"
               class="btn btn-sm {{ $status === $st ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>#</th>
                            <th>{{ translate('商家') }}</th>
                            <th>{{ translate('账户') }}</th>
                            <th class="text-right">{{ translate('申请金额') }}</th>
                            @if($status === 'approved')<th class="text-right">{{ translate('实际入账') }}</th>@endif
                            <th class="text-center">{{ translate('凭证') }}</th>
                            <th>{{ translate('备注') }}</th>
                            <th>{{ translate('提交时间') }}</th>
                            <th class="text-center">
                                {{ $status === 'pending' ? translate('操作') : ($status === 'rejected' ? translate('打回理由') : translate('入账时间')) }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($list as $r)
                            @php $acc = translate($accountLabels[$r->account_type] ?? $r->account_type); @endphp
                            <tr>
                                <td>#{{ $r->id }}</td>
                                <td>
                                    <span class="d-block font-weight-bold">{{ optional($r->restaurant)->name ?? ('#' . $r->restaurant_id) }}</span>
                                    <small class="text-muted">vendor {{ $r->vendor_id }}</small>
                                </td>
                                <td><span class="badge badge-soft-info">{{ $acc }}</span></td>
                                <td class="text-right font-weight-bold">{{ \App\CentralLogics\Helpers::format_currency($r->amount_claimed) }}</td>
                                @if($status === 'approved')
                                    <td class="text-right text-success font-weight-bold">{{ \App\CentralLogics\Helpers::format_currency($r->amount_credited ?? 0) }}</td>
                                @endif
                                <td class="text-center">
                                    @if($r->proof_path)
                                        <a href="{{ asset('storage/' . $r->proof_path) }}" target="_blank" rel="noopener">
                                            <img src="{{ asset('storage/' . $r->proof_path) }}" alt="proof"
                                                 style="height:42px;width:42px;object-fit:cover;border-radius:6px;border:1px solid #eee;">
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td><small>{{ $r->note ?: '—' }}</small></td>
                                <td><small>{{ \Carbon\Carbon::parse($r->created_at)->format('Y-m-d H:i') }}</small></td>
                                <td class="text-center text-nowrap">
                                    @if($status === 'pending')
                                        <button type="button" class="btn btn-sm btn-primary nz-approve-btn"
                                            data-id="{{ $r->id }}"
                                            data-name="{{ optional($r->restaurant)->name }}"
                                            data-account="{{ $acc }}"
                                            data-claimed="{{ $r->amount_claimed }}"
                                            data-toggle="modal" data-target="#nzApproveModal">{{ translate('确认入账') }}</button>
                                        <button type="button" class="btn btn-sm btn-outline-danger nz-reject-btn"
                                            data-id="{{ $r->id }}"
                                            data-toggle="modal" data-target="#nzRejectModal">{{ translate('打回') }}</button>
                                    @elseif($status === 'rejected')
                                        <small class="text-danger">{{ $r->reason ?: '—' }}</small>
                                    @else
                                        <small>{{ $r->reviewed_at ? \Carbon\Carbon::parse($r->reviewed_at)->format('Y-m-d H:i') : '—' }}</small>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $status === 'approved' ? 9 : 8 }}" class="text-center py-4 text-muted">{{ translate('暂无申请') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3 d-flex justify-content-end">{!! $list->links() !!}</div>
        </div>
    </div>
</div>

{{-- 确认入账 弹窗 --}}
<div class="modal fade" id="nzApproveModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form id="nzApproveForm" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ translate('确认入账') }}</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-soft-secondary py-2 small mb-3" id="nzApInfo"></div>
                    <div class="form-group">
                        <label class="input-label">{{ translate('实际入账金额') }} (֏) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount_credited" id="nzApAmount" class="form-control" required>
                        <small class="text-muted">{{ translate('以平台账户实际到账为准, 可与申请额不同。') }}</small>
                    </div>
                    <div class="form-group">
                        <label class="input-label">{{ translate('备注') }}</label>
                        <input type="text" name="note" class="form-control" maxlength="255" placeholder="{{ translate('可选, 如到账渠道 / 时间') }}">
                    </div>
                    <p class="text-muted small mb-0">{{ translate('入账走既有记账(预存佣金 / 押金 / 广告 各自子余额), 平台对商家收的是 B2B 预付, 不涉及顾客资金。') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ translate('取消') }}</button>
                    <button type="submit" class="btn btn-primary">{{ translate('确认入账') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- 打回 弹窗 --}}
<div class="modal fade" id="nzRejectModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form id="nzRejectForm" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ translate('打回充值申请') }}</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label class="input-label">{{ translate('打回理由') }} <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" maxlength="255" required placeholder="{{ translate('如: 未收到到账 / 凭证不清晰, 请重新提交') }}"></textarea>
                    </div>
                    <p class="text-muted small mb-0">{{ translate('打回后商家可修改并重新提交。') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ translate('取消') }}</button>
                    <button type="submit" class="btn btn-danger">{{ translate('确认打回') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('script_2')
<script>
    "use strict";
    (function () {
        var approveBase = "{{ url('admin/nezha-topup/approve') }}";
        var rejectBase = "{{ url('admin/nezha-topup/reject') }}";

        document.querySelectorAll('.nz-approve-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                document.getElementById('nzApproveForm').action = approveBase + '/' + b.dataset.id;
                document.getElementById('nzApAmount').value = b.dataset.claimed || '';
                document.getElementById('nzApInfo').innerHTML =
                    '#' + b.dataset.id + ' &middot; <b>' + (b.dataset.name || '') + '</b> &middot; ' + (b.dataset.account || '');
            });
        });

        document.querySelectorAll('.nz-reject-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                document.getElementById('nzRejectForm').action = rejectBase + '/' + b.dataset.id;
            });
        });
    })();
</script>
@endpush
