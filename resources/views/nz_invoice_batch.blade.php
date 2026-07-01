@extends('layouts.vendor.app')

@section('title', '批量打印小票')

{{-- 哪吒 P2b: 批量打印 —— 选中的订单小票渲染到同一页, 票间自动分页/切纸, ?nz_auto_print=1 时加载后只调用一次 window.print()。小票正文复用 nz_receipt_body 分区(与 new_invoice 单票同源)。 --}}
@section('content')
<style>
    .nz-batch-shell { background:#f5f6f8; padding:18px 0 28px; }
    .nz-batch-actions { text-align:center; margin-bottom:14px; }
    .nz-batch-actions .btn { border-radius:7px; }
    .nz-batch-count { text-align:center; color:#6B7280; font-size:12px; margin-bottom:12px; }
    .nz-receipt { width:80mm; max-width:100%; margin:0 auto 16px; padding:12px 14px; background:#fff; color:#111827; font-family:"Microsoft YaHei", Arial, sans-serif; font-size:12px; line-height:1.45; box-shadow:0 2px 10px rgba(16,24,40,.08); }
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
        .nz-batch-print, .nz-batch-print * { visibility:visible; }
        .nz-batch-print { position:absolute; left:0; top:0; width:80mm; }
        .nz-batch-item { page-break-after: always; box-shadow:none !important; margin:0 auto !important; }
        .nz-batch-item:last-child { page-break-after: auto; }
        .non-printable { display:none !important; }
        .content, .container-fluid, .nz-batch-shell { padding:0 !important; margin:0 !important; background:#fff !important; }
    }
</style>

<div class="content container-fluid nz-batch-shell">
    <div class="nz-batch-actions non-printable">
        <input type="button" class="btn text-white btn--primary" value="打印全部（{{ count($orders) }} 张）" onclick="window.print()" />
        <a href="{{ url()->previous() }}" class="btn btn-danger">{{ translate('messages.back') }}</a>
    </div>

    @if(count($orders) === 0)
        <div class="nz-batch-count non-printable">没有可打印的订单（可能所选订单不属于本店或无权限）。</div>
    @else
        <div class="nz-batch-count non-printable">共 {{ count($orders) }} 张小票，票间自动切纸。</div>
        <div class="nz-batch-print">
            @foreach($orders as $__o)
                <div class="nz-receipt nz-batch-item">
                    @include('nz_receipt_body', ['order' => $__o])
                </div>
            @endforeach
        </div>
    @endif
</div>

@if(request()->query('nz_auto_print') == '1' && count($orders) > 0)
    <script>
        window.addEventListener('load', function(){
            setTimeout(function(){ window.print(); }, 400);
        });
    </script>
@endif
@endsection
