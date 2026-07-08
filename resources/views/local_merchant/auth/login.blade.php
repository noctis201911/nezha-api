@extends('local_merchant.layout')
@section('title', '商户登录')
@section('content')
<h1 class="nzm-title">哪吒商户管理</h1>
<p class="nzm-sub">登录维护您店铺的展示信息</p>
<form method="POST" action="{{ route('local-merchant.login.post') }}" class="nzm-form" novalidate>
    @csrf
    <label class="nzm-label">邮箱
        <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="nzm-input" placeholder="you@example.com">
    </label>
    <label class="nzm-label">密码
        <input type="password" name="password" required autocomplete="current-password" class="nzm-input" placeholder="请输入密码">
    </label>
    <label class="nzm-check"><input type="checkbox" name="remember" value="1"> 记住登录</label>
    <button type="submit" class="nzm-btn">登录</button>
</form>
<div class="nzm-links">
    <a href="{{ route('local-merchant.forgot') }}">忘记密码 / 首次设置密码</a>
</div>
@endsection
