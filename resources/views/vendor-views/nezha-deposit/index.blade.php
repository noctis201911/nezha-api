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
        'deposit_refund'       => translate('预存佣金退还'),
        'advertisement_fee'    => translate('广告费(按天)'),
        'ad_recharge'          => translate('广告充值'),
        'ad_click_fee'         => translate('广告点击费'),
        'ad_refund'            => translate('广告余额退还'),
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

    @php $tpOpen = \App\CentralLogics\NezhaTopup::accountOpen($account); @endphp
    @if($tpOpen)
    @php
        $tpPay = \App\CentralLogics\NezhaTopup::payInfo();
        [$tpMin, $tpMax] = \App\CentralLogics\NezhaTopup::bounds();
        $tpVid = $restaurant->vendor_id ?? \App\CentralLogics\Helpers::get_vendor_id();
        $tpLast = \App\CentralLogics\NezhaTopup::latestRequest((int) $tpVid, $account);
    @endphp
    <div class="card mb-3" id="nz-topup-card">
        <div class="card-header"><h5 class="card-title mb-0">{{ translate('申请充值') }}</h5></div>
        <div class="card-body">
            @if($tpLast && $tpLast->status === 'pending')
                <div class="alert alert-soft-warning d-flex justify-content-between align-items-center flex-wrap">
                    <div class="mr-2 mb-2 mb-sm-0">
                        <strong>{{ translate('审核中') }}</strong> · {{ translate('提交于') }} {{ \Carbon\Carbon::parse($tpLast->created_at)->format('m-d H:i') }} · {{ \App\CentralLogics\Helpers::format_currency($tpLast->amount_claimed) }}
                        <div class="small text-muted mb-0">{{ translate('平台核对到账后为您入账。') }}</div>
                    </div>
                    <form action="{{ route('vendor.nezha-deposit.topup-cancel') }}" method="POST" onsubmit="return confirm('{{ translate('确认撤回这笔充值申请?') }}');">
                        @csrf
                        <input type="hidden" name="id" value="{{ $tpLast->id }}">
                        <button type="submit" class="btn btn-sm btn-white">{{ translate('撤回') }}</button>
                    </form>
                </div>
            @elseif($tpLast && $tpLast->status === 'approved' && $tpLast->reviewed_at && \Carbon\Carbon::parse($tpLast->reviewed_at)->gt(\Carbon\Carbon::now()->subDays(2)))
                <div class="alert alert-soft-success mb-3">
                    <strong>{{ translate('已入账') }}</strong> {{ \App\CentralLogics\Helpers::format_currency($tpLast->amount_credited ?? $tpLast->amount_claimed) }} · {{ translate('余额已更新') }}
                </div>
            @elseif($tpLast && $tpLast->status === 'rejected')
                <div class="alert alert-soft-danger mb-3">
                    <strong>{{ translate('已打回') }}</strong>@if($tpLast->reason) · {{ $tpLast->reason }}@endif
                    <div class="small text-muted mb-0">{{ translate('请核对后重新提交。') }}</div>
                </div>
            @endif
            <div class="row">
                <div class="col-md-5 mb-3 mb-md-0 text-center">
                    @if($tpPay['qr'])
                        <img src="{{ asset('storage/' . $tpPay['qr']) }}?v={{ \Carbon\Carbon::now()->format('ymd') }}" alt="{{ translate('收款码') }}" style="width:200px;max-width:100%;height:auto;border:1px solid #e5e7eb;border-radius:10px;">
                    @else
                        <div class="d-inline-flex align-items-center justify-content-center text-muted mx-auto" style="width:200px;height:200px;border:1px dashed #cbd5e1;border-radius:10px;">{{ translate('收款码待配置') }}</div>
                    @endif
                    <div class="mt-2"><strong>{{ translate('收款方') }}: {{ $tpPay['name'] }}</strong></div>
                    @if($tpPay['account'])
                        <div class="mt-1">{{ translate('支付宝账号') }}: <span>{{ $tpPay['account'] }}</span>
                            <button type="button" class="btn btn-xs btn-white ml-1" id="tp_copy" data-acct="{{ $tpPay['account'] }}">{{ translate('复制') }}</button>
                        </div>
                    @endif
                    @if($tpPay['holder'])
                        <div class="small text-muted mt-1">{{ translate('支付宝账户名') }}: {{ $tpPay['holder'] }}</div>
                    @endif
                </div>
                <div class="col-md-7">
                    <p class="text-muted small mb-2">{{ translate('请用左侧支付宝收款码或账号把款项转给平台, 然后在此填写转账金额并上传转账凭证。平台运营核对到账后为您入账, 余额即时增加。此为您与平台的 B2B 往来, 与顾客货款无关。') }}</p>
                    <form action="{{ route('vendor.nezha-deposit.topup-apply') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="account_type" value="{{ $account }}">
                        <div class="form-group">
                            <label class="input-label">{{ translate('转账金额') }}</label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text">֏</span></div>
                                <input type="number" step="0.01" min="{{ $tpMin }}" max="{{ $tpMax }}" name="amount" id="tp_amount" class="form-control" required>
                            </div>
                            <small class="text-muted d-block">{{ translate('单笔范围') }}: ֏{{ number_format($tpMin) }} ~ ֏{{ number_format($tpMax) }} <span id="tp_conv" class="text-primary"></span></small>
                        </div>
                        <div class="form-group">
                            <label class="input-label">{{ translate('转账凭证') }} <span class="text-danger">*</span></label>
                            <input type="file" name="proof" accept="image/*" class="form-control-file" required>
                            <small class="text-muted d-block">{{ translate('上传支付宝转账成功截图, 便于平台核对到账。') }}</small>
                        </div>
                        <div class="form-group">
                            <label class="input-label">{{ translate('备注') }}</label>
                            <input type="text" name="note" maxlength="255" class="form-control" placeholder="{{ translate('选填') }}">
                        </div>
                        <button type="submit" class="btn btn-primary" {{ ($tpLast && $tpLast->status === 'pending') ? 'disabled' : '' }}>{{ translate('提交充值申请') }}</button>
                        @if($tpLast && $tpLast->status === 'pending')
                            <small class="text-muted d-block mt-1">{{ translate('您有一笔申请正在审核, 处理后可再次提交。') }}</small>
                        @endif
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var i = document.getElementById('tp_amount'), o = document.getElementById('tp_conv');
            var rc = {{ $rateCny > 0 ? $rateCny : 55 }}, ru = {{ $rateUsd > 0 ? $rateUsd : 400 }};
            if (i && o) {
                var u = function () { var v = parseFloat(i.value) || 0; o.textContent = v > 0 ? ('≈ ¥' + (rc > 0 ? (v / rc) : 0).toFixed(2) + '  ·  ≈ $' + (ru > 0 ? (v / ru) : 0).toFixed(2)) : ''; };
                i.addEventListener('input', u); u();
            }
            var cp = document.getElementById('tp_copy');
            if (cp) { cp.addEventListener('click', function () { var a = cp.getAttribute('data-acct'); if (navigator.clipboard) { navigator.clipboard.writeText(a).then(function () { cp.textContent = '{{ translate('已复制') }}'; setTimeout(function () { cp.textContent = '{{ translate('复制') }}'; }, 1500); }); } }); }
        })();
    </script>
    @endif

    @if($account === 'deposit')
    @unless($tpOpen)
    {{-- 充值说明 --}}
    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-2">{{ translate('如何充值?') }}</h5>
            <p class="mb-0" style="font-size: 16px; line-height: 1.75; color: #475569;">{{ translate('请按平台告知的方式把预存佣金转给平台, 平台运营核实后会为您入账, 余额即时增加。预存佣金是您预付给平台的佣金(B2B), 与顾客货款无关。如需充值请联系平台客服。') }}</p>
        </div>
    </div>

    @endunless

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
    @unless($tpOpen)
    {{-- 押金说明 --}}
    <div class="card mb-3">
        <div class="card-body">
            <h5 class="mb-2">{{ translate('关于押金') }}</h5>
            <p class="mb-0" style="font-size: 16px; line-height: 1.75; color: #475569;">{{ translate('押金是您向平台缴纳的可退质押金(B2B), 与顾客货款无关。缴纳档位由平台按您的经营情况设定, 缴纳与退还由平台运营办理并在此对账留痕。退出平台时按规结算、原路退回您本人的收款账户。') }}</p>
        </div>
    </div>
    @endunless
    @endif

    {{-- ============ 退出平台(对账中心底部·step4-4) ============ --}}
    @php $ost = $offboardStatus ?? 'active'; $sett = $activeSettlement ?? null; @endphp
    @if(($offboardEnabled ?? false) || $ost !== 'active')
    <div class="card mb-3 border">
        <div class="card-header"><h5 class="card-title mb-0">{{ translate('退出平台') }}</h5></div>
        <div class="card-body">
            @if($ost === 'active')
                <p class="text-muted mb-3" style="font-size:15px;line-height:1.75;">{{ translate('若您决定不再在平台经营, 可在此申请退出。申请后店铺将停止接单并进入冷静期; 平台核对无未完成订单与纠纷、完成身份核验后, 会把您的押金与预存佣金余额按规结清、原路退回您本人的收款账户。') }}</p>
                @if($offboardEligibility && !($offboardEligibility['ok'] ?? true))
                    <div class="alert alert-soft-warning" role="alert">
                        <div class="mb-1"><strong>{{ translate('暂不能申请退出:') }}</strong></div>
                        <ul class="mb-0 pl-3">
                            @foreach(($offboardEligibility['blockers'] ?? []) as $b)
                                <li>{{ $b }}</li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    @if($offboardEligibility && !empty($offboardEligibility['warnings']))
                        <div class="alert alert-soft-info" role="alert">
                            @foreach($offboardEligibility['warnings'] as $w)
                                <div>{{ $w }}</div>
                            @endforeach
                        </div>
                    @endif
                    <form action="{{ route('vendor.nezha-deposit.offboard-apply') }}" method="POST" onsubmit="return confirm('{{ translate('确认申请退出平台? 申请后店铺将停止接单并进入冷静期(冷静期内可撤回)。') }}');">
                        @csrf
                        <button type="submit" class="btn btn-outline-danger">{{ translate('申请退出平台') }}</button>
                    </form>
                @endif

            @elseif($ost === 'settling')
                @if($sett && $sett->status === 'kyc_pending')
                    <div class="alert alert-soft-info mb-3">
                        <div class="mb-1"><strong>{{ translate('已收到您的退出申请') }}</strong></div>
                        <div>{{ translate('平台需先完成您的身份核验后再结算退款, 预计需要几个工作日, 届时会联系您核验。核验通过后即进入结算。') }}</div>
                    </div>
                @else
                    <div class="alert alert-soft-warning mb-3">
                        <div class="mb-1"><strong>{{ translate('退出申请处理中') }}</strong></div>
                        <div>{{ translate('您的退出申请已进入结算流程, 店铺当前已停止接单。平台核对与冷静期结束后, 会把余额按规结清、退回您本人收款账户。') }}</div>
                        @if($sett && $sett->cooldown_until)
                            <div class="small text-muted mt-1">{{ translate('冷静期至') }}: {{ \Carbon\Carbon::parse($sett->cooldown_until)->format('Y-m-d') }}</div>
                        @endif
                    </div>
                @endif
                @if($sett && in_array($sett->status, ['applied', 'kyc_pending']))
                    <form action="{{ route('vendor.nezha-deposit.offboard-withdraw') }}" method="POST" onsubmit="return confirm('{{ translate('确认撤回退出申请? 店铺将恢复正常营业。') }}');">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary">{{ translate('撤回退出申请, 恢复营业') }}</button>
                    </form>
                @else
                    <p class="text-muted small mb-0">{{ translate('已进入审批或放款阶段, 如需变更请联系平台客服。') }}</p>
                @endif

            @elseif($ost === 'owing')
                <div class="alert alert-soft-danger mb-0">
                    <div class="mb-1"><strong>{{ translate('结算后仍有欠款') }}</strong></div>
                    <div>{{ translate('您的账户结算后为负(未结佣金超过押金与预存余额), 需先补齐欠款平台才能完成退出。请联系平台客服处理。') }}</div>
                </div>

            @elseif($ost === 'offboarded')
                <div class="alert alert-soft-secondary mb-0">
                    <div><strong>{{ translate('本店已退出平台') }}</strong></div>
                    <div class="small text-muted">{{ translate('结算已完成。如需重新入驻请联系平台。') }}</div>
                </div>
            @endif
        </div>
    </div>
    @endif
</div>
@endsection
