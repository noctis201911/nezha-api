@extends('layouts.admin.app')

@section('title','竞价参数设置')

@section('advertisement')
active
@endsection

@section('advertisement_auction')
active
@endsection

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h1 class="page-header-title">
            <span class="page-header-icon">
                <i class="tio-settings"></i>
            </span>
            <span>广告 · 竞价参数设置</span>
        </h1>
        <p class="text-muted mt-2 mb-0">
            CPC 竞价的平台级参数（L2 业务参数，不碰 L1 资金红线）。改后下一次请求即生效，无需重启。
        </p>
    </div>

    @if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <form action="{{ route('admin.advertisement.auction-settings.update') }}" method="post">
                        @csrf

                        <div class="alert alert-danger" role="alert">
                            <i class="tio-warning"></i>
                            开启「竞价总开关」后，商家 CPC 广告将<strong>按真实点击扣 ad_balance（真金）</strong>。请先确认商家已充值、死亡测试全绿再开。关闭时排序退化到现行 CPT，全链路零行为变化。
                        </div>

                        <div class="form-group">
                            <label class="input-label">竞价总开关 · nezha_ad_auction_status</label>
                            <select name="nezha_ad_auction_status" class="form-control">
                                <option value="0" {{ ($settings['nezha_ad_auction_status'] ?? '0') == '0' ? 'selected' : '' }}>关闭（默认 · 排序退化到现行 CPT，零行为变化）</option>
                                <option value="1" {{ ($settings['nezha_ad_auction_status'] ?? '0') == '1' ? 'selected' : '' }}>开启（CPC 竞价 + 按点击扣 ad_balance）</option>
                            </select>
                        </div>

                        <hr>
                        <h5 class="mb-3">计价参数（德拉姆 ֏）</h5>

                        <div class="form-group">
                            <label class="input-label">单次点击保底价 · floor_price</label>
                            <input type="number" min="1" step="1" name="nezha_ad_floor_price" class="form-control"
                                   value="{{ $settings['nezha_ad_floor_price'] ?? '50' }}">
                            <small class="text-muted">出价低于此值拒投放（必须 &gt; 0，INV-7）。</small>
                        </div>

                        <div class="form-group">
                            <label class="input-label">单次点击封顶 · max_per_click</label>
                            <input type="number" min="1" step="1" name="nezha_ad_max_per_click" class="form-control"
                                   value="{{ $settings['nezha_ad_max_per_click'] ?? '500' }}">
                            <small class="text-muted">单次点击最高扣费，防超大出价烧空（须 ≥ 保底价）。</small>
                        </div>

                        <div class="form-group">
                            <label class="input-label">商家日预算上限 · max_daily_budget</label>
                            <input type="number" min="1" step="1" name="nezha_ad_max_daily_budget" class="form-control"
                                   value="{{ $settings['nezha_ad_max_daily_budget'] ?? '50000' }}">
                            <small class="text-muted">商家设置的日预算不得超过此值（须 ≥ 保底价）。</small>
                        </div>

                        <hr>
                        <h5 class="mb-3">排序保护（防完全广告化 / 防垄断）</h5>

                        <div class="form-group">
                            <label class="input-label">前 N 自然位保留 · natural_reserved_slots</label>
                            <input type="number" min="0" step="1" name="nezha_ad_natural_reserved_slots" class="form-control"
                                   value="{{ $settings['nezha_ad_natural_reserved_slots'] ?? '3' }}">
                            <small class="text-muted">广告不挤掉前 N 个真实排名（0 = 不保留）。</small>
                        </div>

                        <div class="form-group">
                            <label class="input-label">单店同位最多占 · max_share_per_store</label>
                            <input type="number" min="1" step="1" name="nezha_ad_max_share_per_store" class="form-control"
                                   value="{{ $settings['nezha_ad_max_share_per_store'] ?? '3' }}">
                            <small class="text-muted">同一广告位单店最多占 N 位，防垄断（至少 1）。</small>
                        </div>

                        <hr>
                        <h5 class="mb-3">防刷与计费身份 · 系统</h5>

                        <div class="form-group">
                            <label class="input-label">去重窗口（秒） · dedup_window_sec</label>
                            <input type="number" min="0" step="1" name="nezha_ad_dedup_window_sec" class="form-control"
                                   value="{{ $settings['nezha_ad_dedup_window_sec'] ?? '900' }}">
                            <small class="text-muted">同一用户同一广告在此窗口内重复点击只计一次（默认 900 秒 = 15 分钟）。</small>
                        </div>

                        <div class="form-group">
                            <label class="input-label">计费身份下单史阈值 · trusted_min_orders</label>
                            <input type="number" min="0" step="1" name="nezha_ad_trusted_min_orders" class="form-control"
                                   value="{{ $settings['nezha_ad_trusted_min_orders'] ?? '1' }}">
                            <small class="text-muted">登录 + 真实下单史 ≥ N 的用户点击才计费；不可信流量只记录不扣费（INV-4）。</small>
                        </div>

                        <div class="form-group">
                            <label class="input-label">物化重算间隔（分钟） · recompute_min</label>
                            <input type="number" min="1" step="1" name="nezha_ad_recompute_min" class="form-control"
                                   value="{{ $settings['nezha_ad_recompute_min'] ?? '5' }}">
                            <small class="text-danger">⚠️ 实际重算调度当前固定为每 5 分钟（写死在 bootstrap 调度，DB 读间隔会拖垮 artisan）。改此值只记录意图，真正改频率需改代码并重新部署。</small>
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
