@extends('layouts.vendor.app')

@section('title', translate('平台集运 · 期次报名'))

@section('content')
@php
    // 依赖指挥窗地基 CentralLogic; 别名收口, 便于取常量 / 总闸 / 进度。
    $R = \App\CentralLogics\NezhaConsolidationRound::class;

    // 总闸(页面侧二次门禁 —— 控制器已 abort(404), 此处为零透出双保险)。
    $enabled = $R::enabled();

    // ---- 期次可报名判定(与控制器 roundState 同口径) ----
    $mutable = false;
    if ($round) {
        $notPastCutoff = empty($round->cutoff_at) || \Carbon\Carbon::now()->lt(\Carbon\Carbon::parse($round->cutoff_at));
        $mutable = ($round->status === 'open') && $notPastCutoff;
    }

    // ---- 本店报名态 ----
    $isEnrolled = $enrollment && $enrollment->status === 'enrolled';

    // ---- 预填数据源: 已报名 → 用报名行; 否则 → 用 v1 问卷 ----
    $decode = function ($json) {
        if (empty($json)) {
            return [];
        }
        $a = json_decode($json, true);
        return is_array($a) ? $a : [];
    };

    $allowedCats = array_column($R::CATEGORIES, 'label');

    // 品类预填: 报名行优先; 否则取问卷品类与白名单的交集。
    if ($enrollment && !empty($enrollment->categories)) {
        $preCats = array_values(array_intersect($decode($enrollment->categories), $allowedCats));
    } elseif ($survey && !empty($survey->categories)) {
        $preCats = array_values(array_intersect($decode($survey->categories), $allowedCats));
    } else {
        $preCats = [];
    }

    // 货量单位预填: 报名行 → 问卷 → 空。
    $preUnit = $enrollment->est_volume_unit ?? ($survey->volume_unit ?? '');

    // 货量数值预填: 仅报名行有精确值(问卷只有档位, 不回填数值)。去掉多余的 .00 尾零。
    $preVal = $enrollment->est_volume_value ?? null;
    $preValDisplay = ($preVal === null || $preVal === '')
        ? ''
        : rtrim(rtrim(number_format((float) $preVal, 2, '.', ''), '0'), '.');

    $preNote = $enrollment->note ?? '';

    // 问卷货量档提示(帮商家想起上次填的量, 仅提示不回填数值)。
    $surveyHint = null;
    if ($survey) {
        if (($survey->volume_unit ?? '') === 'm3' && !empty($survey->volume_m3)) {
            $surveyHint = translate('问卷登记约') . ' ' . $survey->volume_m3 . ' m³';
        } elseif (($survey->volume_unit ?? '') === 'kg' && !empty($survey->weight_kg)) {
            $surveyHint = translate('问卷登记约') . ' ' . $survey->weight_kg . ' kg';
        } elseif (($survey->volume_unit ?? '') === 'box' && !empty($survey->box_count)) {
            $surveyHint = translate('问卷登记约') . ' ' . $survey->box_count . ' ' . translate('箱');
        }
    }

    // ---- 货代与费用(键与 admin 侧写入一致: forwarder_info={name}, pricing_info={unit_price,lead_time,declare_method,forwarder_contact}) ----
    $forwarder = $round ? $decode($round->forwarder_info) : [];
    $pricing   = $round ? $decode($round->pricing_info) : [];
    $fwName    = trim((string) ($forwarder['name'] ?? ''));
    $fwContact = trim((string) ($pricing['forwarder_contact'] ?? ''));
    $priceRows = [];
    foreach (['unit_price' => '单价', 'lead_time' => '时效', 'declare_method' => '申报方式'] as $pk => $plabel) {
        $pv = trim((string) ($pricing[$pk] ?? ''));
        if ($pv !== '') {
            $priceRows[] = ['label' => $plabel, 'value' => $pv];
        }
    }

    // ---- 单位 / 状态标签 ----
    $unitLabels   = $R::UNIT_LABELS;
    $statusLabels = $R::STATUS_LABELS;
    $unitLabel = function ($u) use ($unitLabels) {
        return $unitLabels[$u] ?? $u;
    };
@endphp

@if($enabled)
<style>
    .nzc-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid #d9d9e3;border-radius:999px;padding:6px 14px;font-size:13px;cursor:pointer;margin:0 6px 8px 0;color:#3a3a44;background:#fff;}
    .nzc-chip input{display:none;}
    .nzc-chip:hover{border-color:#b7b7c4;}
    .nzc-chip.has-check{background:#eef0ff;border-color:#8b93f4;color:#3b3fb6;}
    .nzc-flabel{font-size:14px;font-weight:600;margin-bottom:9px;color:#21222c;}
    .nzc-req{font-size:11px;color:#c0392b;background:#fdecea;padding:1px 8px;border-radius:999px;margin-left:6px;font-weight:400;}
    .nzc-opt{font-size:11px;color:#8a8a93;border:1px solid #e2e2e8;padding:1px 8px;border-radius:999px;margin-left:6px;font-weight:400;}
    .nzc-ulabel{font-size:12px;color:#9a9aa3;display:inline-block;min-width:42px;}
    .nzc-sub{font-size:12px;color:#9a9aa3;margin-top:6px;}
    .nzc-food{font-size:11px;color:#b8791f;background:#fdf3e3;padding:1px 7px;border-radius:999px;margin-left:6px;font-weight:400;}
    .nzc-notice{background:#f6f7fb;border-radius:8px;padding:11px 14px;font-size:13px;color:#5f6069;line-height:1.6;}
    .nzc-kv{font-size:13px;color:#4a4a54;margin-bottom:4px;}
    .nzc-kv b{color:#21222c;font-weight:600;margin-right:6px;}
    .nzc-fee{background:#fff8ec;border:1px solid #f2e3c6;border-radius:8px;padding:10px 14px;font-size:13px;color:#8a6d3b;line-height:1.6;}
    .nzc-prog-track{height:10px;border-radius:999px;background:#edeef4;overflow:hidden;}
    .nzc-prog-bar{height:100%;border-radius:999px;background:linear-gradient(90deg,#7c83f0,#5b63d3);}
    .nzc-badge-on{font-size:12px;color:#2e7d32;background:#e8f5e9;padding:2px 10px;border-radius:999px;font-weight:500;}
    .nzc-badge-off{font-size:12px;color:#8a8a93;background:#f0f0f3;padding:2px 10px;border-radius:999px;font-weight:500;}
</style>
<div class="content container-fluid">
    <div class="page-header">
        <h2 class="page-header-title">
            <span class="page-header-icon"><i class="tio-cube"></i></span>
            <span>{{ translate('平台集运 · 期次报名') }}</span>
        </h2>
    </div>

    @if(!$round)
        {{-- 闸开但当前无进行中期次 --}}
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="tio-cube" style="font-size:40px;color:#c3c3cc;"></i>
                <h5 class="mt-3 mb-1">{{ translate('暂无进行中的集运期次') }}</h5>
                <p class="text-muted mb-0" style="font-size:13px;">{{ translate('平台开启新一期集运时，会在此展示货代、价格与报名入口。') }}</p>
            </div>
        </div>
    @else
        {{-- 期次卡 --}}
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
                <h5 class="card-title mb-0 d-inline-block">{{ $round->title }}</h5>
                <span class="{{ $mutable ? 'nzc-badge-on' : 'nzc-badge-off' }}">{{ $statusLabels[$round->status] ?? $round->status }}</span>
            </div>
            <div class="card-body">
                {{-- 期次要素 --}}
                <div class="row g-3 mb-1">
                    <div class="col-md-4">
                        <div class="nzc-kv"><b>{{ translate('期号') }}</b>{{ $round->round_no }}</div>
                        <div class="nzc-kv"><b>{{ translate('报名截止') }}</b>
                            @if(!empty($round->cutoff_at))
                                {{ \Carbon\Carbon::parse($round->cutoff_at)->format('Y-m-d H:i') }}
                            @else
                                {{ translate('未设截止') }}
                            @endif
                        </div>
                    </div>
                    <div class="col-md-4">
                        @if(!empty($round->etd))
                            <div class="nzc-kv"><b>{{ translate('预计发运') }}</b>{{ \Carbon\Carbon::parse($round->etd)->format('Y-m-d') }}</div>
                        @endif
                        @if(!empty($round->eta))
                            <div class="nzc-kv"><b>{{ translate('预计到港') }}</b>{{ \Carbon\Carbon::parse($round->eta)->format('Y-m-d') }}</div>
                        @endif
                    </div>
                    <div class="col-md-4">
                        @if(!empty($round->min_volume_value))
                            <div class="nzc-kv"><b>{{ translate('成团目标') }}</b>{{ rtrim(rtrim(number_format((float)$round->min_volume_value, 2, '.', ''), '0'), '.') }} {{ $unitLabel($round->min_volume_unit) }}</div>
                        @endif
                    </div>
                </div>

                @if(!empty($round->notes))
                    <div class="nzc-notice mt-2 mb-3">{{ $round->notes }}</div>
                @endif

                {{-- 成团进度(只同单位统计) --}}
                @if($progress)
                    <div class="mt-3 mb-2">
                        <div class="d-flex align-items-center justify-content-between mb-2" style="font-size:13px;color:#4a4a54;">
                            <span>
                                {{ translate('已报名') }} <b>{{ $progress['enroll_count'] ?? 0 }}</b> {{ translate('家') }}
                                @if(($progress['sum'] ?? null) !== null)
                                    · {{ translate('已归集') }} <b>{{ rtrim(rtrim(number_format((float)($progress['sum'] ?? 0), 2, '.', ''), '0'), '.') }} {{ $progress['unit_label'] ?? '' }}</b>
                                @endif
                            </span>
                            @if(!empty($progress['min']))
                                <span class="text-muted">{{ translate('目标') }} {{ rtrim(rtrim(number_format((float)$progress['min'], 2, '.', ''), '0'), '.') }} {{ $progress['unit_label'] ?? '' }}</span>
                            @endif
                        </div>
                        <div class="nzc-prog-track">
                            <div class="nzc-prog-bar" style="width:{{ min(100, max(0, (float)($progress['pct'] ?? 0))) }}%;"></div>
                        </div>
                        @if(!empty($progress['other_count']))
                            <p class="nzc-sub">{{ translate('另有') }} {{ $progress['other_count'] }} {{ translate('家以其它单位报名') }}</p>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- 货代与费用(只展示; 无任何收款入口。键与 admin 侧写入一致) --}}
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="card-title mb-0 d-inline-block">{{ translate('货代与费用') }}</h5>
            </div>
            <div class="card-body">
                @if($fwName !== '' || $fwContact !== '')
                    <div class="nzc-kv"><b>{{ translate('货代') }}</b>{{ $fwName !== '' ? $fwName : translate('待定') }}@if($fwContact !== '') · {{ $fwContact }}@endif</div>
                @endif
                @foreach($priceRows as $pr)
                    <div class="nzc-kv"><b>{{ translate($pr['label']) }}</b>{{ $pr['value'] }}</div>
                @endforeach
                {{-- 费用文案: 逐字取自 NezhaConsolidationRound::FEE_NOTE(勿改 / 勿加"平台不收费"等承诺) --}}
                <div class="nzc-fee mt-2"><i class="tio-info-outined"></i> {{ $R::FEE_NOTE }}</div>
            </div>
        </div>

        {{-- 报名区 --}}
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
                <h5 class="card-title mb-0 d-inline-block">{{ translate('本店报名') }}</h5>
                @if($isEnrolled)
                    <span class="nzc-badge-on">{{ translate('已报名本期') }}</span>
                @endif
            </div>
            <div class="card-body">
                @if(!$mutable)
                    {{-- 已截止 / 已关闭: 只读 --}}
                    @if($isEnrolled)
                        <div class="alert alert-secondary py-2 px-3 mb-3" style="font-size:13px;">{{ translate('本期报名已截止，以下为您已提交的报名信息。') }}</div>
                        <div class="nzc-kv"><b>{{ translate('预估货量') }}</b>
                            @if($preValDisplay !== ''){{ $preValDisplay }} {{ $unitLabel($enrollment->est_volume_unit) }}@else{{ translate('未填') }}@endif
                        </div>
                        @if(!empty($preCats))
                            <div class="nzc-kv"><b>{{ translate('品类') }}</b>{{ implode('、', $preCats) }}</div>
                        @endif
                        @if(!empty($preNote))
                            <div class="nzc-kv"><b>{{ translate('备注') }}</b>{{ $preNote }}</div>
                        @endif
                    @else
                        <p class="text-muted mb-0" style="font-size:13px;">{{ translate('本期集运报名已截止或已关闭。') }}</p>
                    @endif
                @else
                    {{-- 可报名 / 可改 --}}
                    @if($isEnrolled)
                        <div class="alert alert-info py-2 px-3 mb-3" style="font-size:13px;">{{ translate('您已报名本期集运，可在截止前修改下方信息或撤销报名。') }}</div>
                    @endif

                    <form action="{{ $isEnrolled ? route('vendor.nezha-consolidation-rounds.update', $enrollment->id) : route('vendor.nezha-consolidation-rounds.store') }}" method="POST">
                        @csrf
                        @unless($isEnrolled)
                            <input type="hidden" name="round_id" value="{{ $round->id }}">
                        @endunless

                        {{-- 品类 --}}
                        <div class="form-group mb-4">
                            <div class="nzc-flabel">{{ translate('拟集运品类') }}<span class="nzc-opt">{{ translate('多选') }}</span></div>
                            @foreach($R::CATEGORIES as $cat)
                                <label class="nzc-chip">
                                    <input type="checkbox" name="categories[]" value="{{ $cat['label'] }}" {{ in_array($cat['label'], $preCats) ? 'checked' : '' }}>
                                    {{ $cat['label'] }}@if(!empty($cat['is_food']))<span class="nzc-food">{{ translate('食品') }}</span>@endif
                                </label>
                            @endforeach
                            {{-- 食品类清关提示: 逐字取自 FOOD_HINT --}}
                            <p class="nzc-sub">{{ translate('食品') }}：{{ $R::FOOD_HINT }}</p>
                        </div>

                        {{-- 预估货量 --}}
                        <div class="form-group mb-4">
                            <div class="nzc-flabel">{{ translate('预估货量') }}<span class="nzc-opt">{{ translate('选填') }}</span></div>
                            <div class="d-flex align-items-center flex-wrap" style="gap:8px;">
                                <input type="number" name="est_volume_value" class="form-control" style="max-width:140px;" step="0.01" min="0" value="{{ $preValDisplay }}" placeholder="{{ translate('如 3.5') }}">
                                <label class="nzc-chip"><input type="radio" name="est_volume_unit" value="m3" {{ $preUnit === 'm3' ? 'checked' : '' }}>{{ $unitLabels['m3'] ?? 'm³' }}</label>
                                <label class="nzc-chip"><input type="radio" name="est_volume_unit" value="kg" {{ $preUnit === 'kg' ? 'checked' : '' }}>{{ $unitLabels['kg'] ?? 'kg' }}</label>
                                <label class="nzc-chip"><input type="radio" name="est_volume_unit" value="box" {{ $preUnit === 'box' ? 'checked' : '' }}>{{ $unitLabels['box'] ?? translate('箱') }}</label>
                            </div>
                            @if($surveyHint && !$isEnrolled)
                                <p class="nzc-sub">{{ $surveyHint }}{{ translate('（供参考，请按本期实际预估填写）') }}</p>
                            @else
                                <p class="nzc-sub">{{ translate('预估即可，成团时以实际交运为准；成团进度只在同一单位内统计。') }}</p>
                            @endif
                        </div>

                        {{-- 备注 --}}
                        <div class="form-group mb-4">
                            <div class="nzc-flabel">{{ translate('备注') }}<span class="nzc-opt">{{ translate('选填') }}</span></div>
                            <textarea name="note" rows="2" class="form-control" maxlength="500" placeholder="{{ translate('特殊说明或需求（选填）') }}">{{ $preNote }}</textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">{{ $isEnrolled ? translate('保存修改') : translate('报名本期集运') }}</button>
                    </form>

                    {{-- 撤销(独立表单, 避免与主表单嵌套) --}}
                    @if($isEnrolled)
                        <form action="{{ route('vendor.nezha-consolidation-rounds.cancel', $enrollment->id) }}" method="POST" class="d-inline-block mt-2" onsubmit="return confirm('{{ translate('确定撤销本期集运报名？') }}');">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger btn-sm">{{ translate('撤销报名') }}</button>
                        </form>
                    @endif
                @endif
            </div>
        </div>
    @endif
</div>

<script>
    (function () {
        function syncChip(inp) {
            if (inp.type === 'radio') {
                document.querySelectorAll('input[name="' + inp.name + '"]').forEach(function (r) {
                    var c = r.closest('.nzc-chip'); if (c) c.classList.toggle('has-check', r.checked);
                });
            } else {
                var c = inp.closest('.nzc-chip'); if (c) c.classList.toggle('has-check', inp.checked);
            }
        }
        document.querySelectorAll('.nzc-chip input').forEach(function (inp) {
            inp.addEventListener('change', function () { syncChip(inp); });
            syncChip(inp);
        });
    })();
</script>
@endif
@endsection
