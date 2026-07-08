@extends('layouts.admin.app')
@section('title', '生活攻略')

@section('content')
<div class="content container-fluid">

    <div class="card mt-2">
        <div class="card-body py-3">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h5 class="mb-1">生活攻略管理</h5>
                    <small class="text-muted">
                        平台整理的落地/租房/换汇/中餐/居留等攻略长文（PGC）。顾客在本地生活页「生活攻略」入口进入。
                        <span class="text-danger">纯信息展示，标注时效，过期宁下架。</span>
                        总开关 <code>nezha_guides_status</code>（默认关）另控整块可见性。
                    </small>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <a href="{{ route('admin.guides.create') }}" class="btn btn--primary">
                        <i class="tio-add-circle"></i> 新建攻略
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-2">
        <div class="card-header py-2">
            <form action="{{ route('admin.guides.list') }}" method="get" class="d-flex align-items-center gap-2 flex-wrap w-100">
                <input type="text" name="search" class="form-control" style="max-width:280px" placeholder="搜标题 / slug" value="{{ request('search') }}">
                <button type="submit" class="btn btn--primary">搜索</button>
                @if(request('search'))
                    <a href="{{ route('admin.guides.list') }}" class="btn btn--reset">重置</a>
                @endif
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive datatable-custom">
                <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                    <thead class="thead-light">
                    <tr>
                        <th style="width:6%">排序</th>
                        <th style="width:8%">封面</th>
                        <th style="width:30%">标题</th>
                        <th style="width:16%">信息截至</th>
                        <th class="text-center" style="width:8%">有用</th>
                        <th class="text-center" style="width:8%">敏感</th>
                        <th class="text-center" style="width:8%">状态</th>
                        <th style="width:10%">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                        @forelse($guides as $g)
                        <tr>
                            <td>{{ $g->sort }}</td>
                            <td>
                                @if($g->cover_url)
                                    <img src="{{ \App\CentralLogics\Helpers::get_full_url('guide', $g->cover_url, 'public') }}" style="height:40px;width:56px;object-fit:cover;border-radius:8px;">
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <strong>{{ $g->title }}</strong>
                                <div class="text-muted" style="font-size:11px;">/{{ $g->slug }}</div>
                            </td>
                            <td>
                                <span class="{{ $g->isStale() ? '' : 'text-muted' }}">{{ $g->info_as_of }}</span>
                                @if($g->isStale())
                                    <span class="badge badge-warning ml-1" title="信息截至已超 180 天，建议更新或下架"><i class="tio-warning"></i> 过期</span>
                                @endif
                            </td>
                            <td class="text-center">{{ $g->helpful_count }}</td>
                            <td class="text-center">
                                @if($g->is_sensitive_topic)
                                    <span class="badge badge-soft-warning" title="level1 话题（签证/居留/移民），文末专用免责">敏感</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <form action="{{ route('admin.guides.status', $g->id) }}" method="post" class="d-inline">
                                    @csrf
                                    <label class="toggle-switch toggle-switch-sm mb-0" title="点击切换上架/下架">
                                        <input type="checkbox" class="toggle-switch-input" onchange="this.form.submit()" {{ $g->status == 1 ? 'checked' : '' }}>
                                        <span class="toggle-switch-label"><span class="toggle-switch-indicator"></span></span>
                                    </label>
                                </form>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('admin.guides.edit', $g->id) }}" class="btn btn-sm btn-outline-primary" title="编辑"><i class="tio-edit"></i></a>
                                    <form action="{{ route('admin.guides.delete') }}" method="post"
                                          onsubmit="return confirm('确定删除攻略「{{ $g->title }}」？');">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="id" value="{{ $g->id }}">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="删除"><i class="tio-delete"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">还没有攻略，点右上角「新建攻略」添加</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($guides->hasPages())
        <div class="card-footer">
            {!! $guides->appends(request()->query())->links() !!}
        </div>
        @endif
    </div>
</div>
@endsection
