<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title', '商户管理') · 哪吒</title>
<style>
:root{--nz-ink:#102A4C;--nz-ink2:#1c3a63;--nz-line:#e3e8f0;--nz-muted:#6b7c99;}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'PingFang SC','Microsoft YaHei','Noto Sans SC',sans-serif;
 background:linear-gradient(160deg,#0e2440 0%,#153153 55%,#0e2440 100%);min-height:100vh;color:var(--nz-ink);
 display:flex;align-items:flex-start;justify-content:center;padding:32px 16px 48px;line-height:1.5}
.nzm-wrap{width:100%;max-width:460px}
.nzm-brand{display:flex;align-items:center;gap:7px;color:#cfe0ff;font-size:13px;letter-spacing:.5px;margin:0 0 16px;justify-content:center}
.nzm-brand b{color:#fff;font-weight:800;font-size:15px}
.nzm-card{background:#fff;border-radius:18px;padding:26px 22px 22px;box-shadow:0 14px 44px rgba(4,18,40,.30)}
.nzm-title{font-size:21px;font-weight:700;margin:0 0 4px;color:var(--nz-ink)}
.nzm-sub{font-size:13.5px;color:var(--nz-muted);margin:0 0 18px}
.nzm-form{display:flex;flex-direction:column;gap:14px;margin-top:6px}
.nzm-label{display:flex;flex-direction:column;gap:6px;font-size:13px;color:var(--nz-ink2);font-weight:600}
.nzm-input{appearance:none;border:1.5px solid var(--nz-line);border-radius:11px;padding:12px 13px;font-size:15px;color:var(--nz-ink);background:#fbfcfe;transition:border-color .15s;width:100%}
.nzm-input:focus{outline:none;border-color:var(--nz-ink);background:#fff}
.nzm-check{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--nz-muted);font-weight:500}
.nzm-check input{width:16px;height:16px}
.nzm-btn{margin-top:4px;border:none;border-radius:11px;padding:13px;font-size:15.5px;font-weight:700;color:#fff;background:var(--nz-ink);cursor:pointer;width:100%;transition:background .15s}
.nzm-btn:hover{background:var(--nz-ink2)}
.nzm-btn-ghost{background:transparent;color:var(--nz-ink);border:1.5px solid var(--nz-line)}
.nzm-btn-ghost:hover{background:#f4f6fa}
.nzm-links{margin-top:16px;text-align:center;font-size:13px}
.nzm-links a{color:var(--nz-ink2);text-decoration:none;border-bottom:1px solid #c7d3e6;padding-bottom:1px}
.nzm-alert{border-radius:11px;padding:11px 13px;font-size:13.5px;margin-bottom:16px}
.nzm-alert div+div{margin-top:3px}
.nzm-alert-err{background:#fdecec;color:#b3261e;border:1px solid #f5c6c4}
.nzm-alert-ok{background:#e8f5ec;color:#1b7a3d;border:1px solid #bfe3ca}
.nzm-foot{text-align:center;color:#9db3d6;font-size:11.5px;margin-top:18px}
@yield('head_extra')
</style>
@stack('head')
</head>
<body>
<div class="nzm-wrap">
  <div class="nzm-brand"><b>哪吒</b><span>商户管理</span></div>
  <div class="nzm-card">
    @if($errors->any())
      <div class="nzm-alert nzm-alert-err">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
      </div>
    @endif
    @if(session('status'))<div class="nzm-alert nzm-alert-ok">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="nzm-alert nzm-alert-err">{{ session('error') }}</div>@endif
    @yield('content')
  </div>
  <div class="nzm-foot">哪吒平台 · 本地生活商户自助维护</div>
</div>
@stack('scripts')
</body>
</html>
