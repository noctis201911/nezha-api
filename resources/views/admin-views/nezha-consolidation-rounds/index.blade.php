@extends('layouts.admin.app')

@section('title', translate('集运期次管理'))

@section('content')
@php
    $statusLabels = \App\CentralLogics\NezhaConsolidationRound::STATUS_LABELS;
    $unitLabels = \App\CentralLogics\NezhaConsolidationRound::UNIT_LABELS;
    $statusCls = ['draft' => 'badge-soft-secondary', 'open' => 'badge-soft-success', 'closed' => 'badge-soft-info', 'canceled' => 'badge-soft-danger'];
@endphp
<div class="content container-fluid">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap">
        <h2 class="page-header-title mb-0">
            <span class="page-header-icon"><i class="tio-box"></i></span>
            <span>{{ translate('集运期次管理') }}</span>
        </h2>
        <a href="{{ route('admin.nezha-consolidation-rounds.create') }}" class="btn btn-sm btn-primary"><i class="tio-add mr-1"></i>{{ translate('新建期次') }}</a>
    </div>

    @unless($switchOn)
        {{-- 在场感知: 总闸关时管理端仍可预建, 但商家端看不到报名入口 --}}
        <div class="alert alert-soft-warning" role="alert">
            <i class="tio-info-outined mr-1"></i>
            {{ translate('集运期次功能当前未对商家开放（总闸关闭）。你仍可在此预建期次；开放报名后，只有总闸打开、商家端才能看到并报名。') }}
        </div>
    @endunless

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">{{ translate('期次列表') }}（{{ $rows->count() }}）</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('期次') }}</th>
                            <th>{{ translate('状态') }}</th>
                            <th class="text-center">{{ translate('报名数') }}</th>
                            <th>{{ translate('成团进度') }}</th>
                            <th>{{ translate('截止时间') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $r)
                            @php $p = $r->progress; @endphp
                            <tr>
                                <td>
                                    <span class="font-weight-bold">{{ $r->title }}</span>
                                    <div class="small text-muted">{{ $r->round_no }}</div>
                                </td>
                                <td><span class="badge {{ $statusCls[$r->status] ?? 'badge-soft-secondary' }}">{{ translate($statusLabels[$r->status] ?? $r->status) }}</span></td>
                                <td class="text-center">{{ $p['enroll_count'] }}</td>
                                <td style="min-width:200px;">
                                    @if(($p['min'] ?? 0) > 0)
                                        <div class="d-flex align-items-center" style="gap:8px;">
                                            <div style="flex:1;height:8px;background:#edeef3;border-radius:999px;overflow:hidden;">
                                                <div style="width:{{ $p['pct'] }}%;height:100%;background:#5b63d3;"></div>
                                            </div>
                                            <small class="text-muted" style="white-space:nowrap;">{{ rtrim(rtrim(number_format($p['sum'], 2), '0'), '.') }} / {{ rtrim(rtrim(number_format($p['min'], 2), '0'), '.') }} {{ $p['unit_label'] }} · {{ $p['pct'] }}%</small>
                                        </div>
                                    @else
                                        <small class="text-muted">{{ translate('未设成团目标') }}</small>
                                    @endif
                                    @if(($p['other_count'] ?? 0) > 0)
                                        <div class="small text-muted">{{ translate('另有') }} {{ $p['other_count'] }} {{ translate('家以其它单位报名') }}</div>
                                    @endif
                                </td>
                                <td><small class="text-muted">{{ $r->cutoff_at ? \Carbon\Carbon::parse($r->cutoff_at)->format('Y-m-d H:i') : '—' }}</small></td>
                                <td class="text-right"><a href="{{ route('admin.nezha-consolidation-rounds.show', $r->id) }}" class="btn btn-xs btn-outline-primary">{{ translate('管理') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center py-4 text-muted">{{ translate('还没有期次，点右上角新建') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
