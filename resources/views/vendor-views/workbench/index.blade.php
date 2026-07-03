@extends('layouts.vendor.app')

@section('title', '今天 · 作业台')

@push('css_or_js')
<style>
/* 哪吒作业台 W2 —— 全部 class 以 nzwb- 前缀命名空间, 避免与 Bootstrap/主题的 .row/.btn/.card 冲突。
   token 取自业主已点头视觉稿 fable-mockups/workbench.html(商家端 V2 藏青采样)。 */
.nzwb{
  --navy:#102A4C; --body:#42505F; --sec:#98A2B3; --line:#D6DBE1; --bg:#F3F5F7; --bg2:#F7F8FA;
  --ink:#17191D; --amber:#E8910C; --amberBg:#FFF7E6; --amberLine:#FFE2A8; --red:#E5484D; --redBg:#FEE7EA;
  --green:#1F7A3A; --greenBg:#E8F8EE; --greenCta:#189A52; --chipBg:#F6F7F9; --gray:#6B7280;
  color:var(--body); font-size:14px;
  font-family:"Noto Sans Armenian","Segoe UI","Microsoft YaHei","PingFang SC",sans-serif;
}
.nzwb *{box-sizing:border-box}
.nzwb .num{font-variant-numeric:tabular-nums}

/* 顶部: 店态胶囊 + 今日单量 */
.nzwb-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px}
.nzwb-title{color:var(--navy);font-size:17px;font-weight:700}
.nzwb-cap{display:inline-flex;align-items:center;gap:8px;border:1.5px solid var(--line);border-radius:999px;padding:7px 14px;font-weight:600;color:var(--navy);background:#fff}
.nzwb-cap .dot{width:9px;height:9px;border-radius:50%;background:var(--greenCta)}
.nzwb-cap.nzwb-store{cursor:pointer;font-family:inherit;font-size:14px;transition:border-color .15s,color .15s}
.nzwb-cap.nzwb-store:hover{border-color:var(--navy)}
.nzwb-cap.paused{color:var(--red);border-color:var(--red)}
.nzwb-cap.paused .dot{background:var(--red)}
.nzwb-today{color:var(--body);font-size:13.5px;background:var(--bg2);border:1px solid var(--line);border-radius:999px;padding:7px 14px}
.nzwb-today b{color:var(--ink)}

/* 需动作总览条 */
.nzwb-alertbar{display:flex;align-items:center;gap:10px;padding:12px 16px;background:#fff;border:1px solid var(--line);border-radius:12px;margin-bottom:14px;overflow-x:auto}
.nzwb-alertbar .lbl{color:var(--sec);font-size:13px;flex:0 0 auto}
.nzwb-apill{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line);border-radius:999px;padding:6px 13px;font-size:13.5px;color:var(--navy);font-weight:600;background:#fff;cursor:pointer;flex:0 0 auto;text-decoration:none}
.nzwb-apill b{background:var(--navy);color:#fff;border-radius:8px;padding:0 7px;font-size:12.5px;line-height:18px}
.nzwb-apill.warn b{background:var(--amber)} .nzwb-apill.red b{background:var(--red)}
.nzwb-alertbar .calm{color:var(--green);background:var(--greenBg);border-radius:999px;padding:6px 14px;font-size:13.5px}

/* 双栏 */
.nzwb-cols{display:flex;gap:16px;align-items:flex-start}
.nzwb-maincol{flex:1;min-width:0}
.nzwb-rail{width:300px;flex:0 0 300px}
@media (max-width:1180px){ .nzwb-cols{flex-direction:column} .nzwb-rail{width:100%;flex:auto} }

/* 队列卡 */
.nzwb-qcard{background:#fff;border:1px solid var(--line);border-radius:12px;margin-bottom:14px;overflow:hidden}
.nzwb-qhead{display:flex;align-items:center;gap:9px;padding:12px 16px;border-bottom:1px solid #EAEDF0}
.nzwb-qhead .qdot{width:8px;height:8px;border-radius:2px;background:var(--navy)}
.nzwb-qhead b{color:var(--navy);font-size:14.5px}
.nzwb-qhead .cnt{background:var(--chipBg);border:1px solid var(--line);color:var(--navy);font-weight:700;border-radius:8px;padding:0 8px;font-size:12.5px;line-height:20px}
.nzwb-qhead .sub{color:var(--sec);font-size:12.5px}
.nzwb-qhead .all{margin-left:auto;color:var(--sec);font-size:13px;text-decoration:none}
.nzwb-qhead .all:hover{color:var(--navy)}
.nzwb-qcard.warncard .nzwb-qhead{background:var(--amberBg)} .nzwb-qcard.warncard .qdot{background:var(--amber)}
.nzwb-row{display:flex;align-items:center;gap:14px;padding:13px 16px;border-bottom:1px solid #F0F2F4}
.nzwb-row:last-child{border-bottom:none}
.nzwb-row .oid{color:var(--ink);font-weight:700;font-size:14px;white-space:nowrap}
.nzwb-row .meta{color:var(--sec);font-size:12.5px;margin-top:3px}
.nzwb-row .grow{flex:1;min-width:0}
.nzwb-money{color:var(--ink);font-weight:700;font-size:15px;white-space:nowrap;text-align:right}
.nzwb-money small{color:var(--sec);font-weight:400;font-size:11.5px;display:block}
.nzwb-chip{display:inline-block;border-radius:6px;padding:1px 8px;font-size:12px;background:var(--chipBg);border:1px solid var(--line);color:var(--body);vertical-align:1px}
.nzwb-chip.green{background:var(--greenBg);border-color:transparent;color:var(--green)}
.nzwb-chip.amber{background:var(--amberBg);border-color:var(--amberLine);color:var(--amber)}
.nzwb-chip.red{background:var(--redBg);border-color:transparent;color:var(--red)}
.nzwb-proof{width:40px;height:40px;border-radius:6px;background:linear-gradient(160deg,#E8EDF3,#D8E0E9);border:1px solid var(--line);color:var(--sec);font-size:11px;display:flex;align-items:center;justify-content:center;flex:0 0 40px}
.nzwb-btn{border:none;border-radius:8px;padding:9px 16px;font-size:13.5px;font-weight:600;cursor:pointer;white-space:nowrap;font-family:inherit;text-decoration:none;display:inline-block;line-height:1.3}
.nzwb-btn.green{background:var(--greenCta);color:#fff}
.nzwb-btn.navy{background:var(--navy);color:#fff}
.nzwb-btn.amber{background:var(--amber);color:#fff}
.nzwb-btn.line{background:#fff;color:var(--navy);border:1.5px solid var(--line)}
.nzwb-btn.more{background:#fff;color:var(--sec);border:1px solid var(--line);padding:9px 12px}
.nzwb-act{display:flex;gap:8px;align-items:center}
.nzwb-row.mute{background:var(--bg2)}
.nzwb-row.mute .oid{color:var(--gray);font-weight:600}
.nzwb-row.overdue{box-shadow:inset 3px 0 0 var(--red)}
.nzwb-hint{color:var(--sec);font-size:12px;padding:9px 16px;background:var(--bg2);border-top:1px solid #F0F2F4}
.nzwb-qempty{padding:18px 16px;color:var(--sec);font-size:13px}
.nzwb-sect{display:flex;align-items:center;gap:8px;padding:9px 16px;background:var(--bg2);font-size:12.5px;color:var(--body);border-bottom:1px solid #F0F2F4}
.nzwb-sect .d{width:7px;height:7px;border-radius:50%}
.nzwb-sect.a .d{background:var(--red)} .nzwb-sect.b .d{background:var(--sec)}

/* 右栏 */
.nzwb-rcard{background:#fff;border:1px solid var(--line);border-radius:12px;margin-bottom:14px;padding:14px 16px}
.nzwb-rcard h4{margin:0 0 10px;color:var(--navy);font-size:14px}
.nzwb-warnline{display:flex;align-items:center;justify-content:space-between;gap:8px;background:var(--amberBg);border:1px solid var(--amberLine);border-radius:8px;padding:9px 12px;font-size:13px;color:var(--body)}
.nzwb-calmline{background:var(--greenBg);border-radius:8px;padding:9px 12px;font-size:13px;color:var(--green)}
.nzwb-stat{display:flex;justify-content:space-between;padding:7px 0;font-size:13.5px}
.nzwb-stat b{color:var(--ink)}
.nzwb-rcap{color:var(--sec);font-size:11.5px;margin-top:6px;line-height:1.6}
.nzwb-quick{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.nzwb-quick a{display:block;text-align:center;border:1px solid var(--line);border-radius:8px;padding:10px 0;color:var(--navy);font-size:13px;text-decoration:none;background:var(--bg2)}
.nzwb-quick a:hover{border-color:var(--navy)}

/* 空态横幅 */
.nzwb-banner-empty{background:#fff;border:1px dashed var(--line);border-radius:12px;padding:26px 20px;text-align:center;color:var(--body);font-size:14px;margin-bottom:14px}
.nzwb-banner-empty .zzz{font-size:26px;color:var(--sec);display:block;margin-bottom:8px}

/* 叫车抽屉(复用订单列表页同款·W3) */
body.nz-dispatch-lock { overflow: hidden; }
.nz-dispatch-drawer { position: fixed; inset: 0; z-index: 11050; display: none; }
.nz-dispatch-drawer.nz-open { display: block; }
.nz-dispatch-backdrop { position: absolute; inset: 0; background: rgba(16,24,40,.45); }
.nz-dispatch-sheet { position: absolute; left: 0; right: 0; bottom: 0; background: #fff; border-radius: 16px 16px 0 0; max-height: 88vh; overflow-y: auto; box-shadow: 0 -4px 24px rgba(16,24,40,.18); }
.nz-dispatch-grip { width: 38px; height: 4px; border-radius: 99px; background: #D8DEE7; margin: 8px auto 2px; }
.nz-dispatch-head { position: sticky; top: 0; background: #fff; display: flex; align-items: center; justify-content: space-between; padding: 6px 16px 12px; border-bottom: 1px solid #EEF0F3; z-index: 1; }
.nz-dispatch-title { font-weight: 800; font-size: 15px; color: #17191D; }
.nz-dispatch-x { border: 0; background: transparent; font-size: 24px; line-height: 1; color: #8A9099; cursor: pointer; padding: 0 4px; }
.nz-dispatch-body { padding: 4px 16px 20px; }
@media (min-width: 768px) {
    .nz-dispatch-sheet { left: 50%; top: 50%; right: auto; bottom: auto; transform: translate(-50%, -50%); width: 460px; max-width: 92vw; border-radius: 16px; max-height: 84vh; }
    .nz-dispatch-grip { display: none; }
}
</style>
@endpush

@section('content')

<div class="content container-fluid">
<div class="nzwb">

    {{-- W4: 新出现的队列行高亮(6s 心跳换入 _body 后 JS 给新行加 .nzwb-new) --}}
    <style>
    @keyframes nzwbNewFlash { 0%{background:#FFF7E6} 62%{background:#FFF7E6} 100%{background:transparent} }
    .nzwb-row.nzwb-new{ animation: nzwbNewFlash 2.4s ease-out; }
    </style>

    {{-- 可刷新分区: index @include 与 vendor/workbench/refresh 端点共用 _body 同一 Blade(单一真相源·防第二套渲染)。全局 6s 心跳换其 innerHTML。 --}}
    <div id="nzwbRefresh">
        @include('vendor-views.workbench._body', ['wb' => $wb, 'dispatchOrders' => $dispatchOrders])
    </div>

    {{-- 叫车抽屉外壳(静态·在 #nzwbRefresh 刷新区之外; 仅 #nzDispatchBody 内容由 JS 换入·6s 刷新不误抹开着的抽屉) --}}
    <div class="nz-dispatch-drawer d-print-none" id="nzDispatchDrawer" aria-hidden="true">
        <div class="nz-dispatch-backdrop" data-nz-dispatch-close></div>
        <div class="nz-dispatch-sheet" role="dialog" aria-modal="true" aria-labelledby="nzDispatchTitle">
            <div class="nz-dispatch-grip"></div>
            <div class="nz-dispatch-head">
                <div class="nz-dispatch-title" id="nzDispatchTitle">🛵 Yandex Go 配送</div>
                <button type="button" class="nz-dispatch-x" data-nz-dispatch-close aria-label="关闭">&times;</button>
            </div>
            <div class="nz-dispatch-body" id="nzDispatchBody"></div>
        </div>
    </div>

</div>
</div>
@endsection

@push('script_2')
<script>
    "use strict";
    (function () {
        // W3(改为 refresh-safe·W4): 叫车底部抽屉 —— 节点每次现查(getElementById), 防 6s 心跳换入 #nzwbRefresh 后引用失效;
        // 暴露 window.nzwbDrawerOpen() 供心跳避让(抽屉开着时不换 DOM)。交互本身不变(复用订单列表页同款 _dispatch_tools)。
        function el(id){ return document.getElementById(id); }
        var openId = null;
        window.nzwbDrawerOpen = function(){ return openId != null; };
        function stow(){
            if (openId != null) {
                var s = el('nzDispatchSrc-' + openId);
                var holder = el('nzDispatchHolder');
                if (s && holder) { s.style.display = 'none'; holder.appendChild(s); }
                openId = null;
            }
        }
        function openDrawer(id){
            var src = el('nzDispatchSrc-' + id);
            var body = el('nzDispatchBody');
            var drawer = el('nzDispatchDrawer');
            if (!src || !body || !drawer) return;
            stow();
            body.appendChild(src);
            src.style.display = 'block';
            body.scrollTop = 0;
            openId = id;
            var title = el('nzDispatchTitle');
            if (title) title.textContent = '🛵 Yandex Go 配送 · 订单 #' + id;
            drawer.classList.add('nz-open');
            drawer.setAttribute('aria-hidden', 'false');
            document.body.classList.add('nz-dispatch-lock');
        }
        function closeDrawer(){
            var drawer = el('nzDispatchDrawer');
            stow();
            if (drawer) { drawer.classList.remove('nz-open'); drawer.setAttribute('aria-hidden', 'true'); }
            document.body.classList.remove('nz-dispatch-lock');
        }
        document.addEventListener('click', function(e){
            var t = e.target;
            if (!t || !t.closest) return;
            var opener = t.closest('.nz-dispatch-open');
            if (opener) { e.preventDefault(); openDrawer(opener.getAttribute('data-nz-dispatch')); return; }
            if (t.closest('[data-nz-dispatch-close]')) { e.preventDefault(); closeDrawer(); }
        });
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && openId != null) closeDrawer(); });
    })();

    (function () {
        // W4: 作业台并入全局 6s 心跳(app.blade poll 成功 → window.nzHeartbeat.fire)。不另开轮询。
        // 只做「换入最新 _body HTML + 高亮新出现的行」; 响铃/新单去重仍归 app.blade 全局 poll 独占(防漏单/防双响)。
        var REFRESH_URL = '{{ route('vendor.workbench.refresh') }}';
        var inFlight = false;
        function collectOids(zone){
            var s = {};
            zone.querySelectorAll('.nzwb-row .oid').forEach(function(o){ s[o.textContent.trim()] = 1; });
            return s;
        }
        function refresh(){
            if (inFlight) return;
            if (document.hidden) return;                                     // 不在场不刷(在场感知)
            if (window.nzwbDrawerOpen && window.nzwbDrawerOpen()) return;     // 抽屉开着时避让, 不换 DOM
            var zone = el2();
            if (!zone) return;
            inFlight = true;
            var prev = collectOids(zone);
            fetch(REFRESH_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function(r){ return r.ok ? r.text() : Promise.reject(); })
                .then(function(html){
                    var z = el2();
                    if (!z) return;
                    if (window.nzwbDrawerOpen && window.nzwbDrawerOpen()) return;   // 拉取期间抽屉被打开 → 放弃换入
                    z.innerHTML = html;
                    z.querySelectorAll('.nzwb-row').forEach(function(row){
                        var o = row.querySelector('.oid');
                        if (o && !prev[o.textContent.trim()]) row.classList.add('nzwb-new');   // 新出现的行高亮
                    });
                })
                .catch(function(){})
                .then(function(){ inFlight = false; });
        }
        function el2(){ return document.getElementById('nzwbRefresh'); }
        window.nzwbRefreshNow = refresh;                                      // W5: 店态切换成功后立即从服务器 re-sync(与心跳同一函数)
        if (window.nzHeartbeat) window.nzHeartbeat.on(refresh);              // 订阅全局 6s 心跳
        document.addEventListener('visibilitychange', function(){ if (!document.hidden) refresh(); });  // 回到标签页即刷一次
    })();

    (function () {
        // W5: 店态胶囊 —— 点击切换 营业中/暂停接单(复用 update-active-status·翻转 nezha_temp_closed·同门店页写路径)+二次确认+后端留痕。
        // 胶囊在 #nzwbRefresh 内(每 6s 心跳重渲染), 故事件委托; 成功后乐观翻转 + 立即刷新从服务器 re-sync(单一真相源)。
        var TOGGLE_URL = '{{ route('vendor.business-settings.update-active-status') }}';
        var busy = false;
        document.addEventListener('click', function (e) {
            var cap = (e.target && e.target.closest) ? e.target.closest('.nzwb-store') : null;
            if (!cap || busy) return;
            e.preventDefault();
            var paused = cap.getAttribute('data-nz-store') === 'paused';
            var msg = paused
                ? '恢复营业？顾客将可以重新对本店下单。'
                : '暂停接单？暂停后顾客无法对本店下单（店铺仍显示、标「休息中」），进行中的订单不受影响。';
            if (!window.confirm(msg)) return;
            busy = true; cap.style.opacity = '.6';
            $.get(TOGGLE_URL).done(function () {
                var nowPaused = !paused;
                cap.setAttribute('data-nz-store', nowPaused ? 'paused' : 'open');       // 乐观翻转(即时反馈)
                cap.classList.toggle('paused', nowPaused);
                cap.innerHTML = '<span class="dot"></span>' + (nowPaused ? '暂停接单' : '营业中');
                if (window.nzwbRefreshNow) window.nzwbRefreshNow();                      // 立即从服务器 re-sync
            }).fail(function () {
                window.alert('切换失败，请重试');
            }).always(function () {
                busy = false; cap.style.opacity = '';
            });
        });
    })();
</script>
@endpush
