@extends('layouts.vendor.app')

@section('title', '今天 · 作业台')

@push('css_or_js')
<style>
/* 哪吒作业台 W2 —— 全部 class 以 nzwb- 前缀命名空间, 避免与 Bootstrap/主题的 .row/.btn/.card 冲突。
   token 取自业主已点头视觉稿 fable-mockups/workbench.html(商家端 V2 藏青采样)。 */
.nzwb{
  --navy:#102A4C; --body:#42505F; --sec:#98A2B3; --line:#D6DBE1; --bg:#F3F5F7; --bg2:#F7F8FA;
  --ink:#17191D; --amber:#E8910C; --amberBg:#FFF7E6; --amberLine:#FFE2A8; --red:#E5484D; --redBg:#FEE7EA;
  --green:#1F7A3A; --greenBg:#E8F8EE; --greenCta:#189A52; --chipBg:#F6F7F9; --gray:#6B7280;
  color:var(--body); font-size:14px;
  font-family:"Noto Sans Armenian","Segoe UI","Microsoft YaHei","PingFang SC",sans-serif;
}
.nzwb *{box-sizing:border-box}
.nzwb .num{font-variant-numeric:tabular-nums}

/* 顶部: 店态胶囊 + 今日单量 */
.nzwb-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px}
.nzwb-title{color:var(--navy);font-size:17px;font-weight:700}
.nzwb-cap{display:inline-flex;align-items:center;gap:8px;border:1.5px solid var(--line);border-radius:999px;padding:7px 14px;font-weight:600;color:var(--navy);background:#fff}
.nzwb-cap .dot{width:9px;height:9px;border-radius:50%;background:var(--greenCta)}
.nzwb-today{color:var(--body);font-size:13.5px;background:var(--bg2);border:1px solid var(--line);border-radius:999px;padding:7px 14px}
.nzwb-today b{color:var(--ink)}

/* 需动作总览条 */
.nzwb-alertbar{display:flex;align-items:center;gap:10px;padding:12px 16px;background:#fff;border:1px solid var(--line);border-radius:12px;margin-bottom:14px;overflow-x:auto}
.nzwb-alertbar .lbl{color:var(--sec);font-size:13px;flex:0 0 auto}
.nzwb-apill{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line);border-radius:999px;padding:6px 13px;font-size:13.5px;color:var(--navy);font-weight:600;background:#fff;cursor:pointer;flex:0 0 auto;text-decoration:none}
.nzwb-apill b{background:var(--navy);color:#fff;border-radius:8px;padding:0 7px;font-size:12.5px;line-height:18px}
.nzwb-apill.warn b{background:var(--amber)} .nzwb-apill.red b{background:var(--red)}
.nzwb-alertbar .calm{color:var(--green);background:var(--greenBg);border-radius:999px;padding:6px 14px;font-size:13.5px}

/* 双栏 */
.nzwb-cols{display:flex;gap:16px;align-items:flex-start}
.nzwb-maincol{flex:1;min-width:0}
.nzwb-rail{width:300px;flex:0 0 300px}
@media (max-width:1180px){ .nzwb-cols{flex-direction:column} .nzwb-rail{width:100%;flex:auto} }

/* 队列卡 */
.nzwb-qcard{background:#fff;border:1px solid var(--line);border-radius:12px;margin-bottom:14px;overflow:hidden}
.nzwb-qhead{display:flex;align-items:center;gap:9px;padding:12px 16px;border-bottom:1px solid #EAEDF0}
.nzwb-qhead .qdot{width:8px;height:8px;border-radius:2px;background:var(--navy)}
.nzwb-qhead b{color:var(--navy);font-size:14.5px}
.nzwb-qhead .cnt{background:var(--chipBg);border:1px solid var(--line);color:var(--navy);font-weight:700;border-radius:8px;padding:0 8px;font-size:12.5px;line-height:20px}
.nzwb-qhead .sub{color:var(--sec);font-size:12.5px}
.nzwb-qhead .all{margin-left:auto;color:var(--sec);font-size:13px;text-decoration:none}
.nzwb-qhead .all:hover{color:var(--navy)}
.nzwb-qcard.warncard .nzwb-qhead{background:var(--amberBg)} .nzwb-qcard.warncard .qdot{background:var(--amber)}
.nzwb-row{display:flex;align-items:center;gap:14px;padding:13px 16px;border-bottom:1px solid #F0F2F4}
.nzwb-row:last-child{border-bottom:none}
.nzwb-row .oid{color:var(--ink);font-weight:700;font-size:14px;white-space:nowrap}
.nzwb-row .meta{color:var(--sec);font-size:12.5px;margin-top:3px}
.nzwb-row .grow{flex:1;min-width:0}
.nzwb-money{color:var(--ink);font-weight:700;font-size:15px;white-space:nowrap;text-align:right}
.nzwb-money small{color:var(--sec);font-weight:400;font-size:11.5px;display:block}
.nzwb-chip{display:inline-block;border-radius:6px;padding:1px 8px;font-size:12px;background:var(--chipBg);border:1px solid var(--line);color:var(--body);vertical-align:1px}
.nzwb-chip.green{background:var(--greenBg);border-color:transparent;color:var(--green)}
.nzwb-chip.amber{background:var(--amberBg);border-color:var(--amberLine);color:var(--amber)}
.nzwb-chip.red{background:var(--redBg);border-color:transparent;color:var(--red)}
.nzwb-proof{width:40px;height:40px;border-radius:6px;background:linear-gradient(160deg,#E8EDF3,#D8E0E9);border:1px solid var(--line);color:var(--sec);font-size:11px;display:flex;align-items:center;justify-content:center;flex:0 0 40px}
.nzwb-btn{border:none;border-radius:8px;padding:9px 16px;font-size:13.5px;font-weight:600;cursor:pointer;white-space:nowrap;font-family:inherit;text-decoration:none;display:inline-block;line-height:1.3}
.nzwb-btn.green{background:var(--greenCta);color:#fff}
.nzwb-btn.navy{background:var(--navy);color:#fff}
.nzwb-btn.amber{background:var(--amber);color:#fff}
.nzwb-btn.line{background:#fff;color:var(--navy);border:1.5px solid var(--line)}
.nzwb-btn.more{background:#fff;color:var(--sec);border:1px solid var(--line);padding:9px 12px}
.nzwb-act{display:flex;gap:8px;align-items:center}
.nzwb-row.mute{background:var(--bg2)}
.nzwb-row.mute .oid{color:var(--gray);font-weight:600}
.nzwb-row.overdue{box-shadow:inset 3px 0 0 var(--red)}
.nzwb-hint{color:var(--sec);font-size:12px;padding:9px 16px;background:var(--bg2);border-top:1px solid #F0F2F4}
.nzwb-qempty{padding:18px 16px;color:var(--sec);font-size:13px}
.nzwb-sect{display:flex;align-items:center;gap:8px;padding:9px 16px;background:var(--bg2);font-size:12.5px;color:var(--body);border-bottom:1px solid #F0F2F4}
.nzwb-sect .d{width:7px;height:7px;border-radius:50%}
.nzwb-sect.a .d{background:var(--red)} .nzwb-sect.b .d{background:var(--sec)}

/* 右栏 */
.nzwb-rcard{background:#fff;border:1px solid var(--line);border-radius:12px;margin-bottom:14px;padding:14px 16px}
.nzwb-rcard h4{margin:0 0 10px;color:var(--navy);font-size:14px}
.nzwb-warnline{display:flex;align-items:center;justify-content:space-between;gap:8px;background:var(--amberBg);border:1px solid var(--amberLine);border-radius:8px;padding:9px 12px;font-size:13px;color:var(--body)}
.nzwb-calmline{background:var(--greenBg);border-radius:8px;padding:9px 12px;font-size:13px;color:var(--green)}
.nzwb-stat{display:flex;justify-content:space-between;padding:7px 0;font-size:13.5px}
.nzwb-stat b{color:var(--ink)}
.nzwb-rcap{color:var(--sec);font-size:11.5px;margin-top:6px;line-height:1.6}
.nzwb-quick{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.nzwb-quick a{display:block;text-align:center;border:1px solid var(--line);border-radius:8px;padding:10px 0;color:var(--navy);font-size:13px;text-decoration:none;background:var(--bg2)}
.nzwb-quick a:hover{border-color:var(--navy)}

/* 空态横幅 */
.nzwb-banner-empty{background:#fff;border:1px dashed var(--line);border-radius:12px;padding:26px 20px;text-align:center;color:var(--body);font-size:14px;margin-bottom:14px}
.nzwb-banner-empty .zzz{font-size:26px;color:var(--sec);display:block;margin-bottom:8px}
</style>
@endpush

@section('content')
@php
    $wb = $wb ?? [];
    $action = $wb['action'] ?? [];
    $queues = $wb['queues'] ?? [];
    $rail   = $wb['rail'] ?? [];
    $rateCny = $wb['rates']['cny'] ?? 55;
    $qc = $queues['confirm_payment'] ?? []; $qk = $queues['cooking'] ?? [];
    $qd = $queues['delivery'] ?? []; $qn = $queues['nudge_timeout'] ?? []; $qr = $queues['refund'] ?? [];
    $today = $rail['today'] ?? []; $bad = $rail['bad_review'] ?? [];
    $detail = fn($id) => route('vendor.order.details', ['id' => $id]);
    $listUrl = fn($st) => route('vendor.order.list', [$st]);
    $dur = function ($m) { $m = (int) $m; if ($m < 60) return $m.' 分钟'; $h = intdiv($m, 60); $mm = $m % 60; return $h.' 小时'.($mm ? $mm.' 分' : ''); };
    $waitTxt = function ($m) use ($dur) { return $m === null ? '' : '已等 '.$dur($m); };
    $more = function ($total, $rows, $url) { $n = (int)$total - count($rows ?? []); return $n > 0 ? '<a class="nzwb-hint" style="display:block;text-decoration:none" href="'.$url.'">还有 '.$n.' 单 · 查看全部 →</a>' : ''; };
    $isEmpty = (($action['total'] ?? 0) == 0)
        && (($qc['total'] ?? 0) + ($qc['no_proof_total'] ?? 0) == 0)
        && (($qk['total'] ?? 0) == 0) && (($qd['total'] ?? 0) == 0)
        && (($qn['total'] ?? 0) == 0) && (($qr['total'] ?? 0) == 0);
@endphp

<div class="content container-fluid">
<div class="nzwb">

    {{-- 顶部: 店态胶囊(W5 变交互·W2 静态) + 今日单量 --}}
    <div class="nzwb-top">
        <span class="nzwb-title">今天 · 作业台</span>
        <span class="nzwb-cap" title="店态(暂停接单将在 W5 接入)"><span class="dot"></span>营业中</span>
        <span class="nzwb-today">今日 <b class="num">{{ (int)($today['orders'] ?? 0) }}</b> 单 · 自收款 <b class="num">֏{{ number_format((float)($today['collected'] ?? 0)) }}</b></span>
    </div>

    {{-- 需动作总览条: 4 枚定稿=待确认收款/催促·超时/退款处理/退款申请中(不含待叫车·裁决 0703)。全 0 → 绿胶囊。 --}}
    @if($isEmpty)
        <div class="nzwb-alertbar"><span class="calm">今天没有需要立刻处理的事</span></div>
    @else
        <div class="nzwb-alertbar">
            <span class="lbl">需动作</span>
            @if(($action['offline_pending'] ?? 0) > 0)<a class="nzwb-apill" href="{{ $listUrl('offline_pending') }}">待确认收款 <b>{{ $action['offline_pending'] }}</b></a>@endif
            @if(($action['nudge_or_timeout'] ?? 0) > 0)<a class="nzwb-apill warn" href="{{ $listUrl('grp_action') }}">催促 / 超时 <b>{{ $action['nudge_or_timeout'] }}</b></a>@endif
            @if(($action['refund_pending'] ?? 0) > 0)<a class="nzwb-apill red" href="{{ $listUrl('refund_pending') }}">退款处理 <b>{{ $action['refund_pending'] }}</b></a>@endif
            @if(($action['refund_requested'] ?? 0) > 0)<a class="nzwb-apill red" href="{{ $listUrl('grp_aftersale') }}">退款申请中 <b>{{ $action['refund_requested'] }}</b></a>@endif
        </div>
    @endif

    <div class="nzwb-cols">
        <section class="nzwb-maincol">

            @if($isEmpty)
                <div class="nzwb-banner-empty"><span class="zzz">◔</span>今天还没有新订单。顾客下单后会第一时间出现在这里并响铃提醒。</div>
            @endif

            {{-- ① 待确认收款 --}}
            <div class="nzwb-qcard">
                <div class="nzwb-qhead"><span class="qdot"></span><b>待确认收款</b><span class="cnt">{{ (int)($qc['total'] ?? 0) }}</span>
                    @if(($qc['no_proof_total'] ?? 0) > 0)<span class="sub">另有 {{ $qc['no_proof_total'] }} 单等顾客传凭证（不计需动作）</span>@endif
                    <a class="all" href="{{ $listUrl('offline_pending') }}">查看全部 →</a></div>
                @forelse(($qc['rows'] ?? []) as $r)
                    <div class="nzwb-row">
                        <div class="grow"><span class="oid">#{{ $r['id'] }}</span> <span class="nzwb-chip">{{ $r['payment_label'] }}</span>
                            <div class="meta">{{ $r['placed_at'] ? '下单 '.$r['placed_at'].' · ' : '' }}{{ $waitTxt($r['waited_min']) }} · 顾客 {{ $r['customer']['name'] }}{{ $r['customer']['phone'] ? '（'.$r['customer']['phone'].'）' : '' }}</div></div>
                        <div class="nzwb-proof">{{ $r['proof']['label'] ?? '凭证' }}</div>
                        <div class="nzwb-money num">{{ $r['amount_amd'] }}<small>≈ ¥{{ $r['amount_cny'] }}</small></div>
                        <div class="nzwb-act"><a class="nzwb-btn green" href="{{ $detail($r['id']) }}">{{ $r['cta']['label'] ?? '确认收款' }}</a><a class="nzwb-btn more" href="{{ $detail($r['id']) }}">⋯</a></div>
                    </div>
                @empty
                    @if(($qc['no_proof_total'] ?? 0) == 0)<div class="nzwb-qempty">{{ $qc['empty_text'] ?? '暂无待确认收款的订单' }}</div>@endif
                @endforelse
                @foreach(($qc['no_proof_rows'] ?? []) as $r)
                    <div class="nzwb-row mute">
                        <div class="grow"><span class="oid">#{{ $r['id'] }}</span> <span class="nzwb-chip amber">无凭证</span>
                            <div class="meta">等顾客传凭证 · {{ $waitTxt($r['waited_min']) }} · 超时未传将自动取消</div></div>
                        <div class="nzwb-money num" style="color:var(--gray)">{{ $r['amount_amd'] }}<small>≈ ¥{{ $r['amount_cny'] }}</small></div>
                        <a class="nzwb-btn line" href="{{ $detail($r['id']) }}">详情</a>
                    </div>
                @endforeach
                {!! $more($qc['total'] ?? 0, $qc['rows'] ?? [], $listUrl('offline_pending')) !!}
                @if(!empty($qc['rows']))<div class="nzwb-hint">⋯ 内含：未收到 / 打回 · 拒单 · 详情；确认收款走现有核对弹层，不简化步骤。</div>@endif
            </div>

            {{-- ② 备餐中 --}}
            <div class="nzwb-qcard">
                <div class="nzwb-qhead"><span class="qdot"></span><b>备餐中</b><span class="cnt">{{ (int)($qk['total'] ?? 0) }}</span><a class="all" href="{{ $listUrl('cooking') }}">查看全部 →</a></div>
                @forelse(($qk['rows'] ?? []) as $r)
                    <div class="nzwb-row">
                        <div class="grow"><span class="oid">#{{ $r['id'] }}</span>
                            <div class="meta">{{ $r['items'] ?: '—' }} · 已备 {{ $dur($r['cooking_min'] ?? 0) }}</div></div>
                        <div class="nzwb-money num">{{ $r['amount_amd'] }}<small>≈ ¥{{ $r['amount_cny'] }}</small></div>
                        <div class="nzwb-act"><a class="nzwb-btn navy" href="{{ $detail($r['id']) }}">出餐 · 叫车</a><a class="nzwb-btn more" href="{{ $detail($r['id']) }}">⋯</a></div>
                    </div>
                @empty
                    <div class="nzwb-qempty">{{ $qk['empty_text'] ?? '暂无备餐中的订单' }}</div>
                @endforelse
                {!! $more($qk['total'] ?? 0, $qk['rows'] ?? [], $listUrl('cooking')) !!}
                @if(!empty($qk['rows']))<div class="nzwb-hint">「出餐 · 叫车」打开现有 Yandex 两步抽屉（一键叫车 → 贴链接 → 出餐·标记配送中）。</div>@endif
            </div>

            {{-- ③ 配送(待叫车/配送中) --}}
            <div class="nzwb-qcard">
                <div class="nzwb-qhead"><span class="qdot"></span><b>配送</b><span class="cnt">{{ (int)($qd['total'] ?? 0) }}</span>
                    @if(($qd['total'] ?? 0) > 0)<span class="sub">待叫车 {{ (int)($qd['wait_car'] ?? 0) }} · 配送中 {{ (int)($qd['on_the_way'] ?? 0) }}</span>@endif
                    <a class="all" href="{{ $listUrl('grp_ongoing') }}">查看全部 →</a></div>
                @forelse(($qd['rows'] ?? []) as $r)
                    <div class="nzwb-row">
                        <div class="grow"><span class="oid">#{{ $r['id'] }}</span>
                            @if($r['stage'] === 'handover')<span class="nzwb-chip amber">出餐待叫车</span>
                            @elseif($r['tracking'] === 'posted')<span class="nzwb-chip green">链接已贴 ✓</span>
                            @else<span class="nzwb-chip amber">未贴链接</span>@endif
                            @if(!empty($r['nudged']))<span class="nzwb-chip red">顾客催促中</span>@endif
                            <div class="meta">{{ $r['items'] ?: '—' }} · {{ $r['stage']==='handover' ? '出餐 '.$dur($r['stage_min'] ?? 0) : '配送 '.$dur($r['stage_min'] ?? 0) }}</div></div>
                        <div class="nzwb-money num">{{ $r['amount_amd'] }}<small>≈ ¥{{ $r['amount_cny'] }}</small></div>
                        <div class="nzwb-act">
                            @if($r['stage'] === 'handover')<a class="nzwb-btn navy" href="{{ $detail($r['id']) }}">叫车 / 标记配送中</a>
                            @else<a class="nzwb-btn green" href="{{ $detail($r['id']) }}">标为「已送达」</a>@endif
                            <a class="nzwb-btn more" href="{{ $detail($r['id']) }}">⋯</a></div>
                    </div>
                @empty
                    <div class="nzwb-qempty">{{ $qd['empty_text'] ?? '暂无待叫车或配送中的订单' }}</div>
                @endforelse
                {!! $more($qd['total'] ?? 0, $qd['rows'] ?? [], $listUrl('grp_ongoing')) !!}
            </div>

            {{-- ④ 催促 · 超时(横切告警·轻量跳转行) --}}
            <div class="nzwb-qcard warncard">
                <div class="nzwb-qhead"><span class="qdot"></span><b>催促 · 超时</b><span class="cnt">{{ (int)($qn['total'] ?? 0) }}</span>
                    @if(($qn['total'] ?? 0) > 0)<span class="sub">与其他队列按单去重，不重复计数</span>@endif
                    <a class="all" href="{{ $listUrl('grp_action') }}">查看全部 →</a></div>
                @forelse(($qn['rows'] ?? []) as $r)
                    <div class="nzwb-row">
                        <div class="grow"><span class="oid">#{{ $r['id'] }}</span> <span class="nzwb-chip">{{ $r['status_text'] }}</span>
                            <div class="meta">{{ $r['hint'] }}{{ $r['waited_min'] ? ' · '.$waitTxt($r['waited_min']) : '' }}</div></div>
                        <div class="nzwb-act"><a class="nzwb-btn {{ $r['reason']==='nudge' ? 'amber' : 'line' }}" href="{{ $detail($r['id']) }}">跳到该单主操作</a></div>
                    </div>
                @empty
                    <div class="nzwb-qempty">{{ $qn['empty_text'] ?? '没有催促或超时，安心备餐' }}</div>
                @endforelse
                {!! $more($qn['total'] ?? 0, $qn['rows'] ?? [], $listUrl('grp_action')) !!}
            </div>

            {{-- ⑤ 退款处理(两段式) --}}
            <div class="nzwb-qcard">
                <div class="nzwb-qhead"><span class="qdot" style="background:var(--red)"></span><b>退款处理</b><span class="cnt">{{ (int)($qr['total'] ?? 0) }}</span><a class="all" href="{{ $listUrl('refund_pending') }}">查看全部 →</a></div>
                @php $segA = collect($qr['rows'] ?? [])->where('segment','A'); $segB = collect($qr['rows'] ?? [])->where('segment','B'); @endphp
                @if($segA->count())
                    <div class="nzwb-sect a"><span class="d"></span>已确认收款 · 须退（{{ $segA->count() }}）</div>
                    @foreach($segA as $r)
                        <div class="nzwb-row">
                            <div class="grow"><span class="oid">#{{ $r['id'] }}</span> <span class="nzwb-chip">{{ $r['channel'] }}</span>
                                <div class="meta">{{ $r['held_text'] }}</div></div>
                            <div class="nzwb-money num" style="color:var(--red)">应退 {{ $r['refund_amd'] }}<small>≈ ¥{{ $r['refund_cny'] }}</small></div>
                            <a class="nzwb-btn line" href="{{ $detail($r['id']) }}">{{ $r['cta']['label'] ?? '去退款核对' }}</a>
                        </div>
                    @endforeach
                @endif
                @if($segB->count())
                    <div class="nzwb-sect b"><span class="d"></span>凭证在案 · 先核后退（{{ $segB->count() }}）</div>
                    @foreach($segB as $r)
                        <div class="nzwb-row">
                            <div class="grow"><span class="oid">#{{ $r['id'] }}</span> <span class="nzwb-chip">{{ $r['channel'] }}</span>
                                <div class="meta">{{ $r['meta'] ?: '顾客有付款凭证在案，请核对您的收款账户' }} · {{ $r['held_text'] }}</div></div>
                            <div class="nzwb-money num" style="color:var(--red)">应退 {{ $r['refund_amd'] }}<small>≈ ¥{{ $r['refund_cny'] }}</small></div>
                            <a class="nzwb-btn line" href="{{ $detail($r['id']) }}">{{ $r['cta']['label'] ?? '去退款核对' }}</a>
                        </div>
                    @endforeach
                @endif
                @if(($qr['total'] ?? 0) == 0)<div class="nzwb-qempty">{{ $qr['empty_text'] ?? '没有需要处理的退款' }}</div>@endif
                {!! $more($qr['total'] ?? 0, $qr['rows'] ?? [], $listUrl('refund_pending')) !!}
                @if(($qr['total'] ?? 0) > 0)<div class="nzwb-hint">退款须在详情页核对原路渠道后标记（原路强确认流程原样保留），此处不提供一键标记。</div>@endif
            </div>

        </section>

        {{-- 右栏 --}}
        <aside class="nzwb-rail">
            <div class="nzwb-rcard">
                <h4>差评预警</h4>
                @if(($bad['bad_review_count'] ?? 0) > 0)
                    <div class="nzwb-warnline">近 {{ $bad['bad_review_days'] ?? 7 }} 天 {{ $bad['bad_review_count'] }} 条未回复差评 <a class="nzwb-btn amber" style="padding:6px 12px" href="{{ route('vendor.reviews') }}">去回复</a></div>
                @else
                    <div class="nzwb-calmline">近 {{ $bad['bad_review_days'] ?? 7 }} 天暂无未回复差评</div>
                @endif
            </div>
            <div class="nzwb-rcard">
                <h4>今日经营</h4>
                <div class="nzwb-stat"><span>今日单量</span><b class="num">{{ (int)($today['orders'] ?? 0) }}</b></div>
                <div class="nzwb-stat"><span>自收款</span><b class="num">֏{{ number_format((float)($today['collected'] ?? 0)) }} <small style="color:var(--sec);font-weight:400">≈ ¥{{ $rateCny > 0 ? number_format(((float)($today['collected'] ?? 0))/$rateCny) : 0 }}</small></b></div>
                <div class="nzwb-stat"><span>客单价</span><b class="num">{{ $today['avg_ticket'] !== null ? '֏'.number_format((float)$today['avg_ticket']) : '—' }}</b></div>
                <div class="nzwb-rcap">自收款为「已确认收款」口径，与对账中心一致。</div>
            </div>
            <div class="nzwb-rcard">
                <h4>快捷入口</h4>
                <div class="nzwb-quick">
                    <a href="{{ $listUrl('all') }}">全部订单</a>
                    <a href="{{ route('vendor.food.list') }}">商品列表</a>
                    <a href="{{ route('vendor.food.list') }}">一键售罄</a>
                    <a href="{{ route('vendor.nezha-deposit.index') }}">对账中心</a>
                </div>
            </div>
        </aside>
    </div>

</div>
</div>
@endsection
