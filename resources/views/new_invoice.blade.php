@php
    use App\CentralLogics\Helpers;
    use Carbon\Carbon;

    $deliveryAddress = $order?->delivery_address ? json_decode($order->delivery_address, true) : [];
    $customerName = $deliveryAddress['contact_person_name'] ?? trim(($order?->customer?->f_name ?? '') . ' ' . ($order?->customer?->l_name ?? ''));
    $customerPhone = $deliveryAddress['contact_person_number'] ?? ($order?->customer?->phone ?? '');
    $subTotal = 0;
    $addonTotal = 0;
    $taxTotal = $order->tax_status === 'included' ? 0 : (float) ($order['total_tax_amount'] ?? 0);
@endphp

{{-- 哪吒标准小票模板：平台固定字段/隐私规则；追加 ?nz_auto_print=1 时加载后自动调用 window.print() --}}
<style>
    .nz-receipt-shell { background:#f5f6f8; padding:18px 0 28px; }
    .nz-receipt-actions { text-align:center; margin-bottom:14px; }
    .nz-receipt-actions .btn { border-radius:7px; }
    .nz-receipt { width:80mm; max-width:100%; margin:0 auto; padding:12px 14px; background:#fff; color:#111827; font-family:"Microsoft YaHei", Arial, sans-serif; font-size:12px; line-height:1.45; box-shadow:0 2px 10px rgba(16,24,40,.08); }
    .nz-r-center { text-align:center; }
    .nz-r-title { font-size:18px; font-weight:900; margin:4px 0; }
    .nz-r-sub { font-size:11px; color:#6B7280; }
    .nz-r-line { border-top:1px dashed #9CA3AF; margin:9px 0; }
    .nz-r-row { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
    .nz-r-row + .nz-r-row { margin-top:3px; }
    .nz-r-label { color:#6B7280; white-space:nowrap; }
    .nz-r-value { text-align:right; font-weight:700; word-break:break-word; }
    .nz-r-items { width:100%; border-collapse:collapse; }
    .nz-r-items th { border-bottom:1px dashed #9CA3AF; color:#6B7280; font-weight:700; padding:4px 0; }
    .nz-r-items td { padding:5px 0; vertical-align:top; border-bottom:1px dotted #E5E7EB; }
    .nz-r-items .qty { width:28px; }
    .nz-r-items .price { width:58px; text-align:right; font-weight:700; }
    .nz-r-note { background:#F9FAFB; border:1px solid #E5E7EB; border-radius:6px; padding:6px 8px; margin-top:6px; }
    .nz-r-total { font-size:16px; font-weight:900; }
    .nz-r-privacy { font-size:10px; color:#6B7280; text-align:center; }
    @media print {
        @page { size:80mm auto; margin:0; }
        body { background:#fff !important; }
        body * { visibility:hidden; }
        #printableArea, #printableArea * { visibility:visible; }
        #printableArea { position:absolute; left:0; top:0; width:80mm; box-shadow:none; margin:0; }
        .non-printable { display:none !important; }
        .content, .container-fluid, .nz-receipt-shell { padding:0 !important; margin:0 !important; background:#fff !important; }
    }
</style>

<div class="content container-fluid nz-receipt-shell">
    <div class="nz-receipt-actions non-printable">
        <input type="button" class="btn text-white btn--primary print-Div" value="打印小票" onclick="window.print()" />
        <a href="{{ url()->previous() }}" class="btn btn-danger">{{ translate('messages.back') }}</a>
    </div>

    <div id="printableArea" class="nz-receipt">
        <div class="nz-r-center">
            <div class="nz-r-title">{{ $order?->restaurant?->name ?? '哪吒商家' }}</div>
            <div class="nz-r-sub">哪吒外卖 · 标准小票</div>
            @if($order?->restaurant?->address)
                <div class="nz-r-sub">{{ $order->restaurant->address }}</div>
            @endif
            @if($order?->restaurant?->phone)
                <div class="nz-r-sub">{{ translate('phone') }}: {{ $order->restaurant->phone }}</div>
            @endif
        </div>

        <div class="nz-r-line"></div>
        <div class="nz-r-row"><span class="nz-r-label">订单号</span><span class="nz-r-value">#{{ $order['id'] }}</span></div>
        <div class="nz-r-row"><span class="nz-r-label">下单时间</span><span class="nz-r-value">{{ Carbon::parse($order['created_at'])->format('Y-m-d H:i') }}</span></div>
        <div class="nz-r-row"><span class="nz-r-label">订单类型</span><span class="nz-r-value">{{ $order->order_type == 'delivery' ? '外卖配送' : translate(str_replace('_', ' ', $order->order_type)) }}</span></div>
        <div class="nz-r-row"><span class="nz-r-label">订单状态</span><span class="nz-r-value">{{ translate(str_replace('_', ' ', $order->order_status)) }}</span></div>
        <div class="nz-r-row"><span class="nz-r-label">支付状态</span><span class="nz-r-value">{{ $order->payment_status == 'paid' ? '已支付' : translate(str_replace('_', ' ', $order->payment_status)) }}</span></div>

        <div class="nz-r-line"></div>
        <div class="nz-r-row"><span class="nz-r-label">顾客</span><span class="nz-r-value">{{ $customerName ?: translate('messages.walk_in_customer') }}</span></div>
        @if($customerPhone)
            <div class="nz-r-row"><span class="nz-r-label">电话</span><span class="nz-r-value">{{ Helpers::mask_phone($customerPhone) }}</span></div>
        @endif
        @if(!in_array($order->order_type, ['dine_in','take_away']) && !empty($deliveryAddress['address']))
            <div class="nz-r-row"><span class="nz-r-label">配送地址</span><span class="nz-r-value">{{ $deliveryAddress['address'] }}</span></div>
        @endif

        <div class="nz-r-line"></div>
        <table class="nz-r-items">
            <thead>
                <tr>
                    <th class="qty">数</th>
                    <th>商品</th>
                    <th class="price">金额</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($order->details as $detail)
                @php
                    $food = is_string($detail->food_details) ? json_decode($detail->food_details, true) : $detail->food_details;
                    $lineAmount = (float) $detail['price'] * (int) $detail['quantity'];
                    $subTotal += $lineAmount;
                    $addons = json_decode($detail['add_ons'], true) ?: [];
                @endphp
                <tr>
                    <td class="qty">{{ $detail['quantity'] }}x</td>
                    <td>
                        <strong>{{ $food['name'] ?? ($detail->campaign['title'] ?? translate('Food_was_deleted')) }}</strong>
                        @if(count($addons) > 0)
                            @foreach($addons as $addon)
                                @php($addonTotal += ((float)($addon['price'] ?? 0) * (int)($addon['quantity'] ?? 1)))
                                <div class="nz-r-sub">+ {{ $addon['name'] ?? translate('messages.addons') }} x{{ $addon['quantity'] ?? 1 }}</div>
                            @endforeach
                        @endif
                    </td>
                    <td class="price">{{ Helpers::format_currency($lineAmount) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @if($order['order_note'] || $order['delivery_instruction'])
            <div class="nz-r-note">
                @if($order['order_note'])<div><strong>订单备注：</strong>{{ $order['order_note'] }}</div>@endif
                @if($order['delivery_instruction'])<div><strong>配送说明：</strong>{{ $order['delivery_instruction'] }}</div>@endif
            </div>
        @endif

        <div class="nz-r-line"></div>
        <div class="nz-r-row"><span class="nz-r-label">商品小计</span><span class="nz-r-value">{{ Helpers::format_currency($subTotal) }}</span></div>
        @if($addonTotal > 0)<div class="nz-r-row"><span class="nz-r-label">加料/加项</span><span class="nz-r-value">+ {{ Helpers::format_currency($addonTotal) }}</span></div>@endif
        @if($order['restaurant_discount_amount'] > 0)<div class="nz-r-row"><span class="nz-r-label">折扣</span><span class="nz-r-value">- {{ Helpers::format_currency($order['restaurant_discount_amount']) }}</span></div>@endif
        @if($order['coupon_discount_amount'] > 0)<div class="nz-r-row"><span class="nz-r-label">优惠券</span><span class="nz-r-value">- {{ Helpers::format_currency($order['coupon_discount_amount']) }}</span></div>@endif
        @if($taxTotal > 0)<div class="nz-r-row"><span class="nz-r-label">增值税/税费</span><span class="nz-r-value">+ {{ Helpers::format_currency($taxTotal) }}</span></div>@endif
        @if($order['delivery_charge'] > 0)<div class="nz-r-row"><span class="nz-r-label">配送费</span><span class="nz-r-value">+ {{ Helpers::format_currency($order['delivery_charge']) }}</span></div>@endif
        @if($order['additional_charge'] > 0)<div class="nz-r-row"><span class="nz-r-label">{{ Helpers::get_business_data('additional_charge_name') ?? translate('messages.additional_charge') }}</span><span class="nz-r-value">+ {{ Helpers::format_currency($order['additional_charge']) }}</span></div>@endif
        <div class="nz-r-row nz-r-total"><span>总计</span><span>{{ Helpers::format_currency($order['order_amount']) }}</span></div>

        <div class="nz-r-line"></div>
        <div class="nz-r-privacy">顾客电话按平台隐私规则脱敏；本小票仅用于商家履约、备餐、配送核对。</div>
        <div class="nz-r-center" style="margin-top:6px;font-weight:800;">谢谢使用哪吒外卖</div>
    </div>
</div>

@if(request()->query('nz_auto_print') == '1')
    <script>
        window.addEventListener('load', function(){
            setTimeout(function(){ window.print(); }, 350);
        });
    </script>
@endif
