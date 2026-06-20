@extends('layouts.admin.app')

@section('title','广告计费设置')

@section('advertisement')
active
@endsection

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">
            <span class="page-header-icon">
                <i class="tio-settings"></i>
            </span>
            <span>广告计费设置</span>
        </h1>
        <p class="text-muted mt-2 mb-0">
            控制商家投放广告是否收费、单价与曝光加权。资金性质：平台向商家收自己的广告服务费、从商家保证金扣，不碰顾客的钱（L2 业务参数）。
        </p>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <form action="{{ route('admin.advertisement.billing-settings.update') }}" method="post">
                        @csrf

                        <div class="alert alert-warning" role="alert">
                            <i class="tio-warning"></i>
                            开启「计费」后，商家投放广告将从其保证金扣费；关闭时完全走现有免费审核流程。修改后立即生效，请确认对商家的影响。
                        </div>

                        <div class="form-group">
                            <label class="input-label">计费总开关</label>
                            <select name="nezha_ad_billing_status" class="form-control">
                                <option value="0" {{ ($settings['nezha_ad_billing_status'] ?? '0') == '0' ? 'selected' : '' }}>关闭（免费投放，现有流程）</option>
                                <option value="1" {{ ($settings['nezha_ad_billing_status'] ?? '0') == '1' ? 'selected' : '' }}>开启（按天计费，从保证金扣费）</option>
                            </select>
                            <small class="text-muted">默认关。建议先免费跑一阵看有无商家投放，再开收费。</small>
                        </div>

                        <div class="form-group">
                            <label class="input-label">广告单价（德拉姆 ֏ / 天）</label>
                            <input type="number" min="0" step="1" name="nezha_ad_price_per_day" class="form-control"
                                   value="{{ $settings['nezha_ad_price_per_day'] ?? '1000' }}">
                            <small class="text-muted">广告费 = 单价 × 投放天数。例：1000 ֏/天 × 7 天 = 7000 ֏。</small>
                        </div>

                        <div class="form-group">
                            <label class="input-label">曝光加权系数</label>
                            <input type="number" min="0" max="2" step="0.1" name="nezha_ad_boost_weight" class="form-control"
                                   value="{{ $settings['nezha_ad_boost_weight'] ?? '0.5' }}">
                            <small class="text-muted">已扣费且在投放期内的餐馆，综合排序分数 + 此值（基础分约 0~1）。0.5 = 明显提升但不强制置顶；调太高会让排序失真。</small>
                        </div>

                        <div class="form-group">
                            <label class="input-label">平台强制下架退费</label>
                            <select name="nezha_ad_refund_on_platform_takedown" class="form-control">
                                <option value="1" {{ ($settings['nezha_ad_refund_on_platform_takedown'] ?? '1') == '1' ? 'selected' : '' }}>开启（平台/超管强制下架已扣费广告，按未投放天数比例退回保证金）</option>
                                <option value="0" {{ ($settings['nezha_ad_refund_on_platform_takedown'] ?? '1') == '0' ? 'selected' : '' }}>关闭（一律不退）</option>
                            </select>
                            <small class="text-muted">商家「自己中途停投」永远不退；此开关只管「平台主动下架」是否按比例退。</small>
                        </div>

                        <div class="btn--container justify-content-end">
                            <button type="submit" class="btn btn--primary">保存设置</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
