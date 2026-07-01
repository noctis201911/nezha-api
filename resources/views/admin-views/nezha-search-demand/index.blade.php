@extends('layouts.admin.app')

@section('title', translate('搜索需求'))

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h2 class="page-header-title">
            <span class="page-header-icon"><i class="tio-search"></i></span>
            <span>{{ translate('搜索需求') }}</span>
        </h2>
        <p class="text-muted mb-0">{{ translate('顾客在搜索框里搜了什么(匿名聚合, 已抹除隐私)。「热门搜索」看大家最想要什么;「搜了没结果」= 平台还没有、但顾客在找的东西 = 未被满足的需求。') }}</p>
    </div>

    @if(!($summary['switch_on'] ?? true))
        <div class="alert alert-warning py-2">{{ translate('注意: 全量搜索采集总开关(nezha_search_log_status)当前已关闭, 新的搜索不再记录(历史数据仍可查)。') }}</div>
    @endif

    @php
        $mergeq = function (array $override) { return array_merge(request()->except(['page']), $override); };
        $zoneMap = $zones->pluck('name', 'id');
        $typeLabel = ['product' => translate('商品'), 'restaurant' => translate('餐厅')];
    @endphp

    {{-- 汇总 --}}
    <div class="row g-2 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('搜索词数') }}<small>（{{ translate('去重') }}）</small></h6>
                <span class="h3">{{ number_format($summary['distinct_terms']) }}</span>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('总搜索次数') }}</h6>
                <span class="h3">{{ number_format($summary['total_hits']) }}</span>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('搜了没结果的词') }}</h6>
                <span class="h3 {{ $summary['zero_terms'] > 0 ? 'text-danger' : '' }}">{{ number_format($summary['zero_terms']) }}</span>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('没结果总次数') }}</h6>
                <span class="h3 {{ $summary['zero_hits'] > 0 ? 'text-danger' : '' }}">{{ number_format($summary['zero_hits']) }}</span>
            </div></div>
        </div>
    </div>

    {{-- 视图切换 热门/没结果 --}}
    <div class="btn-group mb-3" role="group">
        <a href="{{ route('admin.nezha-search-demand.index', $mergeq(['view' => 'hot'])) }}"
           class="btn btn-sm {{ $view === 'hot' ? 'btn-primary' : 'btn-outline-primary' }}">{{ translate('热门搜索') }}</a>
        <a href="{{ route('admin.nezha-search-demand.index', $mergeq(['view' => 'miss'])) }}"
           class="btn btn-sm {{ $view === 'miss' ? 'btn-danger' : 'btn-outline-danger' }}">{{ translate('搜了没结果') }}</a>
    </div>

    <div class="card">
        <div class="card-header flex-between flex-wrap gap-2">
            <h5 class="card-title mb-0">
                {{ $view === 'miss' ? translate('搜了没结果(按无结果次数)') : translate('热门搜索(按搜索次数)') }}
            </h5>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <form action="{{ route('admin.nezha-search-demand.index') }}" method="GET" class="d-flex flex-wrap gap-1 align-items-center">
                    <input type="hidden" name="view" value="{{ $view }}">
                    <select name="type" class="form-control form-control-sm" style="width:auto">
                        <option value="all" {{ $type === 'all' ? 'selected' : '' }}>{{ translate('全部类型') }}</option>
                        <option value="product" {{ $type === 'product' ? 'selected' : '' }}>{{ translate('商品') }}</option>
                        <option value="restaurant" {{ $type === 'restaurant' ? 'selected' : '' }}>{{ translate('餐厅') }}</option>
                    </select>
                    <select name="zone" class="form-control form-control-sm" style="width:auto">
                        <option value="all" {{ $zone === 'all' ? 'selected' : '' }}>{{ translate('全部区域') }}</option>
                        @foreach($zones as $z)
                            <option value="{{ $z->id }}" {{ (string) $zone === (string) $z->id ? 'selected' : '' }}>{{ $z->name }}</option>
                        @endforeach
                    </select>
                    <select name="days" class="form-control form-control-sm" style="width:auto">
                        <option value="0" {{ $days === 0 ? 'selected' : '' }}>{{ translate('全部时间') }}</option>
                        <option value="7" {{ $days === 7 ? 'selected' : '' }}>{{ translate('近7天') }}</option>
                        <option value="30" {{ $days === 30 ? 'selected' : '' }}>{{ translate('近30天') }}</option>
                        <option value="90" {{ $days === 90 ? 'selected' : '' }}>{{ translate('近90天') }}</option>
                    </select>
                    <input type="search" name="search" value="{{ $search }}" class="form-control form-control-sm" style="width:140px" placeholder="{{ translate('关键词') }}">
                    <button class="btn btn-sm btn-primary">{{ translate('messages.filter') }}</button>
                </form>
                <a href="{{ route('admin.nezha-search-demand.export', request()->all()) }}" class="btn btn-sm btn-outline-secondary"><i class="tio-download-to mr-1"></i>{{ translate('导出CSV') }}</a>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>#</th>
                            <th>{{ translate('关键词') }}</th>
                            <th>{{ translate('类型') }}</th>
                            <th>{{ translate('区域') }}</th>
                            <th class="text-right">{{ translate('搜索次数') }}</th>
                            <th class="text-right">{{ translate('无结果次数') }}</th>
                            <th class="text-right">{{ translate('无结果率') }}</th>
                            <th>{{ translate('最近搜索') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($terms as $i => $r)
                            @php
                                $rate = $r->hit_count > 0 ? round($r->zero_result_count / $r->hit_count * 100) : 0;
                            @endphp
                            <tr>
                                <td class="text-muted">{{ $terms->firstItem() + $i }}</td>
                                <td><span class="font-weight-bold">{{ $r->keyword }}</span></td>
                                <td><span class="badge badge-soft-secondary">{{ $typeLabel[$r->search_type] ?? $r->search_type }}</span></td>
                                <td><small class="text-muted">{{ $r->zone_id > 0 ? ($zoneMap[$r->zone_id] ?? ('Zone ' . $r->zone_id)) : translate('未知') }}</small></td>
                                <td class="text-right font-weight-bold">{{ number_format($r->hit_count) }}</td>
                                <td class="text-right {{ $r->zero_result_count > 0 ? 'text-danger' : 'text-muted' }}">{{ number_format($r->zero_result_count) }}</td>
                                <td class="text-right">
                                    @if($r->zero_result_count > 0)
                                        <span class="badge {{ $rate >= 50 ? 'badge-soft-danger' : 'badge-soft-warning' }}">{{ $rate }}%</span>
                                    @else
                                        <span class="text-muted">0%</span>
                                    @endif
                                </td>
                                <td><small>{{ $r->last_seen_at ? \Carbon\Carbon::parse($r->last_seen_at)->format('Y-m-d H:i') : '—' }}</small></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center py-4 text-muted">{{ translate('暂无搜索数据') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3 d-flex justify-content-end">{!! $terms->links() !!}</div>
        </div>
    </div>
</div>
@endsection
