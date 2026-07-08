{{-- 哪吒 P3 共享 partial: Yandex 叫车/贴链接/标记配送中 工具卡。作业台 + 列表页叫车抽屉 + 详情页内嵌卡三处共用。
     入参 order(需 order_type/order_status/delivery_address/yandex_tracking_url/restaurant 关系)。
     可选入参 nzDrawer(bool): true=抽屉内(作业台/列表叫车)显订单上下文条+送达地址块; 缺省 false=详情页内嵌卡(页面已有同信息不重复)。
     固定 ID 全部后缀连订单号 防列表多行同页 ID 碰撞。自带 delivery 门控, 非配送/非对应状态渲染为空。
     两步逻辑/端点/表单字段/JS 钩子沿用上一版零改动(0703 呈现壳改版: 居中弹层改右侧或底部抽屉, 杂绿改系统藏青)。
     实现约束: 全文只用一个 php 语句块, 不混用内联 php 指令, 防 Blade 原始块正则误配吞并整段。 --}}
@if ($order->order_type == 'delivery' && in_array($order->order_status, ['processing', 'handover', 'picked_up']))
    @php
        $nzDrawer = $nzDrawer ?? false;
        $yAddr = $order->delivery_address ? json_decode($order->delivery_address, true) : [];
        $yAddr = is_array($yAddr) ? $yAddr : [];
        $yLat = $yAddr['latitude'] ?? null;
        $yLng = $yAddr['longitude'] ?? null;
        $yText = $yAddr['address'] ?? '';
        $isCall = in_array($order->order_status, ['processing', 'handover']);
        $hasCoord = $yLat && $yLng;
        $hasRoute = $hasCoord && $order->restaurant && $order->restaurant->latitude && $order->restaurant->longitude;
        // 抽屉内独有: 订单上下文条 + 送达地址块所需数据(详情页 $nzDrawer=false 不算, 页面已有同信息)
        $nzItems = ''; $cny = null; $doorParts = []; $custLine = '';
        if ($nzDrawer) {
            $allDetails = $order->details ?? collect();
            $parts = [];
            foreach ($allDetails as $d) {
                $fd = is_string($d->food_details ?? null) ? json_decode($d->food_details) : ($d->food_details ?? null);
                if (!empty($fd->name)) { $parts[] = $fd->name . ' ×' . (int) $d->quantity; }
                if (count($parts) >= 2) break;
            }
            $nzItems = implode(' · ', $parts);
            if ($nzItems !== '' && $allDetails->count() > count($parts)) { $nzItems .= ' 等'; }
            $rate = (float) (\App\CentralLogics\Helpers::get_business_data('nezha_rate_cny_to_amd') ?: 55);
            $cny  = $rate > 0 ? round(((float) $order->order_amount) / $rate, 1) : null;
            $cName = trim((string) ($yAddr['contact_person_name'] ?? ''));
            $cPhone = (string) ($yAddr['contact_person_number'] ?? '');
            $house = trim((string) ($yAddr['house'] ?? ''));
            $floor = trim((string) ($yAddr['floor'] ?? ''));
            $doorParts = array_values(array_filter([$house, $floor], fn ($v) => $v !== ''));
            $custLine = '顾客 ' . ($cName !== '' ? \App\CentralLogics\Helpers::mask_name($cName) : '—');
            if ($cPhone !== '') { $custLine .= '（' . \App\CentralLogics\Helpers::mask_phone($cPhone) . '）'; }
            if ($order->created_at) { $custLine .= ' · 下单 ' . \Carbon\Carbon::parse($order->created_at)->timezone('Asia/Yerevan')->format('H:i'); }
        }
    @endphp

    @once
    <style>
    .nzyx{--nvy:#102A4C;--body:#42505F;--sec:#98A2B3;--line:#D6DBE1;--bg2:#F7F8FA;--ink:#17191D;--chip:#F6F7F9;--yx:#FC3F1D;
        display:flex;flex-direction:column;flex:1 1 auto;min-height:0;overflow:hidden;background:#fff;color:var(--body);
        border:1px solid var(--line);border-radius:14px;
        font-family:"Noto Sans Armenian","Segoe UI","Microsoft YaHei","PingFang SC",sans-serif;font-size:14px;line-height:1.5}
    .nz-dispatch-sheet .nzyx{border:none;border-radius:0}
    .nzyx *{box-sizing:border-box}
    .nzyx .num{font-variant-numeric:tabular-nums}

    .nzyx-head{flex:0 0 auto;display:flex;align-items:center;gap:9px;padding:15px 18px;border-bottom:1px solid var(--line)}
    .nzyx-dot{width:10px;height:10px;border-radius:3px;background:var(--yx);flex:0 0 10px}
    .nzyx-ttl{color:var(--nvy);font-size:16px;font-weight:800}
    .nzyx-oid{color:var(--sec);font-weight:400;font-size:13px}
    .nzyx-x{margin-left:auto;width:30px;height:30px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--sec);font-size:15px;display:none;align-items:center;justify-content:center;cursor:pointer;flex:0 0 auto;padding:0}
    .nz-dispatch-sheet .nzyx-x{display:flex}

    .nzyx-scroll{flex:1 1 auto;min-height:0;overflow-y:auto;padding:16px 18px 18px}
    .nzyx-sub{color:var(--sec);font-size:12.5px;margin:0 0 14px}

    .nzyx-remind{margin:0 0 14px;padding:9px 12px;background:#FFF3F5;border:1px solid #F3C9D2;border-radius:10px;font-size:12.5px;color:#7c1228;line-height:1.6}

    .nzyx-ctx{background:var(--bg2);border:1px solid var(--line);border-radius:10px;padding:11px 14px;margin-bottom:14px}
    .nzyx-ctx .l1{display:flex;justify-content:space-between;align-items:baseline;gap:10px}
    .nzyx-ctx .food{color:var(--ink);font-weight:600}
    .nzyx-ctx .amt{color:var(--ink);font-weight:700;white-space:nowrap}
    .nzyx-ctx .amt small{color:var(--sec);font-weight:400}
    .nzyx-ctx .l2{color:var(--sec);font-size:12.5px;margin-top:3px}

    .nzyx-addr{border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin-bottom:18px}
    .nzyx-addr .cap{color:var(--sec);font-size:12px;margin-bottom:4px}
    .nzyx-addr .a{color:var(--ink);font-size:14px;font-weight:600;line-height:1.5}
    .nzyx-addr .note{color:var(--body);font-size:12.5px;margin-top:3px;line-height:1.6}
    .nzyx-addr .ops{display:flex;gap:8px;margin-top:11px}
    .nzyx-addr .ops>*{flex:1;border:1.5px solid var(--line);background:#fff;border-radius:8px;padding:8px 0;font-size:13px;color:var(--nvy);font-weight:600;cursor:pointer;font-family:inherit;text-align:center;text-decoration:none;line-height:1.4}

    .nzyx-step{display:flex;gap:12px}
    .nzyx-step .rail{display:flex;flex-direction:column;align-items:center;flex:0 0 22px}
    .nzyx-step .dot{width:22px;height:22px;border-radius:50%;background:var(--nvy);color:#fff;font-size:12.5px;font-weight:700;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
    .nzyx-step .dot.hollow{background:#fff;color:var(--nvy);border:2px solid var(--nvy)}
    .nzyx-step .vline{width:2px;flex:1 1 auto;background:var(--line);margin:4px 0;min-height:14px}
    .nzyx-step .cont{flex:1 1 auto;padding-bottom:18px;min-width:0}
    .nzyx-step .t{color:var(--nvy);font-weight:700;font-size:14.5px;line-height:22px}
    .nzyx-step .d{color:var(--sec);font-size:12.5px;line-height:1.7;margin:3px 0 0}
    .nzyx-optional{color:var(--sec);font-weight:400;font-size:12px;margin-left:6px;background:var(--chip);border:1px solid var(--line);border-radius:6px;padding:1px 6px}

    .nzyx-callbtn{display:block;width:100%;margin-top:10px;background:var(--nvy);color:#fff;border:none;border-radius:10px;padding:12px 0;font-size:14.5px;font-weight:700;cursor:pointer;font-family:inherit;text-align:center;text-decoration:none}
    .nzyx-warn{margin-top:8px;font-size:12.5px;color:#C4193E;background:#FFF3F5;border:1px solid #F3C9D2;border-radius:8px;padding:9px 11px;line-height:1.6}

    .nzyx-details{margin-top:9px}
    .nzyx-details>summary{color:var(--sec);font-size:12.5px;text-align:center;text-decoration:underline;text-underline-offset:3px;cursor:pointer;outline:none;list-style:none}
    .nzyx-details>summary::-webkit-details-marker{display:none}
    .nzyx-flabel{font-size:11px;color:var(--sec);margin:8px 0 4px}
    .nzyx-copyrow{display:flex;align-items:center;gap:8px;margin-bottom:2px}
    .nzyx-copybtn{white-space:nowrap;border:1px solid var(--line);background:#fff;color:var(--nvy);border-radius:8px;padding:7px 12px;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit}
    .nzyx-maplinks{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
    .nzyx-maplinks a{border:1px solid var(--line);background:#fff;color:var(--nvy);border-radius:8px;padding:7px 12px;font-size:12.5px;font-weight:600;text-decoration:none}

    .nzyx-ip{width:100%;margin-top:10px;border:1px solid var(--line);border-radius:10px;padding:11px 12px;font-size:13px;color:var(--ink);font-family:inherit;background:#fff}
    .nzyx-ip::placeholder{color:var(--sec)}
    .nzyx-hint{display:flex;gap:6px;align-items:flex-start;margin-top:8px;color:var(--sec);font-size:12px;line-height:1.6}

    .nzyx-note-ok{font-size:13px;color:var(--ink);background:var(--bg2);border:1px solid var(--line);border-radius:10px;padding:11px 13px;margin-bottom:14px;line-height:1.6}
    .nzyx-note-ok b{color:var(--nvy)}
    .nzyx-linkbox .nzyx-linkops{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:10px}
    .nzyx-obtn{border:1.5px solid var(--nvy);background:#fff;color:var(--nvy);border-radius:9px;padding:9px 16px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;line-height:1.4}
    .nzyx-obtn.ghost{border-color:var(--line);color:var(--sec);font-weight:600}

    .nzyx-foot{flex:0 0 auto;border-top:1px solid var(--line);padding:14px 18px 16px;background:#fff}
    .nzyx-go{display:block;width:100%;background:var(--nvy);color:#fff;border:none;border-radius:10px;padding:14px 0;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;text-align:center}
    .nzyx-fnote{text-align:center;color:var(--sec);font-size:12px;margin-top:8px;line-height:1.5}

    @media (max-width:480px){ .nz-dispatch-sheet .nzyx-scroll{padding-bottom:26px} }
    </style>
    @endonce

    <div class="nzyx" id="nzYandexCard-{{ $order['id'] }}">
        <div class="nzyx-head">
            <span class="nzyx-dot"></span>
            <b class="nzyx-ttl">Yandex Go 配送</b>
            <span class="nzyx-oid">· 订单 #{{ $order['id'] }}</span>
            <button type="button" class="nzyx-x" data-nz-dispatch-close aria-label="关闭">&times;</button>
        </div>

        <div class="nzyx-scroll">
            @if ($order->delivery_link_reminded_at && !$order->yandex_tracking_url)
                <div class="nzyx-remind">🔔 顾客已请求查看配送进度（{{ \Carbon\Carbon::parse($order->delivery_link_reminded_at)->timezone('Asia/Yerevan')->format('H:i') }}）——请在 Yandex Go 点「分享」复制追踪链接，贴到下方第②步。</div>
            @endif

            @if ($nzDrawer)
                <div class="nzyx-ctx">
                    <div class="l1">
                        <span class="food">{{ $nzItems !== '' ? $nzItems : '本单商品' }}</span>
                        <span class="amt num">֏{{ number_format((float) $order->order_amount) }}@if($cny) <small>≈ ¥{{ $cny }}</small>@endif</span>
                    </div>
                    <div class="l2">{{ $custLine }}</div>
                </div>

                <div class="nzyx-addr">
                    <div class="cap">送达地址</div>
                    <div class="a">{{ $yText !== '' ? $yText : '（顾客未填写文字地址）' }}</div>
                    <div class="note">门牌/楼层：{{ count($doorParts) ? implode(' · ', $doorParts) : '—' }} &nbsp;·&nbsp; 备注：{{ $order->order_note ?: '—' }}</div>
                    <div class="ops">
                        <button type="button" data-copy="{{ $yText }}" onclick="var v=this.getAttribute('data-copy')||'';if(navigator.clipboard){navigator.clipboard.writeText(v);}else{var t=document.createElement('textarea');t.value=v;document.body.appendChild(t);t.select();try{document.execCommand('copy');}catch(e){}document.body.removeChild(t);}var b=this;b.textContent='已复制';setTimeout(function(){b.textContent='复制地址';},1500);">复制地址</button>
                        @if ($hasCoord)
                            <a href="https://yandex.com/maps/?ll={{ $yLng }},{{ $yLat }}&z=17&pt={{ $yLng }},{{ $yLat }},pm2rdm&l=map" target="_blank" rel="noopener noreferrer">在 Yandex 查看位置</a>
                        @endif
                    </div>
                </div>
            @else
                <div class="nzyx-sub">餐做好后叫车 → 叫到车贴链接、标记配送中</div>
            @endif

            @if ($isCall)
                <div class="nzyx-step">
                    <div class="rail"><div class="dot">1</div><div class="vline"></div></div>
                    <div class="cont">
                        <div class="t">叫车</div>
                        @if ($hasRoute)
                            <div class="d">自动填好「餐厅 → 顾客」路线，确认即叫车；没装 App 会跳应用商店。</div>
                            <a href="https://3.redirect.appmetrica.yandex.com/route?start-lat={{ $order->restaurant->latitude }}&start-lon={{ $order->restaurant->longitude }}&end-lat={{ $yLat }}&end-lon={{ $yLng }}&tariffClass=express&ref=nezha&appmetrica_tracking_id=1178268795219780156&lang=hy"
                                target="_blank" rel="noopener noreferrer" class="nzyx-callbtn">打开 Yandex Go 叫车</a>
                            <details class="nzyx-details">
                                <summary>没自动跳？复制顾客位置，手动下单叫车</summary>
                                @if ($yText)
                                    <div class="nzyx-flabel">顾客地址（粘到 Yandex 目的地）</div>
                                    <div class="nzyx-copyrow">
                                        <input type="text" readonly value="{{ $yText }}" class="nzyx-ip" style="margin-top:0;">
                                        <button type="button" class="nzyx-copybtn" onclick="this.previousElementSibling.select();try{document.execCommand('copy');}catch(e){}this.innerText='已复制';">复制</button>
                                    </div>
                                @endif
                                <div class="nzyx-flabel">顾客坐标</div>
                                <div class="nzyx-copyrow">
                                    <input type="text" readonly value="{{ $yLat }}, {{ $yLng }}" class="nzyx-ip" style="margin-top:0;">
                                    <button type="button" class="nzyx-copybtn" onclick="this.previousElementSibling.select();try{document.execCommand('copy');}catch(e){}this.innerText='已复制';">复制</button>
                                </div>
                                <div class="nzyx-maplinks">
                                    <a href="https://yandex.com/maps/?ll={{ $yLng }},{{ $yLat }}&z=17&pt={{ $yLng }},{{ $yLat }},pm2rdm&l=map" target="_blank" rel="noopener noreferrer">地图看顾客位置</a>
                                    <a href="https://yandex.com/maps/?rtext={{ $order->restaurant->latitude }},{{ $order->restaurant->longitude }}~{{ $yLat }},{{ $yLng }}&rtt=auto" target="_blank" rel="noopener noreferrer">餐厅→顾客 路线</a>
                                </div>
                            </details>
                        @else
                            <div class="nzyx-warn">该订单未带坐标（顾客下单未用地图定位），请按地址/电话与顾客确认位置后在 Yandex Go 手动叫车。</div>
                        @endif
                    </div>
                </div>

                <div class="nzyx-step">
                    <div class="rail"><div class="dot hollow">2</div></div>
                    <div class="cont" style="padding-bottom:2px">
                        <div class="t">贴追踪链接<span class="nzyx-optional">可选</span></div>
                        <div class="d">在 Yandex Go 点「分享」复制链接贴到这里，顾客就能实时看到骑手位置。</div>
                        <form id="nzDispatchForm-{{ $order['id'] }}" action="{{ route('vendor.order.mark-dispatched', ['id' => $order['id']]) }}" method="post" data-nz-ajax data-nz-ok-toast="已标记为「配送中」，顾客已看到">
                            @csrf
                            @method('put')
                            <input type="url" name="yandex_tracking_url" id="nzDispatchTrackInput-{{ $order['id'] }}"
                                value="{{ $order->yandex_tracking_url }}"
                                placeholder="https://…yandex.ru/…"
                                class="nzyx-ip">
                        </form>
                        <div class="nzyx-hint"><span>ⓘ</span><span>不贴也可以直接出餐；链接只接受 yandex.ru / yandex.com，出餐后仍可在订单里补贴。</span></div>
                    </div>
                </div>
                <script>
                (function(){
                    var f = document.getElementById('nzDispatchForm-{{ $order['id'] }}');
                    var inp = document.getElementById('nzDispatchTrackInput-{{ $order['id'] }}');
                    if(!f || !inp) return;
                    var rDispatch = @json(route('vendor.order.mark-dispatched', ['id' => $order['id']]));
                    var rYandex = @json(route('vendor.order.set-yandex-delivery', ['id' => $order['id']]));
                    f.addEventListener('submit', function(){
                        var v = (inp.value || '').trim();
                        if (v) { f.action = rYandex; }
                        else { inp.removeAttribute('name'); f.action = rDispatch; }
                    });
                })();
                </script>
            @endif

            @if ($order->order_status == 'picked_up')
                <div class="nzyx-note-ok"><b>✅ Yandex 已送达？</b> 顾客收到餐后点下面「已送达」完成本单（顾客也能自己在 App 确认）。约 {{ (int)(\App\CentralLogics\Helpers::get_business_data('nezha_auto_finalize_handover_hours') ?: 3) }} 小时无人确认将自动完成。</div>
                <form id="nzDeliveredForm-{{ $order['id'] }}" action="{{ route('vendor.order.mark-delivered', ['id' => $order['id']]) }}" method="post" data-nz-ajax data-nz-confirm="确认本单已送达顾客？确认后订单完成、不可撤销。" data-nz-confirm-danger data-nz-ok-toast="已标记为「已送达」，本单完成">
                    @csrf
                    @method('put')
                </form>
                <div class="nzyx-linkbox">
                    <form action="{{ route('vendor.order.set-yandex-delivery', ['id' => $order['id']]) }}" method="post">
                        @csrf
                        @method('put')
                        <div class="nzyx-flabel">配送追踪链接（顾客实时看骑手位置）</div>
                        <input type="url" name="yandex_tracking_url" required
                            value="{{ $order->yandex_tracking_url }}"
                            placeholder="https://...yandex.ru/..."
                            class="nzyx-ip" style="margin-top:0;">
                        <div class="nzyx-linkops">
                            <button type="submit" class="nzyx-obtn">{{ $order->yandex_tracking_url ? '更新链接' : '保存链接' }}</button>
                            @if ($order->yandex_tracking_url)
                                <a href="{{ $order->yandex_tracking_url }}" target="_blank" rel="noopener noreferrer" class="nzyx-obtn ghost">预览顾客追踪页</a>
                            @endif
                        </div>
                    </form>
                </div>
            @endif
        </div>

        <div class="nzyx-foot">
            @if ($isCall)
                <button type="submit" form="nzDispatchForm-{{ $order['id'] }}" class="nzyx-go">出餐 · 标记配送中</button>
                <div class="nzyx-fnote">点击后订单进入「配送中」，顾客将收到通知</div>
            @elseif ($order->order_status == 'picked_up')
                <button type="submit" form="nzDeliveredForm-{{ $order['id'] }}" class="nzyx-go">✅ 标记为「已送达」</button>
                <div class="nzyx-fnote">确认后订单完成、不可撤销</div>
            @endif
        </div>
    </div>
@endif
