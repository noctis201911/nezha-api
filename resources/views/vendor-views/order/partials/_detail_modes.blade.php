@once
    <style>
        .item-box-page > .nz-order-statusbar { display: none !important; } /* 收口主操作到本页头部, 抑制 M-04 重复条 */
        .nzo-shell { --ink:#102A4C; --meta:#667085; --faint:#98A2B3; --line:#E6EAF0; --card:#fff;
            --amber-t:#8A5A06; --amber-b:#FFF4DE; --amber-l:#FBE3B4; --green-t:#0A6B1F; --green-b:#E7F7EE; --green-l:#BFE8CD;
            --red-t:#A3121B; --red-b:#FEECEC; --red-l:#F5CFCF; --blue-t:#1E4F9C; --blue-b:#EAF1FF; --blue-l:#CBDDF7;
            --gray-t:#5A6472; --gray-b:#F1F3F5; --gray-l:#E4E8EC; --amt-red:#E5484D; --brand:#12A150;
            color: var(--ink); margin-bottom: 16px; }
        .nzo-card { background:var(--card); border:1px solid var(--line); border-radius:14px; box-shadow:0 1px 8px rgba(20,28,44,.05); overflow:hidden; margin-bottom:12px; }
        .nzo-cb { padding:14px 16px; }
        .nzo-ch { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:12px 16px; border-bottom:1px solid var(--line); }
        .nzo-ch h3 { margin:0; font-size:15px; font-weight:700; color:var(--ink); }
        .nzo-head { display:flex; flex-wrap:wrap; align-items:flex-start; gap:12px 18px; background:var(--card); border:1px solid var(--line); border-radius:16px; box-shadow:0 1px 8px rgba(20,28,44,.05); padding:14px 18px; margin-bottom:12px; }
        .nzo-htitle { flex:1 1 240px; min-width:200px; }
        .nzo-htitle .t { font-size:20px; font-weight:800; color:var(--ink); display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .nzo-htitle .m { margin-top:7px; font-size:12.5px; color:var(--meta); display:flex; flex-wrap:wrap; gap:6px 14px; }
        .nzo-hamt { text-align:right; }
        .nzo-hamt .lbl { font-size:12px; color:var(--meta); }
        .nzo-hamt .val { font-size:23px; font-weight:800; line-height:1.15; margin-top:2px; }
        .nzo-hamt .cv { font-size:12px; color:var(--faint); margin-top:3px; }
        .nzo-hact { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .nzo-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 11px; border-radius:999px; font-size:12.5px; font-weight:700; line-height:1.6; }
        .b-amber{ background:var(--amber-b); color:var(--amber-t); } .b-green{ background:var(--green-b); color:var(--green-t); }
        .b-red{ background:var(--red-b); color:var(--red-t); } .b-blue{ background:var(--blue-b); color:var(--blue-t); } .b-gray{ background:var(--gray-b); color:var(--gray-t); }
        .nzo-btn { display:inline-flex; align-items:center; gap:6px; border:0; border-radius:9px; padding:9px 15px; font-size:13.5px; font-weight:700; cursor:pointer; text-decoration:none; white-space:nowrap; }
        .nzo-btn-primary { background:var(--brand); color:#fff; } .nzo-btn-primary:hover{ color:#fff; opacity:.94; }
        .nzo-btn-blue { background:#1F6FD0; color:#fff; } .nzo-btn-blue:hover{ color:#fff; opacity:.94; }
        .nzo-btn-ghost { background:#fff; border:1px solid #D6DBE1; color:#42505F; } .nzo-btn-ghost:hover{ background:#F7F8FA; color:#42505F; }
        .nzo-steps { display:grid; gap:8px; margin-bottom:12px; }
        .nzo-step { display:flex; align-items:center; gap:8px; padding:9px 10px; border:1px solid var(--line); border-radius:9px; background:var(--card); font-size:12.5px; font-weight:700; color:var(--faint); text-align:left; }
        .nzo-step .dot { width:18px; height:18px; border-radius:50%; flex:0 0 auto; display:flex; align-items:center; justify-content:center; font-size:11px; background:#EAEDF0; color:#98A2B3; }
        .nzo-step.done { background:var(--green-b); border-color:var(--green-l); color:var(--green-t); } .nzo-step.done .dot{ background:var(--brand); color:#fff; }
        .nzo-step.cur { background:var(--amber-b); border-color:var(--amber-l); color:var(--amber-t); } .nzo-step.cur .dot{ background:#E8910C; color:#fff; }
        .nzo-grid { display:grid; grid-template-columns:minmax(0,1.75fr) minmax(300px,1fr); gap:12px; align-items:start; }
        .nzo-row { display:grid; grid-template-columns:84px minmax(0,1fr); gap:10px; font-size:13px; padding:4px 0; }
        .nzo-row .k { color:var(--meta); } .nzo-row .v { color:var(--ink); font-weight:600; overflow-wrap:anywhere; }
        .nzo-fee { display:flex; align-items:baseline; justify-content:space-between; gap:12px; font-size:13px; padding:5px 0; color:var(--meta); }
        .nzo-fee .v { color:var(--ink); font-weight:700; } .nzo-fee.tot { border-top:1px solid var(--line); margin-top:5px; padding-top:10px; }
        .nzo-fee.tot span:first-child, .nzo-fee.tot .v { font-size:15.5px; font-weight:800; }
        .nzo-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
        .nzo-sub { border:1px solid var(--line); border-radius:10px; padding:12px; }
        .nzo-sub .st { font-size:12px; color:var(--faint); font-weight:700; margin-bottom:9px; }
        .nzo-sub .nzo-row { grid-template-columns:58px minmax(0,1fr); }
        .nzo-warn { background:var(--amber-b); color:var(--amber-t); border:1px solid var(--amber-l); border-radius:10px; padding:10px 12px; font-size:12.5px; line-height:1.6; margin-bottom:12px; display:flex; gap:7px; align-items:flex-start; }
        .nzo-tbl { width:100%; border-collapse:collapse; font-size:12.5px; }
        .nzo-tbl th { color:var(--meta); font-weight:700; text-align:left; padding:9px 10px; background:#FaFbFC; border-bottom:1px solid var(--line); }
        .nzo-tbl td { padding:10px; border-bottom:1px solid #F3F5F7; color:var(--ink); vertical-align:middle; }
        .nzo-item { display:flex; align-items:center; gap:10px; }
        .nzo-item img { width:46px; height:46px; border-radius:8px; object-fit:cover; border:1px solid var(--line); flex:0 0 auto; }
        .nzo-tl { display:grid; gap:0; }
        .nzo-tli { display:grid; grid-template-columns:16px minmax(0,1fr); gap:10px; padding-bottom:14px; position:relative; }
        .nzo-tli:not(:last-child)::before { content:""; position:absolute; left:7px; top:16px; bottom:0; width:2px; background:var(--line); }
        .nzo-tli .d { width:14px; height:14px; border-radius:50%; margin-top:2px; background:#D3D9DF; z-index:1; }
        .nzo-tli.done .d { background:var(--brand); } .nzo-tli.cur .d { background:#E8910C; }
        .nzo-tli .lb { font-size:13px; font-weight:700; color:var(--ink); } .nzo-tli .sb { font-size:11.5px; color:var(--faint); margin-top:1px; }
        .nzo-ip { width:100%; box-sizing:border-box; border:1px solid #D6DBE1; border-radius:8px; padding:8px 10px; font-size:13px; margin-top:6px; background:#fff; color:var(--ink); }
        .nzo-ck { display:flex; align-items:flex-start; gap:8px; font-size:12.5px; color:var(--ink); margin-bottom:8px; line-height:1.5; }
        .nzo-note { background:#FBFAF6; border:1px solid #EFE7D4; color:#7A6320; border-radius:8px; padding:8px 10px; font-size:11.5px; line-height:1.55; margin-top:8px; }
        .nzo-payment-review { border-color:var(--amber-l); }
        .nzo-payment-review .nzo-ch { background:var(--amber-b); border-bottom-color:var(--amber-l); }
        .nzo-proof-grid { display:grid; grid-template-columns:minmax(180px,240px) minmax(0,1fr); gap:14px; align-items:start; }
        .nzo-proof-link { display:block; border:1px solid var(--line); border-radius:10px; overflow:hidden; background:#F8FAFC; }
        .nzo-proof-link img { display:block; width:100%; height:auto; max-height:260px; object-fit:contain; background:#fff; }
        .nzo-payment-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
        .nzo-payment-actions form { margin:0; }
        .nzo-btn-danger { background:#fff; border:1px solid var(--red-l); color:var(--red-t); }
        .nzo-btn-danger:hover { background:var(--red-b); color:var(--red-t); }
        .nzo-legacy { text-align:right; margin-top:2px; }
        .nzo-legacy button { background:transparent; border:1px solid #D6DBE1; color:#6B7280; border-radius:8px; padding:5px 11px; font-size:12px; cursor:pointer; }
        .nzo-tabs { display:inline-flex; gap:4px; padding:4px; border:1px solid #D8E0EA; border-radius:10px; background:#F7F9FC; margin-bottom:12px; }
        .nzo-tabs button { border:0; border-radius:7px; background:transparent; color:#475467; padding:7px 18px; font-size:13px; font-weight:700; cursor:pointer; }
        .nzo-tabs button.on { background:#102A4C; color:#fff; box-shadow:0 1px 4px rgba(16,42,76,.18); }
        .nzo-kpis { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin-bottom:12px; }
        .nzo-kpi { padding:12px 14px; border:1px solid var(--line); border-radius:10px; background:#F8FAFC; }
        .nzo-kpi span { display:block; color:var(--meta); font-size:12px; font-weight:700; }
        .nzo-kpi strong { display:block; margin-top:4px; color:var(--ink); font-size:18px; font-weight:800; }
        @media (max-width:575.98px){
            .nzo-kpis { grid-template-columns:1fr; }
            .nzo-step { min-width:0; flex-direction:column; justify-content:center; gap:4px; padding:7px 3px; font-size:11.5px; line-height:1.25; text-align:center; overflow-wrap:anywhere; }
            .nzo-proof-grid { grid-template-columns:1fr; }
            .nzo-payment-actions, .nzo-payment-actions form, .nzo-payment-actions .nzo-btn { width:100%; }
            .nzo-payment-actions .nzo-btn { justify-content:center; min-height:44px; }
            .nzo-payment-modal .modal-dialog { margin:8px; min-height:0; align-items:flex-start; }
            .nzo-payment-modal .modal-content { max-height:calc(100vh - 16px); max-height:calc(100dvh - 16px); }
            .nzo-payment-modal .modal-body { min-height:0; overflow-y:auto; }
        }
        @media (max-width:991.98px){ .nzo-grid{ grid-template-columns:1fr; } .nzo-3{ grid-template-columns:1fr; } .nzo-head{ border-radius:14px; } .nzo-hamt{ text-align:left; } }
    </style>
@endonce

@php
    use App\CentralLogics\Helpers;
    $nzoAddr = $c_address ?: ($order->delivery_address ? (json_decode($order->delivery_address, true) ?: []) : []);
    $nzoName = data_get($nzoAddr, 'contact_person_name') ?: (trim(($order->customer->f_name ?? '') . ' ' . ($order->customer->l_name ?? '')) ?: '—');
    $nzoPhone = data_get($nzoAddr, 'contact_person_number') ?: ($order->customer->phone ?? null);
    $nzoEmail = data_get($nzoAddr, 'contact_person_email') ?: ($order->customer->email ?? null);
    $nzoOrders = $order->customer->orders_count ?? null;
    $nzoAddrText = data_get($nzoAddr, 'address') ?: '—';
    $nzoHouse = data_get($nzoAddr, 'house'); $nzoFloor = data_get($nzoAddr, 'floor'); $nzoRoad = data_get($nzoAddr, 'road');
    $nzoDeliveryNote = data_get($nzoAddr, 'delivery_note') ?: ($order->order_note ?: '');
    $nzoLat = data_get($nzoAddr, 'latitude'); $nzoLng = data_get($nzoAddr, 'longitude');
    $nzoCopy = collect([$nzoName, ($nzoPhone ? \App\CentralLogics\NezhaContactVisibility::phone($nzoPhone, $order->created_at ?? null) : null), $nzoRoad, $nzoHouse ? '门牌 ' . $nzoHouse : null, $nzoFloor ? $nzoFloor . ' 层' : null, $nzoAddrText, $nzoDeliveryNote ?: null, ($nzoLat && $nzoLng) ? $nzoLat . ',' . $nzoLng : null])->filter()->implode("\n");

    $nzoProduct = 0; $nzoAddon = 0; $nzoItems = [];
    foreach ($order->details as $nzd) {
        $nzSnap = $nzd->food_details ? (json_decode($nzd->food_details, true) ?: []) : [];
        $nzAdd = $nzd->add_ons ? (json_decode($nzd->add_ons, true) ?: []) : [];
        $nzLine = ((float) $nzd->price) * ((int) $nzd->quantity);
        $nzAdSum = collect($nzAdd)->sum(fn($a) => ((float) ($a['price'] ?? 0)) * ((int) ($a['quantity'] ?? 0)));
        $nzoProduct += $nzLine; $nzoAddon += $nzAdSum;
        $nzVar = collect(data_get($nzSnap, 'variations', []))->flatMap(fn($v) => collect(data_get($v, 'values', []))->pluck('label'))->filter()->implode(', ');
        $nzoItems[] = ['name' => data_get($nzSnap, 'name') ?: '商品已删除', 'img' => data_get($nzSnap, 'image_full_url') ?: dynamicAsset('assets/admin/img/100x100/food-default-image.png'), 'qty' => (int) $nzd->quantity, 'amt' => $nzLine + $nzAdSum, 'variation' => $nzVar, 'addons' => collect($nzAdd)->pluck('name')->filter()->implode(', ')];
    }
    $nzoTax = ($order->tax_status == 'excluded' || $order->tax_status == null) ? (float) $order->total_tax_amount : 0;
    $nzoRestDisc = (float) ($order->restaurant_discount_amount ?? 0);
    $nzoCoupon = (float) ($order->coupon_discount_amount ?? 0);
    $nzoDelivery = (float) ($order->delivery_charge ?? 0);
    $nzoComm = \App\CentralLogics\OrderLogic::nezha_commissionable_amount($order);

    $nzoRateCny = (float) (\App\Models\BusinessSetting::where('key', 'nezha_rate_cny_to_amd')->value('value') ?: 55);
    $nzoRateUsd = (float) (\App\Models\BusinessSetting::where('key', 'nezha_rate_usd_to_amd')->value('value') ?: 400);
    $nzoAmt = (float) $order->order_amount;

    $nzoRR = \App\Models\NezhaRefundRecord::where('order_id', $order->id)
        ->whereIn('status', \App\Models\NezhaRefundRecord::STATUS_CUSTOMER_VISIBLE)
        ->latest('id')->first();
    $nzoRefundFlow = $nzoRR || in_array($order->order_status, ['refunded', 'refund_requested'], true);
    $nzoRefundAmt = $nzoRR ? (float) $nzoRR->refund_amount : $nzoAmt;
    // denied 凭证争议流 R2b(dormant·开关 nezha_refund_dispute_status): 争议态/入口资格
    $nzoDisputed = $nzoRR && $nzoRR->status === 'disputed';
    $nzoRefundDone = $nzoRR && ($nzoRR->status === 'merchant_refunded' || !empty($nzoRR->merchant_refunded_at));
    $nzoDispute = $nzoDisputed ? \App\Models\NezhaRefundDispute::where('refund_record_id', $nzoRR->id)->latest('id')->first() : null;
    $nzoDisputeFlag = (int) \App\CentralLogics\Helpers::get_business_settings('nezha_refund_dispute_status') === 1;
    $nzoHasVerifiedProof = \App\Models\OfflinePayments::where('order_id', $order->id)->where('status', 'verified')->exists();
    $nzoCanDispute = $nzoDisputeFlag && $nzoRR && $nzoRR->status === 'pending_merchant_refund' && $order->payment_status !== 'paid' && !$nzoHasVerifiedProof;

    $nzoOp = $order->offline_payments;
    $nzoOpInfo = ($nzoOp && $nzoOp->payment_info) ? (is_array($nzoOp->payment_info) ? $nzoOp->payment_info : (json_decode($nzoOp->payment_info, true) ?: [])) : [];
    $nzoPayName = data_get($nzoOpInfo, 'method_name');
    $nzoChannel = $nzoRR ? ($nzoRR->payment_channel == 'usdt' ? ('USDT' . ($nzoRR->chain ? ' · ' . $nzoRR->chain : '')) : ($nzoRR->payment_channel == 'rmb' ? ($nzoPayName ? $nzoPayName . '（人民币）' : '人民币') : ($nzoPayName ?: '见付款凭证'))) : ($nzoPayName ?: translate($order->payment_method));
    $nzoProof = null;
    foreach ((array) $nzoOpInfo as $nzv) { if (is_string($nzv)) { $nzu = \App\CentralLogics\Helpers::offline_payment_proof_url($nzv); if ($nzu) { $nzoProof = $nzu; break; } } }
    $nzoPayTime = $nzoOp ? $nzoOp->created_at : $order->pending;

    $nzoFmt = fn($t) => $t ? date('Y-m-d H:i', strtotime($t)) : null;
    $nzoStatusMap = ['pending' => '待接单', 'confirmed' => '已接单', 'accepted' => '已接单', 'processing' => '备餐中', 'handover' => '待配送', 'picked_up' => '配送中', 'delivered' => '已送达', 'canceled' => '已取消', 'failed' => '已失败', 'refunded' => '已退款', 'refund_requested' => '退款申请中', 'refund_request_canceled' => '退款已撤销'];
    $nzoBizStatus = $nzoDisputed ? '退款争议核实中' : ($nzoRefundDone ? '商家已标记退款' : ($nzoRR ? '待商家退款' : ($nzOffPending ? '待确认收款' : ($nzoStatusMap[$order->order_status] ?? $order->order_status))));
    if ($nzoDisputed) { $nzoBadge = 'b-gray'; }
    elseif ($nzoRefundDone) { $nzoBadge = 'b-green'; }
    elseif ($nzoRR || in_array($order->order_status, ['refund_requested'], true)) { $nzoBadge = 'b-amber'; }
    elseif ($order->order_status == 'refunded' || $order->order_status == 'delivered') { $nzoBadge = 'b-green'; }
    elseif (in_array($order->order_status, ['canceled', 'failed', 'refund_request_canceled'], true)) { $nzoBadge = 'b-gray'; }
    elseif (in_array($order->order_status, ['processing', 'handover', 'picked_up'], true)) { $nzoBadge = 'b-blue'; }
    else { $nzoBadge = 'b-amber'; }

    $nzoAmtLbl = $nzoRefundFlow ? '应退金额' : ($nzOffPending ? '应收金额' : '订单金额');

    // 步骤条
    if ($nzoRefundFlow) {
        $nzoFlow = ['下单', $order->order_status == 'refund_requested' ? '退款申请' : '已取消', '待商家退款', '商家标记'];
        $nzoCur = $nzoRefundDone ? count($nzoFlow) : ($nzoRR ? 2 : 1);
    } elseif ($order->order_type == 'delivery') {
        $nzoFlow = ['下单', '已接单', '备餐中', '配送中', '已送达'];
        $nzoCur = ['pending' => 0, 'confirmed' => 1, 'accepted' => 1, 'processing' => 2, 'handover' => 2, 'picked_up' => 3, 'delivered' => 4][$order->order_status] ?? 0;
    } else {
        $nzoFlow = ['下单', '已接单', '备餐中', '待取餐', '已完成'];
        $nzoCur = ['pending' => 0, 'confirmed' => 1, 'accepted' => 1, 'processing' => 2, 'handover' => 3, 'delivered' => 4][$order->order_status] ?? 0;
    }
    if (!$nzoRefundFlow && $order->order_status === 'delivered') { $nzoCur = count($nzoFlow); } // 完成态：全部步骤置绿，无"当前"高亮（退款流程另算，见上）

    // 时间线
    $nzoTl = [['l' => '下单', 'sb' => $nzoFmt($order->created_at) . ' · 顾客下单成功', 'c' => 'done']];
    if ($order->confirmed) $nzoTl[] = ['l' => '商家接单', 'sb' => $nzoFmt($order->confirmed), 'c' => 'done'];
    if ($order->processing) $nzoTl[] = ['l' => '开始备餐', 'sb' => $nzoFmt($order->processing), 'c' => 'done'];
    if ($order->picked_up) $nzoTl[] = ['l' => '标记配送中', 'sb' => $nzoFmt($order->picked_up), 'c' => 'done'];
    if ($order->delivered) $nzoTl[] = ['l' => '已送达', 'sb' => $nzoFmt($order->delivered) . ' · 订单完成', 'c' => 'done'];
    if ($order->canceled) $nzoTl[] = ['l' => '顾客取消', 'sb' => $nzoFmt($order->canceled) . ' · 顾客取消订单', 'c' => 'done'];
    if ($nzoRR) {
        $nzoTl[] = ['l' => '待退款', 'sb' => $nzoFmt($nzoRR->created_at) . ' · 请商家原路退款给顾客', 'c' => $nzoRefundDone ? 'done' : 'cur'];
        if ($nzoRefundDone) $nzoTl[] = ['l' => '商家已标记退款', 'sb' => $nzoFmt($nzoRR->merchant_refunded_at ?: $nzoRR->updated_at), 'c' => 'done'];
        else $nzoTl[] = ['l' => '商家确认退款', 'sb' => '待完成 · 商家原路退款后点击「确认已退款」', 'c' => ''];
    }
@endphp

<div class="nzo-shell d-print-none" data-nzo-shell>
    {{-- ===== 头部：订单/状态/金额/主操作 ===== --}}
    <div class="nzo-head">
        <div class="nzo-htitle">
            <div class="t">订单 #{{ $order['id'] }} <span class="nzo-badge {{ $nzoBadge }}">{{ $nzoBizStatus }}</span></div>
            <div class="m">
                <span>下单 {{ $nzoFmt($order->created_at) }}</span>
                <span>{{ $order->order_type == 'delivery' ? '外卖配送' : ($order->order_type == 'take_away' ? '自取' : '堂食') }}</span>
                <span>{{ $nzoPayName ?: translate($order->payment_method) }}</span>
            </div>
        </div>
        <div class="nzo-hamt">
            <div class="lbl">{{ $nzoAmtLbl }}</div>
            <div class="val" style="{{ $nzoRefundFlow ? 'color:var(--amt-red)' : 'color:var(--ink)' }}">{{ Helpers::format_currency($nzoRefundFlow ? $nzoRefundAmt : $nzoAmt) }}</div>
            <div class="cv">≈ ¥{{ number_format(($nzoRefundFlow ? $nzoRefundAmt : $nzoAmt) / max($nzoRateCny, 0.0001), 2) }} · ≈ ${{ number_format(($nzoRefundFlow ? $nzoRefundAmt : $nzoAmt) / max($nzoRateUsd, 0.0001), 2) }}</div>
        </div>
        <div class="nzo-hact">
            @if ($nzoDisputed)
                <span class="nzo-badge b-gray" style="padding:7px 12px;display:inline-flex;align-items:center;gap:5px;"><i class="tio-time"></i> 争议审核中</span>
            @elseif ($nzoRR && $nzoRR->status === 'pending_merchant_refund')
                <button type="button" class="nzo-btn nzo-btn-primary" data-toggle="modal" data-target="#nzoMarkRefunded-{{ $order['id'] }}"><i class="tio-checkmark-circle"></i> 标记已退款</button>
            @elseif ($nzPrimary['visible'] && $nzPrimary['kind'] == 'form' && !empty($nzPrimary['combined_yandex']))
                <a href="#nzYandexCard-{{ $order['id'] }}" onclick="var c=document.getElementById('nzYandexCard-{{ $order['id'] }}');if(c){c.scrollIntoView({behavior:'smooth',block:'center'});}return false;" class="nzo-btn nzo-btn-blue">↓ {{ $nzPrimary['label'] }}</a>
            @elseif ($nzPrimary['visible'] && $nzPrimary['kind'] == 'form' && !$nzOffPending)
                <form action="{{ $nzPrimary['route'] }}" method="post" style="margin:0;" data-nz-auto-print-invoice="{{ route('vendor.order.generate-invoice', [$order['id']]) }}?nz_auto_print=1" data-nz-auto-print-action="{{ $nzOffPending ? '1' : '0' }}" @if ($nzPrimary['confirm']) data-nz-confirm-msg="{{ $nzPrimary['confirm'] }}{{ $nzScheduleLabel ? ' 本单预约送达：'.$nzScheduleLabel.'；确认后请按预约时间安排备餐和叫车。' : '' }}" @endif>
                    @csrf @method($nzPrimary['method'])
                    <button type="submit" class="nzo-btn nzo-btn-primary"><i class="tio-checkmark-circle"></i> {{ $nzPrimary['label'] }}</button>
                </form>
            @elseif ($nzPrimary['visible'] && $nzPrimary['kind'] == 'link')
                <a class="nzo-btn nzo-btn-primary order-status-change-alert" href="javascript:" data-url="{{ $nzPrimary['route'] }}" @foreach ($nzPrimary['data'] as $nzK => $nzV) data-{{ $nzK }}="{{ $nzV }}" @endforeach><i class="tio-checkmark-circle"></i> {{ $nzPrimary['label'] }}</a>
            @elseif ($nzPrimary['visible'] && $nzPrimary['kind'] == 'info')
                <form action="{{ $nzPrimary['route'] }}" method="post" style="margin:0;" onsubmit="return confirm('{{ $nzPrimary['confirm'] }}');">
                    @csrf @method($nzPrimary['method'])
                    <button type="submit" class="nzo-btn nzo-btn-primary"><i class="tio-checkmark-circle"></i> {{ $nzPrimary['label'] }}</button>
                </form>
            @endif
            <a target="_blank" class="nzo-btn nzo-btn-ghost" href="{{ route('vendor.order.generate-invoice', [$order['id']]) }}"><i class="tio-print"></i> 打印收据</a>
        </div>
    </div>

    {{-- ===== KPI 概览三格（codex 卡片风） ===== --}}
    <div class="nzo-kpis">
        <div class="nzo-kpi"><span>订单状态</span><strong>{{ $nzoBizStatus }}</strong></div>
        <div class="nzo-kpi"><span>支付状态</span><strong>{{ $nzoRefundDone ? '商家已标记退款' : ($order->payment_status == 'paid' ? '已确认收款' : ($nzoRR ? '待商家退款' : '待确认收款')) }}</strong></div>
        <div class="nzo-kpi"><span>{{ $nzoAmtLbl }}</span><strong>{{ \App\CentralLogics\Helpers::format_currency($nzoRefundFlow ? $nzoRefundAmt : $nzoAmt) }}</strong></div>
    </div>

    {{-- 顾客取消申请必须出现在当前可见的新详情区；旧右栏已被新版布局隐藏。 --}}
    @if ($order->nezha_cancel_request === 'requested' && in_array($order->order_status, ['confirmed', 'processing'], true))
        <div class="nzo-card">
            <div class="nzo-cb">
                <div class="nzo-warn" style="background:var(--red-b);color:var(--red-t);border-color:var(--red-l);">
                    <i class="tio-warning"></i>
                    <div>
                        <strong>顾客申请取消本单</strong><br>
                        顾客理由：{{ $order->nezha_cancel_request_reason ?: '（未填写）' }}
                        @if ($order->payment_method == 'offline_payment')
                            <br>若顾客已付款，同意取消后请按原路退款并在本单确认完成。
                        @endif
                    </div>
                </div>
                <div class="nzo-hact">
                    <form action="{{ route('vendor.order.cancel-request-decision', ['id' => $order['id']]) }}" method="post" data-nz-confirm="确认同意取消本单？若顾客已付款，需你按原路退还。" data-nz-confirm-danger>
                        @csrf @method('put')
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="nzo-btn nzo-btn-primary">同意取消</button>
                    </form>
                    <button type="button" class="nzo-btn nzo-btn-ghost" style="color:var(--red-t);" data-toggle="modal" data-target="#nzRejectCancelReq-{{ $order['id'] }}">拒绝（继续履约）</button>
                </div>
            </div>
        </div>
        <div class="modal fade" id="nzRejectCancelReq-{{ $order['id'] }}" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">
                <form action="{{ route('vendor.order.cancel-request-decision', ['id' => $order['id']]) }}" method="post">
                    @csrf @method('put')
                    <input type="hidden" name="action" value="reject">
                    <div class="modal-header"><h5 class="modal-title">拒绝取消申请</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div>
                    <div class="modal-body">
                        <label style="font-size:13px;color:#555;">请填写拒绝原因（会通知顾客）</label>
                        <textarea name="reason" required maxlength="500" rows="3" class="form-control" placeholder="例：已开始备餐，无法取消；如有问题请电话联系。"></textarea>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">关闭</button><button type="submit" class="btn btn--danger btn-sm">确认拒绝</button></div>
                </form>
            </div></div>
        </div>
    @elseif ($order->nezha_cancel_request === 'rejected')
        <div class="nzo-warn" style="background:var(--gray-b);color:var(--gray-t);border-color:var(--gray-l);">本单曾有顾客取消申请，你已拒绝（{{ $order->nezha_cancel_response_note }}），订单继续履约。</div>
    @endif

    {{-- ===== 视图切换（工作台=全部·默认；收款/配送=聚焦子集，商家手动切） ===== --}}
    <div class="nzo-tabs" data-nzo-tabs>
        <button type="button" data-tab="wb" class="on">工作台</button>
        <button type="button" data-tab="fin">收款</button>
        @if ($order->order_type == 'delivery' && in_array($order->order_status, ['processing', 'handover', 'picked_up'], true))<button type="button" data-tab="dis">配送</button>@endif
    </div>

    {{-- ===== 步骤条 ===== --}}
    <div class="nzo-steps" style="grid-template-columns:repeat({{ count($nzoFlow) }},1fr)">
        @foreach ($nzoFlow as $nzi => $nzstep)
            <div class="nzo-step {{ $nzi < $nzoCur ? 'done' : ($nzi == $nzoCur ? 'cur' : '') }}">
                <span class="dot">@if ($nzi < $nzoCur)<i class="tio-done"></i>@else{{ $nzi + 1 }}@endif</span>
                <span>{{ $nzstep }}</span>
            </div>
        @endforeach
    </div>

    <div class="nzo-grid">
        {{-- ===== 主区 ===== --}}
        <div>
            @if ($nzoRefundFlow)
                {{-- 退款核对 --}}
                <div class="nzo-card nzo-sec" data-tab-in="wb fin">
                    <div class="nzo-ch"><h3>退款核对</h3><span class="nzo-badge {{ $nzoBadge }}">{{ $nzoBizStatus }}</span></div>
                    <div class="nzo-cb">
                        @if ($nzoRefundDone)
                            <div class="nzo-warn" style="background:var(--green-b);color:var(--green-t);border-color:var(--green-l);"><i class="tio-checkmark-circle"></i> 已记录商家标记退款；到账情况仍以顾客支付渠道为准。</div>
                        @elseif ($nzoDisputed)
                            <div class="nzo-warn" style="background:#f3f4f6;color:#4b5563;border-color:#e5e7eb;"><i class="tio-time"></i> 该单退款争议审核中{{ $nzoDispute && $nzoDispute->opened_at ? ' · 提交于 ' . $nzoFmt($nzoDispute->opened_at) : '' }}。平台正在核实顾客是否已付款，核实期间暂停本单催办与逾期计时，裁决前暂不可退款。</div>
                        @else
                            <div class="nzo-warn"><i class="tio-warning"></i> 请确认已按原路退款给顾客，再点「确认已退款」。</div>
                        @endif
                        <div class="nzo-3">
                            <div class="nzo-sub">
                                <div class="st">顾客应退</div>
                                <div class="nzo-fee"><span>商品净价</span><span class="v">{{ Helpers::format_currency($nzoProduct) }}</span></div>
                                @if ($nzoAddon > 0)<div class="nzo-fee"><span>加料</span><span class="v">{{ Helpers::format_currency($nzoAddon) }}</span></div>@endif
                                @if ($nzoRestDisc > 0)<div class="nzo-fee"><span>店铺优惠</span><span class="v">- {{ Helpers::format_currency($nzoRestDisc) }}</span></div>@endif
                                @if ($nzoCoupon > 0)<div class="nzo-fee"><span>优惠券</span><span class="v">- {{ Helpers::format_currency($nzoCoupon) }}</span></div>@endif
                                @if ($nzoDelivery > 0)<div class="nzo-fee"><span>配送费</span><span class="v">{{ Helpers::format_currency($nzoDelivery) }}</span></div>@endif
                                {{-- 平台佣金行: 业主0718定·商家端全隐藏佣金展示; 恢复删本 Blade 注释 --}}
                                {{-- <div class="nzo-fee"><span>平台佣金 <span class="nzo-badge b-green" style="font-size:11px;padding:1px 7px;">活动期暂免</span></span><span class="v">{{ Helpers::format_currency(0) }}</span></div> --}}
                                <div class="nzo-fee tot"><span>应退金额</span><span class="v" style="color:var(--amt-red)">{{ Helpers::format_currency($nzoRefundAmt) }}</span></div>
                            </div>
                            <div class="nzo-sub">
                                <div class="st">原付款渠道</div>
                                <div class="nzo-row"><span class="k">支付方式</span><span class="v">{{ $nzoChannel }}</span></div>
                                <div class="nzo-row"><span class="k">付款时间</span><span class="v">{{ $nzoFmt($nzoPayTime) ?: '—' }}</span></div>
                                @if ($nzoProof)
                                    <div style="margin-top:8px;"><a target="_blank" rel="noopener" class="nzo-btn nzo-btn-ghost" style="padding:6px 11px;" href="{{ $nzoProof }}"><i class="tio-visible"></i> 查看付款截图</a></div>
                                @endif
                                @if ($nzoRR && $nzoRR->route_locked_note)
                                    <div class="nzo-note">🔒 {{ $nzoRR->route_locked_note }}</div>
                                @endif
                            </div>
                            <div class="nzo-sub">
                                <div class="st">商家操作</div>
                                @if ($nzoRefundDone)
                                    <div class="nzo-note" style="background:var(--green-b);color:var(--green-t);border-color:var(--green-l);">✓ 已标记原路退款并留痕，无需再次操作。</div>
                                @elseif ($nzoDisputed)
                                    <div class="nzo-note" style="background:#f3f4f6;color:#4b5563;">⏸ 争议审核中，平台裁决前暂不可退款。</div>
                                    @if ($nzoDispute && $nzoDispute->merchant_statement)
                                        <div style="font-size:12px;color:var(--meta);margin-top:8px;">您的说明：{{ $nzoDispute->merchant_statement }}</div>
                                    @endif
                                @elseif (!$nzoRR || $nzoRR->status !== 'pending_merchant_refund')
                                    <div class="nzo-note" style="background:#fff7ed;color:#9a3412;border-color:#fed7aa;">⏸ 当前退款仍在顾客安全确认或系统挂起状态，请勿复制地址、跳转钱包或发送资金。</div>
                                @else
                                    <form action="{{ route('vendor.order.mark-refunded', ['id' => $order['id']]) }}" method="post" onsubmit="return confirm('请确认：您已在自己的账户按原路退款给顾客？');">
                                        @csrf @method('PUT')
                                        <label class="nzo-ck"><input type="checkbox" required> 我已按原路向顾客完成退款</label>
                                        <div style="font-size:11.5px;color:var(--faint);">冻结退款金额</div>
                                        <div class="nzo-ip" aria-label="冻结退款金额">{{ $nzoRR && $nzoRR->payment_channel == 'usdt' && $nzoRR->refund_asset_amount_atomic !== null ? \App\CentralLogics\NezhaAtomicAmount::atomicToDecimal((string) $nzoRR->refund_asset_amount_atomic, (int) $nzoRR->asset_decimals).' USDT' : (int) $nzoRefundAmt }}</div>
                                        @if ($nzoRR && $nzoRR->payment_channel == 'usdt')
                                            <input class="nzo-ip" name="refund_tx_hash" required placeholder="公开链上退款 tx hash（必填）">
                                        @endif
                                        <input class="nzo-ip" name="note" placeholder="备注（选填）">
                                        <button type="submit" class="nzo-btn nzo-btn-primary" style="width:100%;justify-content:center;margin-top:10px;"><i class="tio-checkmark-circle"></i> 确认已按原路退款</button>
                                    </form>
                                    @if ($nzoCanDispute)
                                        <div style="margin-top:10px;text-align:center;">
                                            <a href="javascript:void(0);" data-toggle="modal" data-target="#nzoDispute-{{ $order['id'] }}" style="font-size:12px;color:var(--meta);text-decoration:underline;">核实未收到款？发起争议</a>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                {{-- 收款与退款流水 --}}
                <div class="nzo-card nzo-sec" data-tab-in="wb fin">
                    <div class="nzo-ch"><h3>收款与退款流水</h3></div>
                    <div class="nzo-cb" style="padding:0;">
                        <table class="nzo-tbl">
                            <thead><tr><th>项目</th><th>说明</th><th style="text-align:right;">金额</th><th>状态</th><th>时间</th></tr></thead>
                            <tbody>
                                <tr><td>下单金额</td><td style="color:var(--meta);">订单创建</td><td style="text-align:right;font-weight:700;">{{ Helpers::format_currency($nzoAmt) }}</td><td><span class="nzo-badge b-gray">成交</span></td><td style="color:var(--meta);">{{ $nzoFmt($order->created_at) }}</td></tr>
                                <tr><td>顾客付款</td><td style="color:var(--meta);">{{ $nzoChannel }}{{ $nzoProof ? ' · 已上传凭证' : '' }}</td><td style="text-align:right;font-weight:700;">{{ Helpers::format_currency($nzoAmt) }}</td><td><span class="nzo-badge {{ $order->payment_status == 'paid' ? 'b-green' : 'b-amber' }}">{{ $order->payment_status == 'paid' ? '已确认收款' : '待商家确认' }}</span></td><td style="color:var(--meta);">{{ $nzoFmt($nzoPayTime) ?: '—' }}</td></tr>
                                @if ($order->canceled)
                                    <tr><td>订单取消</td><td style="color:var(--meta);">顾客取消订单</td><td style="text-align:right;color:var(--amt-red);font-weight:700;">- {{ Helpers::format_currency($nzoAmt) }}</td><td><span class="nzo-badge b-gray">已取消</span></td><td style="color:var(--meta);">{{ $nzoFmt($order->canceled) }}</td></tr>
                                @endif
                                <tr><td>应退顾客</td><td style="color:var(--meta);">原路退还（{{ $nzoChannel }}）</td><td style="text-align:right;font-weight:700;">{{ Helpers::format_currency($nzoRefundAmt) }}</td><td><span class="nzo-badge {{ $nzoRefundDone ? 'b-green' : 'b-amber' }}">{{ $nzoRefundDone ? '商家已标记退款' : '待商家退款' }}</span></td><td style="color:var(--meta);">{{ $nzoRR ? $nzoFmt($nzoRR->created_at) : '—' }}</td></tr>
                                {{-- 平台佣金流水行: 业主0718定·商家端全隐藏佣金展示; 恢复删本 Blade 注释 --}}
                                {{-- <tr><td>平台佣金</td><td style="color:var(--meta);">活动期暂免收</td><td style="text-align:right;font-weight:700;">{{ Helpers::format_currency(0) }}</td><td><span class="nzo-badge b-green">暂免</span></td><td style="color:var(--meta);">—</td></tr> --}}
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="nzo-sec" data-tab-in="wb">@include('vendor-views.order.partials._nzo_items', ['nzoItems' => $nzoItems])</div>
            @elseif ($order->order_type == 'delivery' && in_array($order->order_status, ['processing', 'handover', 'picked_up'], true))
                {{-- 配送指挥台：复用已上线共享叫车工具卡(链接按钮，无地图)，与列表页叫车抽屉同源 --}}
                <div class="nzo-sec" data-tab-in="wb dis">@include('vendor-views.order.partials._dispatch_tools')</div>
                <div class="nzo-sec" data-tab-in="wb dis">@include('vendor-views.order.partials._nzo_items', ['nzoItems' => $nzoItems])</div>
                <div class="nzo-sec" data-tab-in="wb fin">@include('vendor-views.order.partials._nzo_fees')</div>
            @else
                @if ($nzOffPending)
                    <div class="nzo-card nzo-sec nzo-payment-review" data-tab-in="wb fin" data-nzo-payment-review>
                        <div class="nzo-ch">
                            <h3>付款凭证待审核</h3>
                            <span class="nzo-badge b-amber">待确认收款</span>
                        </div>
                        <div class="nzo-cb">
                            <div class="nzo-warn">
                                <i class="tio-warning"></i>
                                <span>请先在自己的收款账户核对实际到账与应收金额一致。确认后订单将进入履约；拒绝后订单保持待处理，顾客会看到原因并可重新上传凭证，不会在平台触发扣款或退款。</span>
                            </div>
                            <div class="nzo-proof-grid">
                                <div>
                                    <div style="font-size:12px;color:var(--meta);font-weight:700;margin-bottom:6px;">顾客付款截图</div>
                                    @if ($nzoProof)
                                        <a class="nzo-proof-link" href="{{ $nzoProof }}" target="_blank" rel="noopener" onclick="return nzShowProof('{{ $nzoProof }}');">
                                            <img src="{{ $nzoProof }}" alt="顾客付款凭证截图">
                                        </a>
                                        <div style="font-size:11.5px;color:var(--faint);margin-top:5px;">点击图片可放大核对</div>
                                    @else
                                        <div class="nzo-note" style="margin-top:0;">凭证图片暂不可用，请刷新后重试；在看清凭证前不要确认收款。</div>
                                    @endif
                                </div>
                                <div>
                                    <div class="nzo-row"><span class="k">付款渠道</span><span class="v">{{ $nzoChannel }}</span></div>
                                    <div class="nzo-row"><span class="k">应收金额</span><span class="v">{{ Helpers::format_currency($nzoAmt) }}</span></div>
                                    <div class="nzo-row"><span class="k">提交时间</span><span class="v">{{ $nzoFmt($nzoPayTime) ?: '—' }}</span></div>
                                    <div class="nzo-row"><span class="k">顾客备注</span><span class="v">{{ $nzoOp->customer_note ?: '—' }}</span></div>
                                    <div class="nzo-payment-actions">
                                        <form action="{{ $nzPrimary['route'] }}" method="post" data-nzo-offline-confirm
                                            data-nz-auto-print-invoice="{{ route('vendor.order.generate-invoice', [$order['id']]) }}?nz_auto_print=1"
                                            data-nz-auto-print-action="1"
                                            @if ($nzPrimary['confirm']) data-nz-confirm-msg="{{ $nzPrimary['confirm'] }}{{ $nzScheduleLabel ? ' 本单预约送达：'.$nzScheduleLabel.'；确认后请按预约时间安排备餐和叫车。' : '' }}" @endif>
                                            @csrf @method($nzPrimary['method'])
                                            <button type="submit" class="nzo-btn nzo-btn-primary"><i class="tio-checkmark-circle"></i> 确认收款</button>
                                        </form>
                                        <button type="button" class="nzo-btn nzo-btn-danger" data-nzo-offline-deny data-toggle="modal" data-target="#nzDenyOffline-{{ $order['id'] }}"><i class="tio-clear-circle"></i> 拒绝凭证</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal fade nzo-payment-modal" id="nzDenyOffline-{{ $order['id'] }}" tabindex="-1" role="dialog" aria-hidden="true" aria-describedby="nzDenyOfflineHelp-{{ $order['id'] }}">
                        <div class="modal-dialog modal-dialog-centered" role="document">
                            <div class="modal-content" style="border-radius:12px;">
                                <form action="{{ route('vendor.order.deny-offline-payment', ['id' => $order['id']]) }}" method="post" data-nzo-offline-deny-form>
                                    @csrf @method('PUT')
                                    <div class="modal-header">
                                        <h5 class="modal-title">拒绝付款凭证</h5>
                                        <button type="button" class="close" data-dismiss="modal" aria-label="关闭"><span aria-hidden="true">&times;</span></button>
                                    </div>
                                    <div class="modal-body">
                                        <p id="nzDenyOfflineHelp-{{ $order['id'] }}" style="font-size:13px;color:#555;line-height:1.65;margin-bottom:10px;">请说明无法确认收款的原因。提交后顾客会看到该原因并可重新上传凭证；订单保持待处理，不会触发平台扣款或退款。</p>
                                        <div class="nz-deny-reasons mb-2" data-target="nzDenyOfflineNote-{{ $order['id'] }}" style="display:flex;flex-wrap:wrap;gap:6px;">
                                            <button type="button" class="btn btn-sm" style="background:#FFF8F8;border:1px solid #FAD4D6;border-radius:16px;color:#C4193E;font-size:13px;padding:4px 12px;" data-reason="截图金额对不上">截图金额对不上</button>
                                            <button type="button" class="btn btn-sm" style="background:#F7F8FA;border:1px solid #E5E7EB;border-radius:16px;color:#4B5563;font-size:13px;padding:4px 12px;" data-reason="截图模糊看不清">截图模糊看不清</button>
                                            <button type="button" class="btn btn-sm" style="background:#F7F8FA;border:1px solid #E5E7EB;border-radius:16px;color:#4B5563;font-size:13px;padding:4px 12px;" data-reason="付款方式不对">付款方式不对</button>
                                            <button type="button" class="btn btn-sm" style="background:#F7F8FA;border:1px solid #E5E7EB;border-radius:16px;color:#4B5563;font-size:13px;padding:4px 12px;" data-reason="不是支付给本店">不是支付给本店</button>
                                            <button type="button" class="btn btn-sm" style="background:#F7F8FA;border:1px solid #E5E7EB;border-radius:16px;color:#4B5563;font-size:13px;padding:4px 12px;" data-reason="__other__">其他（自填）</button>
                                        </div>
                                        <label for="nzDenyOfflineNote-{{ $order['id'] }}" style="font-size:13px;color:#555;">拒绝原因（必填）</label>
                                        <textarea id="nzDenyOfflineNote-{{ $order['id'] }}" name="note" required maxlength="255" rows="3" class="form-control" style="border-radius:8px;" placeholder="请填写顾客可理解的拒绝原因，最多 255 字"></textarea>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">取消</button>
                                        <button type="submit" class="btn btn--danger btn-sm">确认拒绝凭证</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @elseif ((($nzPrimary['kind'] ?? null) == 'wait'))
                    {{-- 哪吒P1b-B 裁决①: 无凭证离线单——无主CTA·灰条等凭证 --}}
                    <div class="nzo-card nzo-sec" data-tab-in="wb fin"><div class="nzo-cb"><div style="background:#F1F3F5;border:1px solid #E4E8EC;border-radius:10px;padding:12px 14px;"><div style="font-weight:700;color:#344054;margin-bottom:4px;"><i class="tio-time"></i> 顾客尚未上传付款凭证</div><div style="font-size:12.5px;line-height:1.6;color:#5A6472;">等顾客提交凭证后，这里会出现「确认收款」按钮；若超时未传，系统会自动取消本单，无需您操作。</div></div></div></div>
                @endif
                <div class="nzo-sec" data-tab-in="wb dis">@include('vendor-views.order.partials._nzo_items', ['nzoItems' => $nzoItems])</div>
                <div class="nzo-sec" data-tab-in="wb fin">@include('vendor-views.order.partials._nzo_fees')</div>
            @endif
        </div>

        {{-- ===== 侧栏 ===== --}}
        <div>
            <div class="nzo-card">
                <div class="nzo-ch"><h3>顾客信息</h3></div>
                <div class="nzo-cb">
                    <div class="nzo-row"><span class="k">姓名</span><span class="v">{{ $nzoName }}</span></div>
                    <div class="nzo-row"><span class="k">电话</span><span class="v">{{ $nzoPhone ? \App\CentralLogics\NezhaContactVisibility::phone($nzoPhone, $order->created_at ?? null) : '—' }}</span></div>
                    <div class="nzo-row"><span class="k">邮箱</span><span class="v">{{ $nzoEmail ? \App\CentralLogics\NezhaContactVisibility::email($nzoEmail, $order->created_at ?? null) : '—' }}</span></div>
                    @if (!is_null($nzoOrders))<div class="nzo-row"><span class="k">下单次数</span><span class="v">{{ $nzoOrders }} 次</span></div>@endif
                </div>
            </div>
            <div class="nzo-card">
                <div class="nzo-ch"><h3>配送地址</h3></div>
                <div class="nzo-cb">
                    <div class="nzo-row"><span class="k">地址</span><span class="v">{{ $nzoAddrText }}</span></div>
                    @if ($nzoHouse || $nzoFloor)<div class="nzo-row"><span class="k">门牌/楼层</span><span class="v">{{ $nzoHouse ?: '—' }}{{ $nzoFloor ? ' · ' . $nzoFloor . ' 层' : '' }}</span></div>@endif
                    <div class="nzo-row"><span class="k">备注</span><span class="v">{{ $nzoDeliveryNote ?: '—' }}</span></div>
                    <div style="margin-top:8px;display:flex;gap:7px;flex-wrap:wrap;">
                        <button type="button" class="nzo-btn nzo-btn-ghost" style="padding:6px 11px;" data-nzo-copy="{{ e($nzoCopy) }}"><i class="tio-copy"></i> 复制地址</button>
                        @if ($nzoLat && $nzoLng)
                            <a class="nzo-btn nzo-btn-ghost" style="padding:6px 11px;" target="_blank" rel="noopener noreferrer" href="https://yandex.com/maps/?ll={{ $nzoLng }},{{ $nzoLat }}&z=17&pt={{ $nzoLng }},{{ $nzoLat }},pm2rdm&l=map"><i class="tio-poi"></i> 在 Yandex 看位置</a>
                        @endif
                    </div>
                </div>
            </div>
            <div class="nzo-card">
                <div class="nzo-ch"><h3>订单记录</h3></div>
                <div class="nzo-cb">
                    <div class="nzo-tl">
                        @foreach ($nzoTl as $nztl)
                            <div class="nzo-tli {{ $nztl['c'] }}"><span class="d"></span><div><div class="lb">{{ $nztl['l'] }}</div><div class="sb">{{ $nztl['sb'] }}</div></div></div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="nzo-legacy">
        <button type="button" data-toggle="collapse" data-target="#printableArea">查看/打印原始详情</button>
    </div>
</div>

@if ($nzoRR && $nzoRR->status === 'pending_merchant_refund')
    <div class="modal fade" id="nzoMarkRefunded-{{ $order['id'] }}" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">
            <form action="{{ route('vendor.order.mark-refunded', ['id' => $order['id']]) }}" method="post" onsubmit="return confirm('请确认：人民币已退原付款人；USDT 已退本单冻结地址？');">
                @csrf @method('PUT')
                <div class="modal-header"><h5 class="modal-title">标记已退款</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
                <div class="modal-body">
                    <div class="nzo-warn" style="margin-bottom:14px;"><i class="tio-warning"></i> 请先完成实际退款；USDT 只能退本单冻结地址和精确数量，再登记公开链上哈希。</div>
                    <div class="form-group"><label>冻结退款金额</label><div class="form-control">{{ $nzoRR->payment_channel == 'usdt' && $nzoRR->refund_asset_amount_atomic !== null ? \App\CentralLogics\NezhaAtomicAmount::atomicToDecimal((string) $nzoRR->refund_asset_amount_atomic, (int) $nzoRR->asset_decimals).' USDT' : (int) $nzoRefundAmt }}</div></div>
                    @if ($nzoRR->payment_channel == 'usdt')<div class="form-group"><label>公开链上退款 tx hash（必填）</label><input type="text" name="refund_tx_hash" required class="form-control"></div>@endif
                    <div class="form-group mb-0"><label>备注（选填）</label><input type="text" name="note" class="form-control" placeholder="如：已按冻结目标退款"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">取消</button><button type="submit" class="btn btn-success">确认已退款</button></div>
            </form>
        </div></div>
    </div>
@endif

@if ($nzoCanDispute)
    <div class="modal fade" id="nzoDispute-{{ $order['id'] }}" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">
            <form action="{{ route('vendor.order.refund-dispute', ['id' => $order['id']]) }}" method="post">
                @csrf @method('PUT')
                <div class="modal-header"><h5 class="modal-title">发起退款争议</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
                <div class="modal-body">
                    <div style="margin-bottom:14px;background:#f3f4f6;color:#4b5563;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;font-size:13px;line-height:1.75;">提交须知：仅当您核实顾客<b>并未实际付款</b>、系统却生成了待退款时使用。平台将核实顾客付款情况；争议审核期间，本单<b>暂停催办与逾期计时</b>。若核实顾客确已付款，您的原路退款义务将恢复。<b>本单争议只可发起一次</b>。</div>
                    <div class="form-group mb-0"><label>核实说明（必填）<span style="color:#dc3545;">*</span></label><textarea name="statement" class="form-control" rows="4" required maxlength="1000" placeholder="请说明为何认为不应退款，例如：顾客实际未付款 / 凭证不实 / 已与顾客沟通确认……"></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">取消</button><button type="submit" class="btn btn-warning">提交争议</button></div>
            </form>
        </div></div>
    </div>
@endif

@push('script_2')
    <script>
        (function () {
            var shell = document.querySelector('[data-nzo-shell]');
            var tabbar = shell && shell.querySelector('[data-nzo-tabs]');
            if (tabbar) {
                var TKEY = 'nezha.order.detail.tab';
                var secs = shell.querySelectorAll('[data-tab-in]');
                var applyTab = function (t) {
                    tabbar.querySelectorAll('button').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-tab') === t); });
                    secs.forEach(function (s) { s.style.display = (' ' + s.getAttribute('data-tab-in') + ' ').indexOf(' ' + t + ' ') >= 0 ? '' : 'none'; });
                    try { window.localStorage.setItem(TKEY, t); } catch (e) {}
                };
                tabbar.addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) applyTab(b.getAttribute('data-tab')); });
                var savedTab = 'wb';
                try { savedTab = window.localStorage.getItem(TKEY) || 'wb'; } catch (e) {}
                // 哪吒作业台深链: URL ?tab=fin/dis/wb 优先(作业台「确认收款」直落收款tab确认区, 不落页顶)
                var nzDeep = null;
                try { nzDeep = new URLSearchParams(window.location.search).get('tab'); } catch (e) {}
                if (nzDeep && tabbar.querySelector('[data-tab="' + nzDeep + '"]')) savedTab = nzDeep;
                if (!tabbar.querySelector('[data-tab="' + savedTab + '"]')) savedTab = 'wb';
                applyTab(savedTab);
                if (nzDeep) { var nzSec = shell.querySelector('[data-tab-in~="' + nzDeep + '"]'); if (nzSec) setTimeout(function(){ nzSec.scrollIntoView({behavior:'smooth',block:'start'}); }, 150); }
            }
            document.querySelectorAll('[data-nzo-copy]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var t = b.getAttribute('data-nzo-copy') || '';
                    if (navigator.clipboard) navigator.clipboard.writeText(t);
                    var old = b.innerHTML; b.innerHTML = '<i class="tio-done"></i> 已复制';
                    setTimeout(function () { b.innerHTML = old; }, 1400);
                });
            });
        })();
    </script>
@endpush
