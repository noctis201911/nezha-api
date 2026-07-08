@extends('layouts.admin.app')
@section('title', '商家举报处理')
@push('css_or_js')
@endpush

@section('content')
<div class="content container-fluid">

    {{-- 被举报商家概要 --}}
    <div class="card mt-2">
        <div class="card-body py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h5 class="mb-1">
                    {{ $merchant->name }}
                    @php $mcls = $merchant->status ? 'badge-success' : 'badge-secondary'; @endphp
                    <label class="badge {{ $mcls }} ml-1">{{ $merchant->status ? '上线中' : '已隐藏' }}</label>
                    @if($merchant->is_sensitive)<label class="badge badge-warning ml-1">敏感类目</label>@endif
                </h5>
                <small class="text-muted">{{ $merchant->category }} · {{ $merchant->area ?: '—' }} · 商家 ID {{ $merchant->id }}</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="{{ route('admin.local-life.merchants.list') }}" class="btn btn--secondary btn-sm">返回商家列表</a>
                @if($merchant->status)
                    <a class="btn btn--danger btn-sm form-alert" href="javascript:"
                       data-id="resolve-merchant-{{ $merchant->id }}"
                       data-message="确定隐藏该商家吗？隐藏后顾客端将不再展示，并把该商家所有待处理举报标记为已处理。">
                        <i class="tio-hidden"></i> 隐藏该商家
                    </a>
                    <form action="{{ route('admin.local-life.merchants.resolve-reports', $merchant->id) }}" method="post" id="resolve-merchant-{{ $merchant->id }}" class="d-none">
                        @csrf
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="card mt-2">
        <div class="card-header py-2 border-0">
            <h5 class="card-title">举报记录<span class="badge badge-soft-dark ml-2">{{ $reports->total() }}</span></h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive datatable-custom">
                <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                    <thead class="thead-light">
                    <tr>
                        <th style="width:5%">序号</th>
                        <th style="width:20%">举报理由</th>
                        <th style="width:33%">补充说明</th>
                        <th style="width:12%">举报人ID</th>
                        <th style="width:15%">时间</th>
                        <th class="text-center" style="width:8%">状态</th>
                        <th style="width:7%">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                        @foreach($reports as $k=>$r)
                        <tr>
                            <td>{{ $reports->firstItem()+$k }}</td>
                            <td>{{ $r->reason }}</td>
                            <td style="white-space:normal;">{{ $r->detail ?: '—' }}</td>
                            <td>{{ $r->user_id ?? '—' }}</td>
                            <td>{{ $r->created_at }}</td>
                            <td class="text-center">
                                @php $rcls = ['badge-warning','badge-success','badge-secondary'][$r->status] ?? 'badge-warning'; @endphp
                                <label class="badge {{ $rcls }}">{{ $r->statusLabel() }}</label>
                            </td>
                            <td>
                                @if($r->status == 0)
                                    <form action="{{ route('admin.local-life.report-dismiss', $r->id) }}" method="post" class="d-inline">
                                        @csrf
                                        <button type="submit" title="驳回该举报（商家保留）" class="btn btn-sm btn--secondary btn-outline-secondary">
                                            驳回
                                        </button>
                                    </form>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @if(count($reports) === 0)
                <div class="empty--data">
                    <img src="{{ dynamicAsset('assets/admin/img/empty.png') }}" alt="empty">
                    <h5>该商家暂无举报</h5>
                </div>
                @endif
            </div>
        </div>
        <div class="card-footer p-0 border-0">
            <div class="page-area px-4 pb-3">
                <div class="d-flex align-items-center justify-content-end">
                    <div>{!! $reports->links() !!}</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
