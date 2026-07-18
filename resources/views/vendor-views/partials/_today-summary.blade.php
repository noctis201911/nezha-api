{{-- 哪吒 M-01 今日经营卡: 今日订单 / 今日自收款(已确认到账) / 保证金健康四档 / 店铺评分(累计)。
     口径已拍板(见规格 §0.1/§5): 自收款只计已确认到账(payment_status=paid), 保证金不显精确天数只显健康四档,
     评分用累计 rating 不造"今日评分"维度。 --}}
@php
    $nz_today = $nz_today ?? [];
    $t_orders    = (int) ($nz_today['today_orders'] ?? 0);
    $t_collected = (float) ($nz_today['today_collected'] ?? 0);
    $dep_balance = (float) ($nz_today['deposit_balance'] ?? 0);
    $dep_tier    = $nz_today['deposit_tier'] ?? 'sample';
    $r_avg       = $nz_today['rating_avg'] ?? 0;
    $r_count     = (int) ($nz_today['rating_count'] ?? 0);
    $rate_cny    = (float) ($nz_today['rate_cny'] ?? 55);
    $rate_usd    = (float) ($nz_today['rate_usd'] ?? 400);

    // 已确认到账折算 ≈¥/≈$(与商家端保证金页同源)
    $collected_cny = $rate_cny > 0 ? $t_collected / $rate_cny : 0;
    $collected_usd = $rate_usd > 0 ? $t_collected / $rate_usd : 0;

    // 保证金健康四档 → 文案 / 配色
    $dep_map = [
        'sufficient'   => ['label' => '充足',   'cls' => 'success',   'note' => ''],
        'low'          => ['label' => '偏低',   'cls' => 'warning',   'note' => '建议尽快充值'],
        'insufficient' => ['label' => '不足',   'cls' => 'danger',    'note' => '余额不足，可能无法接新单'],
        'sample'       => ['label' => '样本不足', 'cls' => 'secondary', 'note' => '暂无足够数据'],
    ];
    $dep = $dep_map[$dep_tier] ?? $dep_map['sample'];
@endphp

<div class="card mb-3 nz-today-summary">
    <div class="card-header p-2">
        <h4 class="card-header-title">今日经营</h4>
    </div>
    <div class="card-body p-2">
        <div class="row g-2">
            {{-- 今日订单数 --}}
            <div class="col-6 col-lg-3">
                <div class="order--card h-100">
                    <span class="card-subtitle d-block mb-1">今日订单</span>
                    @if($t_orders > 0)
                        <span class="card-title h3 mb-0">{{ $t_orders }}</span>
                    @else
                        <span class="card-title h3 mb-0 text-muted">0</span>
                        <small class="d-block text-muted">今天还没有订单</small>
                    @endif
                </div>
            </div>

            {{-- 今日自收款(已确认到账) --}}
            <div class="col-6 col-lg-3">
                <div class="order--card h-100">
                    <span class="card-subtitle d-block mb-1">今日自收款 <small class="text-muted">(已确认到账)</small></span>
                    <span class="card-title h3 mb-0">{{ \App\CentralLogics\Helpers::format_currency($t_collected) }}</span>
                    <small class="d-block text-muted">≈¥{{ number_format($collected_cny, 0) }} / ≈${{ number_format($collected_usd, 0) }}</small>
                </div>
            </div>

            {{-- 保证金余额 + 健康四档 --}}
            <div class="col-6 col-lg-3">
                <div class="order--card h-100 {{ $dep_tier === 'insufficient' ? 'border-danger bg-soft-danger' : '' }}">
                    <span class="card-subtitle d-block mb-1">保证金</span>
                    <span class="card-title h3 mb-0">{{ \App\CentralLogics\Helpers::format_currency($dep_balance) }}</span>
                    <small class="d-block mt-1">
                        健康状态：<span class="badge badge-soft-{{ $dep['cls'] }} text-{{ $dep['cls'] }}">{{ $dep['label'] }}</span>
                        @if($dep['note'])
                            <span class="text-{{ $dep_tier === 'insufficient' ? 'danger' : 'muted' }} d-block mt-1">{{ $dep['note'] }}</span>
                        @endif
                    </small>
                </div>
            </div>

            {{-- 店铺评分(累计) --}}
            <div class="col-6 col-lg-3">
                <div class="order--card h-100">
                    <span class="card-subtitle d-block mb-1">店铺评分（累计）</span>
                    @if($r_count > 0)
                        <span class="card-title h3 mb-0">{{ number_format($r_avg, 1) }} <i class="tio-star text-warning fz--14"></i></span>
                        <small class="d-block text-muted">{{ $r_count }} 条评价</small>
                    @else
                        <span class="card-title h3 mb-0 text-muted">—</span>
                        <small class="d-block text-muted">暂无评价</small>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
