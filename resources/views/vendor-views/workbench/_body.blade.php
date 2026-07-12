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

    {{-- 哪吒 自动下线: 长期不确认订单被自动停接单 → 顶部红条 + 商家一键恢复(自助·点击即证明在场)。未下线/总闸关时整条不渲染。 --}}
    @php $nzAO = $wb['auto_offline'] ?? ['on' => false]; @endphp
    @if(!empty($nzAO['on']))
    {{-- DS§19 商家后台·危险族 tint #F9EAE8 / 字 #AE4840 / 次墨 #5A6069 / accent 墨钮 #1F2329(唯一交互色)。 --}}
    <div style="margin:0 0 12px;padding:13px 15px;border-radius:12px;background:#F9EAE8;box-shadow:0 1px 3px rgba(23,28,38,.05);font-size:13px;line-height:1.6">
        <div style="font-weight:600;font-size:14.5px;color:#AE4840;margin-bottom:3px">已暂停接单</div>
        <div style="color:#5A6069">近期多单超时未处理、期间也没有成功接单，为免影响顾客，已暂停接新单。你回到岗位后点下方即可恢复。</div>
        <form action="{{ route('vendor.workbench.autooffline-recover') }}" method="post" style="margin:11px 0 0"
              onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='恢复中…';">
            @csrf
            <button type="submit" style="border:0;border-radius:12px;background:#1F2329;color:#fff;font-weight:600;font-size:14px;padding:10px 22px;cursor:pointer">恢复接单</button>
        </form>
    </div>
    @endif

    {{-- 顶部: 店态胶囊(W5 变交互·W2 静态) + 今日单量 --}}
    <div class="nzwb-top">
        <span class="nzwb-title">今天 · 作业台</span>
        <button type="button" class="nzwb-cap nzwb-store{{ $capState === 'paused' ? ' paused' : ($capState === 'busy' ? ' busy' : '') }}"
            data-nz-store="{{ $capState }}" data-nz-mode-enabled="{{ $stModeEnabled ? 1 : 0 }}"
            data-nz-busy-min="{{ $stBusyMin }}" data-nz-busy-reason="{{ $stBusyReason }}" data-nz-pause-until="{{ $stPauseUntil }}"
            title="{{ $stModeEnabled ? '点击设置营业状态' : '点击切换 营业中 / 暂停接单' }}"><span class="dot"></span>{{ $capText }}</button>
        <span class="nzwb-today">今日 <b class="num">{{ (int)($today['orders'] ?? 0) }}</b> 单 · 自收款 <b class="num">֏{{ number_format((float)($today['collected'] ?? 0)) }}</b></span>
    </div>

    {{-- ══ screen05 单点版 · 作业台「预约配送」分区(05b·浅白专业 DS§19·nzpo- scoped)。总闸 nezha_preorder_status 关 → enabled=false → 整区块不渲染(dormant)。 ══ --}}
    @php $po = $wb['preorder'] ?? ['enabled' => false]; @endphp
    @if(!empty($po['enabled']))
        @php $poSecs = $po['sections'] ?? []; $poSum = $po['summary'] ?? []; $poBan = $po['banner'] ?? null; @endphp
        <section class="nzpo">
            <div class="nzpo-head">
                <span class="nzpo-h1">预约配送</span>
                @if(($po['tab_count'] ?? 0) > 0)<span class="nzpo-h1n">{{ (int) $po['tab_count'] }}</span>@endif
                <a class="nzpo-manage" href="{{ route('vendor.business-settings.nezha-window.index') }}">可约时间段设置 ›</a>
            </div>

            @if(empty($poSecs))
                <div class="nzpo-empty">
                    <div class="nzpo-eic">📅</div>
                    <div class="nzpo-e1">还没有预约单</div>
                    <div class="nzpo-e2">开启「即时 + 预约」或「只接预约」模式后，顾客即可预约送达时间下单；<br>订单会按预计送达时间在这里排队，到建议叫车时间提醒您。</div>
                    <a class="nzpo-ebtn" href="{{ route('vendor.business-settings.nezha-window.index') }}">去设置接单模式</a>
                </div>
            @else
                <div class="nzpo-sum">预约单 <b>{{ (int) ($poSum['total'] ?? 0) }}</b> · 今天 <b>{{ (int) ($poSum['today_total'] ?? 0) }}</b>（待叫车 <b>{{ (int) ($poSum['today_due'] ?? 0) }}</b> · 已完成 <b>{{ (int) ($poSum['today_done'] ?? 0) }}</b>）@if(($poSum['future'] ?? 0) > 0) · 之后 <b>{{ (int) $poSum['future'] }}</b>@endif<span class="nzpo-sumr">今日合计 <b>{{ $poSum['total_amd'] ?? '' }}</b>@if(($poSum['total_cny'] ?? null) !== null)<i>≈¥{{ number_format((float) $poSum['total_cny']) }} ≈${{ number_format((float) $poSum['total_usd']) }}</i>@endif</span></div>

                @if($poBan)
                    <div class="nzpo-rem">
                        <span class="nzpo-rem-i">🔔</span>
                        <div>
                            <div class="nzpo-rt">该叫车了：<b>{{ (int) $poBan['count'] }}</b> 单待发 · 最早 <b>{{ $poBan['earliest'] }}</b> 送达（建议 <b>{{ $poBan['suggest'] }}</b> 叫车）</div>
                            <div class="nzpo-rs">开着作业台时只在这里提醒、不推送 · 建议时间为固定提前 {{ (int) $poBan['dispatch_lead'] }} 分钟（可在设置调整），非实时路况</div>
                        </div>
                    </div>
                @endif

                @foreach($poSecs as $sec)
                    <div class="nzpo-dh"><b>{{ $sec['day_label'] }}</b>{{ $sec['date_label'] }}<i>{{ (int) $sec['count'] }} 单</i></div>
                    @foreach($sec['cards'] as $c)
                        @php $ocls = $c['state'] === 'due' ? 'hot' : (in_array($c['state'], ['called', 'delivered'], true) ? 'done' : ''); @endphp
                        <div class="nzpo-oc {{ $ocls }}">
                            <div class="nzpo-l1"><span class="nzpo-pt">{{ $c['point'] }}</span><span class="nzpo-plab">预计送达</span><span class="nzpo-chip c-{{ $c['chip'][1] }}">{{ $c['chip'][0] }}</span></div>
                            <div class="nzpo-l2"><span class="nzpo-oid">#{{ $c['id'] }}</span><span class="nzpo-onm">{{ $c['customer'] }}</span><span class="nzpo-cnt">· {{ (int) $c['items_qty'] }} 件</span><span class="nzpo-amt">{{ $c['amount_amd'] }}</span></div>
                            <div class="nzpo-l3">
                                @if($c['state'] === 'due')
                                    <span class="nzpo-sug">建议 <b>{{ $c['suggest_time'] }}</b> 叫车 · <span class="nzpo-due">已到时间</span></span>
                                    <button type="button" class="nzpo-call nz-dispatch-open" data-nz-dispatch="{{ $c['id'] }}">叫车</button>
                                @elseif($c['state'] === 'upcoming')
                                    <span class="nzpo-sug">建议 <b>{{ $c['suggest_time'] }}</b> 叫车@if(($c['wait_min'] ?? 0) > 0)（{{ $dur($c['wait_min']) }}后）@endif</span>
                                @else
                                    <span class="nzpo-dnote">{{ $c['note'] ?? '' }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endforeach

                <div class="nzpo-tip">到建议叫车时间会提醒您（可在「提醒设置」关）· 可约时间段与接单模式在「店铺设置」调整</div>
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

            @if($isEmpty && empty($po['sections'] ?? []))
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
                {{-- nzAllowConfirmed: 单点预约「该叫车」单可能仍是 confirmed(待出餐) → 抽屉放行, [叫车]一步折叠出餐·标记配送中(仅作业台源传此旗, 列表/详情页不传=零影响)。 --}}
                @include('vendor-views.order.partials._dispatch_tools', ['order' => $__do, 'nzDrawer' => true, 'nzAllowConfirmed' => true])
            </div>
        @endforeach
    </div>
