@extends('layouts.admin.app')
@section('title', $restaurant->name . ' - ' . translate('messages.收款信息'))
@section('content')
    <div class="content container-fluid">
        <div class="page-header">
            <div class="d-flex flex-wrap justify-content-between align-items-center">
                <h1 class="page-header-title text-break">
                    <i class="tio-museum"></i> <span>{{ $restaurant->name }}</span>
                </h1>
            </div>
            <div class="js-nav-scroller hs-nav-scroller-horizontal">
                <span class="hs-nav-scroller-arrow-prev initial-hidden">
                    <a class="hs-nav-scroller-arrow-link" href="javascript:"><i class="tio-chevron-left"></i></a>
                </span>
                <span class="hs-nav-scroller-arrow-next initial-hidden">
                    <a class="hs-nav-scroller-arrow-link" href="javascript:"><i class="tio-chevron-right"></i></a>
                </span>
                @include('admin-views.vendor.view.partials._header', ['restaurant' => $restaurant])
            </div>
        </div>

        <div class="alert alert-info" role="alert">
            <i class="tio-info"></i>
            {{ translate('哪吒外卖收款方式: 顾客付款直接付进商家本人的下列账户, 平台不经手资金。请如实填写本店真实收款信息。') }}
        </div>

        <form action="{{ route('admin.restaurant.update-payment-info', [$restaurant->id]) }}" method="post"
              enctype="multipart/form-data">
            @csrf
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-title">
                        <span class="card-header-icon"><i class="tio-wallet"></i></span> &nbsp;
                        <span>{{ translate('人民币收款 (微信/支付宝)') }}</span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('收款人姓名 (顾客转账时核对用)') }}</label>
                            <input type="text" name="payee_name" class="form-control"
                                   value="{{ $restaurant->payee_name }}" placeholder="{{ translate('如: 张三') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('收款码图片 (微信或支付宝收款二维码)') }}</label>
                            <div class="custom-file">
                                <input type="file" name="rmb_qr_image" id="rmb_qr_image" class="custom-file-input"
                                       accept=".jpg,.jpeg,.png,.webp">
                                <label class="custom-file-label" for="rmb_qr_image">{{ translate('选择图片') }}</label>
                            </div>
                            <small class="text-muted">{{ translate('支持 jpg/png/webp, 最大 2MB') }}</small>
                        </div>
                        <div class="col-12">
                            @if ($restaurant->rmb_qr_image_full_url)
                                <div class="mt-2">
                                    <label class="input-label">{{ translate('当前收款码') }}</label><br>
                                    <img src="{{ $restaurant->rmb_qr_image_full_url }}" alt="QR"
                                         style="max-width:200px;max-height:200px;border:1px solid #eee;border-radius:8px;"
                                         onerror="this.style.display='none';">
                                </div>
                            @else
                                <small class="text-danger">{{ translate('尚未上传人民币收款码') }}</small>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-title">
                        <span class="card-header-icon"><i class="tio-bitcoin"></i></span> &nbsp;
                        <span>{{ translate('USDT 收款') }}</span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="input-label">{{ translate('USDT 网络') }}</label>
                            <select name="usdt_network" class="form-control">
                                <option value="">{{ translate('-- 选择网络 --') }}</option>
                                <option value="TRC20" {{ $restaurant->usdt_network == 'TRC20' ? 'selected' : '' }}>TRC20 (Tron)</option>
                                <option value="BSC" {{ $restaurant->usdt_network == 'BSC' ? 'selected' : '' }}>BSC (BEP20)</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="input-label">{{ translate('USDT 收款地址') }}</label>
                            <input type="text" name="usdt_address" class="form-control"
                                   value="{{ $restaurant->usdt_address }}"
                                   placeholder="{{ translate('如: TXxxxxx... 或 0xXxxx...') }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body text-right">
                    <button type="submit" class="btn btn-primary">{{ translate('保存收款信息') }}</button>
                </div>
            </div>
        </form>

        {{-- 哪吒外卖 B方案 组4: 保证金管理 (余额 / 充值 / 流水) --}}
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="card-title">
                    <span class="card-header-icon"><i class="tio-savings"></i></span> &nbsp;
                    <span>{{ translate('保证金管理') }}</span>
                </h5>
            </div>
            <div class="card-body">
                <div class="row align-items-center mb-3">
                    <div class="col-md-4">
                        <span class="text-muted d-block">{{ translate('当前保证金余额') }}</span>
                        <strong class="fs-18 {{ (($depositBalance ?? 0) < ($depositThreshold ?? 0)) ? 'text-danger' : 'text-success' }}">
                            {{ \App\CentralLogics\Helpers::format_currency($depositBalance ?? 0) }}
                        </strong>
                    </div>
                    <div class="col-md-4">
                        <span class="text-muted d-block">{{ translate('停接单阈值') }}</span>
                        <strong class="fs-18">{{ \App\CentralLogics\Helpers::format_currency($depositThreshold ?? 0) }}</strong>
                    </div>
                    <div class="col-md-4">
                        <span class="text-muted d-block">{{ translate('扣佣模式') }}</span>
                        @if (($depositMode ?? 0) == 1)
                            <span class="badge badge-soft-success">{{ translate('已开启 (二阶段: 扣佣+不足停接单)') }}</span>
                        @else
                            <span class="badge badge-soft-secondary">{{ translate('未开启 (一阶段: 免佣免押)') }}</span>
                        @endif
                    </div>
                </div>

                @if (($depositBalance ?? 0) < ($depositThreshold ?? 0) && ($depositMode ?? 0) == 1)
                    <div class="alert alert-warning py-2">
                        <i class="tio-warning"></i>
                        {{ translate('该餐馆保证金低于阈值, 当前已停止接收新单。请充值后恢复。') }}
                    </div>
                @endif

                <form action="{{ route('admin.restaurant.recharge-deposit', [$restaurant->id]) }}" method="post">
                    @csrf
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="input-label">{{ translate('充值/调整金额') }}</label>
                            <input type="number" step="0.01" name="amount" class="form-control"
                                   placeholder="{{ translate('正数充值, 负数扣减') }}" required>
                        </div>
                        <div class="col-md-5">
                            <label class="input-label">{{ translate('备注 (选填)') }}</label>
                            <input type="text" name="note" class="form-control" maxlength="191"
                                   placeholder="{{ translate('如: 商家微信转账充值 500') }}">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-success btn-block">{{ translate('提交') }}</button>
                        </div>
                    </div>
                    <small class="text-muted">{{ translate('提示: 充值仅记录到本系统保证金账本, 实际收款请商家另行线下转账给平台。') }}</small>
                </form>
            </div>
        </div>

        {{-- 保证金流水 --}}
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="card-title"><span>{{ translate('保证金流水 (最近20条)') }}</span></h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-borderless mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>{{ translate('时间') }}</th>
                                <th>{{ translate('类型') }}</th>
                                <th>{{ translate('订单') }}</th>
                                <th class="text-right">{{ translate('金额') }}</th>
                                <th class="text-right">{{ translate('余额') }}</th>
                                <th>{{ translate('备注') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $typeLabels = [
                                    'commission_deduction' => translate('订单扣佣'),
                                    'recharge' => translate('充值'),
                                    'refund_reversal' => translate('退款返还'),
                                    'adjustment' => translate('调整'),
                                ];
                            @endphp
                            @forelse (($depositLogs ?? []) as $log)
                                <tr>
                                    <td>{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                                    <td>{{ $typeLabels[$log->type] ?? $log->type }}</td>
                                    <td>{{ $log->order_id ? '#' . $log->order_id : '-' }}</td>
                                    <td class="text-right {{ $log->amount < 0 ? 'text-danger' : 'text-success' }}">
                                        {{ \App\CentralLogics\Helpers::format_currency($log->amount) }}
                                    </td>
                                    <td class="text-right">{{ \App\CentralLogics\Helpers::format_currency($log->balance_after) }}</td>
                                    <td>{{ $log->note }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">{{ translate('暂无保证金流水') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
