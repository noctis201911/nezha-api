@extends('layouts.vendor.app')

@section('title', '竞价推广')

@section('advertisement')
active
@endsection

@section('advertisement_promotion')
active
@endsection

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">
            <span class="page-header-icon"><i class="tio-star"></i></span>
            <span>竞价推广 · 让你的店更靠前</span>
        </h1>
        <p class="text-muted mt-2 mb-0">
            花钱让你的店在顾客的餐厅列表里更靠前。<strong>只有真实顾客点进你的店才扣费</strong>（按点击），随时可关；广告余额扣完会自动暂停，不影响你的经营保证金。
        </p>
    </div>

    @if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
    @endif

    <div class="row">
        <div class="col-lg-7">

            <div class="row">
                <div class="col-4">
                    <div class="card mb-3"><div class="card-body text-center py-3">
                        <div class="text-muted" style="font-size:12px">广告余额</div>
                        <div style="font-size:22px;font-weight:600">{{ number_format($adBalance, 0) }} <small class="text-muted">֏</small></div>
                    </div></div>
                </div>
                <div class="col-4">
                    <div class="card mb-3"><div class="card-body text-center py-3">
                        <div class="text-muted" style="font-size:12px">今日已花</div>
                        <div style="font-size:22px;font-weight:600">{{ number_format($spentToday, 0) }} <small class="text-muted">֏</small></div>
                    </div></div>
                </div>
                <div class="col-4">
                    <div class="card mb-3"><div class="card-body text-center py-3">
                        <div class="text-muted" style="font-size:12px">今日点击</div>
                        <div style="font-size:22px;font-weight:600">{{ number_format($clicksToday, 0) }} <small class="text-muted">次</small></div>
                    </div></div>
                </div>
            </div>

            @if ($adBalance <= 0)
            <div class="alert alert-warning" role="alert">
                <i class="tio-warning"></i> 广告余额为 0，开启也不会生效。广告余额由平台按你的对公付款充值，请联系平台。这笔钱与你的经营保证金完全分开。
            </div>
            @endif

            <div class="card">
                <div class="card-body">
                    <form action="{{ route('vendor.advertisement.promotion.save') }}" method="post">
                        @csrf

                        <div class="form-group">
                            <label class="input-label">推广开关</label>
                            <select name="enabled" class="form-control">
                                <option value="1" {{ $enabled ? 'selected' : '' }}>开启（有余额即参与排名靠前）</option>
                                <option value="0" {{ !$enabled ? 'selected' : '' }}>关闭</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="input-label">想多靠前？</label>
                            <div class="d-flex" style="gap:12px">
                                <label class="flex-fill text-center m-0" style="cursor:pointer;border:1px solid #e7eaf3;border-radius:6px;padding:10px 6px">
                                    <input type="radio" name="tier" value="low" {{ $currentTier === 'low' ? 'checked' : '' }}>
                                    <div>低</div><div class="text-muted" style="font-size:11px">稳一点</div>
                                </label>
                                <label class="flex-fill text-center m-0" style="cursor:pointer;border:1px solid #e7eaf3;border-radius:6px;padding:10px 6px">
                                    <input type="radio" name="tier" value="mid" {{ $currentTier === 'mid' ? 'checked' : '' }}>
                                    <div>中</div><div class="text-muted" style="font-size:11px">推荐</div>
                                </label>
                                <label class="flex-fill text-center m-0" style="cursor:pointer;border:1px solid #e7eaf3;border-radius:6px;padding:10px 6px">
                                    <input type="radio" name="tier" value="high" {{ $currentTier === 'high' ? 'checked' : '' }}>
                                    <div>高</div><div class="text-muted" style="font-size:11px">最靠前</div>
                                </label>
                            </div>
                            <small class="text-muted">档位越高，同等条件下排得越靠前、单次点击花得越多。具体出价平台按后台参数换算，你不用管数字。</small>
                        </div>

                        <div class="form-group">
                            <label class="input-label">每天最多花（德拉姆 ֏）</label>
                            <input type="number" min="1" step="1" max="{{ (int) $maxDaily }}" name="daily_budget" class="form-control"
                                   value="{{ $ad && $ad->daily_budget ? (int) $ad->daily_budget : 2000 }}">
                            <small class="text-muted">当天花到这个数就自动停投，第二天重置。平台上限 {{ number_format($maxDaily, 0) }} ֏/天。</small>
                        </div>

                        <div class="btn--container justify-content-end">
                            <button type="submit" class="btn btn--primary">保存</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
