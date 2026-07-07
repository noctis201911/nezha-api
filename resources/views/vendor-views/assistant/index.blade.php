@extends('layouts.vendor.app')
@section('title', translate('商家助手'))
@section('content')
    <div class="content container-fluid">
        <div class="page-header">
            <h1 class="page-header-title"><i class="tio-chat"></i> {{ translate('哪吒商家助手') }}</h1>
        </div>

        <div class="alert alert-soft-info" role="alert">
            <i class="tio-info"></i>
            {{ translate('遇到不会操作的就问我：怎么上传/修改菜品、设营业时间、配收款方式、处理订单和退款等。我还能帮你把菜品名称、描述写得更吸引人。') }}
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('vendor.nezha-assistant.ask') }}" class="nz-ma-form">
                    @csrf
                    <div class="input-group">
                        <input type="text" name="question" class="form-control" maxlength="500"
                               placeholder="{{ translate('例如：太忙了帮我暂停接单 / 怎么上传新菜品') }}"
                               value="{{ session('ma_q') }}">
                        <button class="btn btn-primary" type="submit">{{ translate('问一下') }}</button>
                    </div>
                </form>
                <div id="nzMaLoading" class="mt-3 text-muted" style="display:none;">🤔 {{ translate('正在思考，请稍候（一般几秒钟）…') }}</div>

                @if (session('ma_a'))
                    <div class="mt-3 p-3" style="background:#f7f8fa;border-radius:10px;white-space:pre-wrap;line-height:1.6;">{{ session('ma_a') }}</div>
                @endif

                @if (session('ma_action'))
                    @php
                        $nzAct = session('ma_action');
                        $nzLbl = ['pause' => '✅ 确认暂停接单', 'resume' => '✅ 确认恢复接单', 'feedback' => '✅ 确认提交给平台'][$nzAct] ?? '✅ 确认';
                        $nzCls = $nzAct === 'pause' ? 'btn-danger' : ($nzAct === 'resume' ? 'btn-success' : 'btn-primary');
                    @endphp
                    {{-- 动作确认：AI 只提议，真正执行要商家点这个按钮（走 auth 商家端点、绑本店、服务端校验） --}}
                    <form method="POST" action="{{ route('vendor.nezha-assistant.ask') }}" class="mt-2 nz-ma-form">
                        @csrf
                        <input type="hidden" name="confirm_action" value="{{ $nzAct }}">
                        <button class="btn {{ $nzCls }}" type="submit">{{ $nzLbl }}</button>
                        <a href="{{ route('vendor.nezha-assistant.index') }}" class="btn btn-soft-secondary btn-sm">{{ translate('取消') }}</a>
                    </form>
                @endif

                <div class="mt-3">
                    <small class="text-muted">{{ translate('常见问题：') }}</small>
                    <div class="d-flex flex-wrap" style="gap:8px;margin-top:8px;">
                        @foreach (['太忙了，先暂停接单', '这笔订单有问题，帮我反馈给平台', '怎么上传新菜品？', '怎么设置营业时间？', '顾客要退款怎么处理？', '帮我给麻辣香锅写一段吸引人的描述'] as $eg)
                            <form method="POST" action="{{ route('vendor.nezha-assistant.ask') }}" style="display:inline-block;" class="nz-ma-form">
                                @csrf
                                <input type="hidden" name="question" value="{{ $eg }}">
                                <button class="btn btn-sm btn-soft-secondary" type="submit">{{ $eg }}</button>
                            </form>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 提交后立刻显示"思考中"，避免整页刷新期间看着像卡死（真正的流式对话是后续更大改动） --}}
    <script>
        (function () {
            document.querySelectorAll('.nz-ma-form').forEach(function (f) {
                f.addEventListener('submit', function () {
                    var l = document.getElementById('nzMaLoading');
                    if (l) l.style.display = 'block';
                    var b = f.querySelector('button[type=submit]');
                    if (b) { b.disabled = true; b.innerHTML = '🤔 思考中…'; }
                });
            });
        })();
    </script>
@endsection
