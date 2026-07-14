@extends('layouts.admin.app')

@section('title', '收款地址复核')

@push('css_or_js')
<style>
/* 哪吒 V3 收款地址复核：限定 .nz-par，类名 nzpar- 前缀，复用工作台系 token。 */
.nz-par{
  --nzpar-navy:#102A4C; --nzpar-navy-tint:#E8EEF6;
  --nzpar-bg:#F5F6F8; --nzpar-card:#FFFFFF; --nzpar-line:#E4E7EC; --nzpar-line2:#F0F2F5;
  --nzpar-ink:#1A2233; --nzpar-ink2:#5B6472; --nzpar-ink3:#98A1B0;
  --nzpar-red:#E5484D; --nzpar-red-tint:#FEECEC;
  --nzpar-amber:#D97A08; --nzpar-amber-tint:#FCF1E3;
  --nzpar-green:#2F9E6E; --nzpar-green-tint:#E8F5EF;
  --nzpar-grey-tint:#F1F1F3;
  --nzpar-mono:Consolas,"SF Mono","Cascadia Mono",monospace;
  color:var(--nzpar-ink); max-width:100%; overflow-x:hidden; padding-top:22px;
}
.nz-par button,.nz-par input,.nz-par textarea{font:inherit}
.nz-par .nzpar-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px;flex-wrap:wrap}
.nz-par .nzpar-title h1{margin:0;font-size:21px;display:flex;align-items:center;gap:10px}
.nz-par .nzpar-title h1 i{color:var(--nzpar-ink2);font-size:19px}
.nz-par .nzpar-count{padding:2px 9px;border-radius:7px;background:#E3EAF2;color:#52667E;font:700 13px var(--nzpar-mono)}
.nz-par .nzpar-title p{margin:6px 0 0;color:var(--nzpar-ink2);font-size:12.5px;line-height:1.7;max-width:1080px}
.nz-par .nzpar-sum{background:#fff;border:1px solid var(--nzpar-line);border-radius:12px;padding:13px 18px;display:flex;align-items:center;gap:22px;flex-wrap:wrap}
.nz-par .nzpar-stat{display:flex;align-items:baseline;gap:7px}
.nz-par .nzpar-stat-n{font:700 22px var(--nzpar-mono)}
.nz-par .nzpar-stat-n.nzpar-warn{color:var(--nzpar-amber)}
.nz-par .nzpar-stat-n.nzpar-bad{color:var(--nzpar-red)}
.nz-par .nzpar-stat-n.nzpar-zero{color:var(--nzpar-ink3)}
.nz-par .nzpar-stat-k{font-size:12px;color:var(--nzpar-ink2)}
.nz-par .nzpar-rules{margin-left:auto;text-align:right;font-size:11.5px;color:var(--nzpar-ink3);line-height:1.7}
.nz-par .nzpar-rules b{color:var(--nzpar-ink2);font-weight:600}
.nz-par .nzpar-pill{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:700;border-radius:99px;padding:3px 10px;white-space:nowrap;background:var(--nzpar-green-tint);color:var(--nzpar-green)}
.nz-par .nzpar-pill::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
.nz-par .nzpar-note{margin:11px 2px 0;font-size:11.5px;color:var(--nzpar-ink3);line-height:1.8}
.nz-par .nzpar-note b{color:var(--nzpar-red);font-weight:600}
.nz-par .nzpar-card{margin-top:14px;background:#fff;border:1px solid var(--nzpar-line);border-radius:12px;overflow:hidden}
.nz-par .nzpar-card-head{min-height:56px;padding:10px 18px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--nzpar-line);flex-wrap:wrap}
.nz-par .nzpar-card-head h2{margin:0;font-size:15.5px}
.nz-par .nzpar-tools{margin-left:auto;display:flex;gap:10px;align-items:center}
.nz-par .nzpar-search{width:250px;height:37px;border:1px solid #DCE3EB;border-radius:6px;display:flex;align-items:center;overflow:hidden;background:#fff}
.nz-par .nzpar-search input{flex:1;min-width:0;height:100%;border:0;outline:0;padding:0 12px;color:#40536A;font-size:12.5px}
.nz-par .nzpar-search i{width:40px;text-align:center;color:#50667E}
.nz-par .nzpar-refresh{height:37px;padding:0 14px;border:1px solid var(--nzpar-line);border-radius:6px;background:#fff;color:var(--nzpar-ink2);font-size:12.5px;font-weight:600}
.nz-par .nzpar-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.nz-par .nzpar-table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:980px;margin:0}
.nz-par .nzpar-table th{background:var(--nzpar-bg);color:var(--nzpar-ink2);font-weight:700;font-size:11.5px;text-align:left;padding:10px 12px;border-bottom:1px solid var(--nzpar-line);white-space:nowrap}
.nz-par .nzpar-table td{padding:11px 12px;border-top:1px solid var(--nzpar-line2);vertical-align:middle}
.nz-par .nzpar-table tbody tr:first-child td{border-top:none}
.nz-par .nzpar-table tbody tr:hover{background:#FAFCFF}
.nz-par .nzpar-table tr.nzpar-expired td{background:#FFF7F7}
.nz-par .nzpar-table tr.nzpar-expired td:first-child{box-shadow:inset 3px 0 0 var(--nzpar-red)}
.nz-par .nzpar-merchant{font-weight:600;color:var(--nzpar-ink);font-size:13px}
.nz-par .nzpar-change-id{font:10.5px var(--nzpar-mono);color:var(--nzpar-ink3);margin-top:2px}
.nz-par .nzpar-network{display:inline-flex;align-items:center;gap:7px;font-weight:700;font-size:12px}
.nz-par .nzpar-network::before{content:"";width:8px;height:8px;border-radius:50%;background:#E83858}
.nz-par .nzpar-network.nzpar-bep::before{background:#E0A200}
.nz-par .nzpar-fp{font:11.5px var(--nzpar-mono);color:#586A7E}
.nz-par .nzpar-requester{color:var(--nzpar-ink2);font-size:12px}
.nz-par .nzpar-me{color:var(--nzpar-amber);font-weight:700;font-size:10.5px;margin-left:4px}
.nz-par .nzpar-confirmed{display:inline-flex;align-items:center;gap:5px;padding:3px 8px;border-radius:5px;background:var(--nzpar-green-tint);color:var(--nzpar-green);font-size:10.5px;font-weight:700;white-space:nowrap}
.nz-par .nzpar-confirmed small{color:#6F9C88;font-weight:600}
.nz-par .nzpar-ttl{font:700 12px var(--nzpar-mono);color:var(--nzpar-ink2);white-space:nowrap}
.nz-par .nzpar-ttl.nzpar-soon{color:var(--nzpar-amber)}
.nz-par .nzpar-ttl.nzpar-dead{color:var(--nzpar-red)}
.nz-par .nzpar-ttl small{display:block;max-width:170px;white-space:normal;font-family:Inter,"PingFang SC","Microsoft YaHei",sans-serif;font-size:10px;font-weight:600;color:var(--nzpar-red);margin-top:2px}
.nz-par .nzpar-review{height:32px;padding:0 13px;border:0;border-radius:6px;background:var(--nzpar-navy);color:#fff;font-size:12px;font-weight:600;white-space:nowrap}
.nz-par .nzpar-foot{padding:12px 18px;border-top:1px solid var(--nzpar-line);color:#7F8E9F;font-size:11px;text-align:center}
.nz-par .nzpar-no-results{padding:24px;text-align:center;color:var(--nzpar-ink2);font-size:12.5px}
.nz-par .nzpar-empty{padding:64px 24px;text-align:center}
.nz-par .nzpar-empty-icon{width:56px;height:56px;margin:0 auto;border-radius:50%;background:var(--nzpar-grey-tint);display:grid;place-items:center;font-size:24px;color:var(--nzpar-ink3)}
.nz-par .nzpar-empty h3{margin:16px 0 0;font-size:15px}
.nz-par .nzpar-empty p{margin:8px auto 0;max-width:440px;color:var(--nzpar-ink2);font-size:12.5px;line-height:1.8}
.nz-par .nzpar-empty .nzpar-refresh{margin-top:18px}
.nz-par .nzpar-backdrop{position:fixed;inset:0;z-index:1080;background:rgba(24,37,52,.46);display:none;align-items:flex-start;justify-content:center;padding:38px 24px;overflow-y:auto}
.nz-par .nzpar-backdrop.nzpar-open{display:flex}
.nz-par .nzpar-modal{width:900px;max-width:100%;background:#fff;border-radius:12px;box-shadow:0 22px 70px rgba(18,32,48,.25)}
.nz-par .nzpar-modal-head{min-height:60px;padding:14px 22px;border-bottom:1px solid var(--nzpar-line);display:flex;align-items:center;gap:12px;position:sticky;top:0;background:#fff;border-radius:12px 12px 0 0;z-index:2}
.nz-par .nzpar-modal-head h2{margin:0;font-size:17px}
.nz-par .nzpar-modal-sub{color:#8090A2;font:11px var(--nzpar-mono)}
.nz-par .nzpar-close{margin-left:auto;border:0;background:transparent;color:#718197;font-size:22px;line-height:1}
.nz-par .nzpar-modal-body{padding:18px 22px 20px}
.nz-par .nzpar-guard{padding:11px 14px;border:1px solid #ECD7A8;border-radius:8px;background:#FFF9EB;color:#72551C;font-size:12px;line-height:1.7}
.nz-par .nzpar-guard b{color:#5D430F}
.nz-par .nzpar-error,.nz-par .nzpar-info{margin:14px 0 0;padding:11px 14px;border-radius:8px;font-size:12.5px;line-height:1.7;display:flex;gap:9px}
.nz-par .nzpar-error{border:1px solid #F3C2C4;background:var(--nzpar-red-tint);color:#9D2226}
.nz-par .nzpar-error b{color:#7D1418}
.nz-par .nzpar-info{border:1px solid #D8DEE8;background:#F4F6FA;color:#3D4C63}
.nz-par .nzpar-kv{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px 26px;margin:16px 0;padding-bottom:16px;border-bottom:1px solid var(--nzpar-line)}
.nz-par .nzpar-cell span{display:block;color:#8391A2;font-size:11px}
.nz-par .nzpar-cell strong{display:block;margin-top:5px;color:#2E4259;font-size:12.5px;font-weight:600;overflow-wrap:anywhere}
.nz-par .nzpar-mono{font-family:var(--nzpar-mono)!important}
.nz-par .nzpar-section{margin:16px 0 9px;color:#344A63;font-size:13px;font-weight:700}
.nz-par .nzpar-addresses{display:grid;grid-template-columns:1fr 1fr;gap:13px}
.nz-par .nzpar-address{padding:12px 14px;border:1px solid #DCE4EC;border-radius:8px;background:#F8FAFC}
.nz-par .nzpar-address.nzpar-new{border-left:3px solid var(--nzpar-navy);background:#F4F8FD}
.nz-par .nzpar-address label{display:flex;justify-content:space-between;color:#66788C;font-size:10.5px;font-weight:700;gap:8px}
.nz-par .nzpar-address label em{font-style:normal;color:var(--nzpar-navy)}
.nz-par .nzpar-address code{display:block;margin-top:8px;color:#30455C;font:12px/1.6 var(--nzpar-mono);word-break:break-all;white-space:normal}
.nz-par .nzpar-fp-row{display:grid;grid-template-columns:92px 1fr;gap:8px;align-items:start;margin-top:8px;padding-top:8px;border-top:1px dashed #E2E9F0}
.nz-par .nzpar-fp-row span{color:#77879A;font-size:10.5px;padding-top:1px}
.nz-par .nzpar-fp-row code{margin:0;color:#42566D;font-size:10.5px}
.nz-par .nzpar-checklist{margin:10px 0 0;padding:12px 14px;border:1px solid var(--nzpar-line);border-radius:8px;background:#FBFCFE}
.nz-par .nzpar-checklist li{margin:4px;color:#4A5A70;font-size:12px;line-height:1.75;list-style:none;display:flex;gap:8px}
.nz-par .nzpar-checklist li::before{content:"·";color:var(--nzpar-navy);font-weight:800}
.nz-par .nzpar-actions{margin-top:16px;padding-top:16px;border-top:1px solid var(--nzpar-line);display:grid;grid-template-columns:250px 1fr;gap:18px}
.nz-par .nzpar-totp label{display:block;color:#4A5A70;font-size:12px;font-weight:700}
.nz-par .nzpar-totp input{margin-top:8px;width:100%;height:46px;border:1.5px solid #CFD8E3;border-radius:8px;padding:0 14px;font:20px var(--nzpar-mono);letter-spacing:.5em;color:var(--nzpar-ink)}
.nz-par .nzpar-totp input:focus{outline:none;border-color:var(--nzpar-navy)}
.nz-par .nzpar-hint{margin-top:6px;font-size:10.5px;color:var(--nzpar-ink3);line-height:1.6}
.nz-par .nzpar-button-column{display:flex;flex-direction:column;gap:10px}
.nz-par .nzpar-button-row{display:flex;gap:10px;flex-wrap:wrap}
.nz-par .nzpar-button{height:44px;padding:0 22px;border-radius:8px;font-size:13.5px;font-weight:700}
.nz-par .nzpar-button.btn-success{background:var(--nzpar-green);border-color:var(--nzpar-green);color:#fff}
.nz-par .nzpar-button.btn-danger{background:var(--nzpar-red);border-color:var(--nzpar-red);color:#fff}
.nz-par .nzpar-button.nzpar-reject-trigger{background:#fff!important;color:var(--nzpar-red)!important;border:1.5px solid var(--nzpar-red)!important}
.nz-par .nzpar-button:disabled{opacity:.45;cursor:not-allowed}
.nz-par .nzpar-after{font-size:11px;color:var(--nzpar-ink3);line-height:1.8}
.nz-par .nzpar-after b{color:var(--nzpar-ink2);font-weight:600}
.nz-par .nzpar-reason{display:none}
.nz-par .nzpar-reason label{display:flex;justify-content:space-between;color:#4A5A70;font-size:12px;font-weight:700}
.nz-par .nzpar-reason label em{font-style:normal;color:var(--nzpar-ink3);font-weight:500;font-size:11px}
.nz-par .nzpar-reason textarea{margin-top:8px;width:100%;min-height:72px;border:1.5px solid #CFD8E3;border-radius:8px;padding:10px 12px;font-size:12.5px;color:var(--nzpar-ink);resize:vertical}
.nz-par .nzpar-reason.nzpar-show{display:block}
.nz-par .nzpar-main-buttons.nzpar-hide{display:none}
.nz-par .nzpar-back{background:var(--nzpar-grey-tint);color:var(--nzpar-ink2);border:0}
body.nzpar-modal-open{overflow:hidden}
@media(max-width:768px){
  .nz-par{padding:14px 10px 26px}
  .nz-par .nzpar-sum{gap:14px;padding:12px 14px}
  .nz-par .nzpar-rules{margin-left:0;text-align:left;width:100%}
  .nz-par .nzpar-kv{grid-template-columns:1fr 1fr}
  .nz-par .nzpar-addresses{grid-template-columns:1fr}
  .nz-par .nzpar-backdrop{padding:0}
  .nz-par .nzpar-modal{width:100%;min-height:100vh;min-height:100dvh;border-radius:0}
  .nz-par .nzpar-modal-head{border-radius:0}
  .nz-par .nzpar-modal-body{padding:14px 14px calc(20px + env(safe-area-inset-bottom))}
  .nz-par .nzpar-actions{grid-template-columns:1fr;gap:14px}
  .nz-par .nzpar-button{flex:1;padding:0 14px}
  .nz-par .nzpar-card-head{padding:10px 12px}
  .nz-par .nzpar-tools{width:100%;margin-left:0}
  .nz-par .nzpar-search{width:100%;flex:1}
}
</style>
@endpush

@section('content')
@php
    $now = now();
    $pendingCount = $changes->count();
    $expiredCount = $changes->filter(fn ($change) => $change->expires_at && $change->expires_at->lte($now))->count();
    $urgentCount = $changes->filter(fn ($change) => $change->expires_at
        && $change->expires_at->gt($now)
        && $change->expires_at->lte($now->copy()->addHours(2)))->count();
    $twoFactorEnabled = (bool) auth('admin')->user()?->two_factor_enabled;
    $reviewErrorChangeId = (string) ($reviewError['change_id'] ?? '');
@endphp
<div class="content container-fluid nz-par" data-payment-address-review="reviewer-v3"
    data-reopen-change="{{ $reviewErrorChangeId }}">
  <div class="nzpar-head">
    <div class="nzpar-title">
      <h1><i class="tio-checkmark-square"></i>收款地址复核 <span class="nzpar-count" data-review-count>{{ $pendingCount }}</span></h1>
      <p>只显示商家 owner 已确认、等待「不同管理员复核」的 USDT 收款地址变更。批准或驳回都需要您的 6 位动态码；申请人不能复核自己的申请。超时未处理的申请将自动按「已驳回（超时）」处理。</p>
    </div>
  </div>

  <div class="nzpar-sum">
    <div class="nzpar-stat"><span class="nzpar-stat-n {{ $pendingCount === 0 ? 'nzpar-zero' : '' }}">{{ $pendingCount }}</span><span class="nzpar-stat-k">待复核</span></div>
    <div class="nzpar-stat"><span class="nzpar-stat-n {{ $urgentCount > 0 ? 'nzpar-warn' : 'nzpar-zero' }}">{{ $urgentCount }}</span><span class="nzpar-stat-k">2 小时内到期</span></div>
    <div class="nzpar-stat"><span class="nzpar-stat-n {{ $expiredCount > 0 ? 'nzpar-bad' : 'nzpar-zero' }}">{{ $expiredCount }}</span><span class="nzpar-stat-k">已到期·待系统处理</span></div>
    <div class="nzpar-rules">
      <span class="nzpar-pill">本账号 2FA 已启用</span><br>
      <b>复核动作只有批准与驳回</b>；发起、取消与紧急暂停归地址管理权限，另行处理。
    </div>
  </div>
  <p class="nzpar-note">列表只显示地址指纹；完整新旧地址在详情内逐字核对。<b>请勿凭聊天记录里的地址代替第二渠道核对。</b></p>

  <div class="nzpar-card">
    <div class="nzpar-card-head">
      <h2>待复核申请</h2><span class="nzpar-count">{{ $pendingCount }}</span>
      <div class="nzpar-tools">
        @if($pendingCount > 0)
          <label class="nzpar-search" aria-label="筛选待复核申请">
            <input type="search" data-review-filter placeholder="筛选：商家名称 / 申请编号" autocomplete="off">
            <i class="tio-search"></i>
          </label>
        @endif
        <button type="button" class="nzpar-refresh" data-review-refresh><i class="tio-refresh mr-1"></i>刷新</button>
      </div>
    </div>

    @if($changes->isEmpty())
      <div class="nzpar-empty" data-review-empty>
        <div class="nzpar-empty-icon"><i class="tio-checkmark-square"></i></div>
        <h3>当前没有待复核的地址变更</h3>
        <p>商家 owner 确认地址变更后，申请会进入这里等待您复核。收到新申请时会按现有管理员渠道通知；也可以点「刷新队列」重新拉取。</p>
        <button type="button" class="nzpar-refresh" data-review-refresh><i class="tio-refresh mr-1"></i>刷新队列</button>
      </div>
    @else
      <div class="nzpar-table-wrap">
        <table class="nzpar-table">
          <thead>
            <tr>
              <th style="width:46px">序号</th><th>商家</th><th style="width:92px">网络</th><th>新地址指纹</th>
              <th>申请人</th><th>商家确认</th><th style="width:170px">复核剩余</th><th style="width:110px">操作</th>
            </tr>
          </thead>
          <tbody>
          @foreach($changes as $change)
            @php
              $expiresAt = $change->expires_at;
              $expired = $expiresAt && $expiresAt->lte($now);
              $minutesLeft = $expiresAt ? max(0, (int) floor($now->diffInMinutes($expiresAt, false))) : null;
              $soon = !$expired && $minutesLeft !== null && $minutesLeft <= 120;
              $ttl = $minutesLeft === null ? '—' : ($minutesLeft < 60
                  ? $minutesLeft.' 分钟'
                  : intdiv($minutesLeft, 60).' 小时 '.($minutesLeft % 60).' 分');
              $requester = $change->requestedByAdmin;
              $requesterName = trim((string) ($requester?->f_name.' '.$requester?->l_name));
              $requesterName = $requesterName !== '' ? $requesterName : '管理员#'.$change->requested_by_admin_id;
              $isSelf = (int) $change->requested_by_admin_id === $currentAdminId;
              $merchantName = $change->restaurant?->name ?? '商家#'.$change->restaurant_id;
              $searchText = mb_strtolower($merchantName.' '.$change->public_id, 'UTF-8');
            @endphp
            <tr class="{{ $expired ? 'nzpar-expired' : '' }}" data-review-row data-review-search="{{ $searchText }}">
              <td>{{ $loop->iteration }}</td>
              <td><div class="nzpar-merchant">{{ $merchantName }}</div><div class="nzpar-change-id">{{ $change->public_id }}</div></td>
              <td><span class="nzpar-network {{ strtoupper((string) $change->network) === 'BEP20' ? 'nzpar-bep' : '' }}">{{ $change->network }}</span></td>
              <td><span class="nzpar-fp">{{ substr((string) $change->new_fingerprint, 0, 6) }}…{{ substr((string) $change->new_fingerprint, -6) }}</span></td>
              <td><span class="nzpar-requester">{{ $requesterName }}@if($isSelf)<span class="nzpar-me">本人</span>@endif</span></td>
              <td><span class="nzpar-confirmed">✓ 已确认 <small>{{ $change->merchant_confirmed_at?->format('H:i') ?? '—' }}</small></span></td>
              <td>
                @if($expired)
                  <span class="nzpar-ttl nzpar-dead">已到期<small>系统将按「已驳回（超时）」处理</small></span>
                @else
                  <span class="nzpar-ttl {{ $soon ? 'nzpar-soon' : '' }}">{{ $ttl }}</span>
                @endif
              </td>
              <td><button type="button" class="nzpar-review" data-review-open="nzpar-modal-{{ $change->public_id }}">查看并复核</button></td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      <div class="nzpar-no-results d-none" data-review-no-results>没有匹配的待复核申请</div>
      <div class="nzpar-foot">最多显示按复核截止时间升序排列的 100 条申请；筛选只作用于当前已加载队列。时间以系统当前时区显示。</div>
    @endif
  </div>

  @foreach($changes as $change)
    @php
      $expiresAt = $change->expires_at;
      $expired = $expiresAt && $expiresAt->lte($now);
      $requester = $change->requestedByAdmin;
      $requesterName = trim((string) ($requester?->f_name.' '.$requester?->l_name));
      $requesterName = $requesterName !== '' ? $requesterName : '管理员#'.$change->requested_by_admin_id;
      $isSelf = (int) $change->requested_by_admin_id === $currentAdminId;
      $errorForChange = $reviewErrorChangeId !== '' && hash_equals($reviewErrorChangeId, (string) $change->public_id);
      $errorCode = $errorForChange ? (string) ($reviewError['code'] ?? '') : '';
      $retryable = in_array($errorCode, ['address_change_totp_invalid', 'address_change_totp_replayed', 'address_change_totp_rate_limited'], true);
      $canAct = $twoFactorEnabled && !$expired && !$isSelf && (!$errorForChange || $retryable);
      $showStateError = $expired || in_array($errorCode, ['address_change_expired', 'address_change_state_invalid'], true);
      $merchantName = $change->restaurant?->name ?? '商家#'.$change->restaurant_id;
    @endphp
    <div class="nzpar-backdrop" id="nzpar-modal-{{ $change->public_id }}" data-review-modal
        data-change-id="{{ $change->public_id }}" data-can-act="{{ $canAct ? '1' : '0' }}"
        role="dialog" aria-modal="true" aria-labelledby="nzpar-title-{{ $change->public_id }}">
      <div class="nzpar-modal">
        <div class="nzpar-modal-head">
          <h2 id="nzpar-title-{{ $change->public_id }}">复核地址变更</h2>
          <span class="nzpar-modal-sub">{{ $change->public_id }} · USDT-{{ $change->network }}</span>
          <button type="button" class="nzpar-close" data-review-close aria-label="关闭">×</button>
        </div>
        <div class="nzpar-modal-body">
          <div class="nzpar-guard">请把下方<b>新地址与指纹</b>与商家经由<b>第二渠道</b>（当面 / 电话回拨等，勿只看聊天消息）核对一致后再批准。您提交时批准的就是这串指纹——系统会在服务端再次校验它与申请一致，不一致会拒绝执行。</div>

          <div class="nzpar-kv">
            <div class="nzpar-cell"><span>商家</span><strong>{{ $merchantName }}（ID {{ $change->restaurant_id }}）</strong></div>
            <div class="nzpar-cell"><span>网络</span><strong>USDT · {{ $change->network }}</strong></div>
            <div class="nzpar-cell"><span>申请人</span><strong>{{ $requesterName }}（#{{ $change->requested_by_admin_id }}）</strong></div>
            <div class="nzpar-cell"><span>商家 owner 确认</span><strong>{{ $change->merchant_confirmed_at?->format('Y-m-d H:i') ?? '—' }}</strong></div>
            <div class="nzpar-cell"><span>复核截止</span><strong class="nzpar-mono">{{ $expiresAt?->format('Y-m-d H:i') ?? '—' }}{{ $expired ? ' · 已到期' : '' }}</strong></div>
            <div class="nzpar-cell"><span>申请原因（管理员填写）</span><strong>{{ trim((string) $change->reason) !== '' ? $change->reason : '未填写' }}</strong></div>
          </div>

          <div class="nzpar-section">地址核对（逐字核对，特别是首 6 位与末 6 位）</div>
          <div class="nzpar-addresses">
            <div class="nzpar-address">
              <label><span>当前收款地址（旧 · A）</span><span>变更前</span></label>
              <code>{{ $change->old_address }}</code>
              <div class="nzpar-fp-row"><span>SHA-256 指纹</span><code>{{ $change->old_fingerprint }}</code></div>
            </div>
            <div class="nzpar-address nzpar-new">
              <label><span>申请启用的新地址（B）</span><em>批准后立即用于新付款</em></label>
              <code>{{ $change->new_address }}</code>
              <div class="nzpar-fp-row"><span>SHA-256 指纹</span><code>{{ $change->new_fingerprint }}</code></div>
            </div>
          </div>
          <ul class="nzpar-checklist">
            <li>与商家本人经第二渠道核对新地址首 6 位、末 6 位与指纹片段一致；</li>
            <li>确认申请人、商家确认时间与你了解的换址安排相符（陌生申请人 / 深夜异常时间要提高警惕）；</li>
            <li>任何一点对不上：选择驳回。驳回不改变当前地址，商家可重新发起。</li>
          </ul>

          @if($showStateError)
            <div class="nzpar-error"><span>✕</span><span><b>申请状态已变化，请刷新页面后重新核对。</b>该申请已到期，系统将按「已驳回（超时）」处理；当前地址未改变，此处所有提交均已禁用。</span></div>
          @elseif($errorForChange)
            <div class="nzpar-error"><span>✕</span><span><b>{{ $reviewError['message'] }}</b>@if($retryable) 请重新输入验证器中的最新动态码；连续多次失败会触发临时限频。@else 当前地址未改变，请刷新队列后重新核对。@endif</span></div>
          @endif
          @if($isSelf)
            <div class="nzpar-info"><span>ⓘ</span><span><b>申请人不能自批，必须由另一名管理员复核。</b>这条申请由您本人发起，因此批准与驳回按钮对您禁用；请等待另一名已启用 2FA 的管理员处理。</span></div>
          @endif

          <div class="nzpar-actions">
            <div class="nzpar-totp">
              <label for="nzpar-totp-{{ $change->public_id }}">交易级动态码（6 位）</label>
              <input id="nzpar-totp-{{ $change->public_id }}" type="text" maxlength="6" inputmode="numeric"
                  autocomplete="one-time-code" placeholder="······" data-review-totp {{ $canAct ? '' : 'disabled' }}>
              <div class="nzpar-hint">来自您绑定的验证器 App。登录时输入过的动态码不能复用；每一步资金动作都要求重新输入。</div>
            </div>
            <div class="nzpar-button-column">
              <div class="nzpar-button-row nzpar-main-buttons" data-review-main-buttons>
                <form method="post" action="{{ route('admin.restaurant.payment-address-change.approve', $change) }}" data-review-approve-form>
                  @csrf
                  <input type="hidden" name="new_fingerprint" value="{{ $change->new_fingerprint }}">
                  <input type="hidden" name="totp_code" value="">
                  <button type="submit" class="btn btn-success nzpar-button" data-review-approve disabled>批准地址变更</button>
                </form>
                <button type="button" class="btn btn-danger nzpar-button nzpar-reject-trigger" data-review-reject-trigger disabled>驳回申请…</button>
              </div>
              <form method="post" action="{{ route('admin.restaurant.payment-address-change.reject', $change) }}" class="nzpar-reason" data-review-reject-form>
                @csrf
                <input type="hidden" name="new_fingerprint" value="{{ $change->new_fingerprint }}">
                <input type="hidden" name="totp_code" value="">
                <label for="nzpar-reason-{{ $change->public_id }}">驳回原因 <em>选填 · 可留空直接提交 · ≤500 字</em></label>
                <textarea id="nzpar-reason-{{ $change->public_id }}" name="reason" maxlength="500"
                    placeholder="选填。仅在填写时写入加密审计记录；不会出现在发给商家的通知里。"></textarea>
                <div class="nzpar-hint">商家会收到「申请被驳回」的安全通知（按可用渠道尽力投递并逐渠道留痕）；通知不包含完整地址与驳回原因。</div>
                <div class="nzpar-button-row mt-2">
                  <button type="submit" class="btn btn-danger nzpar-button" data-review-reject-confirm disabled>确认驳回</button>
                  <button type="button" class="nzpar-button nzpar-back" data-review-reject-cancel>返回</button>
                </div>
              </form>
              <div class="nzpar-after">
                <b>批准后</b>：新地址立即用于新付款；已签发的旧地址凭据只保留到各自到期（约 10 分钟内自然轮换），不会被撤销。<br>
                <b>驳回后</b>：当前地址不变，该商家该网络可重新发起申请。
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  @endforeach
</div>
@endsection

@push('script_2')
<script>
(function () {
  'use strict';
  var root = document.querySelector('[data-payment-address-review="reviewer-v3"]');
  if (!root) return;

  function openModal(modal) {
    if (!modal) return;
    modal.classList.add('nzpar-open');
    document.body.classList.add('nzpar-modal-open');
    var input = modal.querySelector('[data-review-totp]:not([disabled])');
    if (input && window.innerWidth > 768) input.focus();
  }
  function closeModal(modal) {
    modal.classList.remove('nzpar-open');
    document.body.classList.remove('nzpar-modal-open');
  }

  Array.prototype.forEach.call(root.querySelectorAll('[data-review-open]'), function (button) {
    button.addEventListener('click', function () {
      openModal(document.getElementById(button.getAttribute('data-review-open')));
    });
  });
  Array.prototype.forEach.call(root.querySelectorAll('[data-review-modal]'), function (modal) {
    var canAct = modal.getAttribute('data-can-act') === '1';
    var totp = modal.querySelector('[data-review-totp]');
    var approve = modal.querySelector('[data-review-approve]');
    var rejectTrigger = modal.querySelector('[data-review-reject-trigger]');
    var rejectConfirm = modal.querySelector('[data-review-reject-confirm]');
    var mainButtons = modal.querySelector('[data-review-main-buttons]');
    var rejectForm = modal.querySelector('[data-review-reject-form]');

    function syncActions() {
      var valid = canAct && /^\d{6}$/.test(totp.value);
      approve.disabled = !valid;
      rejectTrigger.disabled = !valid;
      rejectConfirm.disabled = !valid;
    }
    totp.addEventListener('input', function () {
      totp.value = totp.value.replace(/\D/g, '').slice(0, 6);
      syncActions();
    });
    rejectTrigger.addEventListener('click', function () {
      if (rejectTrigger.disabled) return;
      mainButtons.classList.add('nzpar-hide');
      rejectForm.classList.add('nzpar-show');
      rejectForm.querySelector('textarea').focus();
    });
    modal.querySelector('[data-review-reject-cancel]').addEventListener('click', function () {
      rejectForm.classList.remove('nzpar-show');
      mainButtons.classList.remove('nzpar-hide');
    });
    modal.querySelector('[data-review-approve-form]').addEventListener('submit', function (event) {
      if (!canAct || !/^\d{6}$/.test(totp.value)) {
        event.preventDefault();
        return;
      }
      event.currentTarget.querySelector('[name="totp_code"]').value = totp.value;
      approve.disabled = true;
      rejectTrigger.disabled = true;
    });
    rejectForm.addEventListener('submit', function (event) {
      if (!canAct || !/^\d{6}$/.test(totp.value)) {
        event.preventDefault();
        return;
      }
      rejectForm.querySelector('[name="totp_code"]').value = totp.value;
      rejectConfirm.disabled = true;
    });
    modal.querySelector('[data-review-close]').addEventListener('click', function () { closeModal(modal); });
    modal.addEventListener('click', function (event) { if (event.target === modal) closeModal(modal); });
    syncActions();
  });

  var filter = root.querySelector('[data-review-filter]');
  if (filter) {
    filter.addEventListener('input', function () {
      var query = filter.value.trim().toLocaleLowerCase('zh-CN');
      var visible = 0;
      Array.prototype.forEach.call(root.querySelectorAll('[data-review-row]'), function (row) {
        var match = query === '' || (row.getAttribute('data-review-search') || '').indexOf(query) !== -1;
        row.style.display = match ? '' : 'none';
        if (match) visible++;
      });
      var empty = root.querySelector('[data-review-no-results]');
      if (empty) empty.classList.toggle('d-none', visible !== 0);
    });
  }
  Array.prototype.forEach.call(root.querySelectorAll('[data-review-refresh]'), function (button) {
    button.addEventListener('click', function () { window.location.reload(); });
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    var modal = root.querySelector('[data-review-modal].nzpar-open');
    if (modal) closeModal(modal);
  });

  var reopen = root.getAttribute('data-reopen-change');
  if (reopen) openModal(document.getElementById('nzpar-modal-' + reopen));
})();
</script>
@endpush
