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
                    <small class="text-muted">{{ translate('USDT 付款来源地址的 OFAC SDN 制裁筛查见下方「制裁名单筛查」卡片(命中即自动拒收, 记入风控日志)。') }}</small>
                </div>
            </div>

            {{-- 退款控制 (机制②: 原路锁定 + 限额) --}}
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title"><i class="tio-undo"></i> {{ translate('退款控制 (原路锁定 + 限额限笔)') }}</h5></div>
                <div class="card-body">
                    <div class="alert alert-warning" role="alert">
                        <i class="tio-warning"></i>
                        {{ translate('退款护栏总开关独立于上方交易风控。开启后: 退款金额强制 ≤ 原订单; USDT 退款锁定原付款地址(链上反查+校验); 法币退还原付款人; 超过限额的退款转审核队列, 不直接执行。开启属"真实影响"操作, 请先用测试订单验证再开。关闭时现网退款行为完全不变。') }}
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('退款护栏总开关') }}</label>
                            <select name="nezha_refund_control_status" class="form-control">
                                <option value="1" {{ ($cfg['nezha_refund_control_status'] ?? '0') == '1' ? 'selected' : '' }}>{{ translate('启用') }}</option>
                                <option value="0" {{ ($cfg['nezha_refund_control_status'] ?? '0') == '0' ? 'selected' : '' }}>{{ translate('禁用 (退款行为不变)') }}</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('单笔退款上限 ($) — 超过转审核') }}</label>
                            <input type="number" step="0.01" min="0" name="nezha_refund_single_limit" class="form-control" value="{{ $cfg['nezha_refund_single_limit'] ?? 100 }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('单商家单日退款累计上限 ($) — 超过转审核') }}</label>
                            <input type="number" step="0.01" min="0" name="nezha_refund_daily_total_limit" class="form-control" value="{{ $cfg['nezha_refund_daily_total_limit'] ?? 300 }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('单商家单日退款笔数上限 — 超过转审核') }}</label>
                            <input type="number" min="0" name="nezha_refund_daily_count_limit" class="form-control" value="{{ $cfg['nezha_refund_daily_count_limit'] ?? 5 }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('退款窗口 (交付后 N 天内可退, 0 = 不限)') }}</label>
                            <input type="number" min="0" name="nezha_refund_window_days" class="form-control" value="{{ $cfg['nezha_refund_window_days'] ?? 7 }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('USDT 退款链上自动校验') }}</label>
                            <select name="nezha_refund_usdt_verify_status" class="form-control">
                                <option value="1" {{ ($cfg['nezha_refund_usdt_verify_status'] ?? '1') == '1' ? 'selected' : '' }}>{{ translate('启用 (校验退款tx金额+地址)') }}</option>
                                <option value="0" {{ ($cfg['nezha_refund_usdt_verify_status'] ?? '1') == '0' ? 'selected' : '' }}>{{ translate('禁用 (仅锁定+人工核)') }}</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('BscScan API Key (选填, 空则用公共RPC节点)') }}</label>
                            <input type="text" name="nezha_refund_bscscan_api_key" class="form-control" maxlength="191" value="{{ $cfg['nezha_refund_bscscan_api_key'] ?? '' }}" placeholder="{{ translate('留空即可, 免密钥公共节点') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('TronGrid API Key (选填, 空则用公共端点)') }}</label>
                            <input type="text" name="nezha_refund_trongrid_api_key" class="form-control" maxlength="191" value="{{ $cfg['nezha_refund_trongrid_api_key'] ?? '' }}" placeholder="{{ translate('留空即可, 免密钥公共端点') }}">
                        </div>
                    </div>
                    <small class="text-muted">{{ translate('退款留痕(含原路锁定地址/链上校验结果)记入 nezha_refund_records, 合规留存 ≥5年。') }}</small>
                </div>
            </div>

            {{-- 制裁名单筛查 (机制② L1-6) --}}
            @php
                $sancSync = null;
                if (!empty($cfg['nezha_sanction_last_sync'])) {
                    $sancSync = json_decode($cfg['nezha_sanction_last_sync'], true) ?: null;
                }
            @endphp
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title"><i class="tio-shield-outlined"></i> {{ translate('制裁名单筛查 (OFAC SDN — 合规红线 L1-6)') }}</h5></div>
                <div class="card-body">
                    <div class="alert alert-danger" role="alert">
                        <i class="tio-warning"></i>
                        {{ translate('开启后: 顾客 USDT 付款被「确认收款」时, 系统反查该笔链上交易的付款来源地址, 比对 OFAC SDN 制裁名单。命中即自动拒收、不放行出餐, 并记入「风控日志」。这是合规红线(平台不得与受制裁主体交易), 默认开启。关闭将不再筛查 USDT 来源地址。') }}
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('制裁筛查总开关') }}</label>
                            <select name="nezha_sanction_screen_status" class="form-control">
                                <option value="1" {{ ($cfg['nezha_sanction_screen_status'] ?? '1') == '1' ? 'selected' : '' }}>{{ translate('启用 (命中即拒收)') }}</option>
                                <option value="0" {{ ($cfg['nezha_sanction_screen_status'] ?? '1') == '0' ? 'selected' : '' }}>{{ translate('禁用 (不筛查来源地址)') }}</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('OFAC SDN 名单来源 URL') }}</label>
                            <input type="text" name="nezha_sanction_source_url" class="form-control" maxlength="255" value="{{ $cfg['nezha_sanction_source_url'] ?? '' }}" placeholder="https://sanctionslistservice.ofac.treas.gov/...">
                            <small class="text-muted">{{ translate('每天 04:30 自动从此源拉取数字货币地址入库; 取数/解析失败会保留旧名单不动。') }}</small>
                        </div>
                    </div>
                    <div class="mt-2">
                        <small class="text-muted">
                            {{ translate('当前名单地址数') }}: <strong>{{ \App\Models\NezhaSanctionAddress::count() }}</strong>
                            @if ($sancSync)
                                ｜ {{ translate('最近同步') }}: {{ $sancSync['at'] ?? '-' }}
                                <span class="badge {{ ($sancSync['status'] ?? '') === 'ok' ? 'badge-soft-success' : 'badge-soft-warning' }}">{{ $sancSync['status'] ?? '-' }}</span>
                                {{ $sancSync['detail'] ?? '' }}
                            @else
                                ｜ <span class="text-warning">{{ translate('尚未同步, 名单为空时不会拦截任何地址。可在服务器运行 php artisan nezha:sync-sanction-list 立即拉取。') }}</span>
                            @endif
                        </small>
                    </div>
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
