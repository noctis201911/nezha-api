@extends('local_merchant.layout')
@section('title', '设置登录密码')
@section('content')
<h1 class="nzm-title">设置登录密码</h1>
<p class="nzm-sub">为您的商户账号设置一个登录密码</p>
<form method="POST" action="{{ route('local-merchant.reset.post') }}" class="nzm-form" novalidate>
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <label class="nzm-label">邮箱
        <input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username" class="nzm-input" placeholder="you@example.com">
    </label>
    <label class="nzm-label">新密码（至少 8 位）
        <input type="password" name="password" required autocomplete="new-password" class="nzm-input" placeholder="设置新密码">
    </label>
    <label class="nzm-label">确认新密码
        <input type="password" name="password_confirmation" required autocomplete="new-password" class="nzm-input" placeholder="再次输入新密码">
    </label>
    <button type="submit" class="nzm-btn">保存并前往登录</button>
</form>
<div class="nzm-links"><a href="{{ route('local-merchant.login') }}">返回登录</a></div>
@endsection
