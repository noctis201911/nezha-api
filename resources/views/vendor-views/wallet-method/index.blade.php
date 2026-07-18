@extends('layouts.vendor.app')

@section('title', translate('messages.payment_information'))

@push('css_or_js')
    <style>
        /* 哪吒外卖: 只读收款方式核对页 */
        .nz-pay-card .nz-qr-box {
            display: inline-block; background: #fff; padding: 8px;
            border: 1px solid #eee; border-radius: 10px; line-height: 0;
        }
        .nz-pay-card .nz-qr-box img, .nz-pay-card .nz-qr-box svg {
            display: block; max-width: 150px; max-height: 150px; width: 100%; height: auto;
        }
        .nz-pay-empty {
            display: flex; align-items: center; justify-content: center; text-align: center;
            width: 150px; height: 150px; border: 1px dashed #d0d5dd; border-radius: 10px;
            color: #98a2b3; font-size: 12px; line-height: 1.5; background: #fafbfc; padding: 10px;
        }
        .nz-addr-box {
            word-break: break-all; background: #f7f8fa; border: 1px solid #eef0f3;
            border-radius: 8px; padding: 8px 12px; font-size: 14px; color: #1f2937;
        }
        .nz-usdt-network-card {
            height: 100%; border: 1px solid #e7eaf3; border-radius: 10px;
            background: #fff; padding: 16px;
        }
    </style>
@endpush

@section('content')
    <div class="content container-fluid">
        @include('vendor-views.partials.business_setup_header')

        @php
            $hasAlipay = !empty($restaurant?->rmb_qr_image);
            $hasTrc20 = !empty($restaurant?->usdt_address);
            $hasBep20 = !empty($restaurant?->usdt_bep20_address);
            $hasUsdt = $hasTrc20 || $hasBep20;
            $configuredUsdtNetworks = ($hasTrc20 ? 1 : 0) + ($hasBep20 ? 1 : 0);
            $usdtNetworkSummary = implode(' / ', array_filter([
                $hasTrc20 ? 'TRC20' : null,
                $hasBep20 ? 'BEP20' : null,
            ]));
            $configuredCount = ($hasAlipay ? 1 : 0) + ($hasUsdt ? 1 : 0);
        @endphp

        {{-- 说明: 平台不碰钱 + 只读核对 (INVARIANTS L1-1) --}}
        <div class="alert alert-info d-flex gap-2" role="alert">
            <i class="tio-info lh-1 mt-1"></i>
            <div>
                <div class="font-weight-bold mb-1">{{ translate('顾客付款直接进您本人的收款账户。') }}</div>
                <div class="fs-13">
                    {{ translate('下面是平台为您登记的收款方式。顾客在结算页选择对应方式时，看到的就是这里的收款码 / 地址，款项直接转给您本人。请核对是否正确——若有错误或需要修改，请联系平台客服处理（为防止误改导致收不到款，商家端暂不支持自行修改）。') }}
                </div>
            </div>
        </div>

        {{-- 状态摘要 --}}
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2 py-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="card-header-icon d-inline-flex"><i class="tio-wallet"></i></span>
                    <h5 class="mb-0">{{ translate('我的收款方式') }}</h5>
                    <span class="badge badge-soft-secondary">{{ translate('共') }} {{ $configuredCount }} {{ translate('种已启用') }}</span>
                </div>
                @if ($configuredCount === 0)
                    <span class="badge badge-soft-danger fs-13 py-2 px-3">
                        <i class="tio-warning"></i> {{ translate('尚未配置任何收款方式，顾客暂时无法向您付款，请尽快联系平台登记') }}
                    </span>
                @else
                    <span class="badge badge-soft-success fs-13 py-2 px-3">
                        <i class="tio-checkmark-circle"></i>
                        {{ translate('顾客可用') }}:
                        {{ trim(($hasAlipay ? translate('支付宝') . ' ' : '') . ($hasUsdt ? 'USDT (' . $usdtNetworkSummary . ')' : '')) }}
                    </span>
                @endif
            </div>
        </div>

        @if ($paymentAddressSecurity['enabled'] ?? false)
            @include('vendor-views.wallet-method.partials._payment-address-change', [
                'security' => $paymentAddressSecurity,
                'viewedSecurityNotifications' => $viewedSecurityNotifications ?? 0,
            ])
        @endif

        {{-- 支付宝收款 (人民币) --}}
        <div class="card mb-2 nz-pay-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h5 class="card-title mb-0">
                    <span class="card-header-icon"><i class="tio-wallet"></i></span> &nbsp;
                    <span>{{ translate('支付宝收款 (人民币)') }}</span>
                </h5>
                @if ($hasAlipay)
                    <span class="badge badge-soft-success badge-pill">{{ translate('已配置') }}</span>
                @else
                    <span class="badge badge-soft-secondary badge-pill">{{ translate('未配置') }}</span>
                @endif
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-start">
                    <div class="col-md-7">
                        <label class="input-label">{{ translate('收款人姓名 (顾客转账时核对用)') }}</label>
                        <div class="nz-addr-box mb-2">
                            {{ $restaurant?->payee_name ?: translate('— 平台尚未登记 —') }}
                        </div>
                        <small class="text-muted">{{ translate('顾客在结算页选「支付宝」时，会扫描右侧收款码付款。') }}</small>
                    </div>
                    <div class="col-md-5">
                        <label class="input-label d-block mb-1">{{ translate('当前支付宝收款码') }}</label>
                        @if ($hasAlipay)
                            <span class="nz-qr-box">
                                <img src="{{ $restaurant->rmb_qr_image_full_url }}" alt="Alipay QR"
                                     onerror="this.parentNode.outerHTML='<div class=\'nz-pay-empty\'>{{ translate('收款码加载失败，请联系平台') }}</div>';">
                            </span>
                        @else
                            <div class="nz-pay-empty">{{ translate('平台尚未登记支付宝收款码') }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- 微信收款已下线: 平台放弃微信支付方式(2026-06-19), 顾客只用支付宝/USDT --}}

        {{-- USDT 收款 --}}
        <div class="card mb-2 nz-pay-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h5 class="card-title mb-0">
                    <span class="card-header-icon"><i class="tio-bitcoin"></i></span> &nbsp;
                    <span>{{ translate('USDT 收款') }}</span>
                </h5>
                @if ($hasUsdt)
                    <span class="badge badge-soft-success badge-pill">
                        {{ translate('已配置') }} {{ $configuredUsdtNetworks }} {{ translate('个网络') }}
                    </span>
                @else
                    <span class="badge badge-soft-secondary badge-pill">{{ translate('未配置') }}</span>
                @endif
            </div>
            <div class="card-body">
                @if ($hasUsdt)
                    <div class="row g-3 align-items-stretch">
                        @if ($hasTrc20)
                            <div class="col-lg-6">
                                <section class="nz-usdt-network-card" data-usdt-network="TRC20">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                                        <h6 class="mb-0">{{ translate('USDT · TRC20') }}</h6>
                                        <span class="badge badge-soft-success badge-pill">{{ translate('已配置') }}</span>
                                    </div>
                                    <label class="input-label">{{ translate('USDT · TRC20 收款地址') }}</label>
                                    <div class="nz-addr-box mb-2">{{ $restaurant?->usdt_address }}</div>
                                    <small class="text-muted d-block mb-3">
                                        {{ translate('仅限 TRON (TRC20) 网络。请核对网络与地址一致，跨网络转账可能无法到账且无法追回。') }}
                                    </small>
                                    <label class="input-label d-block mb-1">{{ translate('USDT · TRC20 收款二维码') }}</label>
                                    <span class="nz-qr-box">
                                        {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(150)->margin(1)->generate($restaurant->usdt_address) !!}
                                    </span>
                                </section>
                            </div>
                        @endif
                        @if ($hasBep20)
                            <div class="col-lg-6">
                                <section class="nz-usdt-network-card" data-usdt-network="BEP20">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                                        <h6 class="mb-0">{{ translate('USDT · BEP20') }}</h6>
                                        <span class="badge badge-soft-success badge-pill">{{ translate('已配置') }}</span>
                                    </div>
                                    <label class="input-label">{{ translate('USDT · BEP20 收款地址') }}</label>
                                    <div class="nz-addr-box mb-2">{{ $restaurant?->usdt_bep20_address }}</div>
                                    <small class="text-muted d-block mb-3">
                                        {{ translate('仅限 BNB Smart Chain (BEP20) 网络。请核对网络与地址一致，跨网络转账可能无法到账且无法追回。') }}
                                    </small>
                                    <label class="input-label d-block mb-1">{{ translate('USDT · BEP20 收款二维码') }}</label>
                                    <span class="nz-qr-box">
                                        {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(150)->margin(1)->generate($restaurant->usdt_bep20_address) !!}
                                    </span>
                                </section>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="nz-pay-empty">{{ translate('平台尚未登记 USDT 收款地址') }}</div>
                @endif
            </div>
        </div>

        {{-- 联系平台 --}}
        <div class="alert alert-light border d-flex gap-2 align-items-center" role="alert">
            <i class="tio-help-outlined lh-1"></i>
            <span class="fs-13">
                {{ translate('以上收款方式由平台统一登记与维护。如发现收款码、姓名或 USDT 地址有误，或想新增 / 停用某种收款方式，请通过左侧「帮助与支持 → 聊天」联系平台客服，由平台为您修改。') }}
            </span>
        </div>

    </div>
@endsection
