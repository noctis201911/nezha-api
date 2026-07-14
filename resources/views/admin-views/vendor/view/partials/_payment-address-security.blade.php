@php
    $stateLabels = [
        'active' => '生效中',
        'paused' => '已暂停',
        'pending_merchant' => '待商家 owner 确认',
        'pending_distinct_admin' => '待不同管理员复核',
        'draining' => '升级前旧记录排空中',
        'applying' => '正在原子切换',
        'applied' => '已生效',
        'rejected' => '商家已拒绝',
        'canceled' => '已取消',
        'expired' => '已过期',
        'failed' => '切换失败并暂停',
    ];
    $nextOwner = [
        'pending_merchant' => '商家 owner',
        'pending_distinct_admin' => '与申请人不同的管理员',
        'draining' => '系统（仅处理升级前遗留记录）',
        'applying' => '系统',
    ];
    $baseUrl = route('admin.restaurant.view', [$restaurant->id, 'payment_info']);
    $review = $security['review'] ?? null;
@endphp

<style>
    .nz-address-security .nz-address-box, .nz-review-drawer .nz-address-box {
        padding: 12px 14px; border: 1px solid #e7eaf3; border-radius: 8px;
        background: #f8fafc; color: #1f2937; word-break: break-all; font-family: monospace;
    }
    .nz-address-security .nz-network-card { border: 1px solid #e7eaf3; border-radius: 10px; height: 100%; }
    .nz-address-security .nz-network-head { padding: 15px 16px; border-bottom: 1px solid #eef0f3; }
    .nz-address-security .nz-network-body { padding: 16px; }
    .nz-address-security .nz-fingerprint { font-family: monospace; letter-spacing: .04em; }
    .nz-address-security details > summary { cursor: pointer; color: #2457d6; font-weight: 600; }
    .nz-address-security .nz-progress-step, .nz-review-drawer .nz-progress-step { display: flex; gap: 10px; margin-bottom: 12px; }
    .nz-address-security .nz-progress-dot, .nz-review-drawer .nz-progress-dot {
        width: 22px; height: 22px; flex: 0 0 22px; border-radius: 50%;
        display: inline-flex; align-items: center; justify-content: center;
        background: #e8eefc; color: #2457d6; font-size: 12px; font-weight: 700;
    }
    .nz-address-security .nz-progress-step.done .nz-progress-dot,
    .nz-review-drawer .nz-progress-step.done .nz-progress-dot { background: #d9f4e5; color: #087443; }
    .nz-review-overlay { position: fixed; inset: 0; z-index: 1055; background: rgba(17, 24, 39, .48); }
    .nz-review-drawer {
        position: fixed; z-index: 1056; top: 0; right: 0; bottom: 0; width: min(560px, 100%);
        overflow-y: auto; background: #fff; box-shadow: -12px 0 34px rgba(17, 24, 39, .18); padding: 24px;
    }
    .nz-review-drawer .nz-compare { border: 1px solid #e7eaf3; border-radius: 10px; overflow: hidden; }
    .nz-review-drawer .nz-compare-item { padding: 14px; }
    .nz-review-drawer .nz-compare-item + .nz-compare-item { border-top: 1px solid #e7eaf3; background: #fffaf0; }
    @media (max-width: 575.98px) { .nz-review-drawer { padding: 18px; } }
</style>

<section class="nz-address-security mt-3" data-payment-address-security="admin-a">
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div>
                <small class="text-uppercase text-muted">{{ translate('资金安全 · 地址状态机') }}</small>
                <h4 class="mb-0">{{ translate('USDT 收款地址') }}</h4>
            </div>
            <span class="badge badge-soft-success px-3 py-2">
                <i class="tio-shield-check"></i> {{ translate('受控变更已启用') }}
            </span>
        </div>
        <div class="card-body py-3">
            <div class="alert alert-info mb-0">
                “商家 owner 确认 → 不同管理员 TOTP 复核”完成后，新地址会立即用于后续新付款。
                批准前已签发的旧地址凭据只到各自原到期时间；打开申请、确认或审核页面本身不会改地址。
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        @foreach ($security['networks'] as $network => $item)
            @php
                $state = $item['state'];
                $pending = $item['pending'];
                $stateName = $pending?->state ?? $state?->state;
            @endphp
            <div class="col-xl-6">
                <article class="nz-network-card" data-payment-network="{{ $network }}">
                    <header class="nz-network-head d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h5 class="mb-0">USDT · {{ $network }}</h5>
                        <div class="d-flex flex-wrap gap-1">
                            @if (!$item['configured'])
                                <span class="badge badge-soft-secondary">未配置</span>
                            @elseif ($item['valid'])
                                <span class="badge badge-soft-success">严格格式有效</span>
                            @else
                                <span class="badge badge-soft-danger">严格格式无效</span>
                            @endif
                            @if ($state)
                                <span class="badge {{ $state->state === 'paused' ? 'badge-soft-danger' : 'badge-soft-primary' }}">
                                    版本 {{ $state->active_version }} · {{ $stateLabels[$state->state] ?? $state->state }}
                                </span>
                            @endif
                        </div>
                    </header>
                    <div class="nz-network-body">
                        <label class="input-label">当前数据库地址</label>
                        <div class="nz-address-box {{ $item['configured'] ? '' : 'text-muted' }}">
                            {{ $item['configured'] ? $item['address'] : '— 未配置，顾客端不展示该网络 —' }}
                        </div>
                        @if ($item['fingerprint'])
                            <small class="text-muted d-block mt-2 nz-fingerprint">
                                指纹 {{ substr($item['fingerprint'], 0, 4) }} {{ substr($item['fingerprint'], 4, 4) }} {{ substr($item['fingerprint'], 8, 4) }} {{ substr($item['fingerprint'], 12, 4) }}
                            </small>
                        @endif

                        @if ($pending)
                            <div class="alert alert-warning mt-3 mb-3" data-address-change-state="{{ $pending->state }}">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <strong>{{ $stateLabels[$pending->state] ?? $pending->state }}</strong>
                                    <span>当前地址未切换</span>
                                </div>
                                <div class="mt-1">下一责任人：{{ $nextOwner[$pending->state] ?? '系统/人工核对' }}</div>
                                @if ($pending->expires_at && in_array($pending->state, ['pending_merchant', 'pending_distinct_admin'], true))
                                    <small>申请有效至 {{ $pending->expires_at->format('Y-m-d H:i') }}</small>
                                @elseif ($pending->drain_until)
                                    <small>最早排空时间 {{ $pending->drain_until->format('Y-m-d H:i') }}</small>
                                @endif
                                <div class="mt-2">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="{{ $baseUrl }}?review={{ rawurlencode($pending->public_id) }}">
                                        查看完整申请
                                    </a>
                                </div>
                            </div>
                        @elseif (!$item['configured'])
                            <div class="alert alert-light border mt-3 mb-0">
                                首次地址启用会改变资金地址生效规则，不在本页面开放；需另行完成初始化方案与授权。
                            </div>
                        @elseif (!$item['valid'])
                            <div class="alert alert-danger mt-3 mb-0">
                                当前地址不符合 {{ $network }} 严格格式，不能进入自动变更流程。先保持原值，另行人工核对数据来源。
                            </div>
                        @elseif (!$state)
                            <div class="alert alert-warning mt-3 mb-0">
                                地址有效，但尚未建立受控版本状态；本页面不会自动初始化或改变地址。
                            </div>
                        @elseif ($item['requestable'])
                            <details class="mt-3">
                                <summary>发起 {{ $network }} 地址变更</summary>
                                <form class="mt-3" action="{{ route('admin.restaurant.payment-address-change.store', $restaurant->id) }}" method="post">
                                    @csrf
                                    <input type="hidden" name="network" value="{{ $network }}">
                                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                    <div class="form-group">
                                        <label class="input-label">候选完整地址</label>
                                        <input class="form-control" type="text" name="new_address" maxlength="191"
                                               autocomplete="off" spellcheck="false" required>
                                        <small class="text-muted">提交后当前地址不会改变；商家只能在自己的后台核对完整候选地址。</small>
                                    </div>
                                    <div class="form-group">
                                        <label class="input-label">变更原因（仅审计，不进入外部通知）</label>
                                        <textarea class="form-control" name="reason" maxlength="500" rows="3" required></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label class="input-label">当前管理员 6 位 TOTP</label>
                                        <input class="form-control" type="text" name="totp_code" inputmode="numeric"
                                               autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
                                    </div>
                                    <button class="btn btn-primary" type="submit">创建申请（不切换地址）</button>
                                </form>
                            </details>
                        @endif

                        @if ($state)
                            <details class="mt-3">
                                <summary class="text-danger">紧急暂停 {{ $network }}</summary>
                                <div class="alert alert-danger mt-3">
                                    单个 TOTP 管理员可立即暂停该商家/网络，并撤销尚未消费的地址版本凭据；此动作不会把地址换成候选地址。
                                </div>
                                <form action="{{ route('admin.restaurant.payment-address-change.pause', $restaurant->id) }}" method="post">
                                    @csrf
                                    <input type="hidden" name="network" value="{{ $network }}">
                                    <div class="form-group">
                                        <label class="input-label">暂停原因</label>
                                        <textarea class="form-control" name="reason" maxlength="500" rows="2" required></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label class="input-label">当前管理员 6 位 TOTP</label>
                                        <input class="form-control" type="text" name="totp_code" inputmode="numeric"
                                               autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
                                    </div>
                                    <button class="btn btn-danger" type="submit">确认紧急暂停</button>
                                </form>
                            </details>
                        @endif
                    </div>
                </article>
            </div>
        @endforeach
    </div>

    <div class="card mb-3" data-reviewer-readiness>
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <small class="text-uppercase text-muted">独立复核准备状态</small>
                <h5 class="mb-0">不同管理员审批</h5>
            </div>
            @if (($security['totp_admin_count'] ?? 0) >= 2)
                <span class="badge badge-soft-success">{{ $security['totp_admin_count'] }} 名 TOTP 管理员可用</span>
            @else
                <span class="badge badge-soft-danger">尚未就绪</span>
            @endif
        </div>
        <div class="card-body">
            @if (($security['totp_admin_count'] ?? 0) >= 2)
                <div class="alert alert-success mb-0">已有至少两名启用 TOTP 的管理员；申请人仍不能批准自己创建的申请。</div>
            @else
                <div class="alert alert-danger mb-0">
                    当前系统只有 {{ $security['totp_admin_count'] ?? 0 }} 名启用 TOTP 的管理员可参与资金地址动作。
                    创建第二生产管理员、分配权限和启用 TOTP 仍需另行执行授权；本页面不会创建账号。
                </div>
            @endif
        </div>
    </div>
</section>

@if ($review)
    <a class="nz-review-overlay" href="{{ $baseUrl }}" aria-label="关闭复核抽屉"></a>
    <aside class="nz-review-drawer" role="dialog" aria-modal="true" aria-labelledby="nz-review-title"
           data-payment-address-review-drawer="admin-c">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
                <small class="text-uppercase text-muted">独立管理员复核 · {{ substr($review->public_id, 0, 12) }}</small>
                <h3 id="nz-review-title" class="mb-1">确认 {{ $review->network }} 地址变更</h3>
                <p class="text-muted mb-0">打开抽屉不会改变当前地址。只依据本页完整地址和指纹核对，不依据聊天截图。</p>
            </div>
            <a class="btn btn-sm btn-light" href="{{ $baseUrl }}" aria-label="关闭">×</a>
        </div>

        <div class="nz-compare mb-3">
            <div class="nz-compare-item">
                <small class="text-muted">当前生效 · 版本 {{ $review->expected_version }}</small>
                <div class="nz-address-box mt-1">{{ $review->old_address }}</div>
                <small class="nz-fingerprint text-muted">指纹 {{ substr($review->old_fingerprint, 0, 16) }}</small>
            </div>
            <div class="nz-compare-item">
                <small class="text-muted">候选地址 · 未生效、不可用于当前转账</small>
                <div class="nz-address-box mt-1">{{ $review->new_address }}</div>
                <small class="nz-fingerprint text-muted">指纹 {{ substr($review->new_fingerprint, 0, 16) }}</small>
            </div>
        </div>

        <div class="mb-3">
            <div class="nz-progress-step {{ $review->merchant_confirmed_at ? 'done' : '' }}">
                <span class="nz-progress-dot">1</span>
                <div><strong>商家 owner 核对候选地址</strong><br><small class="text-muted">
                    {{ $review->merchant_confirmed_at ? '已确认于 '.$review->merchant_confirmed_at->format('Y-m-d H:i') : '等待商家 owner' }}
                </small></div>
            </div>
            <div class="nz-progress-step {{ $review->approved_at ? 'done' : '' }}">
                <span class="nz-progress-dot">2</span>
                <div><strong>与申请人不同的管理员 TOTP 复核</strong><br><small class="text-muted">
                    {{ $review->approved_at ? '已批准于 '.$review->approved_at->format('Y-m-d H:i') : '申请管理员 #'.$review->requested_by_admin_id.' 不得自批' }}
                </small></div>
            </div>
            <div class="nz-progress-step {{ $review->state === 'applied' ? 'done' : '' }}">
                <span class="nz-progress-dot">3</span>
                <div><strong>批准后立即切换新付款地址</strong><br><small class="text-muted">
                    {{ $review->state === 'applied' ? '新付款已使用新地址；旧凭据仍只到原到期时间' : '普通换址不暂停新付款，也不延长旧凭据' }}
                </small></div>
            </div>
        </div>

        <div class="alert alert-info">当前申请状态：<strong>{{ $stateLabels[$review->state] ?? $review->state }}</strong>。当前数据库地址未因查看本页而改变。</div>

        @if ($review->state === 'pending_distinct_admin')
            @if ($security['reviewer_can_approve'])
                <form action="{{ route('admin.restaurant.payment-address-change.approve', $review->public_id) }}" method="post" class="mb-3">
                    @csrf
                    <input type="hidden" name="new_fingerprint" value="{{ $review->new_fingerprint }}">
                    <div class="form-group">
                        <label class="input-label">复核管理员 6 位 TOTP</label>
                        <input class="form-control" type="text" name="totp_code" inputmode="numeric"
                               autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
                    </div>
                    <button class="btn btn-primary btn-block" type="submit">批准并立即切换新付款地址</button>
                </form>
            @else
                <div class="alert alert-danger">
                    <strong>当前无法批准：</strong>
                    @if ((int) $review->requested_by_admin_id === (int) ($security['current_admin_id'] ?? 0))
                        当前账号是申请人，不能自批。
                    @elseif (($security['totp_admin_count'] ?? 0) < 2)
                        系统尚无第二名启用 TOTP 的管理员。
                    @else
                        当前管理员未完成 TOTP 准备。
                    @endif
                </div>
            @endif
        @endif

        @if (in_array($review->state, ['pending_merchant', 'pending_distinct_admin', 'draining'], true))
            <details class="mb-3">
                <summary>取消这项申请</summary>
                <form action="{{ route('admin.restaurant.payment-address-change.cancel', $review->public_id) }}" method="post" class="mt-3">
                    @csrf
                    <div class="form-group">
                        <label class="input-label">当前管理员 6 位 TOTP</label>
                        <input class="form-control" type="text" name="totp_code" inputmode="numeric"
                               autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
                    </div>
                    <button class="btn btn-outline-danger" type="submit">取消申请（保留当前地址）</button>
                </form>
            </details>
        @endif

        <details>
            <summary class="text-danger">紧急暂停 {{ $review->network }} 网络收款</summary>
            <form action="{{ route('admin.restaurant.payment-address-change.pause', $restaurant->id) }}" method="post" class="mt-3">
                @csrf
                <input type="hidden" name="network" value="{{ $review->network }}">
                <div class="form-group">
                    <label class="input-label">暂停原因</label>
                    <textarea class="form-control" name="reason" maxlength="500" rows="2" required></textarea>
                </div>
                <div class="form-group">
                    <label class="input-label">当前管理员 6 位 TOTP</label>
                    <input class="form-control" type="text" name="totp_code" inputmode="numeric"
                           autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
                </div>
                <button class="btn btn-danger btn-block" type="submit">确认紧急暂停</button>
            </form>
        </details>
    </aside>
@endif
