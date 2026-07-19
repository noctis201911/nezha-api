@extends('layouts.admin.app')
@section('title', '本地生活商家')

@section('content')
<div class="content container-fluid">

    <div class="card mt-2">
        <div class="card-body py-3">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h5 class="mb-1">本地生活商家管理</h5>
                    <small class="text-muted">
                        商家=服务型商户（移民/签证/美容美发/按摩/包车出行/本地旅游…）。顾客在本地生活点对应类目→进入商家列表→商家店铺页。
                        <span class="text-danger">纯信息展示，平台不碰钱、不接预订下单。</span>
                    </small>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <a href="{{ route('admin.local-life.list') }}" class="btn btn--secondary">
                        <i class="tio-chevron-left"></i> 返回帖子列表
                    </a>
                    <a href="{{ route('admin.local-life.merchants.create') }}" class="btn btn--primary">
                        <i class="tio-add-circle"></i> 新建商家
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-2">
        <div class="card-header py-2">
            <form action="{{ route('admin.local-life.merchants.list') }}" method="get" class="d-flex align-items-center gap-2 flex-wrap w-100">
                <select name="category" class="form-control" style="max-width:200px" onchange="this.form.submit()">
                    <option value="">全部类目</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->name }}" {{ request('category') === $c->name ? 'selected' : '' }}>{{ $c->emoji }} {{ $c->name }}</option>
                    @endforeach
                </select>
                <input type="text" name="search" class="form-control" style="max-width:240px" placeholder="搜商家名 / 区域" value="{{ request('search') }}">
                <button type="submit" class="btn btn--primary">搜索</button>
                @if(request('category') || request('search'))
                    <a href="{{ route('admin.local-life.merchants.list') }}" class="btn btn--reset">重置</a>
                @endif
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive datatable-custom">
                <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                    <thead class="thead-light">
                    <tr>
                        <th style="width:6%">排序</th>
                        <th style="width:8%">Logo</th>
                        <th style="width:18%">商家名</th>
                        <th style="width:13%">类目</th>
                        <th style="width:9%">评分</th>
                        <th class="text-center" style="width:9%" title="近30天顾客点击联系的意图次数（上界口径，非独立用户数）">近30天咨询</th>
                        <th style="width:10%">区域</th>
                        <th class="text-center" style="width:7%">敏感</th>
                        <th class="text-center" style="width:7%">状态</th>
                        <th style="width:10%">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                        @forelse($merchants as $m)
                        @php $rc = ($reportCounts[$m->id] ?? 0); @endphp
                        <tr>
                            <td>{{ $m->sort_order }}</td>
                            <td>
                                @if($m->logo)
                                    <img src="{{ \App\CentralLogics\Helpers::get_full_url('local-life-merchant', $m->logo, 'public') }}" style="height:40px;width:40px;object-fit:cover;border-radius:9px;">
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <strong>{{ $m->name }}</strong>
                                @if($rc > 0)
                                    <a href="{{ route('admin.local-life.merchants.reports', $m->id) }}" class="badge badge-danger ml-1" title="有 {{ $rc }} 条待处理举报">举报 {{ $rc }}</a>
                                @endif
                            </td>
                            <td><span class="badge badge-soft-secondary">{{ $m->category }}</span></td>
                            <td>★ {{ number_format($m->rating, 1) }}@if($m->google_rating) <small class="text-muted">G {{ number_format($m->google_rating,1) }}</small>@endif</td>
                            <td class="text-center">{{ $contact30[$m->id] ?? 0 }}</td>
                            <td>{{ $m->area ?: '—' }}</td>
                            <td class="text-center">
                                @if($m->is_sensitive)
                                    <span class="badge badge-warning"><i class="tio-warning"></i></span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <form action="{{ route('admin.local-life.merchants.status', $m->id) }}" method="post" class="d-inline">
                                    @csrf
                                    <label class="toggle-switch toggle-switch-sm mb-0" title="点击切换上线/隐藏">
                                        <input type="checkbox" class="toggle-switch-input" onchange="this.form.submit()" {{ $m->status ? 'checked' : '' }}>
                                        <span class="toggle-switch-label"><span class="toggle-switch-indicator"></span></span>
                                    </label>
                                </form>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('admin.local-life.merchants.reports', $m->id) }}" class="btn btn-sm {{ $rc > 0 ? 'btn-outline-danger' : 'btn-outline-secondary' }}" title="举报记录"><i class="tio-flag"></i></a>
                                    <a href="{{ route('admin.local-life.merchants.edit', $m->id) }}" class="btn btn-sm btn-outline-primary" title="编辑"><i class="tio-edit"></i></a>
                                    <form action="{{ route('admin.local-life.merchants.delete') }}" method="post"
                                          onsubmit="return confirm('确定删除商家「{{ $m->name }}」？');">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="id" value="{{ $m->id }}">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="删除"><i class="tio-delete"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">还没有商家，点右上角「新建商家」添加</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($merchants->hasPages())
        <div class="card-footer">
            {!! $merchants->appends(request()->query())->links() !!}
        </div>
        @endif
    </div>
</div>
@endsection
