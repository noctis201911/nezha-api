@php
    $max_processing_time = explode('-', $order['restaurant']['delivery_time'])[0];
    $c_address = null;
    if ($order->delivery_address) {
        $c_address = json_decode($order->delivery_address, true);
    }
@endphp


@if ($order->scheduled == 1)
    @section('scheduled')
    @elseif(in_array($order->order_status, ['confirmed', 'accepted']))
    @section('confirmed')
    @else
    @section($order->order_status)
    @endif

    active
@endsection

@extends('layouts.vendor.app')

@section('title', translate('messages.Order Details'))

@section('content')
    <?php
    use App\CentralLogics\Helpers;
    $campaign_order = isset($order->details[0]->campaign) ? true : false;
    $reasons = \App\Models\OrderCancelReason::where('status', 1)->where('user_type', 'restaurant')->get();
    $subscription = isset($order->subscription_id) ? true : false;
    $restaurant = Helpers::get_restaurant_data();
    $can_restaurant_edit_order = Helpers::get_business_settings('can_restaurant_edit_order') ?? 0;
    ?>

    <div class="content container-fluid item-box-page">

        <div class="page-header d-print-none">

            <h1 class="page-header-title text-capitalize">
                <div class="card-header-icon d-inline-flex mr-2 img">
                    <img src="{{ dynamicAsset('assets/admin/img/orders.png') }}" alt="public">
                </div>
                <span>
                    {{ translate('messages.Order Details') }}
                </span>
                <div class="d-flex ml-auto">
                    <a class="btn btn-icon btn-sm btn-soft-primary rounded-circle mr-1"
                        href="{{ route('vendor.order.details', [$order['id'] - 1]) }}" data-toggle="tooltip"
                        data-placement="top" title="{{ translate('Previous order') }}">
                        <i class="tio-chevron-left m-0"></i>
                    </a>
                    <a class="btn btn-icon btn-sm btn-soft-primary rounded-circle"
                        href="{{ route('vendor.order.details', [$order['id'] + 1]) }}" data-toggle="tooltip"
                        data-placement="top" title="{{ translate('Next order') }}">
                        <i class="tio-chevron-right m-0"></i>
                    </a>
                </div>
            </h1>
        </div>


        <div class="row g-1" id="printableArea">
            <div class="col-lg-8 order-print-area-left">
                <!-- Card -->
                <div class="card mb-3 mb-lg-5">
                    <!-- Header -->
                    <div class="card-header border-0 align-items-start flex-wrap">
                        <div class="order-invoice-left">
                            <h1 class="page-header-title flex-wrap gap-1 mt-2">
                                <span>
                                    {{ translate('messages.order') }} #{{ $order['id'] }}
                                </span>

                                @if ($order->edited)
                                    <span class="badge badge-soft-danger text-capitalize px-2 ml-2">
                                        {{ translate('messages.edited') }}
                                    </span>
                                @endif
                                @if ($order->orderEditLogs && count($order->orderEditLogs) > 0)

                                <a href="#0" class="w-sm-auto w-100 fs-12 font-medium hover-info ml-2 offcanvas-trigger" data-target="#offcanvas__history_log">({{ translate('Edit History Log') }})</a>
                                @endif
                                <div class="d-sm-none d-flex align-items-center gap-1">
                                    <button
                                        class="btn bn--primary btn-outline-primary m-2 print--btn edit-orde offcanvas-trigger"
                                        data-target="#offcanvas__order_edit" type="button">
                                        <i class="tio-edit m-0"></i>
                                    </button>
                                    <a target="_blank" class="btn btn--primary m-2 print--btn ml-auto"
                                        href="{{ route('vendor.order.generate-invoice', [$order['id']]) }}">
                                        <i class="tio-print mr-0"></i>
                                    </a>
                                </div>
                            </h1>

                            <span class="mt-2 d-block text--title">
                                {{ translate('messages.Placed_Date') }} :
                                <span class="font-semibold">
                                    {{ date('d M Y ' . config('timeformat'), strtotime($order['created_at'])) }}</span>
                            </span>
                            @if ($order->order_type == 'dine_in')
                                <span class="mt-2 d-block text--title">
                                    {{ translate('messages.Din-in_Date') }} :
                                    <span class="font-semibold">
                                        {{ date('d M Y ' . config('timeformat'), strtotime($order['schedule_at'])) }}
                                    </span>
                                </span>
                            @endif

                            @if ($subscription)
                                <span>
                                    <strong class="text-primary"> {{ translate('messages.subscription_order') }}</strong>
                                </span>
                                <br>
                            @endif
                            @if ($order->schedule_at && ($order->scheduled || $subscription))
                                <span class="text-capitalize d-block mt-1">
                                    {{ translate('messages.scheduled_at') }}
                                    : <label
                                        class="fz-10px badge badge-soft-primary">{{ date('d M Y ' . config('timeformat'), strtotime($order['schedule_at'])) }}</label>
                                </span>
                            @endif
                            @if ($campaign_order)
                                <span class="badge mt-2 badge-soft-primary">
                                    {{ translate('messages.campaign_order') }}
                                </span>
                            @endif

                            @if ($order?->offline_payments && $order?->offline_payments->status == 'denied' && $order?->offline_payments->note)
                                <h6 class="w-100 badge-soft-warning">
                                    <span class="text-dark">
                                        {{ translate('messages.Offline_payment_rejection_note') }} :
                                    </span>
                                    {{ $order?->offline_payments->note }}
                                </h6>
                            @endif

                            @if ($order['unavailable_item_note'])
                                <h6>
                                    {{ translate('messages.if_item_is_not_available') }} :
                                    {{ translate($order->unavailable_item_note) }}
                                </h6>
                            @endif
                            @if ($order['delivery_instruction'])
                                <h6>
                                    {{ translate('messages.order_delivery_instruction') }} :
                                    {{ translate($order->delivery_instruction) }}
                                </h6>
                            @endif

                            @if ($c_address)
                                <div class="hs-unfold mt-2">
                                    <button class="btn order--details-btn-sm btn--primary btn-outline-primary btn--sm"
                                        data-toggle="modal" data-target="#locationModal"><i class="tio-poi-outlined"></i>
                                        <span class="ml-1">{{ translate('messages.show_locations_on_map') }}</span>
                                    </button>
                                </div>
                            @endif
                             @if($order['order_note'])
                            <span  class="bg--F6F6F6 rounded py-1 px-2 fs-12 mt-3 d-flex align-items-center gap-1 font-regular m-0"><strong
                                    class="text-dark font-semibold">{{ translate('messages.Note:') }}</strong> {{ $order['order_note'] }}</span>
                            @endif
                        </div>
                        <div class="order-invoice-right">
                            <div class="d-none d-sm-flex flex-wrap ml-auto align-items-center justify-content-end m-n-5rem">

                                  @if ($can_restaurant_edit_order == 1 && $restaurant?->restaurant_config?->can_edit_order ==1 &&  !$subscription && !$campaign_order  && $order->payment_method == 'cash_on_delivery'  &&  in_array($order->order_status, ['pending', 'confirmed', 'processing', 'accepted']) && $order->restaurant)
                                    <button class="btn bn--primary btn-outline-primary m-1 print--btn "data-toggle="modal" data-target="#edit-order" type="button">
                                        <i class="tio-edit m-0"></i>
                                        <span>{{ translate('messages.edit_Item') }}</span>
                                    </button>
                                    @endif
                                <a target="_blank" class="btn btn--primary m-2 print--btn"
                                    href="{{ route('vendor.order.generate-invoice', [$order['id']]) }}">
                                    <i class="tio-print mr-0"></i> {{ translate('messages.print_invoice') }}
                                </a>
                            </div>
                            <div class="text-right mt-3 order-invoice-right-contents text-capitalize">
                                @if (isset($order->subscription))
                                    <h6>
                                        <span>{{ translate('messages.Subscription_status') }} :</span>
                                        @if ($order->subscription->status == 'active')
                                            <span class="badge badge-soft-success ">
                                                <span
                                                    class="legend-indicator bg-success"></span>{{ translate('messages.' . $order->subscription->status) }}
                                            </span>
                                        @elseif ($order->subscription->status == 'paused')
                                            <span class="badge badge-soft-primary">
                                                <span
                                                    class="legend-indicator bg-danger"></span>{{ translate('messages.' . $order->subscription->status) }}
                                            </span>
                                        @else
                                            <span class="badge badge-soft-primary ">
                                                <span
                                                    class="legend-indicator bg-info"></span>{{ translate('messages.' . $order->subscription->status) }}
                                            </span>
                                        @endif
                                    </h6>
                                @endif

                                <h6>
                                    <span>{{ translate('Order_Status') }} :</span>

                                    @if (isset($order->subscription) && $order->subscription->status != 'canceled')
                                        @php
                                            $order->order_status = $order->subscription_log
                                                ? $order->subscription_log->order_status
                                                : $order->order_status;
                                        @endphp
                                    @endif
                                    @if ($order['order_status'] == 'pending')
                                        <span class="badge badge-soft-info ml-2 ml-sm-3">
                                            {{ translate('messages.pending') }}
                                        </span>
                                    @elseif($order['order_status'] == 'confirmed')
                                        <span class="badge badge-soft-info ml-2 ml-sm-3">
                                            {{ translate('messages.confirmed') }}
                                        </span>
                                    @elseif($order['order_status'] == 'processing')
                                        <span class="badge badge-soft-warning ml-2 ml-sm-3">
                                            {{ translate('messages.cooking') }}
                                        </span>
                                    @elseif($order['order_status'] == 'picked_up')
                                        <span class="badge badge-soft-warning ml-2 ml-sm-3">
                                            {{ translate('messages.out_for_delivery') }}
                                        </span>
                                    @elseif($order['order_status'] == 'delivered')
                                        <span class="badge badge-soft-success ml-2 ml-sm-3">
                                            {{ $order?->order_type == 'dine_in' ? translate('messages.Completed') : translate('messages.delivered') }}
                                        </span>
                                    @else
                                        <span class="badge badge-soft-danger ml-2 ml-sm-3">
                                            {{ translate(str_replace('_', ' ', $order['order_status'])) }}
                                        </span>
                                    @endif

                                </h6>
                                @php
                                    $deliveryTypes = [
                                        'express' => ['class' => 'badge-soft-warning', 'label' => 'messages.Express'],
                                        'standard' => ['class' => 'badge-soft-info', 'label' => 'messages.Standard'],
                                        'slightly_delay' => ['class' => 'badge-soft-secondary', 'label' => 'messages.Slightly_Delay'],
                                    ];

                                    $type = $deliveryTypes[$order->delivery_type] ?? $deliveryTypes['standard'];
                                @endphp
                                    @if($type)

                                <h6>
                                    <span>{{ translate('messages.Delivery_Type') }} :</span>
                                        <span class="badge {{ $type['class'] }} ml-2 ml-sm-3 text-capitalize">
                                            {{ translate($type['label']) }}
                                        </span>
                                </h6>
                                    @endif

                                <h6>
                                    <span>
                                        {{ translate('messages.payment_method') }} :</span>
                                    <strong>
                                        {{ translate(str_replace('_', ' ', $order['payment_method'])) }}</strong>
                                </h6>

                                <h6>
                                    <span>{{ translate('messages.payment_status') }} :</span>
                                    @if ($order['payment_status'] == 'paid')
                                        <strong class="text-success">{{ translate('messages.paid') }}</strong>
                                    @elseif ($order['payment_status'] == 'partially_paid')
                                        @if ($order->payments()->where('payment_status', 'unpaid')->exists())
                                            <strong class="text-danger">{{ translate('messages.partially_paid') }}</strong>
                                        @else
                                            <strong class="text-success">{{ translate('messages.paid') }}</strong>
                                        @endif
                                    @else
                                        <strong class="text-danger">{{ translate('messages.unpaid') }}</strong>
                                    @endif
                                </h6>



                                @if ($order?->offline_payments)
                                    @php($nzAc = $order->offline_payments->nezha_auto_check ?? null)
                                    <div style="background:#FFF7E6;border:1px solid #FFE2A8;border-radius:10px;padding:10px 12px;margin-bottom:12px;">
                                        <div style="font-size:12px;color:#8a6d3b;">本单应收</div>
                                        <div style="font-size:22px;font-weight:800;color:#17191D;line-height:1.2;">{{ Helpers::format_currency($order->order_amount) }}</div>
                                        @if($nzAc && (!empty($nzAc['expected_usdt']) || !empty($nzAc['expected_rmb'])))
                                            @php($nzApprox = collect([!empty($nzAc['expected_usdt']) ? ('≈ '.$nzAc['expected_usdt'].' USDT') : null, !empty($nzAc['expected_rmb']) ? ('≈ ¥'.$nzAc['expected_rmb']) : null])->filter()->implode(' · '))
                                            <div style="font-size:12px;color:#8a6d3b;margin-top:2px;">{{ $nzApprox }}</div>
                                        @endif
                                        <div style="font-size:11px;color:#a98b54;margin-top:6px;">请先在自己账户核对到账金额与上方一致，再点「确认收款」。</div>
                                    </div>
                                    @foreach ((json_decode($order->offline_payments->payment_info) ?? []) as $key => $item)
                                        @if ($key != 'method_id')
                                            <h6 class="">
                                                <div class="d-flex justify-content-sm-end text-capitalize">
                                                    <span class="title-color">{{ translate($key) }} :</span>
                                                    @php($nzProof = \App\CentralLogics\Helpers::offline_payment_proof_url($item))
                                                    @if($nzProof)
                                                        <a href="{{ $nzProof }}" target="_blank" rel="noopener"><img src="{{ $nzProof }}" alt="{{ translate('payment proof') }}" style="max-width:140px;max-height:140px;border-radius:8px;border:1px solid #e6e6e6;object-fit:cover;"></a>
                                                    @else
                                                    @if(preg_match('/^(0x)?[0-9a-fA-F]{64}$/', trim($item)))
                                                        @php($nzTxUrl = $nzAc['chain']['explorer_url'] ?? ('https://tronscan.org/#/transaction/'.preg_replace('/^0x/i','',trim($item))))
                                                        <a href="{{ $nzTxUrl }}" target="_blank" rel="noopener" style="word-break:break-all;color:#1769aa;text-decoration:underline;"><strong>{{ $item }}</strong> &#8599;</a>
                                                    @else
                                                        <strong>{{ $item }}</strong>
                                                    @endif
                                                    @endif
                                                </div>
                                            </h6>
                                        @endif
                                    @endforeach
                                    <h6>
                                        <span>{{ translate('Payment_verification') }}</span> <span>:</span>
                                        @if ($order?->offline_payments->status == 'pending')
                                            <span class="badge badge-soft-info ml-2 ml-sm-3 text-capitalize">
                                                {{ translate('messages.pending') }}
                                            </span>
                                        @elseif ($order?->offline_payments->status == 'verified')
                                            <span class="badge badge-soft-success ml-2 ml-sm-3 text-capitalize">
                                                {{ translate('messages.verified') }}
                                            </span>
                                        @elseif ($order?->offline_payments->status == 'denied')
                                            <span class="badge badge-soft-danger ml-2 ml-sm-3 text-capitalize">
                                                {{ translate('messages.denied') }}
                                            </span>
                                        @endif
                                    </h6>

                                    @if($nzAc)
                                        @php($nzChain = $nzAc['chain'] ?? null)
                                        @if($nzChain)
                                            @php($cs = $nzChain['status'] ?? '')
                                            <div style="margin-top:8px;padding:10px 12px;border-radius:10px;@if($cs==='verified')background:#E8F6EC;border:1px solid #BCE5C7;@elseif($cs==='unconfirmed')background:#FFF7E6;border:1px solid #FFE2A8;@else background:#FDECEC;border:1px solid #F5C2C2;@endif">
                                                <div style="font-size:13px;font-weight:700;color:#17191D;">@if($cs==='verified')✅ 链上已核验到账@elseif($cs==='unconfirmed')⏳ 链上已查到 · 待区块确认@elseif($cs==='mismatch')⚠️ 链上核验不符@elseif($cs==='not_found')⚠️ 链上查无此交易@elseif($cs==='invalid_hash')⚠️ 交易哈希无效@else ℹ️ 未自动核验@endif</div>
                                                <div style="font-size:12px;color:#555;margin-top:3px;">{{ $nzChain['reason'] ?? '' }}</div>
                                                @if(!empty($nzChain['explorer_url']))<a href="{{ $nzChain['explorer_url'] }}" target="_blank" rel="noopener" style="font-size:12px;color:#1769aa;text-decoration:underline;">在区块浏览器查看 &#8599;</a>@endif
                                                <div style="font-size:11px;color:#999;margin-top:4px;">自动核验仅供参考，确认收款前请仍以你钱包实际到账为准。</div>
                                            </div>
                                        @endif
                                        @if(isset($nzAc['amount_match']) && $nzAc['amount_match'] !== null)
                                            <div style="margin-top:6px;font-size:12.5px;color:{{ $nzAc['amount_match'] ? '#1b8a3f' : '#c0392b' }};">顾客填写实付：<strong>{{ $nzAc['paid_amount'] ?? '' }}</strong> {{ $nzAc['amount_match'] ? '（与应付相符 ✓）' : '（与应付不符，请核对 ⚠️）' }}</div>
                                        @endif
                                        @if(!empty($nzAc['image_flags']))
                                            @php($nzImg = collect(['low_res'=>'分辨率偏低','blurry'=>'疑似模糊','blank'=>'接近空白'])->filter(fn($v,$k)=>!empty($nzAc['image_flags'][$k]))->values()->implode('、'))
                                            <div style="margin-top:6px;font-size:12.5px;color:#b8860b;">⚠️ 凭证图片存疑：{{ $nzImg }} — 请仔细核对，必要时让顾客重传。</div>
                                        @endif
                                    @endif

                                    {{-- 哪吒 B方案: 商家自营「确认收款」操作。仅离线支付 + 待核验时显示。 --}}
                                    @if ($order->payment_method == 'offline_payment' && $order?->offline_payments->status == 'pending')
                                        <div class="mt-3 p-3" style="background:#FFF7E6;border-radius:12px;">
                                            <p class="mb-2" style="color:#8a6d3b;font-size:13px;line-height:1.6;">
                                                {{ translate('messages.For_offline_payments_please_verify_if_the_payments_are_safely_received_to_your_account_Customer_id_not_liable_if_you_confirm_and_deliver_the_orders_without_checking_payments_transactions') }}
                                            </p>
                                            <div class="d-flex flex-wrap" style="gap:8px;">
                                                <form action="{{ route('vendor.order.confirm-offline-payment', ['id' => $order['id']]) }}" method="post"
                                                    onsubmit="return confirm('请确认：您已在自己的账户收到本单顾客的付款？确认后将通知顾客并可开始出餐。');">
                                                    @csrf
                                                    @method('PUT')
                                                    <button type="submit" class="btn btn-success btn-sm" style="border-radius:8px;">
                                                        确认收款（我已收到顾客付款）
                                                    </button>
                                                </form>
                                                <button type="button" class="btn btn-outline-danger btn-sm" style="border-radius:8px;"
                                                    data-toggle="modal" data-target="#nzDenyOffline-{{ $order['id'] }}">
                                                    未收到 / 打回
                                                </button>
                                            </div>
                                        </div>

                                        {{-- 拒收备注弹窗 --}}
                                        <div class="modal fade" id="nzDenyOffline-{{ $order['id'] }}" tabindex="-1" role="dialog" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered" role="document">
                                                <div class="modal-content" style="border-radius:12px;">
                                                    <form action="{{ route('vendor.order.deny-offline-payment', ['id' => $order['id']]) }}" method="post">
                                                        @csrf
                                                        @method('PUT')
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">打回 / 未收到付款</h5>
                                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p style="font-size:13px;color:#666;">请填写打回原因（将通知顾客，让其重新上传付款凭证或联系客服）：</p>
                                                            <textarea name="note" required maxlength="255" rows="3" class="form-control" style="border-radius:8px;"
                                                                placeholder="例如：未在账户查到这笔到账，请核对转账金额/收款码后重新上传凭证"></textarea>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">取消</button>
                                                            <button type="submit" class="btn btn--danger btn-sm">确认打回</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                @endif
                                <!-- offline_payment -->

                                {{-- 哪吒 F-4: 待商家退款卡。平台已取消/退款本直付单, 商家需原路退还原付款人, 退款后点「标记已退款」。平台不碰钱。 --}}
                                @php($nzRefundRec = \App\Models\NezhaRefundRecord::where('order_id', $order->id)->where('status', 'pending_merchant_refund')->latest('id')->first())
                                @if ($nzRefundRec)
                                    @php($nzAddr = $order->delivery_address ? json_decode($order->delivery_address, true) : [])
                                    <div class="mt-3 p-3" style="background:#FFF7E6;border:1px solid #FFE0A3;border-radius:12px;">
                                        <h6 class="mb-2" style="color:#C4193E;font-weight:700;">待您退款（平台已取消/退款本单）</h6>
                                        <p class="mb-2" style="font-size:13px;color:#8a6d3b;line-height:1.7;">
                                            请在您自己的收款账户里，按<strong>原路</strong>退还顾客的付款。平台不经手资金、仅记录。退款完成后点下方「标记已退款」。
                                        </p>
                                        <div style="font-size:13px;color:#333;line-height:1.9;">
                                            <div>应退金额：<strong>{{ Helpers::format_currency($nzRefundRec->refund_amount) }}</strong></div>
                                            <div>原付款渠道：<strong>@if ($nzRefundRec->payment_channel === 'usdt') USDT（{{ $nzRefundRec->chain ?? '链上' }}）@elseif ($nzRefundRec->payment_channel === 'rmb') 微信 / 支付宝（人民币）@else 见付款凭证 @endif</strong></div>
                                            @if ($nzRefundRec->payment_channel === 'usdt')
                                                <div style="word-break:break-all;">须退回的原地址：<strong>{{ $nzRefundRec->locked_to_address ?: '原始交易反查未果，请按付款凭证里的来源地址退回' }}</strong></div>
                                            @endif
                                            @if (!empty($nzAddr['contact_person_name']))<div>顾客：{{ $nzAddr['contact_person_name'] }}</div>@endif
                                            @if (!empty($nzAddr['contact_person_number']))<div>顾客电话（配送填写）：{{ \App\CentralLogics\Helpers::mask_phone($nzAddr['contact_person_number']) }}</div>@endif
                                            @if (!empty($nzAddr['contact_person_email']))<div>顾客邮箱：{{ \App\CentralLogics\Helpers::mask_email($nzAddr['contact_person_email']) }}</div>@endif
                                            <div style="color:#999;">退款如需联系顾客请用站内聊天（顾客邮箱/手机已脱敏保护隐私）。付款人见上方「付款凭证」截图；个人收款码不支持原路退回时，可按凭证里的账号重新转账退回。</div>
                                        </div>
                                        <button type="button" class="btn btn-success btn-sm mt-3" style="border-radius:8px;" data-toggle="modal" data-target="#nzMarkRefunded-{{ $order['id'] }}">标记已退款</button>
                                    </div>

                                    <div class="modal fade" id="nzMarkRefunded-{{ $order['id'] }}" tabindex="-1" role="dialog" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered" role="document">
                                            <div class="modal-content" style="border-radius:12px;">
                                                <form action="{{ route('vendor.order.mark-refunded', ['id' => $order['id']]) }}" method="post" onsubmit="return confirm('请确认：您已在自己的账户按原路退还本单顾客的付款？');">
                                                    @csrf
                                                    @method('PUT')
                                                    <div class="modal-header"><h5 class="modal-title">标记已退款</h5><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
                                                    <div class="modal-body">
                                                        @if ($nzRefundRec->payment_channel === 'usdt')
                                                            <div class="form-group"><label style="font-size:13px;">USDT 退款交易哈希（选填，便于平台链上核对）</label><input type="text" name="refund_tx_hash" maxlength="120" class="form-control" style="border-radius:8px;" placeholder="0x... / TRC20 txid"></div>
                                                        @endif
                                                        <div class="form-group mb-0"><label style="font-size:13px;">备注（选填）</label><textarea name="note" maxlength="255" rows="2" class="form-control" style="border-radius:8px;" placeholder="例如：已微信原路退回 / 已转回顾客支付宝"></textarea></div>
                                                    </div>
                                                    <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">取消</button><button type="submit" class="btn btn-success btn-sm">确认已退款</button></div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                <h6>
                                    <span>{{ translate('Order Type') }} :</span>
                                    <strong
                                        class="text--title">{{ translate(str_replace('_', ' ', $order['order_type'])) }}</strong>
                                </h6>

                                @if ($order->cutlery)
                                    <h6>
                                        <span>{{ translate('cutlery') }}</span> <span>:</span>
                                        <strong class="text-success">
                                            {{ translate('messages.yes') }}
                                        </strong>
                                    </h6>
                                @else
                                    <h6>
                                        <span>{{ translate('cutlery') }}</span> <span>:</span>
                                        <strong class="text-danger">
                                            {{ translate('messages.No') }}
                                        </strong>
                                    </h6>
                                @endif
                            </div>
                        </div>
                    </div>
                    <!-- End Header -->

                    <!-- Body -->
                    <div class="card-body p-0">
                        <?php
                        $product_price = 0;
                        $total_addon_price = 0;
                        $details = $order->details;
                        ?>
                        <div class="table-responsive">
                            <table
                                class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table dataTable no-footer mb-0">
                                <thead class="thead-light">
                                    <th>{{ translate('sl') }}</th>
                                    <th>{{ translate('item_details') }}</th>
                                    <th>{{ translate('addons') }}</th>
                                    <th class="text-right">{{ translate('price') }}</th>
                                </thead>
                                <tbody>
                                    @forelse ($details as $key => $detail)
                                        @if (isset($detail->food_id))
                                            <?php

                                            $deleted_food = $detail->food == null ? 1 : 0;
                                            $detail->food = json_decode($detail->food_details, true);
                                            $food = \App\Models\Food::where(['id' => $detail?->food['id']])
                                                ->with('storage')
                                                ->select('id', 'image')
                                                ->first();
                                            ?>
                                            <!-- Media -->
                                            <tr>
                                                <td>
                                                    <!-- Static Count Number -->
                                                    <div>
                                                        {{ $key + 1 }}
                                                    </div>
                                                    <!-- Static Count Number -->
                                                </td>
                                                <td>
                                                    <div class="media media--sm">
                                                        @if (!$deleted_food)
                                                            <a class="avatar avatar-xl mr-3"
                                                                href="{{ route('vendor.food.view', $detail->food['id']) }}">
                                                                <img class="img-fluid rounded onerror-image"
                                                                    src="{{ $food['image_full_url'] ?? dynamicAsset('assets/admin/img/100x100/food-default-image.png') }}"
                                                                    data-onerror-image="{{ dynamicAsset('assets/admin/img/100x100/food-default-image.png') }}"
                                                                    alt="Image Description">
                                                            </a>
                                                        @else
                                                            <div class="avatar avatar-xl mr-3">
                                                                <img class="img-fluid rounded"
                                                                    src="{{ dynamicAsset('assets/admin/img/100x100/food-default-image.png') }}"
                                                                    alt="Image Description">
                                                            </div>
                                                        @endif
                                                        <div class="media-body">
                                                            <div>
                                                                <strong class="line--limit-1">
                                                                    {{ $detail->food == null ? 'Not Found' : $detail->food['name'] }}</strong>
                                                                @if (isset($detail['variation']) ? (json_decode($detail['variation'], true) ?? []) : [])
                                                                    @foreach ((json_decode($detail['variation'], true) ?? []) as $variation)
                                                                        @if (isset($variation['name']) && isset($variation['values']))
                                                                            <span class="d-block text-capitalize">
                                                                                <strong>
                                                                                    {{ $variation['name'] }} -
                                                                                </strong>
                                                                            </span>
                                                                            @foreach ($variation['values'] as $value)
                                                                                <span class="d-block text-capitalize">
                                                                                    &nbsp; &nbsp; {{ $value['label'] }} :
                                                                                    <strong>{{ Helpers::format_currency($value['optionPrice']) }}</strong>
                                                                                </span>
                                                                            @endforeach
                                                                        @else
                                                                            @if (isset((json_decode($detail['variation'], true) ?? [])[0]))
                                                                                <strong><u>
                                                                                        {{ translate('messages.Variation') }}
                                                                                        : </u></strong>
                                                                                @foreach ((json_decode($detail['variation'], true) ?? [])[0] as $key1 => $variation)
                                                                                    <div class="font-size-sm text-body">
                                                                                        <span>{{ $key1 }} : </span>
                                                                                        <span
                                                                                            class="font-weight-bold">{{ $variation }}</span>
                                                                                    </div>
                                                                                @endforeach
                                                                            @endif
                                                                        @endif
                                                                    @endforeach
                                                                @endif

                                                                <h6>
                                                                    {{ translate('qty') }} : {{ $detail['quantity'] }}
                                                                </h6>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        @foreach ((json_decode($detail['add_ons'], true) ?? []) as $key2 => $addon)
                                                            <div class="font-size-sm text-body">
                                                                <span>{{ Str::limit($addon['name'], 20, '...') }} : </span>
                                                                <span class="font-weight-bold">
                                                                    {{ $addon['quantity'] }} x
                                                                    {{ Helpers::format_currency($addon['price']) }}
                                                                </span>

                                                            </div>
                                                            @php($total_addon_price += $addon['price'] * $addon['quantity'])
                                                        @endforeach
                                                    </div>
                                                </td>
                                                <td class="text-right">
                                                    <div>
                                                        @php($amount = $detail['price'] * $detail['quantity'])
                                                        <h5>{{ Helpers::format_currency($amount) }}</h5>
                                                    </div>
                                                </td>
                                            </tr>
                                            @php($product_price += $amount)
                                        @elseif(isset($detail->item_campaign_id))
                                            <?php
                                            $deleted_food = $detail->campaign == null ? 1 : 0;
                                            $detail->campaign = json_decode($detail->food_details, true);
                                            $campaign = \App\Models\ItemCampaign::where(['id' => $detail->campaign['id']])
                                                ->select('id', 'image')
                                                ->first();
                                            ?>

                                            <tr>
                                                <td>
                                                    <div>
                                                        {{ $key + 1 }}
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="media media--sm">
                                                        @if (!$deleted_food)
                                                            <a class="avatar avatar-xl mr-3"
                                                                href="{{ route('vendor.campaign.view', ['item', $detail->campaign['id']]) }}">
                                                                <img class="img-fluid rounded onerror-image"
                                                                    src="{{ $campaign['image_full_url'] ?? dynamicAsset('assets/admin/img/100x100/food-default-image.png') }}"
                                                                    data-onerror-image="{{ dynamicAsset('assets/admin/img/100x100/food-default-image.png') }}"
                                                                    alt="Image Description">
                                                            </a>
                                                        @else
                                                            <div class="avatar avatar-xl mr-3">
                                                                <img class="img-fluid"
                                                                    src="{{ dynamicAsset('assets/admin/img/100x100/food-default-image.png') }}"
                                                                    alt="Image Description">
                                                            </div>
                                                        @endif
                                                        <div class="media-body">
                                                            <div>
                                                                <strong class="line--limit-1">
                                                                    {{ $detail->campaign == null ? 'Not Found' : $detail->campaign['name'] }}</strong>
                                                                @if (count((json_decode($detail['variation'], true) ?? [])) > 0)
                                                                    @foreach ((json_decode($detail['variation'], true) ?? []) as $variation)
                                                                        @if (isset($variation['name']) && isset($variation['values']))
                                                                            <span class="d-block text-capitalize">
                                                                                <strong>
                                                                                    {{ $variation['name'] }} -
                                                                                </strong>
                                                                            </span>
                                                                            @foreach ($variation['values'] as $value)
                                                                                <span class="d-block text-capitalize">
                                                                                    &nbsp; &nbsp; {{ $value['label'] }} :
                                                                                    <strong>{{ Helpers::format_currency($value['optionPrice']) }}</strong>
                                                                                </span>
                                                                            @endforeach
                                                                        @else
                                                                            @if (isset((json_decode($detail['variation'], true) ?? [])[0]))
                                                                                <strong><u>
                                                                                        {{ translate('messages.Variation') }}
                                                                                        : </u></strong>
                                                                                @foreach ((json_decode($detail['variation'], true) ?? [])[0] as $key1 => $variation)
                                                                                    <div class="font-size-sm text-body">
                                                                                        <span>{{ $key1 }} : </span>
                                                                                        <span
                                                                                            class="font-weight-bold">{{ $variation }}</span>
                                                                                    </div>
                                                                                @endforeach
                                                                            @endif
                                                                            @break
                                                                        @endif
                                                                    @endforeach
                                                                @endif
                                                                <h6>
                                                                    {{ $detail['quantity'] }} x
                                                                    {{ Helpers::format_currency($detail['price']) }}
                                                                </h6>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        @foreach ((json_decode($detail['add_ons'], true) ?? []) as $key2 => $addon)
                                                            @if ($key2 == 0)
                                                                <strong><u>{{ translate('messages.addons') }} :
                                                                    </u></strong>
                                                            @endif
                                                            <div class="font-size-sm text-body">
                                                                <span class="font-weight-bold">
                                                                    {{ $addon['quantity'] }} x
                                                                    {{ Helpers::format_currency($addon['price']) }}
                                                                </span>
                                                                <span>{{ Str::limit($addon['name'], 20, '...') }} : </span>
                                                            </div>
                                                            @php($total_addon_price += $addon['price'] * $addon['quantity'])
                                                        @endforeach
                                                    </div>
                                                </td>
                                                <td class="text-right">
                                                    <div>
                                                        @php($amount = $detail['price'] * $detail['quantity'])
                                                        <h5>{{ Helpers::format_currency($amount) }}</h5>
                                                    </div>
                                                </td>
                                            </tr>
                                            @php($product_price += $amount)
                                            <!-- End Media -->
                                        @endif
                                    @empty
                                        <tr>
                                            <td>
                                                {{ translate('Food_was_deleted') }}

                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <?php
                        $total_tax_amount = $order['total_tax_amount'];
                        if ($order->tax_status == 'included') {
                            $total_tax_amount = 0;
                        }
                        ?>
                        <div class="">
                            <div class="row md-row-reverse justify-content-between align-items-center px-4 mb-3">
                                <div class="col-md-6 col-lg-5">

                                </div>
                                <div class="col-md-6 col-lg-5">
                                    <dl class="row">
                                        <dt class="col-6">{{ translate('messages.items_price') }}:
                                        </dt>
                                        <dd class="col-6 text-right">
                                            {{ Helpers::format_currency($product_price) }}</dd>
                                        <dt class="col-6">{{ translate('messages.addon_cost') }}:
                                        </dt>
                                        <dd class="col-6 text-right">
                                            {{ Helpers::format_currency($total_addon_price) }}
                                        </dd>

                                        <dt class="col-6">{{ translate('messages.subtotal') }}
                                            @if ($order->tax_status == 'included')
                                                ({{ translate('messages.TAX_Included') }})
                                            @endif
                                            :
                                        </dt>
                                        <dd class="col-6 text-right">
                                            {{ Helpers::format_currency($product_price + $total_addon_price) }}
                                        </dd>
                                        <dt class="col-6">{{ translate('messages.discount') }}:</dt>
                                        <dd class="col-6 text-right">
                                            - {{ Helpers::format_currency($order['restaurant_discount_amount']) }}
                                        </dd>
                                        <dt class="col-6">{{ translate('messages.coupon_discount') }}:
                                        </dt>
                                        <dd class="col-6 text-right">
                                            - {{ Helpers::format_currency($order['coupon_discount_amount']) }}</dd>

                                        @if ($order['ref_bonus_amount'] > 0)
                                            <dt class="col-6">{{ translate('messages.Referral_Discount') }}:</dt>
                                            <dd class="col-6 text-right">
                                                - {{ Helpers::format_currency($order['ref_bonus_amount']) }}</dd>
                                        @endif

                                        @if ($order->tax_status == 'excluded' || $order->tax_status == null)
                                            <dt class="col-6">{{ translate('messages.vat/tax') }}:</dt>
                                            <dd class="col-6 text-right">
                                                +
                                                {{ Helpers::format_currency($total_tax_amount) }}
                                            </dd>
                                        @endif

                                        <dt class="col-6">{{ translate('messages.delivery_man_tips') }}

                                        </dt>
                                        <dd class="col-6 text-right">

                                            + {{ Helpers::format_currency($order['dm_tips']) }}

                                        </dd>
                                        @if ($order['delivery_type_charge'] > 0)
                                        <dt class="col-6">
                                        {{ translate($order['delivery_type'].'_delivery') }}
                                        
                                    </dt>
                                    <dd class="col-6 text-right">
                                        {{ $order['delivery_type'] == 'slightly_delay' ? '-' : '+' }} {{ Helpers::format_currency($order['delivery_type_charge']) }}
                                    </dd>
                                    @endif
                                        <dt class="col-6">{{ translate('messages.delivery_fee') }}:
                                        </dt>
                                        <dd class="col-6 text-right">
                                            + {{ Helpers::format_currency($order['delivery_charge']) }}
                                            @if (Helpers::get_business_data('additional_charge_status') == 1 || $order['additional_charge'] > 0)
                                                @php($additional_charge_status = 1)
                                            @else
                                                @php($additional_charge_status = 0)
                                                <hr>
                                            @endif
                                        </dd>
                                        

                                        @if ($additional_charge_status)
                                            <dt class="col-6">
                                                {{ Helpers::get_business_data('additional_charge_name') ?? (Helpers::get_business_data('additional_charge_name') ?? translate('messages.additional_charge')) }}
                                                <hr>
                                            </dt>
                                            <dd class="col-6 text-right">
                                                + {{ Helpers::format_currency($order['additional_charge']) }}
                                                <hr>
                                            </dd>
                                        @endif
                                        @if ($order['extra_packaging_amount'] > 0)
                                            <dt class="col-6">{{ translate('messages.Extra_Packaging_Amount') }}:</dt>
                                            <dd class="col-6 text-right">
                                                + {{ Helpers::format_currency($order['extra_packaging_amount']) }}
                                            </dd>
                                        @endif
                                        @if ($order?->payments)
                                            @foreach ($order?->payments as $payment)
                                                @if ($payment->payment_status == 'paid')
                                                    @if ($payment->payment_method == 'cash_on_delivery')
                                                        <dt class="col-6">{{ translate('messages.Paid_with_Cash') }}
                                                            ({{ translate('COD') }}) :</dt>
                                                    @else
                                                        <dt class="col-6">{{ translate('messages.Paid_by') }}
                                                            {{ translate($payment->payment_method) }} :</dt>
                                                    @endif
                                                @else
                                                    <dt class="col-6">{{ translate('Due_Amount') }}
                                                        ({{ $payment->payment_method == 'cash_on_delivery' ? translate('messages.COD') : translate($payment->payment_method) }})
                                                        :</dt>
                                                @endif
                                                <dd class="col-6 text-right">
                                                    {{ Helpers::format_currency($payment->amount) }}
                                                </dd>
                                            @endforeach
                                        @endif
                                    </dl>
                                    <!-- End Row -->
                                </div>
                            </div>

                            <div id="stickyDiv"
                                class="bg-white pb-1 mt-n25 py-2 mb-3 {{ $order->bring_change_amount > 0 ? 'position-sticky bottom-0' : '' }}">
                                <div class="row  md-row-reverse justify-content-between align-items-center mb-3 px-4">
                                    <div class="col-md-6 col-lg-5">
                                        @if ($order->bring_change_amount > 0)
                                            <div class="alert fs-12 p-2 alert-primary-light text-dark mb-0 mt-3 mt-md-0 row"
                                                role="alert">
                                                {{ translate('Please instruct the delivery man to collect ' . Helpers::format_currency($order->bring_change_amount) . ' in change upon delivery') }}
                                            </div>
                                        @endif
                                    </div>
                                    <div class="col-md-6 col-lg-5">
                                        <dl class="row align-items-center font-weight-bold text--title">
                                            <dd class="col-6 mb-0">{{ translate('messages.total') }}:</dd>
                                            <dt class="col-6 text-right">
                                                {{ Helpers::format_currency($order['order_amount']) }}
                                            </dt>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- End Row -->
                    </div>
                    <!-- End Body -->
                </div>
                <!-- End Card -->
            </div>
            <div class="col-lg-4 order-print-area-right">
                <!-- Card -->
                @if ($order['order_status'] != 'delivered')
                    <div class="card mb-2">
                        <!-- Header -->
                        <div class="card-header border-0 py-0">
                            <h4 class="card-header-title border-bottom py-3 m-0  w-100 text-center">
                                {{ translate('Order Setup') }}</h4>
                        </div>
                        <!-- End Header -->

                        <!-- Body -->


                        <div class="card-body">
                            <!-- Unfold -->
                            @php($order_delivery_verification = (bool) Helpers::get_business_data('order_delivery_verification'))
                            {{-- 哪吒 B方案: 顾客「取消申请」待裁决 — 商家同意取消 / 拒绝继续履约 --}}
                            @if ($order->nezha_cancel_request === 'requested' && in_array($order->order_status, ['confirmed', 'processing']))
                                <div class="mb-3 p-3" style="background:#FFF3F5;border:1px solid #F3C9D2;border-radius:12px;">
                                    <div style="font-weight:800;color:#7c1228;margin-bottom:4px;">⚠️ 顾客申请取消本单</div>
                                    <div style="font-size:13px;color:#555;line-height:1.7;margin-bottom:4px;">顾客理由：{{ $order->nezha_cancel_request_reason ?: '（未填写）' }}</div>
                                    @if ($order->payment_method == 'offline_payment')
                                        <div style="font-size:12px;color:#a98b54;line-height:1.6;margin-bottom:8px;">提示：若顾客已付款，同意取消后请到「订单 → 待退款」按原路退还顾客（平台不经手此款）。</div>
                                    @endif
                                    <div class="d-flex gap-2 flex-wrap">
                                        <form action="{{ route('vendor.order.cancel-request-decision', ['id' => $order['id']]) }}" method="post" onsubmit="return confirm('确认同意取消本单？若顾客已付款，需你按原路退还。');">
                                            @csrf
                                            @method('put')
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn btn-sm btn-success" style="border-radius:8px;">同意取消</button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:8px;" data-toggle="modal" data-target="#nzRejectCancelReq-{{ $order['id'] }}">拒绝（继续履约）</button>
                                    </div>
                                </div>
                                {{-- 拒绝取消申请弹窗(必填原因) --}}
                                <div class="modal fade" id="nzRejectCancelReq-{{ $order['id'] }}" tabindex="-1" role="dialog" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered" role="document">
                                        <div class="modal-content">
                                            <form action="{{ route('vendor.order.cancel-request-decision', ['id' => $order['id']]) }}" method="post">
                                                @csrf
                                                @method('put')
                                                <input type="hidden" name="action" value="reject">
                                                <div class="modal-header"><h5 class="modal-title">拒绝取消申请</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                                                <div class="modal-body">
                                                    <label style="font-size:13px;color:#555;">请填写拒绝原因（会通知顾客）</label>
                                                    <textarea name="reason" required maxlength="500" rows="3" class="form-control" placeholder="例：已开始备餐，无法取消；如有问题请电话联系。"></textarea>
                                                </div>
                                                <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">关闭</button><button type="submit" class="btn btn--danger btn-sm">确认拒绝</button></div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @elseif ($order->nezha_cancel_request === 'rejected')
                                <div class="mb-2 p-2" style="background:#F6F7F9;border:1px solid #E3E6EA;border-radius:8px;font-size:12px;color:#6B7280;line-height:1.6;">本单曾有顾客取消申请，你已拒绝（{{ $order->nezha_cancel_response_note }}），订单继续履约。</div>
                            @endif
                            <div class="order-btn-wraper d-flex justify-content-center flex-wrap gap-2 align-items-center">
                                @if ($order['order_status'] == 'pending')

                                    @if (config('canceled_by_restaurant'))
                                        <a
                                            class="btn btn-sm btn-outline-danger btn--danger cancelled-status">{{ translate('Cancel Order') }}</a>
                                    @endif
                                    <a class="btn btn-sm btn--primary order-status-change-alert"
                                        data-url="{{ route('vendor.order.status', ['id' => $order['id'], 'order_status' => 'confirmed']) }}"
                                        data-message="{{ translate('Change status to confirmed ?') }}"
                                        href="javascript:">
                                        {{ translate('Confirm Order') }}
                                    </a>
                                @elseif ($order['order_status'] == 'confirmed' || $order['order_status'] == 'accepted')
                                    <a class="btn btn-sm btn--primary order-status-change-alert"
                                        data-url="{{ route('vendor.order.status', ['id' => $order['id'], 'order_status' => 'processing']) }}"
                                        data-message="{{ translate('Change status to cooking ?') }}"
                                        data-verification="false" data-processing-time="{{ $max_processing_time }}"
                                        href="javascript:">{{ translate('messages.Proceed_for_cooking') }}</a>
                                @elseif ($order['order_status'] == 'processing' && in_array($order['order_type'], ['take_away', 'dine_in']))
                                    <a class="btn btn-sm btn--primary order-status-change-alert"
                                        data-url="{{ route('vendor.order.status', ['id' => $order['id'], 'order_status' => 'handover']) }}"
                                        data-message="{{ translate('Change status to ready for handover ?') }}"
                                        href="javascript:">{{ translate('messages.make_ready_for_handover') }}</a>
                                @elseif (
                                    $order['order_status'] == 'handover' &&
                                        (in_array($order['order_type'], ['dine_in', 'take_away']) || $restaurant->sub_self_delivery == 1))
                                    <a class="btn btn-sm btn--primary order-status-change-alert"
                                        data-url="{{ route('vendor.order.status', ['id' => $order['id'], 'order_status' => 'delivered']) }}"
                                        data-message="{{ translate('Change status to delivered (payment status will be paid if not) ?') }}"
                                        data-verification="{{ $order_delivery_verification ? 'true' : 'false' }}"
                                        href="javascript:">{{ $order?->order_type == 'dine_in' ? translate('messages.Make_Completed') : translate('messages.make_delivered') }}</a>
                                @endif
                            </div>

                            {{-- 哪吒 B方案: 商家「无法接单 / 拒单」(仅 pending/confirmed, 必填原因) --}}
                            @if (in_array($order->order_status, ['pending', 'confirmed']))
                                <div class="mt-2 text-center">
                                    <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:8px;" data-toggle="modal" data-target="#nzRejectOrder-{{ $order['id'] }}">无法接单 / 拒单</button>
                                    <div style="font-size:11px;color:#999;margin-top:4px;line-height:1.6;">缺货 / 打烊 / 接不了单时使用。拒单会取消订单并通知顾客；已付款的请到「待退款」原路退还。</div>
                                </div>
                                <div class="modal fade" id="nzRejectOrder-{{ $order['id'] }}" tabindex="-1" role="dialog" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered" role="document">
                                        <div class="modal-content">
                                            <form action="{{ route('vendor.order.reject', ['id' => $order['id']]) }}" method="post" onsubmit="return confirm('确认拒单？订单将取消并通知顾客。若顾客已付款，需你按原路退还。');">
                                                @csrf
                                                @method('put')
                                                <div class="modal-header"><h5 class="modal-title">无法接单 / 拒单</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                                                <div class="modal-body">
                                                    <label style="font-size:13px;color:#555;">请填写拒单原因（会通知顾客）</label>
                                                    <textarea name="reason" required maxlength="500" rows="3" class="form-control" placeholder="例：该商品已售罄；今日已打烊；订单超出配送范围。"></textarea>
                                                </div>
                                                <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">关闭</button><button type="submit" class="btn btn--danger btn-sm">确认拒单</button></div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            {{-- 哪吒 B方案: 配送单呼叫 Yandex 后填写追踪链接, 顾客端可实时查看 --}}
                            @if ($order->order_type == 'delivery' && in_array($order->order_status, ['processing', 'handover', 'picked_up']))
                                @php($yAddr = $order->delivery_address ? json_decode($order->delivery_address, true) : [])
                                @php($yLat = $yAddr['latitude'] ?? null)
                                @php($yLng = $yAddr['longitude'] ?? null)
                                @php($yText = $yAddr['address'] ?? '')
                                @if (in_array($order->order_status, ['processing', 'handover']))
                                    <div class="mt-3 mb-1 p-2" style="background:#EEF6FF;border:1px solid #CFE3FF;border-radius:10px;font-size:12px;color:#1A1A1A;line-height:1.7;">
                                        <span style="font-weight:700;">🛵 什么时候叫 Yandex？</span> 餐快做好时，用下方「顾客配送位置」把坐标粘到 Yandex Go 叫车；叫到车后点下面「已叫车，标记为配送中」（贴上追踪链接，顾客还能看骑手实时位置）。
                                    </div>
                                    <form action="{{ route('vendor.order.mark-dispatched', ['id' => $order['id']]) }}" method="post" class="mt-3 mb-1">
                                        @csrf
                                        @method('put')
                                        <button type="submit" class="btn btn--primary w-100" style="font-weight:700;border-radius:10px;">✅ 已叫车，标记为「配送中」</button>
                                        <div style="font-size:12px;color:#6B7280;margin-top:4px;line-height:1.6;">点一下，顾客立刻看到“配送中”。下面贴 Yandex 链接是可选项——贴了顾客能实时看骑手位置。</div>
                                    </form>
                                @endif
                                @if ($order->delivery_link_reminded_at && !$order->yandex_tracking_url)
                                    <div class="mt-2 p-2" style="background:#FFF3F5;border:1px solid #F3C9D2;border-radius:8px;font-size:13px;color:#7c1228;line-height:1.6;">🔔 顾客已请求查看配送进度（{{ \Carbon\Carbon::parse($order->delivery_link_reminded_at)->timezone('Asia/Yerevan')->format('H:i') }}）——请在 Yandex Go 点「分享」复制追踪链接，贴到下方。</div>
                                @endif
                                {{-- 哪吒 B方案: 顾客配送定位助手 — 复制地址/坐标粘进 Yandex Go, 或一键在 Yandex 地图确认位置 --}}
                                <div class="mt-3 p-3" style="background:#F0F7FF;border:1px solid #CFE3FF;border-radius:12px;">
                                    <div style="font-weight:700;margin-bottom:6px;color:#1A1A1A;">顾客配送位置（叫 Yandex 用）</div>
                                    <div style="font-size:12px;color:#6B7280;margin-bottom:8px;line-height:1.6;">复制下面的“坐标”粘到 Yandex Go 目的地框，认准带 Yerevan / 亚美尼亚 的那条（一般在最顶）——⚠️ 列表里可能混入坐标顺序写反、落到俄罗斯/土耳其的诱饵，别选错。也可点下方“在 Yandex 看顾客位置 / 路线”先核对。配送定位以图钉为准。</div>
                                    @if ($yText)
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <input type="text" readonly value="{{ $yText }}" class="form-control form-control-sm" style="border-radius:8px;">
                                            <button type="button" class="btn btn-sm btn-outline-primary" style="white-space:nowrap;" onclick="this.previousElementSibling.select();document.execCommand('copy');this.innerText='已复制';">复制地址</button>
                                        </div>
                                    @endif
                                    @if ($yLat && $yLng)
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <input type="text" readonly value="{{ $yLat }}, {{ $yLng }}" class="form-control form-control-sm" style="border-radius:8px;">
                                            <button type="button" class="btn btn-sm btn-outline-primary" style="white-space:nowrap;" onclick="this.previousElementSibling.select();document.execCommand('copy');this.innerText='已复制';">复制坐标</button>
                                        </div>
                                        <a href="https://yandex.com/maps/?ll={{ $yLng }},{{ $yLat }}&z=17&pt={{ $yLng }},{{ $yLat }},pm2rdm&l=map" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn--primary">在 Yandex 看顾客位置</a>
                                        @if ($order->restaurant && $order->restaurant->latitude && $order->restaurant->longitude)
                                            {{-- 哪吒: 餐厅->顾客 驾车路线(含距离/ETA, Yandex 可发到手机); rtext 坐标顺序=lat,lon (与点位 ll/pt 的 lon,lat 相反, 已实测) --}}
                                            <a href="https://yandex.com/maps/?rtext={{ $order->restaurant->latitude }},{{ $order->restaurant->longitude }}~{{ $yLat }},{{ $yLng }}&rtt=auto" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary">餐厅→顾客 路线（含距离）</a>
                                        @endif
                                    @else
                                        <div style="font-size:12px;color:#C4193E;">该订单未带坐标（顾客下单未用地图定位），请按地址或电话与顾客确认位置。</div>
                                    @endif
                                </div>
                                <div class="mt-3 p-3" style="background:#FFF7E6;border:1px solid #F3D9A8;border-radius:12px;">
                                    <div style="font-weight:700;margin-bottom:6px;color:#1A1A1A;">Yandex 配送追踪链接</div>
                                    <div style="font-size:12px;color:#6B7280;margin-bottom:8px;line-height:1.6;">
                                        叫车后，在 Yandex Go App 点“分享 / Поделиться”复制追踪链接，粘贴到下方保存。保存后订单将更新为“配送中”，顾客可点击实时查看骑手位置。
                                    </div>
                                    <form action="{{ route('vendor.order.set-yandex-delivery', ['id' => $order['id']]) }}" method="post">
                                        @csrf
                                        @method('put')
                                        <input type="url" name="yandex_tracking_url" required
                                            value="{{ $order->yandex_tracking_url }}"
                                            placeholder="https://dostavka.yandex.ru/route/#..."
                                            class="form-control form-control-sm mb-2" style="border-radius:8px;">
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <button type="submit" class="btn btn-sm btn--primary">
                                                {{ $order->yandex_tracking_url ? '更新配送链接' : '保存并标记为配送中' }}
                                            </button>
                                            @if ($order->yandex_tracking_url)
                                                <a href="{{ $order->yandex_tracking_url }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary">预览顾客看到的追踪页</a>
                                            @endif
                                        </div>
                                        <div style="font-size:12px;color:#9CA3AF;margin-top:6px;">仅接受 Yandex 官方链接（yandex.ru / yandex.com）。配送完成后该链接可能失效，属正常现象。</div>
                                    </form>
                                </div>
                            @endif

                            @if ($order->order_status == 'canceled')
                                <ul class="delivery--information-single mt-3">
                                    <li>
                                        <span class=" badge badge-soft-danger ">
                                            {{ translate('messages.Cancel_Reason') }} :</span>
                                        <span class="info"> {{ $order->cancellation_reason }} </span>
                                    </li>

                                    <li>
                                        <span class="name">{{ translate('Cancel_Note') }} </span>
                                        <span class="info">
                                            {{ $order->cancellation_note ?? translate('messages.N/A') }} </span>
                                    </li>
                                    <li>
                                        <span class="name">{{ translate('Canceled_By') }} </span>
                                        <span class="info"> {{ translate($order->canceled_by) }} </span>
                                    </li>
                                    @if ($order->payment_status == 'paid' || $order->payment_status == 'partially_paid')
                                        @if ($order?->payments)
                                            @php($pay_infos = $order->payments()->where('payment_status', 'paid')->get())
                                            @foreach ($pay_infos as $pay_info)
                                                <li>
                                                    <span class="name">{{ translate('Amount_paid_by') }}
                                                        {{ translate($pay_info->payment_method) }} </span>
                                                    <span class="info">
                                                        {{ Helpers::format_currency($pay_info->amount) }} </span>
                                                </li>
                                            @endforeach
                                        @else
                                            <li>
                                                <span class="name">{{ translate('Amount_paid_by') }}
                                                    {{ translate($order->payment_method) }} </span>
                                                <span class="info ">
                                                    {{ Helpers::format_currency($order->order_amount) }} </span>
                                            </li>
                                        @endif
                                    @endif

                                    @if ($order->payment_status == 'paid' || $order->payment_status == 'partially_paid')
                                        @if ($order?->payments)
                                            @php($amount = $order->payments()->where('payment_status', 'paid')->sum('amount'))
                                            <li>
                                                <span class="name">{{ translate('Amount_Returned_To_Wallet') }} </span>
                                                <span class="info"> {{ Helpers::format_currency($amount) }} </span>
                                            </li>
                                        @else
                                            <li>
                                                <span class="name">{{ translate('Amount_Returned_To_Wallet') }} </span>
                                                <span class="info">
                                                    {{ Helpers::format_currency($order->order_amount) }} </span>
                                            </li>
                                        @endif
                                    @endif
                                </ul>
                                <hr class="w-100">
                            @endif


                            <!-- End Unfold -->

                        </div>

                    </div>

                @endif
                <!-- Schedule Date -->

                @if ($order->scheduled)
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center gap-1">
                                <div>
                                    <label
                                        class="form-label mb-0 fs-14 font-bold">{{ translate('Schedule Date') }}</label>
                                    <div class="m-0 font-normal text-title">
                                        {{ Helpers::time_date_format($order->schedule_at) }}</div>
                                </div>
                                @if ($can_restaurant_edit_order == 1 && $restaurant?->restaurant_config?->can_edit_order ==1 && in_array($order->order_status, ['pending', 'confirmed', 'processing', 'accepted']) )

                                <div class="position-relative">
                                    <div id="customTrigger">
                                        <i class="tio-calendar-month -absolute-on-right text-gray1"></i>
                                    </div>
                                    <input id="hiddenInput" type="text"
                                        value="{{ \Carbon\Carbon::parse($order->schedule_at)->format('m/d/Y h:i A') }}"
                                        class="border-0 p-0 bg-transparent position-absolute right-0 bottom-0"
                                        name="schedule_at" autocomplete="off">
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif


                @if ($order->order_type == 'dine_in')
                    <form action="{{ route('vendor.order.add_dine_in_table_number', [$order['id']]) }}" method="post">
                        @method('PUT')
                        @csrf
                        <div class="card">
                            <div class="card-body">
                                <div class="form-group mb-20">
                                    <label class="form-label">{{ translate('Table_Number') }}</label>
                                    <div class="d-flex">
                                        <input type="text" @readonly(in_array($order['order_status'], ['failed', 'delivered', 'refund_requested', 'canceled', 'refunded', 'refund_request_canceled'])) maxlength="20"
                                            value="{{ $order?->OrderReference?->table_number }}" name="table_number"
                                            class="form-control" placeholder="Ex: 10">
                                    </div>
                                </div>
                                <div class="form-group mb-0">
                                    <label class="form-label">{{ translate('Token_Number') }}</label>
                                    <div class="d-flex">
                                        <input type="text" @readonly(in_array($order['order_status'], ['failed', 'delivered', 'refund_requested', 'canceled', 'refunded', 'refund_request_canceled'])) maxlength="20"
                                            value="{{ $order?->OrderReference?->token_number }}" name="token_number"
                                            class="form-control" placeholder="Ex: 32">
                                    </div>
                                </div>
                                @if (
                                    !in_array($order['order_status'], [
                                        'failed',
                                        'delivered',
                                        'refund_requested',
                                        'canceled',
                                        'refunded',
                                        'refund_request_canceled',
                                    ]))
                                    <div class="w-100 text-center mt-4">
                                        <button type="submit"
                                            class="btn w-100 btn-primary font-regular">{{ translate('messages.Save') }}
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </form>
                @endif

                @if (!in_array($order['order_type'], ['dine_in', 'take_away']))
                    <div class="card mb-2 mt-2">
                        <div class="card-header border-0 text-center pb-0">
                            @if ($order->delivery_man)
                                <h5 class="card-title mb-3 w-100 justify-content-between">
                                    <div>
                                        <span class="card-header-icon">
                                            <i class="tio-user"></i>
                                        </span>
                                        <span>
                                            {{ translate('Delivery Man Information') }}
                                        </span>
                                    </div>
                                    @if (
                                        !in_array($order['order_status'], [
                                            'handover',
                                            'delivered',
                                            'refund_requested',
                                            'canceled',
                                            'refunded',
                                            'refund_request_canceled',
                                        ]) &&
                                            ((isset($order->restaurant) &&
                                                ($order->restaurant->restaurant_model == 'commission' && $order->restaurant->self_delivery_system)) ||
                                                ($order->restaurant->restaurant_model == 'subscription' &&
                                                    isset($order->restaurant->restaurant_sub) &&
                                                    $order->restaurant->restaurant_sub->self_delivery == 1)))
                                        <span class="ml-auto text--primary position-relative pl-2 cursor-pointer"
                                            data-toggle="modal" data-target="#myModal">
                                            {{ translate('messages.change') }}
                                        </span>
                                    @endif
                                </h5>
                        </div>

                        <div class="card-body">
                            <div class="media align-items-center deco-none customer--information-single ml-3 border-0 text-center pb-0"
                                href="javascript:">
                                <div class="avatar avatar-circle">
                                    <img class="avatar-img  initial-81 onerror-image"
                                        data-onerror-image="{{ dynamicAsset('assets/admin/img/160x160/img3.jpg') }}"
                                        src="{{ $order->delivery_man?->image_full_url ?? dynamicAsset('assets/admin/img/160x160/img3.jpg') }}"
                                        alt="Image Description">
                                </div>
                                <div class="media-body">
                                    <span class="fz--14px text--title font-semibold text-hover-primary d-block">
                                        {{ $order->delivery_man['f_name'] . ' ' . $order->delivery_man['l_name'] }}
                                    </span>
                                    <span class="d-block">
                                        <strong class="text--title font-semibold">
                                            {{ $order->delivery_man->orders_count }}
                                        </strong>
                                        {{ translate('messages.orders') }}
                                    </span>
                                    <span class="d-block">
                                        <a class="text--title font-semibold"
                                            href="tel:{{ $order->delivery_man['phone'] }}">
                                            <strong>
                                                {{ $order->delivery_man['email'] }}
                                            </strong>
                                        </a>
                                    </span>
                                    <span class="d-block">
                                        <strong class="text--title font-semibold">
                                        </strong>
                                        {{ $order->delivery_man['phone'] }}
                                    </span>
                                </div>
                            </div>

                            @if (!in_array($order['order_type'], ['dine_in', 'take_away']))
                                <hr>
                                @php($address = $order->dm_last_location)
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5>{{ translate('messages.last_location') }}</h5>
                                </div>
                                @if (isset($address))
                                    <span class="d-block mb-2">
                                        <a target="_blank"
                                            href="http://maps.google.com/maps?z=12&t=m&q=loc:{{ $address['latitude'] }}+{{ $address['longitude'] }}">
                                            <i class="tio-poi"></i> {{ $address['location'] }}<br>
                                        </a>
                                    </span>
                                @else
                                    <span class="d-block text-lowercase qcont mb-2">
                                        {{ translate('messages.location_not_found') }}
                                    </span>
                                @endif
                            @endif
                        @else
                            @if (
                                !$order->delivery_man &&
                                    !in_array($order['order_status'], [
                                        'handover',
                                        'delivered',
                                        'take_away',
                                        'refund_requested',
                                        'canceled',
                                        'refunded',
                                        'refund_request_canceled',
                                    ]) &&
                                    ((isset($order->restaurant) &&
                                        ($order->restaurant->restaurant_model == 'commission' && $order->restaurant->self_delivery_system)) ||
                                        ($order->restaurant->restaurant_model == 'subscription' &&
                                            isset($order->restaurant->restaurant_sub) &&
                                            $order->restaurant->restaurant_sub->self_delivery == 1)))
                                <div class="w-100 text-center mr-2 mt-4 mb-4">
                                    <button type="button" class="btn w-100 btn-primary font-regular" data-toggle="modal"
                                        data-target="#myModal" data-lat='21.03' data-lng='105.85'>
                                        <i class="tio-bike"></i> {{ translate('messages.assign_delivery_man') }}
                                    </button>
                                </div>
                            @endif
                @endif
            </div>
        </div>
        @endif


        @if ($order->order_type != 'dine_in')
            <!-- order proof -->
            <div class="card mb-2 mt-2">
                <div class="card-header border-0 text-center pb-0">
                    <h5 class="card-title mb-3">
                        <span class="card-header-icon">
                            <i class="tio-user"></i>
                        </span>
                        <span>
                            {{ translate('messages.Delivery_Proof') }}
                        </span>
                    </h5>
                    @if ($restaurant->sub_self_delivery == 1)
                        <button class="btn btn-outline-primary btn-sm" data-toggle="modal"
                            data-target=".order-proof-modal"> {{ translate('messages.add') }}</button>
                    @endif
                </div>
                @php($data = isset($order->order_proof) ? json_decode($order->order_proof, true) : 0)
                <div class="card-body pt-2">
                    @if ($data)
                        <label class="input-label" for="order_proof">{{ translate('messages.image') }} : </label>
                        <div class="row g-3">
                            @foreach ($data as $key => $img)
                                @php($img = is_array($img) ? $img : ['img' => $img, 'storage' => 'public'])
                                <div class="col-3">
                                    <img class="img__aspect-1 rounded border w-100 onerror-image" data-toggle="modal"
                                        data-target="#imagemodal{{ $key }}"
                                        data-onerror-image="{{ dynamicAsset('assets/admin/img/160x160/img2.jpg') }}"
                                        src="{{ Helpers::get_full_url('order', $img['img'], $img['storage']) }}">
                                </div>
                                <div class="modal fade" id="imagemodal{{ $key }}" tabindex="-1"
                                    role="dialog" aria-labelledby="order_proof_{{ $key }}"
                                    aria-hidden="true">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h4 class="modal-title" id="order_proof_{{ $key }}">
                                                    {{ translate('order_proof_image') }}</h4>
                                                <button type="button" class="close" data-dismiss="modal"><span
                                                        aria-hidden="true">&times;</span><span
                                                        class="sr-only">{{ translate('messages.cancel') }}</span></button>
                                            </div>
                                            <div class="modal-body">
                                                <img src="{{ Helpers::get_full_url('order', $img['img'], $img['storage']) }}"
                                                    class="initial--22 w-100">
                                            </div>
                                            @php($storage = $img['storage'] ?? 'public')
                                            @php($file = $storage == 's3' ? base64_encode('order/' . $img['img']) : base64_encode('public/order/' . $img['img']))
                                            <div class="modal-footer">
                                                <a class="btn btn-primary"
                                                    href="{{ route('vendor.file-manager.download', [$file, $storage]) }}"><i
                                                        class="tio-download"></i>
                                                    {{ translate('messages.download') }}
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif

        <div class="card mt-2">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <span class="card-header-icon">
                        <i class="tio-user"></i>
                    </span>
                    <span>
                        {{ translate('messages.customer_info') }}
                    </span>
                </h5>
                @if ($order->customer && $order->is_guest == 0)
                    <div class="media align-items-center deco-none customer--information-single" href="javascript:">
                        <div class="avatar avatar-circle">
                            <img class="avatar-img  initial-81 onerror-image "
                                data-onerror-image="{{ dynamicAsset('assets/admin/img/160x160/img1.jpg') }}"
                                src="{{ $order->customer?->image_full_url ?? dynamicAsset('assets/admin/img/160x160/img1.jpg') }}"
                                alt="Image Description">
                        </div>
                        <div class="media-body">
                            <span class="fz--14px text--title font-semibold text-hover-primary d-block">
                                {{ $order->customer['f_name'] . ' ' . $order->customer['l_name'] }}
                            </span>
                            <span class="d-block">
                                <strong class="text--title font-semibold">
                                    {{ $order->customer->orders_count }}
                                </strong>
                                {{ translate('Orders') }}
                            </span>
                            <span class="d-block">
                                <strong class="text--title font-semibold">
                                    {{ \App\CentralLogics\Helpers::mask_phone($order->customer['phone']) }}
                                </strong>
                            </span>
                            <span class="d-block">
                                <strong class="text--title font-semibold">
                                </strong>
                                {{ \App\CentralLogics\Helpers::mask_email($order->customer['email']) }}
                            </span>
                        </div>
                    </div>
                @elseif($order->is_guest)
                    <span class="badge badge-soft-success py-2 d-block qcont">
                        {{ translate('Guest_user') }}
                    </span>
                @else
                    {{ translate('messages.customer_not_found') }}
                @endif
            </div>
        </div>

        @if ($order->delivery_address)

            <div class="card mt-2">
                <div class="card-body">
                    @php($address = json_decode($order->delivery_address, true))
                       <div class="d-flex justify-content-between align-items-center">
                                                    <h5 class="card-title">
                                                        <span class="card-header-icon">
                                                            <i class="tio-user"></i>
                                                        </span>
                                                        <span>{{ $order->order_type == 'dine_in' ? translate('Order_Info') : translate('delivery_info') }}</span>
                                                    </h5>

                                                    @if (isset($address) && isset($order->restaurant) && !in_array($order['order_type'],['dine_in','take_away']) && $can_restaurant_edit_order == 1 && $restaurant?->restaurant_config?->can_edit_order ==1 && in_array($order->order_status, ['pending', 'confirmed', 'processing', 'accepted']) )
                                                        <a class="link" data-toggle="modal" data-target="#shipping-address-modal" href="javascript:"><i class="tio-edit"></i></a>
                                                    @endif
                                                </div>
                    @if (isset($address))
                        <span class="delivery--information-single mt-3">
                            <span class="name">{{ translate('messages.name') }}:</span>
                            <span class="info">{{ $address['contact_person_name'] }}</span>
                            <span class="name">{{ translate('messages.contact') }}:</span>
                            <a class="info" href="tel:{{ $address['contact_person_number'] }}">
                                {{ $address['contact_person_number'] }}
                            </a>

                            @if (!in_array($order->order_type, ['dine_in', 'take_away']))
                            @if (isset($address['road']))
                                <span class="name">{{ translate('Road') }}:</span>
                                <span class="info">{{ isset($address['road']) ? $address['road'] : '' }}</span>
                            @endif
                            @if (isset($address['house']))
                                <span class="name">{{ translate('House') }}:</span>
                                <span class="info">{{ isset($address['house']) ? $address['house'] : '' }}</span>
                            @endif
                            @if (isset($address['floor']))
                                <span class="name">{{ translate('Floor') }}:</span>
                                <span class="info">{{ isset($address['floor']) ? $address['floor'] : '' }}</span>
                            @endif
                                <span class="mt-2 d-flex w-100">
                                    <span><i class="tio-poi text--title"></i></span>
                                    @if (!in_array($order['order_type'], ['dine_in', 'take_away']) && isset($address['address']))
                                        @if (isset($address['latitude']) && isset($address['longitude']))
                                            <a target="_blank" class="info pl-2"
                                                href="http://maps.google.com/maps?z=12&t=m&q=loc:{{ $address['latitude'] }}+{{ $address['longitude'] }}">
                                                {{ $address['address'] }}
                                            </a>
                                        @else
                                            <span class="info pl-2">
                                                {{ $address['address'] }}
                                            </span>
                                        @endif
                                    @endif
                                </span>
                            @endif
                        </span>
                    @endif
                </div>
            </div>
        @endif
    </div>
    </div>
    <!-- End Row -->
    </div>



    <!-- Modal -->
    <div id="shipping-address-modal" class="modal fade" tabindex="-1" role="dialog"
        aria-labelledby="exampleModalTopCoverTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <!-- Header -->
                <div class="modal-top-cover bg-dark text-center">
                    <figure class="position-absolute right-0 bottom-0 left-0 mb-n-1">
                        <svg preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg" x="0px" y="0px"
                            viewBox="0 0 1920 100.1">
                            <path fill="#fff" d="M0,0c0,0,934.4,93.4,1920,0v100.1H0L0,0z" />
                        </svg>
                    </figure>

                    <div class="modal-close">
                        <button type="button" class="btn btn-icon btn-sm btn-ghost-light" data-dismiss="modal"
                            aria-label="Close">
                            <svg width="16" height="16" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                                <path fill="currentColor"
                                    d="M11.5,9.5l5-5c0.2-0.2,0.2-0.6-0.1-0.9l-1-1c-0.3-0.3-0.7-0.3-0.9-0.1l-5,5l-5-5C4.3,2.3,3.9,2.4,3.6,2.6l-1,1 C2.4,3.9,2.3,4.3,2.5,4.5l5,5l-5,5c-0.2,0.2-0.2,0.6,0.1,0.9l1,1c0.3,0.3,0.7,0.3,0.9,0.1l5-5l5,5c0.2,0.2,0.6,0.2,0.9-0.1l1-1 c0.3-0.3,0.3-0.7,0.1-0.9L11.5,9.5z" />
                            </svg>
                        </button>
                    </div>
                </div>
                <!-- End Header -->

                <div class="modal-top-cover-icon">
                    <span class="icon icon-lg icon-light icon-circle icon-centered shadow-soft">
                        <i class="tio-location-search"></i>
                    </span>
                </div>

                @if (isset($address))
                    <form action="{{ route('vendor.order.update-shipping', [$order['id']]) }}" method="post">
                        @csrf
                        <div class="modal-body">
                            <div class="row mb-3">
                                <label for="requiredLabel" class="col-md-2 col-form-label input-label text-md-right">
                                    {{ translate('messages.type') }}
                                </label>
                                <div class="col-md-10 js-form-message">
                                    <input type="text" class="form-control" name="address_type"
                                        value="{{ isset($address['address_type']) ? $address['address_type'] : '' }}" required>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <label for="requiredLabel" class="col-md-2 col-form-label input-label text-md-right">
                                    {{ translate('messages.contact') }}
                                </label>
                                <div class="col-md-10 js-form-message">
                                    <input type="text" class="form-control" name="contact_person_number"
                                        value="{{ $address['contact_person_number'] }}" required>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <label for="requiredLabel" class="col-md-2 col-form-label input-label text-md-right">
                                    {{ translate('messages.name') }}
                                </label>
                                <div class="col-md-10 js-form-message">
                                    <input type="text" class="form-control" name="contact_person_name"
                                        value="{{ $address['contact_person_name'] }}" required>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <label for="requiredLabel" class="col-md-2 col-form-label input-label text-md-right">
                                    {{ translate('House') }}
                                </label>
                                <div class="col-md-10 js-form-message">
                                    <input type="text" class="form-control" name="house"
                                        value="{{ isset($address['house']) ? $address['house'] : '' }}">
                                </div>
                            </div>
                            <div class="row mb-3">
                                <label for="requiredLabel" class="col-md-2 col-form-label input-label text-md-right">
                                    {{ translate('Floor') }}
                                </label>
                                <div class="col-md-10 js-form-message">
                                    <input type="text" class="form-control" name="floor"
                                        value="{{ isset($address['floor']) ? $address['floor'] : '' }}" >
                                </div>
                            </div>
                            <div class="row mb-3">
                                <label for="requiredLabel" class="col-md-2 col-form-label input-label text-md-right">
                                    {{ translate('Road') }}
                                </label>
                                <div class="col-md-10 js-form-message">
                                    <input type="text" class="form-control" name="road"
                                        value="{{ isset($address['road']) ? $address['road'] : '' }}" >
                                </div>
                            </div>
                            <div class="row mb-3">
                                <label for="requiredLabel" class="col-md-2 col-form-label input-label text-md-right">
                                    {{ translate('address') }}
                                </label>
                                <div class="col-md-10 js-form-message">
                                    <input type="text" class="form-control" id="address" name="address"
                                        value="{{ $address['address'] }}">
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="requiredLabel" class="col-md-2 col-form-label input-label text-md-right">
                                    {{ translate('latitude') }}
                                </label>
                                <div class="col-md-4 js-form-message">
                                    <input type="text" class="form-control" readonly name="latitude" id="latitude"
                                        value="{{ $address['latitude'] }}">
                                </div>
                                <label for="requiredLabel" class="col-md-2 col-form-label input-label text-md-right">
                                    {{ translate('messages.longitude') }}
                                </label>
                                <div class="col-md-4 js-form-message">
                                    <input type="text" class="form-control" readonly name="longitude" id="longitude"
                                        value="{{ $address['longitude'] }}">
                                </div>
                            </div>
                            <div class="mb-3">
                                <input id="pac-input" class="controls rounded initial-8"
                                title="{{ translate('messages.search_your_location_here') }}" type="text"
                                placeholder="{{ translate('messages.search_here') }}" />
                            <div class="mb-2 h-200px" id="map"></div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-white"
                                data-dismiss="modal">{{ translate('messages.close') }}</button>
                            <button type="submit" class="btn btn-primary">{{ translate('messages.save_changes') }}</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
    <!-- End Modal -->

    <!-- End Content -->
    <!-- Modal -->
    <div class="modal fade order-proof-modal" tabindex="-1" role="dialog" aria-labelledby="mySmallModalLabel"
        aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title h4" id="mySmallModalLabel">{{ translate('messages.add_delivery_proof') }}
                    </h5>
                    <button type="button" class="btn btn-xs btn-icon btn-ghost-secondary" data-dismiss="modal"
                        aria-label="Close">
                        <i class="tio-clear tio-lg"></i>
                    </button>
                </div>

                <form action="{{ route('vendor.order.add-order-proof', [$order['id']]) }}" method="post"
                    enctype="multipart/form-data">
                    @csrf
                    <div class="modal-body">
                        <!-- Input Group -->
                        <div class="flex-grow-1 mx-auto">
                            <div class="d-flex flex-wrap __gap-12px __new-coba" id="coba">
                                @php($proof = isset($order->order_proof) ? json_decode($order->order_proof, true) : 0)
                                @if ($proof)

                                    @foreach ($proof as $key => $photo)
                                        @php($photo = is_string($photo) ? ['img' => $photo, 'storage' => 'public'] : $photo)

                                        <div class="spartan_item_wrapper min-w-100px max-w-100px">
                                            <img class="img--square" src="{{ $order->order_proof_full_url[$key] }}"
                                                alt="order image">
                                            <a href="{{ route('vendor.order.remove-proof-image', ['id' => $order['id'], 'name' => $photo['img']]) }}"
                                                class="spartan_remove_row"><i class="tio-add-to-trash"></i></a>
                                        </div>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                        <!-- End Input Group -->
                        <div class="text-right mt-2">
                            <button class="btn btn--primary">{{ translate('messages.submit') }}</button>
                        </div>
                    </div>
                </form>

            </div>
        </div>
    </div>
    <!-- End Modal -->

    <!--Dm assign Modal -->
    <div class="modal fade" id="myModal" tabindex="-1" role="dialog" aria-labelledby="myModalLabel">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="myModalLabel">
                        {{ $selected_delivery_man != [] ? translate('messages.Change_Delivery_Men') : translate('messages.assign_deliveryman') }}
                    </h4>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
                            aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-5 my-2">
                            <ul class="list-group overflow-auto max-height-400">
                                @if ($selected_delivery_man != [])
                                    <li class="list-group-item">
                                        <div class="d-flex align-items-center gap-2 justify-content-between">
                                            <div class="dm_list_selected media gap-2"
                                                data-id="{{ $selected_delivery_man['id'] }}">
                                                <img class="avatar avatar-60 rounded-10 onerror-image"
                                                    data-onerror-image="{{ dynamicAsset('assets/admin/img/160x160/img1.jpg') }}"
                                                    src="{{ $selected_delivery_man['image_full_url'] ?? dynamicAsset('assets/admin/img/160x160/img1.jpg') }}"
                                                    alt="{{ $selected_delivery_man['name'] }}">
                                                <div class="media-body d-flex gap-1 flex-column">
                                                    <h6 class="mb-1"> {{ $selected_delivery_man['name'] }}</h6>
                                                    <div class="fs-12 text-muted">
                                                        {{ translate('Active_Orders') }} :
                                                        {{ $selected_delivery_man['current_orders'] }}
                                                    </div>

                                                    <div class="fs-12 text-muted">
                                                        {{ $selected_delivery_man['distance'] }}
                                                        {{ translate('away_from_restaurant') }}
                                                    </div>
                                                </div>
                                            </div>
                                            <span class="badge __badge badge-success __badge-abs">
                                                {{ translate('Currently_Assigned') }}
                                            </span>
                                        </div>
                                    </li>
                                @endif

                                <p class="mb-2 text-center text-danger {{ $order->delivery_man_id ? 'mt-2' : ' ' }}">
                                    {{ count($deliveryMen) > 0 ? count($deliveryMen) - ($order->delivery_man_id ? 1 : 0) : 0 }}
                                    {{ translate('Delivery_Man_Available') }}</p>

                                @foreach ($deliveryMen as $dm)
                                    @if ($dm['id'] != $order->delivery_man_id)
                                        <li class="list-group-item">
                                            <div class="d-flex align-items-center gap-2 justify-content-between">
                                                <div class="dm_list media gap-2" data-id="{{ $dm['id'] }}">
                                                    <img class="avatar avatar-60 rounded-10 onerror-image"
                                                        data-onerror-image="{{ dynamicAsset('assets/admin/img/160x160/img1.jpg') }}"
                                                        src="{{ $dm['image_full_url'] ?? dynamicAsset('assets/admin/img/160x160/img1.jpg') }}"
                                                        alt="{{ $dm['name'] }}">
                                                    <div class="media-body d-flex gap-1 flex-column">
                                                        <h6 class="mb-1"> {{ $dm['name'] }}</h6>
                                                        <div class="fs-12 text-muted">
                                                            {{ translate('Active_Orders') }} :
                                                            {{ $dm['current_orders'] }}
                                                        </div>

                                                        <div class="fs-12 text-muted">
                                                            {{ $dm['distance'] }}
                                                            {{ translate('away_from_restaurant') }}
                                                        </div>
                                                    </div>
                                                </div>

                                                @if ($order->delivery_man_id)
                                                    <a class="btn btn-primary btn-xs float-right add-delivery-man"
                                                        data-id="{{ $dm['id'] }}">{{ translate('messages.Reassign') }}</a>
                                                @else
                                                    <a class="btn btn-primary btn-xs float-right add-delivery-man"
                                                        data-id="{{ $dm['id'] }}">{{ translate('messages.assign') }}</a>
                                                @endif
                                            </div>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                        <div class="col-md-7 modal_body_map">
                            <div class="location-map" id="dmassign-map">
                                <div id="map_canvas"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- End Modal -->

    <!--Show locations on map Modal -->
    <div class="modal fade" id="locationModal" tabindex="-1" role="dialog" aria-labelledby="locationModalLabel">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="locationModalLabel">{{ translate('messages.location_data') }}</h4>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span
                            aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12 modal_body_map">
                            <div class="location-map" id="location-map">
                                <div id="location_map_canvas"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- End Modal -->

    <!-- Order Edit -->
    <div id="offcanvas__order_edit" class="custom-offcanvas d-flex flex-column justify-content-between" style="--offcanvas-width: 750px">
        <div>
            <form action="{{ route('vendor.order.update', [$order['id']]) }}" method="post">
                @csrf
            <div class="custom-offcanvas-header d-flex justify-content-between align-items-center">
                <div class="px-3 py-3 d-flex justify-content-between w-100">
                    <div>
                        <h2 class="mb-1">{{ translate('Edit Item') }}</h2>
                        <div class="d-flex flex-wrap align-items-center gap-30">
                            <h3 class="page-header-title d-flex align-items-center gap-2">
                                <span class="font--max-sm fs-14">{{ translate('Order') }} #{{ $order['id'] }}</span>
                                <span class="badge badge-soft-info font-regular m-0">{{ translate($order['order_status']) }}</span>
                            </h3>
                            <div class="d-flex align-items-center gap-2">
                                <span class="fs-14 font-regular d-block text-dark">{{ translate('Order_Placed') }} :</span>
                                <span class="fs-14 font-semibold d-block text-dark"> {{ Helpers::time_date_format($order->created_at)  }}</span>
                            </div>
                        </div>
                    </div>
                    <button type="button"
                        class="btn-close w-25px h-25px border rounded-circle d-center bg--secondary offcanvas-close location-reload fz-15px p-0"
                        aria-label="Close">&times;
                    </button>
                </div>
            </div>
            <div class="custom-offcanvas-body p-20">
                <div class="mb-20 position-relative edit-search-form">
                    <div class="form-control position-relative bg-white d-flex align-items-center gap-2">
                        <i class="tio-search"></i>
                        <input id="food_search" data-restaurant_id="{{ $order['restaurant_id'] }}" data-url="{{ route('vendor.order.getSearchedFoods') }}" type="search" name="search" class="h-100 fs-12 bg-transparent w-100 border-0 rounded-0"  placeholder="Search by food name" autocomplete="off">
                        <!--- After Search -->
                       <div id="food_search_result"> </div>
                    </div>
                </div>


                <div id ="data-view">  </div>

            </div>
            <div class="offcanvas-footer position-absolute bottom-0 start-0 w-100 bg-white p-3 d-flex align-items-center justify-content-end gap-3">
                <button type="button"  class="btn min-w-120 btn--reset location-reload  reset">{{ translate('Cancel') }}</button>
                <button type="submit" class="btn min-w-120 btn--primary">{{ translate('Update Cart') }}</button>
            </div>
            </form>
        </div>
    </div>
    <div id="offcanvasOverlay_fixed" class="offcanvasOverlay_fixed"></div>

    <!-- History Log -->
     <div id="offcanvas__history_log" class="custom-offcanvas d-flex flex-column justify-content-between" style="--offcanvas-width: 570px">
        <div>
            <div class="custom-offcanvas-header d-flex justify-content-between align-items-center">
                <div class="px-3 py-3 d-flex justify-content-between w-100">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <h2 class="mb-1 fs-18 font-medium">{{ translate('Edit History Log') }}</h2>
                        <h3 class="page-header-title d-flex align-items-center gap-2">
                            <span class="font--max-sm fs-14 font-normal fs-14">(# {{ $order['id'] }})</span>
                        </h3>
                    </div>
                    <button type="button"
                        class="btn-close w-25px h-25px border rounded-circle d-center bg--secondary offcanvas-close fz-15px p-0"
                        aria-label="Close">&times;
                    </button>
                </div>
            </div>
            <div class="custom-offcanvas-body p-20">
                <div class="p-xl-0">
                    <div class="table-responsive pt-0 mb-20">
                        <div class="p-1">
                            <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table dataTable no-footer mb-0">
                                <thead class="border-0 initial-94">
                                    <tr>
                                        <th class="border-0">{{ translate('Sl') }}</th>
                                        <th class="border-0">{{ translate('Date & time') }}</th>
                                        <th class="border-0 ">{{ translate('Remark') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($order->orderEditLogs as $key => $log)
                                    <tr>
                                        <td>
                                            <div>
                                                {{ $key + 1 }}
                                            </div>
                                        </td>
                                        <td class="fs-14">
                                            <span class="d-block text-dark">{{ Helpers::date_format($log->created_at) }} </span>
                                            <span class="text-muted">{{ Helpers::time_format($log->created_at) }}</span>
                                        </td>
                                        <td class="fs-14">
                                           {{ translate($log->log) }}
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="offcanvasOverlay" class="offcanvas-overlay"></div>

    <div class="modal quick_modal_init fade" id="quick-view" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" id="quick-view-modal">

            </div>
        </div>
    </div>



      <div class="modal fade" id="edit-order">

            <div class="modal-dialog modal-dialog-centered status-warning-modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal">
                            <span aria-hidden="true" class="tio-clear"></span>
                        </button>
                    </div>
                    <div class="modal-body pb-5 pt-0">
                        <div class="max-349 mx-auto mb-20">
                            <div>
                                <div class="text-center">
                                    <img src="{{dynamicAsset('assets/admin/img/fi_10308693.png')}}" alt="" class="mb-20">
                                    <h5 class="modal-title text-center text-capitalize">{{ translate('Are You sure you want to edit this order?') }}</h5>
                                </div>
                                <div class="text-center">
                                    <p>{{ translate('If you edit this order, some product details will be updated, which may affect the total price.') }}</p>
                                </div>
                            </div>
                            <div class="btn--container justify-content-center">
                                <button id="reset_btn" type="reset"  class="btn btn--secondary min-w-120  " data-dismiss="modal">{{translate('No')}}</button>
                                <button class="btn btn--primary min-w-120 offcanvas-trigger offcanvas-trigger_fixed"  id="edit_order_cart"
                                data-url="{{ route('vendor.order.edit',$order->id) }}"

                                data-target="#offcanvas__order_edit" data-dismiss="modal">
                                    {{translate("Yes")}}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>


      <div class="modal fade" id="remove-from-cart">
            <div class="modal-dialog modal-dialog-centered status-warning-modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal">
                            <span aria-hidden="true" class="tio-clear"></span>
                        </button>
                    </div>
                    <div class="modal-body pb-5 pt-0">
                        <div class="max-349 mx-auto mb-20">
                            <div>
                                <div class="text-center">
                                    <img src="{{dynamicAsset('assets/admin/img/fi_10308693.png')}}" alt="" class="mb-20">
                                    <h5 class="modal-title text-center text-capitalize">{{ translate('Are you sure you want to delete this Food?') }}</h5>
                                </div>
                                <div class="text-center">
                                    <p>{{ translate('If you delete this food item, it will be removed from the list. You’ll need to add it again by searching in the food list.') }}</p>
                                </div>
                            </div>

                            <input type="hidden" name="delete_cart_id" id="delete_cart_id" value="" >
                            <div class="btn--container justify-content-center">
                                <button  class="btn btn--secondary min-w-120" data-dismiss="modal">{{translate('Cancel')}}</button>
                                <button class="btn btn--danger min-w-120 removeFromCart"

                                data-dismiss="modal">
                                    {{translate("Delete")}}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>



@endsection
@push('script_2')
    <script
        src="https://maps.googleapis.com/maps/api/js?key={{ Helpers::get_business_data('map_api_key') }}&libraries=places,marker&callback=initMap&v=3.61">
    </script>
    <script src="{{ dynamicAsset('assets/admin/js/spartan-multi-image-picker.js') }}"></script>
    <script src="{{ dynamicAsset('assets/admin/js/offcanvas.js') }}"></script>
    <script type="text/javascript" src="{{ dynamicAsset('assets/admin/js/moment.min.js') }}"></script>
    <script type="text/javascript" src="{{ dynamicAsset('assets/admin/js/daterangepicker.min.js') }}"></script>
    <script>
        $(document).ready(function() {
            $('.offcanvas-trigger_fixed').on('click', function() {
                // Add 'active' class to the overlay
                $('.offcanvasOverlay_fixed').addClass('active');
            });

            // Close functionality
            $('.offcanvas-close').on('click', function() {
                $('.offcanvasOverlay_fixed').removeClass('active');
            });
        });
    </script>
    <script>
        $(document).on('click', '.quick-View', function () {
            let product_id = $(this).data('id');
            $.get({
                url: '{{ route('vendor.order.quick-view') }}',
                dataType: 'json',
                data: {
                    product_id: product_id,
                    order_id: '{{ $order->id }}',
                },
                beforeSend: function() {
                    $('#loading').show();
                },
                success: function(data) {
                    $('#quick-view').modal('show');
                    $('#quick-view-modal').empty().html(data.view);
                },
                complete: function() {
                    $('#loading').hide();
                },
            });
        })

        $(document).on('click', '.remove_from_cart_id', function () {
            var cartId = $(this).data('cart_id');
            $('#delete_cart_id').val(cartId);
        });

        $(document).on('click', '.disable-delete-btn-text', function () {
            toastr.error('{{ translate('You must have at least one food in cart.You can not delete this food.') }}', { CloseButton: true,ProgressBar: true });
        });

        $(document).ready(function () {
            let searchTimeout;

            $('#food_search').on('keyup', function () {
                clearTimeout(searchTimeout);

                let query = $(this).val().trim();
                let restaurantId = $(this).data('restaurant_id');
                let url = $(this).data('url');

                searchTimeout = setTimeout(function () {
                    if (query.length > 0) {
                        $.ajax({
                            url: url,
                            method: 'GET',
                            data: {
                                search: query,
                                restaurant_id: restaurantId
                            },
                            beforeSend: function() {
                                $('#food_search_result').empty();

                            },
                            success: function (response) {
                                $('#food_search_result').append(response.view);
                            }
                        });
                    }
                }, 300);
            });


                document.querySelectorAll('[name="search"]').forEach(function (element) {
                element.addEventListener("input", function (event) {
                        if (this.value === "" ) {
                          $('#food_search_result').empty();
                        }
                    });
                });
        });

        $(document).on('click', '#edit_order_cart', function() {
            // $('#content-disable').addClass('disabled');
            fetch_data($(this).data('url'))
        })

        function fetch_data(url) {
            $.ajax({
                url: url,
                type: "get",
                beforeSend: function() {
                    $('#data-view').empty();
                    $('#loading').show()
                },
                success: function(data) {
                    if(data.error === 'food_not_found'){
                        $('.custom-offcanvas').removeClass('open');
                        $('#offcanvasOverlay').removeClass('show');
                        toastr.error('{{ translate('messages.product_deleted') }}', { CloseButton: true,ProgressBar: true });
                    } else {
                        $("#data-view").append(data.view);

                    }
                },
                complete: function() {
                    $('#loading').hide()
                }
            })
        }

        $(function () {
             const $input = $('#hiddenInput');
             $input.daterangepicker({
                singleDatePicker: true,
                timePicker: true,
                timePicker24Hour: false,
                timePickerSeconds: true,
                autoUpdateInput: false,
                minDate: moment(),
                locale: { format: 'MM/DD/YYYY hh:mm A' },
                parentEl: 'body'
            });

            $input.on('apply.daterangepicker', function (ev, picker) {
                $(this).val(picker.startDate.format('MM/DD/YYYY hh:mm A'));
                $.post('{{ route("admin.order.updateSchedule") }}', {
                    _token: '{{ csrf_token() }}',
                    order_id: '{{ $order->id }}',
                    date: picker.startDate.format('YYYY-MM-DD HH:mm:ss')
                }, function (response) {
                    if (response.status === 'success') {
                         toastr.success(response.message);
                          setTimeout(function () {
                            location.reload();
                        }, 800);
                    } else {
                       toastr.error(response.message);
                    }
                });
            });
            $('#customTrigger').on('click', function () {
                $input.data('daterangepicker').show();
            });


            $input.on('show.daterangepicker', function (e, picker) {
                picker.container.addClass('date__custom_width');
            });
        });

        $(document).ready(function () {
            let $stickyDiv = $("#stickyDiv");

            function checkSticky() {
                let rect = $stickyDiv[0].getBoundingClientRect();

                if (rect.bottom >= window.innerHeight && rect.top < window.innerHeight) {
                    $stickyDiv.addClass("total_price_sticky_wrapper");
                } else {
                    $stickyDiv.removeClass("total_price_sticky_wrapper");
                }
            }

            $(window).on("scroll resize", checkSticky);

            if ({{ $order->bring_change_amount > 0 ? 'true' : 'false' }}){
                checkSticky();
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                return false;
            }
        });

        $(document).on('click', '.addon-quantity-input-toggle', function (event) {
            let cb = $(event.target);
            if (cb.is(":checked")) {
                cb.siblings('.addon-quantity-input').css({
                    'visibility': 'visible'
                });
            } else {
                cb.siblings('.addon-quantity-input').css({
                    'visibility': 'hidden'
                });
            }
        })

        $(document).on('click', '.quick_view_cart_item', function (event) {
            quick_view_cart_item($(this).data('id'), $(this).data('cart_id'));
        })

        function quick_view_cart_item(key, cart_id) {
            $.get({
                url: '{{ route('vendor.order.quick-view-cart-item') }}',
                dataType: 'json',
                data: {
                    key: key,
                    cart_id: cart_id,
                    order_id: '{{ $order->id }}',
                },
                beforeSend: function() {
                    $('#loading').show();
                },
                success: function(data) {
                    $('#quick-view').modal('show');
                    $('#quick-view-modal').empty().html(data.view);
                },
                complete: function() {
                    $('#loading').hide();
                },
            });
        }

        function cartQuantityInitialize() {
            $('.btn-number').click(function(e) {
                e.preventDefault();

                let fieldName = $(this).attr('data-field');
                let type = $(this).attr('data-type');
                let input = $("input[name='" + fieldName + "']");
                let currentVal = parseInt(input.val());

                if (!isNaN(currentVal)) {
                    if (type == 'minus') {

                        if (currentVal > input.attr('min')) {
                            input.val(currentVal - 1).change();
                        }
                        if (parseInt(input.val()) == input.attr('min')) {
                            $(this).attr('disabled', true);
                        }

                    } else if (type == 'plus') {

                        if (currentVal < input.attr('max')) {
                            input.val(currentVal + 1).change();
                        }
                        if (parseInt(input.val()) == input.attr('max')) {
                            $(this).attr('disabled', true);
                        }

                    }
                } else {
                    input.val(0);
                }
            });

            $('.input-number').focusin(function() {
                $(this).data('oldValue', $(this).val());
            });

            $('.input-number').change(function() {

                minValue = parseInt($(this).attr('min'));
                maxValue = parseInt($(this).attr('max'));
                valueCurrent = parseInt($(this).val());

                let name = $(this).attr('name');
                if (valueCurrent >= minValue) {
                    $(".btn-number[data-type='minus'][data-field='" + name + "']").removeAttr('disabled')
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Cart',
                        text: 'Sorry, the minimum value was reached'
                    });
                    $(this).val($(this).data('oldValue'));
                }
                if (valueCurrent <= maxValue) {
                    $(".btn-number[data-type='plus'][data-field='" + name + "']").removeAttr('disabled')
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Cart',
                        text: 'Sorry, stock limit exceeded.'
                    });
                    $(this).val($(this).data('oldValue'));
                }
            });
            $(".input-number").keydown(function(e) {
                // Allow: backspace, delete, tab, escape, enter and .
                if ($.inArray(e.keyCode, [46, 8, 9, 27, 13, 190]) !== -1 ||
                    // Allow: Ctrl+A
                    (e.keyCode == 65 && e.ctrlKey === true) ||
                    // Allow: home, end, left, right
                    (e.keyCode >= 35 && e.keyCode <= 39)) {
                    // let it happen, don't do anything
                    return;
                }
                // Ensure that it is a number and stop the keypress
                if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
                    e.preventDefault();
                }
            });
        }

        function getVariantPrice() {
            if (typeof getCheckedInputs === 'function') {
                getCheckedInputs();
            }
            if ($('#add-to-cart-form input[name=quantity]').val() > 0) {
                $.ajaxSetup({
                    headers: {
                        'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')
                    }
                });
                $.ajax({
                    type: "POST",
                    url: '{{ route('vendor.pos.variant_price') }}',
                    data: $('#add-to-cart-form').serializeArray(),
                    success: function(data) {
                        if (data.error === 'quantity_error') {
                            toastr.error(data.message);
                        }
                        else if(data.error === 'stock_out'){
                            toastr.warning(data.message);
                            if(data.type == 'addon'){
                                $('#addon_quantity_button'+data.id).attr("disabled", true);
                                $('#addon_quantity_input'+data.id).val(data.current_stock);
                            }

                            else{
                                $('#quantity_increase_button').attr("disabled", true);
                                $('#add_new_product_quantity').val(data.current_stock);
                            }
                            getVariantPrice();
                        }

                        else {
                            $('#add-to-cart-form #chosen_price_div').removeClass('d-none');
                            $('#add-to-cart-form #chosen_price_div #chosen_price').html(data.price);
                            $('.add-To-Cart').removeAttr("disabled");
                            $('.increase-button').removeAttr("disabled");
                            $('#quantity_increase_button').removeAttr("disabled");

                        }

                    }
                });
            }
        }

        $(document).on('change', '.update-Quantity', function () {
            handleQuantityChange($(this));
        });

        $(document).on('click', '.decrease-button', function () {
            let addonId = $(this).data('id');
            let addon_quantity_input = $('input[name="addon-quantity' + addonId + '"]');
            let currentValue = parseInt(addon_quantity_input.val(), 10);
            if (currentValue > 1) {
                addon_quantity_input.val(currentValue - 1);
                getVariantPrice();
            }
        });

        $(document).on('click', '.increase-button', function () {
            let addonId = $(this).data('id');
            let addon_quantity_input = $('input[name="addon-quantity' + addonId + '"]');
            let currentValue = parseInt(addon_quantity_input.val(), 10);
            addon_quantity_input.val(currentValue + 1);
            getVariantPrice();
        });

        $(document).on('click', '.decrease-quantity-button', function () {
            let Id = $(this).data('id');
            let quantity_input = $('#update_quantity_' + Id);
            let currentValue = parseInt(quantity_input.val(), 10);
            if (currentValue > parseInt(quantity_input.attr('min'))) {
                quantity_input.val(currentValue - 1);
            }
             quantity_input.trigger('change');
        });

        $(document).on('click', '.increase-quantity-button', function () {
            let Id = $(this).data('id');
            let quantity_input = $('#update_quantity_' + Id);
            let currentValue = parseInt(quantity_input.val(), 10);
            // if (currentValue < parseInt(quantity_input.attr('max'))) {
            // }
            quantity_input.val(currentValue + 1);
            quantity_input.trigger('change');
        });

        function handleQuantityChange(element) {

            let valueCurrent = parseInt(element.val());
            let key = element.data('key');
            let option_ids = element.data('option_ids');
            let variation_options_old = element.data('variation_options_old');
            let addon_price = element.data('addon_price');
            let order_detail_id = element.data('order_detail_id');
            let food_id = element.data('food_id');
            let oldvalue = element.data('value');

                $.post('{{ route('vendor.order.getSingleFoodPrice') }}', {
                    _token: '{{ csrf_token() }}',
                    cart_id: key,
                    option_ids: option_ids,
                    food_id: food_id,
                    addon_price: addon_price,
                    variation_options_old: variation_options_old,
                    order_detail_id: order_detail_id,
                    quantity: valueCurrent
                }, function (data) {
                    if (data.data === 'stock_out') {
                        element.val(oldvalue);
                        Swal.fire({
                            icon: 'error',
                            title: "{{ translate('Cart') }}",
                            text: data.message
                        });
                    } else if (data.data === 'maximum_cart_quantity') {
                         element.val(oldvalue);
                         toastr.error(data.message, {
                            CloseButton: true,
                            ProgressBar: true
                        });
                    }
                    else {
                        element.data('value', valueCurrent);
                         toastr.success(data.message, {
                            CloseButton: true,
                            ProgressBar: true
                        });
                        $("#data-view").empty().append(data.view);
                    }
                });

        }

        $(document).on('change', '[name="quantity"]', function (event) {
            getVariantPrice();
            if($('#option_ids').val() == ''){
                $(this).attr('max', $(this).data('maximum_cart_quantity'));
            }
        });

        $(document).on('click', '.add-To-Cart', function () {
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': "{{ csrf_token() }}"
                }
            });
            let form_id = 'add-to-cart-form';
            $.post({
                url: '{{ route('vendor.order.add-to-cart') }}',
                data: $('#' + form_id).serializeArray(),
                beforeSend: function() {
                    $('#loading').show();
                },
                success: function(data) {
                    if (data.data == 1) {
                        Swal.fire({
                            icon: 'info',
                            title: 'Cart',
                            text: "{{ translate('messages.product_already_added_in_cart') }}"
                        });
                        return false;
                    } else if (data.data == 0) {
                        $('#food_search_result').empty();
                        $('#quick-view').modal('hide');
                        $("#data-view").empty().append(data.view);
                        toastr.success(data.message, {
                            CloseButton: true,
                            ProgressBar: true
                        });

                        return false;

                    } else if (data.data === 'stock_out') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Cart',
                            text: data.message
                        });
                        return false;
                    }

                    else if (data.data == 'variation_error') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Cart',
                            text: data.message
                        });
                        return false;
                    }
                    $('.call-when-done').click();

                },
                complete: function() {
                    $('#loading').hide();
                }
            });
        })

        $(document).on('click', '.add-To-Cart-Single', function () {
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': "{{ csrf_token() }}"
                }
            });

            let id = $(this).data('id');
            let item_type = $(this).data('item_type');
            let quantity = $(this).data('quantity');

            $.post({
                url: '{{ route('vendor.order.add-to-cart') }}',
                data: {
                    id: id,
                    item_type: item_type,
                    quantity: quantity
                },
                beforeSend: function() {
                    $('#loading').show();
                },
                success: function(data) {
                    if (data.data == 1) {
                        Swal.fire({
                            icon: 'info',
                            title: 'Cart',
                            text: "{{ translate('messages.product_already_added_in_cart') }}"
                        });
                        return false;
                    } else if (data.data == 0) {
                        $('#food_search_result').empty();
                        $('#quick-view').modal('hide');
                        $("#data-view").empty().append(data.view);
                        toastr.success('{{ translate('messages.product_has_been_added_in_cart') }}', {
                            CloseButton: true,
                            ProgressBar: true
                        });
                        return false;
                    } else if (data.data === 'stock_out') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Cart',
                            text: data.message
                        });
                        return false;
                    } else if (data.data == 'variation_error') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Cart',
                            text: data.message
                        });
                        return false;
                    }
                    $('.call-when-done').click();
                },
                complete: function() {
                    $('#loading').hide();
                }
            });
        });

        $(document).on('click', '.removeFromCart', function () {
                $.post('{{ route('vendor.order.remove-from-cart') }}', {
                        _token: '{{ csrf_token() }}',
                        cart_id:  $('#delete_cart_id').val(),
                        order_id: '{{ $order->id }}'
                    }, function(data) {
                        $('#quick-view').modal('hide');
                        if (data.errors) {
                            for (let i = 0; i < data.errors.length; i++) {
                                toastr.error(data.errors[i].message, {
                                    CloseButton: true,
                                    ProgressBar: true
                                });
                            }
                        } else {
                        $('#food_search_result').empty();
                        $('#quick-view').modal('hide');
                        $("#data-view").empty().append(data.view);
                            toastr.success('{{ translate('messages.item_has_been_removed_from_cart') }}', {
                                CloseButton: true,
                                ProgressBar: true
                            });

                        }
                    });
        })

        $(document).ready(function() {
            let $stickyDiv = $("#stickyDiv");

            function checkSticky() {
                let rect = $stickyDiv[0].getBoundingClientRect();

                if (rect.bottom >= window.innerHeight && rect.top < window.innerHeight) {
                    $stickyDiv.addClass("total_price_sticky_wrapper");
                } else {
                    $stickyDiv.removeClass("total_price_sticky_wrapper");
                }
            }

            $(window).on("scroll resize", checkSticky);

            if ({{ $order->bring_change_amount > 0 ? 'true' : 'false' }}) {
                checkSticky();
            }
        });
    </script>
    <script>
        "use strict";

        $('.cancelled-status').on('click', function() {
            Swal.fire({
                title: '{{ translate('messages.are_you_sure_?') }}',
                text: '{{ translate('messages.You_want_to_cancel_this_order_?') }}',
                type: 'warning',
                html: `<select class="form-control js-select2-custom mx-1" name="reason" id="reason">
                        <option value=" ">
                            {{ translate('select_cancellation_reason') }}
                        </option>
                    @foreach ($reasons as $r)
                        <option value="{{ $r->reason }}">
                            {{ $r->reason }}
                        </option>
                    @endforeach
                    </select>
                    <div class="mt-3 text-start">
                        <textarea class="form-control mx-1" name="note" id="note" rows="4" placeholder="{{ translate('Cancellation note') }}"></textarea>
                    </div>`,
                showCancelButton: true,
                cancelButtonColor: 'default',
                confirmButtonColor: '#FC6A57',
                cancelButtonText: '{{ translate('messages.no') }}',
                confirmButtonText: '{{ translate('messages.yes') }}',
                reverseButtons: true,
                onOpen: function() {
                    $('.js-select2-custom').select2({
                        minimumResultsForSearch: 5,
                        width: '100%',
                        placeholder: "Select Reason",
                        language: "en",
                    });
                }
            }).then((result) => {
                if (result.value) {
                    let reason = document.getElementById('reason').value;
                    let note = document.getElementById('note').value;
                    location.href = '{!! route('vendor.order.status', ['id' => $order['id'], 'order_status' => 'canceled']) !!}&reason=' + reason + '&note=' + note,
                        '{{ translate('Change status to canceled ?') }}';
                }
            })
        })

        $('.order-status-change-alert').on('click', function() {
            let route = $(this).data('url');
            let message = $(this).data('message');
            let verification = $(this).data('verification');
            let processing = $(this).data('processing-time') ?? false;
            if (verification) {
                Swal.fire({
                    title: '{{ translate('Enter order verification code') }}',
                    input: 'text',
                    inputAttributes: {
                        autocapitalize: 'off'
                    },
                    showCancelButton: true,
                    cancelButtonColor: 'default',
                    confirmButtonColor: '#FC6A57',
                    confirmButtonText: '{{ translate('messages.submit') }}',
                    showLoaderOnConfirm: true,
                    preConfirm: (otp) => {
                        location.href = route + '&otp=' + otp;
                    },
                    allowOutsideClick: () => !Swal.isLoading()
                })

            } else if (processing) {
                Swal.fire({
                    //text: message,
                    title: '{{ translate('messages.Are you sure ?') }}',
                    type: 'warning',
                    showCancelButton: true,
                    cancelButtonColor: 'default',
                    confirmButtonColor: '#FC6A57',
                    cancelButtonText: '{{ translate('messages.Cancel') }}',
                    confirmButtonText: '{{ translate('messages.submit') }}',
                    inputPlaceholder: "{{ translate('Enter processing time') }}",
                    input: 'text',
                    html: message + '<br/>' +
                        '<label>{{ translate('Enter Processing time in minutes') }}</label>',
                    inputValue: processing,
                    inputValidator: (value) => {
                        if (!value || !/^[0-9]+$/.test(value) || parseInt(value) < 1) {
                            return '请填写预计出餐时间（至少 1 分钟）后再接单';
                        }
                    },
                    preConfirm: (processing_time) => {
                        location.href = route + '&processing_time=' + processing_time;
                    },
                    allowOutsideClick: () => !Swal.isLoading()
                })
            } else {
                Swal.fire({
                    title: '{{ translate('messages.Are you sure ?') }}',
                    text: message,
                    type: 'warning',
                    showCancelButton: true,
                    cancelButtonColor: 'default',
                    confirmButtonColor: '#FC6A57',
                    cancelButtonText: '{{ translate('messages.No') }}',
                    confirmButtonText: '{{ translate('messages.Yes') }}',
                    reverseButtons: true
                }).then((result) => {
                    if (result.value) {
                        location.href = route;
                    }
                })
            }
        })

        function last_location_view() {
            toastr.warning('{{ translate('Only available when order is out for delivery!') }}', {
                CloseButton: true,
                ProgressBar: true
            });
        }

        $('.add-delivery-man').on('click', function() {
            let id = $(this).data('id');
            $.ajax({
                type: "GET",
                url: '{{ url('/') }}/restaurant-panel/order/add-delivery-man/{{ $order['id'] }}/' +
                    id,
                success: function(data) {

                    toastr.success('{{ translate('Successfully_added') }}', {
                        CloseButton: true,
                        ProgressBar: true
                    });
                    location.reload();
                },
                error: function(response) {
                    toastr.error(response.responseJSON.message, {
                        CloseButton: true,
                        ProgressBar: true
                    });
                }
            });
        })

        let deliveryMan = <?php echo json_encode($deliveryMen); ?>;

        const mapId = "{{ \App\Models\BusinessSetting::where('key', 'map_api_key')->first()->value }}";
        const { AdvancedMarkerElement } = google.maps.marker;

        function createMarkerIcon(src) {
            const img = document.createElement('img');
            img.src = src;
            img.style.width = '100%';
            img.style.height = '100%';
            return img;
        }

        const customerIcon = createMarkerIcon("{{ dynamicAsset('assets/admin/img/customer_location.png') }}");
        const restaurantIcon = createMarkerIcon("{{ dynamicAsset('assets/admin/img/restaurant_map_1.png') }}");
        const deliveryBoy1Icon = createMarkerIcon("{{ dynamicAsset('assets/admin/img/delivery_boy_map_1.png') }}");
        const deliveryBoy2Icon = createMarkerIcon("{{ dynamicAsset('assets/admin/img/delivery_boy_map_2.png') }}");


        let map = null;
        let myLatlng = new google.maps.LatLng({{ isset($order->restaurant) ? $order->restaurant->latitude : 0 }},
            {{ isset($order->restaurant) ? $order->restaurant->longitude : 0 }});
        let dmbounds = new google.maps.LatLngBounds(null);
        let locationbounds = new google.maps.LatLngBounds(null);
        let dmMarkers = [];
        dmbounds.extend(myLatlng);
        locationbounds.extend(myLatlng);
        let myOptions = {
            center: myLatlng,
            mapId: mapId,
            zoom: 13,
            mapTypeId: google.maps.MapTypeId.ROADMAP,

            panControl: true,
            mapTypeControl: false,
            panControlOptions: {
                position: google.maps.ControlPosition.RIGHT_CENTER
            },
            zoomControl: true,
            zoomControlOptions: {
                style: google.maps.ZoomControlStyle.LARGE,
                position: google.maps.ControlPosition.RIGHT_CENTER
            },
            scaleControl: false,
            streetViewControl: false,
            streetViewControlOptions: {
                position: google.maps.ControlPosition.RIGHT_CENTER
            }
        };

        function initializeGMap() {

            map = new google.maps.Map(document.getElementById("map_canvas"), myOptions);

            let infowindow = new google.maps.InfoWindow();
            let dm_default_image = null;

            @if ($order->restaurant)
                let Restaurantmarker = new AdvancedMarkerElement({
                    position: new google.maps.LatLng(
                        {{ isset($order->restaurant) ? $order->restaurant->latitude : 0 }},
                        {{ isset($order->restaurant) ? $order->restaurant->longitude : 0 }}),
                    map: map,
                    title: "{{ isset($order->restaurant) ? Str::limit($order->restaurant->name, 15, '...') : '' }}",
                    {{--icon: "{{ dynamicAsset('assets/admin/img/restaurant_map_1.png') }}"--}}
                    content: restaurantIcon
                });

                google.maps.event.addListener(Restaurantmarker, 'click', (function(Restaurantmarker) {
                    return function() {
                        infowindow.setContent(
                            `<div class='float--left'><img class='js--design-1' onerror="this.src='{{ dynamicAsset('assets/admin/img/160x160/img1.jpg') }}'"  src='{{ dynamicStorage('storage/app/public/restaurant/' . $order->restaurant->logo) }}'></div><div class='text-break float--right p--10px'><b>{{ Str::limit($order->restaurant->name, 15, '...') }}</b><br/> {{ $order->restaurant->address }}</div>`
                        );
                        infowindow.open(map, Restaurantmarker);
                    }
                })(Restaurantmarker));
            @endif
            map.fitBounds(dmbounds);
            for (let i = 0; i < deliveryMan.length; i++) {
                if (deliveryMan[i].lat) {
                    let point = new google.maps.LatLng(deliveryMan[i].lat, deliveryMan[i].lng);
                    dmbounds.extend(point);
                    map.fitBounds(dmbounds);
                    let d_man = "{{ $order?->delivery_man_id ?? 0 }}";
                    if (deliveryMan[i].id == d_man) {
                        dm_default_image = deliveryBoy2Icon;
                    } else {
                        dm_default_image = deliveryBoy1Icon;
                    }

                    let marker = new AdvancedMarkerElement({
                        position: point,
                        map: map,
                        title: deliveryMan[i].location,
                        content: dm_default_image


                    });
                    dmMarkers[deliveryMan[i].id] = marker;
                    google.maps.event.addListener(marker, 'click', (function(marker, i) {
                        return function() {
                            infowindow.setContent(
                                `<div class='float--left'><img class='js--design-1 mt-2' onerror="this.src='{{ dynamicAsset('assets/admin/img/160x160/img1.jpg') }}'"  src='{{ dynamicStorage('storage/app/public/delivery-man') }}/` +
                                deliveryMan[i].image +
                                "'></div><div class='float--right p--10px'><b>" + deliveryMan[i]
                                .name +
                                "</b><br/> <div> {{ translate('messages.Active_Orders') }} :" +
                                deliveryMan[i].current_orders + "</div> </b>" + deliveryMan[i]
                                .location + "</div>");
                            infowindow.open(map, marker);
                        }
                    })(marker, i));
                }

            };
        }

        $(document).ready(function() {

            // Re-init map before show modal
            $('#myModal').on('shown.bs.modal', function(event) {
                let button = $(event.relatedTarget);
                $("#dmassign-map").css("width", "100%");
                $("#map_canvas").css("width", "100%");
            });

            // Trigger map resize event after modal shown
            $('#myModal').on('shown.bs.modal', function() {
                initializeGMap();
                google.maps.event.trigger(map, "resize");
                map.setCenter(myLatlng);
            });

            $('#shipping-address-modal').on('shown.bs.modal', function() {
                initMap();
            });


            function initializegLocationMap() {
                map = new google.maps.Map(document.getElementById("location_map_canvas"), myOptions);

                let infowindow = new google.maps.InfoWindow();


                @if (isset($c_address) && isset($c_address['latitude']) && isset($c_address['longitude']))
                    let marker = new AdvancedMarkerElement({
                        position: new google.maps.LatLng({{ $c_address['latitude'] }},
                            {{ $c_address['longitude'] }}),
                        map: map,
                        title: "{{ $order->customer ? $order->customer->f_name . ' ' . $order->customer->l_name : $c_address['contact_person_name'] }}",
                        content: customerIcon
                    });

                    google.maps.event.addListener(marker, 'click', (function(marker) {
                        return function() {
                            infowindow.setContent(
                                `<div class='float--left'><img class='js--design-1' onerror="this.src='{{ dynamicAsset('assets/admin/img/160x160/img1.jpg') }}'"  src='{{ $order->customer ? dynamicStorage('storage/app/public/profile/' . $order?->customer?->image) : dynamicAsset('assets/admin/img/160x160/img3.png') }}'></div><div class='float--right p--10px'><b>{{ $order->customer ? $order->customer->f_name . ' ' . $order->customer->l_name : $c_address['contact_person_name'] }}</b><br/>{{ $c_address['address'] }}</div>`
                            );
                            infowindow.open(map, marker);
                        }
                    })(marker));
                    locationbounds.extend(marker.position);
                @endif
                @if ($order->delivery_man && $order->dm_last_location)
                    let dmmarker = new AdvancedMarkerElement({
                        position: new google.maps.LatLng({{ $order->dm_last_location['latitude'] }},
                            {{ $order->dm_last_location['longitude'] }}),
                        map: map,
                        title: "{{ $order->delivery_man->f_name }}  {{ $order->delivery_man->l_name }}",
                        content: deliveryBoy2Icon
                    });

                    google.maps.event.addListener(dmmarker, 'click', (function(dmmarker) {
                        return function() {
                            infowindow.setContent(
                                `<div class='float--left'><img class='js--design-1 mt-2' onerror="this.src='{{ dynamicAsset('assets/admin/img/160x160/img1.jpg') }}'"  src='{{ dynamicStorage('storage/app/public/delivery-man/' . $order->delivery_man->image) }}'></div><div class='float--right p--10px'><b>{{ $order->delivery_man->f_name }}  {{ $order->delivery_man->l_name }}</b><br/>  <div> {{ translate('messages.Active_Orders') }} : {{ $order->delivery_man->current_orders }} </div> </b>{{ $order->dm_last_location['location'] }}</div>`
                            );
                            infowindow.open(map, dmmarker);
                        }
                    })(dmmarker));
                    locationbounds.extend(dmmarker.position);
                @endif

                @if ($order->restaurant)
                    let Retaurantmarker = new AdvancedMarkerElement({
                        position: new google.maps.LatLng({{ $order->restaurant->latitude }},
                            {{ $order->restaurant->longitude }}),
                        map: map,
                        title: "{{ Str::limit($order->restaurant->name, 15, '...') }}",
                        content: restaurantIcon
                    });

                    google.maps.event.addListener(Retaurantmarker, 'click', (function(Retaurantmarker) {
                        return function() {
                            infowindow.setContent(
                                `<div class='float--left'><img class='js--design-1' onerror="this.src='{{ dynamicAsset('assets/admin/img/160x160/img1.jpg') }}'"  src='{{ dynamicStorage('storage/app/public/restaurant/' . $order->restaurant->logo) }}'></div><div class='float--right p--10px'><b>{{ Str::limit($order->restaurant->name, 15, '...') }}</b><br/> {{ $order->restaurant->address }}</div>`
                            );
                            infowindow.open(map, Retaurantmarker);
                        }
                    })(Retaurantmarker));
                    locationbounds.extend(Retaurantmarker.position);
                @endif

                google.maps.event.addListenerOnce(map, 'idle', function() {
                    map.fitBounds(locationbounds);
                });
            }

            // Re-init map before show modal
            $('#locationModal').on('shown.bs.modal', function(event) {
                initializegLocationMap();
            });



            $('.dm_list').on('click', function() {
                let id = $(this).data('id');
                map.panTo(dmMarkers[id].position);
                map.setZoom(13);
                dmMarkers[id].setAnimation(google.maps.Animation.BOUNCE);
                window.setTimeout(() => {
                    dmMarkers[id].setAnimation(null);
                }, 3);
            });




            $('.dm_list_selected').on('click', function() {
                let id = $(this).data('id');
                map.panTo(dmMarkers[id].position);
                map.setZoom(13);
                dmMarkers[id].setAnimation(google.maps.Animation.BOUNCE);
                window.setTimeout(() => {
                    dmMarkers[id].setAnimation(null);
                }, 3);

            });

        })

        $(function() {
            $("#coba").spartanMultiImagePicker({
                fieldName: 'order_proof[]',
                maxCount: 6 -
                    {{ $order->order_proof && is_array($order->order_proof) ? count(json_decode($order->order_proof)) : 0 }},
                rowHeight: '100px !important',
                groupClassName: 'spartan_item_wrapper min-w-100px max-w-100px',
                maxFileSize: '',
                placeholderImage: {
                    image: "{{ dynamicAsset('assets/admin/img/upload.png') }}",
                    width: '100px'
                },
                dropFileLabel: "Drop Here",
                onAddRow: function(index, file) {

                },
                onRenderedPreview: function(index) {

                },
                onRemoveRow: function(index) {

                },
                onExtensionErr: function(index, file) {
                    toastr.error(
                        "{{ translate('messages.please_only_input_png_or_jpg_type_file') }}", {
                            CloseButton: true,
                            ProgressBar: true
                        });
                },
                onSizeErr: function(index, file) {
                    toastr.error("{{ translate('messages.file_size_too_big') }}", {
                        CloseButton: true,
                        ProgressBar: true
                    });
                }
            });
        });
        function initMap() {
            let zonePolygon = null;
            //get current location block
            let infoWindow = new google.maps.InfoWindow();
            // Try HTML5 geolocation.
            if(!document.getElementById("map")){
                return true;
            }
            let map = new google.maps.Map(document.getElementById("map"), myOptions);

            @if (isset($address) && isset($address['latitude']) && isset($address['longitude']) )
            let marker = new AdvancedMarkerElement({
                position: new google.maps.LatLng({{ $address['latitude'] }},
                    {{ $address['longitude'] }}),
                map: map,
                title: "{{ $order?->customer ? $order?->customer->f_name .' '. $order?->customer->l_name : $address['contact_person_name'] }}",
                content: customerIcon
            });

            google.maps.event.addListener(marker, 'click', (function(marker) {
                return function() {
                    infowindow.setContent(
                        `<div class='float--left'><img class='js--design-1' onerror="this.src='{{ dynamicAsset('assets/admin/img/160x160/img1.jpg') }}'"  src='{{ $order?->customer ? dynamicStorage('storage/app/public/profile/' . $order?->customer?->image) : dynamicAsset('assets/admin/img/160x160/img3.png') }}'></div><div class='float--right p--10px'><b>{{ $order?->customer ? $order?->customer->f_name .' '. $order?->customer->l_name : $address['contact_person_name'] }}</b><br/>{{ $address['address'] }}</div>`
                    );
                    infowindow.open(map, marker);
                }
            })(marker));
            locationbounds.extend(marker.position);
            @endif
            //-----end block------
            const input = document.getElementById("pac-input");
            const searchBox = new google.maps.places.SearchBox(input);
            map.controls[google.maps.ControlPosition.TOP_CENTER].push(input);
            let markers = [];
            const bounds = new google.maps.LatLngBounds();
            searchBox.addListener("places_changed", () => {
                const places = searchBox.getPlaces();

                if (places.length == 0) {
                    return;
                }
                // Clear out the old markers.
                markers.forEach((marker) => {
                    marker.setMap(null);
                });
                markers = [];
                // For each place, get the icon, name and location.
                places.forEach((place) => {
                    if (!place.geometry || !place.geometry.location) {
                        console.log("Returned place contains no geometry");
                        return;
                    }
                    console.log(place.geometry.location);
                    if(!google.maps.geometry.poly.containsLocation(
                        place.geometry.location,
                        zonePolygon
                    )){
                        toastr.error('{{ translate('messages.out_of_coverage') }}', {
                            CloseButton: true,
                            ProgressBar: true
                        });
                        return false;
                    }

                    document.getElementById('latitude').value = place.geometry.location.lat();
                    document.getElementById('longitude').value = place.geometry.location.lng();

                    const icon = {
                        url: place.icon,
                        size: new google.maps.Size(71, 71),
                        origin: new google.maps.Point(0, 0),
                        anchor: new google.maps.Point(17, 34),
                        scaledSize: new google.maps.Size(25, 25),
                    };
                    // Create a marker for each place.
                    markers.push(
                        new AdvancedMarkerElement({
                            map,
                            icon,
                            title: place.name,
                            position: place.geometry.location,
                        })
                    );

                    if (place.geometry.viewport) {
                        // Only geocodes have viewport.
                        bounds.union(place.geometry.viewport);
                    } else {
                        bounds.extend(place.geometry.location);
                    }
                });
                map.fitBounds(bounds);
            });
            @if ($order->restaurant)
                $.get({
                    url: '{{ route('vendor.zone.get-coordinates', ['id' => $order->restaurant->zone_id]) }}',
                    dataType: 'json',
                    success: function(data) {
                        zonePolygon = new google.maps.Polygon({
                            paths: data.coordinates,
                            strokeColor: "#FF0000",
                            strokeOpacity: 0.8,
                            strokeWeight: 2,
                            fillColor: 'white',
                            fillOpacity: 0,
                        });
                        zonePolygon.setMap(map);
                        zonePolygon.getPaths().forEach(function(path) {
                            path.forEach(function(latlng) {
                                bounds.extend(latlng);
                                map.fitBounds(bounds);
                            });
                        });
                        map.setCenter(data.center);
                        google.maps.event.addListener(zonePolygon, 'click', function(mapsMouseEvent) {
                            infoWindow.close();
                            // Create a new InfoWindow.
                            infoWindow = new google.maps.InfoWindow({
                                position: mapsMouseEvent.latLng,
                                content: JSON.stringify(mapsMouseEvent.latLng.toJSON(), null,
                                    2),
                            });
                            let coordinates = JSON.stringify(mapsMouseEvent.latLng.toJSON(), null, 2);
                            coordinates = JSON.parse(coordinates);

                            document.getElementById('latitude').value = coordinates['lat'];
                            document.getElementById('longitude').value = coordinates['lng'];
                            infoWindow.open(map);
                            const geocoder = new google.maps.Geocoder();
                            geocoder.geocode({ location: mapsMouseEvent.latLng }, function (results, status) {
                                if (status === "OK") {
                                    if (results[0]) {
                                        document.getElementById('address').value = results[0].formatted_address;
                                    }
                                }
                            });
                        });
                    },
                });
            @endif
        }
        initMap();
    </script>
@endpush
