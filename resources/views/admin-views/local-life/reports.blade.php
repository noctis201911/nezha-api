@extends('layouts.admin.app')
@section('title', '举报处理')
@push('css_or_js')
@endpush

@section('content')
<div class="content container-fluid">

    {{-- 被举报帖概要 --}}
    <div class="card mt-2">
        <div class="card-body py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h5 class="mb-1">
                    @if($post->cover_emoji)<span class="mr-1">{{$post->cover_emoji}}</span>@endif
                    {{$post->title}}
                    @php $cls = ['badge-secondary','badge-success','badge-dark','badge-warning','badge-danger'][$post->status] ?? 'badge-secondary'; @endphp
                    <label class="badge {{$cls}} ml-1">{{$post->statusLabel()}}</label>
                </h5>
                <small class="text-muted">{{$post->category}} · {{$post->tab}} · 帖子 ID {{$post->id}}</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="{{ route('admin.local-life.list') }}" class="btn btn--secondary btn-sm">返回列表</a>
                @if($post->status == 1)
                    <a class="btn btn--danger btn-sm form-alert" href="javascript:"
                       data-id="offline-post-{{$post->id}}"
                       data-message="确定下线该帖吗？下线后顾客端将不再展示，并把该帖所有待处理举报标记为已处理。">
                        <i class="tio-archive"></i> 下线该帖
                    </a>
                    <form action="{{ route('admin.local-life.offline', $post->id) }}" method="post" id="offline-post-{{$post->id}}" class="d-none">
                        @csrf
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="card mt-2">
        <div class="card-header py-2 border-0">
            <h5 class="card-title">举报记录<span class="badge badge-soft-dark ml-2">{{$reports->total()}}</span></h5>
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
                            <td>{{$reports->firstItem()+$k}}</td>
                            <td>{{$r->reason}}</td>
                            <td style="white-space:normal;">{{ $r->detail ?: '—' }}</td>
                            <td>{{ $r->user_id ?? '—' }}</td>
                            <td>{{ $r->created_at }}</td>
                            <td class="text-center">
                                @php $rcls = ['badge-warning','badge-success','badge-secondary'][$r->status] ?? 'badge-warning'; @endphp
                                <label class="badge {{$rcls}}">{{$r->statusLabel()}}</label>
                            </td>
                            <td>
                                @if($r->status == 0)
                                    <form action="{{ route('admin.local-life.report-dismiss', $r->id) }}" method="post" class="d-inline">
                                        @csrf
                                        <button type="submit" title="驳回该举报（帖子保留）" class="btn btn-sm btn--secondary btn-outline-secondary">
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
                    <img src="{{dynamicAsset('assets/admin/img/empty.png')}}" alt="empty">
                    <h5>该帖暂无举报</h5>
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
