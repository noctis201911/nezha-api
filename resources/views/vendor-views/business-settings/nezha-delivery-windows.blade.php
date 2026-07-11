@extends('layouts.vendor.app')

@section('title', '配送时段')

{{-- 哪吒 预约下单 M5 前端 · 配送时段配置页(mockup 02)·商家端方向A「浅白专业」DS §19(墨 #1F2329 accent·状态五族)。
     后端 CRUD = NezhaDeliveryWindowController(store/toggle/destroy·全总闸门·IDOR)。页面渲染不 gate·mutations 端点 gated。 --}}

@push('css_or_js')
<style>
.nzdw-wrap{max-width:600px;margin:0 auto;color:#1F2329;-webkit-font-smoothing:antialiased}
.nzdw-card{background:#fff;border:1px solid #E7EAEF;border-radius:14px;box-shadow:0 1px 3px rgba(23,28,38,.04);padding:14px 16px;margin-bottom:12px}
.nzdw-ct{font-size:15px;font-weight:600;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.nzdw-cd{font-size:13px;color:#5A6069;line-height:1.6;margin-top:5px}
.nzdw-chip{display:inline-flex;align-items:center;font-size:11px;font-weight:600;padding:3px 8px;border-radius:999px}
.nzdw-g{background:#E5F1EA;color:#2B7A57}
.nzdw-gray{background:#EFF1F3;color:#6B7280}
.nzdw-days{display:flex;gap:8px;overflow-x:auto;padding:2px 0 6px;margin-bottom:8px;-webkit-overflow-scrolling:touch}
.nzdw-day{min-width:56px;padding:7px 6px 6px;border-radius:12px;background:#fff;border:1.5px solid #E7EAEF;text-align:center;font-size:13px;font-weight:600;color:#5A6069;flex:none;cursor:pointer;user-select:none}
.nzdw-day small{display:block;font-size:10px;font-weight:500;color:#9AA0A8;margin-top:2px}
.nzdw-day.nzdw-sel{background:#1F2329;border-color:#1F2329;color:#fff}
.nzdw-day.nzdw-sel small{color:rgba(255,255,255,.72)}
.nzdw-day.nzdw-off small{color:#C9CDD2}
.nzdw-hours{display:flex;align-items:center;gap:6px;font-size:12px;color:#9AA0A8;padding:0 2px 10px}
.nzdw-win{display:flex;align-items:center;gap:12px;background:#fff;border:1.5px solid #E7EAEF;border-radius:14px;box-shadow:0 1px 3px rgba(23,28,38,.04);padding:12px 14px;margin-bottom:10px}
.nzdw-wt{flex:1;min-width:0}
.nzdw-wtime{font-size:15px;font-weight:600;font-variant-numeric:tabular-nums;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.nzdw-winoff .nzdw-wtime{color:#9AA0A8}
.nzdw-wcap{display:flex;align-items:center;gap:4px;font-size:11px;color:#9AA0A8;margin-top:4px}
.nzdw-pchip{display:inline-flex;font-size:10px;font-weight:600;color:#6B7280;background:#EFF1F3;border-radius:999px;padding:2px 7px;align-items:center;gap:3px}
.nzdw-sw{width:44px;height:26px;border-radius:13px;background:#C9CDD2;position:relative;flex:none;cursor:pointer;transition:background .15s}
.nzdw-sw:after{content:'';position:absolute;width:22px;height:22px;border-radius:50%;background:#fff;top:2px;left:2px;box-shadow:0 1px 3px rgba(0,0,0,.22);transition:left .15s}
.nzdw-sw.nzdw-on{background:#1F2329}
.nzdw-sw.nzdw-on:after{left:20px}
.nzdw-tdel{width:36px;height:36px;border-radius:10px;background:#F0F2F4;color:#5A6069;display:flex;align-items:center;justify-content:center;flex:none;cursor:pointer;border:none}
.nzdw-tdel:hover{background:#E7EAEF}
.nzdw-empty{background:#fff;border:1px dashed #D5DAE1;border-radius:14px;padding:22px 16px;text-align:center;font-size:13px;color:#9AA0A8}
.nzdw-add{width:100%;height:44px;border-radius:12px;border:1.5px solid #1F2329;color:#1F2329;background:#fff;font-size:14px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;margin-top:2px}
.nzdw-add:hover{background:#1F2329;color:#fff}
.nzdw-note{margin-top:14px;background:#fff;border:1px solid #E7EAEF;border-radius:10px;padding:10px 12px;font-size:12px;color:#5A6069;line-height:1.6}
.nzdw-mask{position:fixed;inset:0;background:rgba(23,28,38,.48);z-index:11000;display:flex;align-items:flex-end;justify-content:center}
.nzdw-sheet{width:100%;max-width:600px;background:#fff;border-radius:20px 20px 0 0;padding:8px 20px 26px;box-shadow:0 -8px 32px rgba(23,28,38,.14)}
.nzdw-grab{width:36px;height:4px;border-radius:2px;background:#E0E4EA;margin:4px auto 12px}
.nzdw-sh{display:flex;align-items:center;justify-content:space-between}
.nzdw-sht{font-size:17px;font-weight:600}
.nzdw-shx{width:28px;height:28px;border-radius:50%;background:#F0F2F4;display:flex;align-items:center;justify-content:center;color:#5A6069;cursor:pointer}
.nzdw-grp{font-size:12px;color:#9AA0A8;margin:14px 0 8px;font-weight:500}
.nzdw-qs{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.nzdw-q{height:44px;border:1.5px solid #E7EAEF;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:600;font-variant-numeric:tabular-nums;background:#fff;cursor:pointer}
.nzdw-q.nzdw-qsel{border-color:#1F2329;background:#1F2329;color:#fff}
.nzdw-frow{display:flex;gap:10px}
.nzdw-fld{flex:1;border:1.5px solid #E7EAEF;border-radius:12px;padding:9px 12px;background:#fff;display:block}
.nzdw-fl{font-size:11px;color:#9AA0A8;display:block}
.nzdw-fld input{border:none;outline:none;font-size:15px;font-weight:600;font-variant-numeric:tabular-nums;width:100%;background:transparent;color:#1F2329;padding:2px 0 0}
.nzdw-fhelp{font-size:11px;color:#9AA0A8;margin-top:6px;line-height:1.55}
.nzdw-sbtn{margin-top:16px;width:100%;height:48px;border-radius:12px;background:#1F2329;color:#fff;font-size:15px;font-weight:600;border:none;cursor:pointer}
.nzdw-sbtn:disabled{background:#C9CDD2;cursor:not-allowed}
.nzdw-modecard{cursor:pointer}
.nzdw-modehead{display:flex;align-items:center;justify-content:space-between;gap:10px}
.nzdw-modelabel{font-size:12px;color:#9AA0A8}
.nzdw-modeval{font-size:16px;font-weight:600;display:flex;align-items:center;gap:8px;margin-top:2px}
.nzdw-modechev{font-size:13px;font-weight:600;color:#1F2329;white-space:nowrap}
.nzdw-modehint{font-size:12px;color:#5A6069;line-height:1.55;margin-top:8px}
.nzdw-b{background:#ECEAF7;color:#5B54A8}
.nzdw-opts{display:flex;flex-direction:column;gap:10px;margin-top:6px}
.nzdw-opt{display:flex;gap:12px;align-items:flex-start;border:1.5px solid #E7EAEF;border-radius:14px;background:#fff;padding:13px 14px;cursor:pointer}
.nzdw-opt.nzdw-optsel{border-color:#1F2329}
.nzdw-oicon{width:38px;height:38px;border-radius:12px;background:#F0F2F4;color:#5A6069;display:flex;align-items:center;justify-content:center;flex:none;font-size:18px}
.nzdw-opt.nzdw-optsel .nzdw-oicon{background:#1F2329;color:#fff}
.nzdw-obody{flex:1;min-width:0}
.nzdw-ot{font-size:15px;font-weight:600;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.nzdw-ochip{font-size:11px;font-weight:600;color:#5B54A8;background:#ECEAF7;border-radius:999px;padding:3px 8px}
.nzdw-od{font-size:13px;color:#5A6069;line-height:1.55;margin-top:3px}
.nzdw-radio{width:20px;height:20px;border-radius:50%;border:1.7px solid #C9CDD2;flex:none;margin-top:2px;background:#fff}
.nzdw-opt.nzdw-optsel .nzdw-radio{border:6px solid #1F2329}
.nzdw-alert{margin-top:10px;background:#fff;border:1px solid #E7EAEF;border-left:3px solid #D9A521;border-radius:10px;padding:11px 13px}
.nzdw-alt{font-size:13px;font-weight:600;color:#1F2329}
.nzdw-ald{font-size:12px;color:#5A6069;line-height:1.55;margin-top:4px}
</style>
@endpush

@section('content')
@php
    $dayNames = ['周日','周一','周二','周三','周四','周五','周六'];
    $hoursJson = [];
    foreach (range(0, 6) as $d) {
        $hoursJson[$d] = collect($hoursByDay[$d] ?? [])
            ->map(fn($h) => [substr($h->opening_time, 0, 5), substr($h->closing_time, 0, 5)])->values();
    }
@endphp
<div class="content container-fluid">
  <div class="nzdw-wrap">
    @php $modeNames = ['instant'=>'即时接单','instant_preorder'=>'即时 + 预约','preorder_only'=>'只接预约']; @endphp
    <div class="nzdw-card nzdw-modecard" id="nzdwModeOpen" role="button" tabindex="0">
      <div class="nzdw-modehead">
        <div>
          <div class="nzdw-modelabel">接单模式</div>
          <div class="nzdw-modeval"><span>{{ $modeNames[$currentMode] ?? '即时接单' }}</span>
            @if($currentMode==='preorder_only')<span class="nzdw-chip nzdw-b">只接预约</span>@elseif($currentMode==='instant_preorder')<span class="nzdw-chip nzdw-b">开放预约</span>@endif
          </div>
        </div>
        <span class="nzdw-modechev">切换 ›</span>
      </div>
      <div class="nzdw-modehint">决定顾客能怎么向您下单 · 与「忙碌 / 暂停接单」互不影响</div>
    </div>
    <div class="nzdw-card">
      <div class="nzdw-ct">预约配送时段
        @if($preorderOn)
          <span class="nzdw-chip nzdw-g">预约模式生效中</span>
        @else
          <span class="nzdw-chip nzdw-gray">预约功能未开放</span>
        @endif
      </div>
      <div class="nzdw-cd">顾客结算时只能从这里的时段中选择;时段仅影响预约单,即时单不受影响。</div>
    </div>

    <div class="nzdw-days" id="nzdwDays">
      @foreach(range(0,6) as $d)
        @php $cnt = isset($windowsByDay[$d]) ? count($windowsByDay[$d]) : 0; @endphp
        <span class="nzdw-day @if($cnt==0) nzdw-off @endif" data-day="{{ $d }}">{{ $dayNames[$d] }}<small>{{ $cnt>0 ? $cnt.' 时段' : '未设置' }}</small></span>
      @endforeach
    </div>

    <div class="nzdw-hours" id="nzdwHours"></div>

    @foreach(range(0,6) as $d)
      <div class="nzdw-panel" data-day="{{ $d }}" hidden>
        @forelse(($windowsByDay[$d] ?? []) as $w)
          <div class="nzdw-win @if(!$w->active) nzdw-winoff @endif" data-id="{{ $w->id }}">
            <div class="nzdw-wt">
              <div class="nzdw-wtime">{{ substr($w->start_time,0,5) }}–{{ substr($w->end_time,0,5) }}
                @if(!$w->active)<span class="nzdw-chip nzdw-gray">已暂停</span>@endif
              </div>
              <div class="nzdw-wcap">接单上限:不限 <span class="nzdw-pchip">Phase 2 开放</span></div>
            </div>
            <span class="nzdw-sw @if($w->active) nzdw-on @endif" data-toggle-id="{{ $w->id }}" role="button" aria-label="启停时段"></span>
            <button type="button" class="nzdw-tdel" data-del-id="{{ $w->id }}" aria-label="删除时段">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 7V5h4v2M6.5 7l1 13h9l1-13"/><path d="M10 11v6M14 11v6"/></svg>
            </button>
          </div>
        @empty
          <div class="nzdw-empty">该日暂无配送时段,点下方「添加时段」新增。</div>
        @endforelse
      </div>
    @endforeach

    <button type="button" class="nzdw-add" id="nzdwAdd">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>添加时段
    </button>
    <div class="nzdw-note">「单时段接单上限」用于防爆单,Phase 2 开放;当前不限量,请按备货能力设置时段数量。</div>
  </div>
</div>

<div class="nzdw-mask" id="nzdwModeMask" hidden>
  <div class="nzdw-sheet">
    <div class="nzdw-grab"></div>
    <div class="nzdw-sh"><span class="nzdw-sht">接单模式</span><span class="nzdw-shx" id="nzdwModeClose">✕</span></div>
    <div class="nzdw-modehint" style="margin:3px 0 12px">决定顾客能怎么向您下单 · 随时可改</div>
    <form method="post" action="{{ route('vendor.business-settings.nezha-accept-mode') }}" id="nzdwModeForm">
      @csrf
      <input type="hidden" name="mode" id="nzdwModeInput" value="{{ $currentMode }}">
      <div class="nzdw-opts">
        <div class="nzdw-opt @if($currentMode==='instant') nzdw-optsel @endif" data-mode="instant">
          <span class="nzdw-oicon">⚡</span>
          <div class="nzdw-obody"><div class="nzdw-ot">即时接单</div><div class="nzdw-od">只接「现在送」的即时单,需要实时接单、实时备餐(平台默认)。</div></div>
          <span class="nzdw-radio"></span>
        </div>
        <div class="nzdw-opt @if($currentMode==='instant_preorder') nzdw-optsel @endif" data-mode="instant_preorder">
          <span class="nzdw-oicon">🗓</span>
          <div class="nzdw-obody"><div class="nzdw-ot">即时 + 预约</div><div class="nzdw-od">即时单照常接,同时开放预约时段,顾客可任选「现在送」或「预约送」。</div></div>
          <span class="nzdw-radio"></span>
        </div>
        <div class="nzdw-opt @if($currentMode==='preorder_only') nzdw-optsel @endif" data-mode="preorder_only">
          <span class="nzdw-oicon">📦</span>
          <div class="nzdw-obody"><div class="nzdw-ot">只接预约 <span class="nzdw-ochip">适合超市 / 百货</span></div><div class="nzdw-od">顾客必须选择配送时段下单;您可按时段集中备货、集中叫车,<b style="color:#1F2329">不用守店</b>。</div></div>
          <span class="nzdw-radio"></span>
        </div>
      </div>
      <div class="nzdw-alert" id="nzdwModeAlert" hidden>
        <div class="nzdw-alt">⚠ 还没有配送时段</div>
        <div class="nzdw-ald">「即时+预约」或「只接预约」需要至少 1 个配送时段才能生效,顾客才有时段可选。请先在下方添加配送时段。</div>
      </div>
      <button type="submit" class="nzdw-sbtn" id="nzdwModeSave">保存接单模式</button>
    </form>
  </div>
</div>

<div class="nzdw-mask" id="nzdwMask" hidden>
  <div class="nzdw-sheet">
    <div class="nzdw-grab"></div>
    <div class="nzdw-sh"><span class="nzdw-sht">添加时段 · <span id="nzdwSheetDay"></span></span><span class="nzdw-shx" id="nzdwClose">✕</span></div>
    <div class="nzdw-grp">常用时段</div>
    <div class="nzdw-qs" id="nzdwQs">
      <span class="nzdw-q" data-s="10:00" data-e="12:00">10:00–12:00</span>
      <span class="nzdw-q" data-s="12:00" data-e="14:00">12:00–14:00</span>
      <span class="nzdw-q" data-s="14:00" data-e="16:00">14:00–16:00</span>
      <span class="nzdw-q" data-s="18:00" data-e="20:00">18:00–20:00</span>
    </div>
    <div class="nzdw-grp">自定义</div>
    <div class="nzdw-frow">
      <label class="nzdw-fld"><span class="nzdw-fl">开始</span><input type="time" id="nzdwStart"></label>
      <label class="nzdw-fld"><span class="nzdw-fl">结束</span><input type="time" id="nzdwEnd"></label>
    </div>
    <div class="nzdw-fhelp">时段须在当天营业时间内。上限用于防爆单,Phase 2 开放设置;当前默认不限量。</div>
    <button type="button" class="nzdw-sbtn" id="nzdwSave">添加时段</button>
  </div>
</div>

<script>
(function () {
  var HOURS = @json($hoursJson);
  var NAMES = @json($dayNames);
  var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var R_store = "{{ route('vendor.business-settings.nezha-window.store') }}";
  var R_toggle = "{{ route('vendor.business-settings.nezha-window.toggle', ['id' => '__ID__']) }}";
  var R_del = "{{ route('vendor.business-settings.nezha-window.destroy', ['id' => '__ID__']) }}";
  var sel = 0;

  function firstDayWithWindows() {
    var panels = document.querySelectorAll('.nzdw-panel');
    for (var i = 0; i < panels.length; i++) {
      if (panels[i].querySelector('.nzdw-win')) return parseInt(panels[i].getAttribute('data-day'), 10);
    }
    return (new Date()).getDay(); // 0=周日, 对齐 day 约定
  }

  function renderHours(day) {
    var el = document.getElementById('nzdwHours');
    var h = HOURS[day] || [];
    if (!h.length) { el.textContent = NAMES[day] + '当天不营业 · 无法添加配送时段'; return; }
    var parts = h.map(function (b) { return b[0] + '–' + b[1]; }).join('、');
    el.textContent = NAMES[day] + '营业时间 ' + parts + ' · 时段须在营业时间内';
  }

  function selectDay(day) {
    sel = day;
    document.querySelectorAll('.nzdw-day').forEach(function (d) {
      d.classList.toggle('nzdw-sel', parseInt(d.getAttribute('data-day'), 10) === day);
    });
    document.querySelectorAll('.nzdw-panel').forEach(function (p) {
      p.hidden = parseInt(p.getAttribute('data-day'), 10) !== day;
    });
    renderHours(day);
    if (history.replaceState) history.replaceState(null, '', '#day-' + day);
  }

  function nzToast(type, msg) {
    if (typeof toastr !== 'undefined') { toastr[type === 'error' ? 'error' : 'success'](msg); }
    else if (type === 'error') { alert(msg); }
  }

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': CSRF, 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
  }

  function errMsg(res) {
    try { return res.body.errors[0].message; } catch (e) { return '操作失败,请重试'; }
  }

  // 日切换
  document.getElementById('nzdwDays').addEventListener('click', function (e) {
    var d = e.target.closest('.nzdw-day'); if (!d) return;
    selectDay(parseInt(d.getAttribute('data-day'), 10));
  });

  // 启停 + 删除(事件委托)
  document.querySelector('.nzdw-wrap').addEventListener('click', function (e) {
    var sw = e.target.closest('.nzdw-sw');
    if (sw) {
      var tid = sw.getAttribute('data-toggle-id');
      post(R_toggle.replace('__ID__', tid)).then(function (res) {
        if (res.ok) { location.reload(); } else { nzToast('error', errMsg(res)); }
      });
      return;
    }
    var del = e.target.closest('.nzdw-tdel');
    if (del) {
      if (!confirm('确定删除该配送时段?')) return;
      var did = del.getAttribute('data-del-id');
      post(R_del.replace('__ID__', did)).then(function (res) {
        if (res.ok) { location.reload(); } else { nzToast('error', errMsg(res)); }
      });
    }
  });

  // 添加抽屉
  var mask = document.getElementById('nzdwMask');
  function openSheet() {
    document.getElementById('nzdwSheetDay').textContent = NAMES[sel];
    document.getElementById('nzdwStart').value = '';
    document.getElementById('nzdwEnd').value = '';
    document.querySelectorAll('.nzdw-q').forEach(function (q) { q.classList.remove('nzdw-qsel'); });
    mask.hidden = false;
  }
  function closeSheet() { mask.hidden = true; }
  document.getElementById('nzdwAdd').addEventListener('click', openSheet);
  document.getElementById('nzdwClose').addEventListener('click', closeSheet);
  mask.addEventListener('click', function (e) { if (e.target === mask) closeSheet(); });

  document.getElementById('nzdwQs').addEventListener('click', function (e) {
    var q = e.target.closest('.nzdw-q'); if (!q) return;
    document.querySelectorAll('.nzdw-q').forEach(function (x) { x.classList.remove('nzdw-qsel'); });
    q.classList.add('nzdw-qsel');
    document.getElementById('nzdwStart').value = q.getAttribute('data-s');
    document.getElementById('nzdwEnd').value = q.getAttribute('data-e');
  });

  document.getElementById('nzdwSave').addEventListener('click', function () {
    var s = document.getElementById('nzdwStart').value;
    var en = document.getElementById('nzdwEnd').value;
    if (!s || !en) { nzToast('error', '请选择开始与结束时间'); return; }
    var btn = this; btn.disabled = true;
    post(R_store, { day: sel, start_time: s, end_time: en }).then(function (res) {
      btn.disabled = false;
      if (res.ok) { location.hash = '#day-' + sel; location.reload(); }
      else { nzToast('error', errMsg(res)); }
    }).catch(function () { btn.disabled = false; nzToast('error', '网络错误,请重试'); });
  });

  // 初始选中日:URL hash > 首个有时段的日 > 今天
  var m = (location.hash || '').match(/day-(\d)/);
  selectDay(m ? parseInt(m[1], 10) : firstDayWithWindows());
})();

// screen 01 接单模式抽屉(三态·form POST 走 M4 nezha_accept_mode·server 端二次校验 0/0 与「≥1时段」守卫)
(function () {
  var HASWIN = @json($hasActiveWindow);
  var mask = document.getElementById('nzdwModeMask');
  if (!mask) return;
  var opts = document.querySelectorAll('#nzdwModeForm .nzdw-opt');
  var input = document.getElementById('nzdwModeInput');
  var alertEl = document.getElementById('nzdwModeAlert');
  var saveBtn = document.getElementById('nzdwModeSave');

  function refresh() {
    var mode = input.value;
    var block = (mode === 'instant_preorder' || mode === 'preorder_only') && !HASWIN; // 预约模式须 ≥1 时段(mockup01 状态B)
    alertEl.hidden = !block;
    saveBtn.disabled = block;
    saveBtn.textContent = block ? '添加配送时段后即可保存' : '保存接单模式';
  }
  document.getElementById('nzdwModeOpen').addEventListener('click', function () { mask.hidden = false; });
  document.getElementById('nzdwModeClose').addEventListener('click', function () { mask.hidden = true; });
  mask.addEventListener('click', function (e) { if (e.target === mask) mask.hidden = true; });
  opts.forEach(function (o) {
    o.addEventListener('click', function () {
      opts.forEach(function (x) { x.classList.remove('nzdw-optsel'); });
      o.classList.add('nzdw-optsel');
      input.value = o.getAttribute('data-mode');
      refresh();
    });
  });
  refresh();
})();
</script>
@endsection
