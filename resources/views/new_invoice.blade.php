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
        @include('nz_receipt_body', ['order' => $order])
    </div>
</div>

@if(request()->query('nz_auto_print') == '1')
    <script>
        window.addEventListener('load', function(){
            setTimeout(function(){ window.print(); }, 350);
        });
    </script>
@endif
