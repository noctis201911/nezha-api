@extends('layouts.admin.app')
@section('title', translate('逾期未退款'))
@section('content')
    <div class="content container-fluid">
        <div class="page-header">
            <h1 class="page-header-title"><i class="tio-time"></i> {{ translate('逾期未退款') }}</h1>
        </div>

        <div class="alert alert-warning" role="alert">
            <i class="tio-info"></i>
            {{ translate('哪吒为点对点直付、平台不碰钱: 平台已取消/退款的订单, 退款须由商家在自己账户原路退还顾客。此页列出商家「待退款、尚未标记已退款」的留痕。逾期') }}
            {{ $remindDays }}{{ translate('天起系统自动催办商家+计入风控; 逾期') }}{{ $suspendDays }}{{ translate('天起升级告警。') }}
            <strong>{{ translate('停接单为非资金约束、需由您在此手动执行(留人工复核口子, 避免误伤已退款但忘标记的商家)。平台绝不从保证金扣钱代退。') }}</strong>
            @if ($status != 1)
                <br><span class="text-danger">{{ translate('注意: 兜底总开关当前关闭(nezha_refund_overdue_status=0), 系统不会自动催办/记风控/告警; 本列表仍可查看与手动处置。') }}</span>
            @endif
        </div>

        {{-- 当前被「退款逾期」停接单的商家 --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="card-header-title">{{ translate('当前因退款逾期被暂停接单的商家') }} ({{ count($suspended) }})</h5></div>
            <div class="table-responsive">
                <table class="table table-borderless table-thead-bordered table-align-middle card-table mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('商家') }}</th>
                            <th>{{ translate('原因') }}</th>
                            <th>{{ translate('暂停时间') }}</th>
                            <th>{{ translate('操作') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($suspended as $s)
                            <tr>
                                <td>{{ $s->name }} <span class="text-muted">#{{ $s->id }}</span></td>
                                <td class="text-muted">{{ $s->nezha_suspend_reason }}</td>
                                <td>{{ $s->nezha_suspended_at }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.nezha-refund.overdue.unsuspend', $s->id) }}" onsubmit="return confirm('{{ translate('确定解除该商家接单暂停吗?') }}');">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success">{{ translate('解除接单暂停') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">{{ translate('当前没有因退款逾期被暂停接单的商家。') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 待退款留痕列表 --}}
        <div class="card">
            <div class="card-header"><h5 class="card-header-title">{{ translate('待商家原路退款的留痕(按生成时间升序, 越久越靠前)') }}</h5></div>
            <div class="table-responsive">
                <table class="table table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('ID') }}</th>
                            <th>{{ translate('订单 / 商家') }}</th>
                            <th>{{ translate('应退金额') }}</th>
                            <th>{{ translate('生成时间') }}</th>
                            <th>{{ translate('逾期') }}</th>
                            <th>{{ translate('操作') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($records as $rec)
                            @php
                                $overdueDays = $rec->created_at ? (int) floor($rec->created_at->diffInSeconds(\Carbon\Carbon::now()) / 86400) : 0;
                                $isSuspended = $rec->restaurant && (int) ($rec->restaurant->nezha_order_suspended ?? 0) === 1;
                            @endphp
                            <tr>
                                <td>#{{ $rec->id }}</td>
                                <td>
                                    {{ translate('订单') }} #{{ $rec->order_id }}<br>
                                    <span class="text-muted">{{ $rec->restaurant->name ?? ('餐厅#' . $rec->restaurant_id) }}</span>
                                </td>
                                <td>{{ \App\CentralLogics\Helpers::format_currency($rec->refund_amount) }}</td>
                                <td>{{ $rec->created_at }}</td>
                                <td>
                                    @if ($overdueDays >= $suspendDays)
                                        <span class="badge badge-soft-danger">{{ $overdueDays }} {{ translate('天') }}</span>
                                    @elseif ($overdueDays >= $remindDays)
                                        <span class="badge badge-soft-warning">{{ $overdueDays }} {{ translate('天') }}</span>
                                    @else
                                        <span class="badge badge-soft-secondary">{{ $overdueDays }} {{ translate('天') }}</span>
                                    @endif
                                </td>
                                <td style="min-width:260px;">
                                    @if (!$isSuspended)
                                        <form method="POST" action="{{ route('admin.nezha-refund.overdue.suspend', $rec->id) }}" class="d-inline" onsubmit="return confirm('{{ translate('确定暂停该商家接单吗? 商家标记退款或您手动解除后恢复。') }}');">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ translate('停接单') }}</button>
                                        </form>
                                    @else
                                        <span class="badge badge-soft-danger mr-1">{{ translate('已停接单') }}</span>
                                    @endif
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#resolveModal{{ $rec->id }}">{{ translate('人工核实已退款') }}</button>

                                    <div class="modal fade" id="resolveModal{{ $rec->id }}" tabindex="-1" role="dialog" aria-hidden="true">
                                        <div class="modal-dialog" role="document"><div class="modal-content">
                                            <form method="POST" action="{{ route('admin.nezha-refund.overdue.resolve', $rec->id) }}">
                                                @csrf
                                                <div class="modal-header"><h5 class="modal-title">{{ translate('人工核实已退款') }} #{{ $rec->id }}</h5>
                                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
                                                <div class="modal-body">
                                                    <p class="text-muted">{{ translate('用于商家已实际原路退款但忘了在后台标记的情况。确认后该留痕转为「已退款」, 并自动解除该商家接单暂停(若无其它逾期留痕)。') }}</p>
                                                    <div class="form-group">
                                                        <label>{{ translate('核实备注') }}</label>
                                                        <input type="text" name="note" class="form-control" placeholder="{{ translate('如: 已与商家核对, 退款凭证已收到') }}" maxlength="255">
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ translate('取消') }}</button>
                                                    <button type="submit" class="btn btn-primary">{{ translate('确认已退款') }}</button>
                                                </div>
                                            </form>
                                        </div></div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">{{ translate('暂无待商家退款的逾期留痕。') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $records->links() }}</div>
        </div>
    </div>
@endsection
