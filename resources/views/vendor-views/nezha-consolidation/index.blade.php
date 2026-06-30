@extends('layouts.vendor.app')

@section('title', translate('平台集运申报'))

@section('content')
@php
    $sv = $survey ?? null;
    $arr = fn ($json) => $json ? (json_decode($json, true) ?: []) : [];
    $chCur  = $sv ? $arr($sv->current_channels) : [];
    $chPain = $sv ? $arr($sv->pain_points) : [];
    $chCat  = $sv ? $arr($sv->categories) : [];
    $rc = fn ($field, $v) => ($sv && $sv->$field === $v) ? 'checked' : '';      // radio 预填
    $cc = fn ($label, $list) => in_array($label, $list) ? 'checked' : '';        // checkbox 预填
    $tv = fn ($field) => $sv ? e($sv->$field) : '';                              // text 预填

    $chanOpts = ['自己找货代物流', '托人带', '本地批发(不进口)'];
    $painOpts = ['运费贵', '时效慢不稳', '清关麻烦', '量少没人接', '敏货(粉末/液体/带电类)找不到渠道', '丢损破损', '找不到靠谱物流'];
    $catOpts  = ['干货 / 常温食材', '厨房用具 / 设备', '包装 / 一次性用品', '超市百货 / 日用'];
    $timesOpts = [['0', '0 次'], ['1-2', '1–2 次'], ['3-5', '3–5 次'], ['6+', '6 次以上']];
    $volOpts  = [['<1', '<1 m³'], ['1-3', '1–3 m³'], ['3-5', '3–5 m³'], ['5-10', '5–10 m³'], ['>10', '>10 m³']];
    $wtOpts   = [['<100', '<100 kg'], ['100-500', '100–500'], ['500-1000', '500–1000'], ['>1000', '>1000 kg']];
    $freqOpts = [['weekly', '每周'], ['biweekly', '每两周'], ['monthly', '每月'], ['quarterly', '每季度'], ['irregular', '不定期']];
    $leadOpts = [['fast', '越快越好(空运档)'], ['mid', '2–4 周可接受'], ['slow', '1–2 个月也行(成本优先)']];
    $saveOpts = [['little', '省一点就参加'], ['s15', '省 15%+'], ['s30', '省 30%+']];
@endphp
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
    .nzc-bn i{font-size:20px;color:#5b63d3;}
    .nzc-bn b{display:block;font-size:14px;margin:6px 0 2px;}
    .nzc-bn span{font-size:13px;color:#6b6b75;}
    .nzc-notice{background:#f6f7fb;border-radius:8px;padding:11px 14px;font-size:13px;color:#5f6069;line-height:1.6;}
</style>
<div class="content container-fluid">
    <div class="page-header">
        <h2 class="page-header-title">
            <span class="page-header-icon"><i class="tio-cube"></i></span>
            <span>{{ translate('平台集运申报') }}</span>
        </h2>
    </div>

    {{-- 说明 / 引导 --}}
    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-2">{{ translate('拼货出海，把您的进货运费降下来') }}</h5>
            <p class="mb-2">{{ translate('由平台收集各位入驻商家的货运需求，将商家的货运需求拼成整车 / 整柜，用集体货量谈更低价，帮您降运费、省去自己找物流的麻烦。') }}</p>
            <p class="mb-3">{{ translate('该服务面向深度合作商家开放——欢迎您填写下方需求登记表，平台会想办法为您降本、解决进货难题，符合条件后平台将有工作人员与您联系。如有其他需求，也可在后台向平台工作人员反馈，平台将对反馈较多的问题进行改善或寻找相关服务商解决。感谢您的支持。') }}</p>
            <div class="row g-2 mb-3">
                <div class="col-md-4"><div class="nzc-bn"><i class="tio-percent"></i><b>{{ translate('降低运费') }}</b><span>{{ translate('集体货量，谈更低的价') }}</span></div></div>
                <div class="col-md-4"><div class="nzc-bn"><i class="tio-sentiment-very-satisfied"></i><b>{{ translate('进货省心') }}</b><span>{{ translate('不用自己找物流清关') }}</span></div></div>
                <div class="col-md-4"><div class="nzc-bn"><i class="tio-verified"></i><b>{{ translate('定向开放') }}</b><span>{{ translate('面向深度合作商家') }}</span></div></div>
            </div>
            <div class="nzc-notice">
                <i class="tio-info-outined"></i>
                {{ translate('为避免恶性竞价、减少商家参与顾虑，平台集运仅面向经营达标的深度合作商家开放；申报货物须为店面主营相关品类，可含少量自用物品。') }}
            </div>
        </div>
    </div>

    {{-- 需求登记表 --}}
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0 d-inline-block">{{ translate('需求登记表') }}</h5>
            <small class="text-muted ml-2" style="font-weight:400;">
                @if($sv)
                    {{ translate('上次提交') }}: {{ \Carbon\Carbon::parse($sv->updated_at)->format('Y-m-d H:i') }} · {{ translate('可随时更新') }}
                @else
                    {{ translate('约 1 分钟 · 尚未提交') }}
                @endif
            </small>
        </div>
        <div class="card-body">
            <form action="{{ route('vendor.nezha-consolidation.store') }}" method="POST">
                @csrf

                <div class="form-group mb-4">
                    <div class="nzc-flabel">① {{ translate('您是否有意参加平台集运？') }}<span class="nzc-req">{{ translate('必填') }}</span></div>
                    <label class="nzc-chip"><input type="radio" name="intent" value="yes" {{ $rc('intent','yes') }}>{{ translate('有意向') }}</label>
                    <label class="nzc-chip"><input type="radio" name="intent" value="maybe" {{ $rc('intent','maybe') }}>{{ translate('看价格再定') }}</label>
                    <label class="nzc-chip"><input type="radio" name="intent" value="no" {{ $rc('intent','no') }}>{{ translate('暂不需要') }}</label>
                </div>

                <div class="form-group mb-4">
                    <div class="nzc-flabel">② {{ translate('您现在怎么进货 / 最头疼什么？') }}<span class="nzc-opt">{{ translate('选填 · 多选') }}</span></div>
                    <div class="mb-2">
                        @foreach($chanOpts as $o)
                            <label class="nzc-chip"><input type="checkbox" name="current_channels[]" value="{{ $o }}" {{ $cc($o,$chCur) }}>{{ translate($o) }}</label>
                        @endforeach
                    </div>
                    @foreach($painOpts as $o)
                        <label class="nzc-chip"><input type="checkbox" name="pain_points[]" value="{{ $o }}" {{ $cc($o,$chPain) }}>{{ translate($o) }}</label>
                    @endforeach
                </div>

                <div class="form-group mb-4">
                    <div class="nzc-flabel">③ {{ translate('想集运的货物品类') }}<span class="nzc-req">{{ translate('必填 · 多选') }}</span></div>
                    @foreach($catOpts as $o)
                        <label class="nzc-chip"><input type="checkbox" name="categories[]" value="{{ $o }}" {{ $cc($o,$chCat) }}>{{ translate($o) }}</label>
                    @endforeach
                    <div class="d-flex align-items-center flex-wrap mt-1" style="gap:8px;">
                        <label class="nzc-chip"><input type="checkbox" name="categories[]" value="其它" {{ $cc('其它',$chCat) }}>{{ translate('其它') }}</label>
                        <input type="text" name="category_other" class="form-control" style="max-width:240px;" value="{{ $tv('category_other') }}" placeholder="{{ translate('其它品类，请填写') }}">
                    </div>
                    <input type="text" name="category_examples" class="form-control mt-2" style="max-width:420px;" value="{{ $tv('category_examples') }}" placeholder="{{ translate('可选：常进的具体品名举例（帮我们做报关归类）') }}">
                    <p class="nzc-sub">{{ translate('暂不接冷冻 / 生鲜。') }}</p>
                </div>

                <div class="form-group mb-4">
                    <div class="nzc-flabel">④ {{ translate('货量（按过去 3 个月真实进货填）') }}<span class="nzc-req">{{ translate('必填') }}</span></div>
                    <p class="nzc-sub mb-2">{{ translate('过去 3 个月进了几次货？') }}</p>
                    <div class="mb-3">
                        @foreach($timesOpts as $o)
                            <label class="nzc-chip"><input type="radio" name="times_3m" value="{{ $o[0] }}" {{ $rc('times_3m',$o[0]) }}>{{ $o[1] }}</label>
                        @endforeach
                    </div>
                    <p class="nzc-sub mb-2">{{ translate('平均每次大约多少？（体积 / 重量 / 箱数，任填一种即可）') }}</p>
                    <div class="d-flex align-items-center flex-wrap mb-1"><span class="nzc-ulabel">{{ translate('体积') }}</span>
                        @foreach($volOpts as $o)
                            <label class="nzc-chip"><input type="radio" name="volume_m3" value="{{ $o[0] }}" {{ $rc('volume_m3',$o[0]) }}>{{ $o[1] }}</label>
                        @endforeach
                    </div>
                    <div class="d-flex align-items-center flex-wrap mb-1"><span class="nzc-ulabel">{{ translate('重量') }}</span>
                        @foreach($wtOpts as $o)
                            <label class="nzc-chip"><input type="radio" name="weight_kg" value="{{ $o[0] }}" {{ $rc('weight_kg',$o[0]) }}>{{ $o[1] }}</label>
                        @endforeach
                    </div>
                    <div class="d-flex align-items-center flex-wrap" style="gap:8px;"><span class="nzc-ulabel">{{ translate('箱数') }}</span>
                        <input type="text" name="box_count" class="form-control" style="max-width:90px;" value="{{ $tv('box_count') }}" placeholder="{{ translate('如 20') }}">
                        <span class="text-muted" style="font-size:13px;">{{ translate('箱，每箱大致尺寸') }}</span>
                        <input type="text" name="box_size" class="form-control" style="max-width:170px;" value="{{ $tv('box_size') }}" placeholder="{{ translate('如 60×40×40cm') }}">
                    </div>
                </div>

                <div class="form-group mb-4">
                    <div class="nzc-flabel">⑤ {{ translate('进货频率') }}<span class="nzc-req">{{ translate('必填') }}</span></div>
                    @foreach($freqOpts as $o)
                        <label class="nzc-chip"><input type="radio" name="frequency" value="{{ $o[0] }}" {{ $rc('frequency',$o[0]) }}>{{ translate($o[1]) }}</label>
                    @endforeach
                </div>

                <div class="form-group mb-4">
                    <div class="nzc-flabel">⑥ {{ translate('能接受的运输时长') }}<span class="nzc-opt">{{ translate('选填') }}</span></div>
                    @foreach($leadOpts as $o)
                        <label class="nzc-chip"><input type="radio" name="lead_time" value="{{ $o[0] }}" {{ $rc('lead_time',$o[0]) }}>{{ translate($o[1]) }}</label>
                    @endforeach
                </div>

                <div class="form-group mb-4">
                    <div class="nzc-flabel">⑦ {{ translate('目前物流成本与期望') }}<span class="nzc-opt">{{ translate('选填') }}</span></div>
                    <div class="d-flex align-items-center flex-wrap mb-2" style="gap:8px;">
                        <span class="text-muted" style="font-size:13px;">{{ translate('目前物流成本约为') }}</span>
                        <input type="text" name="current_cost" class="form-control" style="max-width:280px;" value="{{ $tv('current_cost') }}" placeholder="{{ translate('如 8 美元/公斤、或每次约多少') }}">
                    </div>
                    <p class="nzc-sub mb-2">{{ translate('期望平台帮您降多少？') }}</p>
                    @foreach($saveOpts as $o)
                        <label class="nzc-chip"><input type="radio" name="expected_saving" value="{{ $o[0] }}" {{ $rc('expected_saving',$o[0]) }}>{{ translate($o[1]) }}</label>
                    @endforeach
                </div>

                <div class="form-group mb-4">
                    <div class="nzc-flabel">⑧ {{ translate('您是否愿意向平台推荐物流 / 货代 / 集运服务商？') }}<span class="nzc-opt">{{ translate('选填') }}</span></div>
                    <div class="mb-2">
                        <label class="nzc-chip"><input type="radio" name="refer_provider" value="yes" {{ $rc('refer_provider','yes') }}>{{ translate('愿意推荐') }}</label>
                        <label class="nzc-chip"><input type="radio" name="refer_provider" value="no" {{ $rc('refer_provider','no') }}>{{ translate('暂时没有') }}</label>
                    </div>
                    <input type="text" name="refer_provider_info" class="form-control" style="max-width:380px;" value="{{ $tv('refer_provider_info') }}" placeholder="{{ translate('服务商名称 + 联系方式（选填）') }}">
                </div>

                <div class="form-group mb-4">
                    <div class="nzc-flabel">⑨ {{ translate('其它建议') }}<span class="nzc-opt">{{ translate('选填') }}</span></div>
                    <textarea name="suggestion" rows="2" class="form-control" placeholder="{{ translate('您的建议或特殊需求') }}">{{ $sv->suggestion ?? '' }}</textarea>
                </div>

                <button type="submit" class="btn btn-primary">{{ translate('提交集运需求') }}</button>
            </form>
        </div>
    </div>
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
@endsection
