@extends('layouts.admin.app')

@section('title', '收款地址复核')

@push('css_or_js')
<style>
    .nz-review-page{padding:28px 32px 40px;max-width:1540px;margin:0 auto}
    .nz-review-title{font-size:24px;font-weight:700;color:#1a2233;margin:0}
    .nz-review-subtitle{color:#697386;margin:6px 0 0;font-size:14px}
    .nz-review-stat{border:1px solid #e6e9ef;border-radius:10px;background:#fff;padding:18px 20px;height:100%}
    .nz-review-stat__label{color:#697386;font-size:13px;margin-bottom:6px}
    .nz-review-stat__value{font-size:28px;line-height:1.15;font-weight:700;color:#1a2233}
    .nz-review-stat--warn{border-left:4px solid #d97a08}
    .nz-review-card{border:1px solid #e6e9ef;border-radius:10px;background:#fff;box-shadow:0 2px 8px rgba(16,42,76,.04)}
    .nz-review-toolbar{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid #edf0f4}
    .nz-review-search{position:relative;max-width:390px;flex:1}
    .nz-review-search i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#8a94a6}
    .nz-review-search input{padding-left:38px;height:40px;border-radius:8px}
    .nz-review-table{margin-bottom:0}
    .nz-review-table th{background:#f7f8fa;color:#697386;font-size:12px;font-weight:600;border:0;padding:13px 18px;white-space:nowrap}
    .nz-review-table td{padding:16px 18px;vertical-align:middle;border-color:#edf0f4;color:#1a2233}
    .nz-review-table tbody tr{cursor:pointer}
    .nz-review-table tbody tr:hover{background:#f8fafc}
    .nz-review-shop{font-weight:600;margin-bottom:2px}
    .nz-review-muted{font-size:12px;color:#8a94a6}
    .nz-review-fingerprint{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:#475467}
    .nz-review-empty{padding:68px 20px;text-align:center;color:#697386}
    .nz-review-empty i{font-size:38px;color:#b8c0cc;display:block;margin-bottom:12px}
    .nz-review-modal .modal-dialog{max-width:820px}
    .nz-review-modal .modal-content{border:0;border-radius:12px;overflow:hidden}
    .nz-review-modal .modal-header{padding:20px 24px;border-bottom:1px solid #e7eaf0}
    .nz-review-modal .modal-body{padding:22px 24px;max-height:calc(100vh - 180px);overflow-y:auto}
    .nz-review-section{border:1px solid #e6e9ef;border-radius:9px;padding:16px 18px;margin-bottom:16px}
    .nz-review-section__title{font-size:13px;font-weight:700;color:#344054;margin-bottom:12px}
    .nz-review-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px 24px}
    .nz-review-field__label{font-size:12px;color:#8a94a6;margin-bottom:4px}
    .nz-review-field__value{font-size:14px;color:#1a2233;font-weight:500;overflow-wrap:anywhere}
    .nz-review-address{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;line-height:1.55;word-break:break-all;background:#f7f8fa;border-radius:7px;padding:10px 12px}
    .nz-review-address--new{background:#fff8ec;border:1px solid #f3d7ad}
    .nz-review-warning{display:flex;gap:10px;align-items:flex-start;background:#fff8ec;color:#7a4a08;border-radius:8px;padding:12px 14px;font-size:13px}
    .nz-review-actions{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:18px}
    .nz-review-actions .btn{min-height:44px;font-weight:600;border-radius:8px}
    .nz-review-loading{padding:54px 0;text-align:center;color:#697386}
    .nz-review-detail{display:none}
    .nz-review-reason{resize:vertical;min-height:74px}
    @media(max-width:767px){
        .nz-review-page{padding:18px 14px 30px}
        .nz-review-toolbar{align-items:stretch;flex-direction:column}
        .nz-review-search{max-width:none}
        .nz-review-grid,.nz-review-actions{grid-template-columns:1fr}
        .nz-review-table th:nth-child(3),.nz-review-table td:nth-child(3),.nz-review-table th:nth-child(4),.nz-review-table td:nth-child(4){display:none}
        .nz-review-modal .modal-body{padding:18px}
    }
</style>
@endpush

@section('content')
@php
    $urgentCount = $changes->filter(fn ($change) => $change->expires_at && $change->expires_at->lte(now()->addHours(2)))->count();
@endphp
<div class="content container-fluid nz-review-page" data-payment-address-review="reviewer-v2">
    <div class="d-flex align-items-start justify-content-between flex-wrap mb-4">
        <div>
            <h1 class="nz-review-title">收款地址复核</h1>
            <p class="nz-review-subtitle">只处理商家 owner 已确认的 USDT 地址变更；批准或驳回都需要当前复核员的 6 位 TOTP。</p>
        </div>
        <span class="badge badge-soft-info mt-2">独立复核入口</span>
    </div>

    <div class="row mb-4">
        <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
            <div class="nz-review-stat">
                <div class="nz-review-stat__label">待复核</div>
                <div class="nz-review-stat__value" data-review-count>{{ $changes->count() }}</div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="nz-review-stat nz-review-stat--warn">
                <div class="nz-review-stat__label">2 小时内到期</div>
                <div class="nz-review-stat__value">{{ $urgentCount }}</div>
            </div>
        </div>
    </div>

    <div class="nz-review-card">
        <div class="nz-review-toolbar">
            <div>
                <strong>商家已确认，等待独立复核</strong>
                <div class="nz-review-muted mt-1">列表只显示地址指纹；完整地址仅在详情弹窗中按需读取。</div>
            </div>
            <div class="nz-review-search">
                <i class="tio-search"></i>
                <input id="nz-review-search" class="form-control" type="search"
                    placeholder="搜索商家、网络或指纹" autocomplete="off">
            </div>
        </div>

        @if($changes->isEmpty())
            <div class="nz-review-empty" data-review-empty>
                <i class="tio-checkmark-circle-outlined"></i>
                <strong>当前没有待复核申请</strong>
                <div class="mt-2">新申请只有在商家 owner 确认后才会进入这里。</div>
            </div>
        @else
            <div class="table-responsive">
                <table class="table nz-review-table">
                    <thead>
                        <tr>
                            <th>商家</th>
                            <th>网络</th>
                            <th>新地址指纹</th>
                            <th>申请人</th>
                            <th>商家确认</th>
                            <th>到期时间</th>
                            <th class="text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody id="nz-review-rows">
                        @foreach($changes as $change)
                            @php
                                $requester = $change->requestedByAdmin;
                                $requesterName = trim((string) ($requester?->f_name.' '.$requester?->l_name));
                                $searchText = implode(' ', [
                                    $change->restaurant?->name ?? '商家#'.$change->restaurant_id,
                                    $change->network,
                                    $change->new_fingerprint,
                                    $requesterName,
                                    $requester?->email,
                                ]);
                            @endphp
                            <tr data-review-row data-search="{{ strtolower($searchText) }}"
                                data-detail-url="{{ route('admin.restaurant.payment-address-change.show', $change) }}"
                                tabindex="0" role="button" aria-label="查看 {{ $change->restaurant?->name ?? '商家#'.$change->restaurant_id }} 的地址变更详情">
                                <td>
                                    <div class="nz-review-shop">{{ $change->restaurant?->name ?? '商家#'.$change->restaurant_id }}</div>
                                    <div class="nz-review-muted">ID {{ $change->restaurant_id }}</div>
                                </td>
                                <td><span class="badge badge-soft-primary">{{ $change->network }}</span></td>
                                <td><span class="nz-review-fingerprint">{{ substr($change->new_fingerprint, 0, 12) }}…{{ substr($change->new_fingerprint, -8) }}</span></td>
                                <td>
                                    <div>{{ $requesterName !== '' ? $requesterName : '管理员#'.$change->requested_by_admin_id }}</div>
                                    <div class="nz-review-muted">ID {{ $change->requested_by_admin_id }}</div>
                                </td>
                                <td>{{ $change->merchant_confirmed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td>
                                    <span class="{{ $change->expires_at && $change->expires_at->lte(now()->addHours(2)) ? 'text-danger font-weight-bold' : '' }}">
                                        {{ $change->expires_at?->format('Y-m-d H:i') ?? '—' }}
                                    </span>
                                </td>
                                <td class="text-right">
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-review-open>复核</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="nz-review-empty d-none" data-review-no-results>
                <i class="tio-search"></i>
                没有匹配的待复核申请
            </div>
        @endif
    </div>
</div>

<div class="modal fade nz-review-modal" id="nz-review-modal" tabindex="-1" role="dialog"
    aria-labelledby="nz-review-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h4 class="modal-title mb-1" id="nz-review-modal-title">复核收款地址变更</h4>
                    <div class="nz-review-muted">完整地址只在本弹窗显示。请逐字核对网络、旧地址和新地址。</div>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="关闭">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="nz-review-loading" data-review-loading>
                    <div class="spinner-border text-primary mb-3" role="status"></div>
                    <div>正在读取待复核详情…</div>
                </div>
                <div class="nz-review-detail" data-review-detail>
                    <div class="nz-review-section">
                        <div class="nz-review-section__title">申请信息</div>
                        <div class="nz-review-grid">
                            <div class="nz-review-field"><div class="nz-review-field__label">商家</div><div class="nz-review-field__value" data-field="restaurant"></div></div>
                            <div class="nz-review-field"><div class="nz-review-field__label">网络</div><div class="nz-review-field__value" data-field="network"></div></div>
                            <div class="nz-review-field"><div class="nz-review-field__label">申请人</div><div class="nz-review-field__value" data-field="requester"></div></div>
                            <div class="nz-review-field"><div class="nz-review-field__label">商家确认时间</div><div class="nz-review-field__value" data-field="merchant-confirmed"></div></div>
                            <div class="nz-review-field"><div class="nz-review-field__label">申请到期时间</div><div class="nz-review-field__value" data-field="expires"></div></div>
                            <div class="nz-review-field"><div class="nz-review-field__label">申请编号</div><div class="nz-review-field__value nz-review-fingerprint" data-field="change-id"></div></div>
                        </div>
                    </div>

                    <div class="nz-review-section">
                        <div class="nz-review-section__title">当前地址</div>
                        <div class="nz-review-address" data-field="old-address"></div>
                        <div class="nz-review-muted mt-2">指纹：<span class="nz-review-fingerprint" data-field="old-fingerprint"></span></div>
                    </div>

                    <div class="nz-review-section">
                        <div class="nz-review-section__title">候选新地址</div>
                        <div class="nz-review-address nz-review-address--new" data-field="new-address"></div>
                        <div class="nz-review-muted mt-2">指纹：<span class="nz-review-fingerprint" data-field="new-fingerprint"></span></div>
                    </div>

                    <div class="nz-review-warning mb-3">
                        <i class="tio-shield-outlined mt-1"></i>
                        <div>批准会原子切换新地址，之后的新付款立即使用新地址；已签发的旧地址凭据只保留到各自到期。驳回始终保持当前地址不变。</div>
                    </div>

                    <div class="form-group mb-3">
                        <label for="nz-review-totp" class="font-weight-bold">6 位 TOTP 验证码</label>
                        <input id="nz-review-totp" class="form-control" type="text" inputmode="numeric"
                            autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000">
                        <small class="form-text text-muted">批准与驳回都必须使用当前复核员认证器中的最新验证码。</small>
                    </div>

                    <div class="form-group mb-0">
                        <label for="nz-review-reason" class="font-weight-bold">驳回原因 <span class="text-muted font-weight-normal">（选填）</span></label>
                        <textarea id="nz-review-reason" class="form-control nz-review-reason" maxlength="500"
                            placeholder="可记录异常点，留空也可以驳回"></textarea>
                    </div>

                    <div class="nz-review-actions">
                        <form method="post" data-review-reject-form>
                            @csrf
                            <input type="hidden" name="new_fingerprint">
                            <input type="hidden" name="totp_code">
                            <input type="hidden" name="reason">
                            <button type="submit" class="btn btn-outline-danger w-100" disabled data-review-reject>
                                驳回申请
                            </button>
                        </form>
                        <form method="post" data-review-approve-form>
                            @csrf
                            <input type="hidden" name="new_fingerprint">
                            <input type="hidden" name="totp_code">
                            <button type="submit" class="btn btn-primary w-100" disabled data-review-approve>
                                批准申请
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('script_2')
<script>
(function () {
    'use strict';
    var modal = document.getElementById('nz-review-modal');
    if (!modal) return;
    var loading = modal.querySelector('[data-review-loading]');
    var detail = modal.querySelector('[data-review-detail]');
    var totp = document.getElementById('nz-review-totp');
    var reason = document.getElementById('nz-review-reason');
    var approveForm = modal.querySelector('[data-review-approve-form]');
    var rejectForm = modal.querySelector('[data-review-reject-form]');
    var approveButton = modal.querySelector('[data-review-approve]');
    var rejectButton = modal.querySelector('[data-review-reject]');
    var loaded = false;

    function setField(name, value) {
        var node = modal.querySelector('[data-field="' + name + '"]');
        if (node) node.textContent = value == null || value === '' ? '—' : String(value);
    }

    function formatTime(value) {
        if (!value) return '—';
        var date = new Date(value);
        return isNaN(date.getTime()) ? value : date.toLocaleString('zh-CN', {hour12:false});
    }

    function setActionsEnabled() {
        var valid = loaded && /^\d{6}$/.test(totp.value);
        approveButton.disabled = !valid;
        rejectButton.disabled = !valid;
    }

    function resetModal() {
        loaded = false;
        loading.style.display = 'block';
        detail.style.display = 'none';
        totp.value = '';
        reason.value = '';
        approveForm.removeAttribute('action');
        rejectForm.removeAttribute('action');
        ['old-address','new-address','old-fingerprint','new-fingerprint'].forEach(function (name) { setField(name, ''); });
        setActionsEnabled();
    }

    function showError() {
        loading.innerHTML = '<div class="text-danger"><i class="tio-error-outlined mr-1"></i>详情读取失败。申请可能已被处理，请刷新队列后重试。</div>';
    }

    function openReview(url) {
        resetModal();
        window.jQuery(modal).modal('show');
        fetch(url, {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin'})
            .then(function (response) { if (!response.ok) throw new Error('detail_failed'); return response.json(); })
            .then(function (data) {
                setField('restaurant', data.restaurant_name + '（ID ' + data.restaurant_id + '）');
                setField('network', data.network);
                setField('requester', data.requested_by_admin_name + '（ID ' + data.requested_by_admin_id + '）');
                setField('merchant-confirmed', formatTime(data.merchant_confirmed_at));
                setField('expires', formatTime(data.expires_at));
                setField('change-id', data.change_id);
                setField('old-address', data.old_address);
                setField('new-address', data.new_address);
                setField('old-fingerprint', data.old_fingerprint);
                setField('new-fingerprint', data.new_fingerprint);
                approveForm.action = data.approve_url;
                rejectForm.action = data.reject_url;
                approveForm.querySelector('[name="new_fingerprint"]').value = data.new_fingerprint;
                rejectForm.querySelector('[name="new_fingerprint"]').value = data.new_fingerprint;
                loaded = true;
                loading.style.display = 'none';
                detail.style.display = 'block';
                setActionsEnabled();
                totp.focus();
            })
            .catch(showError);
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-review-row]'), function (row) {
        function activate(event) {
            if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            openReview(row.getAttribute('data-detail-url'));
        }
        row.addEventListener('click', activate);
        row.addEventListener('keydown', activate);
    });

    totp.addEventListener('input', function () {
        totp.value = totp.value.replace(/\D/g, '').slice(0, 6);
        setActionsEnabled();
    });

    function prepareSubmit(form, decision, color) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!loaded || !/^\d{6}$/.test(totp.value)) return;
            form.querySelector('[name="totp_code"]').value = totp.value;
            var reasonInput = form.querySelector('[name="reason"]');
            if (reasonInput) reasonInput.value = reason.value.trim();
            Swal.fire({
                title: '确认' + decision + '这次地址变更？',
                text: decision === '驳回' ? '当前收款地址不会改变。' : '批准后新地址将立即用于新付款；已签发的旧凭据仍可使用到各自到期。',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: color,
                confirmButtonText: '确认' + decision,
                cancelButtonText: '取消'
            }).then(function (result) {
                if (result.value) {
                    approveButton.disabled = true;
                    rejectButton.disabled = true;
                    form.submit();
                }
            });
        });
    }

    prepareSubmit(approveForm, '批准', '#102A4C');
    prepareSubmit(rejectForm, '驳回', '#C4193E');

    var search = document.getElementById('nz-review-search');
    if (search) {
        search.addEventListener('input', function () {
            var query = search.value.trim().toLowerCase();
            var visible = 0;
            Array.prototype.forEach.call(document.querySelectorAll('[data-review-row]'), function (row) {
                var match = query === '' || (row.getAttribute('data-search') || '').indexOf(query) !== -1;
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            var noResults = document.querySelector('[data-review-no-results]');
            if (noResults) noResults.classList.toggle('d-none', visible !== 0);
        });
    }

    window.jQuery(modal).on('hidden.bs.modal', resetModal);
})();
</script>
@endpush
