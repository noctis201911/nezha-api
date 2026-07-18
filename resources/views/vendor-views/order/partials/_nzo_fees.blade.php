<div class="nzo-card">
    <div class="nzo-ch"><h3>费用汇总</h3></div>
    <div class="nzo-cb">
        <div class="nzo-fee"><span>商品净价</span><span class="v">{{ \App\CentralLogics\Helpers::format_currency($nzoProduct) }}</span></div>
        @if ($nzoAddon > 0)<div class="nzo-fee"><span>加料</span><span class="v">{{ \App\CentralLogics\Helpers::format_currency($nzoAddon) }}</span></div>@endif
        @if ($nzoRestDisc > 0)<div class="nzo-fee"><span>店铺优惠</span><span class="v">- {{ \App\CentralLogics\Helpers::format_currency($nzoRestDisc) }}</span></div>@endif
        @if ($nzoCoupon > 0)<div class="nzo-fee"><span>优惠券折扣</span><span class="v">- {{ \App\CentralLogics\Helpers::format_currency($nzoCoupon) }}</span></div>@endif
        @if ($nzoTax > 0)<div class="nzo-fee"><span>税费</span><span class="v">+ {{ \App\CentralLogics\Helpers::format_currency($nzoTax) }}</span></div>@endif
        <div class="nzo-fee"><span>配送费（商家承担）</span><span class="v">{{ \App\CentralLogics\Helpers::format_currency($nzoDelivery) }}</span></div>
        {{-- 平台佣金行: 业主0718定·商家端全隐藏佣金展示; 恢复删本 Blade 注释即可 --}}
        {{-- <div class="nzo-fee"><span>平台佣金 <span class="nzo-badge b-green" style="font-size:11px;padding:1px 7px;">活动期暂免收</span></span><span class="v">{{ \App\CentralLogics\Helpers::format_currency(0) }}</span></div> --}}
        <div class="nzo-fee tot"><span>合计</span><span class="v">{{ \App\CentralLogics\Helpers::format_currency($nzoAmt) }}</span></div>
    </div>
</div>
