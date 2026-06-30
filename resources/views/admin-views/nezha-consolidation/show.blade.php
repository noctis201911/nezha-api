@extends('layouts.admin.app')

@section('title', translate('集运需求详情'))

@section('content')
@php
    $intentLabel = ['yes' => '有意向', 'maybe' => '看价格再定', 'no' => '暂不需要'];
    $freqLabel = ['weekly' => '每周', 'biweekly' => '每两周', 'monthly' => '每月', 'quarterly' => '每季度', 'irregular' => '不定期'];
    $timesLabel = ['0' => '0 次', '1-2' => '1–2 次', '3-5' => '3–5 次', '6+' => '6 次以上'];
    $leadLabel = ['fast' => '越快越好(空运档)', 'mid' => '2–4 周可接受', 'slow' => '1–2 个月也行(成本优先)'];
    $saveLabel = ['little' => '省一点就参加', 's15' => '省 15%+', 's30' => '省 30%+'];
    $referLabel = ['yes' => '愿意推荐', 'no' => '暂时没有'];
    $arr = fn ($j) => implode(' · ', json_decode($j ?: '[]', true) ?: []);
    $dash = fn ($v) => ($v === null || $v === '') ? '—' : $v;
    if ($s->volume_unit === 'm3') { $vol = '体积 ' . $s->volume_m3 . ' m³'; }
    elseif ($s->volume_unit === 'kg') { $vol = '重量 ' . $s->weight_kg . ' kg'; }
    elseif ($s->volume_unit === 'box') { $vol = $s->box_count . ' 箱' . ($s->box_size ? '（每箱 ' . $s->box_size . '）' : ''); }
    else { $vol = '—'; }
    $fmt = fn ($v) => \App\CentralLogics\Helpers::format_currency($v);
@endphp
<div class="content container-fluid">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap">
        <h2 class="page-header-title mb-0">{{ translate('集运需求详情') }} — {{ $s->rname }}</h2>
        <a href="{{ route('admin.nezha-consolidation.index') }}" class="btn btn-sm btn-outline-secondary">{{ translate('返回列表') }}</a>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-md-6"><div class="card h-100"><div class="card-body">
            <h6 class="text-muted mb-1">{{ translate('近90天平台成交') }}</h6>
            <span class="h3">{{ $fmt($s->gmv90) }}</span> <small class="text-muted">· {{ $s->cnt90 }} {{ translate('单') }}</small>
            <div class="small text-muted mt-1">{{ translate('平台内已确认订单口径，供判断是否深度合作商家') }}</div>
        </div></div></div>
        <div class="col-md-6"><div class="card h-100"><div class="card-body">
            <h6 class="text-muted mb-1">{{ translate('联系方式') }}</h6>
            <div>{{ translate('商家') }}: {{ $s->rname }}</div>
            <div>{{ translate('电话') }}: {{ $dash($s->phone) }}</div>
            <div class="small text-muted mt-1">{{ translate('更多联系方式见该商家资料页') }}</div>
        </div></div></div>
    </div>

    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0">{{ translate('登记内容') }}</h5></div>
        <div class="card-body p-0">
            <table class="table table-borderless table-thead-bordered table-align-middle mb-0">
                <tbody>
                    <tr><td class="text-muted" style="width:180px;">{{ translate('参与意向') }}</td><td>{{ translate($intentLabel[$s->intent] ?? $s->intent) }}</td></tr>
                    <tr><td class="text-muted">{{ translate('货物品类') }}</td><td>{{ $dash($arr($s->categories)) }}{{ $s->category_other ? '（其它: ' . $s->category_other . '）' : '' }}</td></tr>
                    <tr><td class="text-muted">{{ translate('具体品名举例') }}</td><td>{{ $dash($s->category_examples) }}</td></tr>
                    <tr><td class="text-muted">{{ translate('过去3个月次数') }}</td><td>{{ $dash(translate($timesLabel[$s->times_3m] ?? ($s->times_3m ?: ''))) }}</td></tr>
                    <tr><td class="text-muted">{{ translate('平均每次货量') }}</td><td>{{ $vol }}</td></tr>
                    <tr><td class="text-muted">{{ translate('进货频率') }}</td><td>{{ $dash(translate($freqLabel[$s->frequency] ?? ($s->frequency ?: ''))) }}</td></tr>
                    <tr><td class="text-muted">{{ translate('能接受时长') }}</td><td>{{ $dash(translate($leadLabel[$s->lead_time] ?? ($s->lead_time ?: ''))) }}</td></tr>
                    <tr><td class="text-muted">{{ translate('目前物流成本') }}</td><td>{{ $dash($s->current_cost) }}</td></tr>
                    <tr><td class="text-muted">{{ translate('期望降幅') }}</td><td>{{ $dash(translate($saveLabel[$s->expected_saving] ?? ($s->expected_saving ?: ''))) }}</td></tr>
                    <tr><td class="text-muted">{{ translate('愿意推荐服务方') }}</td><td>{{ $dash(translate($referLabel[$s->refer_provider] ?? ($s->refer_provider ?: ''))) }}{{ $s->refer_provider_info ? '：' . $s->refer_provider_info : '' }}</td></tr>
                    <tr><td class="text-muted">{{ translate('现在怎么进货') }}</td><td>{{ $dash($arr($s->current_channels)) }}</td></tr>
                    <tr><td class="text-muted">{{ translate('痛点') }}</td><td>{{ $dash($arr($s->pain_points)) }}</td></tr>
                    <tr><td class="text-muted">{{ translate('其它建议') }}</td><td>{{ $dash($s->suggestion) }}</td></tr>
                    <tr><td class="text-muted">{{ translate('提交时间') }}</td><td>{{ \Carbon\Carbon::parse($s->updated_at)->format('Y-m-d H:i') }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
