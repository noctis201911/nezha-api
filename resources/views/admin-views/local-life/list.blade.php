@extends('layouts.admin.app')
@section('title', '本地生活')
@push('css_or_js')
@endpush

@section('content')
<div class="content container-fluid">
@php $sensitiveCats = \App\Models\LocalLifeCategory::sensitiveNames(); @endphp
@php $moveCatOptions = \App\Models\LocalLifeCategory::activeNames(); @endphp

    {{-- 用户发帖入口总开关（真实影响开关，默认关） --}}
    <div class="card mt-2">
        <div class="card-body py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h5 class="mb-1">用户发帖入口
                    @if($ugcEnabled)
                        <span class="badge badge-soft-success ml-1">已开放</span>
                    @else
                        <span class="badge badge-soft-secondary ml-1">未开放</span>
                    @endif
                </h5>
                <small class="text-muted">开放后，登录顾客可在 H5「本地生活」自助发帖（默认进待审核，审核通过才公开）。关闭时入口显示"即将开放"。</small>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="{{ route('admin.local-life.settings') }}" class="btn btn--secondary">
                    <i class="tio-settings"></i> 护栏与文案设置
                </a>
                <form action="{{ route('admin.local-life.ugc-toggle') }}" method="post">
                    @csrf
                    <input type="hidden" name="enable" value="{{ $ugcEnabled ? 0 : 1 }}">
                    <button type="submit" class="btn {{ $ugcEnabled ? 'btn--danger' : 'btn--primary' }}">
                        {{ $ugcEnabled ? '关闭发帖入口' : '开放发帖入口' }}
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="card mt-2">
        <div class="card-header py-2 border-0">
            <div class="search--button-wrapper">
                <h5 class="card-title">本地生活帖子<span class="badge badge-soft-dark ml-2">{{$posts->total()}}</span>
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

            {{-- 状态筛选 --}}
            <div class="mt-2 d-flex flex-wrap gap-1">
                @php
                    $filters = ['' => '全部', '3' => '待审核', '1' => '已发布', '4' => '已驳回', '0' => '草稿', '2' => '已下线'];
                @endphp
                @foreach($filters as $val => $label)
                    <a href="{{ route('admin.local-life.list', array_filter(['status' => $val, 'search' => $search])) }}"
                       class="btn btn-sm {{ (string)$statusFilter === (string)$val ? 'btn--primary' : 'btn-outline-secondary' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive datatable-custom">
                <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                    <thead class="thead-light">
                    <tr>
                        <th style="width:5%">序号</th>
                        <th style="width:24%">标题</th>
                        <th style="width:13%">分类</th>
                        <th style="width:8%">Tab</th>
                        <th style="width:8%">来源</th>
                        <th style="width:11%">价格</th>
                        <th class="text-center" style="width:9%">状态</th>
                        <th style="width:22%">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                        @foreach($posts as $k=>$post)
                        @php $rc = $reportCounts[$post->id] ?? 0; @endphp
                        <tr>
                            <td>{{$posts->firstItem()+$k}}</td>
                            <td>
                                @if($post->cover_emoji)<span class="mr-1">{{$post->cover_emoji}}</span>@endif
                                {{Str::limit($post->title,28,'...')}}
                                @if($post->is_urgent)<span class="badge badge-danger ml-1">急</span>@endif
                                @if($rc > 0)
                                    <a href="{{route('admin.local-life.reports',$post->id)}}" class="badge badge-danger ml-1" title="查看举报">
                                        <i class="tio-flag"></i> 举报 {{$rc}}
                                    </a>
                                @endif
                                @if($post->status == 4 && $post->reject_reason)
                                    <div><small class="text-danger">驳回理由：{{Str::limit($post->reject_reason,40,'...')}}</small></div>
                                @endif
                            </td>
                            <td>{{$post->category}}
                                @if(in_array($post->category, $sensitiveCats, true))
                                    <span class="badge badge-warning ml-1" title="敏感类目，请重点审核"><i class="tio-warning"></i> 敏感</span>
                                @endif
                                @if(count($moveCatOptions))
                                    {{-- 一键改类目：选错金刚区快捷挪到正确类目（业主0710）--}}
                                    <form action="{{route('admin.local-life.move-category',$post->id)}}" method="post" class="mt-1 mb-0">
                                        @csrf
                                        <select name="category" class="form-control form-control-sm" style="max-width:150px;font-size:12px" title="选错类目？快捷改到正确类目"
                                            onchange="if(this.value&&confirm('把此帖改到类目「'+this.value+'」？')){this.form.submit()}else{this.selectedIndex=0}">
                                            <option value="" disabled selected>改类目…</option>
                                            @foreach($moveCatOptions as $mc)
                                                @if($mc !== $post->category)
                                                    <option value="{{$mc}}">{{$mc}}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </form>
                                @endif
                            </td>
                            <td>{{$post->tab}}</td>
                            <td>
                                @if($post->source == 'user')
                                    <span class="badge badge-soft-info">用户</span>
                                @else
                                    <span class="badge badge-soft-secondary">平台</span>
                                @endif
                            </td>
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
                                @php $cls = ['badge-secondary','badge-success','badge-dark','badge-warning','badge-danger'][$post->status] ?? 'badge-secondary'; @endphp
                                <label class="badge {{$cls}}">{{$post->statusLabel()}}</label>
                                @if($post->legal_hold)
                                    <div><label class="badge badge-dark mt-1" title="证据冻结中：豁免30天到期清除，供违规处理/配合调查"><i class="tio-lock"></i> 冻结留证</label></div>
                                @endif
                            </td>
                            <td>
                                <div class="btn--container">
                                    {{-- 待审核：通过 / 驳回 --}}
                                    @if($post->status == 3)
                                        <form action="{{route('admin.local-life.approve',$post->id)}}" method="post" class="d-inline">
                                            @csrf
                                            <button type="submit" title="审核通过并发布" class="btn btn-sm btn--success btn-outline-success action-btn">
                                                <i class="tio-checkmark-circle"></i> 通过
                                            </button>
                                        </form>
                                        <a class="btn btn-sm btn--warning btn-outline-warning action-btn" href="javascript:" title="驳回"
                                            onclick="document.getElementById('reject-form-{{$post->id}}').classList.toggle('d-none')">
                                            <i class="tio-clear-circle"></i> 驳回
                                        </a>
                                    @endif

                                    {{-- 举报：进入举报处理页 --}}
                                    @if($rc > 0)
                                        <a title="查看举报 ({{$rc}})" class="btn btn-sm btn--danger btn-outline-danger action-btn" href="{{route('admin.local-life.reports',$post->id)}}">
                                            <i class="tio-flag"></i> {{$rc}}
                                        </a>
                                    @endif

                                    <a title="编辑" class="btn btn-sm btn--primary btn-outline-primary action-btn" href="{{route('admin.local-life.edit',$post->id)}}">
                                        <i class="tio-edit"></i>
                                    </a>

                                    {{-- 发布/下线切换：仅对非待审核/非驳回显示，避免和审核动作混淆 --}}
                                    @if(in_array($post->status, [0,1,2]))
                                        <form action="{{route('admin.local-life.status',$post->id)}}" method="post" class="d-inline">
                                            @csrf
                                            <button type="submit" title="{{$post->status==1?'转为草稿':'发布'}}"
                                                class="btn btn-sm {{$post->status==1?'btn--warning btn-outline-warning':'btn--success btn-outline-success'}} action-btn">
                                                <i class="{{$post->status==1?'tio-archive-outlined':'tio-publish'}}"></i>
                                            </button>
                                        </form>
                                    @endif

                                    {{-- 证据冻结/解除：违规帖豁免30天到期清除供留证(L1-7有限例外) --}}
                                    <form action="{{route('admin.local-life.legal-hold',$post->id)}}" method="post" class="d-inline"
                                        onsubmit="return confirm('{{$post->legal_hold ? '解除证据冻结？解除后该帖联系方式/图片将按正常30天到期清除。' : '冻结留证？该帖联系方式/图片将豁免到期清除，供违规处理/配合调查。仅用于违规/执法，用尽后请解除。'}}')">
                                        @csrf
                                        <button type="submit" title="{{$post->legal_hold ? '解除证据冻结' : '冻结留证（豁免到期清除）'}}"
                                            class="btn btn-sm {{$post->legal_hold ? 'btn--dark btn-outline-dark' : 'btn--secondary btn-outline-secondary'}} action-btn">
                                            <i class="{{$post->legal_hold ? 'tio-lock-opened' : 'tio-lock'}}"></i>
                                        </button>
                                    </form>
                                    <a class="btn btn-sm btn--danger btn-outline-danger action-btn form-alert" href="javascript:"
                                        data-id="post-{{$post->id}}" data-message="确定删除这条帖子吗？" title="删除"><i class="tio-delete-outlined"></i></a>
                                    <form action="{{route('admin.local-life.delete')}}" method="post" id="post-{{$post->id}}">
                                        <input type="hidden" name="id" value="{{$post->id}}">
                                        @csrf @method('delete')
                                    </form>
                                </div>

                                {{-- 驳回理由输入（默认隐藏） --}}
                                @if($post->status == 3)
                                    <form action="{{route('admin.local-life.reject',$post->id)}}" method="post" id="reject-form-{{$post->id}}" class="d-none mt-2">
                                        @csrf
                                        <div class="input-group input-group-sm">
                                            <input type="text" name="reject_reason" class="form-control" maxlength="255" placeholder="驳回理由（可选，将展示给用户）">
                                            <button type="submit" class="btn btn--warning">确认驳回</button>
                                        </div>
                                    </form>
                                @endif
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
