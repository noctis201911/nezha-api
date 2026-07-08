@extends('layouts.admin.app')
@section('title', '商户资料复审')
@php
    $labels = ['name'=>'店名','address'=>'地址','intro'=>'介绍','open_days'=>'营业星期','open_time'=>'开始时间',
        'close_time'=>'结束时间','hours_note'=>'时间补充','has_offer'=>'到店优惠','offer_text'=>'优惠文字',
        'services'=>'服务项','contacts'=>'联系方式','logo'=>'Logo','wechat_qr'=>'微信二维码','images'=>'相册'];
    $dayN = ['0'=>'周日','1'=>'周一','2'=>'周二','3'=>'周三','4'=>'周四','5'=>'周五','6'=>'周六'];
    $fmt = function ($v, $k) use ($dayN) {
        if (is_null($v) || $v === '') return '—';
        if (is_bool($v)) return $v ? '是' : '否';
        if (is_array($v)) {
            if (!count($v)) return '（空）';
            if ($k === 'open_days') return implode('、', array_map(fn($d) => $dayN[(string)(int)$d] ?? $d, $v));
            if (isset($v[0]) && is_array($v[0]) && array_key_exists('title', $v[0]))
                return implode('；', array_map(fn($s) => trim(($s['title'] ?? '') . ' ' . ($s['price_text'] ?? '')), $v));
            if (isset($v[0]) && is_array($v[0]) && array_key_exists('method', $v[0]))
                return implode('；', array_map(fn($c) => ($c['method'] ?? '') . ':' . ($c['value'] ?? ''), $v));
            if ($k === 'images') return count($v) . ' 张图';
            return implode('、', array_map(fn($x) => is_scalar($x) ? (string)$x : json_encode($x, JSON_UNESCAPED_UNICODE), $v));
        }
        return mb_strlen((string)$v) > 80 ? mb_substr((string)$v, 0, 80) . '…' : (string)$v;
    };
    $changed = function ($c) use ($labels) {
        $p = (array)$c->payload; $b = (array)$c->base_snapshot; $rows = [];
        foreach ($labels as $k => $label) {
            if (!array_key_exists($k, $p)) continue;
            if (json_encode($p[$k] ?? null) !== json_encode($b[$k] ?? null)) $rows[] = ['k' => $k, 'label' => $label];
        }
        return $rows;
    };
@endphp
@section('content')
<div class="content container-fluid">
    <div class="page-header mb-2">
        <h1 class="page-header-title fs-24">商户资料复审</h1>
        <small class="text-muted">本地生活商户在自助管理面提交的修改，通过后才更新到顾客端；驳回请注明原因（商户可见）。运营字段（类目/评分/上线状态等）商户无法提交，此处只审展示信息。</small>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">待审 <span class="badge badge-soft-warning">{{ $pending->count() }}</span></h5></div>
        <div class="card-body">
            @forelse($pending as $c)
                @php $rows = $changed($c); $p = (array)$c->payload; $b = (array)$c->base_snapshot; @endphp
                <div class="border rounded p-3 mb-3">
                    <div class="d-flex justify-content-between flex-wrap mb-2" style="gap:6px">
                        <div>
                            <strong>{{ optional($c->merchant)->name ?? ('#' . $c->merchant_id . '（已删除）') }}</strong>
                            <small class="text-muted d-block">提交人 {{ optional($c->account)->email ?? '—' }} · IP {{ $c->submit_ip ?? '—' }} · {{ $c->created_at?->timezone('Asia/Yerevan')->format('Y-m-d H:i') }}</small>
                        </div>
                    </div>

                    @if(count($rows))
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-2" style="font-size:13px">
                                <thead><tr><th style="width:110px">字段</th><th>原值</th><th>新值</th></tr></thead>
                                <tbody>
                                @foreach($rows as $r)
                                    <tr class="{{ in_array($r['k'], ['name','address']) ? 'table-warning' : '' }}">
                                        <td>{{ $r['label'] }}@if(in_array($r['k'], ['name','address']))<span class="badge badge-soft-danger ml-1">重点</span>@endif</td>
                                        <td class="text-muted">{{ $fmt($b[$r['k']] ?? null, $r['k']) }}</td>
                                        <td><strong>{{ $fmt($p[$r['k']] ?? null, $r['k']) }}</strong></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-muted mb-2" style="font-size:13px">本次提交无字段变化（可直接驳回或通过）。</div>
                    @endif

                    <div class="d-flex flex-wrap align-items-start" style="gap:10px">
                        <form action="{{ route('admin.local-life.merchant-changes.approve', $c->id) }}" method="post" onsubmit="return confirm('确认通过并更新到顾客端？');">
                            @csrf
                            <button type="submit" class="btn btn-sm btn--primary">通过并应用</button>
                        </form>
                        <form action="{{ route('admin.local-life.merchant-changes.reject', $c->id) }}" method="post" class="d-flex" style="gap:6px">
                            @csrf
                            <input type="text" name="review_note" class="form-control form-control-sm" maxlength="255" placeholder="驳回原因（商户可见）" style="min-width:220px">
                            <button type="submit" class="btn btn-sm btn-outline-danger">驳回</button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="text-muted">暂无待审提交。</div>
            @endforelse
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h5 class="mb-0">近期已处理</h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm" style="font-size:13px">
                    <thead><tr><th>商户</th><th>结果</th><th>理由</th><th>处理时间</th></tr></thead>
                    <tbody>
                    @forelse($recent as $c)
                        <tr>
                            <td>{{ optional($c->merchant)->name ?? ('#' . $c->merchant_id) }}</td>
                            <td>@if($c->status === \App\Models\LocalLifeMerchantChange::STATUS_APPROVED)<span class="badge badge-soft-success">已通过</span>@else<span class="badge badge-soft-danger">已驳回</span>@endif</td>
                            <td class="text-muted">{{ $c->review_note ?? '—' }}</td>
                            <td class="text-muted">{{ $c->reviewed_at?->timezone('Asia/Yerevan')->format('Y-m-d H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted">暂无记录。</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
