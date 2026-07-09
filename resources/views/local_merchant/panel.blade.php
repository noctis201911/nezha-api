<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title', '店铺管理') · 哪吒商户</title>
<style>
:root{--nz-ink:#102A4C;--nz-ink2:#1c3a63;--nz-line:#e6eaf1;--nz-muted:#6b7c99;--nz-bg:#eef1f6;
 --nz-amber:#F3A429;--nz-green:#1b7a3d;--nz-red:#b3261e;}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'PingFang SC','Microsoft YaHei','Noto Sans SC',sans-serif;
 background:var(--nz-bg);color:var(--nz-ink);line-height:1.55;min-height:100vh}
.nzp-top{position:sticky;top:0;z-index:20;background:var(--nz-ink);color:#fff;display:flex;align-items:center;
 justify-content:space-between;gap:10px;padding:12px 16px;box-shadow:0 2px 12px rgba(4,18,40,.18)}
.nzp-top .nzp-store{font-size:15.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:62vw}
.nzp-top .nzp-store small{display:block;font-weight:400;font-size:11px;color:#a9c0e6;margin-top:1px}
.nzp-logout{background:rgba(255,255,255,.12);border:none;color:#fff;font-size:12.5px;font-weight:600;
 padding:7px 12px;border-radius:9px;cursor:pointer}
.nzp-logout:hover{background:rgba(255,255,255,.2)}
.nzp-wrap{max-width:640px;margin:0 auto;padding:16px 14px 40px}
.nzp-card{background:#fff;border-radius:16px;padding:18px 16px;box-shadow:0 6px 20px rgba(16,42,76,.06);margin-bottom:14px}
.nzp-card h2{font-size:15px;font-weight:700;margin:0 0 12px;color:var(--nz-ink);display:flex;align-items:center;gap:7px}
.nzp-h1{font-size:20px;font-weight:800;margin:2px 0 2px}
.nzp-sub{font-size:12.5px;color:var(--nz-muted);margin:0 0 14px}
.nzp-alert{border-radius:12px;padding:12px 14px;font-size:13.5px;margin-bottom:14px}
.nzp-alert div+div{margin-top:3px}
.nzp-alert-err{background:#fdecec;color:var(--nz-red);border:1px solid #f5c6c4}
.nzp-alert-ok{background:#e8f5ec;color:var(--nz-green);border:1px solid #bfe3ca}
.nzp-alert-warn{background:#fff6e6;color:#9a6a12;border:1px solid #f4dca0}
.nzp-badge{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:999px;line-height:1}
.nzp-badge.pend{background:#fff2d9;color:#9a6a12}
.nzp-badge.ok{background:#e2f3e8;color:var(--nz-green)}
.nzp-badge.rej{background:#fbe3e1;color:var(--nz-red)}
.nzp-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:none;border-radius:11px;
 padding:12px 16px;font-size:14.5px;font-weight:700;color:#fff;background:var(--nz-ink);cursor:pointer;text-decoration:none;transition:background .15s}
.nzp-btn:hover{background:var(--nz-ink2)}
.nzp-btn.ghost{background:#fff;color:var(--nz-ink);border:1.5px solid var(--nz-line)}
.nzp-btn.ghost:hover{background:#f4f6fa}
.nzp-btn.block{width:100%}
.nzp-btnrow{display:flex;gap:10px;flex-wrap:wrap}
.nzp-input,.nzp-select,.nzp-textarea{appearance:none;width:100%;border:1.5px solid var(--nz-line);border-radius:10px;
 padding:11px 12px;font-size:15px;color:var(--nz-ink);background:#fbfcfe}
.nzp-input:focus,.nzp-select:focus,.nzp-textarea:focus{outline:none;border-color:var(--nz-ink);background:#fff}
.nzp-field{margin-bottom:14px}
.nzp-field>label{display:block;font-size:12.5px;font-weight:600;color:var(--nz-ink2);margin-bottom:6px}
.nzp-hint{font-size:11.5px;color:var(--nz-muted);margin-top:5px}
.nzp-review-tag{font-size:11px;font-weight:600;color:#9a6a12;background:#fff2d9;padding:1px 7px;border-radius:6px;margin-left:6px}
.nzp-row{display:flex;gap:8px;align-items:center;margin-bottom:8px}
.nzp-row .nzp-input,.nzp-row .nzp-select{padding:9px 10px;font-size:14px}
.nzp-delrow{flex:0 0 auto;width:34px;height:34px;border:1.5px solid #f0d2d0;background:#fdf2f1;color:var(--nz-red);
 border-radius:9px;font-size:18px;line-height:1;cursor:pointer}
.nzp-addrow{background:#eef2f8;color:var(--nz-ink);border:1px dashed #b9c6dd;border-radius:10px;padding:9px 12px;
 font-size:13px;font-weight:600;cursor:pointer;width:100%;margin-top:2px}
.nzp-days{display:flex;flex-wrap:wrap;gap:6px}
.nzp-day{font-size:13px;border:1.5px solid var(--nz-line);border-radius:9px;padding:7px 11px;cursor:pointer;user-select:none;color:var(--nz-ink2)}
.nzp-day input{display:none}
.nzp-day.on{background:var(--nz-ink);color:#fff;border-color:var(--nz-ink)}
.nzp-check{display:flex;align-items:flex-start;gap:9px;font-size:13.5px;color:var(--nz-ink2)}
.nzp-check input{width:18px;height:18px;margin-top:1px;flex:0 0 auto}
.nzp-thumbs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
.nzp-thumbs img{height:52px;border-radius:8px;border:1px solid var(--nz-line)}
.nzp-kv{display:flex;gap:8px;font-size:13.5px;padding:6px 0;border-bottom:1px solid #f1f4f8}
.nzp-kv:last-child{border-bottom:none}
.nzp-kv .k{flex:0 0 76px;color:var(--nz-muted)}
.nzp-kv .v{flex:1;color:var(--nz-ink);word-break:break-word}
.nzp-two{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(max-width:420px){.nzp-two{grid-template-columns:1fr}}
.nzp-foot{text-align:center;color:#9aa8c0;font-size:11px;margin-top:8px}
@yield('head_extra')
</style>
</head>
<body>
<div class="nzp-top">
  <div class="nzp-store">{{ $merchant->name ?? '我的店铺' }}<small>哪吒商户管理 · 信息维护</small></div>
  <form method="POST" action="{{ route('local-merchant.logout') }}">@csrf<button type="submit" class="nzp-logout">退出</button></form>
</div>
<div class="nzp-wrap">
  @if($errors->any())
    <div class="nzp-alert nzp-alert-err">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
  @endif
  @if(session('status'))<div class="nzp-alert nzp-alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="nzp-alert nzp-alert-err">{{ session('error') }}</div>@endif
  @yield('content')
  <div class="nzp-foot">哪吒平台 · 本地生活商户自助维护</div>
</div>
@stack('scripts')
</body>
</html>
