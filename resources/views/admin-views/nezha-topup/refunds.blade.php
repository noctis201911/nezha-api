@extends('layouts.admin.app')

@section('title', translate('押金退款'))

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h2 class="page-header-title"><span class="page-header-icon"><i class="tio-undo"></i></span> <span>{{ translate('押金退款审核') }}</span></h2>
        <p class="text-muted mb-0">{{ translate('商家中途退回押金申请(仍营业)。运营核实真实敞口 + 系统逐门核算(制裁复筛/户名核对/可退额/欠款) → 审批锁定快照 → 线下转到 KYC 本人账户后登记放款。') }}</p>
    </div>

    @php $tabs = ['pending' => '待审', 'approved' => '已审批·待放款', 'paid' => '已放款', 'rejected' => '已打回']; @endphp
    <div class="mb-3 d-flex gap-2 flex-wrap">
        @foreach($tabs as $st => $label)
            <a href="{{ route('admin.nezha-topup.refunds', ['status' => $st]) }}"
               class="btn btn-sm {{ $status === $st ? 'btn-primary' : 'btn-outline-secondary' }}">{{ translate($label) }} ({{ $counts[$st] ?? 0 }})</a>
        @endforeach
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>#</th>
                            <th>{{ translate('商家') }}</th>
                            <th class="text-right">{{ translate('申请额') }}</th>
                            @if($status === 'paid')<th class="text-right">{{ translate('实际放款') }}</th>@endif
                            <th>{{ translate('提交时间') }}</th>
                            <th class="text-center">{{ translate('操作') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($list as $r)
                            <tr>
                                <td>#{{ $r->id }}</td>
                                <td><span class="font-weight-bold">{{ optional($r->restaurant)->name ?? ('#'.$r->restaurant_id) }}</span><small class="d-block text-muted">vendor {{ $r->vendor_id }}</small></td>
                                <td class="text-right font-weight-bold">{{ \App\CentralLogics\Helpers::format_currency($r->amount_claimed) }}</td>
                                @if($status === 'paid')<td class="text-right text-success">{{ \App\CentralLogics\Helpers::format_currency($r->amount_credited ?? 0) }}</td>@endif
                                <td><small>{{ \Carbon\Carbon::parse($r->created_at)->format('Y-m-d H:i') }}</small></td>
                                <td class="text-center">
                                    <a href="{{ route('admin.nezha-topup.refund-show', $r->id) }}" class="btn btn-sm btn-outline-primary">{{ $status === 'pending' ? translate('审核') : translate('查看') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $status === 'paid' ? 6 : 5 }}" class="text-center py-4 text-muted">{{ translate('暂无退款申请') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3 d-flex justify-content-end">{!! $list->links() !!}</div>
        </div>
    </div>
</div>
@endsection
