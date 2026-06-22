@extends('layouts.admin.app')
@section('title', translate('无访问权限'))
@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-column align-items-center justify-content-center text-center" style="min-height:60vh;">
            <div style="font-size:64px;line-height:1;color:#DC2626;margin-bottom:16px;">
                <i class="tio-lock-outlined"></i>
            </div>
            <h1 class="page-header-title mb-2" style="font-size:24px;">{{ translate('无访问权限') }}</h1>
            <p class="text-muted mb-1" style="max-width:540px;">
                {{ translate('您当前的管理员角色没有访问该功能的权限。如需使用, 请联系超级管理员为您的角色开通相应权限位。') }}
            </p>
            @if(!empty($module))
                <p class="text-muted mb-3" style="font-size:13px;">
                    {{ translate('所需权限位') }}: <code>{{ $module }}</code>
                </p>
            @endif
            <a href="{{ route('admin.dashboard') }}" class="btn btn-primary mt-2">
                <i class="tio-home"></i> {{ translate('返回后台首页') }}
            </a>
        </div>
    </div>
@endsection
