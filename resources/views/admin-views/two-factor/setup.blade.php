@extends('layouts.admin.app')

@section('title', '两步验证 (2FA)')

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">后台两步验证 (2FA)</h1>
        <p class="text-muted">用手机认证器 App 在登录时增加一道动态验证码, 大幅降低密码泄露后的被盗风险。</p>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif
    @if (session('2fa:disabled'))
        <div class="alert alert-warning">两步验证已关闭。</div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                @if ($enabled)
                    <div class="d-flex align-items-center mb-3">
                        <span class="badge badge-soft-success">已启用</span>
                        <span class="ml-2">你的后台账号已开启两步验证。</span>
                    </div>

                    @if (!empty($recovery_codes))
                        <div class="alert alert-info">
                            <h5>请立刻保存这些一次性恢复码</h5>
                            <p class="mb-2">如果你换手机或丢失认证器, 用其中一个恢复码也能登录(每个只能用一次)。<b>现在不抄下来, 以后将无法再看到。</b></p>
                            <div class="bg-light p-3 rounded" style="font-family:monospace;font-size:16px;letter-spacing:1px;">
                                @foreach ($recovery_codes as $rc) <div>{{ $rc }}</div> @endforeach
                            </div>
                        </div>
                    @endif

                    <hr>
                    <h5>关闭两步验证</h5>
                    <form action="{{ route('admin.two-factor.disable') }}" method="POST" class="mt-2">
                        @csrf
                        <div class="form-group" style="max-width:320px;">
                            <label>输入当前登录密码以确认</label>
                            <input type="password" name="password" class="form-control" autocomplete="off" required>
                        </div>
                        <button type="submit" class="btn btn-outline-danger">关闭两步验证</button>
                    </form>
                @else
                    <h5>第一步: 用认证器扫码</h5>
                    <p class="text-muted">推荐 Google Authenticator / 微软 Authenticator / 1Password 等。</p>
                    <div class="mb-3">
                        <img src="data:image/svg+xml;base64,{{ $qr_svg }}" alt="2FA QR" style="border:1px solid #eee;border-radius:8px;">
                    </div>
                    <p class="mb-1">无法扫码? 在 App 里手动输入这个密钥(选 "基于时间"):</p>
                    <div class="bg-light p-2 rounded mb-4" style="font-family:monospace;font-size:16px;letter-spacing:2px;max-width:420px;">
                        {{ $secret }}
                    </div>

                    <h5>第二步: 输入 App 上显示的 6 位码确认</h5>
                    <form action="{{ route('admin.two-factor.enable') }}" method="POST" class="mt-2">
                        @csrf
                        <div class="form-group" style="max-width:240px;">
                            <input type="text" name="code" class="form-control" inputmode="numeric" autocomplete="off"
                                   placeholder="6 位验证码" maxlength="6" required autofocus>
                        </div>
                        <button type="submit" class="btn btn--primary">确认并启用</button>
                    </form>
                @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
