@extends('layouts.admin.app')

@section('title', translate('押金退款审核'))

@section('content')
@php
    $labels = ['pending' => '待审', 'approved' => '已审批·待放款', 'paid' => '已放款', 'rejected' => '已打回', 'cancelled' => '已撤回'];
    $canApprove = $req->status === 'pending';
    $canPay = $req->status === 'approved';
    $payReady = $req->scheduled_pay_at ? \Carbon\Carbon::parse($req->scheduled_pay_at)->lte(now()) : true;
@endphp
<div class="content container-fluid">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h2 class="page-header-title mb-0"><i class="tio-undo"></i> {{ translate('押金退款审核') }} #{{ $req->id }}</h2>
            <p class="text-muted mb-0">{{ translate('中途退回押金(商家仍营业)。退款只退回 KYC 核验的商家本人法币账户, 平台线下转账后登记。逐门核算见下, 放款时会再原子复核。') }}</p>
        </div>
        <a href="{{ route('admin.nezha-topup.refunds') }}" class="btn btn-sm btn-white">&larr; {{ translate('返回退款队列') }}</a>
    </div>

    <div class="row">
        {{-- 左: 申请 + 商家 KYC(锁定收款账户) --}}
        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">{{ translate('申请信息') }}</h5></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ translate('商家') }}</span><span class="font-weight-bold">{{ optional($req->restaurant)->name ?? ('#'.$req->restaurant_id) }}</span></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ translate('申请退款额') }}</span><span class="font-weight-bold">{{ \App\CentralLogics\Helpers::format_currency($req->amount_claimed) }}</span></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ translate('状态') }}</span><span class="badge badge-soft-info">{{ translate($labels[$req->status] ?? $req->status) }}</span></div>
                    <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ translate('提交时间') }}</span><small>{{ \Carbon\Carbon::parse($req->created_at)->format('Y-m-d H:i') }}</small></div>
                    @if($req->status === 'paid')
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ translate('实际放款') }}</span><span class="font-weight-bold text-success">{{ \App\CentralLogics\Helpers::format_currency($req->amount_credited) }}</span></div>
                        <div class="d-flex justify-content-between"><span class="text-muted">{{ translate('放款回执') }}</span><small>{{ $req->payout_ref }}</small></div>
                    @endif
                </div>
            </div>
            <div class="card mb-3 border-primary">
                <div class="card-header"><h5 class="card-title mb-0">{{ translate('收款账户(锁定 KYC 本人·不可现填)') }}</h5></div>
                <div class="card-body">
                    @if($kyc)
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ translate('法人姓名') }}</span><span class="font-weight-bold">{{ $kyc->legal_name }}</span></div>
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ translate('收款户名') }}</span><span class="font-weight-bold">{{ $kyc->account_holder_name }}</span></div>
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ translate('收款账户') }}</span><span>{{ $hv['masked'] ?: '—' }}</span></div>
                        <div class="alert {{ $hv['ok'] ? 'alert-soft-success' : 'alert-soft-danger' }} py-2 small mb-0">
                            {{ $hv['ok'] ? translate('户名核对通过: 法人=收款户名, 身份指纹一致') : ('✗ ' . $hv['detail']) }}
                        </div>
                    @else
                        <div class="alert alert-soft-danger py-2 small mb-0">{{ translate('该商家无 KYC 资料, 无法核对收款账户, 不可放款(fail-closed)') }}</div>
                    @endif
                </div>
            </div>
        </div>

        {{-- 右: 逐门核算 + 操作 --}}
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">{{ translate('可退额核算(放款时锁内实时复核)') }}</h5></div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col"><div class="text-muted small">{{ translate('押金余额') }}</div><div class="h5 mb-0">{{ \App\CentralLogics\Helpers::format_currency($ctx['guarantee']) }}</div></div>
                        <div class="col"><div class="text-muted small">{{ translate('最低留存(档 '.($ctx['tier'] ?? '未设').')') }}</div><div class="h5 mb-0">{{ \App\CentralLogics\Helpers::format_currency($ctx['floor']) }}</div></div>
                        <div class="col"><div class="text-muted small">{{ translate('可退额') }}</div><div class="h5 mb-0 text-primary">{{ \App\CentralLogics\Helpers::format_currency($ctx['refundable']) }}</div></div>
                        <div class="col"><div class="text-muted small">{{ translate('预存佣金') }}</div><div class="h5 mb-0 {{ $ctx['deposit'] < 0 ? 'text-danger' : '' }}">{{ \App\CentralLogics\Helpers::format_currency($ctx['deposit']) }}</div></div>
                    </div>
                    @if(!empty($ctx['blockers']))
                        <div class="alert alert-soft-warning mt-3 mb-0 small">
                            <div class="font-weight-bold mb-1">{{ translate('当前拦截项(需先解决)') }}:</div>
                            <ul class="mb-0 pl-3">@foreach($ctx['blockers'] as $b)<li>{{ $b }}</li>@endforeach</ul>
                        </div>
                    @else
                        <div class="alert alert-soft-success mt-3 mb-0 small">{{ translate('无拦截项。放款时仍会原子复核制裁复筛 + 户名 + 快照 + 可退额。') }}</div>
                    @endif
                </div>
            </div>

            @if($canApprove)
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">{{ translate('审批') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.nezha-topup.refund-approve', $req->id) }}" method="POST" onsubmit="return confirm('{{ translate('确认审批? 将实时制裁复筛 + 户名核对 + 锁定净额快照。') }}');">
                            @csrf
                            <div class="form-group">
                                <label class="input-label">{{ translate('实际退款额 (֏)') }} <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="{{ min($req->amount_claimed, $ctx['refundable']) }}" required>
                                <small class="text-muted">{{ translate('≤ 可退额; 平台线下转此额到上方锁定账户。') }}</small>
                            </div>
                            @if($ctx['commission_active'])
                                <div class="custom-control custom-checkbox mb-3">
                                    <input type="checkbox" class="custom-control-input" id="mec" name="manual_exposure_confirmed" value="1">
                                    <label class="custom-control-label" for="mec">{{ translate('抽佣已开启: 我已人工核实该商家真实敞口(在途/欠佣), 确认可退') }}</label>
                                </div>
                            @endif
                            <button type="submit" class="btn btn-primary" {{ !empty($ctx['blockers']) ? 'disabled' : '' }}>{{ translate('审批(锁定快照)') }}</button>
                            @if(!empty($ctx['blockers']))<small class="text-danger d-block mt-1">{{ translate('有拦截项, 无法审批') }}</small>@endif
                        </form>
                    </div>
                </div>
            @endif

            @if($canPay)
                <div class="card mb-3 border-success">
                    <div class="card-header"><h5 class="card-title mb-0">{{ translate('放款(登记线下已转账)') }}</h5></div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2"><span class="text-muted">{{ translate('已锁定退款额') }}</span><span class="font-weight-bold">{{ \App\CentralLogics\Helpers::format_currency($req->amount_credited) }}</span></div>
                        <div class="d-flex justify-content-between mb-3"><span class="text-muted">{{ translate('可放款时间') }}</span><span class="{{ $payReady ? 'text-success' : 'text-danger' }}">{{ $req->scheduled_pay_at ? \Carbon\Carbon::parse($req->scheduled_pay_at)->format('Y-m-d H:i') : translate('即时') }}{{ $payReady ? '' : ' ('.translate('未到点').')' }}</span></div>
                        <form action="{{ route('admin.nezha-topup.refund-pay', $req->id) }}" method="POST" onsubmit="return confirm('{{ translate('确认已线下转账到锁定账户并登记放款? 押金余额将冲减。') }}');">
                            @csrf
                            <button type="submit" class="btn btn-success" {{ $payReady ? '' : 'disabled' }}>{{ translate('确认放款(冲减押金)') }}</button>
                            @unless($payReady)<small class="text-danger d-block mt-1">{{ translate('高额/超日额退款须到点后放款') }}</small>@endunless
                        </form>
                    </div>
                </div>
            @endif

            @if($canApprove || $canPay)
                <div class="card">
                    <div class="card-body">
                        <form action="{{ route('admin.nezha-topup.refund-reject', $req->id) }}" method="POST">
                            @csrf
                            <div class="form-group mb-2"><label class="input-label">{{ translate('打回理由') }}</label>
                                <input type="text" name="reason" class="form-control" maxlength="255" required placeholder="{{ translate('如: 敞口不清 / 请走退出结算') }}"></div>
                            <button type="submit" class="btn btn-outline-danger btn-sm">{{ translate('打回申请') }}</button>
                        </form>
                    </div>
                </div>
            @endif

            @if($req->status === 'rejected' && $req->reason)
                <div class="alert alert-soft-danger">{{ translate('打回理由') }}: {{ $req->reason }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
