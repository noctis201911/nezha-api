@extends('layouts.admin.app')

@section('title', translate('商家反馈'))

@section('content')
    <div class="content container-fluid">
        <div class="page-header">
            <h1 class="page-header-title">{{ translate('商家反馈') }}</h1>
            <p class="text-muted mb-0">{{ translate('商家从商家后台「问题反馈」提交的求助/问题。处理后写回复并标「已处理」，商家会收到通知。') }}</p>
        </div>

        {{-- 状态筛选 --}}
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link {{ $status == 'open' ? 'active' : '' }}" href="{{ route('admin.vendor-feedback.index', ['status' => 'open']) }}">
                    {{ translate('待处理') }} ({{ $counts['open'] }})
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ $status == 'in_progress' ? 'active' : '' }}" href="{{ route('admin.vendor-feedback.index', ['status' => 'in_progress']) }}">
                    {{ translate('处理中') }} ({{ $counts['in_progress'] }})
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ $status == 'resolved' ? 'active' : '' }}" href="{{ route('admin.vendor-feedback.index', ['status' => 'resolved']) }}">
                    {{ translate('已处理') }} ({{ $counts['resolved'] }})
                </a>
            </li>
        </ul>

        <div class="card">
            <div class="card-body">
                @forelse ($list as $f)
                    @php
                        $typeLabel = \App\Models\VendorFeedback::TYPE_LABELS[$f->type] ?? $f->type;
                        $statusLabel = \App\Models\VendorFeedback::STATUS_LABELS[$f->status] ?? $f->status;
                        $rname = $f->restaurant->name ?? ('商家#' . $f->vendor_id);
                        $badge = $f->status === 'resolved' ? 'badge-soft-success' : ($f->status === 'in_progress' ? 'badge-soft-info' : 'badge-soft-warning');
                    @endphp
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <strong>#{{ $f->id }} · {{ $f->subject }}</strong>
                            <span class="badge {{ $badge }}">{{ $statusLabel }}</span>
                        </div>
                        <div class="text-muted mb-2" style="font-size:12px;">
                            {{ $rname }} · {{ translate('类型') }}：{{ $typeLabel }} · {{ $f->created_at }}
                        </div>
                        <div class="mb-2" style="white-space: pre-wrap; font-size: 13px;">{{ $f->description }}</div>

                        <form method="POST" action="{{ route('admin.vendor-feedback.resolve', $f->id) }}">
                            @csrf
                            <div class="row g-2 align-items-end">
                                <div class="col-sm-3">
                                    <label class="input-label" style="font-size:12px;">{{ translate('状态') }}</label>
                                    <select name="status" class="form-control form-control-sm">
                                        <option value="open" {{ $f->status == 'open' ? 'selected' : '' }}>{{ translate('待处理') }}</option>
                                        <option value="in_progress" {{ $f->status == 'in_progress' ? 'selected' : '' }}>{{ translate('处理中') }}</option>
                                        <option value="resolved" {{ $f->status == 'resolved' ? 'selected' : '' }}>{{ translate('已处理') }}</option>
                                    </select>
                                </div>
                                <div class="col-sm-7">
                                    <label class="input-label" style="font-size:12px;">{{ translate('回复商家(商家可见)') }}</label>
                                    <input type="text" name="admin_note" class="form-control form-control-sm" maxlength="2000"
                                           value="{{ $f->admin_note }}" placeholder="{{ translate('例如：已核对，本月佣金无误，明细见…') }}">
                                </div>
                                <div class="col-sm-2">
                                    <button type="submit" class="btn btn-sm btn-primary w-100">{{ translate('保存') }}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                @empty
                    <p class="text-center text-muted py-4 mb-0">{{ translate('该状态下暂无反馈。') }}</p>
                @endforelse
                {!! $list->links() !!}
            </div>
        </div>
    </div>
@endsection
