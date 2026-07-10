@extends('local_merchant.panel')
@section('title', '我的笔记')
@php $noteImg = fn($f) => \App\CentralLogics\Helpers::get_full_url('local-life-note', $f, 'public'); @endphp
@section('content')

<div class="nzp-card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px">
        <div>
            <div class="nzp-h1" style="font-size:18px">笔记</div>
            <div class="nzp-sub" style="margin:2px 0 0">发布店内动态、作品、招牌，图文并茂。提交后经平台审核，通过才在店铺页展示。</div>
        </div>
    </div>
    <div class="nzp-hint" style="margin-top:8px">红线：笔记内请勿填写联系方式（电话/微信/链接），也不要冒充顾客口吻——发现将被驳回或下架。</div>
</div>

<div class="nzp-btnrow">
    <a href="{{ route('local-merchant.notes.create') }}" class="nzp-btn block">✎ 写新笔记</a>
</div>

<div class="nzp-card" style="margin-top:14px">
    <h2>已提交 <span style="font-weight:400;color:var(--nz-muted);font-size:12px">（{{ $notes->total() }}）</span></h2>
    @forelse($notes as $n)
        @php
            $badge = [0=>['pend','待审核'],1=>['ok','已展示'],2=>['rej','已驳回'],3=>['rej','已下架']][$n->status] ?? ['pend','待审核'];
        @endphp
        <div style="padding:10px 0;border-bottom:1px solid #f1f4f8">
            <div style="display:flex;gap:10px">
                @if(is_array($n->images) && count($n->images))
                    <img src="{{ $noteImg($n->images[0]) }}" alt="封面" style="width:64px;height:64px;border-radius:10px;object-fit:cover;border:1px solid var(--nz-line);flex:0 0 auto">
                @endif
                <div style="min-width:0;flex:1">
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                        <span class="nzp-badge {{ $badge[0] }}">{{ $badge[1] }}</span>
                        @if(is_array($n->images))<span class="nzp-hint" style="margin:0">{{ count($n->images) }} 图</span>@endif
                        <span class="nzp-hint" style="margin:0">{{ $n->created_at?->timezone('Asia/Yerevan')->format('m-d H:i') }}</span>
                    </div>
                    @if($n->title)<div style="font-weight:600;margin-top:3px">{{ $n->title }}</div>@endif
                    <div style="font-size:13px;color:var(--nz-ink2);margin-top:2px">{{ \Illuminate\Support\Str::limit($n->body, 60) }}</div>
                    @if($n->status == 2 && $n->reject_reason)
                        <div class="nzp-hint" style="color:var(--nz-red);margin-top:3px">驳回理由：{{ $n->reject_reason }}</div>
                    @endif
                </div>
            </div>
            <div style="margin-top:8px;text-align:right">
                <form method="POST" action="{{ route('local-merchant.notes.delete', $n->id) }}" style="display:inline"
                    onsubmit="return confirm('删除这条笔记？删除后不可恢复。')">
                    @csrf @method('delete')
                    <button type="submit" class="nzp-btn ghost" style="padding:6px 12px;font-size:13px;color:var(--nz-red);border-color:#f0d2d0">删除</button>
                </form>
            </div>
        </div>
    @empty
        <div class="nzp-hint">还没有笔记。点上方「写新笔记」发布第一条。</div>
    @endforelse
    <div style="margin-top:10px">{!! $notes->links() !!}</div>
</div>

<div class="nzp-btnrow" style="margin-top:10px">
    <a href="{{ route('local-merchant.home') }}" class="nzp-btn ghost block">返回店铺</a>
</div>

@endsection
