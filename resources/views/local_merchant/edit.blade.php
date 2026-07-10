@extends('local_merchant.panel')
@section('title', '编辑店铺信息')
@php
    $img = fn($f) => \App\CentralLogics\Helpers::get_full_url('local-life-merchant', $f, 'public');
    $pf = fn($k, $d = '') => old($k, $prefill[$k] ?? $d);
    $days = old('open_days', is_array($prefill['open_days'] ?? null) ? $prefill['open_days'] : []);
    $days = array_map('intval', (array) $days);
    $dayNames = ['0'=>'周日','1'=>'周一','2'=>'周二','3'=>'周三','4'=>'周四','5'=>'周五','6'=>'周六'];
    $svcs = old('services', is_array($prefill['services'] ?? null) ? $prefill['services'] : []);
    $cts = old('contacts', is_array($prefill['contacts'] ?? null) ? $prefill['contacts'] : []);
    $methods = ['phone'=>'电话','whatsapp'=>'WhatsApp','telegram'=>'Telegram','wechat'=>'微信'];
    // 房型卡（仅租房民宿类目）：enum label 映射，与 Admin\LocalLifeMerchantController::create.blade / Api\V1\LocalLifeController 白名单一致
    $svcLayouts = ['studio'=>'开间','1b1l'=>'一室一厅','2b1l'=>'两室一厅','3b1l'=>'三室一厅','4plus'=>'四室及以上'];
    $svcAmenities = ['furniture'=>'家具','washer'=>'洗衣机','fridge'=>'冰箱','ac'=>'空调','heating'=>'暖气','elevator'=>'电梯','parking'=>'停车位','balcony'=>'阳台','private_bath'=>'独立卫浴','kitchen'=>'可做饭'];
@endphp
@section('content')

@if($pending)
    <div class="nzp-alert nzp-alert-warn">您正在上一次「待审」内容的基础上继续修改。提交后会<strong>替换</strong>那份待审内容，重新排队等待平台确认。</div>
@endif

<form method="POST" action="{{ route('local-merchant.submit') }}" enctype="multipart/form-data" novalidate>
    @csrf

    <div class="nzp-card">
        <h2>店铺资料</h2>
        <div class="nzp-field">
            <label>店名 <span style="color:var(--nz-red)">*</span><span class="nzp-review-tag">改动需平台重点复核</span></label>
            <input type="text" name="name" class="nzp-input" maxlength="120" required value="{{ $pf('name') }}" placeholder="您的店铺名称">
        </div>
        <div class="nzp-field">
            <label>地址<span class="nzp-review-tag">改动需平台重点复核</span></label>
            <input type="text" name="address" class="nzp-input" maxlength="255" value="{{ $pf('address') }}" placeholder="街道门牌（用于顾客导航）">
            <div class="nzp-hint">地址由平台核对后更新地图定位，您只需填写文字地址。</div>
        </div>
        <div class="nzp-field">
            <label>店铺介绍</label>
            <textarea name="intro" class="nzp-textarea" rows="4" maxlength="3000" placeholder="介绍您的服务、资质等（请勿在此写联系方式，下方单独填）">{{ $pf('intro') }}</textarea>
        </div>
    </div>

    <div class="nzp-card">
        <h2>营业时间</h2>
        <div class="nzp-field">
            <label>营业星期</label>
            <div class="nzp-days" id="nzp-days">
                @foreach($dayNames as $k => $dn)
                    <label class="nzp-day {{ in_array((int)$k, $days, true) ? 'on' : '' }}">
                        <input type="checkbox" name="open_days[]" value="{{ $k }}" {{ in_array((int)$k, $days, true) ? 'checked' : '' }}>{{ $dn }}
                    </label>
                @endforeach
            </div>
        </div>
        <div class="nzp-two">
            <div class="nzp-field"><label>每日开始</label><input type="time" name="open_time" class="nzp-input" value="{{ $pf('open_time') }}"></div>
            <div class="nzp-field"><label>每日结束</label><input type="time" name="close_time" class="nzp-input" value="{{ $pf('close_time') }}"></div>
        </div>
        <div class="nzp-field">
            <label>营业时间补充</label>
            <input type="text" name="hours_note" class="nzp-input" maxlength="120" value="{{ $pf('hours_note') }}" placeholder="如 周末休息 / 节假日另行通知">
        </div>
    </div>

    <div class="nzp-card">
        <h2>服务项{{ $isRental ? '（房型）' : '' }}</h2>
        <div id="nzp-svc-rows">
        @if($isRental)
            @forelse($svcs as $i => $s)
                @php
                    $sAttrs = is_array($s['attrs'] ?? null) ? $s['attrs'] : [];
                    $sAmen = is_array($sAttrs['amenities'] ?? null) ? $sAttrs['amenities'] : [];
                    $sImg = trim((string) ($s['image'] ?? ''));
                @endphp
                <div class="nzp-svc-card" style="border:1px solid rgba(0,0,0,.08);border-radius:10px;padding:10px;margin-bottom:10px">
                    <div class="nzp-row nzp-svc-row">
                        <input type="text" name="services[{{ $i }}][title]" class="nzp-input" placeholder="房型名(如 一室一厅·精装)" value="{{ $s['title'] ?? '' }}">
                        <button type="button" class="nzp-delrow nzp-del">&times;</button>
                    </div>
                    <div class="nzp-field"><input type="text" name="services[{{ $i }}][desc]" class="nzp-input" placeholder="描述(可空)" value="{{ $s['desc'] ?? '' }}"></div>
                    <div class="nzp-field"><input type="text" name="services[{{ $i }}][price_text]" class="nzp-input" placeholder="价格(如 350000֏ /月 起)" value="{{ $s['price_text'] ?? '' }}"></div>
                    <div class="nzp-field">
                        <label>房型图</label>
                        @if($sImg !== '')
                            <div class="nzp-thumbs"><img src="{{ $img($sImg) }}" alt="房型图"></div>
                            <input type="hidden" name="services[{{ $i }}][existing_image]" value="{{ $sImg }}">
                        @endif
                        <input type="file" name="services[{{ $i }}][image]" class="nzp-input" accept="image/*">
                    </div>
                    <div class="nzp-field">
                        <label>户型</label>
                        <select name="services[{{ $i }}][attrs][layout]" class="nzp-select">
                            <option value="">—</option>
                            @foreach($svcLayouts as $lk => $lv)<option value="{{ $lk }}" {{ ($sAttrs['layout'] ?? '') === $lk ? 'selected' : '' }}>{{ $lv }}</option>@endforeach
                        </select>
                    </div>
                    <div class="nzp-field">
                        <label>面积</label>
                        <input type="text" name="services[{{ $i }}][attrs][area_label]" class="nzp-input" placeholder="如 35㎡" value="{{ $sAttrs['area_label'] ?? '' }}">
                    </div>
                    <div class="nzp-field">
                        <label>设施</label>
                        <div style="display:flex;flex-wrap:wrap;gap:4px 12px">
                            @foreach($svcAmenities as $ak => $av)
                                <label style="font-size:12.5px;font-weight:normal;display:inline-flex;align-items:center;gap:4px"><input type="checkbox" name="services[{{ $i }}][attrs][amenities][]" value="{{ $ak }}" {{ in_array($ak, $sAmen, true) ? 'checked' : '' }}>{{ $av }}</label>
                            @endforeach
                        </div>
                    </div>
                </div>
            @empty
                <div class="nzp-svc-card" style="border:1px solid rgba(0,0,0,.08);border-radius:10px;padding:10px;margin-bottom:10px">
                    <div class="nzp-row nzp-svc-row">
                        <input type="text" name="services[0][title]" class="nzp-input" placeholder="房型名(如 一室一厅·精装)">
                        <button type="button" class="nzp-delrow nzp-del">&times;</button>
                    </div>
                    <div class="nzp-field"><input type="text" name="services[0][desc]" class="nzp-input" placeholder="描述(可空)"></div>
                    <div class="nzp-field"><input type="text" name="services[0][price_text]" class="nzp-input" placeholder="价格(如 350000֏ /月 起)"></div>
                    <div class="nzp-field">
                        <label>房型图</label>
                        <input type="file" name="services[0][image]" class="nzp-input" accept="image/*">
                    </div>
                    <div class="nzp-field">
                        <label>户型</label>
                        <select name="services[0][attrs][layout]" class="nzp-select">
                            <option value="">—</option>
                            @foreach($svcLayouts as $lk => $lv)<option value="{{ $lk }}">{{ $lv }}</option>@endforeach
                        </select>
                    </div>
                    <div class="nzp-field">
                        <label>面积</label>
                        <input type="text" name="services[0][attrs][area_label]" class="nzp-input" placeholder="如 35㎡">
                    </div>
                    <div class="nzp-field">
                        <label>设施</label>
                        <div style="display:flex;flex-wrap:wrap;gap:4px 12px">
                            @foreach($svcAmenities as $ak => $av)
                                <label style="font-size:12.5px;font-weight:normal;display:inline-flex;align-items:center;gap:4px"><input type="checkbox" name="services[0][attrs][amenities][]" value="{{ $ak }}">{{ $av }}</label>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforelse
        @else
            @forelse($svcs as $i => $s)
                <div class="nzp-row nzp-svc-row">
                    <input type="text" name="services[{{ $i }}][title]" class="nzp-input" placeholder="服务名(如 剪发)" value="{{ $s['title'] ?? '' }}">
                    <input type="text" name="services[{{ $i }}][price_text]" class="nzp-input" placeholder="价格(如 3000dram)" value="{{ $s['price_text'] ?? '' }}" style="flex:0 0 40%">
                    <button type="button" class="nzp-delrow nzp-del">&times;</button>
                </div>
                @if(!empty($s['desc']))
                    <div class="nzp-row nzp-svc-row" style="margin-top:-4px"><input type="text" name="services[{{ $i }}][desc]" class="nzp-input" placeholder="描述(可空)" value="{{ $s['desc'] }}"></div>
                @endif
            @empty
                <div class="nzp-row nzp-svc-row">
                    <input type="text" name="services[0][title]" class="nzp-input" placeholder="服务名(如 剪发)">
                    <input type="text" name="services[0][price_text]" class="nzp-input" placeholder="价格(如 3000dram)" style="flex:0 0 40%">
                    <button type="button" class="nzp-delrow nzp-del">&times;</button>
                </div>
            @endforelse
        @endif
        </div>
        <button type="button" class="nzp-addrow" id="nzp-add-svc">+ 添加{{ $isRental ? '房型' : '服务项' }}</button>
        <div class="nzp-hint">标题必填，价格仅为展示文字。@if($isRental) 房型图/户型/面积/设施仅用于展示，平台不收款；照片含人物入镜请确认已获同意。@endif</div>
    </div>

    <div class="nzp-card">
        <h2>联系方式</h2>
        <div id="nzp-ct-rows">
            @forelse($cts as $i => $c)
                <div class="nzp-row nzp-ct-row">
                    <select name="contacts[{{ $i }}][method]" class="nzp-select" style="flex:0 0 30%">
                        @foreach($methods as $mk => $ml)<option value="{{ $mk }}" {{ ($c['method'] ?? '') === $mk ? 'selected' : '' }}>{{ $ml }}</option>@endforeach
                    </select>
                    <input type="text" name="contacts[{{ $i }}][value]" class="nzp-input" placeholder="号码/用户名/微信号" value="{{ $c['value'] ?? '' }}">
                    <button type="button" class="nzp-delrow nzp-del">&times;</button>
                </div>
            @empty
                <div class="nzp-row nzp-ct-row">
                    <select name="contacts[0][method]" class="nzp-select" style="flex:0 0 30%">
                        @foreach($methods as $mk => $ml)<option value="{{ $mk }}">{{ $ml }}</option>@endforeach
                    </select>
                    <input type="text" name="contacts[0][value]" class="nzp-input" placeholder="号码/用户名/微信号">
                    <button type="button" class="nzp-delrow nzp-del">&times;</button>
                </div>
            @endforelse
        </div>
        <button type="button" class="nzp-addrow" id="nzp-add-ct">+ 添加联系方式</button>
        <div class="nzp-hint">电话=可拨号 / WhatsApp=带国码号(如 +374 43 329475) / Telegram=用户名(如 @name) / 微信=微信号（二维码在下方上传）。</div>
    </div>

    <div class="nzp-card">
        <h2>优惠</h2>
        <label class="nzp-check" style="margin-bottom:10px">
            <input type="checkbox" name="has_offer" value="1" {{ old('has_offer', $prefill['has_offer'] ?? false) ? 'checked' : '' }}>
            <span>有到店优惠（列表显示「到店优惠」标签，仅展示不核销）</span>
        </label>
        <div class="nzp-field"><label>优惠文字</label><input type="text" name="offer_text" class="nzp-input" maxlength="120" value="{{ $pf('offer_text') }}" placeholder="如 到店出示立减 10%"></div>
    </div>

    <div class="nzp-card">
        <h2>图片</h2>
        <div class="nzp-field">
            <label>Logo / 头像</label>
            @if(!empty($prefill['logo']))<div class="nzp-thumbs"><img src="{{ $img($prefill['logo']) }}" alt="logo"></div>@endif
            <input type="file" name="logo" class="nzp-input" accept="image/*">
        </div>
        <div class="nzp-field">
            <label>微信二维码</label>
            @if(!empty($prefill['wechat_qr']))<div class="nzp-thumbs"><img src="{{ $img($prefill['wechat_qr']) }}" alt="wechat qr" style="height:70px"></div>@endif
            <input type="file" name="wechat_qr" class="nzp-input" accept="image/*">
        </div>
        <div class="nzp-field">
            <label>相册（可多选）</label>
            @if(is_array($prefill['images'] ?? null) && count($prefill['images']))
                <div class="nzp-thumbs">@foreach($prefill['images'] as $im)<img src="{{ $img($im) }}" alt="相册">@endforeach</div>
                <div class="nzp-hint" style="margin-bottom:6px">重新上传会替换全部相册图；不选则保留现状。</div>
            @endif
            <input type="file" name="images[]" class="nzp-input" accept="image/*" multiple>
        </div>
    </div>

    <div class="nzp-alert nzp-alert-warn" style="font-size:12.5px">提交后不会立即生效——平台确认后才会更新到顾客端。照片如含人物入镜，请确认已获当事人同意使用。</div>

    <div class="nzp-btnrow">
        <a href="{{ route('local-merchant.home') }}" class="nzp-btn ghost" style="flex:0 0 34%">取消</a>
        <button type="submit" class="nzp-btn" style="flex:1">提交给平台确认</button>
    </div>
</form>

@push('scripts')
<script>
(function () {
    var NZP_IS_RENTAL = @json($isRental);
    var NZP_SVC_LAYOUTS = @json($svcLayouts);
    var NZP_SVC_AMENITIES = @json($svcAmenities);
    // 星期 chip 视觉切换
    var days = document.getElementById('nzp-days');
    if (days) days.addEventListener('click', function (e) {
        var lab = e.target.closest ? e.target.closest('.nzp-day') : null;
        if (!lab) return;
        setTimeout(function () {
            var cb = lab.querySelector('input');
            lab.classList.toggle('on', cb && cb.checked);
        }, 0);
    });

    // 删除动态行（事件委托）——房型卡先找整卡(svc-card)，否则退回单行(svc-row/ct-row)
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.nzp-del') : null;
        if (!btn) return;
        var row = btn.closest('.nzp-svc-card') || btn.closest('.nzp-svc-row') || btn.closest('.nzp-ct-row');
        if (!row) return;
        row.parentNode.removeChild(row);
    });

    // 添加服务项/房型
    var svcIdx = 1000, addSvc = document.getElementById('nzp-add-svc'), svcRows = document.getElementById('nzp-svc-rows');
    if (addSvc && svcRows) addSvc.addEventListener('click', function () {
        var i = svcIdx++;
        if (NZP_IS_RENTAL) {
            var layoutOpts = '<option value="">—</option>';
            Object.keys(NZP_SVC_LAYOUTS).forEach(function (k) { layoutOpts += '<option value="' + k + '">' + NZP_SVC_LAYOUTS[k] + '</option>'; });
            var amenBoxes = '';
            Object.keys(NZP_SVC_AMENITIES).forEach(function (k) { amenBoxes += '<label style="font-size:12.5px;font-weight:normal;display:inline-flex;align-items:center;gap:4px"><input type="checkbox" name="services[' + i + '][attrs][amenities][]" value="' + k + '">' + NZP_SVC_AMENITIES[k] + '</label>'; });
            var card = document.createElement('div');
            card.className = 'nzp-svc-card';
            card.style.cssText = 'border:1px solid rgba(0,0,0,.08);border-radius:10px;padding:10px;margin-bottom:10px';
            card.innerHTML = '<div class="nzp-row nzp-svc-row"><input type="text" name="services[' + i + '][title]" class="nzp-input" placeholder="房型名(如 一室一厅·精装)"><button type="button" class="nzp-delrow nzp-del">&times;</button></div>' +
                '<div class="nzp-field"><input type="text" name="services[' + i + '][desc]" class="nzp-input" placeholder="描述(可空)"></div>' +
                '<div class="nzp-field"><input type="text" name="services[' + i + '][price_text]" class="nzp-input" placeholder="价格(如 350000֏ /月 起)"></div>' +
                '<div class="nzp-field"><label>房型图</label><input type="file" name="services[' + i + '][image]" class="nzp-input" accept="image/*"></div>' +
                '<div class="nzp-field"><label>户型</label><select name="services[' + i + '][attrs][layout]" class="nzp-select">' + layoutOpts + '</select></div>' +
                '<div class="nzp-field"><label>面积</label><input type="text" name="services[' + i + '][attrs][area_label]" class="nzp-input" placeholder="如 35㎡"></div>' +
                '<div class="nzp-field"><label>设施</label><div style="display:flex;flex-wrap:wrap;gap:4px 12px">' + amenBoxes + '</div></div>';
            svcRows.appendChild(card);
            return;
        }
        var d = document.createElement('div');
        d.className = 'nzp-row nzp-svc-row';
        d.innerHTML = '<input type="text" name="services[' + i + '][title]" class="nzp-input" placeholder="服务名(如 剪发)">' +
            '<input type="text" name="services[' + i + '][price_text]" class="nzp-input" placeholder="价格(如 3000dram)" style="flex:0 0 40%">' +
            '<button type="button" class="nzp-delrow nzp-del">&times;</button>';
        svcRows.appendChild(d);
    });

    // 添加联系方式
    var ctIdx = 1000, addCt = document.getElementById('nzp-add-ct'), ctRows = document.getElementById('nzp-ct-rows');
    var opts = '<option value="phone">电话</option><option value="whatsapp">WhatsApp</option><option value="telegram">Telegram</option><option value="wechat">微信</option>';
    if (addCt && ctRows) addCt.addEventListener('click', function () {
        var i = ctIdx++, d = document.createElement('div');
        d.className = 'nzp-row nzp-ct-row';
        d.innerHTML = '<select name="contacts[' + i + '][method]" class="nzp-select" style="flex:0 0 30%">' + opts + '</select>' +
            '<input type="text" name="contacts[' + i + '][value]" class="nzp-input" placeholder="号码/用户名/微信号">' +
            '<button type="button" class="nzp-delrow nzp-del">&times;</button>';
        ctRows.appendChild(d);
    });
})();
</script>
@endpush
@endsection
