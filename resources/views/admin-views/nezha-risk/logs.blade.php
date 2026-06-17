@extends('layouts.admin.app')
@section('title', translate('风控日志'))
@section('content')
    <div class="content container-fluid">
        <div class="page-header d-flex flex-wrap justify-content-between align-items-center">
            <h1 class="page-header-title"><i class="tio-history"></i> {{ translate('风控日志 (审计)') }}</h1>
            <div>
                <a href="{{ route('admin.nezha-risk.queue') }}" class="btn btn-outline-warning btn-sm">{{ translate('审核队列') }}</a>
                <a href="{{ route('admin.nezha-risk.settings') }}" class="btn btn-outline-primary btn-sm">{{ translate('风控设置') }}</a>
            </div>
        </div>

        @php
            $statuses = ['all' => '全部', 'sanction' => '制裁命中', 'pending' => '待审', 'approved' => '已放行', 'rejected' => '已清退', 'cleared' => '已退款', 'auto' => '自动拒单'];
            $statusBadge = [
                'pending'  => 'badge-soft-warning',
                'approved' => 'badge-soft-success',
                'rejected' => 'badge-soft-danger',
                'cleared'  => 'badge-soft-info',
                'auto'     => 'badge-soft-secondary',
            ];
        @endphp

        <div class="mb-3">
            @foreach ($statuses as $k => $label)
                <a href="{{ route('admin.nezha-risk.logs', ['status' => $k]) }}"
                   class="btn btn-sm {{ $status == $k ? 'btn-primary' : 'btn-outline-secondary' }}">{{ translate($label) }}</a>
            @endforeach
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>{{ translate('时间') }}</th>
                                <th>{{ translate('顾客') }}</th>
                                <th>{{ translate('餐馆') }}</th>
                                <th>{{ translate('通道') }}</th>
                                <th class="text-right">{{ translate('金额') }}</th>
                                <th>{{ translate('处置档') }}</th>
                                <th>{{ translate('命中') }}</th>
                                <th>{{ translate('状态') }}</th>
                                <th>{{ translate('处置结果') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($records as $r)
                                <tr>
                                    <td>{{ $r->id }}</td>
                                    <td>{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                                    <td>
                                        @if ($r->user)
                                            {{ trim(($r->user->f_name ?? '') . ' ' . ($r->user->l_name ?? '')) }}
                                        @elseif ($r->guest_id)
                                            <small class="text-muted">{{ translate('游客') }}{{ $r->guest_id }}</small>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>{{ $r->restaurant->name ?? ('#' . $r->restaurant_id) }}</td>
                                    <td>{{ strtoupper($r->payment_channel) }}</td>
                                    <td class="text-right">{{ \App\CentralLogics\Helpers::format_currency($r->order_amount) }}</td>
                                    <td>
                                        @if ($r->action == 'reject')
                                            <span class="badge badge-soft-danger">{{ translate('拒单') }}</span>
                                        @else
                                            <span class="badge badge-soft-warning">{{ translate('审核') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @foreach (($r->hit_rules ?? []) as $h)
                                            <small class="d-block" title="{{ $h['detail'] ?? '' }}">{{ $h['rule'] ?? '' }}</small>
                                        @endforeach
                                    </td>
                                    <td>
                                        <span class="badge {{ $statusBadge[$r->status] ?? 'badge-soft-secondary' }}">{{ translate($statuses[$r->status] ?? $r->status) }}</span>
                                    </td>
                                    <td>
                                        <small>{{ $r->disposal_result }}</small>
                                        @if ($r->review_note)
                                            <br><small class="text-muted">{{ $r->review_note }}</small>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">{{ translate('暂无记录') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-3">{{ $records->links() }}</div>
    </div>
@endsection
