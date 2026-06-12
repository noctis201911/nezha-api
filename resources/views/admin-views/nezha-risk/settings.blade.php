@extends('layouts.admin.app')
@section('title', translate('风控设置'))
@section('content')
    <div class="content container-fluid">
        <div class="page-header">
            <h1 class="page-header-title"><i class="tio-shield"></i> {{ translate('交易风控设置') }}</h1>
        </div>

        <div class="alert alert-info" role="alert">
            <i class="tio-info"></i>
            {{ translate('所有阈值在此调整即时生效, 无需改代码。法币与 USDT 通道使用各自独立阈值; 金额均按美元等值判断 (1 USDT ≈ 1 USD)。') }}
        </div>

        <form action="{{ route('admin.nezha-risk.settings.update') }}" method="post">
            @csrf

            {{-- 总开关 --}}
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">{{ translate('总开关') }}</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('交易风控') }}</label>
                            <select name="nezha_risk_control_status" class="form-control">
                                <option value="1" {{ ($cfg['nezha_risk_control_status'] ?? '1') == '1' ? 'selected' : '' }}>{{ translate('启用') }}</option>
                                <option value="0" {{ ($cfg['nezha_risk_control_status'] ?? '1') == '0' ? 'selected' : '' }}>{{ translate('禁用 (不拦截任何订单)') }}</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 法币阈值 --}}
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title"><i class="tio-wallet"></i> {{ translate('法币(人民币)通道阈值 — 美元等值') }}</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('单笔订单上限 ($) — 超过直接拒单') }}</label>
                            <input type="number" step="0.01" min="0" name="nezha_risk_single_order_limit" class="form-control" value="{{ $cfg['nezha_risk_single_order_limit'] ?? 100 }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('单账号单日累计上限 ($) — 超过转人工审核') }}</label>
                            <input type="number" step="0.01" min="0" name="nezha_risk_daily_cumulative_limit" class="form-control" value="{{ $cfg['nezha_risk_daily_cumulative_limit'] ?? 300 }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('24小时单数阈值 — 超过转审核') }}</label>
                            <input type="number" min="0" name="nezha_risk_freq_24h_count" class="form-control" value="{{ $cfg['nezha_risk_freq_24h_count'] ?? 5 }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('10分钟单数阈值 — 超过转审核') }}</label>
                            <input type="number" min="0" name="nezha_risk_freq_10min_count" class="form-control" value="{{ $cfg['nezha_risk_freq_10min_count'] ?? 2 }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('大额特征阈值 ($) — 达到即标记审核') }}</label>
                            <input type="number" step="0.01" min="0" name="nezha_risk_large_amount_threshold" class="form-control" value="{{ $cfg['nezha_risk_large_amount_threshold'] ?? 80 }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('整百/整千金额标记') }}</label>
                            <select name="nezha_risk_round_amount_flag" class="form-control">
                                <option value="1" {{ ($cfg['nezha_risk_round_amount_flag'] ?? '1') == '1' ? 'selected' : '' }}>{{ translate('启用') }}</option>
                                <option value="0" {{ ($cfg['nezha_risk_round_amount_flag'] ?? '1') == '0' ? 'selected' : '' }}>{{ translate('禁用') }}</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            {{-- USDT 阈值 --}}
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title"><i class="tio-bitcoin"></i> {{ translate('USDT 通道独立阈值 — 美元等值') }}</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('USDT 单笔上限 ($) — 超过直接拒单') }}</label>
                            <input type="number" step="0.01" min="0" name="nezha_risk_usdt_single_limit" class="form-control" value="{{ $cfg['nezha_risk_usdt_single_limit'] ?? 200 }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('USDT 单账号单日累计上限 ($) — 超过转审核') }}</label>
                            <input type="number" step="0.01" min="0" name="nezha_risk_usdt_daily_limit" class="form-control" value="{{ $cfg['nezha_risk_usdt_daily_limit'] ?? 500 }}">
                        </div>
                    </div>
                    <small class="text-muted">{{ translate('USDT 链上地址筛查(OFAC/黑名单)在「组②」实现, 命中将自动进入本审核队列。') }}</small>
                </div>
            </div>

            {{-- 其它 --}}
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">{{ translate('其它') }}</h5></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('人工放行宽限期 (分钟)') }}</label>
                            <input type="number" min="0" name="nezha_risk_approval_grace_minutes" class="form-control" value="{{ $cfg['nezha_risk_approval_grace_minutes'] ?? 60 }}">
                            <small class="text-muted">{{ translate('客服放行后, 该顾客在此时间内重新下单直接通过。') }}</small>
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('客服联系方式 (拒单提示中展示)') }}</label>
                            <input type="text" name="nezha_risk_contact_info" class="form-control" maxlength="191" value="{{ $cfg['nezha_risk_contact_info'] ?? '' }}" placeholder="{{ translate('如: 微信 nezha-kefu / Telegram @xxx') }}">
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">{{ translate('保存设置') }}</button>
        </form>
    </div>
@endsection
