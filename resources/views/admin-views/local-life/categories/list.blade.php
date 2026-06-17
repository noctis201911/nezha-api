@extends('layouts.admin.app')
@section('title', '本地生活类目')

@section('content')
<div class="content container-fluid">

    <div class="card mt-2">
        <div class="card-body py-3">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h5 class="mb-1">本地生活类目管理</h5>
                    <small class="text-muted">
                        类目就是 H5「本地生活」首页金刚区那一排格子。这里增/删/改/排序，前端会自动跟着变（无需改代码）。
                        <span class="text-danger">移民 / 签证 / 按摩</span> 等敏感类目请勾「敏感」，其发帖会默认进人工审核。
                    </small>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <a href="{{ route('admin.local-life.list') }}" class="btn btn--secondary">
                        <i class="tio-chevron-left"></i> 返回帖子列表
                    </a>
                    <a href="{{ route('admin.local-life.categories.create') }}" class="btn btn--primary">
                        <i class="tio-add-circle"></i> 新建类目
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-2">
        <div class="card-header py-2 border-0">
            <h5 class="card-title">全部类目 <span class="badge badge-soft-dark ml-2">{{ count($categories) }}</span></h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive datatable-custom">
                <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                    <thead class="thead-light">
                    <tr>
                        <th style="width:8%">排序</th>
                        <th style="width:8%">图标</th>
                        <th style="width:22%">类目名</th>
                        <th style="width:12%">归属频道</th>
                        <th style="width:12%">在用帖子</th>
                        <th class="text-center" style="width:10%">敏感</th>
                        <th class="text-center" style="width:10%">状态</th>
                        <th style="width:18%">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                        @forelse($categories as $cat)
                        <tr>
                            <td>{{ $cat->sort_order }}</td>
                            <td>
                                <span style="font-size:22px; display:inline-flex; align-items:center; justify-content:center; width:38px; height:38px; border-radius:11px; background:{{ $cat->color ?: '#EEEFF4' }};">{{ $cat->emoji }}</span>
                            </td>
                            <td><strong>{{ $cat->name }}</strong></td>
                            <td><span class="badge badge-soft-secondary">{{ $cat->tab }}</span></td>
                            <td>{{ $postCounts[$cat->name] ?? 0 }} 条</td>
                            <td class="text-center">
                                @if($cat->is_sensitive)
                                    <span class="badge badge-warning"><i class="tio-warning"></i> 敏感</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <form action="{{ route('admin.local-life.categories.status', $cat->id) }}" method="post" class="d-inline">
                                    @csrf
                                    <label class="toggle-switch toggle-switch-sm mb-0" title="点击切换启用/停用">
                                        <input type="checkbox" class="toggle-switch-input" onchange="this.form.submit()" {{ $cat->status ? 'checked' : '' }}>
                                        <span class="toggle-switch-label"><span class="toggle-switch-indicator"></span></span>
                                    </label>
                                </form>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('admin.local-life.categories.edit', $cat->id) }}" class="btn btn-sm btn-outline-primary" title="编辑">
                                        <i class="tio-edit"></i>
                                    </a>
                                    <form action="{{ route('admin.local-life.categories.delete') }}" method="post"
                                          onsubmit="return confirm('确定删除类目「{{ $cat->name }}」？\n已发布的相关帖子（{{ $postCounts[$cat->name] ?? 0 }} 条）不会被删，仍保留分类文字，但前端金刚区将不再显示此类目。');">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="id" value="{{ $cat->id }}">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="删除"><i class="tio-delete"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">还没有类目，点右上角「新建类目」添加</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
