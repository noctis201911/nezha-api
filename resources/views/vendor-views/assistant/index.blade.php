@extends('layouts.vendor.app')
@section('title', translate('商家助手'))
@section('content')
    {{-- UX1-E 商家助手会话化改版（藏青主题·照 Fable 稿）。持久层见 NezhaAssistantController + nezha_assistant_messages。
         复用全站 _nz_ui_kit：动作卡确认/取消走 data-nz-ajax（不落屏）。安全流不破：AI 只提议、商家点确认才执行。 --}}
    <div class="nzma-outer">
        <div id="nzmaWrap">
            {{-- 页头：AI 身份锚（区别于「消息」的顾客真人会话） --}}
            <div class="nzma-head">
                <div class="nzma-ava">💬</div>
                <div class="nzma-htxt">
                    <b>哪吒商家助手</b><span class="nzma-badge">AI</span>
                    <div class="nzma-sub">查数据、改菜品、店铺操作——用说的就行；改店铺的操作会先和您确认</div>
                </div>
            </div>

            {{-- 会话区（历史持久 · 按日分组 · 可上滑看更早） --}}
            <div class="nzma-chat" id="nzmaChat">
                <div class="nzma-thread" id="nzmaThread" data-oldest="{{ $messages->isNotEmpty() ? $messages->first()->id : '' }}" aria-live="polite">
                    @if ($messages->isEmpty())
                        <div class="nzma-empty" id="nzmaEmpty">
                            <div class="nzma-ava">💬</div>
                            <h3>你好，我是哪吒商家助手</h3>
                            <div class="nzma-es">下面这些都可以直接问我：</div>
                            <div class="nzma-cap3">
                                <div class="nzc"><b>📊 查经营</b><span>“今天卖了多少？”　“这周哪个菜卖得最好？”</span></div>
                                <div class="nzc"><b>🍳 店铺操作</b><span>“太忙了，先暂停接单”　“把麻辣香锅改成 5800”</span></div>
                                <div class="nzc"><b>✍️ 帮写文案</b><span>“给麻辣香锅写一段吸引人的描述”</span></div>
                            </div>
                        </div>
                    @else
                        @if ($hasMore)
                            <button type="button" class="nzma-loadmore" id="nzmaMore">查看更早的对话</button>
                        @endif
                        @include('vendor-views.assistant._messages', ['messages' => $messages])
                    @endif
                </div>
            </div>

            {{-- 底部固定输入区 --}}
            <div class="nzma-dock">
                <div class="nzma-din">
                    <div class="nzma-chips" id="nzmaChips">
                        @foreach (['今日营收', '暂停接单', '一键售罄', '怎么上传新菜品？', '顾客要退款怎么处理？'] as $eg)
                            <button type="button" class="nzma-eg">{{ $eg }}</button>
                        @endforeach
                    </div>
                    {{-- fallback：无 JS 时这个表单直接经典 POST 到 ask 也能问答（服务端落库 + back 回读渲染）。 --}}
                    <form method="POST" action="{{ route('vendor.nezha-assistant.ask') }}" class="nzma-ibar" id="nzmaForm">
                        @csrf
                        <input type="text" name="question" id="nzmaInput" maxlength="500" autocomplete="off"
                               placeholder="问我任何店铺问题，或直接说要做什么…">
                        <button type="submit" id="nzmaSend">发送</button>
                    </form>
                    <div class="nzma-safe">改店铺的操作（改价 / 暂停接单等），AI 都会先出确认卡、您点确认才执行</div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .nzma-outer { padding: 10px 12px 0; }
        #nzmaWrap {
            --nznavy:#102A4C; --nzbody:#42505F; --nzsec:#98A2B3; --nzline:#D6DBE1; --nzbg:#F3F5F7;
            --nzink:#17191D; --nzamber:#E8910C; --nzamberBg:#FFF7E6; --nzgreen:#1F7A3A; --nzgreenBg:#E8F8EE; --nzchipBg:#F6F7F9;
            display:flex; flex-direction:column; height:calc(100vh - 150px); min-height:460px;
            background:var(--nzbg); border:1px solid var(--nzline); border-radius:14px; overflow:hidden;
            font-family:"Noto Sans Armenian","PingFang SC","Microsoft YaHei","Noto Sans SC","Segoe UI",sans-serif; color:var(--nzbody);
        }
        .nzma-head { background:#fff; border-bottom:1px solid var(--nzline); display:flex; align-items:center; gap:12px; padding:14px 18px; flex:0 0 auto; }
        .nzma-ava { width:42px; height:42px; border-radius:12px; background:var(--nznavy); color:#fff; display:flex; align-items:center; justify-content:center; font-size:20px; flex:0 0 42px; }
        .nzma-htxt b { color:var(--nznavy); font-size:16px; }
        .nzma-badge { display:inline-block; border:1.5px solid var(--nznavy); color:var(--nznavy); font-size:11px; font-weight:700; border-radius:6px; padding:0 6px; margin-left:6px; vertical-align:2px; }
        .nzma-sub { color:var(--nzsec); font-size:12.5px; margin-top:2px; }

        .nzma-chat { flex:1 1 auto; overflow-y:auto; -webkit-overflow-scrolling:touch; }
        .nzma-thread { max-width:860px; margin:0 auto; padding:18px 20px 12px; }
        .nzma-loadmore { display:block; width:100%; text-align:center; color:var(--nzsec); font-size:12.5px; padding:2px 0 14px; text-decoration:underline; text-underline-offset:3px; cursor:pointer; background:none; border:none; font-family:inherit; }
        .nzma-day { display:flex; align-items:center; gap:12px; color:var(--nzsec); font-size:12px; margin:16px 0 14px; }
        .nzma-day::before, .nzma-day::after { content:""; flex:1; height:1px; background:var(--nzline); }
        .nzma-row { display:flex; margin-bottom:12px; }
        .nzma-row.me { justify-content:flex-end; }
        .nzma-b-me { background:var(--nznavy); color:#fff; border-radius:14px 14px 4px 14px; padding:10px 14px; max-width:72%; font-size:14px; line-height:1.7; white-space:pre-wrap; word-break:break-word; }
        .nzma-b-ai { background:#fff; border:1px solid var(--nzline); border-left:3px solid var(--nznavy); border-radius:4px 14px 14px 14px; padding:10px 14px; max-width:78%; color:var(--nzink); font-size:14px; line-height:1.8; white-space:pre-wrap; word-break:break-word; }
        .nzma-typing { color:var(--nzsec); }
        .nzma-dots { display:inline-flex; gap:4px; margin-right:8px; vertical-align:middle; }
        .nzma-dots i { width:6px; height:6px; border-radius:50%; background:var(--nzsec); display:inline-block; animation:nzmaBlink 1.2s infinite; }
        .nzma-dots i:nth-child(2) { animation-delay:.2s; }
        .nzma-dots i:nth-child(3) { animation-delay:.4s; }
        @keyframes nzmaBlink { 0%,60%,100% { opacity:.3; } 30% { opacity:1; } }

        .nzma-acard { background:#fff; border:1px solid var(--nzline); border-left:4px solid var(--nzamber); border-radius:12px; padding:13px 16px; max-width:78%; margin:2px 0 12px; }
        .nzma-acard-done { border-left-color:var(--nzgreen); }
        .nzma-acard-cancelled { border-left-color:var(--nzsec); opacity:.85; }
        .nzma-cap { color:var(--nzsec); font-size:11.5px; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
        .nzma-st { border-radius:6px; padding:0 7px; font-weight:700; font-size:11px; }
        .nzma-st.pend { background:var(--nzamberBg); color:var(--nzamber); }
        .nzma-st.done { background:var(--nzgreenBg); color:var(--nzgreen); }
        .nzma-st.cancel { background:#EEF0F2; color:var(--nzsec); }
        .nzma-atitle { margin:5px 0 3px; color:var(--nzink); font-size:15px; font-weight:700; }
        .nzma-adesc { color:var(--nzbody); font-size:13px; line-height:1.7; }
        .nzma-adesc s { color:var(--nzsec); }
        .nzma-adesc b { color:var(--nzink); }
        .nzma-aops { display:flex; gap:9px; margin-top:11px; }
        .nzma-aform { margin:0; }
        .nzma-ok { background:var(--nznavy); color:#fff; border:none; border-radius:9px; padding:9px 20px; font-size:13.5px; font-weight:700; cursor:pointer; font-family:inherit; }
        .nzma-no { background:#fff; color:var(--nzbody); border:1.5px solid var(--nzline); border-radius:9px; padding:9px 16px; font-size:13.5px; cursor:pointer; font-family:inherit; }

        .nzma-empty { min-height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:24px 20px; text-align:center; max-width:600px; margin:0 auto; }
        .nzma-empty .nzma-ava { width:60px; height:60px; border-radius:16px; font-size:28px; margin-bottom:14px; }
        .nzma-empty h3 { margin:0 0 4px; color:var(--nznavy); font-size:17px; }
        .nzma-empty .nzma-es { color:var(--nzsec); font-size:13px; margin-bottom:20px; }
        .nzma-cap3 { display:flex; flex-direction:column; gap:10px; text-align:left; width:100%; }
        .nzma-cap3 .nzc { display:flex; gap:10px; align-items:baseline; background:#fff; border:1px solid var(--nzline); border-radius:10px; padding:12px 16px; font-size:13.5px; }
        .nzma-cap3 .nzc b { color:var(--nzink); white-space:nowrap; }
        .nzma-cap3 .nzc span { color:var(--nzsec); }

        .nzma-dock { background:#fff; border-top:1px solid var(--nzline); flex:0 0 auto; }
        .nzma-din { max-width:860px; margin:0 auto; padding:10px 20px 12px; }
        .nzma-chips { display:flex; gap:8px; overflow-x:auto; padding-bottom:9px; -webkit-overflow-scrolling:touch; }
        .nzma-chips button { flex:0 0 auto; background:var(--nzchipBg); border:1px solid var(--nzline); border-radius:999px; padding:6px 14px; font-size:13px; color:var(--nznavy); cursor:pointer; font-family:inherit; white-space:nowrap; }
        .nzma-ibar { display:flex; gap:9px; margin:0; }
        .nzma-ibar input { flex:1; border:1.5px solid var(--nzline); border-radius:12px; padding:12px 14px; font-size:14px; font-family:inherit; color:var(--nzink); min-width:0; background:#fff; }
        .nzma-ibar input::placeholder { color:var(--nzsec); }
        .nzma-ibar button { background:var(--nznavy); color:#fff; border:none; border-radius:12px; padding:0 24px; font-size:14.5px; font-weight:700; cursor:pointer; font-family:inherit; white-space:nowrap; }
        .nzma-ibar button:disabled { opacity:.6; cursor:default; }
        .nzma-safe { text-align:center; color:var(--nzsec); font-size:11.5px; margin-top:8px; }

        @media (max-width:560px) {
            .nzma-outer { padding:8px 8px 0; }
            #nzmaWrap { height:calc(100vh - 120px); border-radius:12px; }
            .nzma-b-ai, .nzma-acard { max-width:88%; }
            .nzma-b-me { max-width:82%; }
            .nzma-thread { padding:16px 14px 12px; }
            .nzma-din { padding:10px 14px calc(12px + env(safe-area-inset-bottom, 0px)); }
            .nzma-ibar button { padding:0 20px; }
        }
    </style>

    <script>
        (function () {
            var CSRF = @json(csrf_token());
            var STREAM_URL = @json(route('vendor.nezha-assistant.stream'));
            var ASK_URL = @json(route('vendor.nezha-assistant.ask'));
            var HISTORY_URL = @json(route('vendor.nezha-assistant.history'));

            var wrap = document.getElementById('nzmaWrap');
            var chat = document.getElementById('nzmaChat');
            var thread = document.getElementById('nzmaThread');
            var form = document.getElementById('nzmaForm');
            var input = document.getElementById('nzmaInput');
            var sendBtn = document.getElementById('nzmaSend');
            var moreBtn = document.getElementById('nzmaMore');
            var empty = document.getElementById('nzmaEmpty');
            var busy = false;

            // 面板高度贴合视口底部（不依赖硬编码头部高度，移动端/桌面都稳）
            function fitHeight() {
                if (!wrap) return;
                var top = wrap.getBoundingClientRect().top;
                var h = window.innerHeight - top - 14;
                if (h < 380) h = 380;
                wrap.style.height = h + 'px';
            }
            function scrollDown() { chat.scrollTop = chat.scrollHeight; }
            function removeEmpty() { if (empty && empty.parentNode) { empty.parentNode.removeChild(empty); empty = null; } }

            function addUserRow(text) {
                removeEmpty();
                var row = document.createElement('div'); row.className = 'nzma-row me';
                var b = document.createElement('div'); b.className = 'nzma-b-me'; b.textContent = text || '';
                row.appendChild(b); thread.appendChild(row); scrollDown(); return b;
            }
            function addAiRow() {
                removeEmpty();
                var row = document.createElement('div'); row.className = 'nzma-row';
                var b = document.createElement('div'); b.className = 'nzma-b-ai nzma-typing';
                b.innerHTML = '<span class="nzma-dots"><i></i><i></i><i></i></span>正在回答…';
                row.appendChild(b); thread.appendChild(row); scrollDown(); return b;
            }
            function firstToken(b) { if (b.classList.contains('nzma-typing')) { b.classList.remove('nzma-typing'); b.textContent = ''; } }

            function setBusy(v) { busy = v; sendBtn.disabled = v; sendBtn.textContent = v ? '发送中…' : '发送'; }

            // 动作意图 → 交回经典 POST，落到 ask() 的"确认卡片"流程（整页刷新后由服务端从库渲染）
            function classicPost(question) {
                var f = document.createElement('form');
                f.method = 'POST'; f.action = ASK_URL; f.style.display = 'none';
                var t = document.createElement('input'); t.type = 'hidden'; t.name = '_token'; t.value = CSRF;
                var qi = document.createElement('input'); qi.type = 'hidden'; qi.name = 'question'; qi.value = question;
                f.appendChild(t); f.appendChild(qi); document.body.appendChild(f); f.submit();
            }

            function handleFrame(frame, b) {
                var lines = frame.split('\n'); var dataStr = '';
                for (var i = 0; i < lines.length; i++) {
                    if (lines[i].indexOf('data:') === 0) { dataStr += lines[i].slice(5).replace(/^ /, ''); }
                }
                if (dataStr === '' || dataStr === '"ok"') return;
                try { var obj = JSON.parse(dataStr); if (obj && typeof obj.t === 'string') { firstToken(b); b.textContent += obj.t; } } catch (e) {}
            }

            function ask(question) {
                question = (question || '').trim();
                if (!question || busy) return;
                addUserRow(question);
                input.value = '';
                setBusy(true);
                var bubble = addAiRow();

                fetch(STREAM_URL, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'text/event-stream', 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'question=' + encodeURIComponent(question)
                }).then(function (res) {
                    var ct = res.headers.get('Content-Type') || '';
                    if (ct.indexOf('application/json') >= 0) {
                        return res.json().then(function (j) {
                            if (j.mode === 'reload') { classicPost(question); return; } // 保持 busy，页面即将刷新
                            firstToken(bubble);
                            bubble.textContent = j.answer || '出错了，请稍后再试。';
                            setBusy(false); scrollDown();
                        });
                    }
                    if (!res.body || !res.body.getReader) {
                        return res.text().then(function (txt) {
                            txt.split('\n\n').forEach(function (fr) { handleFrame(fr, bubble); });
                            if (bubble.classList.contains('nzma-typing')) { firstToken(bubble); bubble.textContent = '出错了，请稍后再试。'; }
                            setBusy(false); scrollDown();
                        });
                    }
                    var reader = res.body.getReader(); var dec = new TextDecoder('utf-8'); var buf = '';
                    function pump() {
                        return reader.read().then(function (r) {
                            if (r.done) {
                                if (bubble.classList.contains('nzma-typing')) { firstToken(bubble); bubble.textContent = '（暂时没有内容，请重试）'; }
                                setBusy(false); scrollDown(); return;
                            }
                            buf += dec.decode(r.value, { stream: true });
                            var idx;
                            while ((idx = buf.indexOf('\n\n')) >= 0) { handleFrame(buf.slice(0, idx), bubble); buf = buf.slice(idx + 2); }
                            scrollDown(); return pump();
                        });
                    }
                    return pump();
                }).catch(function () {
                    firstToken(bubble);
                    if (!bubble.textContent) bubble.textContent = '网络中断了，请重试。';
                    setBusy(false);
                });
            }

            // 查看更早：只读分页，prepend 到 moreBtn 之后、保留滚动位置
            function loadMore() {
                if (!moreBtn || moreBtn.getAttribute('data-busy')) return;
                moreBtn.setAttribute('data-busy', '1');
                var oldest = thread.getAttribute('data-oldest') || '0';
                var prevH = chat.scrollHeight;
                fetch(HISTORY_URL + '?before=' + encodeURIComponent(oldest), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin'
                }).then(function (r) { return r.json(); }).then(function (j) {
                    var tmp = document.createElement('div'); tmp.innerHTML = j.html || '';
                    var firstDay = thread.querySelector('.nzma-day');
                    var newDays = tmp.querySelectorAll('.nzma-day');
                    var lastNewDay = newDays.length ? newDays[newDays.length - 1] : null;
                    var frag = document.createDocumentFragment();
                    while (tmp.firstChild) { frag.appendChild(tmp.firstChild); }
                    thread.insertBefore(frag, moreBtn.nextSibling);
                    if (firstDay && lastNewDay && firstDay.getAttribute('data-day') === lastNewDay.getAttribute('data-day')) {
                        firstDay.parentNode.removeChild(firstDay);
                    }
                    if (j.oldest) thread.setAttribute('data-oldest', j.oldest);
                    chat.scrollTop += (chat.scrollHeight - prevH);
                    if (!j.hasMore) { if (moreBtn.parentNode) moreBtn.parentNode.removeChild(moreBtn); moreBtn = null; }
                    else { moreBtn.removeAttribute('data-busy'); }
                }).catch(function () {
                    if (moreBtn) moreBtn.removeAttribute('data-busy');
                    if (window.nzToast) nzToast('加载失败，请重试', 'error');
                });
            }

            form.addEventListener('submit', function (e) { e.preventDefault(); ask(input.value); });
            var chips = document.getElementById('nzmaChips');
            if (chips) { chips.querySelectorAll('.nzma-eg').forEach(function (btn) { btn.addEventListener('click', function () { ask(btn.textContent.trim()); }); }); }
            if (moreBtn) moreBtn.addEventListener('click', loadMore);

            fitHeight();
            window.addEventListener('resize', fitHeight);
            scrollDown();
        })();
    </script>
@endsection
