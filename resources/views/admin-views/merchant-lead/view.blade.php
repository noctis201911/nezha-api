@extends('layouts.admin.app')
@section('title', '入驻申请详情')

@section('content')
<div class="content container-fluid">
    <div class="row">
        <div class="col-lg-8">
            <div class="card mt-2">
                <div class="card-header">
                    <h5 class="card-title">入驻申请详情 #{{$lead->id}}</h5>
                </div>
                <div class="card-body">
                    <table class="table table-bordered">
                        <tr><th style="width:30%">店铺名称</th><td>{{$lead->store_name}}</td></tr>
                        <tr><th>联系人</th><td>{{$lead->contact_name}}</td></tr>
                        <tr><th>邮箱</th><td>{{$lead->email}}</td></tr>
                        <tr><th>微信</th><td>{{$lead->wechat ?: '-'}}</td></tr>
                        <tr><th>店铺地址</th><td>{{$lead->address ?: '-'}}</td></tr>
                        <tr><th>经营品类</th><td>{{$lead->category ?: '-'}}</td></tr>
                        <tr><th>备注</th><td>{{$lead->note ?: '-'}}</td></tr>
                        <tr><th>来源</th><td>{{$lead->source}}</td></tr>
                        <tr><th>提交时间</th><td>{{$lead->created_at}}</td></tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card mt-2">
                <div class="card-header"><h5 class="card-title">跟进状态</h5></div>
                <div class="card-body">
                    <form action="{{route('admin.merchant-lead.update-status',$lead->id)}}" method="post">
                        @csrf
                        <div class="form-group">
                            <label>当前状态</label>
                            <select name="status" class="form-control">
                                <option value="0" {{$lead->status==0?'selected':''}}>待跟进</option>
                                <option value="1" {{$lead->status==1?'selected':''}}>跟进中</option>
                                <option value="2" {{$lead->status==2?'selected':''}}>已完成</option>
                                <option value="3" {{$lead->status==3?'selected':''}}>无效</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn--primary">保存状态</button>
                        <a href="{{route('admin.merchant-lead.list')}}" class="btn btn--secondary">返回列表</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
