@extends('layouts.admin.app')
@section('title', translate('风控审核队列'))
@section('content')
    <div class="content container-fluid">
        <div class="page-header d-flex flex-wrap justify-content-between align-items-center">
            <h1 class="page-header-title">
                <i class="tio-warning"></i> {{ translate('风控审核队列') }}
                @if ($pending_count > 0)
                    <span class="badge badge-danger">{{ $pending_count }}</span>
                @endif
            </h1>
            <div>
                <a href="{{ route('admin.nezha-risk.logs') }}" class="btn btn-outline-secondary btn-sm">{{ translate('风控日志') }}</a>
                <a href="{{ route('admin.nezha-risk.settings') }}" class="btn btn-outline-primary btn-sm">{{ translate('风控设置') }}</a>
            </div>
        </div>

        <div class="alert alert-info" role="alert">
            <i class="tio-info"></i>
            {{ translate('以下订单命中风控规则, 已暂拦付款(顾客未拿到商家收款码)。请核实后处置: 放行后该顾客可在宽限期内重新下单付款; 退款仅允许原路退回; 清退则不予放行。') }}
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>{{ translate('时间') }}</th>
                                <th>{{ translate('顾客') }}</th>
                                <th>{{ translate('餐馆') }}</th>
                                <th>{{ translate('通道') }}</th>
                                <th class="text-right">{{ translate('金额') }}</th>
                                <th>{{ translate('命中规则') }}</th>
                                <th style="min-width:340px">{{ translate('处置') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($records as $r)
                                <tr>
                                    <td>{{ $r->id }}</td>
                                    <td>{{ $r->created_at?->format('m-d H:i') }}</td>
                                    <td>
                                        @if ($r->user)
                                            {{ trim(($r->user->f_name ?? '') . ' ' . ($r->user->l_name ?? '')) }}<br>
                                            <small class="text-muted">{{ $r->user->phone ?? $r->user->email }}</small>
                                        @elseif ($r->guest_id)
                                            <small class="text-muted">{{ translate('游客') }} {{ $r->guest_id }}</small>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>{{ $r->restaurant->name ?? ('#' . $r->restaurant_id) }}</td>
                                    <td>
                                        @if ($r->payment_channel == 'usdt')
                                            <span class="badge badge-soft-warning">USDT</span>
                                        @elseif ($r->payment_channel == 'rmb')
                                            <span class="badge badge-soft-info">{{ translate('人民币') }}</span>
                                        @else
                                            {{ $r->payment_channel }}
                                        @endif
                                    </td>
                                    <td class="text-right">{{ \App\CentralLogics\Helpers::format_currency($r->order_amount) }}</td>
                                    <td>
                                        @foreach (($r->hit_rules ?? []) as $h)
                                            <span class="badge badge-soft-danger d-block mb-1" title="{{ $h['detail'] ?? '' }}">{{ $h['rule'] ?? '' }}</span>
                                        @endforeach
                                    </td>
                                    <td>
                                        <form method="post">
                                            @csrf
                                            <input type="text" name="note" class="form-control form-control-sm mb-1" placeholder="{{ translate('处置备注(选填)') }}">
                                            <button formaction="{{ route('admin.nezha-risk.approve', $r->id) }}" class="btn btn-sm btn-success mr-1" onclick="return confirm('{{ translate('确认放行该顾客?') }}')">{{ translate('放行') }}</button>
                                            <button formaction="{{ route('admin.nezha-risk.refund', $r->id) }}" class="btn btn-sm btn-info mr-1" onclick="return confirm('{{ translate('记录原路退款指令?') }}')">{{ translate('退款(原路)') }}</button>
                                            <button formaction="{{ route('admin.nezha-risk.reject', $r->id) }}" class="btn btn-sm btn-danger" onclick="return confirm('{{ translate('确认清退该订单?') }}')">{{ translate('清退') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">{{ translate('暂无待审订单') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-3">{{ $records->links() }}</div>
    </div>
@endsection
