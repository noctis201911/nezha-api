{{-- 哪吒作业台 W4: 可刷新分区 partial —— index @include 与 vendor/workbench/refresh 端点共用同一 Blade(单一真相源·防第二套渲染)。
     本片段收在 #nzwbRefresh 内, 由全局 6s 心跳换入其 innerHTML; 叫车抽屉外壳在片段外(刷新不误抹开着的抽屉)。
     变量 $wb / $dispatchOrders 由 index compact 或 refresh() compact 提供。 --}}
@php
    $wb = $wb ?? [];
    $action = $wb['action'] ?? [];
    $queues = $wb['queues'] ?? [];
    $rail   = $wb['rail'] ?? [];
    $rateCny = $wb['rates']['cny'] ?? 55;
    $rateUsd = $wb['rates']['usd'] ?? 400;
    $qc = $queues['confirm_payment'] ?? []; $qk = $queues['cooking'] ?? [];
    $qd = $queues['delivery'] ?? []; $qn = $queues['nudge_timeout'] ?? []; $qr = $queues['refund'] ?? [];
    $today = $rail['today'] ?? []; $bad = $rail['bad_review'] ?? [];
    $todayCollected = (float)($today['collected'] ?? 0);
    $todayCny = $rateCny > 0 ? number_format($todayCollected / $rateCny) : 0;
    $todayUsd = $rateUsd > 0 ? number_format($todayCollected / $rateUsd, 2) : 0;
    $store = $wb['store'] ?? []; $tempClosed = (bool)($store['temp_closed'] ?? false);   // W5 店态
    $stBusy = (bool)($store['busy'] ?? false); $stBusyMin = (int)($store['busy_min'] ?? 30); $stBusyReason = $store['busy_reason'] ?? 'peak';
    $stModeEnabled = (bool)($store['mode_enabled'] ?? false); $stPauseUntil = $store['pause_until'] ?? null;
    $capState = $tempClosed ? 'paused' : ($stBusy ? 'busy' : 'open');
    $capText  = $tempClosed ? '暂停接单' : ($stBusy ? ('忙碌中 · '.$stBusyMin.'分') : '营业中');
    // W6 移动分段: 五队列计数 + 默认落第一个非空队列。桌面(>600px)不生效(全展示)。
    $qCounts = ['confirm' => ($qc['total'] ?? 0) + ($qc['no_proof_total'] ?? 0), 'cooking' => $qk['total'] ?? 0, 'delivery' => $qd['total'] ?? 0, 'nudge' => $qn['total'] ?? 0, 'refund' => $qr['total'] ?? 0];
    $segFirst = 'confirm'; foreach ($qCounts as $k => $n) { if ($n > 0) { $segFirst = $k; break; } }
    $segCls = fn($k) => $k === $segFirst ? ' nzwb-seg-active' : '';   // 卡显隐(移动)
    $segOn  = fn($k) => $k === $segFirst ? ' on' : '';                 // segbar 选中态
    $detail = fn($id) => route('vendor.order.details', ['id' => $id]);
    $detailFin = fn($id) => route('vendor.order.details', ['id' => $id]) . '?tab=fin';
    $listUrl = fn($st) => route('vendor.order.list', [$st]);
    $dur = function ($m) { $m = (int) $m; if ($m < 60) return $m.' 分钟'; $h = intdiv($m, 60); $mm = $m % 60; return $h.' 小时'.($mm ? $mm.' 分' : ''); };
    $waitTxt = function ($m) use ($dur) { return $m === null ? '' : '已等 '.$dur($m); };
    $more = function ($total, $rows, $url) { $n = (int)$total - count($rows ?? []); return $n > 0 ? '<a class="nzwb-hint" style="display:block;text-decoration:none" href="'.$url.'">还有 '.$n.' 单 · 查看全部 →</a>' : ''; };
    $isEmpty = (($action['total'] ?? 0) == 0)
        && (($qc['total'] ?? 0) + ($qc['no_proof_total'] ?? 0) == 0)
        && (($qk['total'] ?? 0) == 0) && (($qd['total'] ?? 0) == 0)
        && (($qn['total'] ?? 0) == 0) && (($qr['total'] ?? 0) == 0);
@endphp

    {{-- 顶部: 店态胶囊(W5 变交互·W2 静态) + 今日单量 --}}
    <div class="nzwb-top">
        <span class="nzwb-title">今天 · 作业台</span>
        <button type="button" class="nzwb-cap nzwb-store{{ $capState === 'paused' ? ' paused' : ($capState === 'busy' ? ' busy' : '') }}"
            data-nz-store="{{ $capState }}" data-nz-mode-enabled="{{ $stModeEnabled ? 1 : 0 }}"
            data-nz-busy-min="{{ $stBusyMin }}" data-nz-busy-reason="{{ $stBusyReason }}" data-nz-pause-until="{{ $stPauseUntil }}"
            title="{{ $stModeEnabled ? '点击设置营业状态' : '点击切换 营业中 / 暂停接单' }}"><span class="dot"></span>{{ $capText }}</button>
        <span class="nzwb-today">今日 <b class="num">{{ (int)($today['orders'] ?? 0) }}</b> 单 · 自收款 <b class="num">֏{{ number_format((float)($today['collected'] ?? 0)) }}</b></span>
    </div>

    {{-- ══ screen05 · 集中配送作业台「预约」分区(mockup05·浅白专业 DS§19·nzpo- scoped)。总闸 nezha_preorder_status 关 → enabled=false → 整区块不渲染(dormant)。 ══ --}}
    @php $po = $wb['preorder'] ?? ['enabled' => false]; $poHasGroups = !empty($po['enabled']) && !empty($po['groups']); @endphp
    @if(!empty($po['enabled']))
        @php
            $poGroups = $po['groups'] ?? []; $poSum = $po['summary'] ?? []; $poRem = $po['reminder'] ?? null;
            $poChip = ['waiting' => 'a', 'handover' => 'g', 'delivering' => 'g', 'done' => 'x'];
        @endphp
        <section class="nzpo">
            <div class="nzpo-head">
                <span class="nzpo-h1">预约 · 集中配送</span>
                <a class="nzpo-manage" href="{{ route('vendor.business-settings.nezha-window.index') }}">配送时段设置 ›</a>
            </div>

            @if(empty($poGroups))
                <div class="nzpo-empty">
                    <div class="nzpo-eic">📅</div>
                    <div class="nzpo-e1">还没有预约单</div>
                    <div class="nzpo-e2">开启「即时 + 预约」或「只接预约」模式后，顾客即可选时段下单；<br>订单会按配送时段在这里自动分组。</div>
                    <a class="nzpo-ebtn" href="{{ route('vendor.business-settings.nezha-window.index') }}">去设置接单模式</a>
                </div>
            @else
                <div class="nzpo-sum">今天 <b>{{ (int) ($poSum['windows'] ?? 0) }}</b> 个时段 · <b>{{ (int) ($poSum['orders'] ?? 0) }}</b> 单（已送出 <b>{{ (int) ($poSum['delivered'] ?? 0) }}</b>）<span class="nzpo-sumr">合计 <b>{{ $poSum['total_amd'] ?? '' }}</b>@if(($poSum['total_cny'] ?? null) !== null)<i>≈¥{{ number_format((float) $poSum['total_cny']) }} ≈${{ number_format((float) $poSum['total_usd']) }}</i>@endif</span></div>

                @if($poRem)
                    <div class="nzpo-rem">
                        <span class="nzpo-rem-i">⏰</span>
                        <div>
                            <div class="nzpo-rt"><b>{{ $poRem['label'] }}</b> 窗口{{ ($poRem['minutes_until'] ?? 0) > 0 ? '将于 ' . $dur($poRem['minutes_until']) . ' 后开始' : '正在进行中' }}</div>
                            <div class="nzpo-rs">{{ (int) $poRem['pending'] }} 单待处理 · 建议 <b>{{ $poRem['suggest_time'] }}</b> 开始叫车（固定提前 {{ (int) $poRem['dispatch_lead'] }} 分钟，非实时路况，可在设置调整）</div>
                        </div>
                    </div>
                @endif

                @foreach($poGroups as $g)
                    @php
                        $gcls  = $g['state'] === 'hot' ? 'hot' : ($g['state'] === 'done' ? 'done' : 'up');
                        $gchip = $g['state'] === 'hot' ? ['临近', 'a'] : ($g['state'] === 'done' ? ['已完成', 'g'] : ['未到时段', 'b']);
                    @endphp
                    <details class="nzpo-gc {{ $gcls }}" @if($g['state'] === 'hot') open @endif>
                        <summary class="nzpo-gh">
                            <span class="nzpo-gt">{{ $g['label'] }}</span>
                            <span class="nzpo-gday">{{ $g['day_label'] }}</span>
                            <span class="nzpo-chip c-{{ $gchip[1] }}">{{ $gchip[0] }}</span>
                            <span class="nzpo-gchev">▾</span>
                        </summary>
                        <div class="nzpo-gbody">
                            <div class="nzpo-gsub">
                                <span><b>{{ (int) $g['count'] }}</b> 单@if(($g['ready_count'] ?? 0) > 0) · 已出餐 <b>{{ (int) $g['ready_count'] }}</b>/{{ (int) $g['count'] }}@endif</span>
                                @if(($g['count'] ?? 0) > 0)<span class="nzpo-bar"><i style="width:{{ (int) round(($g['ready_count'] ?? 0) / max(1, (int) $g['count']) * 100) }}%"></i></span>@endif
                            </div>
                            @foreach($g['rows'] as $r)
                                <div class="nzpo-ol">
                                    <div class="nzpo-ob"><div class="nzpo-oid">#{{ $r['id'] }}</div><div class="nzpo-on">{{ $r['customer'] }} · {{ (int) $r['items_qty'] }} 件</div></div>
                                    <span class="nzpo-ov">{{ $r['amount_amd'] }}</span>
                                    <span class="nzpo-chip c-{{ $poChip[$r['stage']] ?? 'x' }}">{{ $r['stage_text'] }}</span>
                                    @if($r['stage'] === 'handover')<button type="button" class="nzpo-call nz-dispatch-open" data-nz-dispatch="{{ $r['id'] }}" hidden>叫车</button>@endif
                                </div>
                            @endforeach
                            @if(($g['confirmed_count'] ?? 0) > 0 || count($g['dispatch_ids'] ?? []) > 0)
                                <div class="nzpo-acts">
                                    @if(($g['confirmed_count'] ?? 0) > 0)
                                        <form action="{{ route('vendor.business-settings.nezha-window.batch-ready') }}" method="post" class="nzpo-actf" data-nz-ajax data-nz-ok-toast="已标出餐 · 转入「出餐待叫车」" data-nz-confirm="把该时段 {{ (int) $g['confirmed_count'] }} 单一起标为「出餐待叫车」？标记后请逐单叫车配送。">
                                            @csrf
                                            @foreach($g['batch_ready_ids'] as $bid)<input type="hidden" name="order_ids[]" value="{{ $bid }}">@endforeach
                                            <button type="submit" class="nzpo-btn-o">全部标出餐（{{ (int) $g['confirmed_count'] }}）</button>
                                        </form>
                                    @endif
                                    @if(count($g['dispatch_ids'] ?? []) > 0)
                                        <button type="button" class="nzpo-btn nzpo-reveal" data-nzpo-reveal>转入配送（{{ count($g['dispatch_ids']) }}）</button>
                                    @endif
                                </div>
                                <div class="nzpo-acap">「转入配送」不批量派车：点开后请对每单叫 Yandex 并粘贴跟踪链接（平台不代派车、不会批量把顾客状态翻「配送中」）。</div>
                            @endif
                        </div>
                    </details>
                @endforeach

                <div class="nzpo-tip">窗口开始前约 {{ (int) ($po['remind_min'] ?? 45) }} 分钟在此提醒 · 时段与接单模式在「配送时段设置」调整</div>
            @endif
        </section>
    @endif

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

            {{-- W6 移动端: 五队列横滑分段控件(默认落第一个非空队列·仅 ≤600px 显示; 桌面全展示不受影响) --}}
            <div class="nzwb-segbar" role="tablist" aria-label="队列分段">
                <button type="button" class="nzwb-seg{{ $segOn('confirm') }}" data-nzwb-seg="confirm" role="tab">待确认收款@if($qCounts['confirm'] > 0)<b>{{ $qCounts['confirm'] }}</b>@endif</button>
                <button type="button" class="nzwb-seg{{ $segOn('cooking') }}" data-nzwb-seg="cooking" role="tab">备餐@if($qCounts['cooking'] > 0)<b>{{ $qCounts['cooking'] }}</b>@endif</button>
                <button type="button" class="nzwb-seg{{ $segOn('delivery') }}" data-nzwb-seg="delivery" role="tab">配送@if($qCounts['delivery'] > 0)<b>{{ $qCounts['delivery'] }}</b>@endif</button>
                <button type="button" class="nzwb-seg{{ $segOn('nudge') }}" data-nzwb-seg="nudge" role="tab">催促·超时@if($qCounts['nudge'] > 0)<b>{{ $qCounts['nudge'] }}</b>@endif</button>
                <button type="button" class="nzwb-seg{{ $segOn('refund') }}" data-nzwb-seg="refund" role="tab">退款@if($qCounts['refund'] > 0)<b>{{ $qCounts['refund'] }}</b>@endif</button>
            </div>

            @if($isEmpty && !$poHasGroups)
                <div class="nzwb-banner-empty"><span class="zzz">◔</span>今天还没有新订单。顾客下单后会第一时间出现在这里并响铃提醒。</div>
            @endif

            {{-- ① 待确认收款 --}}
            <div class="nzwb-qcard{{ $segCls('confirm') }}" data-nzwb-q="confirm">
                <div class="nzwb-qhead"><span class="qdot"></span><b>待确认收款</b><span class="cnt">{{ (int)($qc['total'] ?? 0) }}</span>
                    @if(($qc['no_proof_total'] ?? 0) > 0)<span class="sub">另有 {{ $qc['no_proof_total'] }} 单等顾客传凭证（不计需动作）</span>@endif
                    <a class="all" href="{{ $listUrl('offline_pending') }}">查看全部 →</a></div>
                @forelse(($qc['rows'] ?? []) as $r)
                    <div class="nzwb-row">
                        <div class="grow"><span class="oid">#{{ $r['id'] }}</span> <span class="nzwb-chip">{{ $r['payment_label'] }}</span>
                            <div class="meta">{{ $r['placed_at'] ? '下单 '.$r['placed_at'].' · ' : '' }}{{ $waitTxt($r['waited_min']) }} · 顾客 {{ $r['customer']['name'] }}{{ $r['customer']['phone'] ? '（'.$r['customer']['phone'].'）' : '' }}</div></div>
                        @if(!empty($r['proof']['url']))
                            <a class="nzwb-proof" href="{{ $r['proof']['url'] }}" target="_blank" rel="noopener" title="点开大图快速预筛（正式核对在详情页收款）"><img src="{{ $r['proof']['url'] }}" alt="凭证" style="width:100%;height:100%;object-fit:cover;border-radius:6px;"></a>
                        @else
                            <div class="nzwb-proof">{{ $r['proof']['label'] ?? '凭证' }}</div>
                        @endif
                        <div class="nzwb-money num">{{ $r['amount_amd'] }}<small>≈ ¥{{ $r['amount_cny'] }} / ${{ $r['amount_usd'] ?? '' }}</small></div>
                        <div class="nzwb-act"><a class="nzwb-btn green" href="{{ $detailFin($r['id']) }}">{{ $r['cta']['label'] ?? '确认收款' }}</a><a class="nzwb-btn more" href="{{ $detail($r['id']) }}">⋯</a></div>
                    </div>
                @empty
                    @if(($qc['no_proof_total'] ?? 0) == 0)<div class="nzwb-qempty">{{ $qc['empty_text'] ?? '暂无待确认收款的订单' }}</div>@endif
                @endforelse
                @foreach(($qc['no_proof_rows'] ?? []) as $r)
                    <div class="nzwb-row mute">
                        <div class="grow"><span class="oid">#{{ $r['id'] }}</span> <span class="nzwb-chip amber">无凭证</span>
                            <div class="meta">等顾客传凭证 · {{ $waitTxt($r['waited_min']) }} · 超时未传将自动取消</div></div>
                        <div class="nzwb-money num" style="color:var(--gray)">{{ $r['amount_amd'] }}<small>≈ ¥{{ $r['amount_cny'] }} / ${{ $r['amount_usd'] ?? '' }}</small></div>
                        <a class="nzwb-btn line" href="{{ $detail($r['id']) }}">详情</a>
                    </div>
                @endforeach
                {!! $more($qc['total'] ?? 0, $qc['rows'] ?? [], $listUrl('offline_pending')) !!}
                @if(!empty($qc['rows']))<div class="nzwb-hint">⋯ 内含：未收到 / 打回 · 拒单 · 详情；确认收款走现有核对弹层，不简化步骤。</div>@endif
            </div>

            {{-- ② 备餐中 --}}
            <div class="nzwb-qcard{{ $segCls('cooking') }}" data-nzwb-q="cooking">
                <div class="nzwb-qhead"><span class="qdot"></span><b>备餐中</b><span class="cnt">{{ (int)($qk['total'] ?? 0) }}</span><a class="all" href="{{ $listUrl('cooking') }}">查看全部 →</a></div>
                @forelse(($qk['rows'] ?? []) as $r)
                    <div class="nzwb-row">
                        <div class="grow"><span class="oid">#{{ $r['id'] }}</span>
                            <div class="meta">{{ $r['items'] ?: '—' }} · 已备 {{ $dur($r['cooking_min'] ?? 0) }}</div></div>
                        <div class="nzwb-money num">{{ $r['amount_amd'] }}<small>≈ ¥{{ $r['amount_cny'] }} / ${{ $r['amount_usd'] ?? '' }}</small></div>
                        <div class="nzwb-act"><button type="button" class="nzwb-btn navy nz-dispatch-open" data-nz-dispatch="{{ $r['id'] }}">出餐 · 叫车</button><a class="nzwb-btn more" href="{{ $detail($r['id']) }}">⋯</a></div>
                    </div>
                @empty
                    <div class="nzwb-qempty">{{ $qk['empty_text'] ?? '暂无备餐中的订单' }}</div>
                @endforelse
                {!! $more($qk['total'] ?? 0, $qk['rows'] ?? [], $listUrl('cooking')) !!}
                @if(!empty($qk['rows']))<div class="nzwb-hint">「出餐 · 叫车」打开现有 Yandex 两步抽屉（一键叫车 → 贴链接 → 出餐·标记配送中）。</div>@endif
            </div>

            {{-- ③ 配送(待叫车/配送中) --}}
            <div class="nzwb-qcard{{ $segCls('delivery') }}" data-nzwb-q="delivery">
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
                        <div class="nzwb-money num">{{ $r['amount_amd'] }}<small>≈ ¥{{ $r['amount_cny'] }} / ${{ $r['amount_usd'] ?? '' }}</small></div>
                        <div class="nzwb-act">
                            @if($r['stage'] === 'handover')
                                <button type="button" class="nzwb-btn navy nz-dispatch-open" data-nz-dispatch="{{ $r['id'] }}">叫车 / 标记配送中</button>
                            @else
                                <form action="{{ $r['cta']['route'] ?? $detail($r['id']) }}" method="post" style="margin:0" data-nz-ajax data-nz-ok-toast="已标为「已送达」，本单完成" @if(!empty($r['cta']['confirm'])) data-nz-confirm="{{ $r['cta']['confirm'] }}" data-nz-confirm-danger @endif>
                                    @csrf @method($r['cta']['method'] ?? 'PUT')
                                    <button type="submit" class="nzwb-btn green">标为「已送达」</button>
                                </form>
                            @endif
                            <a class="nzwb-btn more" href="{{ $detail($r['id']) }}">⋯</a></div>
                    </div>
                @empty
                    <div class="nzwb-qempty">{{ $qd['empty_text'] ?? '暂无待叫车或配送中的订单' }}</div>
                @endforelse
                {!! $more($qd['total'] ?? 0, $qd['rows'] ?? [], $listUrl('grp_ongoing')) !!}
            </div>

            {{-- ④ 催促 · 超时(横切告警·轻量跳转行) --}}
            <div class="nzwb-qcard warncard{{ $segCls('nudge') }}" data-nzwb-q="nudge">
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
            <div class="nzwb-qcard{{ $segCls('refund') }}" data-nzwb-q="refund">
                <div class="nzwb-qhead"><span class="qdot" style="background:var(--red)"></span><b>退款处理</b><span class="cnt">{{ (int)($qr['total'] ?? 0) }}</span><a class="all" href="{{ $listUrl('refund_pending') }}">查看全部 →</a></div>
                @php $segA = collect($qr['rows'] ?? [])->where('segment','A'); $segB = collect($qr['rows'] ?? [])->where('segment','B'); @endphp
                @if($segA->count())
                    <div class="nzwb-sect a"><span class="d"></span>已确认收款 · 须退（{{ $segA->count() }}）</div>
                    @foreach($segA as $r)
                        <div class="nzwb-row">
                            <div class="grow"><span class="oid">#{{ $r['id'] }}</span> <span class="nzwb-chip">{{ $r['channel'] }}</span>
                                <div class="meta">{{ $r['held_text'] }}</div></div>
                            <div class="nzwb-money num" style="color:var(--red)">应退 {{ $r['refund_amd'] }}<small>≈ ¥{{ $r['refund_cny'] }} / ${{ $r['refund_usd'] ?? '' }}</small></div>
                            <a class="nzwb-btn line" href="{{ $detail($r['id']) }}">{{ $r['cta']['label'] ?? '去退款核对' }}</a>
                        </div>
                    @endforeach
                @endif
                @if($segB->count())
                    <div class="nzwb-sect b"><span class="d"></span>凭证在案 · 先核后退（{{ $segB->count() }}）</div>
                    @foreach($segB as $r)
                        <div class="nzwb-row">
                            <div class="grow"><span class="oid">#{{ $r['id'] }}</span> <span class="nzwb-chip">{{ $r['channel'] }}</span>@if($r['disputed'] ?? false) <span class="nzwb-chip" style="background:#e5e7eb;color:#4b5563;">争议中</span>@endif
                                <div class="meta">{{ $r['meta'] ?: '顾客有付款凭证在案，请核对您的收款账户' }} · {{ $r['held_text'] }}</div></div>
                            <div class="nzwb-money num" style="color:var(--red)">应退 {{ $r['refund_amd'] }}<small>≈ ¥{{ $r['refund_cny'] }} / ${{ $r['refund_usd'] ?? '' }}</small></div>
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
                <div class="nzwb-stat"><span>自收款</span><b class="num">֏{{ number_format($todayCollected) }} <small style="color:var(--sec);font-weight:400">≈ ¥{{ $todayCny }} / ${{ $todayUsd }}</small></b></div>
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

    {{-- W3 叫车抽屉: 每单 _dispatch_tools 隐藏源(复用订单列表页同款 partial·同一写路径) + 共享底部抽屉 --}}
    <div id="nzDispatchHolder" style="display:none">
        @foreach(($dispatchOrders ?? collect()) as $__do)
            <div id="nzDispatchSrc-{{ $__do->id }}" data-nz-dispatch-src="{{ $__do->id }}">
                @include('vendor-views.order.partials._dispatch_tools', ['order' => $__do, 'nzDrawer' => true])
            </div>
        @endforeach
    </div>
