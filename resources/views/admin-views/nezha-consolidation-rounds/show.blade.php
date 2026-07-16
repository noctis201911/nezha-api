@extends('layouts.admin.app')

@section('title', translate('集运期次详情'))

@section('content')
@php
    $statusLabels = \App\CentralLogics\NezhaConsolidationRound::STATUS_LABELS;
    $unitLabels = \App\CentralLogics\NezhaConsolidationRound::UNIT_LABELS;
    $feeNote = \App\CentralLogics\NezhaConsolidationRound::FEE_NOTE;
    $foodHint = \App\CentralLogics\NezhaConsolidationRound::FOOD_HINT;
    $statusCls = ['draft' => 'badge-soft-secondary', 'open' => 'badge-soft-success', 'closed' => 'badge-soft-info', 'canceled' => 'badge-soft-danger'];
    $foodLabelSet = array_column(array_filter(\App\CentralLogics\NezhaConsolidationRound::CATEGORIES, fn ($c) => !empty($c['is_food'])), 'label');
    $dash = fn ($v) => ($v === null || $v === '') ? '—' : $v;
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $anyFood = $enrollments->contains(fn ($e) => $e->has_food);
    // 取消二次确认文案(有报名者时)
    $cancelMsg = translate('取消后商家端会看到「已取消」。确认取消该期次？');
    if ($progress['enroll_count'] > 0) {
        $cancelMsg = translate('该期次已有') . ' ' . $progress['enroll_count'] . ' ' . translate('家报名。') . $cancelMsg;
    }
@endphp
<div class="content container-fluid">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap">
        <h2 class="page-header-title mb-0">
            {{ $round->title }}
            <span class="badge {{ $statusCls[$round->status] ?? 'badge-soft-secondary' }} ml-2">{{ translate($statusLabels[$round->status] ?? $round->status) }}</span>
            <div class="small text-muted mt-1">{{ $round->round_no }}</div>
        </h2>
        <a href="{{ route('admin.nezha-consolidation-rounds.index') }}" class="btn btn-sm btn-outline-secondary">{{ translate('返回列表') }}</a>
    </div>

    {{-- 状态操作 + 导出 --}}
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap align-items-center" style="gap:8px;">
            @if($round->status === 'draft')
                <form action="{{ route('admin.nezha-consolidation-rounds.open', $round->id) }}" method="post" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success"><i class="tio-checkmark-circle mr-1"></i>{{ translate('开放报名') }}</button>
                </form>
            @elseif($round->status === 'open')
                <form action="{{ route('admin.nezha-consolidation-rounds.close', $round->id) }}" method="post" class="d-inline"
                    onsubmit="return confirm('{{ translate('截止后商家不能再报名，确认截止本期？') }}')">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-warning"><i class="tio-time mr-1"></i>{{ translate('截止报名') }}</button>
                </form>
            @endif

            @if(in_array($round->status, ['draft', 'open'], true))
                <a href="{{ route('admin.nezha-consolidation-rounds.edit', $round->id) }}" class="btn btn-sm btn-outline-primary"><i class="tio-edit mr-1"></i>{{ translate('编辑') }}</a>
            @endif

            <a href="{{ route('admin.nezha-consolidation-rounds.export', $round->id) }}" class="btn btn-sm btn-outline-secondary"><i class="tio-download-to mr-1"></i>{{ translate('导出货代清单（脱敏）') }}</a>
            <a href="{{ route('admin.nezha-consolidation-rounds.export', $round->id) }}?names=1" class="btn btn-sm btn-outline-secondary">{{ translate('含真名导出') }}</a>

            @if($round->status !== 'canceled')
                <form action="{{ route('admin.nezha-consolidation-rounds.cancel', $round->id) }}" method="post" class="d-inline ml-auto"
                    onsubmit="return confirm('{{ $cancelMsg }}')">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="tio-clear-circle mr-1"></i>{{ translate('取消期次') }}</button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-2">
        {{-- 期次信息 --}}
        <div class="col-lg-6">
            <div class="card mb-3 h-100">
                <div class="card-header"><h5 class="card-title mb-0">{{ translate('期次信息') }}</h5></div>
                <div class="card-body p-0">
                    <table class="table table-borderless table-thead-bordered table-align-middle mb-0">
                        <tbody>
                            <tr><td class="text-muted" style="width:150px;">{{ translate('报名截止') }}</td><td>{{ $round->cutoff_at ? \Carbon\Carbon::parse($round->cutoff_at)->format('Y-m-d H:i') : '—' }}</td></tr>
                            <tr><td class="text-muted">{{ translate('预计发出 (ETD)') }}</td><td>{{ $round->etd ? \Carbon\Carbon::parse($round->etd)->format('Y-m-d') : '—' }}</td></tr>
                            <tr><td class="text-muted">{{ translate('预计到达 (ETA)') }}</td><td>{{ $round->eta ? \Carbon\Carbon::parse($round->eta)->format('Y-m-d') : '—' }}</td></tr>
                            <tr><td class="text-muted">{{ translate('成团目标') }}</td><td>{{ ($round->min_volume_value !== null && $round->min_volume_value !== '') ? ($num($round->min_volume_value) . ' ' . ($unitLabels[$round->min_volume_unit] ?? $round->min_volume_unit)) : translate('未设') }}</td></tr>
                            @if($round->notes)
                                <tr><td class="text-muted">{{ translate('备注') }}</td><td>{{ $round->notes }}</td></tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- 报价与货代 (只展示) --}}
        <div class="col-lg-6">
            <div class="card mb-3 h-100">
                <div class="card-header"><h5 class="card-title mb-0">{{ translate('报价与货代') }}</h5></div>
                <div class="card-body p-0">
                    <table class="table table-borderless table-thead-bordered table-align-middle mb-0">
                        <tbody>
                            <tr><td class="text-muted" style="width:150px;">{{ translate('单价') }}</td><td>{{ $dash($pricing['unit_price'] ?? '') }}</td></tr>
                            <tr><td class="text-muted">{{ translate('时效') }}</td><td>{{ $dash($pricing['lead_time'] ?? '') }}</td></tr>
                            <tr><td class="text-muted">{{ translate('申报方式') }}</td><td>{{ $dash($pricing['declare_method'] ?? '') }}</td></tr>
                            <tr><td class="text-muted">{{ translate('货代') }}</td><td>{{ $dash($forwarder['name'] ?? '') }}<span class="text-muted">{{ !empty($pricing['forwarder_contact']) ? ' · ' . $pricing['forwarder_contact'] : '' }}</span></td></tr>
                        </tbody>
                    </table>
                    {{-- 费用说明: 逐字 FEE_NOTE, 无任何代收/免费承诺 --}}
                    <div class="card-footer"><small class="text-muted">{{ $feeNote }}</small></div>
                </div>
            </div>
        </div>
    </div>

    {{-- 成团进度 (只同单位统计) --}}
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">{{ translate('成团进度') }}</h5>
            <span class="text-muted small">{{ translate('已报名') }} {{ $progress['enroll_count'] }} {{ translate('家') }}</span>
        </div>
        <div class="card-body">
            @if(($progress['min'] ?? 0) > 0)
                <div class="d-flex align-items-center mb-2" style="gap:12px;">
                    <div style="flex:1;height:12px;background:#edeef3;border-radius:999px;overflow:hidden;">
                        <div style="width:{{ $progress['pct'] }}%;height:100%;background:#5b63d3;"></div>
                    </div>
                    <span style="flex:0 0 auto;font-size:13px;white-space:nowrap;">{{ $num($progress['sum']) }} / {{ $num($progress['min']) }} {{ $progress['unit_label'] }} · <strong>{{ $progress['pct'] }}%</strong></span>
                </div>
            @else
                <p class="mb-2">{{ translate('未设成团目标') }} · {{ translate('累计') }} {{ $num($progress['sum']) }} {{ $progress['unit_label'] }}</p>
            @endif
            @if(($progress['other_count'] ?? 0) > 0)
                <p class="text-muted small mb-0">{{ translate('另有') }} {{ $progress['other_count'] }} {{ translate('家以其它单位报名（不并入本进度，避免跨单位换算）') }}</p>
            @endif
        </div>
    </div>

    {{-- 报名明细 (admin 内部视图, 显真名) --}}
    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0">{{ translate('报名明细') }}（{{ $enrollments->count() }}）</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('商家') }}</th>
                            <th>{{ translate('预估货量') }}</th>
                            <th>{{ translate('品类') }}</th>
                            <th>{{ translate('报名时间') }}</th>
                            <th>{{ translate('状态') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($enrollments as $e)
                            <tr>
                                <td>
                                    <span class="font-weight-bold">{{ $nameMap[$e->restaurant_id] ?? ('商家#' . ($e->restaurant_id ?: $e->vendor_id)) }}</span>
                                    @if($e->note)<div class="small text-muted">{{ $e->note }}</div>@endif
                                </td>
                                <td>{{ ($e->est_volume_value !== null && $e->est_volume_value !== '') ? ($num($e->est_volume_value) . ' ' . ($unitLabels[$e->est_volume_unit] ?? $e->est_volume_unit)) : '—' }}</td>
                                <td>
                                    <small>
                                        @forelse($e->cat_labels as $lbl)
                                            <span>{{ $lbl }}@if(in_array($lbl, $foodLabelSet, true))<sup class="text-warning" title="{{ $foodHint }}">*</sup>@endif</span>@if(!$loop->last)<span class="text-muted"> · </span>@endif
                                        @empty
                                            <span class="text-muted">—</span>
                                        @endforelse
                                    </small>
                                </td>
                                <td><small class="text-muted">{{ $e->created_at ? \Carbon\Carbon::parse($e->created_at)->format('Y-m-d H:i') : '—' }}</small></td>
                                <td>
                                    @if($e->status === 'enrolled')
                                        <span class="badge badge-soft-success">{{ translate('已报名') }}</span>
                                    @else
                                        <span class="badge badge-soft-secondary">{{ translate('已取消') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center py-4 text-muted">{{ translate('暂无报名') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($anyFood)
                <div class="card-footer"><small class="text-muted"><sup class="text-warning">*</sup> {{ $foodHint }}</small></div>
            @endif
        </div>
    </div>
</div>
@endsection
