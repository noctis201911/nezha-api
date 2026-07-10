@extends('layouts.admin.app')
@section('title', '笔记举报处理')
@section('content')
<div class="content container-fluid">

    {{-- 被举报笔记概要 --}}
    <div class="card mt-2">
        <div class="card-body py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h5 class="mb-1">
                    {{ $note->title ?: '（无标题笔记）' }}
                    @php $cls = ['badge-warning','badge-success','badge-danger','badge-dark'][$note->status] ?? 'badge-secondary'; @endphp
                    <label class="badge {{$cls}} ml-1">{{$note->statusLabel()}}</label>
                </h5>
                <small class="text-muted">{{ optional($note->merchant)->name }} · 笔记 ID {{$note->id}} · {{ $note->author_type === 'merchant' ? '商家发布' : '客户发布' }}</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="{{ route('admin.local-life.notes.list') }}" class="btn btn--secondary btn-sm">返回列表</a>
                @if($note->status == 1)
                    <a class="btn btn--danger btn-sm form-alert" href="javascript:"
                       data-id="offline-note-{{$note->id}}"
                       data-message="确定下架该笔记吗？下架后前台将不再展示，并把该笔记所有待处理举报标记为已处理。">
                        <i class="tio-archive"></i> 下架该笔记
                    </a>
                    <form action="{{ route('admin.local-life.notes.offline', $note->id) }}" method="post" id="offline-note-{{$note->id}}" class="d-none">
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
                                    <form action="{{ route('admin.local-life.notes.report-dismiss', $r->id) }}" method="post" class="d-inline">
                                        @csrf
                                        <button type="submit" title="驳回该举报（笔记保留）" class="btn btn-sm btn--secondary btn-outline-secondary">驳回</button>
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
                    <h5>该笔记暂无举报</h5>
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
