@extends('layouts.admin.app')
@section('title', '本地生活 · 笔记审核')
@section('content')
<div class="content container-fluid">
@php $noteImg = fn($f) => \App\CentralLogics\Helpers::get_full_url('local-life-note', $f, 'public'); @endphp

    {{-- 笔记展示总闸（真实影响开关，默认关） --}}
    <div class="card mt-2">
        <div class="card-body py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h5 class="mb-1">笔记展示（商家页）
                    @if($notesEnabled)
                        <span class="badge badge-soft-success ml-1">已开放</span>
                    @else
                        <span class="badge badge-soft-secondary ml-1">未开放</span>
                    @endif
                </h5>
                <small class="text-muted">开放后，过审笔记在 H5 商家页「笔记」卡展示；商家（/m）与客户可提交，均进本审核队列。关闭时前台整卡隐藏，本审核台不受影响。</small>
            </div>
            <form action="{{ route('admin.local-life.notes.toggle') }}" method="post">
                @csrf
                <input type="hidden" name="enable" value="{{ $notesEnabled ? 0 : 1 }}">
                <button type="submit" class="btn {{ $notesEnabled ? 'btn--danger' : 'btn--primary' }}">
                    {{ $notesEnabled ? '关闭笔记展示' : '开放笔记展示' }}
                </button>
            </form>
        </div>
    </div>

    <div class="card mt-2">
        <div class="card-header py-2 border-0">
            <div class="search--button-wrapper">
                <h5 class="card-title">笔记<span class="badge badge-soft-dark ml-2">{{$notes->total()}}</span>
                    @if($pendingCount > 0)
                        <span class="badge badge-warning ml-1">待审核 {{$pendingCount}}</span>
                    @endif
                    @if(!empty($reportPendingTotal) && $reportPendingTotal > 0)
                        <span class="badge badge-danger ml-1"><i class="tio-flag"></i> 待处理举报 {{$reportPendingTotal}}</span>
                    @endif
                </h5>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <form id="search-form">
                        <input type="hidden" name="status" value="{{ $statusFilter }}">
                        <div class="input--group input-group input-group-merge input-group-flush">
                            <input type="search" name="search" class="form-control" placeholder="按标题 / 正文搜索" value="{{ request()?->search ?? null }}">
                            <button type="submit" class="btn btn--secondary"><i class="tio-search"></i></button>
                        </div>
                    </form>
                    <a href="{{ route('admin.local-life.list') }}" class="btn btn--secondary"><i class="tio-chevron-left"></i> 返回帖子</a>
                </div>
            </div>

            {{-- 状态筛选 --}}
            <div class="mt-2 d-flex flex-wrap gap-1">
                @php $filters = ['' => '全部', '0' => '待审核', '1' => '已展示', '2' => '已驳回', '3' => '已下架']; @endphp
                @foreach($filters as $val => $label)
                    <a href="{{ route('admin.local-life.notes.list', array_filter(['status' => $val, 'search' => $search])) }}"
                       class="btn btn-sm {{ (string)$statusFilter === (string)$val ? 'btn--primary' : 'btn-outline-secondary' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive datatable-custom">
                <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                    <thead class="thead-light">
                    <tr>
                        <th style="width:5%">序号</th>
                        <th style="width:16%">商家</th>
                        <th style="width:12%">作者</th>
                        <th style="width:33%">笔记</th>
                        <th class="text-center" style="width:9%">状态</th>
                        <th style="width:25%">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                        @foreach($notes as $k=>$note)
                        @php $rc = $reportCounts[$note->id] ?? 0; @endphp
                        <tr>
                            <td>{{$notes->firstItem()+$k}}</td>
                            <td>
                                {{ optional($note->merchant)->name ?: '（商家已删除）' }}
                                @if(optional($note->merchant)->category)<div><small class="text-muted">{{$note->merchant->category}}</small></div>@endif
                            </td>
                            <td>
                                @if($note->author_type === 'merchant')
                                    <span class="badge badge-soft-primary">商家</span>
                                @else
                                    <span class="badge badge-soft-info">客户</span>
                                    <div><small class="text-muted">{{ optional($note->user)->f_name ? trim($note->user->f_name.' '.$note->user->l_name) : ($note->user_id ? '用户已注销' : '') }}</small></div>
                                @endif
                            </td>
                            <td style="white-space:normal">
                                <a href="javascript:" data-toggle="modal" data-target="#note-view-{{$note->id}}" class="text-dark" style="text-decoration:none" title="点击查看完整笔记">
                                    @if($note->title)<strong>{{Str::limit($note->title,30)}}</strong><br>@endif
                                    <span class="text-muted" style="font-size:12.5px">{{Str::limit($note->body,80)}}</span>
                                </a>
                                @if(is_array($note->images) && count($note->images))
                                    <div class="d-flex flex-wrap gap-1 mt-1">
                                        @foreach(array_slice($note->images,0,5) as $im)
                                            <a href="javascript:" data-toggle="modal" data-target="#note-view-{{$note->id}}"><img src="{{$noteImg($im)}}" alt="图" style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid #eee"></a>
                                        @endforeach
                                        @if(count($note->images) > 5)<span class="text-muted" style="font-size:12px;align-self:center">+{{count($note->images)-5}}</span>@endif
                                    </div>
                                @endif
                                <div class="mt-1"><a href="javascript:" data-toggle="modal" data-target="#note-view-{{$note->id}}" class="small text-primary"><i class="tio-visible-outlined"></i> 查看完整笔记</a></div>
                                @if($rc > 0)
                                    <a href="{{route('admin.local-life.notes.reports',$note->id)}}" class="badge badge-danger mt-1" title="查看举报"><i class="tio-flag"></i> 举报 {{$rc}}</a>
                                @endif
                                @if($note->status == 2 && $note->reject_reason)
                                    <div><small class="text-danger">驳回理由：{{Str::limit($note->reject_reason,40)}}</small></div>
                                @endif
                            </td>
                            <td class="text-center">
                                @php $cls = ['badge-warning','badge-success','badge-danger','badge-dark'][$note->status] ?? 'badge-secondary'; @endphp
                                <label class="badge {{$cls}}">{{$note->statusLabel()}}</label>
                            </td>
                            <td>
                                <div class="btn--container">
                                    @if($note->status == 0)
                                        <form action="{{route('admin.local-life.notes.approve',$note->id)}}" method="post" class="d-inline">
                                            @csrf
                                            <button type="submit" title="通过并展示" class="btn btn-sm btn-success"><i class="tio-checkmark-circle"></i> 通过</button>
                                        </form>
                                        <a class="btn btn-sm btn-outline-warning" href="javascript:" title="驳回"
                                            onclick="document.getElementById('note-reject-{{$note->id}}').classList.toggle('d-none')"><i class="tio-clear-circle"></i> 驳回</a>
                                    @endif
                                    @if($note->status == 1)
                                        <form action="{{route('admin.local-life.notes.offline',$note->id)}}" method="post" class="d-inline"
                                            onsubmit="return confirm('下架该笔记？前台将不再展示。')">
                                            @csrf
                                            <button type="submit" title="下架" class="btn btn-sm btn-outline-warning"><i class="tio-archive-outlined"></i> 下架</button>
                                        </form>
                                    @endif
                                    @if($rc > 0)
                                        <a title="查看举报 ({{$rc}})" class="btn btn-sm btn-outline-danger" href="{{route('admin.local-life.notes.reports',$note->id)}}"><i class="tio-flag"></i> 举报 {{$rc}}</a>
                                    @endif
                                    <a class="btn btn-sm btn-outline-danger action-btn form-alert" href="javascript:"
                                        data-id="note-{{$note->id}}" data-message="确定删除这条笔记吗？" title="删除"><i class="tio-delete-outlined"></i></a>
                                    <form action="{{route('admin.local-life.notes.delete')}}" method="post" id="note-{{$note->id}}">
                                        <input type="hidden" name="id" value="{{$note->id}}">
                                        @csrf @method('delete')
                                    </form>
                                </div>
                                @if($note->status == 0)
                                    <form action="{{route('admin.local-life.notes.reject',$note->id)}}" method="post" id="note-reject-{{$note->id}}" class="d-none mt-2">
                                        @csrf
                                        <div class="input-group input-group-sm">
                                            <input type="text" name="reject_reason" class="form-control" maxlength="255" placeholder="驳回理由（可选，商家在 /m 可见）">
                                            <button type="submit" class="btn btn--warning">确认驳回</button>
                                        </div>
                                    </form>
                                @endif

                                {{-- 笔记完整预览模态框：审核前看清全部标题/正文/图片，避免盲审 --}}
                                <div class="modal fade text-left" id="note-view-{{$note->id}}" tabindex="-1" role="dialog" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">笔记预览
                                                    @if($note->author_type === 'merchant')
                                                        <span class="badge badge-soft-primary ml-1">商家</span>
                                                    @else
                                                        <span class="badge badge-soft-info ml-1">客户</span>
                                                    @endif
                                                </h5>
                                                <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="text-muted small mb-3">
                                                    商家：{{ optional($note->merchant)->name ?: '（已删除）' }}
                                                    · 作者：{{ $note->author_type === 'merchant' ? (optional($note->merchant)->name ?: '商家') : (optional($note->user)->f_name ? trim($note->user->f_name.' '.$note->user->l_name) : ($note->user_id ? '用户已注销' : '匿名')) }}
                                                    · 提交：{{ optional($note->created_at)->timezone('Asia/Yerevan')->format('Y-m-d H:i') }}
                                                </div>
                                                @if($note->title)<h4 class="mb-3">{{ $note->title }}</h4>@endif
                                                <div style="white-space:pre-wrap;line-height:1.75;font-size:14px">{{ $note->body }}</div>
                                                @if(is_array($note->images) && count($note->images))
                                                    <div class="d-flex flex-wrap gap-2 mt-3">
                                                        @foreach($note->images as $im)
                                                            <a href="{{$noteImg($im)}}" target="_blank" title="点击看原图">
                                                                <img src="{{$noteImg($im)}}" alt="图" style="width:128px;height:128px;object-fit:cover;border-radius:8px;border:1px solid #eee">
                                                            </a>
                                                        @endforeach
                                                    </div>
                                                @endif
                                                @if($note->status == 2 && $note->reject_reason)
                                                    <div class="alert alert-soft-danger py-2 small mt-3 mb-0">驳回理由：{{ $note->reject_reason }}</div>
                                                @endif
                                            </div>
                                            <div class="modal-footer">
                                                @if($note->status == 0)
                                                    <form action="{{route('admin.local-life.notes.approve',$note->id)}}" method="post" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-success"><i class="tio-checkmark-circle"></i> 通过并展示</button>
                                                    </form>
                                                @endif
                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">关闭</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @if(count($notes) === 0)
                <div class="empty--data">
                    <img src="{{dynamicAsset('assets/admin/img/empty.png')}}" alt="empty">
                    <h5>暂无笔记</h5>
                </div>
                @endif
            </div>
            <div class="page-area px-3 py-2">
                {!! $notes->links() !!}
            </div>
        </div>
    </div>
</div>
@endsection
