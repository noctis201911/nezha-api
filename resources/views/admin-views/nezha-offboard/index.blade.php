@extends('layouts.admin.app')
@section('title', translate('商家退出结算'))
@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title"><i class="tio-logout"></i> {{ translate('商家退出结算') }}</h1>
        <p class="text-muted mb-0">{{ translate('商家申请退出后在此审批与放款。审批时系统实时做制裁名单核验, 并须逐字核对收款户名; 净额为负不放款、走人工追缴。') }}</p>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h5 class="card-title mb-0">{{ translate('进行中') }}</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-borderless table-thead-bordered table-align-middle">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('工单') }}</th>
                            <th>{{ translate('商家') }}</th>
                            <th>{{ translate('状态') }}</th>
                            <th class="text-right">{{ translate('净额(已审批)') }}</th>
                            <th>{{ translate('待核实纠纷') }}</th>
                            <th>{{ translate('申请时间') }}</th>
                            <th>{{ translate('操作') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($active as $s)
                        <tr>
                            <td>#{{ $s->id }}</td>
                            <td>{{ $names[$s->restaurant_id] ?? ('#'.$s->restaurant_id) }}</td>
                            <td>@include('admin-views.nezha-offboard._status', ['st' => $s->status])</td>
                            <td class="text-right">{{ $s->status === 'approved' ? \App\CentralLogics\Helpers::format_currency($s->net_amount) : '—' }}</td>
                            <td>
                                @if(($disputeFlags[$s->id] ?? 0) > 0)
                                    <span class="badge badge-soft-danger">{{ $disputeFlags[$s->id] }} {{ translate('条') }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td><small>{{ $s->applied_at ? \Carbon\Carbon::parse($s->applied_at)->format('Y-m-d') : '—' }}</small></td>
                            <td><a href="{{ route('admin.nezha-offboard.show', $s->id) }}" class="btn btn-sm btn-outline-primary">{{ translate('查看/审批') }}</a></td>
                        </tr>
                        @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">{{ translate('暂无进行中的退出申请') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0">{{ translate('近期已结') }}</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-borderless table-thead-bordered table-align-middle">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('工单') }}</th>
                            <th>{{ translate('商家') }}</th>
                            <th>{{ translate('结果') }}</th>
                            <th class="text-right">{{ translate('净额') }}</th>
                            <th>{{ translate('时间') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recent as $s)
                        <tr>
                            <td>#{{ $s->id }}</td>
                            <td>{{ $names[$s->restaurant_id] ?? ('#'.$s->restaurant_id) }}</td>
                            <td>@include('admin-views.nezha-offboard._status', ['st' => $s->status])</td>
                            <td class="text-right">{{ \App\CentralLogics\Helpers::format_currency($s->net_amount ?? 0) }}</td>
                            <td><small>{{ \Carbon\Carbon::parse($s->updated_at)->format('Y-m-d H:i') }}</small></td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">{{ translate('暂无记录') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
