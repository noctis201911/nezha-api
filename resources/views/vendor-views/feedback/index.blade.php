@extends('layouts.vendor.app')

@section('title', '问题反馈')

@section('content')
    <div class="content container-fluid">
        <div class="page-header">
            <h1 class="page-header-title">向平台反馈 / 求助</h1>
            <p class="text-muted mb-0">佣金、结算、功能用不了、或任何需要平台帮忙的，都可以在这里提交。平台收到后会处理并在下方回复你。</p>
        </div>

        <div class="row g-3">
            {{-- 提交表单 --}}
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><h5 class="card-header-title">提交反馈</h5></div>
                    <div class="card-body">
                        @if ($errors->any())
                            <div class="alert alert-danger">
                                @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                            </div>
                        @endif
                        <form method="POST" action="{{ route('vendor.feedback.store') }}">
                            @csrf
                            <div class="form-group mb-3">
                                <label class="input-label">类型</label>
                                <select name="type" class="form-control" required>
                                    <option value="commission" {{ old('type') == 'commission' ? 'selected' : '' }}>佣金问题</option>
                                    <option value="settlement" {{ old('type') == 'settlement' ? 'selected' : '' }}>结算问题</option>
                                    <option value="feature" {{ old('type') == 'feature' ? 'selected' : '' }}>功能用不了 / 报错</option>
                                    <option value="other" {{ old('type') == 'other' ? 'selected' : '' }}>其他</option>
                                </select>
                            </div>
                            <div class="form-group mb-3">
                                <label class="input-label">一句话主题</label>
                                <input type="text" name="subject" class="form-control" maxlength="150" required
                                       value="{{ old('subject') }}" placeholder="例如：本月佣金扣款对不上">
                            </div>
                            <div class="form-group mb-3">
                                <label class="input-label">详细说明</label>
                                <textarea name="description" class="form-control" rows="6" maxlength="4000" required
                                          placeholder="请尽量写清楚：发生了什么、什么时候、涉及哪个订单/金额。说得越清楚平台处理越快。">{{ old('description') }}</textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">提交反馈</button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- 我提交过的反馈 --}}
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><h5 class="card-header-title">我的反馈记录</h5></div>
                    <div class="card-body">
                        @forelse ($list as $f)
                            @php
                                $typeLabel = \App\Models\VendorFeedback::TYPE_LABELS[$f->type] ?? $f->type;
                                $statusLabel = \App\Models\VendorFeedback::STATUS_LABELS[$f->status] ?? $f->status;
                                $badge = $f->status === 'resolved' ? 'badge-soft-success' : ($f->status === 'in_progress' ? 'badge-soft-info' : 'badge-soft-warning');
                            @endphp
                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong>{{ $f->subject }}</strong>
                                    <span class="badge {{ $badge }}">{{ $statusLabel }}</span>
                                </div>
                                <div class="text-muted mb-2" style="font-size:12px;">
                                    {{ $typeLabel }} · {{ $f->created_at }}
                                </div>
                                <div style="white-space: pre-wrap; font-size: 13px;">{{ $f->description }}</div>
                                @if ($f->admin_note)
                                    <div class="alert alert-soft-secondary mt-2 mb-0" style="white-space: pre-wrap; font-size: 13px;">
                                        <strong>平台回复：</strong>{{ $f->admin_note }}
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="text-center text-muted py-4 mb-0">还没有提交过反馈。左边填一条试试。</p>
                        @endforelse
                        {!! $list->links() !!}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
