@extends('layouts.admin.app')

@section('title', translate('佣金充值管理'))

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <h2 class="page-header-title">
            <span class="page-header-icon"><i class="tio-wallet"></i></span>
            <span>{{ translate('佣金充值管理') }}（{{ translate('商家预存佣金 + 押金一览') }}）</span>
        </h2>
        <p class="text-muted mb-0">{{ translate('预存佣金 = 商家预付给平台的佣金, 平台按单从中扣除; 余额不足将停止接单。负余额=商家欠平台佣金。押金 = 商家独立质押, 退出时结算退还, 与预存佣金分开记。') }}</p>
    </div>

    @php
        // CNY→AMD 折算(押金入账口径: guarantee_balance 为 AMD 折算单值; 应缴档以人民币元定义)
        $toCny = fn ($amd) => $rateCny > 0 ? round($amd / $rateCny, 2) : 0;
        $tierCnyOf = fn ($t) => $t === null ? null : ($tiersCny[$t] ?? 0);
    @endphp

    {{-- 全局汇总 --}}
    <div class="row g-2 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('全平台预存佣金总额') }}</h6>
                <span class="h3">{{ \App\CentralLogics\Helpers::format_currency($summary['total_balance']) }}</span>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('欠款商家数') }}（{{ translate('余额为负') }}）</h6>
                <span class="h3 {{ $summary['negative_count'] > 0 ? 'text-danger' : '' }}">{{ $summary['negative_count'] }}</span>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('累计充值') }}</h6>
                <span class="h3 text-success">{{ \App\CentralLogics\Helpers::format_currency($summary['total_recharge']) }}</span>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('累计扣佣') }}</h6>
                <span class="h3">{{ \App\CentralLogics\Helpers::format_currency($summary['total_deduction']) }}</span>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card h-100 border-primary"><div class="card-body">
                <h6 class="text-muted mb-1">{{ translate('全平台押金总额') }}</h6>
                <span class="h3 text-primary">{{ \App\CentralLogics\Helpers::format_currency($summary['total_guarantee']) }}</span>
                <span class="d-block text-muted small">≈¥{{ number_format($toCny($summary['total_guarantee']), 2) }}</span>
            </div></div>
        </div>
    </div>

    @php
        $sortLink = function ($col, $label) use ($sort, $dir) {
            $nextDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
            $url = route('admin.nezha-deposit.index', array_merge(request()->except(['sort', 'dir', 'page']), ['sort' => $col, 'dir' => $nextDir]));
            $arrow = $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : ' <span style="opacity:.3">↕</span>';
            return '<a href="' . $url . '" class="text-reset text-decoration-none">' . e($label) . $arrow . '</a>';
        };
    @endphp

    <div class="card">
        <div class="card-header flex-between flex-wrap gap-2">
            <h5 class="card-title mb-0">{{ translate('各商家预存佣金 / 押金') }}</h5>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.nezha-deposit.transactions') }}" class="btn btn-sm btn-outline-primary">{{ translate('全部流水') }}</a>
                <form action="{{ route('admin.nezha-deposit.index') }}" method="GET" class="d-flex gap-1">
                    <input type="hidden" name="sort" value="{{ $sort }}">
                    <input type="hidden" name="dir" value="{{ $dir }}">
                    <input type="search" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="{{ translate('搜索商家名') }}">
                    <button class="btn btn-sm btn-primary">{{ translate('messages.search') }}</button>
                </form>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-borderless table-thead-bordered table-align-middle card-table">
                    <thead class="thead-light">
                        <tr>
                            <th>{!! $sortLink('name', translate('商家')) !!}</th>
                            <th class="text-right">{!! $sortLink('balance', translate('预存佣金余额')) !!}</th>
                            <th class="text-right">{!! $sortLink('recharge', translate('累计充值')) !!}</th>
                            <th class="text-right">{!! $sortLink('deduction', translate('累计扣佣')) !!}</th>
                            <th class="text-right">{!! $sortLink('reversal', translate('累计退还')) !!}</th>
                            <th class="text-right bg-light">{!! $sortLink('guarantee', translate('押金实缴')) !!}</th>
                            <th class="bg-light">{{ translate('应缴档 / 缺口') }}</th>
                            <th class="text-center">{{ translate('messages.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($restaurants as $r)
                            @php
                                $bal = (float) $r->bal;
                                $gBal = (float) $r->g_bal;
                                $tier = $r->guarantee_tier;
                                $tierCny = $tierCnyOf($tier);
                                $reqAmd = $tierCny === null ? null : $tierCny * $rateCny;
                                $gap = $reqAmd === null ? null : max(0, $reqAmd - $gBal);
                            @endphp
                            <tr>
                                <td>
                                    <span class="d-block font-weight-bold">{{ $r->name }}</span>
                                    <small class="text-muted">ID {{ $r->id }} · vendor {{ $r->vendor_id }}</small>
                                </td>
                                <td class="text-right">
                                    @if($bal < 0)
                                        <span class="badge badge-soft-danger">{{ translate('欠款') }} {{ \App\CentralLogics\Helpers::format_currency(abs($bal)) }}</span>
                                    @else
                                        <span class="font-weight-bold">{{ \App\CentralLogics\Helpers::format_currency($bal) }}</span>
                                    @endif
                                </td>
                                <td class="text-right text-success">{{ \App\CentralLogics\Helpers::format_currency($r->total_recharge ?? 0) }}</td>
                                <td class="text-right">{{ \App\CentralLogics\Helpers::format_currency($r->total_deduction ?? 0) }}</td>
                                <td class="text-right">{{ \App\CentralLogics\Helpers::format_currency($r->total_reversal ?? 0) }}</td>
                                <td class="text-right bg-light">
                                    <span class="font-weight-bold">{{ \App\CentralLogics\Helpers::format_currency($gBal) }}</span>
                                    <small class="d-block text-muted">≈¥{{ number_format($toCny($gBal), 2) }}</small>
                                </td>
                                <td class="bg-light">
                                    @if($tier === null)
                                        <span class="badge badge-soft-secondary">{{ translate('未设档') }}</span>
                                    @elseif($tier === 'exempt')
                                        <span class="badge badge-soft-info">{{ translate('豁免') }}</span>
                                    @else
                                        <span class="d-block small">{{ translate('应缴') }} ¥{{ $tierCny }} <span class="text-muted">(≈{{ \App\CentralLogics\Helpers::format_currency($reqAmd) }})</span></span>
                                        @if($gap <= 0)
                                            <span class="badge badge-soft-success">{{ translate('已达标') }}</span>
                                        @else
                                            <span class="badge badge-soft-warning">{{ translate('缺') }} {{ \App\CentralLogics\Helpers::format_currency($gap) }}</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    <button type="button" class="btn btn-sm btn-primary recharge-btn"
                                        data-id="{{ $r->id }}" data-name="{{ $r->name }}"
                                        data-toggle="modal" data-target="#rechargeModal">{{ translate('记录充值') }}</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary guarantee-btn"
                                        data-id="{{ $r->id }}" data-name="{{ $r->name }}"
                                        data-tier="{{ $tier ?? '' }}"
                                        data-bal="{{ $gBal }}"
                                        data-req="{{ $reqAmd === null ? '' : $reqAmd }}"
                                        data-gap="{{ $gap === null ? '' : $gap }}"
                                        data-toggle="modal" data-target="#guaranteeModal">{{ translate('记录押金') }}</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary tier-btn"
                                        data-id="{{ $r->id }}" data-name="{{ $r->name }}" data-tier="{{ $tier ?? '' }}"
                                        data-toggle="modal" data-target="#tierModal">{{ translate('设档') }}</button>
                                    <a href="{{ route('admin.nezha-deposit.transactions', ['restaurant_id' => $r->id]) }}" class="btn btn-sm btn-outline-secondary">{{ translate('流水') }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center py-4 text-muted">{{ translate('暂无商家') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3 d-flex justify-content-end">{!! $restaurants->links() !!}</div>
        </div>
    </div>
</div>

{{-- 记录充值 弹窗 --}}
<div class="modal fade" id="rechargeModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form action="{{ route('admin.nezha-deposit.store-recharge') }}" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ translate('记录商家充值') }}</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="restaurant_id" id="recharge_restaurant_id">
                    <div class="form-group">
                        <label class="input-label">{{ translate('商家') }}</label>
                        <input type="text" id="recharge_restaurant_name" class="form-control" disabled>
                    </div>
                    <div class="form-group">
                        <label class="input-label">{{ translate('充值金额') }} <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required placeholder="{{ translate('商家实际打给平台的预存佣金金额') }}">
                    </div>
                    <div class="form-group">
                        <label class="input-label">{{ translate('备注') }}</label>
                        <input type="text" name="note" class="form-control" maxlength="255" placeholder="{{ translate('如: 微信转账 2026-06-16 / 凭证号') }}">
                    </div>
                    <p class="text-muted small mb-0">{{ translate('提示: 本操作只在商家已把钱打给平台后据实入账; 平台对商家收的是佣金预存(B2B), 不涉及顾客资金。') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ translate('messages.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ translate('确认入账') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- 记录押金缴纳 弹窗 --}}
<div class="modal fade" id="guaranteeModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form action="{{ route('admin.nezha-deposit.store-guarantee') }}" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ translate('记录押金缴纳') }}</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="restaurant_id" id="guarantee_restaurant_id">
                    <div class="form-group">
                        <label class="input-label">{{ translate('商家') }}</label>
                        <input type="text" id="guarantee_restaurant_name" class="form-control" disabled>
                    </div>
                    <div class="alert alert-soft-secondary py-2 small mb-3" id="guarantee_gap_hint">{{ translate('应缴档 / 实缴 / 缺口') }}</div>
                    <div class="form-row">
                        <div class="form-group col-4">
                            <label class="input-label">{{ translate('币种') }} <span class="text-danger">*</span></label>
                            <select name="currency" id="guarantee_currency" class="form-control">
                                <option value="AMD">AMD (֏)</option>
                                <option value="CNY">CNY (¥)</option>
                            </select>
                        </div>
                        <div class="form-group col-8">
                            <label class="input-label">{{ translate('缴纳原额(原币)') }} <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" name="original_amount" id="guarantee_original_amount" class="form-control" required placeholder="{{ translate('商家实际缴纳金额') }}">
                        </div>
                    </div>
                    <p class="text-muted small mb-2" id="guarantee_convert_hint"></p>
                    <div class="form-group">
                        <label class="input-label">{{ translate('缴纳回执 / 凭证号') }} <span class="text-danger">*</span></label>
                        <input type="text" name="original_ref" class="form-control" maxlength="255" required placeholder="{{ translate('如: 支付宝流水号 / 银行回单号') }}">
                    </div>
                    <div class="form-group">
                        <label class="input-label">{{ translate('备注') }}</label>
                        <input type="text" name="note" class="form-control" maxlength="255" placeholder="{{ translate('可选') }}">
                    </div>
                    <p class="text-muted small mb-0">{{ translate('押金为法币-only(不收 USDT); 入账以 AMD 折算记账, 原币/原额/回执号留痕。押金独立可退, 与预存佣金分开。') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ translate('messages.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ translate('确认入账') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- 设定押金应缴档 弹窗 --}}
<div class="modal fade" id="tierModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <form action="{{ route('admin.nezha-deposit.set-tier') }}" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ translate('设定押金应缴档') }}</h5>
                    <button type="button" class="btn-close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="restaurant_id" id="tier_restaurant_id">
                    <div class="form-group">
                        <label class="input-label">{{ translate('商家') }}</label>
                        <input type="text" id="tier_restaurant_name" class="form-control" disabled>
                    </div>
                    <div class="form-group">
                        <label class="input-label">{{ translate('应缴档') }} <span class="text-danger">*</span></label>
                        <select name="guarantee_tier" id="tier_select" class="form-control">
                            <option value="exempt">{{ translate('豁免(0)') }}</option>
                            <option value="500">¥500</option>
                            <option value="1000">¥1000</option>
                            <option value="5000">¥5000</option>
                        </select>
                    </div>
                    <p class="text-muted small mb-0">{{ translate('应缴档由超管按商家入驻来路与单量风险手动设定(普通 500-1000, 高单量可 5000); 仅用于缴纳核对提示, 不自动扣款。变更将记录留痕。') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ translate('messages.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ translate('保存') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('script_2')
<script>
    "use strict";
    var NZ_RATE_CNY = {{ $rateCny }};

    function nzFmtAmd(v) {
        return (Math.round(v * 100) / 100).toLocaleString() + ' ֏';
    }

    document.querySelectorAll('.recharge-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('recharge_restaurant_id').value = this.dataset.id;
            document.getElementById('recharge_restaurant_name').value = this.dataset.name;
        });
    });

    var gCur = document.getElementById('guarantee_currency');
    var gAmt = document.getElementById('guarantee_original_amount');
    function nzGuaranteePreview() {
        var amt = parseFloat(gAmt.value);
        var hint = document.getElementById('guarantee_convert_hint');
        if (isNaN(amt) || amt <= 0) { hint.textContent = ''; return; }
        var amd = gCur.value === 'CNY' ? amt * NZ_RATE_CNY : amt;
        hint.textContent = '{{ translate('折算入账') }}: ' + nzFmtAmd(amd) + (gCur.value === 'CNY' ? '  (¥' + amt + ' × ' + NZ_RATE_CNY + ')' : '');
    }
    if (gCur && gAmt) {
        gCur.addEventListener('change', nzGuaranteePreview);
        gAmt.addEventListener('input', nzGuaranteePreview);
    }

    document.querySelectorAll('.guarantee-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('guarantee_restaurant_id').value = this.dataset.id;
            document.getElementById('guarantee_restaurant_name').value = this.dataset.name;
            var req = this.dataset.req === '' ? null : parseFloat(this.dataset.req);
            var bal = parseFloat(this.dataset.bal) || 0;
            var gap = this.dataset.gap === '' ? null : parseFloat(this.dataset.gap);
            var hint = document.getElementById('guarantee_gap_hint');
            if (req === null) {
                hint.innerHTML = '{{ translate('该商家未设应缴档') }} · {{ translate('实缴') }} <b>' + nzFmtAmd(bal) + '</b>';
            } else {
                hint.innerHTML = '{{ translate('应缴') }} <b>' + nzFmtAmd(req) + '</b> · {{ translate('实缴') }} <b>' + nzFmtAmd(bal) + '</b> · {{ translate('缺口') }} <b class="' + (gap > 0 ? 'text-danger' : 'text-success') + '">' + nzFmtAmd(gap) + '</b>';
            }
            if (gAmt) { gAmt.value = ''; }
            document.getElementById('guarantee_convert_hint').textContent = '';
        });
    });

    document.querySelectorAll('.tier-btn').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('tier_restaurant_id').value = this.dataset.id;
            document.getElementById('tier_restaurant_name').value = this.dataset.name;
            var sel = document.getElementById('tier_select');
            if (this.dataset.tier) { sel.value = this.dataset.tier; }
        });
    });
</script>
@endpush
