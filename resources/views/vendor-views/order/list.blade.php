@extends('layouts.vendor.app')
{{-- 哪吒2026-06-26: 本页订单行/操作列改用原生 PHP 块输出变量, 勿改回 Blade 行内简写(曾致编译畸形整页500); 部署侧 nzcheck-blade 编译探针兜底 --}}

@section('title',translate('messages.Order List'))

@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- 手机端(<768px)把订单表格重排为卡片, 操作按钮直接露出; PC/平板(>=768px)不命中本媒体查询, 表格原样不变 --}}
    <style>
        .nz-order-table-card { border: 1px solid #E6EAF0; border-radius: 10px; box-shadow: 0 1px 4px rgba(16,24,40,.04); overflow: hidden; }
        /* 哪吒: 订单列表吸顶 —— 表格区自成有界滚动框(max-height 由 JS 按视口自适应), 表头 sticky 钉框顶;
           工具栏(card-header)+分页(card-footer)在框外常驻。仅 >=768px; 手机端卡片布局(thead 隐藏)不受影响。 */
        @media (min-width: 768px) {
            .nz-order-table-card .table-responsive.datatable-custom { overflow: auto; }
            .nz-order-table-card #datatable thead.thead-light th { position: sticky; top: 0; z-index: 6; background: #F5F7FA; box-shadow: inset 0 -1px 0 #E6EAF0; }
        }
        .nz-order-table-card .card-header { border-bottom: 1px solid #EDF1F5; background: #fff; }
        .nz-order-table-card #datatable { table-layout: fixed; min-width: 1180px; }
        .nz-order-toolbar { display: flex; align-items: center; justify-content: flex-start; gap: 12px; width: 100%; }
        .nz-export-area { flex: 0 0 auto; }
        .nz-export-area .nz-export-dropdown { left: 12px !important; right: auto !important; }
        .nz-search-area { flex: 0 1 360px; margin-left: 0; }
        .nz-search-area .input--group { width: 360px; max-width: 100%; }
        /* 哪吒 P7: 8 列宽度(此前为 9 列旧值套在现 8 列上, P2 合并两图标列为⋯后残留, 已校正) */
        .nz-order-table-card #datatable th:nth-child(1), .nz-order-table-card #datatable td:nth-child(1) { width: 5%; }
        .nz-order-table-card #datatable th:nth-child(2), .nz-order-table-card #datatable td:nth-child(2) { width: 18%; }
        .nz-order-table-card #datatable th:nth-child(3), .nz-order-table-card #datatable td:nth-child(3) { width: 12%; }
        .nz-order-table-card #datatable th:nth-child(4), .nz-order-table-card #datatable td:nth-child(4) { width: 15%; }
        .nz-order-table-card #datatable th:nth-child(5), .nz-order-table-card #datatable td:nth-child(5) { width: 11%; }
        .nz-order-table-card #datatable th:nth-child(6), .nz-order-table-card #datatable td:nth-child(6) { width: 10%; }
        .nz-order-table-card #datatable th:nth-child(7), .nz-order-table-card #datatable td:nth-child(7) { width: 11%; }
        .nz-order-table-card #datatable th:nth-child(8), .nz-order-table-card #datatable td:nth-child(8) { width: 12%; }
        .nz-order-table-card #datatable th:nth-child(9), .nz-order-table-card #datatable td:nth-child(9) { width: 6%; }
        /* 哪吒P1b-D 待退款两段分隔行 */
        #datatable tr.nz-refund-seg td { display: table-cell !important; width: auto !important; text-align: left !important; border-bottom: 0 !important; padding: 9px 14px !important; font-weight: 700; }
        #datatable tr.nz-refund-seg td::before { content: none !important; display: none !important; }
        #datatable tr.nz-refund-seg.segA td { background: #FEECEC; border-left: 3px solid #E5484D; color: #A3121B; }
        #datatable tr.nz-refund-seg.segB td { background: #F1F3F5; border-left: 3px solid #98A2B3; color: #344054; }
        /* 哪吒: 待退款分区 ⓘ 说明气泡 */
        .nz-seg-name { font-weight: 700; }
        .nz-seg-info { display:inline-flex; align-items:center; justify-content:center; width:16px; height:16px; border-radius:50%; margin-left:6px; font-size:11px; font-weight:700; font-style:normal; line-height:1; color:#fff; cursor:help; vertical-align:middle; position:relative; top:-1px; -webkit-user-select:none; user-select:none; }
        .nz-seg-info:focus { outline:2px solid rgba(16,42,76,.35); outline-offset:1px; }
        .nz-refund-seg.segA .nz-seg-info { background:#E5484D; }
        .nz-refund-seg.segB .nz-seg-info { background:#98A2B3; }
        .nz-seg-tooltip .tooltip-inner { background:#102A4C; color:#EAF0F7; max-width:290px; text-align:left; font-size:12.5px; line-height:1.7; padding:10px 13px; border-radius:9px; box-shadow:0 6px 20px rgba(16,24,40,.22); }
        .nz-seg-tooltip .arrow::before { border-top-color:#102A4C !important; border-bottom-color:#102A4C !important; }
        @media (max-width: 767px) { #datatable tr.nz-refund-seg { display: block; } #datatable tr.nz-refund-seg td { display: block; width: 100% !important; } }
        .nz-print-settings { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 10px 16px; border-bottom: 1px solid #EDF1F5; background: #FFF7F8; color: #7c1228; font-size: 13px; }
        .nz-print-settings label { display: inline-flex; align-items: center; gap: 6px; margin: 0; font-weight: 700; }
        .nz-print-settings input { accent-color: #C4193E; }
        .nz-print-settings .btn { border-radius: 7px; }
        .nz-row-check { display:none; width:16px; height:16px; accent-color:#C4193E; vertical-align:middle; cursor:pointer; }
        body.nz-batch-mode .nz-row-check { display:inline-block; }
        body.nz-batch-mode .nz-sl-num { display:none; }
        .nz-batch-bar { display:none; align-items:center; flex-wrap:wrap; gap:12px; padding:9px 16px; border-bottom:1px solid #EDF1F5; background:#FFF7F8; }
        body.nz-batch-mode .nz-batch-bar { display:flex; }
        .nz-batch-bar label { display:inline-flex; align-items:center; gap:6px; margin:0; font-size:13px; font-weight:700; color:#7c1228; cursor:pointer; }
        .nz-batch-bar label input { width:16px; height:16px; accent-color:#C4193E; }
        .nz-batch-bar .nz-batch-count { font-size:13px; color:#7c1228; font-weight:700; }
        .nz-batch-spacer { flex:1; }
        .nz-batch-cancel { border:1px solid #D8E0EA; background:#fff; color:#475467; border-radius:7px; padding:6px 12px; font-size:13px; font-weight:700; cursor:pointer; }
        .nz-batch-go { border:0; background:#C4193E; color:#fff; border-radius:7px; padding:6px 14px; font-size:13px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
        .nz-batch-go:disabled { opacity:.5; cursor:not-allowed; }
        @media print { .nz-batch-bar { display:none !important; } }
        .nz-order-id { font-size: 16px; font-weight: 800; color: #102A4C; }
        .nz-order-foods { font-size: 13.5px; color: #475467; max-width: 220px; white-space: normal; line-height: 1.42; margin-top: 4px; font-weight: 700; }
        .nz-order-time strong { display: block; font-size: 14px; color: #102A4C; }
        .nz-order-money { font-size: 15px; font-weight: 800; color: #102A4C; }
        .nz-order-converted-amounts { margin-top: 3px; color: #667085; font-size: 11px; line-height: 1.35; font-weight: 700; }
        .nz-order-converted-amounts span { display: block; white-space: nowrap; }
        .nz-resizable-table th { position: relative; }
        .nz-resizable-table thead th { border-right: 0 !important; }
        .nz-col-resizer { position: absolute; top: 0; right: -4px; width: 8px; height: 100%; cursor: col-resize; user-select: none; z-index: 3; }
        .nz-col-resizer::after { content: ""; position: absolute; top: 50%; left: 3px; width: 2px; height: 28px; max-height: calc(100% - 14px); transform: translateY(-50%); border-radius: 2px; background: #C3CDDB; }
        .nz-col-resizer:hover::after, body.nz-col-resizing .nz-col-resizer::after { background: #94A0AF; }
        /* 哪吒 P7: 列设置(列显隐 + 工具按钮显隐, localStorage 本机记住) */
        .nz-col-settings-wrap { position: relative; margin-left: auto; }
        .nz-col-settings-btn { display: inline-flex; align-items: center; white-space: nowrap; }
        .nz-col-settings-menu { position: fixed; z-index: 11050; width: 264px; background: #fff; border: 1px solid #E4E9F0; border-radius: 11px; box-shadow: 0 12px 30px rgba(20,22,40,.16); padding: 8px 6px 10px; display: none; }
        .nz-col-settings-menu.nz-open { display: block; }
        .nz-col-sec-label { font-size: 12px; font-weight: 600; color: #8A94A6; padding: 6px 10px 4px; }
        .nz-col-opt { display: flex; align-items: center; gap: 10px; padding: 7px 10px; margin: 0; border-radius: 7px; font-size: 13.5px; font-weight: 500; color: #1F2329; cursor: pointer; }
        .nz-col-opt:hover { background: #F4F6F9; }
        .nz-col-opt input { width: 16px; height: 16px; accent-color: #102A4C; flex: 0 0 auto; }
        .nz-col-opt span { flex: 1; }
        .nz-col-opt.nz-col-locked { color: #98A2B3; cursor: default; }
        .nz-col-opt.nz-col-locked:hover { background: transparent; }
        .nz-col-opt.nz-col-locked input { accent-color: #9AA4B2; }
        .nz-col-opt.nz-col-locked i { font-size: 14px; color: #B7BECB; }
        .nz-col-div { height: 1px; background: #F0F2F5; margin: 6px 8px; }
        .nz-col-hint { display: flex; gap: 7px; padding: 9px 10px; margin: 8px 6px 0; background: #F7F9FB; border: 1px solid #EAEEF3; border-radius: 8px; font-size: 12px; color: #667085; line-height: 1.5; }
        .nz-col-hint i { font-size: 14px; color: #98A2B3; flex: 0 0 auto; margin-top: 1px; }
        .nz-col-foot { display: flex; align-items: center; justify-content: space-between; padding: 10px 10px 2px; }
        .nz-col-reset { display: inline-flex; align-items: center; gap: 5px; border: 0; background: none; color: #475467; font-size: 12.5px; font-weight: 600; cursor: pointer; padding: 0; }
        .nz-col-foot-note { font-size: 11.5px; color: #98A2B3; }
        @media (max-width: 767.98px) { .nz-col-settings-wrap { display: none; } }
        .nz-payment-proof-list { display: flex; gap: 6px; margin-top: 7px; flex-wrap: wrap; }
        .nz-payment-proof-list--status { justify-content: center; margin-top: 0; margin-bottom: 8px; }
        .nz-payment-proof-thumb { width: 42px; height: 42px; padding: 0; border: 1px solid #D8E0EA; border-radius: 7px; background: #fff; overflow: hidden; cursor: zoom-in; }
        .nz-payment-proof-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .nz-proof-modal-img { width: 100%; max-height: 76vh; object-fit: contain; background: #0F172A; border-radius: 8px; }
        .nz-order-status-muted { color: #8A94A6; font-size: 12px; font-weight: 600; }
        .nz-step-empty { color: #98A2B3; font-size: 12px; font-weight: 700; }
        .nz-pay-method { display:inline-block; padding:2px 9px; border-radius:8px; background:#F1F3F6; color:#344054; font-size:12.5px; font-weight:600; white-space:nowrap; }
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
        .nz-status-tabs .nz-tab-count { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 7px; margin-left: 1px; border-radius: 999px; background: #EEF2F6; color: #475467; font-size: 11.5px; line-height: 1; font-weight: 900; box-shadow: inset 0 0 0 1px rgba(16,24,40,.04); }
        .nz-status-tabs a.active .nz-tab-count { background: #1F6FD0; color: #fff; box-shadow: 0 2px 6px rgba(31,111,208,.22); }
        .nz-status-tabs .nz-tab-count.is-zero { color: #98A2B3; background: #F4F6F9; }
        .nz-status-tabs a.active .nz-tab-count.is-zero { color: #EAF1FF; background: #5A8FDB; }
        /* 哪吒P1b-C: 需动作组高亮(有单时告警红=V2 #E5484D/#FEECEC 系, 同段A退款头, 非顾客端洋红遗留) + 二级 chip 行 */
        .nz-group-tabs .nz-group-tab.nz-group-action-hot { border-color: #F3C1C4; background: #FEECEC; color: #A3121B; }
        .nz-group-tabs .nz-group-tab.nz-group-action-hot .nz-tab-count { background: #E5484D; color: #fff; box-shadow: none; }
        .nz-group-tabs .nz-group-tab.nz-group-action-hot.active { border-color: #E5484D; background: #FCDCDE; color: #8F1019; }
        .nz-status-chips { display: flex; flex-wrap: wrap; gap: 7px; margin: -3px 0 12px; padding-left: 2px; }
        .nz-status-chips .nz-chip { display: inline-flex; align-items: center; gap: 5px; min-height: 30px; padding: 5px 11px; border: 1px solid #E9EDF2; border-radius: 999px; background: #F7F9FB; color: #475467; font-size: 12px; font-weight: 700; }
        .nz-status-chips .nz-chip.active { border-color: #102A4C; background: #102A4C; color: #fff; }
        .nz-status-chips .nz-chip-count { display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 6px; border-radius: 999px; background: #E7ECF2; color: #667085; font-size: 11px; font-weight: 800; line-height: 1; }
        .nz-status-chips .nz-chip.active .nz-chip-count { background: rgba(255,255,255,.22); color: #fff; }
        .nz-status-chips .nz-chip-count.is-zero { color: #A9B2C0; background: #F0F3F7; }
        .nz-status-chips .nz-chip.active .nz-chip-count.is-zero { background: rgba(255,255,255,.16); color: #D9E2F0; }
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
        .nz-step-btn.nz-dispatch-open { background:#102A4C !important; border-color:#102A4C !important; color:#fff !important; }
        .nz-step-btn.nz-dispatch-open:hover { background:#1B3A63 !important; border-color:#1B3A63 !important; }
        body.nz-dispatch-lock { overflow: hidden; }
        .nz-dispatch-drawer { position: fixed; inset: 0; z-index: 11050; display: none; }
        .nz-dispatch-drawer.nz-open { display: block; }
        .nz-dispatch-backdrop { position: absolute; inset: 0; background: rgba(16,24,40,.45); }
        .nz-dispatch-sheet { position: absolute; left: 0; right: 0; bottom: 0; top: auto; background: #fff; border-radius: 16px 16px 0 0; height: 88vh; max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 -8px 40px rgba(10,25,47,.4); }
        .nz-dispatch-grip { flex: 0 0 auto; width: 44px; height: 4px; border-radius: 99px; background: #D6DBE1; margin: 8px auto 2px; }
        .nz-dispatch-body { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; overflow: hidden; }
        @media (min-width: 768px) {
            .nz-dispatch-sheet { left: auto; right: 0; top: 0; bottom: 0; transform: none; width: 440px; max-width: 92vw; height: 100vh; max-height: 100vh; border-radius: 0; box-shadow: -12px 0 40px rgba(10,25,47,.35); }
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
            .nz-status-chips { flex-wrap: nowrap; overflow-x: auto; padding: 0 1px 4px; margin: 0 -1px 8px; scrollbar-width: none; }
            .nz-status-chips::-webkit-scrollbar { display: none; }
            .nz-status-chips .nz-chip { flex: 0 0 auto; min-height: 34px; border-radius: 999px; }
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
        'refund_pending' => ['label' => '待退款', 'hint' => '请商家按原路退还顾客后在此标记已退款。', 'empty' => '暂无待退款订单。', 'icon' => 'tio-receipt-outlined'],
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
    // 超时(timeout)是虚拟过滤视图, 补一条 meta 供 chip / hero 使用。
    $nzStatusMeta['timeout'] = ['label' => '超时', 'hint' => '已超过处理时限、仍未推进的订单，请尽快处理。', 'empty' => '暂无超时订单。', 'icon' => 'tio-time'];
    // done_canceled = 已完结组的「已取消」(无未结退款), 与全部平铺的 canceled(含待退款) 区分。
    $nzStatusMeta['done_canceled'] = ['label' => '已取消', 'hint' => '已取消且无未结退款的订单；带未结退款的取消单请在「售后」处理。', 'empty' => '暂无已取消（无未结退款）的订单。', 'icon' => 'tio-clear-circle-outlined'];
    // 哪吒P1b-C: 订单页一级 tab 由 14 平铺收敛为 4+1 组; 组内二级 chip 懒展开(仅当前组)。
    // 组过滤/计数走单一真相源 NezhaOrderCounts(applyGroupFilter + grp_* rollup), 与控制器 list($status) 同源。
    $nzGroups = [
        'grp_action' => ['label' => '需动作', 'icon' => 'tio-notifications-active', 'count_key' => 'grp_action',
            'hint' => '需要你现在处理的订单：确认收款、回应催促、超时、退款申请与待退款集中在这里。', 'empty' => '太好了，暂时没有需要处理的订单。',
            'chips' => ['offline_pending', 'customer_nudged', 'timeout', 'refund_requested', 'refund_pending']],
        'grp_ongoing' => ['label' => '进行中', 'icon' => 'tio-restaurant', 'count_key' => 'grp_ongoing',
            'hint' => '正在履约的订单：从确认收款到配送送达的完整流程。', 'empty' => '暂无进行中的订单。',
            'chips' => ['offline_pending', 'confirmed', 'cooking', 'ready_for_delivery', 'food_on_the_way', 'scheduled']],
        'grp_aftersale' => ['label' => '售后', 'icon' => 'tio-receipt-outlined', 'count_key' => 'grp_aftersale',
            'hint' => '退款相关的订单集中一处，便于核对与原路退款。', 'empty' => '暂无售后订单。',
            'chips' => ['refund_requested', 'refund_pending', 'refunded']],
        'grp_done' => ['label' => '已完结', 'icon' => 'tio-done-all', 'count_key' => 'grp_done',
            'hint' => '已送达、已取消（无未结退款）与支付失败的历史订单。', 'empty' => '暂无已完结的订单。',
            'chips' => ['delivered', 'done_canceled', 'payment_failed']],
        'all' => ['label' => '全部', 'icon' => 'tio-folder-bookmarked', 'count_key' => 'all',
            'hint' => '全部订单，可搜索、导出，并用「显示已完成·近N天」筛选历史单。', 'empty' => '当前还没有订单。',
            'chips' => []],
    ];
    // chip → 归属的默认组(高亮哪个组 tab; offline_pending / refund_pending 挂两组, 默认落需动作, ?g= 可覆盖)
    $nzChipHome = [
        'offline_pending' => 'grp_action', 'customer_nudged' => 'grp_action', 'timeout' => 'grp_action', 'refund_pending' => 'grp_action',
        'confirmed' => 'grp_ongoing', 'cooking' => 'grp_ongoing', 'ready_for_delivery' => 'grp_ongoing', 'food_on_the_way' => 'grp_ongoing', 'scheduled' => 'grp_ongoing',
        'refund_requested' => 'grp_aftersale', 'refunded' => 'grp_aftersale',
        'delivered' => 'grp_done', 'canceled' => 'grp_done', 'done_canceled' => 'grp_done', 'payment_failed' => 'grp_done',
    ];
    // 当前激活组: ?g= 优先(点 chip 时带上, 保持所在组高亮); 否则组落地取自身, chip 落地取其主组, 兜底全部。
    $nzReqGroup = request('g');
    $nzActiveGroup = isset($nzGroups[$nzReqGroup]) ? $nzReqGroup
        : (isset($nzGroups[$nzRawStatus]) ? $nzRawStatus
        : ($nzChipHome[$nzRawStatus] ?? 'all'));
    // hero / 空态 meta: 组落地用组 meta; chip 落地用该状态 meta。
    $nzCurrentMeta = $nzGroups[$nzRawStatus]
        ?? ($nzStatusMeta[$nzRawStatus] ?? ['label' => str_replace('_', ' ', $nzRawStatus), 'hint' => '查看该状态下的订单。', 'empty' => '暂无该状态订单。', 'icon' => 'tio-shopping-cart']);
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
    $nzRestaurantId = \App\CentralLogics\Helpers::get_restaurant_id();
    // 哪吒P1b-A: 订单计数收口到单一真相源 NezhaOrderCounts(与看板待办条同源, 修"看板待确认收款0 vs 列表1")。
    $nzStatusCounts = \App\CentralLogics\NezhaOrderCounts::forRestaurant($nzRestaurantId);
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
            {{-- 哪吒P1b-C: 一级=4+1 组 tab(计数走 grp_* rollup); 二级=当前组 chip 行(懒展开, 全部组无 chip) --}}
            <div class="nz-status-tabs nz-group-tabs nz-mobile-status-strip d-print-none">
                @foreach($nzGroups as $__gKey => $__g)
                    @php
                        $__gStatus = $__gKey === 'all' ? 'all' : $__gKey;
                        $__gCount = $nzStatusCounts[$__g['count_key']] ?? 0;
                        $__gActive = $nzActiveGroup === $__gKey;
                    @endphp
                    <a href="{{ route('vendor.order.list', [$__gStatus]) }}"
                       class="nz-group-tab {{ $__gActive ? 'active' : '' }} {{ $__gKey === 'grp_action' && $__gCount > 0 ? 'nz-group-action-hot' : '' }}">
                        <i class="{{ $__g['icon'] }}"></i>
                        <span>{{ $__g['label'] }}</span>
                        <span class="nz-tab-count {{ $__gCount == 0 ? 'is-zero' : '' }}">{{ $__gCount }}</span>
                    </a>
                @endforeach
            </div>
            @if($nzActiveGroup !== 'all' && !empty($nzGroups[$nzActiveGroup]['chips']))
            <div class="nz-status-chips nz-mobile-status-strip d-print-none">
                @foreach($nzGroups[$nzActiveGroup]['chips'] as $__chip)
                    @php
                        $__cMeta = $nzStatusMeta[$__chip] ?? ['label' => $__chip];
                        $__cCount = $nzStatusCounts[$__chip] ?? 0;
                        $__cActive = $nzRawStatus === $__chip;
                    @endphp
                    <a href="{{ route('vendor.order.list', [$__chip]) }}?g={{ $nzActiveGroup }}"
                       class="nz-chip {{ $__cActive ? 'active' : '' }}">
                        <span>{{ $__cMeta['label'] ?? $__chip }}</span>
                        <span class="nz-chip-count {{ $__cCount == 0 ? 'is-zero' : '' }}">{{ $__cCount }}</span>
                    </a>
                @endforeach
            </div>
            @endif
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
                                    class="hs-unfold-content dropdown-unfold dropdown-menu dropdown-menu-sm-right nz-export-dropdown">

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
                    {{-- 哪吒 P7: 列设置(列显隐 + 工具按钮显隐, 核心列锁死, 本机记住) --}}
                    <div class="nz-col-settings-wrap d-print-none" id="nzColSettingsWrap">
                        <button type="button" class="btn btn-sm btn-white nz-col-settings-btn" id="nzColSettingsBtn" aria-haspopup="true" aria-expanded="false">
                            <i class="tio-settings mr-1"></i>列设置<i class="tio-chevron-down ml-1"></i>
                        </button>
                        <div class="nz-col-settings-menu" id="nzColSettingsMenu" role="menu" aria-label="列与工具显示设置">
                            <div class="nz-col-sec-label">显示的列</div>
                            <label class="nz-col-opt"><input type="checkbox" data-nz-col="sl" checked><span>序号</span></label>
                            <label class="nz-col-opt nz-col-locked"><input type="checkbox" checked disabled><span>订单号</span><i class="tio-lock"></i></label>
                            <label class="nz-col-opt"><input type="checkbox" data-nz-col="date" checked><span>日期</span></label>
                            <label class="nz-col-opt"><input type="checkbox" data-nz-col="customer" checked><span>顾客</span></label>
                            <label class="nz-col-opt"><input type="checkbox" data-nz-col="total" checked><span>金额</span></label>
                            <label class="nz-col-opt"><input type="checkbox" data-nz-col="payment" checked><span>支付方式</span></label>
                            <label class="nz-col-opt nz-col-locked"><input type="checkbox" checked disabled><span>订单状态</span><i class="tio-lock"></i></label>
                            <label class="nz-col-opt nz-col-locked"><input type="checkbox" checked disabled><span>下一步操作</span><i class="tio-lock"></i></label>
                            <label class="nz-col-opt"><input type="checkbox" data-nz-col="more" checked><span>更多（⋯ 菜单）</span></label>
                            <div class="nz-col-div"></div>
                            <div class="nz-col-sec-label">工具按钮</div>
                            <label class="nz-col-opt"><input type="checkbox" data-nz-tool="export" checked><span>导出</span></label>
                            <label class="nz-col-opt"><input type="checkbox" data-nz-tool="batch" checked><span>批量打印</span></label>
                            <div class="nz-col-hint"><i class="tio-info-outlined"></i><span>隐藏「更多」后，补打小票可点订单号进详情页，不影响操作。</span></div>
                            <div class="nz-col-foot">
                                <button type="button" class="nz-col-reset" id="nzColReset"><i class="tio-refresh"></i>恢复默认</button>
                                <span class="nz-col-foot-note">仅本机保存</span>
                            </div>
                        </div>
                    </div>
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
                <button type="button" class="btn btn-sm btn-outline-primary" id="nzBatchOpenBtn"><i class="tio-print mr-1"></i>批量打印</button>
                <span class="text-muted" style="font-weight:600;">未确认接入时不会自动弹打印，避免误触。</span>
            </div>
            <div class="nz-batch-bar d-print-none" id="nzBatchBar">
                <label><input type="checkbox" id="nzBatchAll">全选本页</label>
                <span class="nz-batch-count" id="nzBatchCount">已选 0 单</span>
                <span class="nz-batch-spacer"></span>
                <button type="button" class="nz-batch-cancel" id="nzBatchCancel">取消</button>
                <button type="button" class="nz-batch-go" id="nzBatchGo" disabled><i class="tio-print"></i>打印选中 (0)</button>
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
                        <th class="w-120px text-center">支付方式</th>
                        <th class="w-110px text-center">{{translate('messages.order_status')}}</th>
                        <th class="w-130px text-center">下一步操作</th>
                        <th class="w-56px text-center">更多</th>
                    </tr>
                    </thead>

                    <tbody id="set-rows">
                    @php $__nzSeg = null; @endphp
                    @foreach($orders as $key=>$order)
                        @if($nzRawStatus === 'refund_pending')
                            @php $__rowSeg = ($order->payment_status == 'paid') ? 'A' : 'B'; @endphp
                            @if($__rowSeg !== $__nzSeg)
                                @php $__nzSeg = $__rowSeg; @endphp
                                <tr class="nz-refund-seg seg{{ $__rowSeg }}">
                                    <td colspan="10">
                                        @if($__rowSeg === 'A')
                                            <strong class="nz-seg-name">已收款，请退还顾客</strong><span class="nz-seg-info" tabindex="0" role="button" data-toggle="tooltip" data-placement="bottom" data-boundary="viewport" data-trigger="hover focus" data-template='<div class="tooltip nz-seg-tooltip" role="tooltip"><div class="arrow"></div><div class="tooltip-inner"></div></div>' title="这笔订单您此前已确认收到顾客付款。现在订单已取消，请按原路（微信/支付宝原路退回，或 USDT 退回原地址）退还给顾客，退好后点「标记已退款」。">i</span>
                                        @else
                                            <strong class="nz-seg-name">先核对账户，再退还</strong><span class="nz-seg-info" tabindex="0" role="button" data-toggle="tooltip" data-placement="bottom" data-boundary="viewport" data-trigger="hover focus" data-template='<div class="tooltip nz-seg-tooltip" role="tooltip"><div class="arrow"></div><div class="tooltip-inner"></div></div>' title="顾客已上传付款凭证，但平台尚未确认您收到款。请先登录您的收款账户核对这笔钱是否真的到账：确认到账后，再按原路退还给顾客。">i</span>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endif
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
                                <input type="checkbox" class="nz-row-check" aria-label="选择本单" data-nz-oid="{{$order['id']}}"><span class="nz-sl-num">{{$key+$orders->firstItem()}}</span>
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
                                            <small class="text-success">已收款</small>
                                        @elseif($order->payment_status=='partially_paid')
                                            <small class="text-success">部分收款</small>
                                        @elseif($order->payment_status=='refunded')
                                            <small class="text-muted">{{translate('messages.refunded')}}</small>
                                        @else
                                            <small class="text-muted">未确认收款</small>
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
                                    <div class="text-muted">{{\App\CentralLogics\NezhaContactVisibility::phone($customer_details['contact_person_number'] ?? '', $order->created_at ?? null)}}</div>
                                @elseif($order->customer)
                                    <a class="text-body text-capitalize"
                                        href="{{route('vendor.order.details',['id'=>$order['id']])}}">
                                        <span class="d-block font-semibold">
                                                {{$order->customer['f_name'].' '.$order->customer['l_name']}}
                                        </span>
                                        <span class="d-block text-muted">
                                                {{\App\CentralLogics\NezhaContactVisibility::phone($order->customer['phone'] ?? '', $order->created_at ?? null)}}
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
                                        已收款
                                    </strong>
                                    @elseif($order->payment_status=='partially_paid')
                                        <strong class="text-success">
                                            部分收款
                                        </strong>
                                    @elseif($order->payment_status=='refunded')
                                        <strong class="text-muted">
                                            {{translate('messages.refunded')}}
                                        </strong>
                                    @else
                                        <strong class="text-muted">
                                            未确认收款
                                        </strong>
                                    @endif
                                </div>

                            </td>
                            <td class="text-center nz-order-pay-cell" data-label="支付方式">
                                @php
                                    $__pm = $order['payment_method'] ?? null;
                                    $__payLabel = null;
                                    if ($__pm === 'offline_payment' && $order->offline_payments) {
                                        $__pinfo = json_decode($order->offline_payments->payment_info, true) ?: [];
                                        $__payLabel = $__pinfo['method_name'] ?? null;
                                    }
                                    elseif ($__pm === 'cash_on_delivery') {
                                        $__payLabel = translate('messages.cash_on_delivery');
                                    } elseif ($__pm === 'digital_payment') {
                                        $__payLabel = translate('messages.digital_payment');
                                    }
                                @endphp
                                @if($__payLabel)
                                    <span class="nz-pay-method">{{ $__payLabel }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
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
                                    @elseif($order['order_status']=='refunded')
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
                                    } elseif ($__os === 'pending' && $order->payment_method === 'offline_payment') {
                                        // 哪吒P1b-B 裁决①: 无凭证离线单(顾客未传凭证) → 无按钮·灰字等凭证(与详情页wait态同源语义)
                                        $__qa = ['type' => 'wait', 'label' => '等顾客传凭证'];
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
                                        $__rr = \App\Models\NezhaRefundRecord::where('order_id', $order['id'])
                                            ->where('restaurant_id', \App\CentralLogics\Helpers::get_restaurant_id())
                                            ->whereIn('status', \App\Models\NezhaRefundRecord::STATUS_UNRESOLVED)
                                            ->latest('id')->first();
                                        $__refundPending = (bool) $__rr;
                                        $__refundDisputed = $__rr && $__rr->status === 'disputed';
                                        if ($__refundPending) {
                                            if ($__refundDisputed) {
                                                $__qa = ['type' => 'link', 'route' => route('vendor.order.details', ['id' => $order['id']]),
                                                          'label' => '争议审核中', 'title' => '退款争议审核中·平台核实中，暂不可退款',
                                                          'cls' => 'btn-secondary', 'icon' => 'tio-time'];
                                            } elseif ($order->payment_status == 'paid') {
                                                // 哪吒P1b-D 段A: 已确认收款·真欠退 → 标记已退款(原 L1 强确认 endpoint 不变)
                                                $__qa = ['route' => route('vendor.order.mark-refunded', ['id' => $order['id']]),
                                                          'label' => '标记已退款', 'cls' => 'btn-warning', 'icon' => 'tio-receipt-outlined',
                                                          'confirm' => '请确认：您已在自己的账户按原路退还本单顾客的付款？'];
                                            } else {
                                                // 哪吒P1b-D 段B: 凭证在案·先核后退 → 进详情页退款核对卡(先核对收款账户再退·不留一键出口)
                                                $__qa = ['type' => 'link', 'route' => route('vendor.order.details', ['id' => $order['id']]),
                                                          'label' => '去核对退款', 'title' => '凭证在案·请先核对您的收款账户，确认到账后再按原路退还',
                                                          'cls' => 'btn-warning', 'icon' => 'tio-open-in-new'];
                                            }
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
                                @elseif($__qa && (($__qa['type'] ?? 'form') === 'wait'))
                                    {{-- 哪吒P1b-B 裁决①: 无凭证离线单·灰字无按钮(与详情页wait态语义一致) --}}
                                    <span class="text-muted text-nowrap" style="font-size:12px;" title="顾客尚未上传付款凭证，等顾客提交凭证后再确认收款"><i class="tio-time mr-1"></i>{{ $__qa['label'] }}</span>
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
                                        @if(!empty($__qa['prep_prompt'])) data-nz-prep-default="{{ $__qa['prep_default'] ?? 30 }}" data-nz-prep-title="{{ $__qa['prep_title'] ?? '开始备餐' }}" data-nz-prep-ok="{{ $__qa['prep_ok'] ?? '确认' }}" data-nz-prep-color="{{ $__qa['prep_color'] ?? '#1F6FD0' }}" data-nz-prep-note="{{ $__qa['prep_note'] ?? '' }}" data-nz-prep-confirm="{{ $__qa['confirm'] ?? '' }}"@else data-nz-confirm-msg="{{ $__qa['confirm'] }}"@endif>
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
                        @include('vendor-views.order.partials._dispatch_tools', ['order' => $__do, 'nzDrawer' => true])
                    </div>
                @endif
            @endforeach
        </div>
        <div class="nz-dispatch-drawer d-print-none" id="nzDispatchDrawer" aria-hidden="true">
            <div class="nz-dispatch-backdrop" data-nz-dispatch-close></div>
            <div class="nz-dispatch-sheet" role="dialog" aria-modal="true" aria-label="Yandex Go 配送">
                <div class="nz-dispatch-grip"></div>
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
                    window.nzToast('浏览器拦截了打印窗口，请允许本站弹出窗口后重试。', 'warning');
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
                    var sc = src.querySelector('.nzyx-scroll'); if (sc) sc.scrollTop = 0;
                    openId = id;
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
                form.addEventListener('submit', function(e){ e.preventDefault(); window.nzConfirm({ body: '确认拒接本单？订单将取消并通知顾客。若顾客已付款，需你按原路退还。', danger: true, okText: '拒接本单' }).then(function(ok){ if (ok) form.submit(); }); });
                document.addEventListener('keydown', function(e){ if (e.key === 'Escape'){ closeMenu(); closeReject(); } });
            }

            function initColumnResize(){
                var table = document.getElementById('datatable');
                if (!table || window.innerWidth < 768) return;
                var ths = Array.prototype.slice.call(table.querySelectorAll('thead th'));
                if (ths.length < 2) return;
                var storeKey = 'nzOrderColumnWidths:' + location.pathname;
                try {
                    var saved = JSON.parse(localStorage.getItem(storeKey) || '[]');
                    saved.forEach(function(width, i){
                        if (width && ths[i]) ths[i].style.width = width + 'px';
                    });
                } catch (e) {}
                // 拖动=「邻列让位」: 拖某列右缘只在本列与右邻列之间平移宽度, 总宽不变、其余列不动(自然, 不再整表回流"吸附")。末列无右邻不放手柄。
                ths.forEach(function(th, colIndex){
                    if (colIndex === ths.length - 1) return;
                    if (th.querySelector('.nz-col-resizer')) return;
                    var grip = document.createElement('span');
                    grip.className = 'nz-col-resizer';
                    grip.setAttribute('aria-hidden', 'true');
                    th.appendChild(grip);
                    grip.addEventListener('pointerdown', function(e){
                        e.preventDefault();
                        var neighbor = ths[colIndex + 1];
                        var wAll = ths.map(function(it){ return it.offsetWidth; });   // 先读全部再统一写(避免边读边写逐次 reflow 漂移)
                        ths.forEach(function(it, i){ it.style.width = wAll[i] + 'px'; });
                        var startX = e.clientX;
                        var startW = wAll[colIndex];
                        var startNext = wAll[colIndex + 1];
                        var pairTotal = startW + startNext;
                        document.body.classList.add('nz-col-resizing');
                        function onMove(ev){
                            var delta = ev.clientX - startX;
                            var cur = startW + delta;
                            var nxt = startNext - delta;
                            if (cur < 54) { cur = 54; nxt = pairTotal - 54; }
                            if (nxt < 54) { nxt = 54; cur = pairTotal - 54; }
                            th.style.width = cur + 'px';
                            neighbor.style.width = nxt + 'px';
                        }
                        function onUp(){
                            document.removeEventListener('pointermove', onMove);
                            document.removeEventListener('pointerup', onUp);
                            document.body.classList.remove('nz-col-resizing');
                            var prevW = [];
                            try { prevW = JSON.parse(localStorage.getItem(storeKey) || '[]') || []; } catch (e) { prevW = []; }
                            var widths = ths.map(function(item, i){ var w = Math.round(item.offsetWidth); return w > 0 ? w : (prevW[i] || 0); });
                            try { localStorage.setItem(storeKey, JSON.stringify(widths)); } catch (e) {}
                        }
                        document.addEventListener('pointermove', onMove);
                        document.addEventListener('pointerup', onUp);
                    });
                });
            }

            function initColumnSettings(){
                var wrap = document.getElementById('nzColSettingsWrap');
                var btn = document.getElementById('nzColSettingsBtn');
                var menu = document.getElementById('nzColSettingsMenu');
                var table = document.getElementById('datatable');
                if (!menu || !table) return;
                var STORE_KEY = 'nzOrderColPrefs';
                // 可隐列 → 表头 1-based 列序(锁死列 订单号2/状态7/下一步8 不在此表, 不可隐)
                var COL_POS = { sl: 1, date: 3, customer: 4, total: 5, payment: 6, more: 9 };
                var styleEl = document.getElementById('nzColHideStyle');
                if (!styleEl){ styleEl = document.createElement('style'); styleEl.id = 'nzColHideStyle'; document.head.appendChild(styleEl); }
                var prefs = {};
                try { prefs = JSON.parse(localStorage.getItem(STORE_KEY) || '{}') || {}; } catch (e) { prefs = {}; }
                function save(){ try { localStorage.setItem(STORE_KEY, JSON.stringify(prefs)); } catch (e) {} }
                function apply(){
                    var css = '';
                    Object.keys(COL_POS).forEach(function(key){
                        if (prefs['col_' + key] === false){
                            var p = COL_POS[key];
                            css += '#datatable thead th:nth-child(' + p + '),#datatable tbody td:nth-child(' + p + '){display:none !important;}';
                            if (key === 'more'){ css += '#datatable tr.class-all td.nz-row-more-cell{display:none !important;}'; }
                        }
                    });
                    styleEl.textContent = css;
                    var exp = document.querySelector('.nz-export-area');
                    if (exp) { if (prefs['tool_export'] === false) { exp.style.setProperty('display', 'none', 'important'); } else { exp.style.removeProperty('display'); } }
                    var batchBtn = document.getElementById('nzBatchOpenBtn');
                    if (batchBtn) { if (prefs['tool_batch'] === false) { batchBtn.style.setProperty('display', 'none', 'important'); } else { batchBtn.style.removeProperty('display'); } }
                    if (prefs['tool_batch'] === false && document.body.classList.contains('nz-batch-mode')){
                        var bc = document.getElementById('nzBatchCancel'); if (bc) bc.click();
                    }
                    if (menu){
                        Array.prototype.slice.call(menu.querySelectorAll('input[data-nz-col]')).forEach(function(cb){ cb.checked = prefs['col_' + cb.getAttribute('data-nz-col')] !== false; });
                        Array.prototype.slice.call(menu.querySelectorAll('input[data-nz-tool]')).forEach(function(cb){ cb.checked = prefs['tool_' + cb.getAttribute('data-nz-tool')] !== false; });
                    }
                }
                apply();
                if (!wrap || !btn) return;
                function place(){
                    var r = btn.getBoundingClientRect();
                    menu.style.visibility = 'hidden'; menu.style.display = 'block';
                    var mw = menu.offsetWidth, mh = menu.offsetHeight;
                    menu.style.display = ''; menu.style.visibility = '';
                    var left = Math.max(8, Math.min(r.right - mw, window.innerWidth - mw - 8));
                    var top = r.bottom + 6;
                    if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 6);
                    menu.style.left = left + 'px';
                    menu.style.top = top + 'px';
                }
                function openMenu(){ place(); menu.classList.add('nz-open'); btn.setAttribute('aria-expanded', 'true'); }
                function closeMenu(){ menu.classList.remove('nz-open'); btn.setAttribute('aria-expanded', 'false'); }
                btn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); if (menu.classList.contains('nz-open')) closeMenu(); else openMenu(); });
                document.addEventListener('click', function(e){ if (menu.classList.contains('nz-open') && !menu.contains(e.target) && !btn.contains(e.target)) closeMenu(); });
                document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeMenu(); });
                window.addEventListener('resize', closeMenu);
                menu.addEventListener('change', function(e){
                    var cb = e.target;
                    if (!cb || !cb.getAttribute) return;
                    if (cb.hasAttribute('data-nz-col')){ prefs['col_' + cb.getAttribute('data-nz-col')] = cb.checked; save(); apply(); }
                    else if (cb.hasAttribute('data-nz-tool')){ prefs['tool_' + cb.getAttribute('data-nz-tool')] = cb.checked; save(); apply(); }
                });
                var resetBtn = document.getElementById('nzColReset');
                if (resetBtn) resetBtn.addEventListener('click', function(){ prefs = {}; save(); apply(); });
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

            function initBatchPrint(){
                var openBtn = document.getElementById('nzBatchOpenBtn');
                var bar = document.getElementById('nzBatchBar');
                var allCb = document.getElementById('nzBatchAll');
                var countEl = document.getElementById('nzBatchCount');
                var goBtn = document.getElementById('nzBatchGo');
                var cancelBtn = document.getElementById('nzBatchCancel');
                if (!openBtn || !bar || !goBtn || !allCb) return;
                function rowChecks(){ return Array.prototype.slice.call(document.querySelectorAll('.nz-row-check')); }
                function selectedIds(){ return rowChecks().filter(function(c){ return c.checked; }).map(function(c){ return c.getAttribute('data-nz-oid'); }).filter(Boolean); }
                function refresh(){
                    var ids = selectedIds();
                    var checks = rowChecks();
                    countEl.textContent = '已选 ' + ids.length + ' 单';
                    goBtn.disabled = ids.length === 0;
                    goBtn.innerHTML = '<i class="tio-print"></i>打印选中 (' + ids.length + ')';
                    allCb.checked = checks.length > 0 && ids.length === checks.length;
                }
                function enter(){ document.body.classList.add('nz-batch-mode'); refresh(); }
                function exit(){ document.body.classList.remove('nz-batch-mode'); rowChecks().forEach(function(c){ c.checked = false; }); allCb.checked = false; refresh(); }
                openBtn.addEventListener('click', function(){ if (document.body.classList.contains('nz-batch-mode')) { exit(); } else { enter(); } });
                cancelBtn.addEventListener('click', exit);
                allCb.addEventListener('change', function(){ rowChecks().forEach(function(c){ c.checked = allCb.checked; }); refresh(); });
                document.addEventListener('change', function(e){ if (e.target && e.target.classList && e.target.classList.contains('nz-row-check')) refresh(); });
                goBtn.addEventListener('click', function(){
                    var ids = selectedIds();
                    if (!ids.length) return;
                    var url = "{{ route('vendor.order.generate-invoice-batch') }}" + '?ids=' + encodeURIComponent(ids.join(',')) + '&nz_auto_print=1';
                    openInvoiceForPrint(url);
                });
            }

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
                        if (!pv || pv < 1) { window.nzToast('请填写预计出餐时间（至少 1 分钟）', 'warning'); return; }
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
                initBatchPrint();
                initColumnSettings();

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
                            window.nzToast('请先勾选“已接入并测试打印机”。', 'warning');
                            auto.checked = false;
                        }
                        localStorage.setItem(AUTO_KEY, auto.checked ? '1' : '0');
                        applyPrintSettings();
                    });
                }
                if (testBtn) {
                    testBtn.addEventListener('click', function(){
                        if (!isPrintReady()) {
                            window.nzToast('请先确认本机/云打印机已接入。', 'warning');
                            return;
                        }
                        var firstPrint = document.querySelector('a[href*="/generate-invoice/"]');
                        if (!firstPrint) {
                            window.nzToast('当前没有可测试打印的订单。', 'warning');
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

                // UX-1 B: 原生 confirm 改藏青 nzConfirm(异步); 确认后 requestSubmit 重入(此时 __nzOk=true 跳过再确认, 既有 AJAX+自动打印链路不变)
                var __cmsg = form.getAttribute('data-nz-confirm-msg');
                if (__cmsg && window.nzConfirm && !form.__nzOk) {
                    window.nzConfirm({ body: __cmsg, danger: form.hasAttribute('data-nz-confirm-danger') }).then(function(ok){
                        if (ok) { form.__nzOk = true; if (form.requestSubmit) form.requestSubmit(); else form.submit(); }
                    });
                    return;
                }
                form.__nzOk = false;

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
                    window.nzToast('操作失败：' + (err && err.message ? err.message : '网络错误，请重试'), 'error');
                });
            }, false);
        })();

        // 哪吒: 订单表格吸顶 —— 表格区高度按视口自适应(有界滚动框, 避免 magic number + 双滚动条; 仅 >=768px)
        (function () {
            function nzFitOrderTable() {
                var tr = document.querySelector('.nz-order-table-card .table-responsive.datatable-custom');
                if (!tr) return;
                if (window.innerWidth < 768) { tr.style.maxHeight = ''; return; }   // 手机端卡片布局, 不限高
                var card = tr.closest('.nz-order-table-card');
                var footer = card ? card.querySelector('.card-footer') : null;
                var footerH = footer ? footer.offsetHeight : 0;
                var top = tr.getBoundingClientRect().top;                            // 表格区距视口顶 = 上方 chrome 高
                var avail = window.innerHeight - top - footerH - 16;                 // 留出页脚分页 + 余量
                tr.style.maxHeight = Math.max(240, Math.round(avail)) + 'px';        // 短屏保底 240
            }
            window.nzFitOrderTable = nzFitOrderTable;
            if (document.readyState !== 'loading') nzFitOrderTable(); else document.addEventListener('DOMContentLoaded', nzFitOrderTable);
            window.addEventListener('load', nzFitOrderTable);
            window.addEventListener('resize', nzFitOrderTable);
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

            // 哪吒 P7: 旧 StackFood toggleColumn_* 列显隐处理器已移除(绑定的 checkbox 不存在, 且会隐藏核心列, 与列设置面板决策冲突); 列显隐改由 initColumnSettings 统一实现。

            // INITIALIZATION OF TAGIFY
            // =======================================================
            $('.js-tagify').each(function () {
                let tagify = $.HSCore.components.HSTagify.init($(this));
            });
        });
    </script>
@endpush
