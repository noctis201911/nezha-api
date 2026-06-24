@extends('layouts.admin.app')
@section('title', translate('退款留痕/审核'))
@section('content')
    <div class="content container-fluid">
        <div class="page-header">
            <h1 class="page-header-title"><i class="tio-undo"></i> {{ translate('退款留痕 / 审核') }}
                @if ($pending_count > 0)
                    <span class="badge badge-soft-danger ml-2">{{ translate('待审核') }} {{ $pending_count }}</span>
                @endif
            </h1>
        </div>

        <div class="alert alert-info" role="alert">
            <i class="tio-info"></i>
            {{ translate('合规留痕: 每笔退款的原路锁定地址 / 限额命中 / 链上校验结果留存 ≥5年。USDT 退款请登记商家退款交易哈希做链上校验(收款方须==原付款地址, 金额须≥退款额); 超限退款在此审核放行或拒绝。') }}
        </div>

        <ul class="nav nav-tabs mb-3">
            @foreach (['all' => '全部', 'pending_merchant_refund' => '待商家退款', 'merchant_refunded' => '已退款', 'pending' => '待审核(超限)', 'approved' => '已放行', 'rejected' => '已拒绝'] as $k => $label)
                <li class="nav-item">
                    <a class="nav-link {{ $status == $k ? 'active' : '' }}"
                        href="{{ route('admin.nezha-refund.records', ['status' => $k]) }}">{{ translate($label) }}</a>
                </li>
            @endforeach
        </ul>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('ID') }}</th>
                            <th>{{ translate('订单 / 商家') }}</th>
                            <th>{{ translate('通道') }}</th>
                            <th>{{ translate('退款额 / 原单') }}</th>
                            <th>{{ translate('原路锁定') }}</th>
                            <th>{{ translate('链上校验') }}</th>
                            <th>{{ translate('状态') }}</th>
                            <th>{{ translate('操作') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($records as $r)
                            <tr>
                                <td>{{ $r->id }}</td>
                                <td>#{{ $r->order_id }}<br><small class="text-muted">{{ $r->restaurant->name ?? '-' }}</small></td>
                                <td><span class="badge badge-soft-info">{{ strtoupper($r->payment_channel) }}</span></td>
                                <td>${{ $r->refund_amount }} / ${{ $r->order_amount }}</td>
                                <td style="max-width:240px;"><small>{{ $r->route_locked_note }}</small>
                                    @if ($r->locked_to_address)
                                        <br><small class="text-monospace" style="word-break:break-all;">→ {{ $r->locked_to_address }}</small>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $cvMap = ['verified' => 'badge-soft-success', 'failed' => 'badge-soft-danger', 'manual' => 'badge-soft-warning', 'unverified' => 'badge-soft-secondary', 'na' => 'badge-soft-light'];
                                        $cv = $cvMap[$r->chain_verify_status] ?? 'badge-soft-secondary';
                                    @endphp
                                    <span class="badge {{ $cv }}">{{ $r->chain_verify_status }}</span>
                                </td>
                                <td><span class="badge badge-soft-dark">{{ $r->status }}</span>
                                    @if ($r->risk_action == 'over_limit')
                                        <br><small class="text-danger">{{ translate('超限') }}</small>
                                    @endif
                                </td>
                                <td style="min-width:270px;">
                                    @if ($r->payment_channel == 'usdt' && in_array($r->chain_verify_status, ['unverified', 'manual', 'failed']))
                                        <form action="{{ route('admin.nezha-refund.submit-tx', $r->id) }}" method="post" class="input-group input-group-sm mb-1">
                                            @csrf
                                            <input type="text" name="refund_tx_hash" class="form-control" placeholder="{{ translate('商家退款交易哈希') }}" value="{{ $r->refund_tx_hash }}">
                                            <button class="btn btn-sm btn-primary">{{ translate('链上校验') }}</button>
                                        </form>
                                    @endif
                                    <form action="{{ route('admin.nezha-refund.upload-proof', $r->id) }}" method="post" enctype="multipart/form-data" class="input-group input-group-sm mb-1">
                                        @csrf
                                        <input type="file" name="refund_proof_image" accept="image/*" class="form-control form-control-sm">
                                        <button class="btn btn-sm btn-outline-secondary">{{ translate('传凭证') }}</button>
                                    </form>
                                    @if ($r->refund_proof_image)
                                        <small class="text-success d-block">{{ translate('已上传退款凭证') }}</small>
                                    @endif
                                    @if ($r->status == 'pending_admin')
                                        <div class="d-flex mt-1">
                                            <form action="{{ route('admin.nezha-refund.approve', $r->id) }}" method="post" class="mr-1">
                                                @csrf<button class="btn btn-sm btn-success">{{ translate('放行') }}</button>
                                            </form>
                                            <form action="{{ route('admin.nezha-refund.reject', $r->id) }}" method="post">
                                                @csrf<button class="btn btn-sm btn-danger">{{ translate('拒绝') }}</button>
                                            </form>
                                        </div>
                                    @endif
                                    @if ($r->review_note)
                                        <small class="text-muted d-block">{{ translate('审核备注') }}: {{ $r->review_note }}</small>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    {{ translate('暂无退款记录。开启「退款护栏」后, 每笔退款会在此自动留痕。') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {!! $records->links() !!}
            </div>
        </div>
    </div>
@endsection
