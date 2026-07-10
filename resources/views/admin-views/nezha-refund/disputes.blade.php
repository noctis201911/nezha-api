@extends('layouts.admin.app')
@section('title', translate('退款争议裁决'))
@section('content')
    <div class="content container-fluid">
        <div class="page-header">
            <h1 class="page-header-title"><i class="tio-shield"></i> {{ translate('退款争议裁决') }}</h1>
        </div>

        <div class="alert alert-info" role="alert">
            <i class="tio-info"></i>
            {{ translate('商家对「待退款(凭证在案·先核后退)」发起争议时(核实顾客并未实际付款), 该单暂停催办与逾期计时, 在此等待运营裁决。平台不碰资金, 裁决仅改留痕/审计状态。') }}
            <ul class="mb-0 mt-2">
                <li><strong>{{ translate('维持退款义务') }}</strong>: {{ translate('核实顾客确已付款 → 记录回「待退款」, 商家须原路退还, 逾期计时从裁决时刻恢复。') }}</li>
                <li><strong>{{ translate('核实未收款') }}</strong>: {{ translate('核实顾客并未付款 → 该单留痕关闭(非删除·可审计), 商家无需退款。此裁决为终局, 商家不可再对该单发起争议。') }}</li>
            </ul>
            @if ($status != 1)
                <br><span class="text-danger">{{ translate('注意: 争议流总开关当前关闭(nezha_refund_dispute_status=0), 暂不受理新争议; 本页仍可查看与查阅裁决历史。') }}</span>
            @endif
        </div>

        {{-- 待裁决 --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="card-header-title">{{ translate('待裁决争议(按发起时间升序, 越久越靠前)') }} ({{ $open->total() }})</h5></div>
            <div class="table-responsive">
                <table class="table table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('订单 / 商家') }}</th>
                            <th>{{ translate('应退金额') }}</th>
                            <th>{{ translate('商家陈述') }}</th>
                            <th>{{ translate('发起时间') }}</th>
                            <th style="min-width:300px;">{{ translate('裁决') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($open as $d)
                            <tr>
                                <td>
                                    {{ translate('订单') }} #{{ $d->order_id }}<br>
                                    <span class="text-muted">{{ $d->restaurant->name ?? ('餐厅#' . $d->restaurant_id) }}</span>
                                </td>
                                <td>{{ $d->record ? \App\CentralLogics\Helpers::format_currency($d->record->refund_amount) : '—' }}</td>
                                <td style="max-width:300px;"><div class="text-wrap" style="white-space:normal;">{{ $d->merchant_statement }}</div></td>
                                <td class="text-nowrap">{{ $d->opened_at }}</td>
                                <td>
                                    {{-- 哪吒M3: 裁决两动作=输入确认+L1-2 现场标注(各按钮差异化后果/复述串) --}}
                                    <form method="POST" action="{{ route('admin.nezha-refund.disputes.resolve', $d->id) }}"
                                        data-nz-danger="input" data-nz-l1="{{ translate('退款只原路退回——裁决决定这笔钱是否必须退，直接触及此条。') }}">
                                        @csrf
                                        <textarea name="operator_reason" class="form-control form-control-sm mb-2" rows="2" required maxlength="1000" placeholder="{{ translate('裁决理由(必填, 留痕存档)') }}"></textarea>
                                        <div class="d-flex" style="gap:6px;">
                                            <button type="submit" name="resolution" value="upheld" class="btn btn-sm btn-warning"
                                                data-nz-title="{{ translate('维持退款义务') }}"
                                                data-nz-consequence="{{ translate('该单将回到「待退款」，商家须原路退还顾客，逾期计时从此刻重算。') }}"
                                                data-nz-phrase="维持退款" data-nz-confirm="{{ translate('确认维持退款义务') }}">{{ translate('维持退款义务') }}</button>
                                            <button type="submit" name="resolution" value="closed_no_payment" class="btn btn-sm btn-outline-secondary"
                                                data-nz-title="{{ translate('核实未收款 · 关闭') }}"
                                                data-nz-consequence="{{ translate('确认顾客未付款：该单留痕关闭，商家无需退款。此操作不可撤销。') }}"
                                                data-nz-phrase="确认未收款" data-nz-confirm="{{ translate('确认未收款并关闭') }}">{{ translate('核实未收款') }}</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">{{ translate('当前没有待裁决的退款争议。') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($open->hasPages())
                <div class="card-footer">{{ $open->links() }}</div>
            @endif
        </div>

        {{-- 已裁决(近期) --}}
        <div class="card">
            <div class="card-header"><h5 class="card-header-title">{{ translate('近期已裁决(最近 20 条)') }}</h5></div>
            <div class="table-responsive">
                <table class="table table-borderless table-thead-bordered table-align-middle card-table mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('订单 / 商家') }}</th>
                            <th>{{ translate('裁决结果') }}</th>
                            <th>{{ translate('裁决理由') }}</th>
                            <th>{{ translate('裁决时间') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($resolved as $d)
                            <tr>
                                <td>{{ translate('订单') }} #{{ $d->order_id }}<br><span class="text-muted">{{ $d->restaurant->name ?? ('餐厅#' . $d->restaurant_id) }}</span></td>
                                <td>
                                    @if ($d->resolution === 'upheld')
                                        <span class="badge badge-soft-warning">{{ translate('维持退款义务') }}</span>
                                    @elseif ($d->resolution === 'closed_no_payment')
                                        <span class="badge badge-soft-secondary">{{ translate('核实未收款') }}</span>
                                    @else
                                        <span class="badge badge-soft-secondary">{{ $d->resolution }}</span>
                                    @endif
                                </td>
                                <td class="text-muted">{{ $d->operator_reason }}</td>
                                <td class="text-nowrap">{{ $d->resolved_at }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">{{ translate('暂无裁决记录。') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @include('admin-views.partials._nz-danger-confirm')
@endsection
