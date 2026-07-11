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
            {{ $remindHours }}{{ translate('小时起系统自动催办商家+计入风控; 逾期') }}{{ $suspendHours }}{{ translate('小时起【系统自动停接单】并 Telegram 提醒超管。') }}
            <strong>{{ translate('自动停接单为非资金约束; 商家原路退款并标记后系统自动恢复其接单。您仍可在下方手动解除/维持(留人工复核口子, 避免误伤已退款但忘标记的商家)。平台绝不从保证金扣钱代退。') }}</strong>
            @if ($status != 1)
                <br><span class="text-danger">{{ translate('注意: 兜底总开关当前关闭(nezha_refund_overdue_status=0), 系统不会自动催办/记风控/告警; 本列表仍可查看与手动处置。') }}</span>
            @endif
        </div>

        {{-- 阈值/总开关 可视化设置 --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="card-header-title">{{ translate('逾期未退款 · 阈值与总开关设置') }}</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.nezha-refund.overdue.settings') }}"
                    data-nz-danger="strong"
                    data-nz-title="{{ translate('保存逾期未退款设置') }}"
                    data-nz-consequence="{{ translate('开启后：系统每小时自动扫描逾期未退款订单、自动催办商家、计入风控并告警——这是对商家的真实约束。') }}"
                    data-nz-impact="{{ translate('影响全部逾期未退款订单的商家；停接单方式按上方选择（手动/自动）。') }}"
                    data-nz-rollback="{{ translate('回本页把「兜底总开关」改回「关闭」并保存即停止。') }}"
                    data-nz-confirm="{{ translate('确认保存并生效') }}">
                    @csrf
                    <div class="row align-items-end">
                        <div class="col-sm-3 form-group mb-2">
                            <label class="input-label">{{ translate('兜底总开关') }}</label>
                            <select name="nezha_refund_overdue_status" class="form-control">
                                <option value="0" {{ $status != 1 ? 'selected' : '' }}>{{ translate('关闭(只看与手动处置, 不自动催办)') }}</option>
                                <option value="1" {{ $status == 1 ? 'selected' : '' }}>{{ translate('开启(每小时自动催办+记风控+告警)') }}</option>
                            </select>
                            <small class="text-danger">{{ translate('⚠️ 开启=真实影响: 自动催办逾期商家、计入风控、告警。停接单方式见右(默认手动)。') }}</small>
                        </div>
                        <div class="col-sm-3 form-group mb-2">
                            <label class="input-label">{{ translate('停接单方式') }}</label>
                            <select name="nezha_refund_overdue_auto_suspend" class="form-control">
                                <option value="0" {{ (int) ($autoSuspend ?? 0) !== 1 ? 'selected' : '' }}>{{ translate('手动(推荐): 仅告警建议, 由您手动停接单') }}</option>
                                <option value="1" {{ (int) ($autoSuspend ?? 0) === 1 ? 'selected' : '' }}>{{ translate('自动: 逾期达阈值系统自动停(退款后自愈)') }}</option>
                            </select>
                        </div>
                        <div class="col-sm-2 form-group mb-2">
                            <label class="input-label">{{ translate('逾期几小时 催办') }}</label>
                            <input type="number" name="nezha_refund_overdue_remind_hours" class="form-control" min="1" max="720" value="{{ $remindHours }}" required>
                        </div>
                        <div class="col-sm-2 form-group mb-2">
                            <label class="input-label">{{ translate('逾期几小时 停接单') }}</label>
                            <input type="number" name="nezha_refund_overdue_suspend_hours" class="form-control" min="1" max="2160" value="{{ $suspendHours }}" required>
                        </div>
                        <div class="col-sm-2 form-group mb-2">
                            <button type="submit" class="btn btn-primary btn-block">{{ translate('保存') }}</button>
                        </div>
                    </div>
                    <small class="text-muted">{{ translate('自动停接单小时数应≥催办小时数; 系统每小时自动扫描一次。') }}</small>
                </form>
            </div>
        </div>

        {{-- 当前被「退款逾期」停接单的商家 --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="card-header-title">{{ translate('当前因退款逾期被停接单的商家(系统自动或运营手动)') }} ({{ count($suspended) }})</h5></div>
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

        {{-- 哪吒 当前被「自动下线」(长期不确认订单)停接单的商家。与退款逾期挂起独立(各用各列), 各自恢复。 --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="card-header-title">{{ translate('当前因长期不确认订单被自动停接单的商家') }} ({{ count($autoOffline ?? []) }})</h5></div>
            <div class="table-responsive">
                <table class="table table-borderless table-thead-bordered table-align-middle card-table mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('商家') }}</th>
                            <th>{{ translate('原因') }}</th>
                            <th>{{ translate('下线时间') }}</th>
                            <th>{{ translate('操作') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse (($autoOffline ?? []) as $a)
                            <tr>
                                <td>{{ $a->name }} <span class="text-muted">#{{ $a->id }}</span></td>
                                <td class="text-muted">{{ $a->nezha_auto_offline_reason }}</td>
                                <td>{{ $a->nezha_auto_offline_at }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.nezha-refund.overdue.autooffline-recover', $a->id) }}" onsubmit="return confirm('{{ translate('确定恢复该商家接单吗?') }}');">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success">{{ translate('恢复接单') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">{{ translate('当前没有因长期不确认订单被自动停接单的商家。') }}</td></tr>
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
                                $overdueSince = $rec->overdue_since; // 锚点优先, 回退生成时刻(争议维持裁决后从裁决时刻起算)
                                $overdueHours = $overdueSince ? (int) floor($overdueSince->diffInSeconds(\Carbon\Carbon::now()) / 3600) : 0;
                                $overdueLabel = \App\CentralLogics\NezhaRefundOverdue::humanizeHours($overdueHours);
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
                                    @if ($overdueHours >= $suspendHours)
                                        <span class="badge badge-soft-danger">{{ $overdueLabel }}</span>
                                    @elseif ($overdueHours >= $remindHours)
                                        <span class="badge badge-soft-warning">{{ $overdueLabel }}</span>
                                    @else
                                        <span class="badge badge-soft-secondary">{{ $overdueLabel }}</span>
                                    @endif
                                    @if ($rec->overdue_anchor_at)
                                        <br><small class="text-muted">{{ translate('争议裁决后重算') }} · {{ $rec->overdue_anchor_at }}</small>
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
    {{-- 哪吒M3: 逾期未退款设置保存=强确认(后果+影响面+回滚) --}}
    @include('admin-views.partials._nz-danger-confirm')
@endsection
