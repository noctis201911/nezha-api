@extends('local_merchant.panel')
@section('title', '我的店铺')
@php
    $img = fn($f) => \App\CentralLogics\Helpers::get_full_url('local-life-merchant', $f, 'public');
    $contacts = $merchant->normalizedContacts();
    $methodLabel = ['phone'=>'电话','whatsapp'=>'WhatsApp','telegram'=>'Telegram','wechat'=>'微信'];
@endphp
@section('content')

@if($pending)
    <div class="nzp-alert nzp-alert-warn">
        <strong>您有一份修改正在等待平台确认</strong>（提交于 {{ $pending->created_at?->timezone('Asia/Yerevan')->format('m-d H:i') }}）。<br>
        确认前，顾客端仍显示下方「当前线上内容」。可继续修改后重新提交（会替换上一次待审内容）。
    </div>
@endif

<div class="nzp-card">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:6px">
        @if($merchant->logo)<img src="{{ $img($merchant->logo) }}" alt="logo" style="width:52px;height:52px;border-radius:12px;object-fit:cover;border:1px solid var(--nz-line)">@endif
        <div style="min-width:0">
            <div class="nzp-h1" style="font-size:18px">{{ $merchant->name }}</div>
            <div class="nzp-sub" style="margin:0">{{ $merchant->category }}@if($merchant->area) · {{ $merchant->area }}@endif</div>
        </div>
    </div>
    <div class="nzp-hint">这是顾客端看到的、平台已确认的当前内容。</div>
</div>

<div class="nzp-card">
    <h2>基本信息</h2>
    <div class="nzp-kv"><div class="k">平台评分</div><div class="v">{{ $merchant->rating }}@if($merchant->google_rating) · Google {{ $merchant->google_rating }}@endif</div></div>
    <div class="nzp-kv"><div class="k">营业时间</div><div class="v">{{ $merchant->todayHoursLabel() }}@if($merchant->hours_note) · {{ $merchant->hours_note }}@endif</div></div>
    <div class="nzp-kv"><div class="k">地址</div><div class="v">{{ $merchant->address ?: '未填写' }}</div></div>
    @if($merchant->has_offer && $merchant->offer_text)
        <div class="nzp-kv"><div class="k">到店优惠</div><div class="v">{{ $merchant->offer_text }}</div></div>
    @endif
    <div class="nzp-kv"><div class="k">介绍</div><div class="v">{{ $merchant->intro ?: '未填写' }}</div></div>
</div>

<div class="nzp-card">
    <h2>服务项 <span style="font-weight:400;color:var(--nz-muted);font-size:12px">（{{ count($merchant->services ?? []) }}）</span></h2>
    @forelse($merchant->services ?? [] as $s)
        <div class="nzp-kv"><div class="v"><strong>{{ $s['title'] ?? '' }}</strong>@if(!empty($s['price_text'])) · {{ $s['price_text'] }}@endif @if(!empty($s['desc']))<div class="nzp-hint" style="margin-top:2px">{{ $s['desc'] }}</div>@endif</div></div>
    @empty
        <div class="nzp-hint">暂无服务项</div>
    @endforelse
</div>

<div class="nzp-card">
    <h2>联系方式 <span style="font-weight:400;color:var(--nz-muted);font-size:12px">（{{ count($contacts) }}）</span></h2>
    @forelse($contacts as $c)
        <div class="nzp-kv"><div class="k">{{ $methodLabel[$c['method']] ?? $c['method'] }}</div><div class="v">{{ $c['value'] }}@if(!empty($c['label'])) · {{ $c['label'] }}@endif</div></div>
    @empty
        <div class="nzp-hint">暂无联系方式</div>
    @endforelse
    @if($merchant->wechat_qr)
        <div class="nzp-hint" style="margin-top:8px">微信二维码：<img src="{{ $img($merchant->wechat_qr) }}" alt="wechat qr" style="height:64px;margin-top:4px;display:block;border-radius:8px"></div>
    @endif
</div>

@if(is_array($merchant->images) && count($merchant->images))
<div class="nzp-card">
    <h2>相册 <span style="font-weight:400;color:var(--nz-muted);font-size:12px">（{{ count($merchant->images) }}）</span></h2>
    <div class="nzp-thumbs">
        @foreach($merchant->images as $im)<img src="{{ $img($im) }}" alt="相册">@endforeach
    </div>
</div>
@endif

<div class="nzp-btnrow">
    <a href="{{ route('local-merchant.edit') }}" class="nzp-btn block">{{ $pending ? '继续编辑（有待审）' : '编辑店铺信息' }}</a>
</div>
<div class="nzp-btnrow" style="margin-top:10px">
    <a href="{{ route('local-merchant.notes') }}" class="nzp-btn ghost block">✎ 笔记（店内动态/作品）</a>
</div>
<div class="nzp-btnrow" style="margin-top:10px">
    <a href="{{ route('local-merchant.history') }}" class="nzp-btn ghost block">提交记录@if($history->count()) · {{ $history->count() }}@endif</a>
</div>

@endsection
