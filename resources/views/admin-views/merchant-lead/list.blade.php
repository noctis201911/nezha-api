@extends('layouts.admin.app')
@section('title', '商家入驻申请')
@push('css_or_js')
@endpush

@section('content')
<div class="content container-fluid">
    <div class="card mt-2">
        <div class="card-header py-2 border-0">
            <div class="search--button-wrapper">
                <h5 class="card-title">商家入驻申请<span class="badge badge-soft-dark ml-2">{{$leads->total()}}</span></h5>
                <form id="search-form">
                    <div class="input--group input-group input-group-merge input-group-flush">
                        <input type="search" name="search" class="form-control" placeholder="按店名 / 联系人 / 邮箱搜索" value="{{ request()?->search ?? null }}">
                        <button type="submit" class="btn btn--secondary">
                            <i class="tio-search"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive datatable-custom">
                <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
                    <thead class="thead-light">
                    <tr>
                        <th style="width:5%">序号</th>
                        <th style="width:18%">店铺名称</th>
                        <th style="width:12%">联系人</th>
                        <th style="width:15%">邮箱</th>
                        <th style="width:12%">品类</th>
                        <th class="text-center" style="width:10%">状态</th>
                        <th style="width:13%">提交时间</th>
                        <th class="text-center" style="width:10%">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                        @foreach($leads as $k=>$lead)
                        <tr>
                            <td>{{$leads->firstItem()+$k}}</td>
                            <td>
                                {{Str::limit($lead->store_name,22,'...')}}
                                @if(!$lead->seen)<span class="badge badge-danger ml-1">新</span>@endif
                            </td>
                            <td>{{$lead->contact_name}}</td>
                            <td>{{$lead->email}}</td>
                            <td>{{$lead->category ?: '-'}}</td>
                            <td class="text-center">
                                @php $cls = ['badge-primary','badge-warning','badge-success','badge-secondary'][$lead->status] ?? 'badge-primary'; @endphp
                                <label class="badge {{$cls}}">{{$lead->statusLabel()}}</label>
                            </td>
                            <td>{{$lead->created_at}}</td>
                            <td class="text-center">
                                <div class="btn--container justify-content-center">
                                    <a title="查看" class="btn btn-sm btn--warning btn-outline-warning action-btn" href="{{route('admin.merchant-lead.view',$lead->id)}}">
                                        <i class="tio-visible"></i>
                                    </a>
                                    <a class="btn btn-sm btn--danger btn-outline-danger action-btn form-alert" href="javascript:"
                                        data-id="lead-{{$lead->id}}" data-message="确定删除这条入驻申请吗？" title="删除"><i class="tio-delete-outlined"></i></a>
                                    <form action="{{route('admin.merchant-lead.delete')}}" method="post" id="lead-{{$lead->id}}">
                                        <input type="hidden" name="id" value="{{$lead->id}}">
                                        @csrf @method('delete')
                                    </form>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @if(count($leads) === 0)
                <div class="empty--data">
                    <img src="{{dynamicAsset('assets/admin/img/empty.png')}}" alt="empty">
                    <h5>暂无入驻申请</h5>
                </div>
                @endif
            </div>
        </div>
        <div class="card-footer p-0 border-0">
            <div class="page-area px-4 pb-3">
                <div class="d-flex align-items-center justify-content-end">
                    <div>{!! $leads->links() !!}</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
