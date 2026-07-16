@extends('layouts.admin.app')

@section('title', translate('编辑集运期次'))

@section('content')
@php
    $unitLabels = \App\CentralLogics\NezhaConsolidationRound::UNIT_LABELS;
    $feeNote = \App\CentralLogics\NezhaConsolidationRound::FEE_NOTE;
    $cutoffVal = $round->cutoff_at ? \Carbon\Carbon::parse($round->cutoff_at)->format('Y-m-d\TH:i') : '';
    $etdVal = $round->etd ? \Carbon\Carbon::parse($round->etd)->format('Y-m-d') : '';
    $etaVal = $round->eta ? \Carbon\Carbon::parse($round->eta)->format('Y-m-d') : '';
    $curUnit = old('min_volume_unit', $round->min_volume_unit ?? 'm3');
@endphp
<div class="content container-fluid">
    <div class="page-header d-flex justify-content-between align-items-center flex-wrap">
        <h2 class="page-header-title mb-0">{{ translate('编辑集运期次') }} <small class="text-muted">{{ $round->round_no }}</small></h2>
        <a href="{{ route('admin.nezha-consolidation-rounds.show', $round->id) }}" class="btn btn-sm btn-outline-secondary">{{ translate('返回详情') }}</a>
    </div>

    @if($errors->any())
        <div class="alert alert-soft-danger">
            <ul class="mb-0 pl-3">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.nezha-consolidation-rounds.update', $round->id) }}" method="post">
        @csrf
        @method('PUT')

        {{-- 基本信息 --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="card-title mb-0">{{ translate('基本信息') }}</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="input-label">{{ translate('期次标题') }} <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required maxlength="191"
                            value="{{ old('title', $round->title) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="input-label">{{ translate('报名截止时间') }}</label>
                        <input type="datetime-local" name="cutoff_at" class="form-control" value="{{ old('cutoff_at', $cutoffVal) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="input-label">{{ translate('预计发出 (ETD)') }}</label>
                        <input type="date" name="etd" class="form-control" value="{{ old('etd', $etdVal) }}">
                    </div>
                    <div class="col-md-4">
                        <label class="input-label">{{ translate('预计到达 (ETA)') }}</label>
                        <input type="date" name="eta" class="form-control" value="{{ old('eta', $etaVal) }}">
                    </div>
                    <div class="col-md-6">
                        <label class="input-label">{{ translate('成团目标货量') }}</label>
                        <input type="number" step="0.01" min="0" name="min_volume_value" class="form-control"
                            value="{{ old('min_volume_value', $round->min_volume_value) }}" placeholder="{{ translate('留空表示不设目标') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="input-label">{{ translate('目标单位') }} <span class="text-danger">*</span></label>
                        <select name="min_volume_unit" class="form-control" required>
                            @foreach($unitLabels as $k => $lbl)
                                <option value="{{ $k }}" {{ $curUnit === $k ? 'selected' : '' }}>{{ $lbl }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">{{ translate('成团进度只统计与该单位相同的报名量，其它单位单独计数、不换算相加。') }}</small>
                    </div>
                </div>
            </div>
        </div>

        {{-- 报价与货代 (只展示, 无收款) --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="card-title mb-0">{{ translate('报价与货代信息') }}</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="input-label">{{ translate('单价') }}</label>
                        <input type="text" name="unit_price" class="form-control" maxlength="191"
                            value="{{ old('unit_price', $pricing['unit_price'] ?? '') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="input-label">{{ translate('时效') }}</label>
                        <input type="text" name="lead_time" class="form-control" maxlength="191"
                            value="{{ old('lead_time', $pricing['lead_time'] ?? '') }}">
                    </div>
                    <div class="col-md-12">
                        <label class="input-label">{{ translate('申报方式') }}</label>
                        <input type="text" name="declare_method" class="form-control" maxlength="500"
                            value="{{ old('declare_method', $pricing['declare_method'] ?? '') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="input-label">{{ translate('货代名称 / 公司') }}</label>
                        <input type="text" name="forwarder_name" class="form-control" maxlength="191"
                            value="{{ old('forwarder_name', $forwarder['name'] ?? '') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="input-label">{{ translate('货代联系方式') }}</label>
                        <input type="text" name="forwarder_contact" class="form-control" maxlength="255"
                            value="{{ old('forwarder_contact', $pricing['forwarder_contact'] ?? '') }}">
                    </div>
                </div>
                <div class="alert alert-soft-secondary mt-3 mb-0 py-2">
                    <small><i class="tio-info-outined mr-1"></i>{{ translate('商家端将逐字展示以下费用说明：') }}「{{ $feeNote }}」</small>
                </div>
            </div>
        </div>

        {{-- 备注 --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="card-title mb-0">{{ translate('备注') }}</h5></div>
            <div class="card-body">
                <textarea name="notes" class="form-control" rows="3" maxlength="2000">{{ old('notes', $round->notes) }}</textarea>
            </div>
        </div>

        <div class="text-right mb-4">
            <a href="{{ route('admin.nezha-consolidation-rounds.show', $round->id) }}" class="btn btn-secondary">{{ translate('取消') }}</a>
            <button type="submit" class="btn btn-primary">{{ translate('保存修改') }}</button>
        </div>
    </form>
</div>
@endsection
