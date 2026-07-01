@extends('layouts.vendor.app')

@section('title', translate('对账中心'))

@section('content')
@php
    // 德拉姆(֏)金额折算 ≈¥ / ≈$ 展示(汇率来自 business_settings, 与全站结算同源; 仅展示, 不碰钱)。
    $nezhaConv = function ($amd) use ($rateCny, $rateUsd) {
        $cny = $rateCny > 0 ? $amd / $rateCny : 0;
        $usd = $rateUsd > 0 ? $amd / $rateUsd : 0;
        return '≈ ¥' . number_format($cny, 2) . '  ·  ≈ $' . number_format($usd, 2);
    };
    $typeLabels = [
        'recharge'             => translate('充值'),
        'commission_deduction' => translate('扣佣'),
        'refund_reversal'      => translate('退款返还'),
        'advertisement_fee'    => translate('广告费(按天)'),
        'ad_recharge'          => translate('广告充值'),
        'ad_click_fee'         => translate('广告点击费'),
        'guarantee_deposit'    => translate('押金缴纳'),
        'guarantee_refund'     => translate('押金退还'),
    ];
    $tzn = 'Asia/Yerevan';
    $qMonthFrom = \Carbon\Carbon::now($tzn)->startOfMonth()->toDateString();
    $qToday     = \Carbon\Carbon::now($tzn)->toDateString();
    $q30From    = \Carbon\Carbon::now($tzn)->subDays(29)->toDateString();
    $adFee = (float) ($byType['advertisement_fee'] ?? 0);
@endphp
<style>
    /* 对账中心: 把这页的次要小字抬到可读字号 + 加深浅灰(仅本页作用域) */
    .nz-recon .small, .nz-recon small { font-size: 14px; }
    .nz-recon .text-muted { color: #5b6472; }
    .nz-recon .table td, .nz-recon .table th { font-size: 14px; }
</style>
<div class="content container-fluid nz-recon">
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col-sm mb-2 mb-sm-0">
                <h2 class="page-header-title text-capitalize">
                    <span class="card-header-icon d-inline-flex mr-2"><i class="tio-wallet"></i></span>
                    <span>{{ translate('对账中心') }}</span>
                </h2>
                <p class="text-muted small mb-0">{{ translate('与平台的全部财务往来, 按账户分开查、可导出对账单。') }}</p>
            </div>
        </div>
    </div>

    {{-- 账户总览 --}}
    <div class="row g-3 mb-3">
        <div class="col-md-4 mb-3 mb-md-0">
            <div class="card h-100 {{ $account === 'deposit' ? 'border-primary' : '' }}">
                <div class="card-body">
                    <h6 class="text-muted mb-1">{{ translate('预存佣金余额') }}</h6>
                    <h3 class="mb-1 {{ $depositBalance < 0 ? 'text-danger' : '' }}">{{ \App\CentralLogics\Helpers::format_currency($depositBalance) }}</h3>
                    <p class="text-muted small mb-0">{{ $nezhaConv($depositBalance) }}</p>
                    @if($depositBalance < 0)
                        <p class="text-danger small mb-0">{{ translate('已欠平台佣金, 请尽快充值, 否则无法接单。') }}</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3 mb-md-0">
            <div class="card h-100 {{ $account === 'ad' ? 'border-primary' : '' }}">
                <div class="card-body">
                    <h6 class="text-muted mb-1">{{ translate('广告余额') }}</h6>
                    <h3 class="mb-1">{{ \App\CentralLogics\Helpers::format_currency($adBalance) }}</h3>
                    <p class="text-muted small mb-0">{{ $nezhaConv($adBalance) }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 {{ $account === 'guarantee' ? 'border-primary' : '' }}">
                <div class="card-body">
                    <h6 class="text-muted mb-1">{{ translate('押金') }}</h6>
                    <h3 class="mb-1">{{ \App\CentralLogics\Helpers::format_currency($guaranteeBalance ?? 0) }}</h3>
                    <p class="text-muted small mb-0">{{ $nezhaConv($guaranteeBalance ?? 0) }}</p>
                    <p class="text-muted small mb-0">{{ translate('可退质押账户(由平台按档设定)') }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- 账户切换 --}}
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link {{ $account === 'deposit' ? 'active' : '' }}" href="{{ route('vendor.nezha-deposit.index', ['account' => 'deposit', 'from' => $from, 'to' => $to]) }}">{{ translate('预存佣金') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $account === 'ad' ? 'active' : '' }}" href="{{ route('vendor.nezha-deposit.index', ['account' => 'ad', 'from' => $from, 'to' => $to]) }}">{{ translate('广告') }}</a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $account === 'guarantee' ? 'active' : '' }}" href="{{ route('vendor.nezha-deposit.index', ['account' => 'guarantee', 'from' => $from, 'to' => $to]) }}">{{ translate('押金') }}</a>
        </li>
    </ul>

    {{-- 日期筛选 + 导出 --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-end" style="gap:12px;">
                <form method="GET" action="{{ route('vendor.nezha-deposit.index') }}" class="d-flex flex-wrap align-items-end" style="gap:12px;">
                    <input type="hidden" name="account" value="{{ $account }}">
                    <div class="form-group mb-0">
                        <label class="input-label mb-1">{{ translate('开始日期') }}</label>
                        <input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm" style="min-width:150px;">
                    </div>
                    <div class="form-group mb-0">
                        <label class="input-label mb-1">{{ translate('结束日期') }}</label>
                        <input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm" style="min-width:150px;">
                    </div>
                    <div class="form-group mb-0">
                        <button type="submit" class="btn btn-sm btn-primary">{{ translate('查询') }}</button>
                    </div>
                </form>
                <div class="form-group mb-0">
                    <a class="btn btn-sm btn-white" href="{{ route('vendor.nezha-deposit.index', ['account' => $account, 'from' => $qMonthFrom, 'to' => $qToday]) }}">{{ translate('本月') }}</a>
                    <a class="btn btn-sm btn-white" href="{{ route('vendor.nezha-deposit.index', ['account' => $account, 'from' => $q30From, 'to' => $qToday]) }}">{{ translate('近30天') }}</a>
                </div>
                <div class="form-group mb-0 ml-md-auto">
                    <a class="btn btn-sm btn-white" href="{{ route('vendor.nezha-deposit.export', ['account' => $account, 'from' => $from, 'to' => $to, 'type' => 'excel']) }}"><i class="tio-download-to mr-1"></i>{{ translate('导出 Excel') }}</a>
                    <a class="btn btn-sm btn-white" href="{{ route('vendor.nezha-deposit.export', ['account' => $account, 'from' => $from, 'to' => $to, 'type' => 'csv']) }}"><i class="tio-download-to mr-1"></i>{{ translate('导出 CSV') }}</a>
                </div>
            </div>
        </div>
    </div>

    {{-- 本期汇总 --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="row text-center">
                <div class="col">
                    <div class="text-muted small">{{ translate('期初余额') }}</div>
                    <div class="font-weight-bold">{{ \App\CentralLogics\Helpers::format_currency($opening) }}</div>
                </div>
                @if($account === 'deposit')
                    <div class="col">
                        <div class="text-muted small">{{ translate('本期充值') }}</div>
                        <div class="font-weight-bold text-success">{{ \App\CentralLogics\Helpers::format_currency($byType['recharge'] ?? 0) }}</div>
                    </div>
                    <div class="col">
                        <div class="text-muted small">{{ translate('本期扣佣') }}</div>
                        <div class="font-weight-bold text-danger">{{ \App\CentralLogics\Helpers::format_currency($byType['commission_deduction'] ?? 0) }}</div>
                    </div>
                    <div class="col">
                        <div class="text-muted small">{{ translate('退款返还') }}</div>
                        <div class="font-weight-bold text-success">{{ \App\CentralLogics\Helpers::format_currency($byType['refund_reversal'] ?? 0) }}</div>
                    </div>
                    @if($adFee != 0)
                    <div class="col">
                        <div class="text-muted small">{{ translate('广告费(按天)') }}</div>
                        <div class="font-weight-bold {{ $adFee < 0 ? 'text-danger' : 'text-success' }}">{{ \App\CentralLogics\Helpers::format_currency($adFee) }}</div>
                    </div>
                    @endif
                @elseif($account === 'ad')
                    <div class="col">
                        <div class="text-muted small">{{ translate('本期充值') }}</div>
                        <div class="font-weight-bold text-success">{{ \App\CentralLogics\Helpers::format_currency($byType['ad_recharge'] ?? 0) }}</div>
                    </div>
                    <div class="col">
                        <div class="text-muted small">{{ translate('本期点击费') }}</div>
                        <div class="font-weight-bold text-danger">{{ \App\CentralLogics\Helpers::format_currency($byType['ad_click_fee'] ?? 0) }}</div>
                    </div>
                @else
                    <div class="col">
                        <div class="text-muted small">{{ translate('本期缴纳') }}</div>
                        <div class="font-weight-bold text-success">{{ \App\CentralLogics\Helpers::format_currency($byType['guarantee_deposit'] ?? 0) }}</div>
                    </div>
                    <div class="col">
                        <div class="text-muted small">{{ translate('本期退还') }}</div>
                        <div class="font-weight-bold text-danger">{{ \App\CentralLogics\Helpers::format_currency($byType['guarantee_refund'] ?? 0) }}</div>
                    </div>
                @endif
                <div class="col border-left">
                    <div class="text-muted small">{{ translate('期末余额') }}</div>
                    <div class="font-weight-bold {{ $closing < 0 ? 'text-danger' : '' }}">{{ \App\CentralLogics\Helpers::format_currency($closing) }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- 流水 --}}
    <div class="card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">{{ $account === 'ad' ? translate('广告流水') : ($account === 'guarantee' ? translate('押金流水') : translate('预存佣金流水')) }}</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('时间') }}</th>
                            <th>{{ translate('类型') }}</th>
                            <th class="text-right">{{ translate('变动') }}</th>
                            <th class="text-right">{{ translate('变动后余额') }}</th>
                            <th>{{ translate('订单') }}</th>
                            <th>{{ translate('备注') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($transactions as $t)
                            <tr>
                                <td><small>{{ \Carbon\Carbon::parse($t->created_at)->format('Y-m-d H:i') }}</small></td>
                                <td>{{ $typeLabels[$t->type] ?? $t->type }}</td>
                                <td class="text-right {{ $t->amount < 0 ? 'text-danger' : 'text-success' }}">
                                    {{ \App\CentralLogics\Helpers::format_currency($t->amount) }}
                                    <div class="small text-muted">{{ $nezhaConv($t->amount) }}</div>
                                </td>
                                <td class="text-right {{ $t->balance_after < 0 ? 'text-danger font-weight-bold' : '' }}">
                                    {{ \App\CentralLogics\Helpers::format_currency($t->balance_after) }}
                                    <div class="small text-muted">{{ $nezhaConv($t->balance_after) }}</div>
                                </td>
                                <td>{{ $t->order_id ? ('#'.$t->order_id) : '—' }}</td>
                                <td><small>{{ $t->note }}</small></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center py-4 text-muted">{{ translate('本区间暂无流水') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3 d-flex justify-content-end">{!! $transactions->links() !!}</div>
        </div>
    </div>

    @if($account === 'deposit')
    {{-- 充值说明 --}}
    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-2">{{ translate('如何充值?') }}</h5>
            <p class="mb-0" style="font-size: 16px; line-height: 1.75; color: #475569;">{{ translate('请按平台告知的方式把预存佣金转给平台, 平台运营核实后会为您入账, 余额即时增加。预存佣金是您预付给平台的佣金(B2B), 与顾客货款无关。如需充值请联系平台客服。') }}</p>
        </div>
    </div>

    {{-- 低额告警设置(仅预存佣金账户) --}}
    <div class="card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">{{ translate('余额不足邮件提醒') }}</h5></div>
        <div class="card-body">
            <form action="{{ route('vendor.nezha-deposit.update-alert') }}" method="POST">
                @csrf
                @php $r = $restaurant; @endphp
                <div class="form-group">
                    <label class="toggle-switch d-flex align-items-center" for="alert_enabled">
                        <input type="checkbox" class="toggle-switch-input" id="alert_enabled" name="deposit_alert_enabled" value="1" {{ ($r && $r->deposit_alert_enabled) ? 'checked' : '' }}>
                        <span class="toggle-switch-label mr-2"><span class="toggle-switch-indicator"></span></span>
                        <span>{{ translate('开启余额不足邮件提醒') }}</span>
                    </label>
                </div>
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label class="input-label">{{ translate('当余额低于') }}</label>
                        <div class="input-group">
                            <div class="input-group-prepend"><span class="input-group-text">֏</span></div>
                            <input type="number" step="0.01" min="0" name="deposit_alert_threshold" id="deposit_alert_threshold" class="form-control" value="{{ $r->deposit_alert_threshold ?? '' }}" placeholder="{{ translate('如: 5000') }}">
                        </div>
                        <small class="text-muted d-block">{{ translate('单位为德拉姆(֏), 与上方余额一致。') }} <span id="nz_thr_conv" class="text-primary"></span></small>
                        <small class="text-muted d-block">{{ translate('余额低于这个数(或为负)时, 每天最多给您发一封提醒邮件。') }}</small>
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="input-label">{{ translate('提醒发送到的邮箱') }}</label>
                        <input type="email" name="deposit_alert_email" class="form-control" value="{{ $r->deposit_alert_email ?? '' }}" placeholder="you@example.com">
                        <small class="text-muted">{{ translate('可填与注册邮箱不同的邮箱。') }}</small>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">{{ translate('messages.save') }}</button>
            </form>
        </div>
    </div>
    <script>
        (function () {
            var i = document.getElementById('deposit_alert_threshold'),
                o = document.getElementById('nz_thr_conv');
            if (!i || !o) return;
            var rc = {{ $rateCny > 0 ? $rateCny : 55 }}, ru = {{ $rateUsd > 0 ? $rateUsd : 400 }};
            function upd() {
                var v = parseFloat(i.value) || 0;
                o.textContent = '≈ ¥' + (rc > 0 ? (v / rc) : 0).toFixed(2) + ' · ≈ $' + (ru > 0 ? (v / ru) : 0).toFixed(2);
            }
            i.addEventListener('input', upd); upd();
        })();
    </script>
    @elseif($account === 'guarantee')
    {{-- 押金说明 --}}
    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-2">{{ translate('关于押金') }}</h5>
            <p class="mb-0" style="font-size: 16px; line-height: 1.75; color: #475569;">{{ translate('押金是您向平台缴纳的可退质押金(B2B), 与顾客货款无关。缴纳档位由平台按您的经营情况设定, 缴纳与退还由平台运营办理并在此对账留痕。退出平台时按规结算、原路退回您本人的收款账户。') }}</p>
        </div>
    </div>
    @endif
</div>
@endsection
