@php
    $stateLabels = [
        'pending_merchant' => '等待您核对',
        'pending_distinct_admin' => '等待不同管理员复核',
        'draining' => '旧地址凭据排空中',
        'applying' => '系统正在切换',
    ];
    $openIds = ($security['open_changes'] ?? collect())->pluck('public_id')->all();
    $notificationHistory = ($security['notifications'] ?? collect())->reject(
        fn ($notification) => in_array($notification->data['data_id'] ?? null, $openIds, true)
    );
@endphp

<style>
    .nz-merchant-address-change { border-left: 4px solid #e59f17; }
    .nz-merchant-address-change .nz-security-address {
        padding: 12px 14px; border: 1px solid #e7eaf3; border-radius: 8px;
        background: #f8fafc; word-break: break-all; font-family: monospace; color: #1f2937;
    }
    .nz-merchant-address-change .nz-security-address.candidate { background: #fffaf0; border-color: #f2d38a; }
    .nz-merchant-address-change .nz-fingerprint { font-family: monospace; letter-spacing: .04em; }
</style>

<section data-payment-address-security="merchant-a">
    @if (!($security['is_owner'] ?? false))
        <div class="alert alert-light border d-flex gap-2" role="alert">
            <i class="tio-shield"></i>
            <span>资金地址的候选值与确认操作只向商家 owner 展示；员工账号只能核对当前生效收款信息。</span>
        </div>
    @elseif (($security['open_changes'] ?? collect())->isNotEmpty())
        @foreach ($security['open_changes'] as $change)
            <article class="card mb-3 nz-merchant-address-change" data-address-change-state="{{ $change->state }}">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <small class="text-uppercase text-muted">资金安全 · 系统通知</small>
                        <h5 class="mb-0">USDT · {{ $change->network }} 收款地址变更</h5>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-1">
                        @if (($viewedSecurityNotifications ?? 0) > 0)
                            <span class="badge badge-soft-success">本页已查看 {{ $viewedSecurityNotifications }} 条安全通知</span>
                        @endif
                        <span class="badge badge-soft-warning">{{ $stateLabels[$change->state] ?? $change->state }}</span>
                    </div>
                </div>
                <div class="card-body">
                    @if ($change->state === 'pending_merchant')
                        <div class="alert alert-danger">
                            这是资金去向变更。不要依据客服聊天、邮件或截图复制地址；请打开自己掌握的钱包逐字核对下方完整候选地址。
                        </div>
                    @else
                        <div class="alert alert-info">
                            您已经完成核对；当前地址仍未切换。系统会等待不同管理员复核及旧地址版本凭据排空。
                        </div>
                    @endif

                    <div class="row g-3">
                        <div class="col-lg-6">
                            <label class="input-label">当前仍在生效</label>
                            <div class="nz-security-address">{{ $change->old_address }}</div>
                            <small class="text-muted nz-fingerprint">指纹 {{ substr($change->old_fingerprint, 0, 16) }}</small>
                        </div>
                        <div class="col-lg-6">
                            <label class="input-label">候选地址 · 尚未生效、不可用于当前转账</label>
                            <div class="nz-security-address candidate">{{ $change->new_address }}</div>
                            <small class="text-muted nz-fingerprint">指纹 {{ substr($change->new_fingerprint, 0, 16) }}</small>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between gap-2 mt-3">
                        <small class="text-muted">
                            申请编号 {{ substr($change->public_id, 0, 12) }}
                            @if ($change->expires_at && in_array($change->state, ['pending_merchant', 'pending_distinct_admin'], true))
                                · 有效至 {{ $change->expires_at->format('Y-m-d H:i') }}
                            @elseif ($change->drain_until)
                                · 最早排空时间 {{ $change->drain_until->format('Y-m-d H:i') }}
                            @endif
                        </small>
                        <span class="text-success font-weight-bold">当前地址未改变</span>
                    </div>

                    @if ($change->state === 'pending_merchant')
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <form action="{{ route('vendor.payment-address-change.reject', $change->public_id) }}" method="post">
                                @csrf
                                <input type="hidden" name="new_fingerprint" value="{{ $change->new_fingerprint }}">
                                <button class="btn btn-outline-danger" type="submit">地址不属于我，拒绝申请</button>
                            </form>
                            <form action="{{ route('vendor.payment-address-change.confirm', $change->public_id) }}" method="post">
                                @csrf
                                <input type="hidden" name="new_fingerprint" value="{{ $change->new_fingerprint }}">
                                <button class="btn btn-primary" type="submit">完整地址属于我，确认并继续</button>
                            </form>
                        </div>
                    @elseif ($change->state === 'pending_distinct_admin')
                        <details class="mt-3">
                            <summary class="text-danger" style="cursor:pointer;">确认后发现有误？拒绝申请</summary>
                            <form action="{{ route('vendor.payment-address-change.reject', $change->public_id) }}" method="post" class="mt-2">
                                @csrf
                                <input type="hidden" name="new_fingerprint" value="{{ $change->new_fingerprint }}">
                                <button class="btn btn-outline-danger" type="submit">拒绝并保留当前地址</button>
                            </form>
                        </details>
                    @endif
                </div>
            </article>
        @endforeach

    @endif

    @if (($security['is_owner'] ?? false) && $notificationHistory->isNotEmpty())
        <div class="card mb-3" data-address-security-notifications>
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h5 class="mb-0">最近资金地址安全通知</h5>
                @if (($viewedSecurityNotifications ?? 0) > 0)
                    <span class="badge badge-soft-success">本页已查看</span>
                @endif
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    @foreach ($notificationHistory as $notification)
                        <div class="list-group-item py-3">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <strong>{{ $notification->data['title'] ?? '收款地址安全状态已更新' }}</strong>
                                <small class="text-muted">{{ $notification->created_at }}</small>
                            </div>
                            <div class="text-muted mt-1">{{ $notification->data['description'] ?? '' }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    @if (($security['is_owner'] ?? false)
        && (($security['open_changes'] ?? collect())->isNotEmpty() || $notificationHistory->isNotEmpty()))
        <div class="alert alert-light border d-flex gap-2" role="alert">
            <i class="tio-notifications-on"></i>
            <span>此类资金安全事件写入站内通知，并按真实配置尝试 Telegram、邮箱和 App 推送；不受普通订单提醒开关影响。</span>
        </div>
    @endif
</section>
