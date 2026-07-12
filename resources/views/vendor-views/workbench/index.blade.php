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
.nzwb-cap.busy{color:var(--amber);border-color:var(--amber)}
.nzwb-cap.busy .dot{background:var(--amber)}
/* 店态设置底部抽屉(忙碌模式/定时挂起·灰度开时) */
.nzss-mask{position:fixed;inset:0;z-index:12000;background:rgba(20,25,40,.42);display:flex;align-items:flex-end;justify-content:center}
.nzss-mask[hidden]{display:none}
.nzss{background:#fff;width:100%;max-width:460px;border-radius:18px 18px 0 0;padding:16px 16px 22px;box-shadow:0 -8px 30px rgba(0,0,0,.18);font-family:inherit;max-height:88vh;overflow-y:auto}
@media(min-width:520px){.nzss-mask{align-items:center}.nzss{border-radius:18px}}
.nzss-h{display:flex;align-items:center;justify-content:space-between;font-size:16px;font-weight:700;color:var(--navy);margin-bottom:12px}
.nzss-x{border:none;background:none;font-size:18px;color:var(--sec);cursor:pointer;line-height:1;padding:4px}
.nzss-opt{width:100%;display:flex;align-items:center;gap:11px;border:1.5px solid var(--line);background:#fff;border-radius:13px;padding:13px 14px;font-size:15px;font-weight:600;color:var(--navy);font-family:inherit;cursor:pointer;margin-top:9px;text-align:left}
.nzss-opt small{color:var(--sec);font-weight:400;font-size:12.5px;margin-left:2px}
.nzss-opt.on{border-color:var(--navy);background:var(--bg2)}
.nzss-opt.on[data-nzss-mode=busy]{border-color:var(--amber);background:#FFF7EA}
.nzss-opt.on[data-nzss-mode=pause]{border-color:var(--red);background:#FDEEEB}
.nzss-dot{width:11px;height:11px;border-radius:50%;flex:0 0 auto}
.nzss-dot.open{background:var(--greenCta)} .nzss-dot.busy{background:var(--amber)} .nzss-dot.pause{background:var(--red)}
.nzss-sub{padding:2px 4px 2px 6px}
.nzss-sub[hidden]{display:none}
.nzss-lbl{font-size:12.5px;color:var(--sec);margin:12px 0 7px}
.nzss-chips{display:flex;flex-wrap:wrap;gap:8px}
.nzss-chip{border:1px solid var(--line);background:#fff;color:var(--body);border-radius:999px;padding:7px 14px;font-size:13.5px;font-family:inherit;cursor:pointer}
.nzss-chip.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.nzss-go{width:100%;margin-top:16px;border:none;background:var(--navy);color:#fff;border-radius:12px;padding:13px;font-size:15px;font-weight:700;font-family:inherit;cursor:pointer}
.nzss-go:disabled{opacity:.5;cursor:not-allowed}

/* W6 移动端: 五队列横滑分段控件(桌面隐藏·全展示; ≤600px 只显选中队列 + 全宽 CTA) */
.nzwb-segbar{display:none}
@media (max-width:600px){
  .nzwb-segbar{display:flex;gap:8px;overflow-x:auto;-webkit-overflow-scrolling:touch;padding:0 0 10px;margin:0 0 2px;scrollbar-width:none}
  .nzwb-segbar::-webkit-scrollbar{display:none}
  .nzwb-seg{flex:0 0 auto;border:1px solid var(--line);background:#fff;color:var(--body);border-radius:999px;padding:7px 13px;font-size:13px;font-weight:600;font-family:inherit;white-space:nowrap;cursor:pointer}
  .nzwb-seg b{margin-left:5px;background:var(--chipBg);border-radius:7px;padding:0 6px;font-size:11.5px}
  .nzwb-seg.on{background:var(--navy);color:#fff;border-color:var(--navy)}
  .nzwb-seg.on b{background:rgba(255,255,255,.22);color:#fff}
  .nzwb-maincol .nzwb-qcard{display:none}
  .nzwb-maincol .nzwb-qcard.nzwb-seg-active{display:block}
  .nzwb-row{flex-wrap:wrap}
  .nzwb-row .nzwb-act{width:100%;margin-top:10px}
  .nzwb-row .nzwb-act > .nzwb-btn:not(.more){flex:1}
  .nzwb-row .nzwb-act form{flex:1}
  .nzwb-row .nzwb-act form .nzwb-btn{width:100%}
}
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
.nz-dispatch-sheet { position: absolute; left: 0; right: 0; bottom: 0; top: auto; background: #fff; border-radius: 16px 16px 0 0; height: 88vh; max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 -8px 40px rgba(10,25,47,.4); }
.nz-dispatch-grip { flex: 0 0 auto; width: 44px; height: 4px; border-radius: 99px; background: #D6DBE1; margin: 8px auto 2px; }
.nz-dispatch-body { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
@media (min-width: 768px) {
    .nz-dispatch-sheet { left: auto; right: 0; top: 0; bottom: 0; transform: none; width: 440px; max-width: 92vw; height: 100vh; max-height: 100vh; border-radius: 0; box-shadow: -12px 0 40px rgba(10,25,47,.35); }
    .nz-dispatch-grip { display: none; }
}

/* ══ screen05 · 集中配送作业台「预约」分区(mockup05·浅白专业 DS§19)。全 nzpo- scoped·令牌走 CSS 变量便于日后与作业台统一迁墨/换色(藏青↔墨仅换 --po-ink)。 ══ */
.nzpo{--po-ink:#1F2329;--po-body:#5A6069;--po-sec:#9AA0A8;--po-line:#E7EAEF;
  --po-a-bg:#F7EFDC;--po-a-fg:#8A6210;--po-a-side:#D9A521;--po-g-bg:#E5F1EA;--po-g-fg:#2B7A57;--po-g-side:#57A984;
  --po-b-bg:#ECEAF7;--po-b-fg:#5B54A8;--po-x-bg:#EFF1F3;--po-x-fg:#6B7280;
  margin:0 0 16px;color:var(--po-ink);font-family:inherit}
.nzpo *{box-sizing:border-box}
.nzpo-head{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.nzpo-h1{font-size:15px;font-weight:700;color:var(--po-ink)}
.nzpo-manage{margin-left:auto;font-size:13px;font-weight:600;color:var(--po-ink);text-decoration:none}
.nzpo-manage:hover{opacity:.7}
.nzpo-sum{font-size:13px;color:var(--po-body);line-height:1.6;padding:0 2px;margin-bottom:10px;font-variant-numeric:tabular-nums}
.nzpo-sum b{font-weight:700;color:var(--po-ink)}
.nzpo-sumr{margin-left:8px}
.nzpo-sumr i{font-style:normal;font-size:12px;color:var(--po-sec);margin-left:6px}
.nzpo-rem{background:#fff;border:1px solid var(--po-line);border-left:3px solid var(--po-a-side);border-radius:12px;padding:12px 14px;display:flex;gap:10px;margin-bottom:12px}
.nzpo-rem-i{font-size:16px;line-height:1.3;flex:none}
.nzpo-rt{font-size:14px;font-weight:600;line-height:1.45;color:var(--po-ink)}
.nzpo-rt b{font-variant-numeric:tabular-nums}
.nzpo-rs{font-size:12px;color:var(--po-body);line-height:1.6;margin-top:3px}
.nzpo-rs b{font-weight:600;color:var(--po-ink);font-variant-numeric:tabular-nums}
.nzpo-h1n{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:var(--po-x-bg);color:var(--po-x-fg);font-size:11px;font-weight:700;font-variant-numeric:tabular-nums}
.nzpo-chip{display:inline-flex;align-items:center;font-size:11px;font-weight:600;padding:3px 8px;border-radius:999px;white-space:nowrap}
.nzpo-chip.c-a{background:var(--po-a-bg);color:var(--po-a-fg)}
.nzpo-chip.c-g{background:var(--po-g-bg);color:var(--po-g-fg)}
.nzpo-chip.c-b{background:var(--po-b-bg);color:var(--po-b-fg)}
.nzpo-dh{display:flex;align-items:baseline;gap:6px;font-size:12px;font-weight:500;color:var(--po-sec);padding:8px 2px 4px}
.nzpo-dh b{font-size:13px;font-weight:700;color:var(--po-ink)}
.nzpo-dh i{font-style:normal;margin-left:auto;font-variant-numeric:tabular-nums}
.nzpo-oc{background:#fff;border:1px solid var(--po-line);border-radius:14px;box-shadow:0 1px 3px rgba(23,28,38,.04);padding:12px 14px;margin-bottom:10px}
.nzpo-oc.hot{border-left:3px solid var(--po-a-side)}
.nzpo-oc.done{border-left:3px solid var(--po-g-side)}
.nzpo-l1{display:flex;align-items:center;gap:7px}
.nzpo-pt{font-size:17px;font-weight:700;font-variant-numeric:tabular-nums;color:var(--po-ink)}
.nzpo-plab{font-size:11px;color:var(--po-sec)}
.nzpo-l1 .nzpo-chip{margin-left:auto}
.nzpo-l2{display:flex;align-items:center;gap:6px;margin-top:6px}
.nzpo-oid{font-size:11px;color:var(--po-sec);font-variant-numeric:tabular-nums}
.nzpo-onm{font-size:13.5px;font-weight:500;color:var(--po-ink)}
.nzpo-cnt{font-size:12px;color:var(--po-body)}
.nzpo-amt{margin-left:auto;font-size:14px;font-weight:700;font-variant-numeric:tabular-nums;color:var(--po-ink)}
.nzpo-l3{display:flex;align-items:center;gap:8px;margin-top:10px;padding-top:10px;border-top:1px solid #F0F2F4}
.nzpo-sug{font-size:12px;color:var(--po-body);line-height:1.5}
.nzpo-sug b{font-weight:700;color:var(--po-ink);font-variant-numeric:tabular-nums}
.nzpo-due{font-weight:600;color:var(--po-a-fg)}
.nzpo-dnote{font-size:12px;color:var(--po-sec);font-variant-numeric:tabular-nums}
.nzpo-oc.done .nzpo-pt,.nzpo-oc.done .nzpo-onm,.nzpo-oc.done .nzpo-amt{color:var(--po-sec)}
.nzpo-call{margin-left:auto;height:34px;border:1.5px solid var(--po-ink);background:#fff;color:var(--po-ink);border-radius:10px;padding:0 16px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center}
.nzpo-tip{font-size:12px;color:var(--po-sec);text-align:center;line-height:1.6;padding:6px 10px 0}
.nzpo-empty{background:#fff;border:1px solid var(--po-line);border-radius:14px;padding:34px 24px;text-align:center;display:flex;flex-direction:column;align-items:center}
.nzpo-eic{font-size:32px;margin-bottom:10px}
.nzpo-e1{font-size:15px;font-weight:600;color:var(--po-ink)}
.nzpo-e2{font-size:13px;color:var(--po-sec);line-height:1.7;margin-top:6px}
.nzpo-ebtn{margin-top:16px;height:40px;padding:0 20px;border-radius:11px;border:1.5px solid var(--po-ink);color:var(--po-ink);font-size:13px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center}
</style>
@endpush

@section('content')

<div class="content container-fluid">
<div class="nzwb">

    {{-- 哪吒 P3 常开设备接单: 指引入口(小字·常显·可选·把闲置设备设成常开接单更省心) --}}
    <div style="text-align:right;margin:-2px 0 8px">
        <a href="{{ route('vendor.workbench.guide') }}" style="font-size:12.5px;color:#9AA0A8;text-decoration:none;font-family:inherit">新订单提醒设置 ›</a>
    </div>

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
        <div class="nz-dispatch-sheet" role="dialog" aria-modal="true" aria-label="Yandex Go 配送">
            <div class="nz-dispatch-grip"></div>
            <div class="nz-dispatch-body" id="nzDispatchBody"></div>
        </div>
    </div>

    {{-- 店态设置抽屉(忙碌模式/定时挂起·静态·在 #nzwbRefresh 之外; 灰度总闸开才渲染) --}}
    @if((bool)($wb['store']['mode_enabled'] ?? false))
    <div id="nzStoreSheet" class="nzss-mask" hidden>
        <div class="nzss" role="dialog" aria-modal="true" aria-label="设置营业状态">
            <div class="nzss-h"><span>设置营业状态</span><button type="button" class="nzss-x" data-nzss-close>✕</button></div>
            <button type="button" class="nzss-opt" data-nzss-mode="open"><span class="nzss-dot open"></span>营业中<small>正常接单</small></button>
            <button type="button" class="nzss-opt" data-nzss-mode="busy"><span class="nzss-dot busy"></span>忙碌中<small>仍接单 · 提示顾客出餐较慢</small></button>
            <div class="nzss-sub" data-nzss-sub="busy" hidden>
                <div class="nzss-lbl">原因</div>
                <div class="nzss-chips" data-nzss-reasons>
                    <button type="button" class="nzss-chip on" data-r="peak">高峰繁忙</button>
                    <button type="button" class="nzss-chip" data-r="prep">正在备料</button>
                    <button type="button" class="nzss-chip" data-r="short">人手紧张</button>
                </div>
                <div class="nzss-lbl">出餐约需</div>
                <div class="nzss-chips" data-nzss-busymin>
                    <button type="button" class="nzss-chip" data-m="15">15 分钟</button>
                    <button type="button" class="nzss-chip on" data-m="30">30 分钟</button>
                    <button type="button" class="nzss-chip" data-m="45">45 分钟</button>
                    <button type="button" class="nzss-chip" data-m="60">60 分钟</button>
                </div>
            </div>
            <button type="button" class="nzss-opt" data-nzss-mode="pause"><span class="nzss-dot pause"></span>暂停接单<small>顾客暂时下不了单 · 到点自动恢复</small></button>
            <div class="nzss-sub" data-nzss-sub="pause" hidden>
                <div class="nzss-lbl">多久后自动恢复接单</div>
                <div class="nzss-chips" data-nzss-pausemin>
                    <button type="button" class="nzss-chip on" data-m="15">15 分钟</button>
                    <button type="button" class="nzss-chip" data-m="30">30 分钟</button>
                    <button type="button" class="nzss-chip" data-m="60">60 分钟</button>
                    <button type="button" class="nzss-chip" data-m="0">不定时（手动恢复）</button>
                </div>
            </div>
            <button type="button" class="nzss-go" data-nzss-confirm disabled>确定</button>
        </div>
    </div>
    @endif

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
            var sc = src.querySelector('.nzyx-scroll'); if (sc) sc.scrollTop = 0;
            openId = id;
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
        window.nzwbCloseDrawer = closeDrawer;   // 供 data-nz-ajax 提交成功后先收抽屉再 nzwbRefreshNow(避让心跳换入)
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
                    if (window.nzwbApplySeg) window.nzwbApplySeg();   // W6: 换入后重放移动端分段态(选中队列跨刷新保持)
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
        // W5+: 店态胶囊 —— 灰度关(data-nz-mode-enabled=0)=两档 营业/暂停(旧行为·复用 update-active-status);
        //   灰度开=点开三档抽屉(营业/忙碌/暂停接单 + 时长/原因·走 nezha-store-mode)。胶囊在 6s 心跳区内→事件委托; 成功后 re-sync。
        var TOGGLE_URL = '{{ route('vendor.business-settings.update-active-status') }}';
        var MODE_URL   = '{{ route('vendor.business-settings.nezha-store-mode') }}';
        var busy = false;
        var sheet = document.getElementById('nzStoreSheet');
        var sel = { mode: null, reason: 'peak', busyMin: 30, pauseMin: 15 };

        document.addEventListener('click', function (e) {
            var cap = (e.target && e.target.closest) ? e.target.closest('.nzwb-store') : null;
            if (!cap || busy) return;
            e.preventDefault();
            if (cap.getAttribute('data-nz-mode-enabled') === '1' && sheet) { openSheet(cap); return; }
            legacyToggle(cap);
        });

        function legacyToggle(cap) {
            var paused = cap.getAttribute('data-nz-store') === 'paused';
            var msg = paused
                ? '恢复营业？顾客将可以重新对本店下单。'
                : '暂停接单？暂停后顾客无法对本店下单（店铺仍显示、标「休息中」），进行中的订单不受影响。';
            busy = true;   // 先占位防连点(nzConfirm 非阻塞, 与原生 confirm 不同)
            window.nzConfirm({ body: msg, okText: paused ? '恢复营业' : '暂停接单', danger: !paused }).then(function (ok) {
                if (!ok) { busy = false; return; }
                cap.style.opacity = '.6';
                $.get(TOGGLE_URL).done(function () {
                    if (window.nzwbRefreshNow) window.nzwbRefreshNow();
                }).fail(function () { window.nzToast('切换失败，请重试', 'error'); })
                  .always(function () { busy = false; cap.style.opacity = ''; });
            });
        }

        function openSheet(cap) {
            sel.mode = cap.getAttribute('data-nz-store') || 'open';       // 预选当前态
            if (sel.mode === 'busy') {
                sel.busyMin = parseInt(cap.getAttribute('data-nz-busy-min'), 10) || 30;
                sel.reason = cap.getAttribute('data-nz-busy-reason') || 'peak';
            }
            paint();
            sheet.hidden = false;
        }
        function closeSheet() { if (sheet) sheet.hidden = true; }
        function markChips(q, attr, val) {
            sheet.querySelectorAll(q).forEach(function (c) { c.classList.toggle('on', c.getAttribute(attr) === val); });
        }
        function paint() {
            sheet.querySelectorAll('.nzss-opt').forEach(function (o) { o.classList.toggle('on', o.getAttribute('data-nzss-mode') === sel.mode); });
            sheet.querySelectorAll('.nzss-sub').forEach(function (s) { s.hidden = s.getAttribute('data-nzss-sub') !== sel.mode; });
            markChips('[data-nzss-reasons] .nzss-chip', 'data-r', sel.reason);
            markChips('[data-nzss-busymin] .nzss-chip', 'data-m', String(sel.busyMin));
            markChips('[data-nzss-pausemin] .nzss-chip', 'data-m', String(sel.pauseMin));
            var go = sheet.querySelector('[data-nzss-confirm]'); if (go) go.disabled = !sel.mode;
        }
        if (sheet) {
            sheet.addEventListener('click', function (e) {
                var t = e.target;
                if (t.closest('[data-nzss-close]') || t === sheet) { closeSheet(); return; }
                var opt = t.closest('.nzss-opt'); if (opt) { sel.mode = opt.getAttribute('data-nzss-mode'); paint(); return; }
                var chip = t.closest('.nzss-chip');
                if (chip) {
                    if (chip.hasAttribute('data-r')) sel.reason = chip.getAttribute('data-r');
                    else if (chip.closest('[data-nzss-busymin]')) sel.busyMin = parseInt(chip.getAttribute('data-m'), 10);
                    else if (chip.closest('[data-nzss-pausemin]')) sel.pauseMin = parseInt(chip.getAttribute('data-m'), 10);
                    paint(); return;
                }
                if (t.closest('[data-nzss-confirm]')) submit();
            });
        }
        function submit() {
            if (busy || !sel.mode) return;
            var params = { mode: sel.mode };
            if (sel.mode === 'busy') { params.minutes = sel.busyMin; params.reason = sel.reason; }
            if (sel.mode === 'pause') { params.minutes = sel.pauseMin; }
            busy = true;
            var go = sheet.querySelector('[data-nzss-confirm]'); if (go) { go.disabled = true; go.textContent = '处理中…'; }
            $.get(MODE_URL, params).done(function () {
                closeSheet();
                if (window.nzwbRefreshNow) window.nzwbRefreshNow();
            }).fail(function () { window.nzToast('设置失败，请重试', 'error'); })
              .always(function () { busy = false; if (go) { go.disabled = false; go.textContent = '确定'; } });
        }
    })();

    (function () {
        // W6 移动端: 五队列横滑分段。选中态存 JS(跨 6s 心跳刷新保持); 事件委托(卡/segbar 每次刷新是新节点)。
        var SEGS = ['confirm', 'cooking', 'delivery', 'nudge', 'refund'];
        var active = null;   // null = 采用服务器渲染的默认(第一个非空)
        function isMobile(){ return window.matchMedia && window.matchMedia('(max-width:600px)').matches; }
        function apply(){
            var zone = document.getElementById('nzwbRefresh'); if (!zone) return;
            var cards = zone.querySelectorAll('.nzwb-qcard[data-nzwb-q]'); if (!cards.length) return;
            if (active === null) {
                var pre = zone.querySelector('.nzwb-qcard.nzwb-seg-active[data-nzwb-q]');
                active = pre ? pre.getAttribute('data-nzwb-q') : 'confirm';
            }
            if (!zone.querySelector('.nzwb-qcard[data-nzwb-q="' + active + '"]')) { active = cards[0].getAttribute('data-nzwb-q'); }
            cards.forEach(function (c) { c.classList.toggle('nzwb-seg-active', c.getAttribute('data-nzwb-q') === active); });
            zone.querySelectorAll('.nzwb-seg[data-nzwb-seg]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-nzwb-seg') === active); });
        }
        window.nzwbApplySeg = apply;   // 供 W4 刷新在换入后重放分段态
        document.addEventListener('click', function (e) {
            var b = (e.target && e.target.closest) ? e.target.closest('.nzwb-seg[data-nzwb-seg]') : null;
            if (!b) return;
            active = b.getAttribute('data-nzwb-seg'); apply();
            if (b.scrollIntoView) b.scrollIntoView({ inline: 'center', block: 'nearest' });
        });
        // 横滑切换(仅移动·仅队列区明显水平滑·不拦 segbar 横滚/交互元素/抽屉)
        var tsx = null, tsy = null;
        document.addEventListener('touchstart', function (e) {
            tsx = null;
            if (!isMobile()) return;
            var t0 = e.target;
            if (!t0 || !t0.closest || !t0.closest('.nzwb-maincol')) return;
            if (t0.closest('.nzwb-segbar') || t0.closest('a,button,form,input,textarea,.nzwb-proof')) return;
            var t = e.touches[0]; tsx = t.clientX; tsy = t.clientY;
        }, { passive: true });
        document.addEventListener('touchend', function (e) {
            if (tsx === null) return;
            var t = e.changedTouches[0], dx = t.clientX - tsx, dy = t.clientY - tsy; tsx = null;
            if (Math.abs(dx) < 60 || Math.abs(dx) < Math.abs(dy) * 1.4) return;   // 需明显水平滑
            var i = SEGS.indexOf(active); if (i < 0) i = 0;
            var ni = dx < 0 ? Math.min(SEGS.length - 1, i + 1) : Math.max(0, i - 1);
            if (ni !== i) {
                active = SEGS[ni]; apply();
                var sb = document.querySelector('.nzwb-seg[data-nzwb-seg="' + active + '"]');
                if (sb && sb.scrollIntoView) sb.scrollIntoView({ inline: 'center', block: 'nearest' });
            }
        }, { passive: true });
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply); else apply();
    })();
</script>
@endpush
