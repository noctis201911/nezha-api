@extends('layouts.admin.app')
@section('title', translate('商家 KYC 资料'))
@section('content')
    <div class="content container-fluid">
        <div class="page-header d-flex justify-content-between align-items-center">
            <h1 class="page-header-title">
                <i class="tio-user-shield"></i>
                {{ translate('KYC 资料') }} — {{ $restaurant->name }} <small class="text-muted">#{{ $restaurant->id }}</small>
            </h1>
            <a href="{{ route('admin.nezha-kyc.index') }}" class="btn btn-secondary btn-sm">
                <i class="tio-back-ui"></i> {{ translate('返回列表') }}
            </a>
        </div>

        {{-- 当前状态条 --}}
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center" style="gap:1rem;">
                <div>
                    <span class="text-muted">{{ translate('KYC 状态') }}: </span>
                    @php($ks = $profile->kyc_status ?? 'none')
                    @if ($ks === 'approved')
                        <span class="badge badge-soft-success">{{ translate('已通过') }}</span>
                    @elseif ($ks === 'pending')
                        <span class="badge badge-soft-warning">{{ translate('待审核') }}</span>
                    @elseif ($ks === 'rejected')
                        <span class="badge badge-soft-danger">{{ translate('已拒绝') }}</span>
                    @else
                        <span class="badge badge-soft-secondary">{{ translate('未建档') }}</span>
                    @endif
                </div>
                <div>
                    <span class="text-muted">{{ translate('制裁筛查') }}: </span>
                    @php($ss = $profile->screen_status ?? 'not_run')
                    @if ($ss === 'hit')
                        <span class="badge badge-soft-danger">{{ translate('命中 OFAC SDN') }}</span>
                    @elseif ($ss === 'possible')
                        <span class="badge badge-soft-warning">{{ translate('疑似·转人工') }}</span>
                    @elseif ($ss === 'clear')
                        <span class="badge badge-soft-success">{{ translate('已筛·无命中') }}</span>
                    @else
                        <span class="badge badge-soft-secondary">{{ translate('未筛查(阶段1接入后自动筛)') }}</span>
                    @endif
                </div>
                @if ($profile && $profile->reviewed_at)
                    <div><small class="text-muted">{{ translate('审核人') }}: {{ $profile->reviewer }} · {{ $profile->reviewed_at }}</small></div>
                @endif
            </div>
            @if ($profile && $profile->kyc_status === 'rejected' && $profile->reject_reason)
                <div class="card-footer"><small class="text-danger">{{ translate('拒绝原因') }}: {{ $profile->reject_reason }}</small></div>
            @endif
            @if ($profile && $profile->screen_status === 'possible' && $profile->screen_detail)
                <div class="card-footer"><small class="text-warning">{{ translate('筛查说明') }}: {{ $profile->screen_detail }}</small></div>
            @endif
        </div>

        {{-- 录入表单 --}}
        <form action="{{ route('admin.nezha-kyc.save', $restaurant->id) }}" method="post">
            @csrf
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">{{ translate('核验结论录入') }}</h5></div>
                <div class="card-body">
                    <div class="alert alert-warning py-2 mb-3">
                        <i class="tio-warning"></i>
                        {{ translate('请在【当面或视频核验过证件原件】后录入。本表单只存结论,不上传扫描件。法人姓名(拉丁拼写)将用于制裁名单筛查,请按证件拼写填写。') }}
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('法人/经营者真实姓名 (拉丁拼写)') }} <span class="text-danger">*</span></label>
                            <input type="text" name="legal_name" class="form-control" required
                                value="{{ old('legal_name', $profile->legal_name ?? '') }}"
                                placeholder="e.g. ZHANG SAN">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('本地文字姓名 (亚/中/俄, 可选)') }}</label>
                            <input type="text" name="legal_name_local" class="form-control"
                                value="{{ old('legal_name_local', $profile->legal_name_local ?? '') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('受益所有人姓名 (与法人不同时填)') }}</label>
                            <input type="text" name="beneficial_owner_name" class="form-control"
                                value="{{ old('beneficial_owner_name', $profile->beneficial_owner_name ?? '') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('核验方式') }}</label>
                            <select name="verify_method" class="form-control">
                                @php($vm = old('verify_method', $profile->verify_method ?? 'in_person'))
                                <option value="in_person" {{ $vm === 'in_person' ? 'selected' : '' }}>{{ translate('当面核验') }}</option>
                                <option value="video" {{ $vm === 'video' ? 'selected' : '' }}>{{ translate('视频核验') }}</option>
                                <option value="document" {{ $vm === 'document' ? 'selected' : '' }}>{{ translate('仅凭文件') }}</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('证件类型') }}</label>
                            <select name="id_doc_type" class="form-control">
                                @php($dt = old('id_doc_type', $profile->id_doc_type ?? ''))
                                <option value="">{{ translate('— 选择 —') }}</option>
                                <option value="passport" {{ $dt === 'passport' ? 'selected' : '' }}>{{ translate('护照') }}</option>
                                <option value="national_id" {{ $dt === 'national_id' ? 'selected' : '' }}>{{ translate('身份证') }}</option>
                                <option value="residence_permit" {{ $dt === 'residence_permit' ? 'selected' : '' }}>{{ translate('居留证') }}</option>
                                <option value="business_license" {{ $dt === 'business_license' ? 'selected' : '' }}>{{ translate('营业执照') }}</option>
                                <option value="other" {{ $dt === 'other' ? 'selected' : '' }}>{{ translate('其它') }}</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('证件号') }}</label>
                            <input type="text" name="id_doc_number" class="form-control"
                                value="{{ old('id_doc_number', $profile->id_doc_number ?? '') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('收款账户 (户名+账号 / 支付宝实名 / USDT 地址)') }}</label>
                            <input type="text" name="bank_account" class="form-control"
                                value="{{ old('bank_account', $profile->bank_account ?? '') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('联系电话') }}</label>
                            <input type="text" name="contact_phone" class="form-control"
                                value="{{ old('contact_phone', $profile->contact_phone ?? '') }}">
                        </div>
                        <div class="col-12">
                            <label class="input-label">{{ translate('运营备注 (内部)') }}</label>
                            <textarea name="note" class="form-control" rows="2">{{ old('note', $profile->note ?? '') }}</textarea>
                        </div>
                    </div>
                </div>
                <div class="card-footer text-right">
                    <button type="submit" class="btn btn-primary">{{ translate('保存 (转待审核)') }}</button>
                </div>
            </div>
        </form>

        {{-- 审核动作 --}}
        @if ($profile && $profile->kyc_status !== 'none')
            <form action="{{ route('admin.nezha-kyc.review', $restaurant->id) }}" method="post">
                @csrf
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">{{ translate('审核处置') }}</h5></div>
                    <div class="card-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-8">
                                <label class="input-label">{{ translate('拒绝原因 (拒绝时填)') }}</label>
                                <input type="text" name="reject_reason" class="form-control"
                                    value="{{ $profile->reject_reason ?? '' }}">
                            </div>
                            <div class="col-md-4 text-right">
                                <button type="submit" name="decision" value="approved" class="btn btn-success">
                                    {{ translate('审核通过') }}
                                </button>
                                <button type="submit" name="decision" value="rejected" class="btn btn-danger"
                                    onclick="return confirm('{{ translate('确认拒绝该商家 KYC?') }}')">
                                    {{ translate('拒绝') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        @endif
    </div>
@endsection
