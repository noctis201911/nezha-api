@extends('layouts.vendor.app')
{{-- 哪吒2026-06-26: 本页订单行/操作列改用原生 PHP 块输出变量, 勿改回 Blade 行内简写(曾致编译畸形整页500); 部署侧 nzcheck-blade 编译探针兜底 --}}

@section('title',translate('messages.Order List'))

@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- 手机端(<768px)把订单表格重排为卡片, 操作按钮直接露出; PC/平板(>=768px)不命中本媒体查询, 表格原样不变 --}}
    <style>
        .nz-order-table-card { border: 1px solid #E6EAF0; border-radius: 10px; box-shadow: 0 1px 4px rgba(16,24,40,.04); overflow: hidden; }
        .nz-order-table-card .card-header { border-bottom: 1px solid #EDF1F5; background: #fff; }
        .nz-order-table-card #datatable { table-layout: fixed; min-width: 1180px; }
        .nz-order-toolbar { display: flex; align-items: center; justify-content: flex-start; gap: 12px; width: 100%; }
        .nz-export-area { flex: 0 0 auto; }
        .nz-search-area { flex: 0 1 360px; margin-left: 0; }
        .nz-search-area .input--group { width: 360px; max-width: 100%; }
        .nz-order-table-card #datatable th:nth-child(1), .nz-order-table-card #datatable td:nth-child(1) { width: 4%; }
        .nz-order-table-card #datatable th:nth-child(2), .nz-order-table-card #datatable td:nth-child(2) { width: 24%; }
        .nz-order-table-card #datatable th:nth-child(3), .nz-order-table-card #datatable td:nth-child(3) { width: 13%; }
        .nz-order-table-card #datatable th:nth-child(4), .nz-order-table-card #datatable td:nth-child(4) { width: 16%; }
        .nz-order-table-card #datatable th:nth-child(5), .nz-order-table-card #datatable td:nth-child(5) { width: 12%; }
        .nz-order-table-card #datatable th:nth-child(6), .nz-order-table-card #datatable td:nth-child(6) { width: 12%; }
        .nz-order-table-card #datatable th:nth-child(7), .nz-order-table-card #datatable td:nth-child(7) { width: 10%; }
        .nz-order-table-card #datatable th:nth-child(8), .nz-order-table-card #datatable td:nth-child(8) { width: 5%; }
        .nz-order-table-card #datatable th:nth-child(9), .nz-order-table-card #datatable td:nth-child(9) { width: 4%; }
        .nz-print-settings { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 10px 16px; border-bottom: 1px solid #EDF1F5; background: #FFF7F8; color: #7c1228; font-size: 13px; }
        .nz-print-settings label { display: inline-flex; align-items: center; gap: 6px; margin: 0; font-weight: 700; }
        .nz-print-settings input { accent-color: #C4193E; }
        .nz-print-settings .btn { border-radius: 7px; }
        .nz-order-id { font-size: 16px; font-weight: 800; color: #102A4C; }
        .nz-order-foods { font-size: 12px; color: #6B7280; max-width: 220px; white-space: normal; line-height: 1.4; margin-top: 4px; }
        .nz-order-time strong { display: block; font-size: 14px; color: #102A4C; }
        .nz-order-money { font-size: 15px; font-weight: 800; color: #102A4C; }
        .nz-order-converted-amounts { margin-top: 3px; color: #667085; font-size: 11px; line-height: 1.35; font-weight: 700; }
        .nz-order-converted-amounts span { display: block; white-space: nowrap; }
        .nz-resizable-table th { position: relative; }
        .nz-col-resizer { position: absolute; top: 0; right: -3px; width: 8px; height: 100%; cursor: col-resize; user-select: none; z-index: 3; }
        .nz-col-resizer::after { content: ""; position: absolute; top: 25%; bottom: 25%; left: 3px; width: 2px; border-radius: 2px; background: transparent; }
        .nz-col-resizer:hover::after, body.nz-col-resizing .nz-col-resizer::after { background: #9DBBE8; }
        .nz-payment-proof-list { display: flex; gap: 6px; margin-top: 7px; flex-wrap: wrap; }
        .nz-payment-proof-list--status { justify-content: center; margin-top: 0; margin-bottom: 8px; }
        .nz-payment-proof-thumb { width: 42px; height: 42px; padding: 0; border: 1px solid #D8E0EA; border-radius: 7px; background: #fff; overflow: hidden; cursor: zoom-in; }
        .nz-payment-proof-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .nz-proof-modal-img { width: 100%; max-height: 76vh; object-fit: contain; background: #0F172A; border-radius: 8px; }
        .nz-order-status-muted { color: #8A94A6; font-size: 12px; font-weight: 600; }
        .nz-step-empty { color: #98A2B3; font-size: 12px; font-weight: 700; }
        .nz-step-btn { border-radius: 7px !important; font-size: 12px !important; padding: 6px 12px !important; min-width: 86px; font-weight: 800 !important; }
        .nz-action-icon { width: 38px; height: 36px; border-radius: 7px !important; display: inline-flex; align-items: center; justify-content: center; }
        .nz-order-status-hero { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; padding: 14px 16px; margin-bottom: 12px; border: 1px solid #E6EAF0; border-radius: 10px; background: #fff; box-shadow: 0 1px 4px rgba(16,24,40,.04); }
        .nz-order-status-hero h2 { margin: 0; font-size: 21px; line-height: 1.25; font-weight: 900; color: #102A4C; letter-spacing: 0; }
        .nz-order-status-hero p { margin: 5px 0 0; color: #667085; font-size: 13px; line-height: 1.45; max-width: 680px; }
        .nz-status-count { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 24px; padding: 0 9px; margin-left: 7px; border-radius: 999px; background: #EEF0F3; color: #475467; font-size: 13px; font-weight: 900; }
        .nz-status-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        .nz-status-tabs a { display: inline-flex; align-items: center; gap: 6px; min-height: 34px; padding: 7px 11px; border: 1px solid #E6EAF0; border-radius: 8px; background: #fff; color: #344054; font-size: 12px; font-weight: 800; }
        .nz-status-tabs a.active { border-color: #102A4C; background: #EEF0F3; color: #102A4C; }
        .nz-status-tabs i { font-size: 14px; }
        .badge.nz-st-wait { background:#FFF1D6 !important; color:#8A5A06 !important; }
        .badge.nz-st-progress { background:#EAF1FF !important; color:#1E4FBF !important; }
        .badge.nz-st-done { background:#DCFAE6 !important; color:#0A6B1F !important; }
        .badge.nz-st-cancel { background:#F0F0F0 !important; color:#4B5563 !important; }
        .badge.nz-st-alert { background:#FEEBEE !important; color:#A3121B !important; }
        .nz-step-btn.btn-warning { background:#F6EBD6 !important; border-color:#E7CE9A !important; color:#8A5A06 !important; }
        .nz-status-hero-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
        .nz-status-empty-copy { color: #102A4C; font-weight: 900; }
        .nz-status-empty-help { color: #667085; font-size: 13px; margin-top: -4px; }
        .nz-done-filter { display: flex; align-items: center; flex-wrap: wrap; gap: 11px; padding: 11px 14px; margin-bottom: 12px; border: 1px solid #D8E0EA; border-radius: 10px; background: #fff; box-shadow: 0 1px 4px rgba(16,24,40,.04); }
        .nz-done-filter .nz-done-toggle { display: inline-flex; align-items: center; gap: 7px; margin: 0; font-size: 13px; font-weight: 800; color: #102A4C; cursor: pointer; }
        .nz-done-filter .nz-done-toggle input { accent-color: #102A4C; width: 16px; height: 16px; }
        .nz-done-days-wrap { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #344054; }
        .nz-done-days { width: 64px; height: 32px; text-align: center; border: 1px solid #D8E0EA; border-radius: 7px; font-size: 13px; padding: 0 6px; }
        .nz-done-days:disabled { background: #F7F8FA; color: #98A2B3; border-color: #EDF1F5; }
        .nz-done-hint { color: #667085; font-size: 12px; font-weight: 600; }
        .nz-today-rev { flex-basis: 100%; display: none; align-items: baseline; flex-wrap: wrap; gap: 4px 10px; padding-bottom: 9px; margin-bottom: 3px; border-bottom: 1px solid #F2F4F7; }
        .nz-done-filter.nz-rev-on .nz-today-rev { display: flex; }
        .nz-today-rev-label { font-size: 13px; color: #475467; font-weight: 700; }
        .nz-today-rev-label b { color: #102A4C; }
        .nz-today-rev-amt { font-size: 18px; font-weight: 900; color: #102A4C; }
        .nz-today-rev-fx { font-size: 11.5px; color: #98A2B3; font-weight: 700; }
        .nz-today-rev-note { font-size: 11px; color: #98A2B3; }
        .nz-rev-toggle { display: inline-flex; align-items: center; gap: 6px; margin: 0 0 0 auto; font-size: 12.5px; color: #667085; font-weight: 700; cursor: pointer; }
        .nz-rev-toggle input { accent-color: #102A4C; width: 15px; height: 15px; }
        .nz-done-filter.nz-rev-on .nz-rev-toggle { color: #102A4C; }
        .nz-step-btn.nz-dispatch-open { background:#1F6FD0 !important; border-color:#1F6FD0 !important; color:#fff !important; }
        .nz-step-btn.nz-dispatch-open:hover { background:#1A5FB4 !important; border-color:#1A5FB4 !important; }
        body.nz-dispatch-lock { overflow: hidden; }
        .nz-dispatch-drawer { position: fixed; inset: 0; z-index: 11050; display: none; }
        .nz-dispatch-drawer.nz-open { display: block; }
        .nz-dispatch-backdrop { position: absolute; inset: 0; background: rgba(16,24,40,.45); }
        .nz-dispatch-sheet { position: absolute; left: 0; right: 0; bottom: 0; background: #fff; border-radius: 16px 16px 0 0; max-height: 88vh; overflow-y: auto; box-shadow: 0 -4px 24px rgba(16,24,40,.18); }
        .nz-dispatch-grip { width: 38px; height: 4px; border-radius: 99px; background: #D8DEE7; margin: 8px auto 2px; }
        .nz-dispatch-head { position: sticky; top: 0; background: #fff; display: flex; align-items: center; justify-content: space-between; padding: 6px 16px 12px; border-bottom: 1px solid #EEF0F3; z-index: 1; }
        .nz-dispatch-title { font-weight: 800; font-size: 15px; color: #17191D; }
        .nz-dispatch-x { border: 0; background: transparent; font-size: 24px; line-height: 1; color: #8A9099; cursor: pointer; padding: 0 4px; }
        .nz-dispatch-body { padding: 4px 16px 20px; }
        @media (min-width: 768px) {
            .nz-dispatch-sheet { left: 50%; top: 50%; right: auto; bottom: auto; transform: translate(-50%, -50%); width: 460px; max-width: 92vw; border-radius: 16px; max-height: 84vh; }
            .nz-dispatch-grip { display: none; }
        }
        .nz-mobile-print-toggle, .nz-order-mobile-amount, .nz-mobile-action-label { display: none; }
        /* 哪吒 P2: 行末「⋯」更多菜单 + 拒接本单弹窗 */
        .nz-row-more-btn { display:inline-flex; align-items:center; justify-content:center; border:1px solid #D8E0EA; background:#fff; border-radius:6px; min-width:32px; height:32px; line-height:1; font-size:17px; font-weight:900; color:#475467; cursor:pointer; padding:0 6px; }
        .nz-row-more-btn:hover { background:#F4F6F9; border-color:#C3CDDB; }
        .nz-row-menu { position:fixed; z-index:11050; min-width:184px; background:#fff; border:1px solid #E4E9F0; border-radius:10px; box-shadow:0 12px 30px rgba(20,22,40,.16); padding:6px; display:none; }
        .nz-row-menu.nz-open { display:block; }
        .nz-row-menu a, .nz-row-menu button { display:flex; align-items:center; gap:9px; width:100%; text-align:left; padding:10px 11px; font-size:13.5px; font-weight:700; color:#1F2329; border-radius:7px; text-decoration:none; background:none; border:0; cursor:pointer; box-sizing:border-box; }
        .nz-row-menu a:hover, .nz-row-menu button:hover { background:#F4F6F9; }
        .nz-row-menu .nz-menu-div { height:1px; background:#F0F2F5; margin:4px 6px; }
        .nz-row-menu .nz-menu-reject { color:#C0392B; }
        .nz-reject-modal { position:fixed; inset:0; z-index:11060; display:none; align-items:center; justify-content:center; background:rgba(16,24,40,.45); padding:16px; }
        .nz-reject-modal.nz-open { display:flex; }
        .nz-reject-card { width:100%; max-width:440px; background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,.3); }
        .nz-reject-head { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid #F0F2F5; }
        .nz-reject-head h5 { margin:0; font-size:15px; }
        .nz-reject-body { padding:16px 18px; }
        .nz-reject-body label { font-size:13px; color:#555; display:block; margin-bottom:6px; }
        .nz-reject-body textarea { width:100%; box-sizing:border-box; border:1px solid #D8E0EA; border-radius:9px; padding:9px 11px; font-size:13.5px; min-height:78px; font-family:inherit; }
        .nz-reject-foot { display:flex; justify-content:flex-end; gap:8px; padding:12px 18px; border-top:1px solid #F0F2F5; }
        .nz-reject-x { background:none; border:0; font-size:22px; line-height:1; color:#98A2B3; cursor:pointer; }
        @media (max-width:576px){ .nz-reject-modal { align-items:flex-end; padding:0; } .nz-reject-card { max-width:none; border-radius:16px 16px 0 0; } }
        @media (max-width: 767.98px) {
            .content.container-fluid { padding-left: 10px; padding-right: 10px; }
            .page-header { margin-bottom: 6px; }
            .nz-done-filter { gap: 8px 11px; padding: 10px 12px; margin-bottom: 8px; }
            .nz-done-hint { flex-basis: 100%; }
            .nz-order-status-hero { display: block; padding: 11px 12px; margin-bottom: 8px; border-radius: 9px; }
            .nz-order-status-hero h2 { font-size: 18px; }
            .nz-order-status-hero p { font-size: 12px; line-height: 1.35; margin-top: 4px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
            .nz-status-hero-actions { justify-content: flex-start; margin-top: 9px; }
            .nz-status-hero-actions .btn { min-height: 34px; padding-left: 12px; padding-right: 12px; }
            .nz-mobile-status-strip { flex-wrap: nowrap; overflow-x: auto; padding: 0 1px 6px; margin: 0 -1px 8px; scrollbar-width: none; }
            .nz-mobile-status-strip::-webkit-scrollbar { display: none; }
            .nz-mobile-status-strip a { flex: 0 0 auto; min-height: 36px; padding: 8px 11px; border-radius: 7px; }
            .nz-order-table-card { border-radius: 9px; overflow: visible; }
            .nz-mobile-toolbar { padding: 10px 10px 8px !important; }
            .nz-mobile-toolbar .search--button-wrapper, .nz-mobile-toolbar .nz-order-toolbar { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 8px; align-items: start; width: 100%; }
            .nz-mobile-toolbar form, .nz-search-area { min-width: 0; }
            .nz-search-area { margin-left: 0; }
            .nz-mobile-toolbar .input--group { width: 100%; }
            .nz-mobile-toolbar .hs-unfold { margin-right: 0 !important; }
            .nz-mobile-toolbar .hs-unfold > .btn { min-height: 42px; border-radius: 8px; }
            .nz-print-settings { display: block; padding: 8px 10px; }
            .nz-mobile-print-toggle { display: flex; align-items: center; justify-content: space-between; gap: 8px; width: 100%; min-height: 38px; padding: 0; border: 0; background: transparent; color: #7c1228; font-weight: 900; text-align: left; }
            .nz-mobile-print-toggle .nz-mobile-print-state { margin-left: auto; color: #667085; font-size: 12px; font-weight: 800; }
            .nz-print-settings:not(.nz-print-open) .nz-print-title,
            .nz-print-settings:not(.nz-print-open) label,
            .nz-print-settings:not(.nz-print-open) #nzTestPrintBtn,
            .nz-print-settings:not(.nz-print-open) > .text-muted { display: none; }
            .nz-print-settings.nz-print-open { display: flex; align-items: center; gap: 8px; padding-bottom: 10px; }
            .nz-print-settings.nz-print-open .nz-mobile-print-toggle { flex-basis: 100%; }
            .nz-print-settings.nz-print-open label { min-height: 34px; }
            .nz-order-table-card #datatable { min-width: 0 !important; }
            #datatable thead { display: none; }
            #datatable, #datatable tbody { display: block; width: 100%; }
            #datatable tr.class-all {
                display: block;
                background: #fff;
                border: 1px solid #eef0f3;
                border-radius: 10px;
                box-shadow: 0 1px 4px rgba(0,0,0,.04);
                margin-bottom: 10px;
                padding: 0 12px;
            }
            #datatable tr.class-all td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 10px;
                width: 100%;
                border: 0;
                border-bottom: 1px solid #f3f4f6;
                padding: 8px 0;
                text-align: right;
                white-space: normal;
                font-size: 13px;
            }
            #datatable tr.class-all td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #8a94a6;
                text-align: left;
                flex: 0 0 72px;
                white-space: nowrap;
            }
            #datatable tr.class-all td:first-child { display: none; }      /* 序号在卡片里无意义, 隐藏 */
            #datatable tr.class-all td:last-child { border-bottom: 0; }
            #datatable tr.class-all td > * { text-align: right; }
            #datatable tr.class-all td[data-label="订单"] { display: block; padding: 12px 0 10px; }
            #datatable tr.class-all td[data-label="订单"]::before { display: none; }
            .nz-order-primary-line { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
            .nz-order-id { font-size: 18px; line-height: 1.15; }
            .nz-order-mobile-amount { display: block; flex: 0 0 auto; text-align: right; font-size: 15px; font-weight: 900; color: #102A4C; }
            .nz-order-mobile-amount small { display: block; margin-top: 2px; font-size: 12px; font-weight: 900; }
            .nz-order-foods { max-width: none; margin-top: 5px; }
            .nz-order-time strong { display: inline; margin-right: 8px; }
            .nz-order-time { white-space: nowrap !important; }
            .nz-order-time strong, .nz-order-time .text-muted { white-space: nowrap !important; }
            .nz-order-time .text-muted { display: inline !important; }
            .nz-order-amount-cell { display: none !important; }
            #datatable tr.class-all td .btn--container { justify-content: flex-end; }
            #datatable tr.class-all td.nz-order-status-cell { align-items: flex-start; }
            #datatable tr.class-all td.nz-order-status-cell > * { max-width: 70%; }
            #datatable tr.class-all td.nz-order-status-cell .badge,
            #datatable tr.class-all td.nz-order-status-cell .text-capitalze { white-space: nowrap; }
            #datatable tr.class-all td.nz-order-mobile-actions { display: block; padding: 10px 0 8px; }
            #datatable tr.class-all td.nz-order-mobile-actions::before { display: block; margin-bottom: 8px; }
            #datatable tr.class-all td.nz-order-mobile-actions form,
            #datatable tr.class-all td.nz-order-mobile-actions .nz-step-btn { width: 100%; }
            #datatable tr.class-all td.nz-order-mobile-actions .nz-step-btn { min-height: 42px; display: inline-flex; align-items: center; justify-content: center; }
            #datatable tr.class-all td.nz-row-more-cell { display: block !important; width: 100% !important; padding: 4px 0 10px; border-bottom: 0; }
            #datatable tr.class-all td.nz-row-more-cell::before { display: none; }
            #datatable tr.class-all td.nz-row-more-cell .nz-row-more-btn { width: 100%; height: 44px; font-size: 15px; }
            #datatable tr.class-all td .action-btn { display: inline-flex !important; align-items: center; width: 100%; height: 42px; gap: 6px; font-size: 13px; font-weight: 800; justify-content: center !important; }  /* 加大点击热区 */
            .nz-mobile-action-label { display: inline; }
        }
    </style>
@endpush

@section('content')
@php
    $nzRawStatus = $st ?? 'all';
    $nzStatusMeta = [
        'all' => ['label' => '全部订单', 'hint' => '集中查看当前仍需履约或复核的订单，按下一步操作推进。', 'empty' => '当前没有需要处理的订单。', 'icon' => 'tio-shopping-cart'],
        'customer_nudged' => ['label' => '客户催促', 'hint' => '集中处理顾客已催促、且商家还没完成对应动作的订单。', 'empty' => '暂无客户催促订单。', 'icon' => 'tio-notifications-alert'],
        'offline_pending' => ['label' => '待确认收款', 'hint' => '顾客已提交直付凭证，商家确认自己账户已到账后再出餐。', 'empty' => '暂无待确认收款订单。', 'icon' => 'tio-checkmark-circle'],
        'refund_pending' => ['label' => '待退款', 'hint' => '平台不经手货款；请商家按原路退还顾客后在此标记已退款。', 'empty' => '暂无待退款订单。', 'icon' => 'tio-receipt-outlined'],
        'pending' => ['label' => '待处理', 'hint' => '新订单在这里接单；直付待核验订单请优先到待确认收款处理。', 'empty' => '暂无待处理订单。', 'icon' => 'tio-timer'],
        'confirmed' => ['label' => '已接单', 'hint' => '已确认的订单请尽快开始备餐，避免超时影响体验。', 'empty' => '暂无已接单订单。', 'icon' => 'tio-checkmark-circle-outlined'],
        'cooking' => ['label' => '备餐中', 'hint' => '备餐完成后标记配送中，顾客侧会同步看到进度。', 'empty' => '暂无备餐中订单。', 'icon' => 'tio-restaurant'],
        'ready_for_delivery' => ['label' => '待配送', 'hint' => '已出餐、等待配送流转的订单会显示在这里。', 'empty' => '暂无待配送订单。', 'icon' => 'tio-directions'],
        'food_on_the_way' => ['label' => '配送中', 'hint' => '配送中的订单送达后请及时完成，减少顾客等待不确定性。', 'empty' => '暂无配送中订单。', 'icon' => 'tio-send'],
        'delivered' => ['label' => '已送达', 'hint' => '已完成订单用于核对履约结果，可查看详情或补打小票。', 'empty' => '暂无已送达订单。', 'icon' => 'tio-done-all'],
        'refunded' => ['label' => '已退款', 'hint' => '已关闭的退款订单仅供核对记录。', 'empty' => '暂无已退款订单。', 'icon' => 'tio-receipt-outlined'],
        'refund_requested' => ['label' => '退款申请中', 'hint' => '顾客发起退款申请的订单，请进入详情核对原因和凭证后处理。', 'empty' => '暂无退款申请中的订单。', 'icon' => 'tio-help-outlined'],
        'scheduled' => ['label' => '已预订', 'hint' => '预约订单按预约时间履约，接近出餐时再推进状态。', 'empty' => '暂无预约订单。', 'icon' => 'tio-calendar-month'],
        'payment_failed' => ['label' => '支付失败', 'hint' => '支付失败订单已关闭，通常无需商家继续履约。', 'empty' => '暂无支付失败订单。', 'icon' => 'tio-warning-outlined'],
        'canceled' => ['label' => '已取消', 'hint' => '已取消订单用于核对取消原因和退款留痕。', 'empty' => '暂无已取消订单。', 'icon' => 'tio-clear-circle-outlined'],
    ];
    $nzStatusTabs = ['all','customer_nudged','offline_pending','refund_pending','pending','confirmed','cooking','ready_for_delivery','food_on_the_way','delivered','refunded','refund_requested','scheduled','payment_failed','canceled'];
    $nzCurrentMeta = $nzStatusMeta[$nzRawStatus] ?? ['label' => str_replace('_', ' ', $nzRawStatus), 'hint' => '查看该状态下的订单。', 'empty' => '暂无该状态订单。', 'icon' => 'tio-shopping-cart'];
    $nzBaseCurrency = \App\CentralLogics\Helpers::currency_code();
    $nzBusinessRates = \Illuminate\Support\Facades\DB::table('business_settings')
        ->whereIn('key', ['nezha_rate_cny_to_amd', 'nezha_rate_usd_to_amd'])
        ->pluck('value', 'key');
    $nzCnyToAmd = (float) ($nzBusinessRates['nezha_rate_cny_to_amd'] ?? 55);
    $nzUsdToAmd = (float) ($nzBusinessRates['nezha_rate_usd_to_amd'] ?? 400);
    $nzFxTargets = [
        'CNY' => ['divisor' => $nzCnyToAmd, 'symbol' => '¥'],
        'USD' => ['divisor' => $nzUsdToAmd, 'symbol' => '$'],
    ];
    $nzFxTargets = array_filter($nzFxTargets, function ($fx) {
        return ($fx['divisor'] ?? 0) > 0;
    });
@endphp
    <div class="content container-fluid">
        <!-- Page Header -->
        <div class="page-header pt-0 pb-2">
            <div class="nz-order-status-hero">
                <div>
                    <h2>
                        <i class="{{ $nzCurrentMeta['icon'] }} mr-1"></i>{{ $nzCurrentMeta['label'] }}
                        <span class="nz-status-count">{{$orders->total()}}</span>
                    </h2>
                    <p>{{ $nzCurrentMeta['hint'] }}</p>
                </div>
                <div class="nz-status-hero-actions">
                    <a class="btn btn-sm btn-white" href="{{route('vendor.order.list',['all'])}}">
                        <i class="tio-refresh mr-1"></i>全部
                    </a>
                    <a class="btn btn-sm btn--primary" href="{{route('vendor.order.list',['offline_pending'])}}">
                        <i class="tio-checkmark-circle mr-1"></i>收款
                    </a>
                </div>
            </div>
            <div class="nz-status-tabs nz-mobile-status-strip d-print-none">
                @foreach($nzStatusTabs as $__statusKey)
                    <a href="{{route('vendor.order.list',[$__statusKey])}}" class="{{ $nzRawStatus === $__statusKey ? 'active' : '' }}">
                        <i class="{{ $nzStatusMeta[$__statusKey]['icon'] ?? 'tio-circle' }}"></i>
                        <span>{{ $nzStatusMeta[$__statusKey]['label'] ?? $__statusKey }}</span>
                    </a>
                @endforeach
            </div>
        </div>
        <!-- End Page Header -->


        <!-- End Page Header -->

        {{-- 哪吒 P5: 「显示已完成·近N天」筛选控件, 只在「全部」页出现。默认显示全部; 关掉只留进行中;
             设天数只保留近N天已关闭单。服务端筛选(见 OrderController@list 的 all 分支), localStorage 本机记住。 --}}
        @if($nzRawStatus === 'all')
        <div class="nz-done-filter d-print-none" id="nzDoneFilter">
            @if($nzToday)
            {{-- 哪吒 P6: 今日营收(今日单数 + 已确认到账), 默认关(遮屏隐私·商家自己勾), 本机记住; 数字与首屏今日经营卡同源。 --}}
            <div class="nz-today-rev" id="nzTodayRev">
                <span class="nz-today-rev-label">今日 <b>{{ $nzToday['orders'] }}</b> 单 · 已确认到账</span>
                <span class="nz-today-rev-amt">֏{{ number_format($nzToday['collected']) }}</span>
                <span class="nz-today-rev-fx">≈¥{{ number_format($nzToday['collected'] / max($nzCnyToAmd, 1)) }} · ≈${{ number_format($nzToday['collected'] / max($nzUsdToAmd, 1)) }}</span>
                <span class="nz-today-rev-note">商家自有订单额 · 可在右侧关闭</span>
            </div>
            @endif
            <label class="nz-done-toggle">
                <input type="checkbox" id="nzDoneShow">
                显示已完成订单
            </label>
            <span class="nz-done-days-wrap">
                近
                <input type="number" min="1" max="365" inputmode="numeric" id="nzDoneDays" class="nz-done-days" placeholder="不限">
                天
            </span>
            <span class="nz-done-hint" id="nzDoneHint">默认显示全部（含已送达、已取消、已退款等历史单）</span>
            @if($nzToday)
            <label class="nz-rev-toggle" title="只你自己看得到、本机记住；显示的是「已确认收到款」的今日合计">
                <input type="checkbox" id="nzTodayRevToggle">
                今日营收
            </label>
            @endif
        </div>
        @endif

        <!-- Card -->
        <div class="card nz-order-table-card">
            <!-- Header -->
            <div class="card-header py-2 nz-mobile-toolbar">
                <div class="search--button-wrapper nz-order-toolbar max-sm-flex-100">
                    <div class="d-sm-flex align-items-sm-center m-0 nz-export-area">
                        <!-- Unfold -->
                        <div class="hs-unfold mr-2">
                            <a class="js-hs-unfold-invoker btn btn-sm btn-white dropdown-toggle" href="javascript:;"
                                data-hs-unfold-options='{
                                    "target": "#usersExportDropdown",
                                    "type": "css-animation"
                                }'>
                                <i class="tio-download-to mr-1"></i> {{translate('messages.export')}}
                            </a>

                            <div id="usersExportDropdown"
                                    class="hs-unfold-content dropdown-unfold dropdown-menu dropdown-menu-sm-right">

                                <span
                                    class="dropdown-header">{{translate('messages.download_options')}}</span>
                                <a id="export-excel" class="dropdown-item" href="{{route("vendor.order.export",['status'=>$st,'type'=>'excel',request()->getQueryString() ])}}">
                                    <img class="avatar avatar-xss avatar-4by3 mr-2"
                                            src="{{dynamicAsset('assets/admin')}}/svg/components/excel.svg"
                                            alt="Image Description">
                                    {{translate('messages.excel')}}
                                </a>
                                <a id="export-csv" class="dropdown-item" href="{{route("vendor.order.export",['status'=>$st,'type'=>'csv',request()->getQueryString() ])}}">
                                    <img class="avatar avatar-xss avatar-4by3 mr-2"
                                            src="{{dynamicAsset('assets/admin')}}/svg/components/placeholder-csv-format.svg"
                                            alt="Image Description">
                                    {{translate('messages.csv')}}
                                </a>

                            </div>
                        </div>
                    </div>
                    <form class="nz-search-area">
                        <!-- Search -->
                        <div class="input-group input--group">
                            <input id="datatableSearch_" type="search" name="search" class="form-control" value="{{ request()?->search ?? null}}"
                                    placeholder="{{ translate('Ex : Search by Order Id') }}" aria-label="{{translate('messages.search')}}">
                            <button type="submit" class="btn btn--secondary">
                                <i class="tio-search"></i>
                            </button>
                        </div>
                        <!-- End Search -->
                    </form>
                </div>
            </div>
            <div class="nz-print-settings d-print-none" id="nzPrintSettings">
                <button type="button" class="nz-mobile-print-toggle" id="nzMobilePrintToggle">
                    <span><i class="tio-print mr-1"></i>打印设置</span>
                    <span class="nz-mobile-print-state" id="nzMobilePrintState">未开启</span>
                </button>
                <span class="nz-print-title" style="font-weight:800;">打印小票</span>
                <label>
                    <input type="checkbox" id="nzPrintReady">
                    已接入并测试打印机
                </label>
                <label>
                    <input type="checkbox" id="nzAutoPrintReady">
                    确认收款/接单后自动打单
                </label>
                <button type="button" class="btn btn-sm btn-outline-primary" id="nzTestPrintBtn">
                    <i class="tio-print mr-1"></i>测试打印
                </button>
                <span class="text-muted" style="font-weight:600;">未确认接入时不会自动弹打印，避免误触。</span>
            </div>
            <!-- End Header -->

            <!-- Table -->
            <div class="table-responsive datatable-custom">
                <table id="datatable"
                       class="table table-hover table-borderless table-thead-bordered table-nowrap table-align-middle card-table nz-resizable-table"
                       data-hs-datatables-options='{
                                 "order": [],
                                 "orderCellsTop": true,
                                 "paging":false
                               }'>
                    <thead class="thead-light">
                    <tr>
                        <th class="w-60px">
                            {{ translate('messages.sl') }}
                        </th>
                        <th class="w-180px table-column-pl-0">订单</th>
                        <th class="w-140px">{{translate('messages.order_date')}}</th>
                        <th class="w-160px">{{translate('messages.customer_information')}}</th>
                        <th class="w-110px">{{translate('messages.total_amount')}}</th>
                        <th class="w-110px text-center">{{translate('messages.order_status')}}</th>
                        <th class="w-130px text-center">下一步操作</th>
                        <th class="w-56px text-center">更多</th>
                    </tr>
                    </thead>

                    <tbody id="set-rows">
                    @foreach($orders as $key=>$order)
                        <tr class="status-{{$order['order_status']}} class-all">
                            @php
                                $__proofs = [];
                                if ($order->offline_payments) {
                                    $__offline = \App\CentralLogics\Helpers::offline_payment_formater($order->offline_payments);
                                    foreach (($__offline['input'] ?? []) as $__input) {
                                        $__url = $__input['file_url'] ?? null;
                                        if (!empty($__input['is_file']) && $__url && preg_match('/\.(png|jpe?g|webp|gif)(\?|$)/i', $__url)) {
                                            $__proofs[] = $__url;
                                        }
                                    }
                                    $__proofs = array_values(array_unique($__proofs));
                                }
                            @endphp
                            <td class="" data-label="{{translate('messages.sl')}}">
                                {{$key+$orders->firstItem()}}
                            </td>
                            <td class="table-column-pl-0" data-label="订单">
                                <div class="nz-order-primary-line">
                                    <a href="{{route('vendor.order.details',['id'=>$order['id']])}}" class="text-hover nz-order-id">#{{$order['id']}}
                                        @if ($order->is_pos == 1)
                                        <span class="text--warning font-500">({{ translate('POS') }})</span>
                                        @endif
                                    </a>
                                    <span class="nz-order-mobile-amount">
                                        {{\App\CentralLogics\Helpers::format_currency($order['order_amount'])}}
                                        @if($order->payment_status=='paid')
                                            <small class="text-success">{{translate('messages.paid')}}</small>
                                        @elseif($order->payment_status=='partially_paid')
                                            <small class="text-success">{{translate('messages.partially_paid')}}</small>
                                        @else
                                            <small class="text-danger">{{translate('messages.unpaid')}}</small>
                                        @endif
                                    </span>
                                </div>
                                @if ($order->edited == 1)
                                <span class="text-info fs-12 d-block font-500">({{ translate('Edited') }})</span>
                                @endif
                                @if($order->details && $order->details->count() > 0)
                                    <div class="nz-order-foods">
                                        @foreach($order->details->take(3) as $__d)
                                            <?php $__fd = is_string($__d->food_details) ? json_decode($__d->food_details, true) : $__d->food_details; ?>
                                            {{ $__fd['name'] ?? '—' }}@if($__d->quantity > 1)<span class="text-body">×{{ $__d->quantity }}</span>@endif{{ !$loop->last ? '、' : '' }}
                                        @endforeach
                                        @if($order->details->count() > 3)
                                            <span class="text-body">等{{ $order->details->count() }}样</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td data-label="{{translate('messages.order_date')}}" class="nz-order-time">
                                <strong>{{ Carbon\Carbon::parse($order['created_at'])->format('Y-m-d') }}</strong>
                                <span class="d-block text-muted">{{ Carbon\Carbon::parse($order['created_at'])->format('H:i') }}</span>
                            </td>
                            <td data-label="{{translate('messages.customer_information')}}">
                                @if($order->is_guest)
                                     <?php
                                        $customer_details = json_decode($order['delivery_address'],true);
                                    ?>
                                    <strong>{{$customer_details['contact_person_name']}}</strong>
                                    <div class="text-muted">{{\App\CentralLogics\Helpers::mask_phone($customer_details['contact_person_number'] ?? '')}}</div>
                                @elseif($order->customer)
                                    <a class="text-body text-capitalize"
                                        href="{{route('vendor.order.details',['id'=>$order['id']])}}">
                                        <span class="d-block font-semibold">
                                                {{$order->customer['f_name'].' '.$order->customer['l_name']}}
                                        </span>
                                        <span class="d-block text-muted">
                                                {{\App\CentralLogics\Helpers::mask_phone($order->customer['phone'] ?? '')}}
                                        </span>
                                    </a>
                                @else
                                    <label
                                        class="badge badge--pending">{{translate('messages.Walk_In_Customer')}}</label>
                                @endif
                            </td>
                            <td class="nz-order-amount-cell" data-label="{{translate('messages.total_amount')}}">


                                <div class="text-right mw-85px">
                                    <div class="nz-order-money">
                                        {{\App\CentralLogics\Helpers::format_currency($order['order_amount'])}}
                                    </div>
                                    @if(!empty($nzFxTargets))
                                        <div class="nz-order-converted-amounts" data-nz-base-currency="{{ $nzBaseCurrency }}">
                                            @foreach($nzFxTargets as $__code => $__fx)
                                                <span>≈ {{ $__fx['symbol'] }}{{ number_format(((float) $order['order_amount'] / $__fx['divisor']), 2) }} {{ $__code }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if($order->payment_status=='paid')
                                    <strong class="text-success">
                                        {{translate('messages.paid')}}
                                    </strong>
                                    @elseif($order->payment_status=='partially_paid')
                                        <strong class="text-success">
                                            {{translate('messages.partially_paid')}}
                                        </strong>
                                    @else
                                        <strong class="text-danger">
                                            {{translate('messages.unpaid')}}
                                        </strong>
                                    @endif
                                </div>

                            </td>
                            <td class="text-capitalize text-center nz-order-status-cell" data-label="{{translate('messages.order_status')}}">
                                @if(!empty($__proofs))
                                    <div class="nz-payment-proof-list nz-payment-proof-list--status">
                                        @foreach(array_slice($__proofs, 0, 3) as $__proofUrl)
                                            <button type="button" class="nz-payment-proof-thumb" data-nz-proof-src="{{ $__proofUrl }}" title="查看付款截图" onclick="window.nzOpenPaymentProof && window.nzOpenPaymentProof(this.getAttribute('data-nz-proof-src')); return false;">
                                                <img src="{{ $__proofUrl }}" alt="付款截图">
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                                @if (isset($order->subscription)  && $order->subscription->status != 'canceled' )
                                    @php
                                        $order->order_status = $order->subscription_log ? $order->subscription_log->order_status : $order->order_status;
                                    @endphp
                                @endif
                                    @if($order['order_status']=='pending')
                                        <span class="badge nz-st-wait mb-1">
                                            {{translate('messages.pending')}}
                                        </span>
                                    @elseif($order['order_status']=='confirmed')
                                        <span class="badge nz-st-progress mb-1">
                                        {{translate('messages.confirmed')}}
                                        </span>
                                    @elseif($order['order_status']=='processing')
                                        <span class="badge nz-st-progress mb-1">
                                        {{translate('messages.processing')}}
                                        </span>
                                    @elseif($order['order_status']=='picked_up')
                                        <span class="badge nz-st-progress mb-1">
                                        {{translate('messages.out_for_delivery')}}
                                        </span>
                                    @elseif($order['order_status']=='delivered')
                                        <span class="badge nz-st-done mb-1">
                                            {{$order?->order_type == 'dine_in' ? translate('messages.Completed') : translate('messages.delivered')}}
                                        </span>
                                    @elseif($order['order_status']=='handover')
                                        <span class="badge nz-st-progress mb-1">
                                            {{translate('messages.handover')}}
                                        </span>
                                    @elseif($order['order_status']=='accepted')
                                        <span class="badge nz-st-progress mb-1">
                                            {{translate('messages.accepted')}}
                                        </span>
                                    @elseif($order['order_status']=='refund_request_canceled')
                                        <span class="badge nz-st-cancel mb-1">
                                            退款申请已撤销
                                        </span>
                                    @elseif($order['order_status']=='canceled')
                                        <span class="badge nz-st-cancel mb-1">
                                            {{translate(str_replace('_',' ',$order['order_status']))}}
                                        </span>
                                    @else
                                        <span class="badge nz-st-alert mb-1">
                                            {{translate(str_replace('_',' ',$order['order_status']))}}
                                        </span>
                                    @endif


                                <?php $nzTo = \App\CentralLogics\NezhaOrderTimeout::describe($order); ?>
                                @if($nzTo && in_array($nzTo['severity'], ['warning','error']) && !empty($nzTo['elapsed_minutes']))
                                    <span class="badge {{ $nzTo['severity']==='error' ? 'badge-soft-danger' : 'badge-soft-warning' }} d-inline-block mb-1" title="{{ $nzTo['title'] }}">
                                        ⏱ {{ \App\CentralLogics\NezhaOrderTimeout::humanDuration($nzTo['elapsed_minutes']) }}
                                    </span>
                                @endif
                            </td>
                            <td class="text-center nz-order-mobile-actions" data-label="下一步操作">
                                @php
                                    $__os = $order['order_status'];
                                    $__qa = null;
                                    if ($__os === 'pending' && $order->payment_method === 'offline_payment'
                                        && $order->offline_payments && $order->offline_payments->status === 'pending') {
                                        $__qa = ['route' => route('vendor.order.confirm-offline-payment', $order['id']),
                                                  'label' => '确认收款', 'cls' => 'btn-success', 'icon' => 'tio-checkmark-circle',
                                                  'confirm' => '确认：您已在自己的账户收到本单顾客的付款？',
                                                  'auto_print' => true, 'prep_prompt' => true, 'prep_title' => '确认收款', 'prep_ok' => '确认收款', 'prep_color' => '#1FA463', 'prep_note' => '确认后将通知顾客并开始备餐。', 'prep_default' => (int) (\App\CentralLogics\Helpers::get_business_settings('nezha_default_prep_min') ?: 30)];
                                    } elseif ($__os === 'pending') {
                                        $__qa = ['route' => route('vendor.order.status-update', $order['id']),
                                                  'label' => '接单', 'cls' => 'btn-success', 'icon' => 'tio-checkmark-circle',
                                                  'confirm' => '确认接单？',
                                                  'auto_print' => true,
                                                  'extra' => ['order_status'=>'confirmed','id'=>$order['id']]];
                                    } elseif (in_array($__os, ['confirmed','accepted'], true)) {
                                        $__qa = ['route' => route('vendor.order.status-update', $order['id']),
                                                  'label' => '开始备餐', 'cls' => 'btn-info', 'icon' => 'tio-restaurant',
                                                  'prep_prompt' => true, 'prep_title' => '开始备餐', 'prep_ok' => '开始备餐', 'prep_color' => '#1F6FD0', 'prep_note' => '顾客会看到这个预计时间。默认取店铺设置，可临时改本单。', 'prep_default' => (int) explode('-', $restaurant->delivery_time ?? '30-60')[0],
                                                  'extra' => ['order_status'=>'processing','id'=>$order['id'],
                                                              ]];
                                    } elseif (in_array($__os, ['processing','handover'], true) && ($order['order_type'] ?? '') === 'delivery') {
                                        // 哪吒 P3: 配送单出餐环节改「叫车配送」→ 底部抽屉(叫车工具 + 贴链接 + 标记配送中), 不进详情页
                                        $__qa = ['type' => 'dispatch', 'label' => '叫车配送', 'icon' => 'tio-send'];
                                    } elseif ($__os === 'picked_up' && ($order['order_type'] ?? '') === 'delivery') {
                                        $__qa = ['route' => route('vendor.order.mark-delivered', $order['id']),
                                                  'label' => '已送达', 'cls' => 'btn-success', 'icon' => 'tio-done-all',
                                                  'confirm' => '确认本单已送达顾客？确认后不可撤销。'];
                                    } else {
                                        $__refundPending = \App\Models\NezhaRefundRecord::where('order_id', $order['id'])
                                            ->where('restaurant_id', \App\CentralLogics\Helpers::get_restaurant_id())
                                            ->where('status', 'pending_merchant_refund')
                                            ->exists();
                                        if ($__refundPending) {
                                            $__qa = ['route' => route('vendor.order.mark-refunded', ['id' => $order['id']]),
                                                      'label' => '标记已退款', 'cls' => 'btn-warning', 'icon' => 'tio-receipt-outlined',
                                                      'confirm' => '请确认：您已在自己的账户按原路退还本单顾客的付款？'];
                                        } elseif ($__os === 'refund_requested') {
                                            $__qa = ['type' => 'link', 'route' => route('vendor.order.details',['id'=>$order['id']]),
                                                      'label' => '处理退款申请', 'title' => '查看详情处理退款申请',
                                                      'cls' => 'btn-warning', 'icon' => 'tio-open-in-new'];
                                        } elseif (in_array($__os, ['delivered','canceled','failed','refunded','refund_request_canceled'], true)) {
                                            $__qa = ['type' => 'closed', 'label' => '订单已关闭'];
                                        }
                                    }
                                @endphp
                                @if($__qa && (($__qa['type'] ?? 'form') === 'dispatch'))
                                    <button type="button" class="btn btn-sm nz-step-btn nz-dispatch-open text-nowrap" data-nz-dispatch="{{ $order['id'] }}">
                                        <i class="{{ $__qa['icon'] }} mr-1"></i>{{ $__qa['label'] }}
                                    </button>
                                @elseif($__qa && (($__qa['type'] ?? 'form') === 'link'))
                                    <a class="btn btn-sm {{ $__qa['cls'] }} nz-step-btn text-nowrap text-white" href="{{ $__qa['route'] }}" title="{{ $__qa['title'] ?? $__qa['label'] }}">
                                        <i class="{{ $__qa['icon'] }} mr-1"></i>{{ $__qa['label'] }}
                                    </a>
                                @elseif($__qa && (($__qa['type'] ?? 'form') === 'closed'))
                                    <span class="nz-step-empty">{{ $__qa['label'] }}</span>
                                @elseif($__qa)
                                    <form class="nz-order-step-form{{ !empty($__qa['prep_prompt']) ? ' nz-prep-form' : '' }}" method="POST" action="{{ $__qa['route'] }}" style="margin:0"
                                        data-nz-invoice-url="{{route('vendor.order.generate-invoice',[$order['id']])}}?nz_auto_print=1"
                                        data-nz-order-id="{{$order['id']}}"
                                        data-nz-auto-print-action="{{ !empty($__qa['auto_print']) ? '1' : '0' }}"
                                        @if(!empty($__qa['prep_prompt'])) data-nz-prep-default="{{ $__qa['prep_default'] ?? 30 }}" data-nz-prep-title="{{ $__qa['prep_title'] ?? '开始备餐' }}" data-nz-prep-ok="{{ $__qa['prep_ok'] ?? '确认' }}" data-nz-prep-color="{{ $__qa['prep_color'] ?? '#1F6FD0' }}" data-nz-prep-note="{{ $__qa['prep_note'] ?? '' }}" data-nz-prep-confirm="{{ $__qa['confirm'] ?? '' }}"@else onsubmit="return confirm('{{ $__qa['confirm'] }}')"@endif>
                                        @csrf @method('PUT')
                                        @if(!empty($__qa['extra']))
                                            @foreach($__qa['extra'] as $__k => $__v)
                                                <input type="hidden" name="{{ $__k }}" value="{{ $__v }}">
                                            @endforeach
                                        @endif
                                        @if(!empty($__qa['prep_prompt']))<input type="hidden" name="processing_time" value="{{ $__qa['prep_default'] ?? 30 }}">@endif<button type="submit" class="btn btn-sm {{ $__qa['cls'] }} nz-step-btn text-nowrap text-white">
                                            <i class="{{ $__qa['icon'] }} mr-1"></i>{{ $__qa['label'] }}
                                        </button>
                                    </form>
                                @else
                                    <span class="nz-step-empty">无需操作</span>
                                @endif
                            </td>
                            {{-- 哪吒 P2: 「订单详情」「打印小票」两图标列合并进行末「⋯」菜单(查看详情/补打小票/拒接本单), 订单号本身已是详情链接 --}}
                            <td class="text-center nz-row-more-cell" data-label="更多">
                                <button type="button" class="nz-row-more-btn" aria-label="更多操作" data-nz-more
                                    data-detail-url="{{ route('vendor.order.details',['id'=>$order['id']]) }}"
                                    data-invoice-url="{{ route('vendor.order.generate-invoice',[$order['id']]) }}"
                                    @if(in_array($order['order_status'], ['pending','confirmed'], true))
                                    data-reject-url="{{ route('vendor.order.reject',['id'=>$order['id']]) }}"
                                    @endif
                                    data-order-label="#{{ $order['id'] }}">
                                    <span aria-hidden="true">&#8943;</span><span class="nz-mobile-action-label">更多</span>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if(count($orders) === 0)
            <div class="empty--data">
                <img src="{{dynamicAsset('assets/admin/img/empty.png')}}" alt="public">
                <h5 class="nz-status-empty-copy">{{ $nzCurrentMeta['empty'] }}</h5>
                <div class="nz-status-empty-help">可切换上方状态，或用订单号搜索历史订单。</div>
            </div>
            @endif
            <!-- End Table -->

            <!-- Footer -->
            <div class="card-footer">
                <!-- Pagination -->
                <div class="row justify-content-center justify-content-sm-between align-items-sm-center">
                    <div class="col-sm-auto">
                        <div class="d-flex justify-content-center justify-content-sm-end">
                            <!-- Pagination -->
                            {!! $orders->links() !!}
                        </div>
                    </div>
                </div>
                <!-- End Pagination -->
            </div>
            <!-- End Footer -->
        </div>
        <!-- End Card -->
        <div class="modal fade" id="nzProofModal" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h5 class="modal-title">客户付款截图</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <img id="nzProofModalImg" class="nz-proof-modal-img" src="" alt="客户付款截图">
                    </div>
                </div>
            </div>
        </div>
        {{-- 哪吒 P2(2026-07-01): 行末「⋯」共享菜单(查看详情/补打小票/拒接本单) + 拒接本单弹窗。菜单 fixed 定位防表格裁剪; 点哪行由 JS 按 data 属性填充。拒接复用现有 vendor.order.reject 路由。 --}}
        <div class="nz-row-menu d-print-none" id="nzRowMenu" role="menu">
            <a href="#" id="nzMenuDetail" role="menuitem">🔍 查看详情</a>
            <a href="#" id="nzMenuInvoice" target="_blank" role="menuitem">🖨 补打小票</a>
            <div class="nz-menu-div" id="nzMenuRejectDiv"></div>
            <button type="button" class="nz-menu-reject" id="nzMenuReject" role="menuitem">🚫 拒接本单</button>
        </div>
        <div class="nz-reject-modal d-print-none" id="nzRejectModal" aria-hidden="true">
            <div class="nz-reject-card" role="dialog" aria-modal="true" aria-labelledby="nzRejectTitle">
                <form method="post" id="nzRejectForm" action="">
                    @csrf
                    @method('put')
                    <div class="nz-reject-head">
                        <h5 id="nzRejectTitle">拒接本单 · <span id="nzRejectOrdLabel"></span></h5>
                        <button type="button" class="nz-reject-x" data-nz-reject-close aria-label="关闭">&times;</button>
                    </div>
                    <div class="nz-reject-body">
                        <label>请填写拒接原因（会通知顾客）</label>
                        <textarea name="reason" required maxlength="500" rows="3" placeholder="例：该商品已售罄；今日已打烊；订单超出配送范围 / 无法叫到配送。"></textarea>
                    </div>
                    <div class="nz-reject-foot">
                        <button type="button" class="btn btn-secondary btn-sm" data-nz-reject-close>关闭</button>
                        <button type="submit" class="btn btn--danger btn-sm">确认拒接本单</button>
                    </div>
                </form>
            </div>
        </div>
        {{-- 哪吒 P3(2026-07-01): 叫车底部抽屉(移动端全屏) + 每单叫车卡隐藏源。点行内「叫车配送」把对应源移入抽屉, 复用详情页同款 partial --}}
        <div id="nzDispatchHolder" style="display:none">
            @foreach($orders as $__do)
                @if(($__do['order_type'] ?? '') === 'delivery' && in_array($__do['order_status'], ['processing','handover'], true))
                    <div id="nzDispatchSrc-{{ $__do['id'] }}" data-nz-dispatch-src="{{ $__do['id'] }}">
                        @include('vendor-views.order.partials._dispatch_tools', ['order' => $__do])
                    </div>
                @endif
            @endforeach
        </div>
        <div class="nz-dispatch-drawer d-print-none" id="nzDispatchDrawer" aria-hidden="true">
            <div class="nz-dispatch-backdrop" data-nz-dispatch-close></div>
            <div class="nz-dispatch-sheet" role="dialog" aria-modal="true" aria-labelledby="nzDispatchTitle">
                <div class="nz-dispatch-grip"></div>
                <div class="nz-dispatch-head">
                    <div class="nz-dispatch-title" id="nzDispatchTitle">🛵 Yandex Go 配送</div>
                    <button type="button" class="nz-dispatch-x" data-nz-dispatch-close aria-label="关闭">&times;</button>
                </div>
                <div class="nz-dispatch-body" id="nzDispatchBody"></div>
            </div>
        </div>
    </div>

@endsection

@push('script_2')
    <script>
        "use strict";
        (function(){
            var READY_KEY = 'nzPrintReady';
            var AUTO_KEY = 'nzAutoPrintReady';

            function $(id){ return document.getElementById(id); }

            function isPrintReady(){
                return localStorage.getItem(READY_KEY) === '1';
            }

            function isAutoPrintReady(){
                return isPrintReady() && localStorage.getItem(AUTO_KEY) === '1';
            }

            function applyPrintSettings(){
                var ready = $('nzPrintReady');
                var auto = $('nzAutoPrintReady');
                var panel = $('nzPrintSettings');
                var state = $('nzMobilePrintState');
                if (!ready || !auto) return;
                ready.checked = isPrintReady();
                auto.checked = localStorage.getItem(AUTO_KEY) === '1';
                auto.disabled = !ready.checked;
                if (!ready.checked) {
                    auto.checked = false;
                    localStorage.setItem(AUTO_KEY, '0');
                }
                if (state) {
                    state.textContent = isAutoPrintReady() ? '自动打单已开' : (isPrintReady() ? '已接打印机' : '未开启');
                }
                if (panel && (isPrintReady() || localStorage.getItem(AUTO_KEY) === '1')) {
                    panel.classList.add('nz-print-open');
                }
            }

            function openInvoiceForPrint(url){
                if (!url) return;
                var w = window.open(url, '_blank', 'noopener');
                if (!w) {
                    alert('浏览器拦截了打印窗口，请允许本站弹出窗口后重试。');
                }
            }

            var DONE_SHOW_KEY = 'nzOrderDoneShow';
            var DONE_DAYS_KEY = 'nzOrderDoneDays';

            function nzDoneReadPref(){
                var show = localStorage.getItem(DONE_SHOW_KEY);
                if (show !== '0') show = '1';
                var days = parseInt(localStorage.getItem(DONE_DAYS_KEY) || '', 10);
                if (isNaN(days) || days < 1) days = 0;
                if (days > 365) days = 365;
                return { show: show, days: days };
            }

            function nzDoneBuildUrl(pref){
                var p = new URLSearchParams(location.search);
                p.delete('nz_done');
                p.delete('nz_done_days');
                p.delete('page');
                if (pref.show === '0') {
                    p.set('nz_done', '0');
                } else if (pref.days > 0) {
                    p.set('nz_done_days', String(pref.days));
                }
                var qs = p.toString();
                return location.pathname + (qs ? '?' + qs : '');
            }

            function initDoneFilter(){
                var wrap = document.getElementById('nzDoneFilter');
                if (!wrap) return; // 只在「全部」页存在
                var showBox = document.getElementById('nzDoneShow');
                var daysInput = document.getElementById('nzDoneDays');
                var hint = document.getElementById('nzDoneHint');
                if (!showBox || !daysInput || !hint) return;

                var pref = nzDoneReadPref();
                // 持久化: 用本机首选项校正 URL —— 经状态 tab / 「全部」按钮跳回时 URL 无参, 在此补回后重载
                var params = new URLSearchParams(location.search);
                var wantDone = pref.show === '0' ? '0' : null;
                var wantDays = (pref.show !== '0' && pref.days > 0) ? String(pref.days) : null;
                if (params.get('nz_done') !== wantDone || params.get('nz_done_days') !== wantDays) {
                    location.replace(nzDoneBuildUrl(pref));
                    return;
                }

                function render(){
                    var s = nzDoneReadPref();
                    showBox.checked = s.show !== '0';
                    daysInput.value = (s.show !== '0' && s.days > 0) ? String(s.days) : '';
                    daysInput.disabled = !showBox.checked;
                    if (!showBox.checked) {
                        hint.textContent = '只显示进行中订单（已送达/已取消/已退款等全部收起）';
                    } else if (s.days > 0) {
                        hint.textContent = '进行中订单 + 近 ' + s.days + ' 天内已完成单（更早的收起）';
                    } else {
                        hint.textContent = '默认显示全部（含已送达、已取消、已退款等历史单）';
                    }
                }
                render();

                showBox.addEventListener('change', function(){
                    localStorage.setItem(DONE_SHOW_KEY, showBox.checked ? '1' : '0');
                    render();
                    location.replace(nzDoneBuildUrl(nzDoneReadPref()));
                });
                daysInput.addEventListener('change', function(){
                    var v = parseInt(daysInput.value, 10);
                    if (isNaN(v) || v < 1) {
                        localStorage.setItem(DONE_DAYS_KEY, '');
                    } else {
                        localStorage.setItem(DONE_DAYS_KEY, String(Math.min(v, 365)));
                    }
                    render();
                    location.replace(nzDoneBuildUrl(nzDoneReadPref()));
                });
            }

            function initDispatchDrawer(){
                var drawer = document.getElementById('nzDispatchDrawer');
                var body = document.getElementById('nzDispatchBody');
                var holder = document.getElementById('nzDispatchHolder');
                var title = document.getElementById('nzDispatchTitle');
                if (!drawer || !body || !holder) return;
                var openId = null;

                function stow(){
                    if (openId != null) {
                        var s = document.getElementById('nzDispatchSrc-' + openId);
                        if (s) { s.style.display = 'none'; holder.appendChild(s); }
                        openId = null;
                    }
                }
                function openDrawer(id){
                    var src = document.getElementById('nzDispatchSrc-' + id);
                    if (!src) return;
                    stow();
                    body.appendChild(src);
                    src.style.display = 'block';
                    body.scrollTop = 0;
                    openId = id;
                    if (title) title.textContent = '🛵 Yandex Go 配送 · 订单 #' + id;
                    drawer.classList.add('nz-open');
                    drawer.setAttribute('aria-hidden', 'false');
                    document.body.classList.add('nz-dispatch-lock');
                }
                function closeDrawer(){
                    stow();
                    drawer.classList.remove('nz-open');
                    drawer.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('nz-dispatch-lock');
                }

                document.addEventListener('click', function(e){
                    var t = e.target;
                    if (!t || !t.closest) return;
                    var opener = t.closest('.nz-dispatch-open');
                    if (opener) { e.preventDefault(); openDrawer(opener.getAttribute('data-nz-dispatch')); return; }
                    if (t.closest('[data-nz-dispatch-close]')) { e.preventDefault(); closeDrawer(); }
                });
                document.addEventListener('keydown', function(e){
                    if (e.key === 'Escape' && openId != null) closeDrawer();
                });
            }

            function initTodayRev(){
                var wrap = document.getElementById('nzDoneFilter');
                var toggle = document.getElementById('nzTodayRevToggle');
                if (!wrap || !toggle) return;
                var KEY = 'nzOrderTodayRev';
                wrap.classList.toggle('nz-rev-on', localStorage.getItem(KEY) === '1');
                toggle.checked = localStorage.getItem(KEY) === '1';
                toggle.addEventListener('change', function(){
                    localStorage.setItem(KEY, toggle.checked ? '1' : '0');
                    wrap.classList.toggle('nz-rev-on', toggle.checked);
                });
            }

            function initRowMenu(){
                var menu = document.getElementById('nzRowMenu');
                var mDetail = document.getElementById('nzMenuDetail');
                var mInvoice = document.getElementById('nzMenuInvoice');
                var mReject = document.getElementById('nzMenuReject');
                var mRejectDiv = document.getElementById('nzMenuRejectDiv');
                var modal = document.getElementById('nzRejectModal');
                var form = document.getElementById('nzRejectForm');
                var lbl = document.getElementById('nzRejectOrdLabel');
                if (!menu || !modal || !form) return;
                var curReject = '', curLabel = '', openedAt = 0;
                function closeMenu(){ menu.classList.remove('nz-open'); }
                function openMenu(btn){
                    mDetail.href = btn.getAttribute('data-detail-url') || '#';
                    mInvoice.href = btn.getAttribute('data-invoice-url') || '#';
                    curReject = btn.getAttribute('data-reject-url') || '';
                    curLabel = btn.getAttribute('data-order-label') || '';
                    var can = !!curReject;
                    mReject.style.display = can ? '' : 'none';
                    mRejectDiv.style.display = can ? '' : 'none';
                    menu.classList.add('nz-open');
                    openedAt = Date.now();
                    var r = btn.getBoundingClientRect();
                    var mw = menu.offsetWidth, mh = menu.offsetHeight;
                    var left = Math.max(8, Math.min(r.right - mw, window.innerWidth - mw - 8));
                    var top = r.bottom + 6;
                    if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 6);
                    menu.style.left = left + 'px';
                    menu.style.top = top + 'px';
                }
                document.addEventListener('click', function(e){
                    var btn = e.target.closest ? e.target.closest('[data-nz-more]') : null;
                    if (btn){ e.preventDefault(); if (menu.classList.contains('nz-open')) { closeMenu(); } else { openMenu(btn); } return; }
                    if (!menu.contains(e.target)) closeMenu();
                });
                window.addEventListener('scroll', function(){ if (Date.now() - openedAt > 350) closeMenu(); }, true);
                window.addEventListener('resize', closeMenu);
                function openReject(){
                    form.action = curReject;
                    lbl.textContent = curLabel;
                    var ta = form.querySelector('textarea[name=reason]'); if (ta) ta.value = '';
                    modal.classList.add('nz-open');
                    document.body.style.overflow = 'hidden';
                }
                function closeReject(){ modal.classList.remove('nz-open'); document.body.style.overflow = ''; }
                mReject.addEventListener('click', function(){ closeMenu(); openReject(); });
                modal.addEventListener('click', function(e){ if (e.target === modal || (e.target.hasAttribute && e.target.hasAttribute('data-nz-reject-close'))) closeReject(); });
                form.addEventListener('submit', function(e){ if (!confirm('确认拒接本单？订单将取消并通知顾客。若顾客已付款，需你按原路退还。')) e.preventDefault(); });
                document.addEventListener('keydown', function(e){ if (e.key === 'Escape'){ closeMenu(); closeReject(); } });
            }

            function initColumnResize(){
                var table = document.getElementById('datatable');
                if (!table || window.innerWidth < 768) return;
                var ths = Array.prototype.slice.call(table.querySelectorAll('thead th'));
                if (!ths.length) return;
                var storeKey = 'nzOrderColumnWidths:' + location.pathname;
                try {
                    var saved = JSON.parse(localStorage.getItem(storeKey) || '[]');
                    saved.forEach(function(width, i){
                        if (width && ths[i]) ths[i].style.width = width + 'px';
                    });
                } catch (e) {}
                ths.forEach(function(th, index){
                    if (th.querySelector('.nz-col-resizer')) return;
                    var grip = document.createElement('span');
                    grip.className = 'nz-col-resizer';
                    grip.setAttribute('aria-hidden', 'true');
                    th.appendChild(grip);
                    grip.addEventListener('pointerdown', function(e){
                        e.preventDefault();
                        var startX = e.clientX;
                        var startWidth = th.offsetWidth;
                        document.body.classList.add('nz-col-resizing');
                        function onMove(ev){
                            var next = Math.max(54, startWidth + ev.clientX - startX);
                            th.style.width = next + 'px';
                        }
                        function onUp(){
                            document.removeEventListener('pointermove', onMove);
                            document.removeEventListener('pointerup', onUp);
                            document.body.classList.remove('nz-col-resizing');
                            var widths = ths.map(function(item){ return Math.round(item.offsetWidth); });
                            try { localStorage.setItem(storeKey, JSON.stringify(widths)); } catch (e) {}
                        }
                        document.addEventListener('pointermove', onMove);
                        document.addEventListener('pointerup', onUp);
                    });
                });
            }

            function initProofPreview(){
                document.addEventListener('click', function(e){
                    var btn = e.target && e.target.closest ? e.target.closest('.nz-payment-proof-thumb') : null;
                    if (!btn) return;
                    e.preventDefault();
                    e.stopPropagation();
                    var src = btn.getAttribute('data-nz-proof-src');
                    window.nzOpenPaymentProof(src);
                });
            }

            window.nzOpenPaymentProof = function(src){
                var img = document.getElementById('nzProofModalImg');
                var modal = document.getElementById('nzProofModal');
                if (!src || !img || !modal) return false;
                img.setAttribute('src', src);
                if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
                    window.jQuery(modal).modal('show');
                } else {
                    modal.classList.add('show');
                    modal.style.display = 'block';
                    modal.removeAttribute('aria-hidden');
                    document.body.classList.add('modal-open');
                }
                return false;
            };

            function initPrepPrompt(){
                document.addEventListener('click', function(e){
                    var t = e.target;
                    if (!t || !t.closest) return;
                    var btn = t.closest('form.nz-prep-form button[type="submit"]');
                    if (!btn) return;
                    var form = btn.closest('form.nz-prep-form');
                    if (!form || form.getAttribute('data-nz-prep-done') === '1') return;
                    e.preventDefault();
                    var def = parseInt(form.getAttribute('data-nz-prep-default'), 10) || 30;
                    var title = form.getAttribute('data-nz-prep-title') || '开始备餐';
                    var okTxt = form.getAttribute('data-nz-prep-ok') || '确认';
                    var color = form.getAttribute('data-nz-prep-color') || '#1F6FD0';
                    var note = form.getAttribute('data-nz-prep-note') || '';
                    var confirmLine = form.getAttribute('data-nz-prep-confirm') || '';
                    function setAndGo(val){
                        var inp = form.querySelector('input[name="processing_time"]');
                        if (!inp) { inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'processing_time'; form.appendChild(inp); }
                        inp.value = val;
                        form.setAttribute('data-nz-prep-done', '1');
                        if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
                    }
                    if (typeof Swal === 'undefined') {
                        var pv = window.prompt((confirmLine ? confirmLine + '\n\n' : '') + '预计出餐时间（分钟）', def);
                        if (pv === null) return;
                        pv = parseInt(pv, 10);
                        if (!pv || pv < 1) { alert('请填写预计出餐时间（至少 1 分钟）'); return; }
                        setAndGo(Math.min(pv, 1440));
                        return;
                    }
                    var html = '';
                    if (confirmLine) { html += '<div style="text-align:left;font-size:13.5px;color:#475467;line-height:1.5;background:#F7F9FB;border:1px solid #EAEEF3;border-radius:9px;padding:10px 12px;margin:0 0 14px;">' + confirmLine + '</div>'; }
                    html += '<div style="text-align:left;font-size:13.5px;font-weight:700;color:#344054;margin:0 0 6px;">预计出餐时间（分钟）</div>';
                    if (note) { html += '<div style="text-align:left;font-size:12px;color:#98A2B3;line-height:1.5;margin:6px 0 0;">' + note + '</div>'; }
                    Swal.fire({
                        title: title,
                        html: html,
                        input: 'number',
                        inputValue: def,
                        inputAttributes: { min: 1, max: 1440, step: 1 },
                        showCancelButton: true,
                        confirmButtonText: okTxt,
                        cancelButtonText: '取消',
                        confirmButtonColor: color,
                        cancelButtonColor: '#98A2B3',
                        reverseButtons: true,
                        inputValidator: function(value){
                            if (!value || !/^[0-9]+$/.test(String(value)) || parseInt(value, 10) < 1) { return '请填写预计出餐时间（至少 1 分钟）'; }
                            if (parseInt(value, 10) > 1440) { return '预计出餐时间过大（最多 1440 分钟）'; }
                        }
                    }).then(function(r){
                        if (r && r.value) { setAndGo(parseInt(r.value, 10)); }
                    });
                }, false);
            }

            window.nzMaybeAutoPrintAfterOrderAction = function(invoiceUrl){
                if (!isAutoPrintReady() || !invoiceUrl) return;
                sessionStorage.setItem('nzAutoPrintInvoiceUrl', invoiceUrl);
            };

            document.addEventListener('DOMContentLoaded', function(){
                applyPrintSettings();
                initColumnResize();
                initProofPreview();
                initDoneFilter();
                initDispatchDrawer();
                initTodayRev();
                initRowMenu();
                initPrepPrompt();

                var ready = $('nzPrintReady');
                var auto = $('nzAutoPrintReady');
                var testBtn = $('nzTestPrintBtn');
                var mobileToggle = $('nzMobilePrintToggle');

                if (ready) {
                    ready.addEventListener('change', function(){
                        localStorage.setItem(READY_KEY, ready.checked ? '1' : '0');
                        applyPrintSettings();
                    });
                }
                if (auto) {
                    auto.addEventListener('change', function(){
                        if (auto.checked && !isPrintReady()) {
                            alert('请先勾选“已接入并测试打印机”。');
                            auto.checked = false;
                        }
                        localStorage.setItem(AUTO_KEY, auto.checked ? '1' : '0');
                        applyPrintSettings();
                    });
                }
                if (testBtn) {
                    testBtn.addEventListener('click', function(){
                        if (!isPrintReady()) {
                            alert('请先确认本机/云打印机已接入。');
                            return;
                        }
                        var firstPrint = document.querySelector('a[href*="/generate-invoice/"]');
                        if (!firstPrint) {
                            alert('当前没有可测试打印的订单。');
                            return;
                        }
                        openInvoiceForPrint(firstPrint.href + (firstPrint.href.indexOf('?') === -1 ? '?nz_auto_print=1&nz_test_print=1' : '&nz_auto_print=1&nz_test_print=1'));
                    });
                }
                if (mobileToggle) {
                    mobileToggle.addEventListener('click', function(){
                        var panel = $('nzPrintSettings');
                        if (panel) panel.classList.toggle('nz-print-open');
                    });
                }

                var pending = sessionStorage.getItem('nzAutoPrintInvoiceUrl');
                if (pending) {
                    sessionStorage.removeItem('nzAutoPrintInvoiceUrl');
                    if (isAutoPrintReady()) {
                        openInvoiceForPrint(pending);
                    }
                }
            });

            document.addEventListener('submit', function(e){
                var form = e.target;
                if (!form || !form.classList || !form.classList.contains('nz-order-step-form')) return;
                if (e.defaultPrevented) return;
                e.preventDefault();

                var btn = form.querySelector('button[type="submit"]');
                var orig = btn ? btn.innerHTML : '';
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '处理中...';
                }

                var fd = new FormData(form);
                fetch(form.action, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'text/html,application/xhtml+xml,application/xml',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    redirect: 'follow'
                }).then(function(resp){
                    if (!resp.ok) throw new Error('HTTP ' + resp.status);
                    if (form.getAttribute('data-nz-auto-print-action') === '1') {
                        window.nzMaybeAutoPrintAfterOrderAction(form.getAttribute('data-nz-invoice-url'));
                    }
                    setTimeout(function(){ window.location.reload(); }, 60);
                }).catch(function(err){
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = orig;
                    }
                    alert('操作失败：' + (err && err.message ? err.message : '网络错误，请重试'));
                });
            }, false);
        })();

        $(document).on('ready', function () {
            // INITIALIZATION OF NAV SCROLLER
            // =======================================================
            $('.js-nav-scroller').each(function () {
                new HsNavScroller($(this)).init()
            });

            // INITIALIZATION OF SELECT2
            // =======================================================
            $('.js-select2-custom').each(function () {
                let select2 = $.HSCore.components.HSSelect2.init($(this));
            });


            // INITIALIZATION OF DATATABLES
            // =======================================================
            let datatable = $.HSCore.components.HSDatatables.init($('#datatable'), {
                dom: 'Bfrtip',
                buttons: [
                    {
                        extend: 'copy',
                        className: 'd-none'
                    },
                    {
                        extend: 'pdf',
                        className: 'd-none'
                    },
                    {
                        extend: 'print',
                        className: 'd-none'
                    },
                ],
                select: {
                    style: 'multi',
                    selector: 'td:first-child input[type="checkbox"]',
                    classMap: {
                        checkAll: '#datatableCheckAll',
                        counter: '#datatableCounter',
                        counterInfo: '#datatableCounterInfo'
                    }
                },
                language: {
                    zeroRecords: '<div class="text-center p-4">' +
                        '<img class="mb-3 w-7rem" src="{{dynamicAsset('assets/admin')}}/svg/illustrations/sorry.svg" alt="Image Description">' +
                        '<p class="mb-0">{{ translate('No_data_to_show') }}</p>' +
                        '</div>'
                }
            });

            $('#export-copy').click(function () {
                datatable.button('.buttons-copy').trigger()
            });

            $('#export-excel').click(function () {
                datatable.button('.buttons-excel').trigger()
            });

            $('#export-csv').click(function () {
                datatable.button('.buttons-csv').trigger()
            });

            $('#export-pdf').click(function () {
                datatable.button('.buttons-pdf').trigger()
            });

            $('#export-print').click(function () {
                datatable.button('.buttons-print').trigger()
            });

            $('#toggleColumn_order').change(function (e) {
                datatable.columns(1).visible(e.target.checked)
            })

            $('#toggleColumn_date').change(function (e) {
                datatable.columns(2).visible(e.target.checked)
            })

            $('#toggleColumn_customer').change(function (e) {
                datatable.columns(3).visible(e.target.checked)
            })

            $('#toggleColumn_order_status').change(function (e) {
                datatable.columns(5).visible(e.target.checked)
            })


            $('#toggleColumn_total').change(function (e) {
                datatable.columns(4).visible(e.target.checked)
            })

            $('#toggleColumn_actions').change(function (e) {
                datatable.columns(6).visible(e.target.checked)
            })


            // INITIALIZATION OF TAGIFY
            // =======================================================
            $('.js-tagify').each(function () {
                let tagify = $.HSCore.components.HSTagify.init($(this));
            });
        });
    </script>
@endpush
