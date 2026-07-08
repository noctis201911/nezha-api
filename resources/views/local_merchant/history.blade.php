@extends('local_merchant.panel')
@section('title', '提交记录')
@php
    $fieldLabels = ['name'=>'店名','address'=>'地址','intro'=>'介绍','open_days'=>'营业星期','open_time'=>'开始时间',
        'close_time'=>'结束时间','hours_note'=>'时间补充','has_offer'=>'到店优惠','offer_text'=>'优惠文字',
        'services'=>'服务项','contacts'=>'联系方式','logo'=>'Logo','wechat_qr'=>'微信二维码','images'=>'相册'];
    $changedKeys = function ($c) use ($fieldLabels) {
        $p = (array) $c->payload; $b = (array) $c->base_snapshot; $out = [];
        foreach ($fieldLabels as $k => $label) {
            if (!array_key_exists($k, $p)) continue;
            $pv = json_encode($p[$k] ?? null, JSON_UNESCAPED_UNICODE);
            $bv = json_encode($b[$k] ?? null, JSON_UNESCAPED_UNICODE);
            if ($pv !== $bv) $out[] = $label;
        }
        return $out;
    };
@endphp
@section('content')

<div class="nzp-card">
    <h2>提交记录</h2>
    <div class="nzp-hint">您每次提交的修改会在这里留痕。平台确认后才更新到顾客端。</div>
</div>

@forelse($changes as $c)
    @php $ck = $changedKeys($c); @endphp
    <div class="nzp-card">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px">
            <span style="font-size:13px;color:var(--nz-muted)">{{ $c->created_at?->timezone('Asia/Yerevan')->format('Y-m-d H:i') }}</span>
            @if($c->status === \App\Models\LocalLifeMerchantChange::STATUS_PENDING)
                <span class="nzp-badge pend">待平台确认</span>
            @elseif($c->status === \App\Models\LocalLifeMerchantChange::STATUS_APPROVED)
                <span class="nzp-badge ok">已通过</span>
            @else
                <span class="nzp-badge rej">已驳回</span>
            @endif
        </div>
        <div class="nzp-kv"><div class="k">本次修改</div><div class="v">{{ count($ck) ? implode('、', $ck) : '无字段变化' }}</div></div>
        @if($c->status === \App\Models\LocalLifeMerchantChange::STATUS_REJECTED && $c->review_note)
            <div class="nzp-alert nzp-alert-err" style="margin:8px 0 0;font-size:13px"><strong>驳回原因：</strong>{{ $c->review_note }}</div>
        @endif
        @if($c->reviewed_at)
            <div class="nzp-hint" style="margin-top:6px">平台处理于 {{ $c->reviewed_at?->timezone('Asia/Yerevan')->format('Y-m-d H:i') }}</div>
        @endif
    </div>
@empty
    <div class="nzp-card"><div class="nzp-hint">还没有提交记录。</div></div>
@endforelse

@if($changes->hasPages())
    <div class="nzp-btnrow">
        @if($changes->previousPageUrl())<a href="{{ $changes->previousPageUrl() }}" class="nzp-btn ghost" style="flex:1">上一页</a>@endif
        @if($changes->nextPageUrl())<a href="{{ $changes->nextPageUrl() }}" class="nzp-btn ghost" style="flex:1">下一页</a>@endif
    </div>
@endif

<div class="nzp-btnrow" style="margin-top:12px">
    <a href="{{ route('local-merchant.home') }}" class="nzp-btn ghost block">返回店铺</a>
</div>

@endsection
