@extends('layouts.admin.app')
@section('title', isset($merchant) && $merchant ? '编辑本地生活商家' : '新建本地生活商家')

@section('content')
@php
    $isEdit = isset($merchant) && $merchant;
    $action = $isEdit ? route('admin.local-life.merchants.update', $merchant->id) : route('admin.local-life.merchants.store');
    $val = fn($field, $default = '') => old($field, $isEdit ? $merchant->$field : $default);
    $openDays = old('open_days', $isEdit && is_array($merchant->open_days) ? $merchant->open_days : []);
    $dayNames = ['0'=>'周日','1'=>'周一','2'=>'周二','3'=>'周三','4'=>'周四','5'=>'周五','6'=>'周六'];
    $existingServices = old('services', $isEdit && is_array($merchant->services) ? $merchant->services : []);
    $existingContacts = old('contacts', $isEdit && is_array($merchant->contacts) ? $merchant->contacts : []);
    $contactMethods = ['phone'=>'电话','whatsapp'=>'WhatsApp','telegram'=>'Telegram','wechat'=>'微信'];
    // 房型卡(HANDOFF §2b)：services 每项可选 image + attrs(户型/面积/设施)。租房民宿类目用；其他类目留空即回落现状文字行。
    $svcRows = count($existingServices) ? $existingServices : [[]];
    $svcLayouts = ['studio'=>'开间','1b1l'=>'一室一厅','2b1l'=>'两室一厅','3b1l'=>'三室一厅','4plus'=>'四室及以上'];
    $svcAmenities = ['furniture'=>'家具','washer'=>'洗衣机','fridge'=>'冰箱','ac'=>'空调','heating'=>'暖气','elevator'=>'电梯','parking'=>'停车位','balcony'=>'阳台','private_bath'=>'独立卫浴','kitchen'=>'可做饭'];
@endphp

<div class="content container-fluid">
    <div class="page-header mb-1">
        <h1 class="page-header-title fs-24">{{ $isEdit ? '编辑商家' : '新建商家' }}</h1>
        <small class="text-muted">商家页是纯信息展示（评分/营业时间/地址/介绍/服务/联系方式），平台不碰钱、不接预订下单。</small>
    </div>

    @if($isEdit)
    @php $acct = $account ?? null; @endphp
    <div class="card mb-3" style="border-left:3px solid #102A4C">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">商户自助管理账号</h5>
            @if($acct)
                <span class="badge badge-soft-{{ $acct->status ? 'success' : 'danger' }}">{{ $acct->status ? '启用' : '已停用' }}{{ $acct->password ? '' : ' · 未设密' }}</span>
            @else
                <span class="badge badge-soft-secondary">未开通</span>
            @endif
        </div>
        <div class="card-body">
            <small class="text-muted d-block mb-2">给店主一个自助维护店铺信息的账号（邮箱+密码）。店主的所有修改都进平台复审，通过后才更新到顾客端。总开关 <code>nezha_local_merchant_selfserve_status</code> 关闭时店主无法登录。</small>
            @if(!$acct)
                <form action="{{ route('admin.local-life.merchants.account.create', $merchant->id) }}" method="post" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-md-6"><label class="input-label">店主邮箱</label><input type="email" name="email" class="form-control" required placeholder="owner@example.com"></div>
                    <div class="col-md-3"><label class="input-label">联系人(可空)</label><input type="text" name="contact_name" class="form-control" maxlength="120"></div>
                    <div class="col-md-3"><button type="submit" class="btn btn--primary w-100">开通 + 发设密邮件</button></div>
                </form>
            @else
                <div class="mb-2">
                    <strong>{{ $acct->email }}</strong>@if($acct->contact_name) · {{ $acct->contact_name }}@endif
                    @if($acct->last_login_at)<small class="text-muted d-block">上次登录：{{ $acct->last_login_at->timezone('Asia/Yerevan')->format('Y-m-d H:i') }}</small>@else<small class="text-muted d-block">尚未登录</small>@endif
                </div>
                <div class="d-flex flex-wrap" style="gap:8px">
                    <form action="{{ route('admin.local-life.merchants.account.send-link', $merchant->id) }}" method="post">@csrf<button type="submit" class="btn btn-sm btn--primary">{{ $acct->password ? '发送重置密码邮件' : '重新发送设密邮件' }}</button></form>
                    <form action="{{ route('admin.local-life.merchants.account.toggle', $merchant->id) }}" method="post">@csrf<button type="submit" class="btn btn-sm {{ $acct->status ? 'btn-outline-warning' : 'btn-outline-success' }}">{{ $acct->status ? '停用账号' : '启用账号' }}</button></form>
                    <form action="{{ route('admin.local-life.merchants.account.delete', $merchant->id) }}" method="post" onsubmit="return confirm('确认删除该商户账号？店主将无法登录（不影响店铺条目与历史提交）。');">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger">删除账号</button></form>
                </div>
                <form action="{{ route('admin.local-life.merchants.account.email', $merchant->id) }}" method="post" class="row g-2 align-items-end mt-2">
                    @csrf
                    <div class="col-md-8"><label class="input-label">修改绑定邮箱</label><input type="email" name="email" class="form-control form-control-sm" value="{{ $acct->email }}" required></div>
                    <div class="col-md-4"><button type="submit" class="btn btn-sm btn--reset w-100">更新邮箱</button></div>
                </form>
                @if(!$acct->password)<small class="text-warning d-block mt-2">该账号尚未设置密码——店主需点邮件里的链接设密后才能登录。</small>@endif
            @endif
            <div class="mt-2"><span class="text-muted" style="font-size:12px">商户登录入口：<code>{{ url('m/login') }}</code>（需总开关开启）</span></div>
        </div>
    </div>
    @endif

    <form action="{{ $action }}" method="post" enctype="multipart/form-data">
        @csrf
        <div class="row gx-2 gx-lg-3">
            <div class="col-12 col-lg-8 mb-3">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">基本信息</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="input-label">商家名 <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" maxlength="120" required placeholder="如 雅顺移民" value="{{ $val('name') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="input-label">所属类目 <span class="text-danger">*</span></label>
                                <select name="category" class="form-control" required>
                                    <option value="">请选择</option>
                                    @foreach($categories as $c)
                                        <option value="{{ $c->name }}" {{ $val('category') === $c->name ? 'selected' : '' }}>
                                            {{ $c->emoji }} {{ $c->name }}{{ $c->is_sensitive ? '（敏感·重点审核）' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                                <small class="text-muted">仅显示「商家服务」型类目。租房/二手是个人发帖，不在此。</small>
                            </div>
                            <div class="col-md-12">
                                <label class="input-label">商家介绍</label>
                                <textarea name="intro" class="form-control" rows="4" maxlength="3000" placeholder="持牌移民顾问，执业多年……">{{ $val('intro') }}</textarea>
                                <small class="text-muted">介绍里不用再写联系方式——联系方式请填下方「联系方式」卡（前端做成可点）。</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">评分 / 营业时间</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="input-label">平台星级（0-5）</label>
                                <input type="number" step="0.1" min="0" max="5" name="rating" class="form-control" placeholder="5.0" value="{{ $val('rating', 5.0) }}">
                            </div>
                            <div class="col-md-4">
                                <label class="input-label">Google 评分（0-5）</label>
                                <input type="number" step="0.1" min="0" max="5" name="google_rating" class="form-control" placeholder="4.9" value="{{ $val('google_rating') }}">
                            </div>
                            <div class="col-md-4">
                                <label class="input-label">Google 评分链接</label>
                                <input type="text" name="google_rating_url" class="form-control" maxlength="255" placeholder="https://..." value="{{ $val('google_rating_url') }}">
                            </div>
                            <div class="col-md-12">
                                <label class="input-label">营业星期</label>
                                <div>
                                    @foreach($dayNames as $k => $dn)
                                        <label class="form-check form-check-inline">
                                            <input type="checkbox" class="form-check-input" name="open_days[]" value="{{ $k }}" {{ in_array((int)$k, array_map('intval',(array)$openDays), true) ? 'checked' : '' }}>
                                            <span class="form-check-label">{{ $dn }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="input-label">每日开始</label>
                                <input type="time" name="open_time" class="form-control" value="{{ $val('open_time') }}">
                            </div>
                            <div class="col-md-4">
                                <label class="input-label">每日结束</label>
                                <input type="time" name="close_time" class="form-control" value="{{ $val('close_time') }}">
                            </div>
                            <div class="col-md-4">
                                <label class="input-label">营业时间补充</label>
                                <input type="text" name="hours_note" class="form-control" maxlength="120" placeholder="如 周末休息 / 节假日另行通知" value="{{ $val('hours_note') }}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">地址（导航用）</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="input-label">区域（列表筛选用）</label>
                                <input type="text" name="area" class="form-control" maxlength="60" placeholder="如 市中心 / 北区" value="{{ $val('area') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="input-label">详细地址</label>
                                <input type="text" name="address" class="form-control" maxlength="255" placeholder="街道门牌" value="{{ $val('address') }}">
                            </div>
                            <div class="col-md-12">
                                <button type="button" id="nz-geocode-btn" class="btn btn-sm btn--primary">地址 → 坐标（自动定位）</button>
                                <span id="nz-geocode-status" class="text-muted ml-2" style="font-size:12px;"></span>
                                <small class="text-muted d-block mt-1">点一下用地址自动填经纬度；解析不到时可在下方手填。</small>
                            </div>
                            <div class="col-md-6">
                                <label class="input-label">纬度 latitude</label>
                                <input type="text" name="latitude" class="form-control" placeholder="40.1872" value="{{ $val('latitude') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="input-label">经度 longitude</label>
                                <input type="text" name="longitude" class="form-control" placeholder="44.5152" value="{{ $val('longitude') }}">
                                <small class="text-muted">填了经纬度，顾客商家页才显地图缩略图 + 点「导航」跳地图。</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">服务项</h5></div>
                    <div class="card-body">
                        <div id="nz-services-rows">
                            @foreach($svcRows as $i => $s)
                                @php $sAttrs = is_array($s['attrs'] ?? null) ? $s['attrs'] : []; $sAmen = is_array($sAttrs['amenities'] ?? null) ? $sAttrs['amenities'] : []; $sImg = trim((string)($s['image'] ?? '')); @endphp
                                <div class="nz-service-row border rounded p-2 mb-2">
                                    <div class="row g-2 align-items-center">
                                        <div class="col-md-4"><input type="text" name="services[{{ $i }}][title]" class="form-control form-control-sm" placeholder="房型名(如 一室一厅·精装)" value="{{ $s['title'] ?? '' }}"></div>
                                        <div class="col-md-4"><input type="text" name="services[{{ $i }}][desc]" class="form-control form-control-sm" placeholder="描述(可空)" value="{{ $s['desc'] ?? '' }}"></div>
                                        <div class="col-md-3"><input type="text" name="services[{{ $i }}][price_text]" class="form-control form-control-sm" placeholder="价格(如 350000֏ /月 起)" value="{{ $s['price_text'] ?? '' }}"></div>
                                        <div class="col-md-1 text-center"><button type="button" class="btn btn-sm btn-outline-danger nz-del-row" title="删除">&times;</button></div>
                                    </div>
                                    <div class="row g-2 mt-1 align-items-start" style="background:#faf9f7;border-radius:6px;padding:6px 4px">
                                        <div class="col-12"><small class="text-muted">房型卡（租房民宿选填 · 其他类目留空即为现状文字行）</small></div>
                                        <div class="col-md-3">
                                            <label class="text-muted" style="font-size:11px">房型图</label>
                                            @if($sImg !== '')
                                                <div class="mb-1"><img src="{{ \App\CentralLogics\Helpers::get_full_url('local-life-merchant', basename($sImg), 'public') }}" style="height:40px;border-radius:6px"></div>
                                                <input type="hidden" name="services[{{ $i }}][existing_image]" value="{{ $sImg }}">
                                            @endif
                                            <input type="file" name="services[{{ $i }}][image]" accept="image/*" class="form-control form-control-sm">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="text-muted" style="font-size:11px">户型</label>
                                            <select name="services[{{ $i }}][attrs][layout]" class="form-control form-control-sm">
                                                <option value="">—</option>
                                                @foreach($svcLayouts as $lk => $lv)
                                                    <option value="{{ $lk }}" {{ ($sAttrs['layout'] ?? '') === $lk ? 'selected' : '' }}>{{ $lv }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="text-muted" style="font-size:11px">面积</label>
                                            <input type="text" name="services[{{ $i }}][attrs][area_label]" class="form-control form-control-sm" placeholder="如 35㎡" value="{{ $sAttrs['area_label'] ?? '' }}">
                                        </div>
                                        <div class="col-md-5">
                                            <label class="text-muted" style="font-size:11px">设施</label>
                                            <div class="d-flex flex-wrap" style="gap:2px 10px">
                                                @foreach($svcAmenities as $ak => $av)
                                                    <label style="font-size:11px;margin:0"><input type="checkbox" name="services[{{ $i }}][attrs][amenities][]" value="{{ $ak }}" {{ in_array($ak, $sAmen, true) ? 'checked' : '' }}> {{ $av }}</label>
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <button type="button" class="btn btn-sm btn--primary mt-1" id="nz-add-service">+ 添加服务项/房型</button>
                        <small class="text-muted d-block mt-1">标题必填。房型卡（图/户型/面积/设施）仅租房民宿类目需要，其他类目留空即渲染为现状文字价目行。价格只是展示文字，平台不收款。</small>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">联系方式（前端做成可点）</h5></div>
                    <div class="card-body">
                        <div id="nz-contacts-rows">
                            @forelse($existingContacts as $ct)
                                <div class="nz-contact-row row g-2 mb-2 align-items-center">
                                    <div class="col-md-3">
                                        <select name="contacts[{{ $loop->index }}][method]" class="form-control form-control-sm">
                                            @foreach($contactMethods as $mk => $mlabel)
                                                <option value="{{ $mk }}" {{ ($ct['method'] ?? '') === $mk ? 'selected' : '' }}>{{ $mlabel }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-5"><input type="text" name="contacts[{{ $loop->index }}][value]" class="form-control form-control-sm" placeholder="号码/用户名/微信号" value="{{ $ct['value'] ?? '' }}"></div>
                                    <div class="col-md-3"><input type="text" name="contacts[{{ $loop->index }}][label]" class="form-control form-control-sm" placeholder="备注(可空)" value="{{ $ct['label'] ?? '' }}"></div>
                                    <div class="col-md-1 text-center"><button type="button" class="btn btn-sm btn-outline-danger nz-del-row" title="删除">&times;</button></div>
                                </div>
                            @empty
                                <div class="nz-contact-row row g-2 mb-2 align-items-center">
                                    <div class="col-md-3">
                                        <select name="contacts[0][method]" class="form-control form-control-sm">
                                            @foreach($contactMethods as $mk => $mlabel)
                                                <option value="{{ $mk }}">{{ $mlabel }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-5"><input type="text" name="contacts[0][value]" class="form-control form-control-sm" placeholder="号码/用户名/微信号" value=""></div>
                                    <div class="col-md-3"><input type="text" name="contacts[0][label]" class="form-control form-control-sm" placeholder="备注(可空)" value=""></div>
                                    <div class="col-md-1 text-center"><button type="button" class="btn btn-sm btn-outline-danger nz-del-row" title="删除">&times;</button></div>
                                </div>
                            @endforelse
                        </div>
                        <button type="button" class="btn btn-sm btn--primary mt-1" id="nz-add-contact">+ 添加联系方式</button>
                        <small class="text-muted d-block mt-1">电话=可拨号 / WhatsApp=填带国码号(如 +374 43 329475) / Telegram=填用户名(如 @name) / 微信=填微信号(二维码另在右侧「微信二维码」上传)。平台只展示，不代收款。</small>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-4 mb-3">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">图片</h5></div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="input-label">Logo / 头像</label>
                            @if($isEdit && $merchant->logo)
                                <div class="mb-1"><img src="{{ \App\CentralLogics\Helpers::get_full_url('local-life-merchant', $merchant->logo, 'public') }}" style="height:54px;border-radius:10px;"></div>
                            @endif
                            <input type="file" name="logo" class="form-control" accept="image/*">
                        </div>
                        <div class="form-group">
                            <label class="input-label">微信二维码</label>
                            @if($isEdit && $merchant->wechat_qr)
                                <div class="mb-1"><img src="{{ \App\CentralLogics\Helpers::get_full_url('local-life-merchant', $merchant->wechat_qr, 'public') }}" style="height:80px;"></div>
                            @endif
                            <input type="file" name="wechat_qr" class="form-control" accept="image/*">
                            <small class="text-muted">配合上方「微信」联系方式：顾客点微信条目=复制微信号 + 弹此二维码。</small>
                        </div>
                        <div class="form-group">
                            <label class="input-label">相册（可多选）</label>
                            @if($isEdit && is_array($merchant->images) && count($merchant->images))
                                <small class="text-muted d-block mb-1">选一张作「门面图」（顾客端顶部 + 分享卡背景同用）；不选＝系统自动挑第一张横图。</small>
                                <div class="mb-1 d-flex flex-wrap" style="gap:10px;">
                                    <label class="text-center mb-0" style="cursor:pointer;">
                                        <span style="height:46px;display:flex;align-items:center;justify-content:center;padding:0 10px;border:1px dashed #c9cdd2;border-radius:8px;color:#8a8f98;font-size:12px;">自动</span>
                                        <input type="radio" name="cover_image" value="" {{ !$merchant->cover_image ? 'checked' : '' }} class="mt-1">
                                    </label>
                                    @foreach($merchant->images as $im)
                                        <label class="text-center mb-0" style="cursor:pointer;">
                                            <img src="{{ \App\CentralLogics\Helpers::get_full_url('local-life-merchant', $im, 'public') }}" style="height:46px;border-radius:8px;display:block;{{ $merchant->cover_image === $im ? 'outline:3px solid #C4193E;' : '' }}">
                                            <input type="radio" name="cover_image" value="{{ $im }}" {{ $merchant->cover_image === $im ? 'checked' : '' }} class="mt-1">
                                        </label>
                                    @endforeach
                                </div>
                                <small class="text-muted d-block mb-1">重新上传相册会替换全部图并把门面图重置为「自动」。</small>
                            @endif
                            <input type="file" name="images[]" class="form-control" accept="image/*" multiple>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">优惠 / 上线</h5></div>
                    <div class="card-body">
                        <div class="form-check mb-2">
                            <input type="checkbox" name="has_offer" value="1" class="form-check-input" id="has_offer" {{ $val('has_offer') ? 'checked' : '' }}>
                            <label class="form-check-label" for="has_offer">有到店优惠（列表显示「到店优惠」标签，仅展示不核销）</label>
                        </div>
                        <div class="form-group">
                            <label class="input-label">优惠文字</label>
                            <input type="text" name="offer_text" class="form-control" maxlength="120" placeholder="如 到店出示立减 10%" value="{{ $val('offer_text') }}">
                        </div>
                        <div class="form-group">
                            <label class="input-label">排序</label>
                            <input type="number" name="sort_order" class="form-control" min="0" max="9999" value="{{ $val('sort_order', 0) }}">
                            <small class="text-muted">数字越小越靠前。</small>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="status" value="1" class="form-check-input" id="status" {{ $val('status', $isEdit ? '' : '1') ? 'checked' : '' }}>
                            <label class="form-check-label" for="status"><strong>上线</strong> —— 勾选后顾客可在商家列表看到。</label>
                        </div>
                    </div>
                </div>

                <div class="btn--container justify-content-end">
                    <a href="{{ route('admin.local-life.merchants.list') }}" class="btn btn--reset">返回</a>
                    <button type="submit" class="btn btn--primary">{{ $isEdit ? '保存修改' : '创建' }}</button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    var geocodeUrl = "{{ route('admin.local-life.merchants.geocode') }}";
    function csrf() { var t = document.querySelector('input[name=_token]'); return t ? t.value : ''; }

    // ---- 地址→坐标 geocode ----
    var geoBtn = document.getElementById('nz-geocode-btn');
    if (geoBtn) {
        geoBtn.addEventListener('click', function () {
            var addrEl = document.querySelector('input[name=address]');
            var statusEl = document.getElementById('nz-geocode-status');
            var addr = addrEl ? addrEl.value.trim() : '';
            if (!addr) { statusEl.className = 'text-danger ml-2'; statusEl.style.fontSize='12px'; statusEl.textContent = '请先填写详细地址'; return; }
            statusEl.className = 'text-muted ml-2'; statusEl.style.fontSize='12px'; statusEl.textContent = '解析中…';
            fetch(geocodeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
                body: JSON.stringify({ address: addr })
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.ok) {
                    var latEl = document.querySelector('input[name=latitude]');
                    var lngEl = document.querySelector('input[name=longitude]');
                    if (latEl) latEl.value = d.lat;
                    if (lngEl) lngEl.value = d.lng;
                    statusEl.className = 'text-success ml-2'; statusEl.style.fontSize='12px';
                    statusEl.textContent = '已定位：' + d.lat + ', ' + d.lng + (d.formatted ? ('（' + d.formatted + '）') : '');
                } else {
                    statusEl.className = 'text-danger ml-2'; statusEl.style.fontSize='12px';
                    statusEl.textContent = (d && d.message) ? d.message : '解析失败，请手填经纬度';
                }
            }).catch(function () {
                statusEl.className = 'text-danger ml-2'; statusEl.style.fontSize='12px';
                statusEl.textContent = '请求失败，请手填经纬度';
            });
        });
    }

    // ---- 动态行：删除（事件委托）----
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.nz-del-row') : null;
        if (!btn) return;
        var row = btn.closest('.nz-service-row') || btn.closest('.nz-contact-row');
        if (row) row.parentNode.removeChild(row);
    });

    // ---- 服务项：添加 ----
    var svcIdx = 1000; // 新增行用高位索引，避免与服务端渲染索引冲突（PHP 按数组收，键不需连续）
    var addSvc = document.getElementById('nz-add-service');
    var svcRows = document.getElementById('nz-services-rows');
    if (addSvc && svcRows) {
        var svcLayouts = {'studio':'开间','1b1l':'一室一厅','2b1l':'两室一厅','3b1l':'三室一厅','4plus':'四室及以上'};
        var svcAmen = {'furniture':'家具','washer':'洗衣机','fridge':'冰箱','ac':'空调','heating':'暖气','elevator':'电梯','parking':'停车位','balcony':'阳台','private_bath':'独立卫浴','kitchen':'可做饭'};
        addSvc.addEventListener('click', function () {
            var i = svcIdx++;
            var layoutOpts = '<option value="">—</option>';
            Object.keys(svcLayouts).forEach(function (k) { layoutOpts += '<option value="' + k + '">' + svcLayouts[k] + '</option>'; });
            var amenBoxes = '';
            Object.keys(svcAmen).forEach(function (k) { amenBoxes += '<label style="font-size:11px;margin:0"><input type="checkbox" name="services[' + i + '][attrs][amenities][]" value="' + k + '"> ' + svcAmen[k] + '</label>'; });
            var div = document.createElement('div');
            div.className = 'nz-service-row border rounded p-2 mb-2';
            div.innerHTML =
                '<div class="row g-2 align-items-center">' +
                '<div class="col-md-4"><input type="text" name="services[' + i + '][title]" class="form-control form-control-sm" placeholder="房型名(如 一室一厅·精装)"></div>' +
                '<div class="col-md-4"><input type="text" name="services[' + i + '][desc]" class="form-control form-control-sm" placeholder="描述(可空)"></div>' +
                '<div class="col-md-3"><input type="text" name="services[' + i + '][price_text]" class="form-control form-control-sm" placeholder="价格(如 350000֏ /月 起)"></div>' +
                '<div class="col-md-1 text-center"><button type="button" class="btn btn-sm btn-outline-danger nz-del-row" title="删除">&times;</button></div>' +
                '</div>' +
                '<div class="row g-2 mt-1 align-items-start" style="background:#faf9f7;border-radius:6px;padding:6px 4px">' +
                '<div class="col-12"><small class="text-muted">房型卡（租房民宿选填 · 其他类目留空即为现状文字行）</small></div>' +
                '<div class="col-md-3"><label class="text-muted" style="font-size:11px">房型图</label><input type="file" name="services[' + i + '][image]" accept="image/*" class="form-control form-control-sm"></div>' +
                '<div class="col-md-2"><label class="text-muted" style="font-size:11px">户型</label><select name="services[' + i + '][attrs][layout]" class="form-control form-control-sm">' + layoutOpts + '</select></div>' +
                '<div class="col-md-2"><label class="text-muted" style="font-size:11px">面积</label><input type="text" name="services[' + i + '][attrs][area_label]" class="form-control form-control-sm" placeholder="如 35㎡"></div>' +
                '<div class="col-md-5"><label class="text-muted" style="font-size:11px">设施</label><div class="d-flex flex-wrap" style="gap:2px 10px">' + amenBoxes + '</div></div>' +
                '</div>';
            svcRows.appendChild(div);
        });
    }

    // ---- 联系方式：添加 ----
    var ctIdx = 1000;
    var addCt = document.getElementById('nz-add-contact');
    var ctRows = document.getElementById('nz-contacts-rows');
    var methodOptions = '<option value="phone">电话</option><option value="whatsapp">WhatsApp</option><option value="telegram">Telegram</option><option value="wechat">微信</option>';
    if (addCt && ctRows) {
        addCt.addEventListener('click', function () {
            var i = ctIdx++;
            var div = document.createElement('div');
            div.className = 'nz-contact-row row g-2 mb-2 align-items-center';
            div.innerHTML =
                '<div class="col-md-3"><select name="contacts[' + i + '][method]" class="form-control form-control-sm">' + methodOptions + '</select></div>' +
                '<div class="col-md-5"><input type="text" name="contacts[' + i + '][value]" class="form-control form-control-sm" placeholder="号码/用户名/微信号"></div>' +
                '<div class="col-md-3"><input type="text" name="contacts[' + i + '][label]" class="form-control form-control-sm" placeholder="备注(可空)"></div>' +
                '<div class="col-md-1 text-center"><button type="button" class="btn btn-sm btn-outline-danger nz-del-row" title="删除">&times;</button></div>';
            ctRows.appendChild(div);
        });
    }
})();
</script>
@endsection
