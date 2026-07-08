@extends('local_merchant.layout')
@section('title', '我的店铺')
@section('content')
<h1 class="nzm-title">{{ $merchant->name ?? '我的店铺' }}</h1>
<p class="nzm-sub">已登录：{{ $account->email }}</p>
<p style="color:#3d5170;font-size:14px;margin:14px 0 4px">店铺信息维护面板即将上线（预览 / 编辑 / 提交平台确认）。</p>
<form method="POST" action="{{ route('local-merchant.logout') }}" style="margin-top:18px">
    @csrf
    <button type="submit" class="nzm-btn nzm-btn-ghost">退出登录</button>
</form>
@endsection
