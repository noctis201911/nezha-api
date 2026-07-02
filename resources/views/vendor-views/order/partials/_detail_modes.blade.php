@once
    <style>
        .item-box-page > .nz-order-statusbar { display: none !important; }
        .nz-detail-shell { margin-bottom: 14px; }
        .nz-detail-top { position: sticky; top: 0; z-index: 1018; display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 12px 14px; margin-bottom: 12px; background: #fff; border: 1px solid #e6eaf0; border-radius: 10px; box-shadow: 0 3px 14px rgba(16,24,40,.07); }
        .nz-detail-title { min-width: 210px; flex: 1 1 280px; }
        .nz-detail-title h2 { margin: 0; font-size: 20px; line-height: 1.25; font-weight: 900; color: #102a4c; letter-spacing: 0; }
        .nz-detail-meta { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 6px; color: #667085; font-size: 12px; font-weight: 700; }
        .nz-detail-amount { font-size: 20px; font-weight: 900; color: #102a4c; white-space: nowrap; }
        .nz-layout-switch { display: inline-flex; align-items: center; gap: 4px; padding: 4px; border: 1px solid #d8e0ea; border-radius: 9px; background: #f7f9fc; }
        .nz-layout-switch button { border: 0; border-radius: 7px; background: transparent; color: #475467; padding: 6px 10px; font-size: 12px; font-weight: 900; cursor: pointer; }
        .nz-layout-switch button.active { background: #102a4c; color: #fff; box-shadow: 0 1px 4px rgba(16,42,76,.18); }
        .nz-detail-mode { display: none; }
        .nz-detail-mode.active { display: block; }
        .nz-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(310px, 1fr); gap: 12px; }
        .nz-grid-finance { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(360px, .9fr); gap: 12px; }
        .nz-grid-dispatch { display: grid; grid-template-columns: minmax(0, 1.45fr) minmax(360px, 1fr); gap: 12px; }
        .nz-card { background: #fff; border: 1px solid #e6eaf0; border-radius: 10px; box-shadow: 0 1px 4px rgba(16,24,40,.04); overflow: hidden; }
        .nz-card-hd { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 13px 15px; border-bottom: 1px solid #edf1f5; }
        .nz-card-hd h3 { margin: 0; color: #102a4c; font-size: 15px; line-height: 1.2; font-weight: 900; letter-spacing: 0; }
        .nz-card-bd { padding: 14px 15px; }
        .nz-stack { display: grid; gap: 12px; }
        .nz-kpis { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 10px; margin-bottom: 12px; }
        .nz-kpi { padding: 12px; border: 1px solid #e6eaf0; border-radius: 9px; background: #f8fafc; }
        .nz-kpi span { display: block; color: #667085; font-size: 12px; font-weight: 800; }
        .nz-kpi strong { display: block; margin-top: 4px; color: #102a4c; font-size: 18px; font-weight: 900; }
        .nz-badge { display: inline-flex; align-items: center; min-height: 24px; padding: 3px 9px; border-radius: 999px; font-size: 12px; font-weight: 900; white-space: nowrap; }
        .nz-badge-warn { background: #fff1d6; color: #8a5a06; }
        .nz-badge-blue { background: #eaf1ff; color: #1e4fbf; }
        .nz-badge-green { background: #dcfae6; color: #0a6b1f; }
        .nz-badge-red { background: #feebee; color: #a3121b; }
        .nz-badge-gray { background: #eef0f3; color: #475467; }
        .nz-flow { display: grid; grid-template-columns: repeat(var(--nz-flow-count, 4), minmax(0, 1fr)); gap: 8px; margin-bottom: 12px; }
        .nz-flow-step { position: relative; padding: 9px 10px; border: 1px solid #e6eaf0; border-radius: 8px; background: #fff; color: #667085; font-size: 12px; font-weight: 900; text-align: center; }
        .nz-flow-step.done { background: #f0fdf4; border-color: #bbe8cc; color: #0a6b1f; }
        .nz-flow-step.current { background: #fff7e6; border-color: #ffd58a; color: #8a5a06; box-shadow: inset 0 0 0 1px #ffd58a; }
        .nz-items { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .nz-items th { color: #667085; font-size: 12px; font-weight: 900; background: #f8fafc; padding: 10px; border-bottom: 1px solid #edf1f5; }
        .nz-items td { padding: 12px 10px; border-bottom: 1px solid #f1f4f8; vertical-align: middle; color: #102a4c; font-size: 13px; }
        .nz-item-main { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .nz-item-main img { width: 54px; height: 54px; border-radius: 8px; object-fit: cover; border: 1px solid #e6eaf0; flex: 0 0 auto; }
        .nz-item-name { font-weight: 900; color: #102a4c; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .nz-item-sub { margin-top: 3px; color: #667085; font-size: 12px; line-height: 1.4; }
        .nz-fees, .nz-info-list, .nz-ledger { display: grid; gap: 8px; }
        .nz-fee-line { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; color: #475467; font-size: 13px; }
        .nz-fee-line strong { color: #102a4c; font-size: 15px; }
        .nz-fee-total { padding-top: 10px; margin-top: 4px; border-top: 1px solid #e6eaf0; font-weight: 900; }
        .nz-info-row { display: grid; grid-template-columns: 92px minmax(0, 1fr); gap: 10px; font-size: 13px; line-height: 1.45; }
        .nz-info-row span:first-child { color: #667085; font-weight: 800; }
        .nz-info-row span:last-child { color: #102a4c; font-weight: 700; min-width: 0; overflow-wrap: anywhere; }
        .nz-callout { padding: 10px 12px; border-radius: 9px; font-size: 12.5px; line-height: 1.55; font-weight: 700; }
        .nz-callout-warn { background: #fff7e6; border: 1px solid #ffe0a3; color: #8a5a06; }
        .nz-callout-green { background: #e9f8ef; border: 1px solid #bbe8cc; color: #0f5132; }
        .nz-map-card { min-height: 180px; border: 1px solid #dde7f3; border-radius: 10px; background: linear-gradient(135deg,#eaf4ff,#f8fbff); position: relative; overflow: hidden; }
        .nz-map-card::before { content: ""; position: absolute; inset: 0; background-image: linear-gradient(rgba(31,111,208,.08) 1px, transparent 1px), linear-gradient(90deg, rgba(31,111,208,.08) 1px, transparent 1px); background-size: 34px 34px; }
        .nz-map-pin { position: absolute; left: 54%; top: 46%; transform: translate(-50%,-50%); width: 18px; height: 18px; border-radius: 50%; background: #c4193e; box-shadow: 0 0 0 7px rgba(196,25,62,.14); }
        .nz-map-label { position: absolute; left: 18px; bottom: 16px; right: 18px; padding: 9px 10px; border-radius: 8px; background: rgba(255,255,255,.9); color: #102a4c; font-size: 12px; font-weight: 900; }
        .nz-action-form { margin: 0; display: inline-flex; }
        .nz-ledger-row { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; padding: 10px 12px; border: 1px solid #eef1f5; border-radius: 8px; background: #fff; }
        .nz-ledger-row span { color: #667085; font-size: 13px; font-weight: 800; }
        .nz-ledger-row strong { color: #102a4c; font-size: 15px; font-weight: 900; }
        .nz-dispatch-steps { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .nz-dispatch-step { padding: 13px; border: 1px solid #e6eaf0; border-radius: 10px; background: #f8fafc; }
        .nz-dispatch-step b { display: block; color: #102a4c; margin-bottom: 7px; }
        .nz-dispatch-step p { margin: 0; color: #667085; font-size: 12px; line-height: 1.5; }
        .nz-legacy-toggle { margin-top: 12px; text-align: right; }
        .nz-order-legacy-print { margin-top: 10px; }
        @media (max-width: 991.98px) {
            .nz-grid, .nz-grid-finance, .nz-grid-dispatch { grid-template-columns: 1fr; }
            .nz-kpis, .nz-dispatch-steps { grid-template-columns: 1fr; }
            .nz-flow { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 575.98px) {
            .nz-detail-top { position: static; padding: 10px; }
            .nz-layout-switch { width: 100%; justify-content: space-between; }
            .nz-layout-switch button { flex: 1; }
            .nz-info-row { grid-template-columns: 78px minmax(0, 1fr); }
        }
    </style>
@endonce

@php
    $nzText = function ($html) {
        return html_entity_decode($html, ENT_QUOTES, 'UTF-8');
    };
    $nzRefundRec = \App\Models\NezhaRefundRecord::where('order_id', $order->id)->where('status', 'pending_merchant_refund')->latest('id')->first();
    $nzDetailAddress = $order->delivery_address ? (json_decode($order->delivery_address, true) ?: []) : [];
    $nzCustomerName = data_get($nzDetailAddress, 'contact_person_name') ?: trim(($order->customer?->f_name ?? '') . ' ' . ($order->customer?->l_name ?? '')) ?: 'N/A';
    $nzCustomerPhone = data_get($nzDetailAddress, 'contact_person_number') ?: ($order->customer?->phone ?? null);
    $nzCustomerEmail = data_get($nzDetailAddress, 'contact_person_email') ?: ($order->customer?->email ?? null);
    $nzDeliveryAddress = data_get($nzDetailAddress, 'address') ?: 'N/A';
    $nzDeliveryNote = data_get($nzDetailAddress, 'delivery_note') ?: ($order->order_note ?: 'N/A');
    $nzLat = data_get($nzDetailAddress, 'latitude');
    $nzLng = data_get($nzDetailAddress, 'longitude');
    $nzCopyAddress = collect([
        $nzCustomerName,
        $nzCustomerPhone,
        data_get($nzDetailAddress, 'road'),
        data_get($nzDetailAddress, 'house') ? 'House: ' . data_get($nzDetailAddress, 'house') : null,
        data_get($nzDetailAddress, 'floor') ? 'Floor: ' . data_get($nzDetailAddress, 'floor') : null,
        $nzDeliveryAddress,
        $nzDeliveryNote !== 'N/A' ? 'Note: ' . $nzDeliveryNote : null,
        ($nzLat && $nzLng) ? $nzLat . ', ' . $nzLng : null,
    ])->filter()->implode("\n");

    $nzRawStatus = $order->order_status;
    $nzBusinessStatus = $nzRefundRec ? 'pending_merchant_refund' : ($nzOffPending ? 'offline_payment_pending' : $nzRawStatus);
    $nzStatusLabels = [
        'pending' => translate('messages.pending'),
        'confirmed' => translate('messages.confirmed'),
        'accepted' => translate('messages.accepted'),
        'processing' => translate('messages.processing'),
        'handover' => translate('messages.handover'),
        'picked_up' => translate('messages.picked_up'),
        'delivered' => translate('messages.delivered'),
        'canceled' => translate('messages.canceled'),
        'failed' => translate('messages.failed'),
        'refunded' => translate('messages.refunded'),
        'refund_requested' => translate('messages.refund_requested'),
        'refund_request_canceled' => translate('messages.refund_request_canceled'),
        'pending_merchant_refund' => $nzText('&#24453;&#21830;&#23478;&#36864;&#27454;'),
        'offline_payment_pending' => $nzText('&#24453;&#30830;&#35748;&#25910;&#27454;'),
    ];
    $nzStatusLabel = $nzStatusLabels[$nzBusinessStatus] ?? translate('messages.' . $nzRawStatus);
    $nzStatusClass = in_array($nzBusinessStatus, ['delivered', 'refunded'], true) ? 'nz-badge-green' : (in_array($nzBusinessStatus, ['canceled', 'failed', 'refund_requested', 'pending_merchant_refund'], true) ? 'nz-badge-red' : (in_array($nzBusinessStatus, ['processing', 'picked_up'], true) ? 'nz-badge-blue' : 'nz-badge-warn'));
    $nzPaymentLabels = [
        'paid' => translate('messages.paid'),
        'unpaid' => translate('messages.unpaid'),
        'partially_paid' => translate('messages.partially_paid'),
        'refunded' => translate('messages.refunded'),
    ];
    $nzPaymentLabel = $nzPaymentLabels[$order->payment_status] ?? translate('messages.' . $order->payment_status);

    if ($nzRefundRec || in_array($nzRawStatus, ['canceled', 'refunded', 'refund_requested'], true)) {
        $nzFlow = [
            $nzText('&#19979;&#21333;'),
            $nzRawStatus == 'refund_requested' ? translate('messages.refund_requested') : translate('messages.canceled'),
            $nzRefundRec ? $nzText('&#24453;&#21830;&#23478;&#36864;&#27454;') : $nzText('&#36864;&#27454;&#22788;&#29702;'),
            translate('messages.refunded'),
        ];
        $nzFlowCurrent = $nzRawStatus == 'refunded' ? 3 : ($nzRefundRec ? 2 : 1);
    } elseif ($order->order_type == 'delivery') {
        $nzFlow = [
            translate('messages.pending'),
            translate('messages.confirmed'),
            translate('messages.processing'),
            translate('messages.handover'),
            translate('messages.picked_up'),
            translate('messages.delivered'),
        ];
        $nzFlowMap = ['pending' => 0, 'confirmed' => 1, 'accepted' => 1, 'processing' => 2, 'handover' => 3, 'picked_up' => 4, 'delivered' => 5];
        $nzFlowCurrent = $nzFlowMap[$nzRawStatus] ?? 0;
    } else {
        $nzFlow = [
            translate('messages.pending'),
            translate('messages.confirmed'),
            translate('messages.processing'),
            translate('messages.handover'),
            translate('messages.delivered'),
        ];
        $nzFlowMap = ['pending' => 0, 'confirmed' => 1, 'accepted' => 1, 'processing' => 2, 'handover' => 3, 'delivered' => 4];
        $nzFlowCurrent = $nzFlowMap[$nzRawStatus] ?? 0;
    }

    $nzProductPrice = 0;
    $nzAddonPrice = 0;
    $nzPreviewItems = [];
    foreach ($order->details as $detail) {
        $snap = $detail->food_details ? (json_decode($detail->food_details, true) ?: []) : [];
        $addons = $detail->add_ons ? (json_decode($detail->add_ons, true) ?: []) : [];
        $lineAmount = ((float) $detail->price) * ((int) $detail->quantity);
        $addonAmount = collect($addons)->sum(function ($addon) {
            return ((float) ($addon['price'] ?? 0)) * ((int) ($addon['quantity'] ?? 0));
        });
        $nzProductPrice += $lineAmount;
        $nzAddonPrice += $addonAmount;
        $nzPreviewItems[] = [
            'name' => data_get($snap, 'name') ?: translate('messages.item_removed'),
            'image' => data_get($snap, 'image_full_url') ?: dynamicAsset('assets/admin/img/100x100/food-default-image.png'),
            'quantity' => (int) $detail->quantity,
            'price' => $lineAmount + $addonAmount,
            'addons' => $addons,
        ];
    }
    $nzTax = $order->tax_status == 'included' ? 0 : (float) $order->total_tax_amount;
    $nzAdditionalCharge = (float) ($order->additional_charge ?? 0);
    $nzCommission = \App\CentralLogics\OrderLogic::nezha_commissionable_amount($order);
    $nzRefundAmount = $nzRefundRec ? $nzRefundRec->refund_amount : $order->order_amount;
@endphp

<div class="nz-detail-shell d-print-none" data-nz-detail-shell>
    <div class="nz-detail-top">
        <div class="nz-detail-title">
            <h2>{{ translate('messages.order') }} #{{ $order['id'] }}</h2>
            <div class="nz-detail-meta">
                <span>{{ date('d M Y ' . config('timeformat'), strtotime($order['created_at'])) }}</span>
                <span>{{ translate('messages.' . $order->order_type) }}</span>
                <span>{{ translate($order->payment_method) }}</span>
            </div>
        </div>
        <span class="nz-badge {{ $nzStatusClass }}">{{ $nzStatusLabel }}</span>
        <div class="nz-detail-amount">{{ \App\CentralLogics\Helpers::format_currency($order->order_amount) }}</div>
        <div class="nz-layout-switch" aria-label="Order detail layout">
            <button type="button" data-nz-detail-mode="workbench" class="active">&#24037;&#20316;&#21488;</button>
            <button type="button" data-nz-detail-mode="finance">&#25910;&#27454;</button>
            <button type="button" data-nz-detail-mode="dispatch">&#37197;&#36865;</button>
        </div>
        @if ($nzRefundRec)
            <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#nzDetailMarkRefunded-{{ $order['id'] }}">&#26631;&#35760;&#24050;&#36864;&#27454;</button>
        @else
            <a target="_blank" class="btn btn-outline-primary btn-sm" href="{{ route('vendor.order.generate-invoice', [$order['id']]) }}"><i class="tio-print mr-1"></i>{{ translate('messages.print_invoice') }}</a>
        @endif
    </div>

    <div class="nz-detail-mode active" data-nz-detail-panel="workbench">
        <div class="nz-grid">
            <div class="nz-stack">
                <div class="nz-kpis">
                    <div class="nz-kpi"><span>&#35746;&#21333;&#29366;&#24577;</span><strong>{{ $nzStatusLabel }}</strong></div>
                    <div class="nz-kpi"><span>&#25903;&#20184;&#29366;&#24577;</span><strong>{{ $nzPaymentLabel }}</strong></div>
                    <div class="nz-kpi"><span>&#24212;&#25910;&#37329;&#39069;</span><strong>{{ \App\CentralLogics\Helpers::format_currency($order->order_amount) }}</strong></div>
                </div>
                <div class="nz-card">
                    <div class="nz-card-hd"><h3>&#35746;&#21333;&#27969;&#36716;</h3><span class="nz-badge {{ $nzStatusClass }}">{{ $nzRawStatus }}</span></div>
                    <div class="nz-card-bd">
                        <div class="nz-flow" style="--nz-flow-count: {{ count($nzFlow) }}">
                            @foreach ($nzFlow as $idx => $step)
                                <div class="nz-flow-step {{ $idx < $nzFlowCurrent ? 'done' : ($idx == $nzFlowCurrent ? 'current' : '') }}">{{ $step }}</div>
                            @endforeach
                        </div>
                        @if ($nzOffPending)
                            <div class="nz-callout nz-callout-warn">&#36825;&#26159;&#31163;&#32447;&#25903;&#20184;&#24453;&#30830;&#35748;&#35746;&#21333;&#65292;&#20027;&#25805;&#20316;&#20173;&#28982;&#20351;&#29992;&#29616;&#26377;&#30830;&#35748;&#25910;&#27454;&#36335;&#30001;&#12290;</div>
                        @elseif ($nzRefundRec)
                            <div class="nz-callout nz-callout-warn">&#24179;&#21488;&#24050;&#35760;&#24405;&#24453;&#21830;&#23478;&#21407;&#36335;&#36864;&#27454;&#65292;&#35831;&#30830;&#35748;&#21040;&#36134;&#21518;&#20877;&#26631;&#35760;&#12290;</div>
                        @else
                            <div class="nz-callout nz-callout-green">&#29366;&#24577;&#19982;&#29616;&#26377;&#35746;&#21333;&#27969;&#36716;&#23545;&#40784;&#65292;&#19981;&#26032;&#22686;&#35746;&#21333;&#24577;&#12290;</div>
                        @endif
                    </div>
                </div>
                <div class="nz-card">
                    <div class="nz-card-hd"><h3>&#21830;&#21697;&#26126;&#32454;</h3><span class="nz-badge nz-badge-gray">{{ count($nzPreviewItems) }} &#39033;</span></div>
                    <div class="nz-card-bd p-0">
                        <table class="nz-items">
                            <thead>
                                <tr>
                                    <th style="width:55%">&#21830;&#21697;</th>
                                    <th style="width:15%;text-align:center;">&#25968;&#37327;</th>
                                    <th style="width:30%;text-align:right;">&#37329;&#39069;</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($nzPreviewItems as $item)
                                    <tr>
                                        <td>
                                            <div class="nz-item-main">
                                                <img src="{{ $item['image'] }}" alt="">
                                                <div>
                                                    <div class="nz-item-name">{{ $item['name'] }}</div>
                                                    <div class="nz-item-sub">
                                                        @if (count($item['addons']))
                                                            &#21152;&#26009;: {{ collect($item['addons'])->pluck('name')->filter()->implode(', ') }}
                                                        @else
                                                            &#26080;&#21152;&#26009;
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="text-align:center;">x{{ $item['quantity'] }}</td>
                                        <td style="text-align:right;font-weight:900;">{{ \App\CentralLogics\Helpers::format_currency($item['price']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="nz-stack">
                <div class="nz-card">
                    <div class="nz-card-hd"><h3>&#24403;&#21069;&#25805;&#20316;</h3></div>
                    <div class="nz-card-bd nz-stack">
                        @if (($nzPrimary['visible'] ?? false) && ($nzPrimary['kind'] ?? null) == 'link')
                            <a class="btn btn-success btn-sm order-status-change-alert" href="javascript:" data-url="{{ $nzPrimary['route'] }}" @foreach ($nzPrimary['data'] as $nzK => $nzV) data-{{ $nzK }}="{{ $nzV }}" @endforeach>{{ $nzPrimary['label'] }}</a>
                        @elseif (($nzPrimary['visible'] ?? false) && ($nzPrimary['kind'] ?? null) == 'form' && empty($nzPrimary['combined_yandex']))
                            <form action="{{ $nzPrimary['route'] }}" method="post" class="nz-action-form" @if ($nzPrimary['confirm']) onsubmit="return confirm('{{ $nzPrimary['confirm'] }}');" @endif>
                                @csrf
                                @method($nzPrimary['method'])
                                <button type="submit" class="btn btn-success btn-sm">{{ $nzPrimary['label'] }}</button>
                            </form>
                        @elseif (($nzPrimary['visible'] ?? false) && ($nzPrimary['kind'] ?? null) == 'form' && !empty($nzPrimary['combined_yandex']))
                            <a href="#nzDetailDispatchCard-{{ $order['id'] }}" class="btn btn-success btn-sm">&#21040;&#37197;&#36865;&#24067;&#23616;&#22788;&#29702;</a>
                        @elseif (($nzPrimary['visible'] ?? false) && ($nzPrimary['kind'] ?? null) == 'info')
                            <form action="{{ $nzPrimary['route'] }}" method="post" class="nz-action-form" onsubmit="return confirm('{{ $nzPrimary['confirm'] }}');">
                                @csrf
                                @method($nzPrimary['method'])
                                <button type="submit" class="btn btn-outline-success btn-sm">{{ $nzPrimary['label'] }}</button>
                            </form>
                        @elseif ($nzRefundRec)
                            <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#nzDetailMarkRefunded-{{ $order['id'] }}">&#26631;&#35760;&#24050;&#36864;&#27454;</button>
                        @else
                            <span class="text-muted" style="font-size:12px;font-weight:800;">&#24403;&#21069;&#29366;&#24577;&#26080;&#20027;&#25805;&#20316;</span>
                        @endif
                        <div class="nz-callout nz-callout-green">&#27492;&#22788;&#22797;&#29992;&#39029;&#38754;&#39030;&#37096;&#30340;&#29616;&#26377;&#20027;&#25805;&#20316;&#65292;&#20445;&#25345;&#29366;&#24577;&#27969;&#19968;&#33268;&#12290;</div>
                    </div>
                </div>
                <div class="nz-card">
                    <div class="nz-card-hd"><h3>&#39038;&#23458;&#20449;&#24687;</h3></div>
                    <div class="nz-card-bd nz-info-list">
                        <div class="nz-info-row"><span>&#22995;&#21517;</span><span>{{ $nzCustomerName }}</span></div>
                        <div class="nz-info-row"><span>&#30005;&#35805;</span><span>{{ $nzCustomerPhone ? \App\CentralLogics\Helpers::mask_phone($nzCustomerPhone) : 'N/A' }}</span></div>
                        <div class="nz-info-row"><span>&#37038;&#31665;</span><span>{{ $nzCustomerEmail ? \App\CentralLogics\Helpers::mask_email($nzCustomerEmail) : 'N/A' }}</span></div>
                    </div>
                </div>
                <div class="nz-card">
                    <div class="nz-card-hd"><h3>&#37197;&#36865;&#20449;&#24687;</h3></div>
                    <div class="nz-card-bd nz-info-list">
                        <div class="nz-info-row"><span>&#22320;&#22336;</span><span>{{ $nzDeliveryAddress }}</span></div>
                        <div class="nz-info-row"><span>&#22791;&#27880;</span><span>{{ $nzDeliveryNote }}</span></div>
                        <div class="d-flex flex-wrap gap-1">
                            <button type="button" class="btn btn-sm btn-outline-primary" data-nz-copy-address="{{ e($nzCopyAddress) }}">&#22797;&#21046;&#37197;&#36865;&#22320;&#22336;</button>
                            @if ($nzLat && $nzLng)
                                <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener noreferrer" href="https://yandex.com/maps/?ll={{ $nzLng }},{{ $nzLat }}&z=17&pt={{ $nzLng }},{{ $nzLat }},pm2rdm&l=map">Yandex</a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="nz-detail-mode" data-nz-detail-panel="finance">
        <div class="nz-kpis">
            <div class="nz-kpi"><span>{{ $nzRefundRec ? $nzText('&#24212;&#36864;&#37329;&#39069;') : $nzText('&#24403;&#21069;&#24212;&#25910;') }}</span><strong>{{ \App\CentralLogics\Helpers::format_currency($nzRefundRec ? $nzRefundAmount : $order->order_amount) }}</strong></div>
            <div class="nz-kpi"><span>&#25903;&#20184;&#29366;&#24577;</span><strong>{{ $nzPaymentLabel }}</strong></div>
            <div class="nz-kpi"><span>&#19994;&#21153;&#29366;&#24577;</span><strong>{{ $nzStatusLabel }}</strong></div>
        </div>
        <div class="nz-grid-finance">
            <div class="nz-card">
                <div class="nz-card-hd"><h3>&#25910;&#27454;&#19982;&#36864;&#27454;&#27969;&#27700;</h3><span class="nz-badge {{ $nzStatusClass }}">{{ $nzStatusLabel }}</span></div>
                <div class="nz-card-bd nz-ledger">
                    <div class="nz-ledger-row"><span>&#35746;&#21333;&#24635;&#39069;</span><strong>{{ \App\CentralLogics\Helpers::format_currency($order->order_amount) }}</strong></div>
                    <div class="nz-ledger-row"><span>&#39038;&#23458;&#25903;&#20184;&#29366;&#24577;</span><strong>{{ $nzPaymentLabel }}</strong></div>
                    <div class="nz-ledger-row"><span>&#25903;&#20184;&#26041;&#24335;</span><strong>{{ translate($order->payment_method) }}</strong></div>
                    <div class="nz-ledger-row"><span>&#24179;&#21488;&#20323;&#37329;</span><strong>{{ $nzCommission['subscription'] ? $nzText('&#35746;&#38405;&#21046;&#20813;&#20323;') : \App\CentralLogics\Helpers::format_currency($nzCommission['amount']) }}</strong></div>
                    <div class="nz-ledger-row"><span>{{ $nzRefundRec ? $nzText('&#24212;&#36864;&#39038;&#23458;') : $nzText('&#24453;&#22788;&#29702;&#37329;&#39069;') }}</span><strong>{{ \App\CentralLogics\Helpers::format_currency($nzRefundRec ? $nzRefundAmount : $order->order_amount) }}</strong></div>
                    @if ($nzRefundRec)
                        <div class="nz-callout nz-callout-warn">&#21407;&#20184;&#27454;&#28192;&#36947;: {{ $nzRefundRec->payment_channel === 'usdt' ? 'USDT ' . ($nzRefundRec->chain ?? '') : ($nzRefundRec->payment_channel === 'rmb' ? $nzText('&#25903;&#20184;&#23453;/&#20154;&#27665;&#24065;') : $nzText('&#35265;&#20184;&#27454;&#20973;&#35777;')) }}</div>
                        <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#nzDetailMarkRefunded-{{ $order['id'] }}">&#26631;&#35760;&#24050;&#36864;&#27454;</button>
                    @endif
                </div>
            </div>
            <div class="nz-card">
                <div class="nz-card-hd"><h3>&#39038;&#23458;&#19982;&#35746;&#21333;</h3></div>
                <div class="nz-card-bd nz-info-list">
                    <div class="nz-info-row"><span>&#39038;&#23458;</span><span>{{ $nzCustomerName }}</span></div>
                    <div class="nz-info-row"><span>&#21407;&#22987;&#29366;&#24577;</span><span>{{ $nzRawStatus }}</span></div>
                    <div class="nz-info-row"><span>&#19994;&#21153;&#29366;&#24577;</span><span>{{ $nzStatusLabel }}</span></div>
                    <div class="nz-info-row"><span>&#19979;&#21333;&#26102;&#38388;</span><span>{{ date('d M Y ' . config('timeformat'), strtotime($order['created_at'])) }}</span></div>
                    @if ($nzRefundRec)
                        <form action="{{ route('vendor.order.mark-refunded', ['id' => $order['id']]) }}" method="post" class="nz-stack" onsubmit="return confirm('{{ $nzText('&#35831;&#30830;&#35748;&#24744;&#24050;&#25353;&#21407;&#36335;&#36864;&#27454;&#65311;') }}');">
                            @csrf
                            @method('PUT')
                            <input type="text" name="merchant_refund_tx" class="form-control form-control-sm" placeholder="USDT tx hash / voucher optional">
                            <input type="text" name="merchant_note" class="form-control form-control-sm" placeholder="Merchant note optional">
                            <button type="submit" class="btn btn-success btn-sm">&#30830;&#35748;&#24050;&#25353;&#21407;&#36335;&#36864;&#27454;</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="nz-detail-mode" data-nz-detail-panel="dispatch">
        <div class="nz-grid-dispatch">
            <div class="nz-card" id="nzDetailDispatchCard-{{ $order['id'] }}">
                <div class="nz-card-hd"><h3>&#37197;&#36865;&#21483;&#36710;</h3><span class="nz-badge {{ $nzStatusClass }}">{{ $nzStatusLabel }}</span></div>
                <div class="nz-card-bd nz-stack">
                    <div class="nz-dispatch-steps">
                        <div class="nz-dispatch-step"><b>1. &#30830;&#35748;&#39038;&#23458;&#20301;&#32622;</b><p>&#22797;&#21046;&#23436;&#25972;&#37197;&#36865;&#22320;&#22336;&#65292;&#24517;&#35201;&#26102;&#25171;&#24320; Yandex &#26680;&#23545;&#12290;</p></div>
                        <div class="nz-dispatch-step"><b>2. &#21483;&#36710;&#21518;&#26356;&#26032;&#29366;&#24577;</b><p>&#21487;&#31896;&#36148; Yandex &#20998;&#20139;&#38142;&#25509;&#65292;&#20063;&#21487;&#30452;&#25509;&#26631;&#35760;&#37197;&#36865;&#20013;&#12290;</p></div>
                    </div>
                    <div class="nz-map-card">
                        <div class="nz-map-pin"></div>
                        <div class="nz-map-label">{{ $nzDeliveryAddress }}</div>
                    </div>
                    <div class="d-flex flex-wrap gap-1">
                        <button type="button" class="btn btn-sm btn-outline-primary" data-nz-copy-address="{{ e($nzCopyAddress) }}">&#22797;&#21046;&#37197;&#36865;&#22320;&#22336;</button>
                        @if ($nzLat && $nzLng)
                            <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener noreferrer" href="https://yandex.com/maps/?ll={{ $nzLng }},{{ $nzLat }}&z=17&pt={{ $nzLng }},{{ $nzLat }},pm2rdm&l=map">Yandex &#22320;&#22270;</a>
                        @endif
                    </div>
                    @if (in_array($nzRawStatus, ['processing', 'handover'], true) && $order->order_type == 'delivery')
                        <form method="post" action="{{ route('vendor.order.mark-dispatched', ['id' => $order['id']]) }}" class="nz-stack">
                            @csrf
                            @method('PUT')
                            <input type="url" name="yandex_tracking_url" value="{{ $order->yandex_tracking_url }}" placeholder="Paste Yandex tracking link optional" class="form-control form-control-sm">
                            <button type="submit" class="btn btn-success btn-sm">&#20986;&#39184; &#183; &#26631;&#35760;&#37197;&#36865;&#20013;</button>
                        </form>
                    @elseif ($nzRawStatus == 'picked_up')
                        <div class="nz-callout nz-callout-green">&#24403;&#21069;&#24050;&#26159;&#37197;&#36865;&#20013;&#12290;&#39038;&#23458;&#25910;&#21040;&#21518;&#21487;&#26631;&#35760;&#24050;&#36865;&#36798;&#12290;</div>
                    @else
                        <div class="nz-callout nz-callout-warn">&#24403;&#21069;&#35746;&#21333;&#24577;&#19981;&#26159;&#37197;&#36865;&#21483;&#36710;&#38454;&#27573;&#65292;&#20165;&#23637;&#31034;&#22320;&#22336;&#19982;&#36335;&#32447;&#12290;</div>
                    @endif
                </div>
            </div>
            <div class="nz-card">
                <div class="nz-card-hd"><h3>&#22320;&#22336;&#19982;&#36335;&#32447;</h3></div>
                <div class="nz-card-bd nz-info-list">
                    <div class="nz-info-row"><span>&#39038;&#23458;</span><span>{{ $nzCustomerName }}</span></div>
                    <div class="nz-info-row"><span>&#30005;&#35805;</span><span>{{ $nzCustomerPhone ? \App\CentralLogics\Helpers::mask_phone($nzCustomerPhone) : 'N/A' }}</span></div>
                    <div class="nz-info-row"><span>&#22320;&#22336;</span><span>{{ $nzDeliveryAddress }}</span></div>
                    <div class="nz-info-row"><span>&#22791;&#27880;</span><span>{{ $nzDeliveryNote }}</span></div>
                    <div class="nz-info-row"><span>Yandex</span><span>{{ $order->yandex_tracking_url ?: 'N/A' }}</span></div>
                </div>
            </div>
        </div>
    </div>

    <div class="nz-legacy-toggle">
        <button class="btn btn-xs btn-outline-secondary" type="button" data-toggle="collapse" data-target="#printableArea">&#26597;&#30475;&#21407;&#29256;&#35814;&#24773;</button>
    </div>
</div>

@if ($nzRefundRec)
    <div class="modal fade" id="nzDetailMarkRefunded-{{ $order['id'] }}" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <form action="{{ route('vendor.order.mark-refunded', ['id' => $order['id']]) }}" method="post" onsubmit="return confirm('{{ $nzText('&#35831;&#30830;&#35748;&#24744;&#24050;&#22312;&#33258;&#24049;&#30340;&#36134;&#25143;&#25353;&#21407;&#36335;&#36864;&#27454;&#65311;') }}');">
                    @csrf
                    @method('PUT')
                    <div class="modal-header">
                        <h5 class="modal-title">&#26631;&#35760;&#24050;&#36864;&#27454;</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>USDT tx hash / voucher optional</label>
                            <input type="text" name="merchant_refund_tx" class="form-control">
                        </div>
                        <div class="form-group mb-0">
                            <label>Merchant note optional</label>
                            <input type="text" name="merchant_note" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-dismiss="modal">{{ translate('messages.cancel') }}</button>
                        <button type="submit" class="btn btn-success">&#30830;&#35748;&#24050;&#36864;&#27454;</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif

@push('script_2')
    <script>
        (function () {
            var shell = document.querySelector('[data-nz-detail-shell]');
            if (!shell) return;
            var storageKey = 'nezha.order.detail.mode';
            var buttons = shell.querySelectorAll('[data-nz-detail-mode]');
            var panels = shell.querySelectorAll('[data-nz-detail-panel]');
            function activate(mode) {
                buttons.forEach(function (button) {
                    button.classList.toggle('active', button.getAttribute('data-nz-detail-mode') === mode);
                });
                panels.forEach(function (panel) {
                    panel.classList.toggle('active', panel.getAttribute('data-nz-detail-panel') === mode);
                });
                try { window.localStorage.setItem(storageKey, mode); } catch (e) {}
            }
            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    activate(button.getAttribute('data-nz-detail-mode'));
                });
            });
            var saved = 'workbench';
            try { saved = window.localStorage.getItem(storageKey) || 'workbench'; } catch (e) {}
            if (!shell.querySelector('[data-nz-detail-mode="' + saved + '"]')) saved = 'workbench';
            activate(saved);
            shell.querySelectorAll('[data-nz-copy-address]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var text = button.getAttribute('data-nz-copy-address') || '';
                    if (navigator.clipboard) navigator.clipboard.writeText(text);
                    button.classList.remove('btn-outline-primary');
                    button.classList.add('btn-success');
                    button.textContent = '\u5df2\u590d\u5236';
                    setTimeout(function () {
                        button.classList.add('btn-outline-primary');
                        button.classList.remove('btn-success');
                        button.textContent = '\u590d\u5236\u914d\u9001\u5730\u5740';
                    }, 1400);
                });
            });
        })();
    </script>
@endpush
