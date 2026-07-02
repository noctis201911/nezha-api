@extends('layouts.admin.app')
@section('title', translate('退出结算审批'))
@section('content')
<div class="content container-fluid">
    <div class="page-header d-flex justify-content-between align-items-center">
        <h1 class="page-header-title mb-0">{{ translate('退出结算审批') }} · {{ translate('工单') }}#{{ $s->id }}</h1>
        <a href="{{ route('admin.nezha-offboard.index') }}" class="btn btn-sm btn-white">{{ translate('返回队列') }}</a>
    </div>

    {{-- 概览 --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3 mb-2"><div class="text-muted small">{{ translate('商家') }}</div><div>{{ $restaurant->name ?? ('#'.$s->restaurant_id) }}</div></div>
                <div class="col-md-3 mb-2"><div class="text-muted small">{{ translate('状态') }}</div>@include('admin-views.nezha-offboard._status', ['st' => $s->status])</div>
                <div class="col-md-3 mb-2"><div class="text-muted small">{{ translate('申请时间') }}</div><div>{{ $s->applied_at ? \Carbon\Carbon::parse($s->applied_at)->format('Y-m-d H:i') : '—' }}</div></div>
                <div class="col-md-3 mb-2"><div class="text-muted small">{{ translate('冷静期至') }}</div><div>{{ $s->cooldown_until ? \Carbon\Carbon::parse($s->cooldown_until)->format('Y-m-d H:i') : '—' }}</div></div>
            </div>
        </div>
    </div>

    {{-- 三账户余额 + 净额 --}}
    <div class="card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">{{ translate('账户余额与净额') }}</h5></div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col"><div class="text-muted small">{{ translate('预存佣金') }}</div><div class="font-weight-bold {{ ($wallet->deposit_balance ?? 0) < 0 ? 'text-danger' : '' }}">{{ \App\CentralLogics\Helpers::format_currency($wallet->deposit_balance ?? 0) }}</div></div>
                <div class="col"><div class="text-muted small">{{ translate('广告') }}</div><div class="font-weight-bold">{{ \App\CentralLogics\Helpers::format_currency($wallet->ad_balance ?? 0) }}</div></div>
                <div class="col"><div class="text-muted small">{{ translate('押金') }}</div><div class="font-weight-bold">{{ \App\CentralLogics\Helpers::format_currency($wallet->guarantee_balance ?? 0) }}</div></div>
                <div class="col border-left"><div class="text-muted small">{{ translate('审批快照净额') }}</div><div class="font-weight-bold {{ ($s->net_amount ?? 0) < 0 ? 'text-danger' : 'text-success' }}">{{ in_array($s->status, ['approved','paying','partial','paid','owing']) ? \App\CentralLogics\Helpers::format_currency($s->net_amount) : translate('审批时锁定') }}</div></div>
            </div>
            <p class="text-muted small mt-2 mb-0">{{ translate('净额 = 预存佣金 + 广告 + 押金 − 追偿(现为 0)。审批时先收净在途佣金再锁定快照; 净额为负则不放款、走人工追缴。三账户各退各账、不跨户。') }}</p>
        </div>
    </div>

    {{-- 待核实纠纷红旗(§H) --}}
    @if(($pendingDisputes ?? 0) > 0)
    <div class="alert alert-soft-danger">
        <strong>{{ translate('注意: 该商家有') }} {{ $pendingDisputes }} {{ translate('条待核实的举报/风控记录。') }}</strong>
        {{ translate('请先在举报/风控页核实处置(驳回恶意举报), 确认无真实未决纠纷后再放款。') }}
    </div>
    @endif

    {{-- 户名三方核对(§D3) --}}
    <div class="card mb-3">
        <div class="card-header"><h5 class="card-title mb-0">{{ translate('收款户名核对(放款前必核)') }}</h5></div>
        <div class="card-body">
            <table class="table table-sm mb-2">
                <tr><th style="width:34%">{{ translate('KYC 法人姓名') }}</th><td>{{ $kyc->legal_name ?? translate('（无 KYC 记录）') }}</td></tr>
                <tr><th>{{ translate('KYC 收款户名') }}</th><td>{{ $kyc->account_holder_name ?? '—' }}</td></tr>
                <tr><th>{{ translate('KYC 收款账户') }}</th><td>{{ $kyc->bank_account ?? '—' }}</td></tr>
                <tr><th>{{ translate('缴纳凭证付款人') }}</th><td>{{ $lastGuarantee->original_ref ?? translate('（无押金缴纳记录）') }}</td></tr>
            </table>
            <p class="text-muted small mb-0">{{ translate('放款只退回缴纳主体本人 KYC 核验账户。请逐字核对三者一致(法人姓名 == 收款户名 == 缴纳凭证付款人)。') }}</p>
        </div>
    </div>

    {{-- 审批 / 放款操作 --}}
    <div class="card">
        <div class="card-body">
            @if($s->status === 'applied')
                <h5 class="mb-2">{{ translate('审批') }}</h5>
                <p class="text-muted small">{{ translate('点击审批将用当前制裁名单实时核验(命中即拒并转人工 AML); 冷静期未过则暂不能通过。审批通过后锁定净额快照, 再单独放款。') }}</p>
                <form action="{{ route('admin.nezha-offboard.approve', $s->id) }}" method="POST" onsubmit="return confirm('{{ translate('确认审批? 将实时制裁核验并锁定净额快照。') }}');">
                    @csrf
                    <div class="form-group">
                        <label class="d-flex align-items-start" style="gap:8px;">
                            <input type="checkbox" name="holder_verified" value="1" required class="mt-1">
                            <span>{{ translate('我已逐字核对: 法人姓名 == 收款户名 == 缴纳凭证付款人, 三者一致') }}</span>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary">{{ translate('制裁核验并审批') }}</button>
                </form>
            @elseif(in_array($s->status, ['approved','paying','partial']))
                <h5 class="mb-2">{{ translate('放款') }}</h5>
                @if(!($payGate['ok'] ?? false))
                    <div class="alert alert-soft-warning">{{ $payGate['reason'] ?? translate('当前不可放款') }}</div>
                @endif
                <p class="text-muted small">{{ translate('放款将把三账户余额结清置零并写退还流水(各退各账)。请先线下把净额转到商家 KYC 收款账户, 再点此登记放款完成。高额退款须审批满 24 小时(次日转)后才可放款。') }}</p>
                <form action="{{ route('admin.nezha-offboard.pay', $s->id) }}" method="POST" onsubmit="return confirm('{{ translate('确认登记放款? 三账户将结清置零。') }}');">
                    @csrf
                    <button type="submit" class="btn btn-danger" {{ ($payGate['ok'] ?? false) ? '' : 'disabled' }}>{{ translate('登记放款完成') }}</button>
                </form>
            @elseif($s->status === 'kyc_pending')
                <div class="alert alert-soft-secondary mb-0">{{ translate('该商家尚未完成身份核验(KYC)。请到「商家 KYC 资料 → 待退出核验」录入并审核通过后, 本工单会自动进入待审批。') }}</div>
            @elseif($s->status === 'owing')
                <div class="alert alert-soft-danger mb-0">{{ translate('净额为负(欠款), 不放款。需人工向商家追缴后再处理。') }}</div>
            @else
                <div class="alert alert-soft-secondary mb-0">{{ translate('该工单已结束, 无可操作项。') }}</div>
            @endif
        </div>
    </div>
</div>
@endsection
