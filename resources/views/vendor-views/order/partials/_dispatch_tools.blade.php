{{-- 哪吒 P3 共享 partial(2026-07-01): Yandex 叫车/贴链接/标记配送中 工具卡。详情页 order-view + 列表页叫车抽屉共用, 不走样。
     入参 $order(需 order_type/order_status/delivery_address/yandex_tracking_url/restaurant 关系)。
     ⚠️ 固定 ID 全部后缀 -{订单号} 防列表多行同页 ID 碰撞。自带 @if delivery 门控, 非配送/非对应状态渲染为空。 --}}
@if ($order->order_type == 'delivery' && in_array($order->order_status, ['processing', 'handover', 'picked_up']))
    @php($yAddr = $order->delivery_address ? json_decode($order->delivery_address, true) : [])
    @php($yLat = $yAddr['latitude'] ?? null)
    @php($yLng = $yAddr['longitude'] ?? null)
    @php($yText = $yAddr['address'] ?? '')
    @if ($order->delivery_link_reminded_at && !$order->yandex_tracking_url)
        <div class="mt-3 mb-1 p-2" style="background:#FFF3F5;border:1px solid #F3C9D2;border-radius:8px;font-size:13px;color:#7c1228;line-height:1.6;">🔔 顾客已请求查看配送进度（{{ \Carbon\Carbon::parse($order->delivery_link_reminded_at)->timezone('Asia/Yerevan')->format('H:i') }}）——请在 Yandex Go 点「分享」复制追踪链接，贴到下方第②步。</div>
    @endif
    <div id="nzYandexCard-{{ $order['id'] }}" class="mt-3 p-3" style="background:#fff;border:1px solid #E6E9EE;border-radius:14px;box-shadow:0 1px 6px rgba(23,25,29,.05);">
        <div style="font-weight:800;font-size:15px;color:#17191D;">🛵 Yandex Go 配送</div>
        <div style="font-size:12px;color:#8A9099;margin-bottom:14px;">餐做好后叫车 → 叫到车贴链接、标记配送中</div>

        @if (in_array($order->order_status, ['processing', 'handover']))
            {{-- 第①步: 叫车 --}}
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                <span style="flex:0 0 auto;width:22px;height:22px;border-radius:50%;background:#1F6FD0;color:#fff;font-weight:700;font-size:13px;display:inline-flex;align-items:center;justify-content:center;">1</span>
                <span style="font-weight:700;color:#17191D;">叫车</span>
            </div>
            @if ($yLat && $yLng && $order->restaurant && $order->restaurant->latitude && $order->restaurant->longitude)
                <a href="https://3.redirect.appmetrica.yandex.com/route?start-lat={{ $order->restaurant->latitude }}&start-lon={{ $order->restaurant->longitude }}&end-lat={{ $yLat }}&end-lon={{ $yLng }}&tariffClass=express&ref=nezha&appmetrica_tracking_id=1178268795219780156&lang=hy"
                    target="_blank" rel="noopener noreferrer"
                    class="btn btn-success w-100" style="font-weight:700;border-radius:10px;font-size:15px;padding:11px;">🛵 一键叫 Yandex Go 配送</a>
                <div style="font-size:11px;color:#8A9099;margin:6px 2px 10px;text-align:center;line-height:1.5;">自动填好「餐厅→顾客」路线，确认即叫车。没装 App 会跳应用商店。</div>
                <details>
                    <summary style="font-size:12px;color:#1F6FD0;cursor:pointer;outline:none;list-style:none;">没自动跳？复制顾客位置手动叫 ▾</summary>
                    <div style="padding:10px 0 2px;">
                        @if ($yText)
                            <div style="font-size:11px;color:#8A9099;margin-bottom:4px;">顾客地址（粘到 Yandex 目的地）</div>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <input type="text" readonly value="{{ $yText }}" class="form-control form-control-sm" style="border-radius:8px;">
                                <button type="button" class="btn btn-sm btn-outline-secondary" style="white-space:nowrap;" onclick="this.previousElementSibling.select();document.execCommand('copy');this.innerText='已复制';">复制</button>
                            </div>
                        @endif
                        <div style="font-size:11px;color:#8A9099;margin-bottom:4px;">顾客坐标</div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <input type="text" readonly value="{{ $yLat }}, {{ $yLng }}" class="form-control form-control-sm" style="border-radius:8px;">
                            <button type="button" class="btn btn-sm btn-outline-secondary" style="white-space:nowrap;" onclick="this.previousElementSibling.select();document.execCommand('copy');this.innerText='已复制';">复制</button>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="https://yandex.com/maps/?ll={{ $yLng }},{{ $yLat }}&z=17&pt={{ $yLng }},{{ $yLat }},pm2rdm&l=map" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary">地图看顾客位置</a>
                            @if ($order->restaurant && $order->restaurant->latitude && $order->restaurant->longitude)
                                {{-- rtext 坐标顺序=lat,lon (与点位 ll/pt 的 lon,lat 相反, 已实测) --}}
                                <a href="https://yandex.com/maps/?rtext={{ $order->restaurant->latitude }},{{ $order->restaurant->longitude }}~{{ $yLat }},{{ $yLng }}&rtt=auto" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary">餐厅→顾客 路线</a>
                            @endif
                        </div>
                    </div>
                </details>
            @else
                <div style="font-size:12px;color:#C4193E;background:#FFF3F5;border:1px solid #F3C9D2;border-radius:8px;padding:8px;">该订单未带坐标（顾客下单未用地图定位），请按地址/电话与顾客确认位置后在 Yandex Go 手动叫车。</div>
            @endif

            {{-- 第②步: 贴链接 + 标记配送中 (单一入口; 贴了存链接转配送中, 没贴直接标配送中) --}}
            <div style="border-top:1px dashed #E6E9EE;margin:14px 0 12px;"></div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                <span style="flex:0 0 auto;width:22px;height:22px;border-radius:50%;background:#17191D;color:#fff;font-weight:700;font-size:13px;display:inline-flex;align-items:center;justify-content:center;">2</span>
                <span style="font-weight:700;color:#17191D;">叫到车后 → 标记配送中</span>
            </div>
            <form id="nzDispatchForm-{{ $order['id'] }}" action="{{ route('vendor.order.mark-dispatched', ['id' => $order['id']]) }}" method="post">
                @csrf
                @method('put')
                <input type="url" name="yandex_tracking_url" id="nzDispatchTrackInput-{{ $order['id'] }}"
                    value="{{ $order->yandex_tracking_url }}"
                    placeholder="贴 Yandex「分享」的追踪链接（可选，贴了顾客能看骑手位置）"
                    class="form-control form-control-sm mb-2" style="border-radius:8px;">
                <button type="submit" class="btn btn-success w-100" style="font-weight:700;border-radius:10px;">出餐 · 标记配送中</button>
                <div style="font-size:11px;color:#8A9099;margin-top:6px;line-height:1.5;">贴了链接=存链接并转「配送中」；不贴直接点也行。链接只接受 yandex.ru / yandex.com。</div>
            </form>
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
            {{-- 已在配送中: 主操作=已送达; 追踪链接可补/改 --}}
            <div style="font-size:13px;color:#0F5132;background:#E9F8EF;border:1px solid #BBE8CC;border-radius:10px;padding:10px;margin-bottom:12px;line-height:1.6;"><b>✅ Yandex 已送达？</b> 顾客收到餐后点下面「已送达」完成本单（顾客也能自己在 App 确认）。约 {{ (int)(\App\CentralLogics\Helpers::get_business_data('nezha_auto_finalize_handover_hours') ?: 3) }} 小时无人确认将自动完成。</div>
            <form action="{{ route('vendor.order.mark-delivered', ['id' => $order['id']]) }}" method="post" class="mb-3" onsubmit="return confirm('确认本单已送达顾客？确认后订单完成、不可撤销。');">
                @csrf
                @method('put')
                <button type="submit" class="btn btn-success w-100" style="font-weight:700;border-radius:10px;">✅ 标记为「已送达」</button>
            </form>
            <form action="{{ route('vendor.order.set-yandex-delivery', ['id' => $order['id']]) }}" method="post">
                @csrf
                @method('put')
                <div style="font-size:12px;color:#8A9099;margin-bottom:6px;">配送追踪链接（顾客实时看骑手位置）</div>
                <input type="url" name="yandex_tracking_url" required
                    value="{{ $order->yandex_tracking_url }}"
                    placeholder="https://...yandex.ru/..."
                    class="form-control form-control-sm mb-2" style="border-radius:8px;">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <button type="submit" class="btn btn-sm btn-outline-success" style="border-radius:8px;">{{ $order->yandex_tracking_url ? '更新链接' : '保存链接' }}</button>
                    @if ($order->yandex_tracking_url)
                        <a href="{{ $order->yandex_tracking_url }}" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary">预览顾客追踪页</a>
                    @endif
                </div>
            </form>
        @endif
    </div>
@endif
