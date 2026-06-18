@extends('layouts.admin.app')
@section('title', isset($merchant) && $merchant ? '编辑本地生活商家' : '新建本地生活商家')

@section('content')
@php
    $isEdit = isset($merchant) && $merchant;
    $action = $isEdit ? route('admin.local-life.merchants.update', $merchant->id) : route('admin.local-life.merchants.store');
    $val = fn($field, $default = '') => old($field, $isEdit ? $merchant->$field : $default);
    $openDays = old('open_days', $isEdit && is_array($merchant->open_days) ? $merchant->open_days : []);
    $dayNames = ['0'=>'周日','1'=>'周一','2'=>'周二','3'=>'周三','4'=>'周四','5'=>'周五','6'=>'周六'];
    $servicesText = '';
    if ($isEdit && is_array($merchant->services)) {
        foreach ($merchant->services as $s) {
            $servicesText .= trim(($s['title'] ?? '').' | '.($s['desc'] ?? '').' | '.($s['price_text'] ?? ''), " |")."\n";
        }
    }
    $servicesText = old('services', $servicesText);
@endphp

<div class="content container-fluid">
    <div class="page-header mb-1">
        <h1 class="page-header-title fs-24">{{ $isEdit ? '编辑商家' : '新建商家' }}</h1>
        <small class="text-muted">商家页是纯信息展示（评分/营业时间/地址/介绍/服务），平台不碰钱、不接预订下单。</small>
    </div>

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
                            <div class="col-md-6">
                                <label class="input-label">纬度 latitude</label>
                                <input type="text" name="latitude" class="form-control" placeholder="40.1872" value="{{ $val('latitude') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="input-label">经度 longitude</label>
                                <input type="text" name="longitude" class="form-control" placeholder="44.5152" value="{{ $val('longitude') }}">
                                <small class="text-muted">填了经纬度，顾客点「导航」才会跳地图。</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">服务项</h5></div>
                    <div class="card-body">
                        <label class="input-label">每行一项，格式：标题 | 描述 | 价格文字</label>
                        <textarea name="services" class="form-control" rows="4" placeholder="工签申请 | 全程一对一办理 | 面议&#10;学签续签 | 材料预审+递交 | 面议">{{ $servicesText }}</textarea>
                        <small class="text-muted">描述、价格可留空。价格只是展示文字，平台不收款。</small>
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
                        </div>
                        <div class="form-group">
                            <label class="input-label">相册（可多选）</label>
                            @if($isEdit && is_array($merchant->images) && count($merchant->images))
                                <div class="mb-1">
                                    @foreach($merchant->images as $im)
                                        <img src="{{ \App\CentralLogics\Helpers::get_full_url('local-life-merchant', $im, 'public') }}" style="height:46px;border-radius:8px;margin:2px;">
                                    @endforeach
                                </div>
                                <small class="text-muted d-block mb-1">重新上传会替换全部相册图。</small>
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
@endsection
