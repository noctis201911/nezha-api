@extends('layouts.vendor.app')

@section('title', '多级满减')

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">
            <span class="page-header-icon"><i class="tio-percent"></i></span>
            <span>多级满减 · 满额自动减</span>
        </h1>
        <p class="text-muted mt-2 mb-0">
            设置"满多少减多少"，可设多档。顾客下单时系统<strong>自动帮他算最省的一档</strong>，不用输入任何优惠码。这笔让利由你承担。
        </p>
    </div>

    @if (!$featureOn)
    <div class="alert alert-info" role="alert">
        <i class="tio-info-outined"></i> 平台还没开放"多级满减"。你现在可以先把档位配置好，平台开放后会自动对顾客生效。
    </div>
    @endif

    @if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
    @endif

    <form action="{{ route('vendor.nezha-discount.save') }}" method="post">
        @csrf
        <div class="row">
            <div class="col-lg-8">

                <div class="card mb-3"><div class="card-body">
                    <div class="form-group">
                        <label class="input-label">本店满减开关</label>
                        <select name="status" class="form-control">
                            <option value="1" {{ optional($discount)->status !== 0 ? 'selected' : '' }}>开启</option>
                            <option value="0" {{ optional($discount)->status === 0 ? 'selected' : '' }}>关闭（保留配置，暂不生效）</option>
                        </select>
                    </div>

                    <div class="form-group mb-0">
                        <label class="input-label">生效时间 <small class="text-muted">（可选，留空 = 长期有效、全天生效）</small></label>
                        <div class="row">
                            <div class="col-sm-6 mb-2"><small class="text-muted d-block">开始日期</small><input type="date" name="start_date" class="form-control" value="{{ optional($discount)->start_date }}"></div>
                            <div class="col-sm-6 mb-2"><small class="text-muted d-block">结束日期</small><input type="date" name="end_date" class="form-control" value="{{ optional($discount)->end_date }}"></div>
                            <div class="col-sm-6 mb-2"><small class="text-muted d-block">每天开始</small><input type="time" name="start_time" class="form-control" value="{{ optional($discount)->start_time ? substr($discount->start_time, 0, 5) : '' }}"></div>
                            <div class="col-sm-6 mb-2"><small class="text-muted d-block">每天结束</small><input type="time" name="end_time" class="form-control" value="{{ optional($discount)->end_time ? substr($discount->end_time, 0, 5) : '' }}"></div>
                        </div>
                    </div>
                </div></div>

                <div class="card mb-3"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="input-label m-0">满减档位 <small class="text-muted">（可加多档，顾客自动取最省的一档）</small></label>
                        <button type="button" class="btn btn-sm btn--primary" id="nz-add-tier"><i class="tio-add"></i> 添加一档</button>
                    </div>
                    <div id="nz-tier-list"></div>
                    <small class="text-muted d-block mt-2">示例：满 3000 减 300；满 5000 减 700；满 8000 减 10%（封顶 1000）。金额单位：德拉姆 ֏。</small>
                </div></div>

                <div class="card mb-3"><div class="card-body" style="background:#FBF3F4;border-radius:8px">
                    <label class="input-label" style="color:#C4193E">计算规则（请了解后再设置）</label>
                    <ul class="mb-0 text-muted" style="font-size:13px;line-height:1.9">
                        <li>顾客下单自动取<strong>最省的一档</strong>，多档不叠加。</li>
                        <li>满减<strong>不与优惠券叠加</strong>；系统自动帮顾客选更省的一方。</li>
                        <li>门槛按<strong>商品金额</strong>算，不含配送费。</li>
                        <li>平台佣金按你的<strong>实收（减后）</strong>金额收——你让多少利，佣金也跟着按减后算。</li>
                    </ul>
                </div></div>

                <div class="btn--container justify-content-end">
                    <button type="submit" class="btn btn--primary">保存</button>
                </div>
            </div>
        </div>
    </form>
</div>

<template id="nz-tier-template">
    <div class="nz-tier-row border rounded p-2 mb-2">
        <div class="row align-items-end">
            <div class="col-6 col-md-3 mb-2"><small class="text-muted d-block">满（֏）</small><input type="number" min="1" step="1" class="form-control nz-min" name="tiers[IDX][min_purchase]" value="__MIN__"></div>
            <div class="col-6 col-md-3 mb-2"><small class="text-muted d-block">方式</small>
                <select class="form-control nz-type" name="tiers[IDX][discount_type]">
                    <option value="amount" __AMT_SEL__>减固定额</option>
                    <option value="percent" __PCT_SEL__>按百分比</option>
                </select>
            </div>
            <div class="col-6 col-md-3 mb-2"><small class="text-muted d-block nz-disc-label">减（֏）</small><input type="number" min="1" step="1" class="form-control nz-disc" name="tiers[IDX][discount]" value="__DISC__"></div>
            <div class="col-6 col-md-2 mb-2 nz-max-wrap"><small class="text-muted d-block">封顶（֏）</small><input type="number" min="0" step="1" class="form-control nz-max" name="tiers[IDX][max_discount]" value="__MAX__"></div>
            <div class="col-6 col-md-1 mb-2 text-right"><button type="button" class="btn btn-sm btn-outline-danger nz-del" title="删除这一档">✕</button></div>
        </div>
    </div>
</template>

<script>
(function () {
    var list = document.getElementById('nz-tier-list');
    var tpl = document.getElementById('nz-tier-template').innerHTML;

    function reindex() {
        var rows = list.querySelectorAll('.nz-tier-row');
        for (var i = 0; i < rows.length; i++) {
            var inputs = rows[i].querySelectorAll('[name]');
            for (var j = 0; j < inputs.length; j++) {
                inputs[j].name = inputs[j].name.replace(/tiers\[\d*\]/, 'tiers[' + i + ']');
            }
        }
    }

    function toggleMax(row) {
        var type = row.querySelector('.nz-type').value;
        var maxWrap = row.querySelector('.nz-max-wrap');
        var discLabel = row.querySelector('.nz-disc-label');
        if (type === 'percent') {
            maxWrap.style.display = '';
            discLabel.textContent = '减（%）';
        } else {
            maxWrap.style.display = 'none';
            row.querySelector('.nz-max').value = 0;
            discLabel.textContent = '减（֏）';
        }
    }

    function addRow(min, type, disc, max) {
        var html = tpl
            .replace(/IDX/g, list.querySelectorAll('.nz-tier-row').length)
            .split('__MIN__').join(min !== undefined && min !== null && min !== 0 ? min : '')
            .split('__DISC__').join(disc !== undefined && disc !== null && disc !== 0 ? disc : '')
            .split('__MAX__').join(max !== undefined && max !== null ? max : 0)
            .split('__AMT_SEL__').join(type === 'percent' ? '' : 'selected')
            .split('__PCT_SEL__').join(type === 'percent' ? 'selected' : '');
        var wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        var row = wrap.firstElementChild;
        list.appendChild(row);
        row.querySelector('.nz-del').addEventListener('click', function () { row.remove(); reindex(); });
        row.querySelector('.nz-type').addEventListener('change', function () { toggleMax(row); });
        toggleMax(row);
        reindex();
    }

    document.getElementById('nz-add-tier').addEventListener('click', function () { addRow('', 'amount', '', 0); });

    var existing = @json($tiersData);
    if (existing && existing.length) {
        existing.forEach(function (t) { addRow(t.min, t.type, t.disc, t.max); });
    } else {
        addRow('', 'amount', '', 0);
    }
})();
</script>
@endsection
