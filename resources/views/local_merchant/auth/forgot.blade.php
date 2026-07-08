@extends('local_merchant.layout')
@section('title', '设置 / 找回密码')
@section('content')
<h1 class="nzm-title">设置 / 找回密码</h1>
<p class="nzm-sub">输入账号邮箱，我们会发送设置密码的链接到该邮箱</p>
<form method="POST" action="{{ route('local-merchant.forgot.post') }}" class="nzm-form" novalidate>
    @csrf
    <label class="nzm-label">邮箱
        <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="nzm-input" placeholder="you@example.com">
    </label>
    <button type="submit" class="nzm-btn">发送设置链接</button>
</form>
<div class="nzm-links"><a href="{{ route('local-merchant.login') }}">返回登录</a></div>
@endsection
