{{-- 哪吒[券重做 2026-06-25]: 商家券详情人话展示(满减/折扣); 商家券恒为 default, 已去免运费分支 --}}
@php($cur = \App\CentralLogics\Helpers::currency_symbol())
@php($mp = $coupon->min_purchase)
@php($isPercent = $coupon->discount_type == 'percent')
<div class="modal-body pt-2">
    <div class="coupon-details-inner d-flex align-items-center gap-24px">
        <div class="campaign-cont-box">
            <div class="mb-4 pb-xxl-3">
                @if ($isPercent)
                    <h5 class="mb-2 text-dark">{{ $mp>0 ? '满 '.\App\CentralLogics\Helpers::format_currency($mp).' · ' : '无门槛 · ' }}{{ rtrim(rtrim(number_format((float)$coupon->discount,2),'0'),'.') }}% OFF</h5>
                @else
                    <h5 class="mb-2 text-dark">{{ $mp>0 ? '满 '.\App\CentralLogics\Helpers::format_currency($mp).' 减 ' : '无门槛立减 ' }}{{ \App\CentralLogics\Helpers::format_currency($coupon->discount) }}</h5>
                @endif

                <h5 class="mb-2 text-dark">{{ translate('Code') }} : {{ $coupon->code }}</h5>
                <p class="mb-0">{{ $isPercent ? '按比例折扣' : '满减优惠' }}</p>
            </div>
            <div class="d-flex flex-column gap-2">
                <div class="d-flex gap-3">
                    <span class="fs-13 min-w-135px">最低消费</span>
                    <span>:</span>
                    <div>
                        <span class="text-title fw-500">{{ $mp>0 ? \App\CentralLogics\Helpers::format_currency($mp) : '无门槛' }}</span>
                    </div>
                </div>
                @if ($isPercent && $coupon->max_discount > 0)
                <div class="d-flex gap-3">
                    <span class="fs-13 min-w-135px">最高可减</span>
                    <span>:</span>
                    <div>
                        <span class="text-title fw-500">{{ \App\CentralLogics\Helpers::format_currency($coupon->max_discount) }}</span>
                    </div>
                </div>
                @endif
                <div class="d-flex gap-3">
                    <span class="fs-13 min-w-135px">每人限领</span>
                    <span>:</span>
                    <div>
                        <span class="text-title fw-500">{{ $coupon->limit }} 次</span>
                    </div>
                </div>
                <div class="d-flex gap-3">
                    <span class="fs-13 min-w-135px">{{ translate('Start Date') }}</span>
                    <span>:</span>
                    <div>
                        <span class="text-title fw-500">{{ \App\CentralLogics\Helpers::date_format($coupon->start_date) }}</span>
                    </div>
                </div>
                <div class="d-flex gap-3">
                    <span class="fs-13 min-w-135px">{{ translate('End Date') }}</span>
                    <span>:</span>
                    <div>
                        <span class="text-title fw-500">{{ \App\CentralLogics\Helpers::date_format($coupon->expire_date) }}</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="discount-off d-flex align-items-center justify-content-center bg-white">
            <div>
                <h2 class="text-title text-center m-0 fs-24">{{ $isPercent ? rtrim(rtrim(number_format((float)$coupon->discount,2),'0'),'.').'%' : \App\CentralLogics\Helpers::format_currency($coupon->discount) }} <br>
                    <small class="text-muted">{{ translate('Off') }}</small>
                </h2>
            </div>
        </div>
    </div>
</div>
