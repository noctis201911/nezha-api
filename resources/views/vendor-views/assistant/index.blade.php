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
                {{-- 对话区：新提问逐字流式追加；动作确认（经典 POST 刷新）后由服务端回填上一问一答 --}}
                <div id="nzMaChat" class="nz-ma-chat" aria-live="polite">
                    @if (session('ma_q'))
                        <div class="nz-ma-row nz-ma-row-user"><div class="nz-ma-bubble nz-ma-bubble-user">{{ session('ma_q') }}</div></div>
                    @endif
                    @if (session('ma_a'))
                        <div class="nz-ma-row nz-ma-row-ai"><div class="nz-ma-bubble nz-ma-bubble-ai">{{ session('ma_a') }}</div></div>
                    @endif
                </div>

                @if (session('ma_action'))
                    @php
                        $nzAct = session('ma_action');
                        $nzLbl = ['pause' => '✅ 确认暂停接单', 'resume' => '✅ 确认恢复接单', 'feedback' => '✅ 确认提交给平台', 'price' => '✅ 确认改价'][$nzAct] ?? '✅ 确认';
                        $nzCls = $nzAct === 'pause' ? 'btn-danger' : ($nzAct === 'resume' ? 'btn-success' : 'btn-primary');
                    @endphp
                    {{-- 动作确认：AI 只提议，真正执行要商家点这个按钮（经典 POST → auth 商家端点、绑本店、服务端校验）。不流式、不缓存。 --}}
                    <form method="POST" action="{{ route('vendor.nezha-assistant.ask') }}" class="mt-2 nz-ma-confirm">
                        @csrf
                        <input type="hidden" name="confirm_action" value="{{ $nzAct }}">
                        <button class="btn {{ $nzCls }}" type="submit">{{ $nzLbl }}</button>
                        <a href="{{ route('vendor.nezha-assistant.index') }}" class="btn btn-soft-secondary btn-sm">{{ translate('取消') }}</a>
                    </form>
                @endif

                {{-- 输入区。fallback：无 JS 时这个表单直接经典 POST 到 ask 也能问答（只是不流式）。 --}}
                <form method="POST" action="{{ route('vendor.nezha-assistant.ask') }}" class="nz-ma-ask mt-3" id="nzMaForm">
                    @csrf
                    <div class="input-group">
                        <input type="text" name="question" id="nzMaInput" class="form-control" maxlength="500" autocomplete="off"
                               placeholder="{{ translate('例如：太忙了帮我暂停接单 / 怎么上传新菜品') }}">
                        <button class="btn btn-primary" type="submit" id="nzMaSend">{{ translate('问一下') }}</button>
                    </div>
                </form>

                <div class="mt-3">
                    <small class="text-muted">{{ translate('常见问题：') }}</small>
                    <div class="d-flex flex-wrap" style="gap:8px;margin-top:8px;">
                        @foreach (['太忙了，先暂停接单', '把麻辣香锅改成 5800', '这笔订单有问题，帮我反馈给平台', '怎么上传新菜品？', '顾客要退款怎么处理？', '帮我给麻辣香锅写一段吸引人的描述'] as $eg)
                            <button type="button" class="btn btn-sm btn-soft-secondary nz-ma-eg">{{ $eg }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .nz-ma-chat { display: flex; flex-direction: column; gap: 12px; max-height: 52vh; overflow-y: auto; padding: 4px 2px; }
        .nz-ma-chat:empty { display: none; }
        .nz-ma-row { display: flex; }
        .nz-ma-row-user { justify-content: flex-end; }
        .nz-ma-row-ai { justify-content: flex-start; }
        .nz-ma-bubble { max-width: 82%; padding: 10px 14px; border-radius: 14px; white-space: pre-wrap; line-height: 1.6; font-size: 14px; word-break: break-word; }
        .nz-ma-bubble-user { background: #377dff; color: #fff; border-bottom-right-radius: 4px; }
        .nz-ma-bubble-ai { background: #f7f8fa; color: #1f2329; border-bottom-left-radius: 4px; }
        .nz-ma-typing::after { content: '▋'; margin-left: 1px; color: #9aa0a6; animation: nzMaBlink 1s steps(1) infinite; }
        @keyframes nzMaBlink { 50% { opacity: 0; } }
    </style>

    <script>
        (function () {
            var CSRF = @json(csrf_token());
            var STREAM_URL = @json(route('vendor.nezha-assistant.stream'));
            var ASK_URL = @json(route('vendor.nezha-assistant.ask'));
            var LBL_SEND = @json(translate('问一下'));

            var chat = document.getElementById('nzMaChat');
            var form = document.getElementById('nzMaForm');
            var input = document.getElementById('nzMaInput');
            var sendBtn = document.getElementById('nzMaSend');
            var busy = false;

            function scrollDown() { chat.scrollTop = chat.scrollHeight; }

            function addRow(who, text) {
                var row = document.createElement('div');
                row.className = 'nz-ma-row ' + (who === 'user' ? 'nz-ma-row-user' : 'nz-ma-row-ai');
                var b = document.createElement('div');
                b.className = 'nz-ma-bubble ' + (who === 'user' ? 'nz-ma-bubble-user' : 'nz-ma-bubble-ai');
                b.textContent = text || '';
                row.appendChild(b);
                chat.appendChild(row);
                scrollDown();
                return b;
            }

            function setBusy(v) {
                busy = v;
                sendBtn.disabled = v;
                sendBtn.innerHTML = v ? '🤔 思考中…' : LBL_SEND;
            }

            // 动作意图 → 交回经典 POST，落到 ask() 的"确认按钮"流程（整页刷新）
            function classicPost(question) {
                var f = document.createElement('form');
                f.method = 'POST';
                f.action = ASK_URL;
                f.style.display = 'none';
                var t = document.createElement('input');
                t.type = 'hidden'; t.name = '_token'; t.value = CSRF;
                var qi = document.createElement('input');
                qi.type = 'hidden'; qi.name = 'question'; qi.value = question;
                f.appendChild(t); f.appendChild(qi);
                document.body.appendChild(f);
                f.submit();
            }

            function handleFrame(frame, bubble) {
                var lines = frame.split('\n');
                var dataStr = '';
                for (var i = 0; i < lines.length; i++) {
                    if (lines[i].indexOf('data:') === 0) {
                        dataStr += lines[i].slice(5).replace(/^ /, '');
                    }
                }
                if (dataStr === '' || dataStr === '"ok"') return; // 注释帧 / done 帧
                try {
                    var obj = JSON.parse(dataStr);
                    if (obj && typeof obj.t === 'string') { bubble.textContent += obj.t; }
                } catch (e) { /* 非 JSON 帧忽略 */ }
            }

            function ask(question) {
                question = (question || '').trim();
                if (!question || busy) return;
                addRow('user', question);
                input.value = '';
                setBusy(true);
                var bubble = null;

                fetch(STREAM_URL, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': CSRF,
                        'Accept': 'text/event-stream',
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'question=' + encodeURIComponent(question)
                }).then(function (res) {
                    var ct = res.headers.get('Content-Type') || '';
                    // 动作意图 / 缓存命中 / 限速 → JSON 分支
                    if (ct.indexOf('application/json') >= 0) {
                        return res.json().then(function (j) {
                            if (j.mode === 'reload') { classicPost(question); return; } // 保持 busy，页面即将刷新
                            addRow('ai', j.answer || '出错了，请稍后再试。');
                            setBusy(false);
                        });
                    }
                    // 纯问答 → SSE 流式逐字
                    if (!res.body || !res.body.getReader) { // 老浏览器兜底：一次性读完
                        return res.text().then(function (txt) {
                            bubble = addRow('ai', '');
                            txt.split('\n\n').forEach(function (fr) { handleFrame(fr, bubble); });
                            setBusy(false);
                        });
                    }
                    bubble = addRow('ai', '');
                    bubble.classList.add('nz-ma-typing');
                    var reader = res.body.getReader();
                    var dec = new TextDecoder('utf-8');
                    var buf = '';
                    function pump() {
                        return reader.read().then(function (r) {
                            if (r.done) {
                                bubble.classList.remove('nz-ma-typing');
                                setBusy(false);
                                scrollDown();
                                return;
                            }
                            buf += dec.decode(r.value, { stream: true });
                            var idx;
                            while ((idx = buf.indexOf('\n\n')) >= 0) {
                                handleFrame(buf.slice(0, idx), bubble);
                                buf = buf.slice(idx + 2);
                            }
                            scrollDown();
                            return pump();
                        });
                    }
                    return pump();
                }).catch(function () {
                    if (bubble) {
                        bubble.classList.remove('nz-ma-typing');
                        if (!bubble.textContent) bubble.textContent = '网络中断了，请重试。';
                    } else {
                        addRow('ai', '网络中断了，请重试。');
                    }
                    setBusy(false);
                });
            }

            form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
            document.querySelectorAll('.nz-ma-eg').forEach(function (btn) {
                btn.addEventListener('click', function () { ask(btn.textContent.trim()); });
            });
            scrollDown();
        })();
    </script>
@endsection
