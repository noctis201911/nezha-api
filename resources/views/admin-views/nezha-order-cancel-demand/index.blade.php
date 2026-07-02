@extends('layouts.admin.app')

@section('title', translate('顾客取消理由'))

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h2 class="page-header-title">
            <span class="page-header-icon"><i class="tio-clear-circle"></i></span>
            <span>{{ translate('顾客取消理由') }}</span>
        </h2>
        <p class="text-muted mb-0">{{ translate('顾客取消订单时选的理由(按下单时间统计)。「未接单自助取消」= 顾客在商家接单前自己取消;「接单后申请获准」= 顾客接单后申请、商家同意的取消。补充说明含顾客自由输入, 默认打码, 仅超级管理员可见完整内容。') }}</p>
    </div>

    @php
        $mergeq = function (array $override) { return array_merge(request()->except(['page']), $override); };
        $pathBadge = function ($st) {
            if ($st === 'approved') return ['接单后申请获准', 'badge-soft-warning'];
            return ['未接单自助取消', 'badge-soft-info'];
        };
    @endphp

    {{-- 汇总卡 --}}
    <div class="row g-2 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('同期总订单') }}<small>（{{ translate('按下单时间') }}）</small></h6>
                <span class="h3">{{ number_format($summary['total_orders']) }}</span>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('顾客取消数') }}</h6>
                <span class="h3 {{ $summary['canceled'] > 0 ? 'text-danger' : '' }}">{{ number_format($summary['canceled']) }}</span>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('取消率') }}</h6>
                <span class="h3 {{ $summary['cancel_rate'] >= 10 ? 'text-danger' : '' }}">{{ $summary['cancel_rate'] }}%</span>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('路径拆分') }}</h6>
                <div class="d-flex flex-column">
                    <small><span class="badge badge-soft-info mr-1">{{ translate('未接单自助') }}</span>{{ number_format($summary['self']) }}</small>
                    <small class="mt-1"><span class="badge badge-soft-warning mr-1">{{ translate('接单后获准') }}</span>{{ number_format($summary['approved']) }}</small>
                    @if($hasReq)
                        <small class="mt-1 text-muted">{{ translate('想取消被拒/待处理') }}: {{ number_format($summary['attempted']) }}</small>
                    @endif
                </div>
            </div></div>
        </div>
    </div>

    {{-- 过滤 + 导出 --}}
    <div class="card mb-3">
        <div class="card-body py-2">
            <form action="{{ route('admin.nezha-order-cancel-demand.index') }}" method="GET" class="d-flex flex-wrap gap-1 align-items-center">
                <select name="restaurant" class="form-control form-control-sm" style="width:auto">
                    <option value="all" {{ (string) $rid === 'all' ? 'selected' : '' }}>{{ translate('全部店铺') }}</option>
                    @foreach($restaurants as $rs)
                        <option value="{{ $rs->id }}" {{ (string) $rid === (string) $rs->id ? 'selected' : '' }}>{{ $rs->name }}</option>
                    @endforeach
                </select>
                <select name="days" class="form-control form-control-sm" style="width:auto">
                    <option value="0" {{ $days === 0 ? 'selected' : '' }}>{{ translate('全部时间') }}</option>
                    <option value="7" {{ $days === 7 ? 'selected' : '' }}>{{ translate('近7天') }}</option>
                    <option value="30" {{ $days === 30 ? 'selected' : '' }}>{{ translate('近30天') }}</option>
                    <option value="90" {{ $days === 90 ? 'selected' : '' }}>{{ translate('近90天') }}</option>
                </select>
                <input type="search" name="search" value="{{ $search }}" class="form-control form-control-sm" style="width:160px" placeholder="{{ translate('理由关键词') }}">
                <button class="btn btn-sm btn-primary">{{ translate('messages.filter') }}</button>
                <a href="{{ route('admin.nezha-order-cancel-demand.export', request()->all()) }}" class="btn btn-sm btn-outline-secondary"><i class="tio-download-to mr-1"></i>{{ translate('导出CSV') }}</a>
            </form>
        </div>
    </div>

    <div class="row">
        {{-- 理由分布 --}}
        <div class="col-lg-6 mb-3">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0">{{ translate('取消理由分布') }}</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>{{ translate('理由') }}</th>
                                    <th class="text-right">{{ translate('次数') }}</th>
                                    <th style="width:40%">{{ translate('占比') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($dist as $d)
                                    @php $pct = $summary['canceled'] > 0 ? round($d->c / $summary['canceled'] * 100) : 0; @endphp
                                    <tr>
                                        <td class="font-weight-bold">{{ $d->reason }}</td>
                                        <td class="text-right">{{ number_format($d->c) }}</td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1" style="height:8px">
                                                    <div class="progress-bar bg-danger" style="width: {{ $pct }}%"></div>
                                                </div>
                                                <small class="ml-2 text-muted">{{ $pct }}%</small>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center py-4 text-muted">{{ translate('暂无取消数据') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- 按店铺 --}}
        <div class="col-lg-6 mb-3">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0">{{ translate('取消最多的店铺 (Top 10)') }}</h5></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>{{ translate('店铺') }}</th>
                                    <th class="text-right">{{ translate('顾客取消数') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($byRestaurant as $i => $b)
                                    <tr>
                                        <td class="text-muted">{{ $i + 1 }}</td>
                                        <td>{{ $rnameMap[$b->restaurant_id] ?? ('#' . $b->restaurant_id) }}</td>
                                        <td class="text-right font-weight-bold">{{ number_format($b->c) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-center py-4 text-muted">{{ translate('暂无取消数据') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 明细 --}}
    <div class="card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">{{ translate('取消明细') }}</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>#</th>
                            <th>{{ translate('订单号') }}</th>
                            <th>{{ translate('店铺') }}</th>
                            <th>{{ translate('理由') }}</th>
                            <th>{{ translate('补充说明') }}</th>
                            <th>{{ translate('路径') }}</th>
                            <th>{{ translate('取消时间') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($detail as $i => $r)
                            @php [$plabel, $pclass] = $pathBadge($hasReq ? ($r->nezha_cancel_request ?? '') : ''); @endphp
                            <tr>
                                <td class="text-muted">{{ $detail->firstItem() + $i }}</td>
                                <td><a href="{{ route('admin.order.details', ['id' => $r->id]) }}" class="font-weight-bold">#{{ $r->id }}</a></td>
                                <td><small>{{ $rnameMap[$r->restaurant_id] ?? ('#' . $r->restaurant_id) }}</small></td>
                                <td>{{ $r->cancellation_reason ?: translate('(未填理由)') }}</td>
                                <td>
                                    <small class="text-muted">
                                        @if($r->cancellation_note)
                                            {{ $isSuper ? $r->cancellation_note : '••• (' . translate('超管可见') . ')' }}
                                        @else
                                            —
                                        @endif
                                    </small>
                                </td>
                                <td><span class="badge {{ $pclass }}">{{ translate($plabel) }}</span></td>
                                <td><small>{{ $r->canceled ? \Carbon\Carbon::parse($r->canceled)->format('Y-m-d H:i') : '—' }}</small></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center py-4 text-muted">{{ translate('暂无取消数据') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3 d-flex justify-content-end">{!! $detail->links() !!}</div>
        </div>
    </div>

    {{-- 副面板: 想取消被拒/待处理 --}}
    @if($hasReq)
    <div class="card mb-3">
        <div class="card-header">
            <h5 class="card-title mb-0">{{ translate('想取消但没取成 (被拒 / 待处理)') }}</h5>
            <small class="text-muted">{{ translate('顾客接单后申请取消, 商家拒绝继续履约或尚未裁决 — 履约摩擦信号') }}</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('申请理由') }}</th>
                            <th>{{ translate('状态') }}</th>
                            <th class="text-right">{{ translate('次数') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($attemptedRows as $a)
                            <tr>
                                <td class="font-weight-bold">{{ $a->reason }}</td>
                                <td>
                                    @if($a->st === 'rejected')
                                        <span class="badge badge-soft-danger">{{ translate('商家已拒绝') }}</span>
                                    @else
                                        <span class="badge badge-soft-secondary">{{ translate('待商家裁决') }}</span>
                                    @endif
                                </td>
                                <td class="text-right">{{ number_format($a->c) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center py-4 text-muted">{{ translate('暂无数据') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif
</div>
@endsection
