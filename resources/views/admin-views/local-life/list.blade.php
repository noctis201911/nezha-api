@extends('layouts.admin.app')
@section('title', '本地生活')
@push('css_or_js')
@endpush

@section('content')
<div class="content container-fluid">
    <div class="card mt-2">
        <div class="card-header py-2 border-0">
            <div class="search--button-wrapper">
                <h5 class="card-title">本地生活帖子<span class="badge badge-soft-dark ml-2">{{$posts->total()}}</span></h5>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <form id="search-form">
                        <div class="input--group input-group input-group-merge input-group-flush">
                            <input type="search" name="search" class="form-control" placeholder="按标题 / 分类搜索" value="{{ request()?->search ?? null }}">
                            <button type="submit" class="btn btn--secondary">
                                <i class="tio-search"></i>
                            </button>
                        </div>
                    </form>
                    <a href="{{ route('admin.local-life.create') }}" class="btn btn--primary">
                        <i class="tio-add-circle"></i> 新建帖子
                    </a>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive datatable-custom">
                <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                    <thead class="thead-light">
                    <tr>
                        <th style="width:5%">序号</th>
                        <th style="width:24%">标题</th>
                        <th style="width:15%">分类</th>
                        <th style="width:9%">Tab</th>
                        <th style="width:12%">价格</th>
                        <th class="text-center" style="width:9%">状态</th>
                        <th style="width:14%">创建时间</th>
                        <th class="text-center" style="width:12%">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                        @foreach($posts as $k=>$post)
                        <tr>
                            <td>{{$posts->firstItem()+$k}}</td>
                            <td>
                                @if($post->cover_emoji)<span class="mr-1">{{$post->cover_emoji}}</span>@endif
                                {{Str::limit($post->title,28,'...')}}
                                @if($post->is_urgent)<span class="badge badge-danger ml-1">急</span>@endif
                            </td>
                            <td>{{$post->category}}</td>
                            <td>{{$post->tab}}</td>
                            <td>
                                @if($post->is_free)
                                    <span class="badge badge-soft-success">免费</span>
                                @elseif($post->price_amd)
                                    {{number_format($post->price_amd)}}֏{{$post->price_suffix}}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="text-center">
                                @php $cls = ['badge-secondary','badge-success','badge-dark'][$post->status] ?? 'badge-secondary'; @endphp
                                <label class="badge {{$cls}}">{{$post->statusLabel()}}</label>
                            </td>
                            <td>{{$post->created_at}}</td>
                            <td class="text-center">
                                <div class="btn--container justify-content-center">
                                    <a title="编辑" class="btn btn-sm btn--primary btn-outline-primary action-btn" href="{{route('admin.local-life.edit',$post->id)}}">
                                        <i class="tio-edit"></i>
                                    </a>
                                    <form action="{{route('admin.local-life.status',$post->id)}}" method="post" class="d-inline">
                                        @csrf
                                        <button type="submit" title="{{$post->status==1?'转为草稿':'发布'}}"
                                            class="btn btn-sm {{$post->status==1?'btn--warning btn-outline-warning':'btn--success btn-outline-success'}} action-btn">
                                            <i class="{{$post->status==1?'tio-archive-outlined':'tio-publish'}}"></i>
                                        </button>
                                    </form>
                                    <a class="btn btn-sm btn--danger btn-outline-danger action-btn form-alert" href="javascript:"
                                        data-id="post-{{$post->id}}" data-message="确定删除这条帖子吗？" title="删除"><i class="tio-delete-outlined"></i></a>
                                    <form action="{{route('admin.local-life.delete')}}" method="post" id="post-{{$post->id}}">
                                        <input type="hidden" name="id" value="{{$post->id}}">
                                        @csrf @method('delete')
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @if(count($posts) === 0)
                <div class="empty--data">
                    <img src="{{dynamicAsset('assets/admin/img/empty.png')}}" alt="empty">
                    <h5>暂无本地生活帖子</h5>
                </div>
                @endif
            </div>
        </div>
        <div class="card-footer p-0 border-0">
            <div class="page-area px-4 pb-3">
                <div class="d-flex align-items-center justify-content-end">
                    <div>{!! $posts->links() !!}</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
