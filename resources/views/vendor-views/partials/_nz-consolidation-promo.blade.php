{{-- 哪吒 集运申报(阶段 A · A-1): 商家 dashboard 一次性登记提示卡。
     可关闭 · per-vendor 落库(nezha_consolidation_promos) · 不做推送/红点/角标(R3 裁决)。
     显隐由控制器 $nz_consol_promo 判定(NezhaConsolidation::shouldShowDashboardPromo: 无记录或 >90 天陈旧, 且未在本轮关闭)。 --}}
<div class="card mb-3" id="nzConsolPromo">
    <div class="card-body d-flex align-items-start" style="gap:14px;position:relative;">
        <div class="nz-consol-promo-icon"><i class="tio-cube"></i></div>
        <div class="flex-grow-1 pr-4">
            <h5 class="mb-1">{{ translate('集运需求登记') }}</h5>
            <p class="mb-3 text-muted" style="font-size:13px;line-height:1.6;">{{ translate('平台正在筹备中国→埃里温集中进货服务，花 2 分钟登记您的进货需求，货量聚齐后平台统一与货代议价，帮您降低进货成本。') }}</p>
            <div class="d-flex align-items-center flex-wrap" style="gap:10px;">
                <a href="{{ route('vendor.nezha-consolidation.index') }}" class="btn btn-sm btn--primary">{{ translate('去登记') }}</a>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="nzConsolPromoDismiss">{{ translate('暂不需要') }}</button>
            </div>
        </div>
        <button type="button" class="btn p-0 border-0 bg-transparent text-muted nz-consol-promo-x" id="nzConsolPromoDismissX" aria-label="{{ translate('关闭') }}"><i class="tio-clear"></i></button>
    </div>
</div>
<style>
    #nzConsolPromo .nz-consol-promo-icon{width:44px;height:44px;flex:0 0 auto;border-radius:10px;background:#eef0ff;color:#5b63d3;display:flex;align-items:center;justify-content:center;font-size:22px;}
    #nzConsolPromo .nz-consol-promo-x{position:absolute;top:0;right:0;line-height:1;font-size:16px;}
</style>
<script>
    (function () {
        var card = document.getElementById('nzConsolPromo');
        if (!card) return;
        var sent = false;
        function dismiss() {
            card.style.display = 'none';
            if (sent) return;
            sent = true;
            fetch('{{ route('vendor.nezha-consolidation.dismiss-promo') }}', {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json'}
            }).catch(function () {});
        }
        var b1 = document.getElementById('nzConsolPromoDismiss');
        var b2 = document.getElementById('nzConsolPromoDismissX');
        if (b1) b1.addEventListener('click', dismiss);
        if (b2) b2.addEventListener('click', dismiss);
    })();
</script>
