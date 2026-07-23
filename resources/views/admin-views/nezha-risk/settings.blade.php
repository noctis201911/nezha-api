@extends('layouts.admin.app')
@section('title', translate('风控设置'))
@section('content')
    @php $nzIsSuper = auth('admin')->check() && auth('admin')->user()->role_id == 1; @endphp
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
                <div class="card-header"><h5 class="card-title"><i class="tio-undo"></i> {{ translate('退款控制（订单级退款目标 + 限额限笔）') }}</h5></div>
                <div class="card-body">
                    <div class="alert alert-warning" role="alert">
                        <i class="tio-warning"></i>
                        {{ translate('USDT 退款只可使用顾客付款前确认并随订单冻结的地址；tx.from 仅作来源证据，任何模式都不能回退为退款目标。顾客真正退款前还须以原登录方式完成新鲜认证。凭据等级仅为 customer_attested，不代表钱包控制权已验证。') }}
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('USDT 退款绑定模式') }}</label>
                            <select name="nezha_usdt_refund_binding_mode" class="form-control">
                                <option value="enforce" {{ ($cfg['nezha_usdt_refund_binding_mode'] ?? 'drain') === 'enforce' ? 'selected' : '' }}>{{ translate('enforce（允许新USDT单并强制双凭据）') }}</option>
                                <option value="drain" {{ ($cfg['nezha_usdt_refund_binding_mode'] ?? 'drain') === 'drain' ? 'selected' : '' }}>{{ translate('drain（停止新USDT单，继续处理既有快照）') }}</option>
                                <option value="closed" {{ ($cfg['nezha_usdt_refund_binding_mode'] ?? 'drain') === 'closed' ? 'selected' : '' }}>{{ translate('closed（停止新单并暂停既有USDT退款执行）') }}</option>
                            </select>
                            <small class="text-muted">{{ translate('律师 Q1/Q2 后置期间 production 必须保持 drain。') }}</small>
                        </div>
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
                                <option value="1" {{ ($cfg['nezha_refund_usdt_verify_status'] ?? '1') == '1' ? 'selected' : '' }}>{{ translate('启用（网络/合约/目标/原子金额/终局性全校验）') }}</option>
                                <option value="0" {{ ($cfg['nezha_refund_usdt_verify_status'] ?? '1') == '0' ? 'selected' : '' }}>{{ translate('禁用（所有USDT退款保持待处理，不能人工关闭）') }}</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('律师 Q1/Q2 门') }}</label>
                            <select name="nezha_usdt_refund_legal_gate" class="form-control">
                                <option value="pending" {{ ($cfg['nezha_usdt_refund_legal_gate'] ?? 'pending') === 'pending' ? 'selected' : '' }}>{{ translate('pending（未取得结论，不开放新单）') }}</option>
                                <option value="approved" {{ ($cfg['nezha_usdt_refund_legal_gate'] ?? 'pending') === 'approved' ? 'selected' : '' }}>{{ translate('approved（已取得可定位的一手放行意见）') }}</option>
                                <option value="rejected" {{ ($cfg['nezha_usdt_refund_legal_gate'] ?? 'pending') === 'rejected' ? 'selected' : '' }}>{{ translate('rejected（否决，保持关闸）') }}</option>
                            </select>
                            <small class="text-muted">{{ translate('只有 legal gate=approved 且 mode=enforce 才允许签发新退款地址凭据。') }}</small>
                        </div>
                        <div class="col-md-4">
                            <label class="input-label">{{ translate('新鲜认证挑战有效期（秒）') }}</label>
                            <input type="number" min="60" max="600" name="nezha_refund_reconfirm_ttl_seconds" class="form-control" value="{{ $cfg['nezha_refund_reconfirm_ttl_seconds'] ?? 300 }}">
                        </div>
                        <div class="col-md-4">
                            <label class="input-label">{{ translate('BEP20 最低确认数') }}</label>
                            <input type="number" min="1" max="200" name="nezha_refund_bsc_finality_blocks" class="form-control" value="{{ $cfg['nezha_refund_bsc_finality_blocks'] ?? 12 }}">
                        </div>
                        <div class="col-md-4">
                            <label class="input-label">{{ translate('TRC20 最低确认数') }}</label>
                            <input type="number" min="1" max="500" name="nezha_refund_tron_finality_blocks" class="form-control" value="{{ $cfg['nezha_refund_tron_finality_blocks'] ?? 20 }}">
                        </div>
                        <div class="col-md-4">
                            <label class="input-label">{{ translate('退款目标名单最大同步龄（小时）') }}</label>
                            <input type="number" min="1" max="168" name="nezha_refund_sanction_max_sync_age_hours" class="form-control" value="{{ $cfg['nezha_refund_sanction_max_sync_age_hours'] ?? 48 }}">
                            <small class="text-muted">{{ translate('超过该时间、最近同步非成功或筛查关闭时，USDT 退款保持挂起。') }}</small>
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('BscScan API Key (选填, 空则用公共RPC节点)') }}</label>
                            @if ($nzIsSuper)
                            <input type="text" name="nezha_refund_bscscan_api_key" class="form-control" maxlength="191" value="{{ $cfg['nezha_refund_bscscan_api_key'] ?? '' }}" placeholder="{{ translate('留空即可, 免密钥公共节点') }}">
                            @else
                            <input type="text" class="form-control" value="{{ !empty($cfg['nezha_refund_bscscan_api_key']) ? '••••••' : '' }}" placeholder="{{ translate('未配置') }}" disabled>
                            <small class="text-muted">{{ translate('密钥仅超级管理员可查看与修改') }}</small>
                            @endif
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('TronGrid API Key (选填, 空则用公共端点)') }}</label>
                            @if ($nzIsSuper)
                            <input type="text" name="nezha_refund_trongrid_api_key" class="form-control" maxlength="191" value="{{ $cfg['nezha_refund_trongrid_api_key'] ?? '' }}" placeholder="{{ translate('留空即可, 免密钥公共端点') }}">
                            @else
                            <input type="text" class="form-control" value="{{ !empty($cfg['nezha_refund_trongrid_api_key']) ? '••••••' : '' }}" placeholder="{{ translate('未配置') }}" disabled>
                            <small class="text-muted">{{ translate('密钥仅超级管理员可查看与修改') }}</small>
                            @endif
                        </div>
                    </div>
                    <small class="text-muted">{{ translate('退款留痕含订单级冻结地址、资产原子金额、新鲜认证和链上终局性结果；地址凭据与证据表均要求加密表空间。') }}</small>
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
                        {{ translate('开启后：确认 USDT 收款时筛查付款来源；实际退款前还会筛查本单冻结目标。来源命中即拒收；退款目标命中、名单关闭、最近同步非成功/过期或查询异常时一律保持挂起。') }}
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
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('来源地址反查不出时') }}</label>
                            <select name="nezha_sanction_inconclusive_action" class="form-control">
                                <option value="hold" {{ ($cfg['nezha_sanction_inconclusive_action'] ?? 'hold') == 'hold' ? 'selected' : '' }}>{{ translate('拦截待人工 (查不出不放行, 转人工复核 · 更稳健, 默认)') }}</option>
                                <option value="allow" {{ ($cfg['nezha_sanction_inconclusive_action'] ?? 'hold') == 'allow' ? 'selected' : '' }}>{{ translate('放行 + 留痕 (查不出仍出餐, 仅记录待人工事后核)') }}</option>
                            </select>
                            <small class="text-muted">{{ translate('无交易哈希 / 链上服务暂不可达, 导致来源地址反查失败时的处置。未配置 Tron/BSC API key 时公共端点可能限流, 选「拦截」可能误挂合法 USDT 单(顾客需等人工或重试)。') }}</small>
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
