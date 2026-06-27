@extends('layouts.admin.app')
@section('title', '举报商家')

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">
            <span class="page-header-icon"><i class="tio-flag"></i></span>
            <span>举报商家</span>
            @if($pendingTotal > 0)
                <span class="badge badge-danger ml-1">待处理 {{ $pendingTotal }}</span>
            @endif
        </h1>
        <small class="text-muted">顾客在 H5 餐厅页提交的举报。平台仅记录与人工核实，不自动惩罚商家、不碰任何资金。</small>
    </div>

    <div class="card">
        <div class="card-header py-2 border-0 d-flex flex-wrap align-items-center justify-content-between" style="gap:.5rem;">
            <h5 class="card-title mb-0">举报记录 <span class="badge badge-soft-dark ml-1">{{ $reports->total() }}</span></h5>
            <form method="get" class="d-flex align-items-center flex-wrap" style="gap:.5rem;">
                <select name="status" class="form-control form-control-sm" style="width:auto;" onchange="this.form.submit()">
                    <option value="" {{ ($statusFilter === null || $statusFilter === '') ? 'selected' : '' }}>全部状态</option>
                    <option value="0" {{ ((string) $statusFilter === '0') ? 'selected' : '' }}>待处理</option>
                    <option value="1" {{ ((string) $statusFilter === '1') ? 'selected' : '' }}>已处理</option>
                    <option value="2" {{ ((string) $statusFilter === '2') ? 'selected' : '' }}>已驳回</option>
                </select>
                <input type="search" name="search" value="{{ $search }}" class="form-control form-control-sm" style="width:auto;" placeholder="理由 / 说明 / 餐厅ID">
                <button class="btn btn-sm btn--primary" type="submit">搜索</button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table">
                <thead class="thead-light">
                    <tr>
                        <th>#</th>
                        <th>被举报商家</th>
                        <th>理由</th>
                        <th>补充说明</th>
                        <th>举报人</th>
                        <th>时间</th>
                        <th>状态</th>
                        <th class="text-center">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($reports as $r)
                        <tr>
                            <td>{{ $r->id }}</td>
                            <td>
                                <span class="d-block font-weight-bold">{{ optional($r->restaurant)->name ?? '—' }}</span>
                                <small class="text-muted">餐厅ID: {{ $r->restaurant_id }}@if($r->vendor_id) · 商家ID: {{ $r->vendor_id }}@endif</small>
                            </td>
                            <td><span class="badge badge-soft-warning">{{ $r->reason }}</span></td>
                            <td style="max-width:260px;white-space:normal;word-break:break-word;">{{ $r->description ?: '—' }}</td>
                            <td>
                                @if($r->user_id)
                                    <small>登录用户 #{{ $r->user_id }}</small>
                                @elseif($r->guest_id)
                                    <small class="text-muted">游客 {{ \Illuminate\Support\Str::limit($r->guest_id, 6, '…') }}</small>
                                @else
                                    <small class="text-muted">—</small>
                                @endif
                            </td>
                            <td><small>{{ $r->created_at }}</small></td>
                            <td>
                                @if($r->status == \App\Models\RestaurantReport::STATUS_PENDING)
                                    <span class="badge badge-soft-danger">待处理</span>
                                @elseif($r->status == \App\Models\RestaurantReport::STATUS_HANDLED)
                                    <span class="badge badge-soft-success">已处理</span>
                                @else
                                    <span class="badge badge-soft-secondary">已驳回</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center" style="gap:.35rem;">
                                    @if($r->status != \App\Models\RestaurantReport::STATUS_HANDLED)
                                        <form method="post" action="{{ route('admin.restaurant-report.status', $r->id) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="1">
                                            <button class="btn btn-sm btn--primary" type="submit">标记已处理</button>
                                        </form>
                                    @endif
                                    @if($r->status != \App\Models\RestaurantReport::STATUS_REJECTED)
                                        <form method="post" action="{{ route('admin.restaurant-report.status', $r->id) }}">
                                            @csrf
                                            <input type="hidden" name="status" value="2">
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">驳回</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">暂无举报记录</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2">
            {!! $reports->links() !!}
        </div>
    </div>
</div>
@endsection
