@extends('layouts.admin.app')
@section('title', isset($post) && $post ? '编辑本地生活帖子' : '新建本地生活帖子')

@section('content')
@php
    $isEdit = isset($post) && $post;
    $action = $isEdit ? route('admin.local-life.update', $post->id) : route('admin.local-life.store');
    // 类目改读后台「本地生活类目」表（启用中的）；表空时回退旧常量，保证表单永远有选项
    $categories = \App\Models\LocalLifeCategory::where('status', true)->orderBy('sort_order')->orderBy('id')->pluck('name')->toArray();
    if (empty($categories)) {
        $categories = ['租房合租', '找工作', '二手闲置', '养车出行', '装修维修', '免费·赠送'];
    }
    // 编辑旧帖时其分类可能已不在启用列表，补进去免得下拉选不中
    if ($isEdit && $post->category && !in_array($post->category, $categories, true)) {
        $categories[] = $post->category;
    }
    $tabs = ['推荐', '租房', '招聘', '二手', '免费', '服务'];
    $val = fn($field, $default = '') => old($field, $isEdit ? $post->$field : $default);
@endphp

<div class="content container-fluid">
    <div class="page-header mb-1">
        <h1 class="page-header-title fs-24">{{ $isEdit ? '编辑帖子' : '新建帖子' }}</h1>
    </div>

    <div class="row gx-2 gx-lg-3">
        <div class="col-12 mb-3">
            <div class="card">
                <div class="card-body">
                    <form action="{{ $action }}" method="post">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="input-label">标题 <span class="text-danger">*</span></label>
                                    <input type="text" name="title" class="form-control" maxlength="200" required
                                        placeholder="例：中心区一居室整租 家电齐全" value="{{ $val('title') }}">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="input-label">分类 <span class="text-danger">*</span></label>
                                    <select name="category" class="form-control" required>
                                        <option value="" disabled {{ $val('category') === '' ? 'selected' : '' }}>--- 请选择 ---</option>
                                        @foreach($categories as $c)
                                            <option value="{{ $c }}" {{ $val('category') === $c ? 'selected' : '' }}>{{ $c }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="input-label">Tab（前端筛选栏）<span class="text-danger">*</span></label>
                                    <select name="tab" class="form-control" required>
                                        @foreach($tabs as $t)
                                            <option value="{{ $t }}" {{ $val('tab', '推荐') === $t ? 'selected' : '' }}>{{ $t }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="input-label">描述</label>
                                    <textarea name="description" class="form-control" rows="3" placeholder="帖子正文描述">{{ $val('description') }}</textarea>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="input-label">封面 Emoji</label>
                                    <input type="text" name="cover_emoji" class="form-control" maxlength="10" placeholder="🏠" value="{{ $val('cover_emoji') }}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="input-label">封面颜色 / CSS 类</label>
                                    <input type="text" name="cover_color" class="form-control" maxlength="40" placeholder="#F3E9DD 或 c-rent" value="{{ $val('cover_color') }}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="input-label">价格（AMD ֏）</label>
                                    <input type="number" name="price_amd" class="form-control" min="0" placeholder="180000" value="{{ $val('price_amd') }}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="input-label">价格后缀</label>
                                    <input type="text" name="price_suffix" class="form-control" maxlength="20" placeholder="/月、/月起、面议" value="{{ $val('price_suffix') }}">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="input-label">面积 / 规格标注</label>
                                    <input type="text" name="area_label" class="form-control" maxlength="80" placeholder="45㎡·中心区" value="{{ $val('area_label') }}">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="input-label">地点</label>
                                    <input type="text" name="location_label" class="form-control" maxlength="60" placeholder="Kentron" value="{{ $val('location_label') }}">
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="input-label">想要数（运营手动设）</label>
                                    <input type="number" name="want_count" class="form-control" min="0" value="{{ $val('want_count', 0) }}">
                                </div>
                            </div>
                            <div class="col-md-3 d-flex align-items-center">
                                <div class="form-group mb-0">
                                    <div class="form-check">
                                        <input type="checkbox" name="is_free" value="1" class="form-check-input" id="is_free" {{ $val('is_free') ? 'checked' : '' }}>
                                        <label class="form-check-label" for="is_free">免费（价格显示"免费"）</label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 d-flex align-items-center">
                                <div class="form-group mb-0">
                                    <div class="form-check">
                                        <input type="checkbox" name="is_urgent" value="1" class="form-check-input" id="is_urgent" {{ $val('is_urgent') ? 'checked' : '' }}>
                                        <label class="form-check-label" for="is_urgent">急招 / 急（显示"急"标签）</label>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="input-label">联系方式 <span class="badge badge-soft-warning">仅登录用户可见（PII）</span></label>
                                    <textarea name="contact_info" class="form-control" rows="2" placeholder="电话 / 微信 / WhatsApp 等，仅登录顾客可见">{{ $val('contact_info') }}</textarea>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="input-label">过期时间（留空表示长期有效）</label>
                                    <input type="datetime-local" name="expires_at" class="form-control"
                                        value="{{ $isEdit && $post->expires_at ? \Carbon\Carbon::parse($post->expires_at)->format('Y-m-d\TH:i') : old('expires_at') }}">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="input-label">状态 <span class="text-danger">*</span></label>
                                    <select name="status" class="form-control" required>
                                        @php $statusVal = (int) $val('status', 0); @endphp
                                        <option value="0" {{ $statusVal === 0 ? 'selected' : '' }}>草稿（不公开）</option>
                                        <option value="1" {{ $statusVal === 1 ? 'selected' : '' }}>已发布</option>
                                        <option value="2" {{ $statusVal === 2 ? 'selected' : '' }}>已下线</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="btn--container justify-content-end mt-4">
                            <a href="{{ route('admin.local-life.list') }}" class="btn btn--reset">返回</a>
                            <button type="submit" class="btn btn--primary">{{ $isEdit ? '保存修改' : '创建' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
