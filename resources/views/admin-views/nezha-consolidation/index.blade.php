@extends('layouts.admin.app')

@section('title', translate('平台集运申报'))

@section('content')
@php
    $freqLabel = ['weekly' => '每周', 'biweekly' => '每两周', 'monthly' => '每月', 'quarterly' => '每季度', 'irregular' => '不定期'];
    $intentLabel = ['yes' => '有意向', 'maybe' => '看价格', 'no' => '暂不'];
    $intentCls = ['yes' => 'badge-soft-success', 'maybe' => 'badge-soft-warning', 'no' => 'badge-soft-secondary'];
    $maxVol = 1.0; foreach ($catAgg as $c) { $maxVol = max($maxVol, $c['vol']); }
    $maxFreq = 1; foreach ($freqAgg as $n) { $maxFreq = max($maxFreq, $n); }
    $fmt = fn ($v) => \App\CentralLogics\Helpers::format_currency($v);
@endphp
<div class="content container-fluid">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap">
        <h2 class="page-header-title mb-0">
            <span class="page-header-icon"><i class="tio-cube"></i></span>
            <span>{{ translate('平台集运申报') }} · {{ translate('需求汇总') }}</span>
        </h2>
        <a href="{{ route('admin.nezha-consolidation.export') }}" class="btn btn-sm btn-primary"><i class="tio-download-to mr-1"></i>{{ translate('导出 CSV') }}</a>
    </div>

    {{-- KPI --}}
    <div class="row g-2 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('提交商家数') }}</h6>
                <span class="h3">{{ $kpi['total'] }}</span>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('有意向') }}</h6>
                <span class="h3">{{ $kpi['intent_yes'] }}</span> <small class="text-muted">· {{ $kpi['intent_rate'] }}%</small>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('预估月总货量') }}</h6>
                <span class="h3">{{ $kpi['vol_month'] }}</span> <small class="text-muted">m³</small>
                <div class="small text-muted mt-1">{{ translate('重量口径') }} {{ $kpi['wt_month_t'] }} t · {{ translate('箱数口径') }} {{ $kpi['box_month'] }}</div>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('覆盖品类') }}</h6>
                <span class="h3">{{ $kpi['cats'] }}</span>
            </div></div>
        </div>
    </div>

    {{-- 品类汇总 --}}
    <div class="card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">{{ translate('品类需求汇总（商家数 · 体积 · 重量）') }}</h5></div>
        <div class="card-body">
            @forelse($catAgg as $label => $c)
                <div class="d-flex align-items-center mb-2" style="gap:12px;">
                    <span style="width:130px;flex:0 0 auto;font-size:13px;">{{ $label }}</span>
                    <div style="flex:1;height:8px;background:#edeef3;border-radius:999px;overflow:hidden;">
                        <div style="width:{{ round($c['vol'] / $maxVol * 100) }}%;height:100%;background:#5b63d3;"></div>
                    </div>
                    <span style="width:165px;flex:0 0 auto;text-align:right;font-size:12px;color:#888;">{{ $c['count'] }} {{ translate('家') }} · {{ round($c['vol']) }} m³ · {{ round($c['wt'] / 1000, 1) }} t</span>
                </div>
            @empty
                <p class="text-muted mb-0">{{ translate('暂无数据') }}</p>
            @endforelse
            <p class="text-muted mt-3 mb-0" style="font-size:12px;">{{ translate('体积 / 重量按商家填报单位各自合计、箱数另计（每家只填一种）；条长按体积排。找货代谈价前再做"体积重 vs 实重取大"归一。') }}</p>
        </div>
    </div>

    {{-- 频率分布 --}}
    <div class="card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">{{ translate('进货频率分布') }}</h5></div>
        <div class="card-body">
            @foreach($freqAgg as $k => $n)
                <div class="d-flex align-items-center mb-2" style="gap:12px;">
                    <span style="width:130px;flex:0 0 auto;font-size:13px;">{{ translate($freqLabel[$k] ?? $k) }}</span>
                    <div style="flex:1;height:8px;background:#edeef3;border-radius:999px;overflow:hidden;">
                        <div style="width:{{ round($n / $maxFreq * 100) }}%;height:100%;background:#5b63d3;"></div>
                    </div>
                    <span style="width:80px;flex:0 0 auto;text-align:right;font-size:12px;color:#888;">{{ $n }} {{ translate('家') }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- 商家提交列表 --}}
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap:8px;">
            <h5 class="card-title mb-0">{{ translate('商家提交') }}（{{ $list->count() }}）</h5>
            <form method="GET" class="d-flex align-items-center" style="gap:8px;">
                <select name="intent" class="form-control form-control-sm" onchange="this.form.submit()">
                    <option value="">{{ translate('全部意向') }}</option>
                    <option value="yes" {{ $intentFilter === 'yes' ? 'selected' : '' }}>{{ translate('有意向') }}</option>
                    <option value="maybe" {{ $intentFilter === 'maybe' ? 'selected' : '' }}>{{ translate('看价格再定') }}</option>
                    <option value="no" {{ $intentFilter === 'no' ? 'selected' : '' }}>{{ translate('暂不需要') }}</option>
                </select>
                <select name="sort" class="form-control form-control-sm" onchange="this.form.submit()">
                    <option value="gmv" {{ $sort === 'gmv' ? 'selected' : '' }}>{{ translate('按成交额排序') }}</option>
                    <option value="recent" {{ $sort === 'recent' ? 'selected' : '' }}>{{ translate('按最近提交') }}</option>
                </select>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('商家') }}</th>
                            <th>{{ translate('品类 / 频率 / 货量') }}</th>
                            <th class="text-right">{{ translate('近90天平台成交') }}</th>
                            <th>{{ translate('意向') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($list as $s)
                            @php
                                $est = $s->est_vol > 0 ? ('约 ' . round($s->est_vol, 1) . ' m³/月')
                                    : ($s->est_wt > 0 ? ('约 ' . round($s->est_wt) . ' kg/月')
                                    : ($s->box_count ? ($s->box_count . ' 箱/次') : '—'));
                            @endphp
                            <tr>
                                <td>
                                    <span class="font-weight-bold">{{ $s->rname }}</span>
                                    @if($s->stale ?? false)<span class="badge badge-soft-warning ml-1" style="font-size:11px;">{{ translate('数据陈旧') }}</span>@endif
                                    <div class="small text-muted">{{ \Carbon\Carbon::parse($s->updated_at)->format('Y-m-d') }}</div>
                                </td>
                                <td><small class="text-muted">{{ implode(' · ', $s->cat_list) }} · {{ translate($freqLabel[$s->frequency] ?? ($s->frequency ?: '—')) }} · {{ $est }}</small></td>
                                <td class="text-right">{{ $fmt($s->gmv90) }}<div class="small text-muted">{{ $s->cnt90 }} {{ translate('单') }}</div></td>
                                <td><span class="badge {{ $intentCls[$s->intent] ?? 'badge-soft-secondary' }}">{{ translate($intentLabel[$s->intent] ?? $s->intent) }}</span></td>
                                <td class="text-right"><a href="{{ route('admin.nezha-consolidation.show', $s->id) }}" class="btn btn-xs btn-outline-primary">{{ translate('查看') }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center py-4 text-muted">{{ translate('暂无提交') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- A-2 未填名单: 全体商家减去已提交, 供运营定向联系。管理端内部视图, 不进任何对外物料。 --}}
    <div class="card mt-3">
        <div class="card-header">
            <h5 class="card-title mb-0">{{ translate('未提交问卷的商家') }}（{{ $unfilledList->count() }}）</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('商家') }}</th>
                            <th>{{ translate('电话') }}</th>
                            <th class="text-right">{{ translate('近90天平台成交') }}</th>
                            <th>{{ translate('状态') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($unfilledList as $r)
                            <tr>
                                <td><span class="font-weight-bold">{{ $r->name }}</span></td>
                                <td><small class="text-muted">{{ $r->phone ?: '—' }}</small></td>
                                <td class="text-right">{{ $fmt($r->gmv90) }}</td>
                                <td>
                                    @if(($r->status ?? 0) == 1)
                                        <span class="badge badge-soft-success">{{ translate('营业中') }}</span>
                                    @else
                                        <span class="badge badge-soft-secondary">{{ translate('休息中') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center py-4 text-muted">{{ translate('全部商家已提交') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
