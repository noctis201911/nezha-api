@extends('layouts.admin.app')
@section('title', isset($guide) && $guide ? '编辑生活攻略' : '新建生活攻略')

@section('content')
@php
    $isEdit = isset($guide) && $guide;
    $action = $isEdit ? route('admin.guides.update', $guide->id) : route('admin.guides.store');
    $val = fn($field, $default = '') => old($field, $isEdit ? $guide->$field : $default);
@endphp

<div class="content container-fluid">
    <div class="page-header mb-1">
        <h1 class="page-header-title fs-24">{{ $isEdit ? '编辑攻略' : '新建攻略' }}</h1>
        <small class="text-muted">攻略是纯信息展示（PGC）。必填「信息截至」年月；涉汇率/价格/政策段落请文内标注核实年月。过期宁下架。</small>
    </div>

    <form action="{{ $action }}" method="post" enctype="multipart/form-data">
        @csrf
        <div class="row gx-2 gx-lg-3">
            <div class="col-12 col-lg-8 mb-3">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">基本信息</h5></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="input-label">标题 <span class="text-danger">*</span></label>
                                <input type="text" id="nz-title" name="title" class="form-control" maxlength="200" required placeholder="如 初到埃里温 72 小时：电话卡、打车、换汇与超市" value="{{ $val('title') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="input-label">slug（网址） <span class="text-danger">*</span></label>
                                <input type="text" id="nz-slug" name="slug" class="form-control" maxlength="191" required placeholder="如 first-72-hours" value="{{ $val('slug') }}">
                                <small class="text-muted">只能小写字母、数字、连字符。攻略网址：/local-life/guides/<b>slug</b>。上线后勿改（会断已分享链接）。</small>
                            </div>
                            <div class="col-md-6">
                                <label class="input-label">信息截至（年月） <span class="text-danger">*</span></label>
                                <input type="month" name="info_as_of" class="form-control" required value="{{ $val('info_as_of') }}">
                                <small class="text-muted">时效锚点。超 180 天列表标「过期」、详情页自动加提醒条。</small>
                            </div>
                            <div class="col-md-12">
                                <label class="input-label">一句话摘要</label>
                                <textarea name="summary" class="form-control" rows="2" maxlength="300" placeholder="列表卡显示的一句话概述（2 行内）">{{ $val('summary') }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">正文（Markdown）</h5></div>
                    <div class="card-body">
                        <textarea name="body_md" class="form-control" rows="20" style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;font-size:13px;line-height:1.6;" placeholder="# 段落标题&#10;&#10;正文段落……&#10;&#10;- 列表项">{{ $val('body_md') }}</textarea>
                        <small class="text-muted d-block mt-2">
                            支持标准 Markdown（<code>## 小标题</code> / <code>- 列表</code> / <code>**加粗**</code> / <code>![图注](图片URL)</code> / <code>[链接](URL)</code>）。裸 HTML 会被自动剥除。<br>
                            <b>内嵌店卡</b>：单独一行写 <code>@{{restaurant:12}}</code>（外卖餐厅，CTA「去点餐」）或 <code>@{{merchant:5}}</code>（本地生活商家，CTA「去看看」）→ 前端渲染成可点店卡。下架/不存在的店会整卡跳过。
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-4 mb-3">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">封面</h5></div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="input-label">封面图</label>
                            @if($isEdit && $guide->cover_url)
                                <div class="mb-1"><img src="{{ \App\CentralLogics\Helpers::get_full_url('guide', $guide->cover_url, 'public') }}" style="height:70px;border-radius:10px;"></div>
                            @endif
                            <input type="file" name="cover" class="form-control" accept="image/*">
                            <small class="text-muted">可空——不传则前端/分享卡走米金渐变 +「攻略」两字兜底。</small>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">时效 / 上架</h5></div>
                    <div class="card-body">
                        <div class="form-check mb-3">
                            <input type="checkbox" name="is_sensitive_topic" value="1" class="form-check-input" id="is_sensitive_topic" {{ $val('is_sensitive_topic') ? 'checked' : '' }}>
                            <label class="form-check-label" for="is_sensitive_topic">
                                <strong>level1 敏感话题</strong>（签证/居留/移民）
                                <small class="text-muted d-block">勾选后文末显示专用免责（不构成法律或移民建议）。仅平台起草，发布前过合规口径。</small>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="input-label">排序</label>
                            <input type="number" name="sort" class="form-control" min="0" max="9999" value="{{ $val('sort', 0) }}">
                            <small class="text-muted">数字越小越靠前。<b>排最前的一篇</b>会在列表显示「新来必读」标签。</small>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="status" value="1" class="form-check-input" id="status" {{ $val('status', $isEdit ? '' : '1') ? 'checked' : '' }}>
                            <label class="form-check-label" for="status"><strong>上架</strong> —— 勾选后（且总开关已开）顾客可见。</label>
                        </div>
                    </div>
                </div>

                <div class="btn--container justify-content-end">
                    <a href="{{ route('admin.guides.list') }}" class="btn btn--reset">返回</a>
                    <button type="submit" class="btn btn--primary">{{ $isEdit ? '保存修改' : '创建' }}</button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    // 新建时：标题输入自动生成 slug 建议（仅当 slug 尚为空且非编辑态）；已填/编辑不覆盖
    var isEdit = {{ $isEdit ? 'true' : 'false' }};
    var titleEl = document.getElementById('nz-title');
    var slugEl = document.getElementById('nz-slug');
    if (!isEdit && titleEl && slugEl) {
        titleEl.addEventListener('input', function () {
            if (slugEl.dataset.touched) return;
            var s = (titleEl.value || '').toLowerCase()
                .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60);
            slugEl.value = s;
        });
        slugEl.addEventListener('input', function () { slugEl.dataset.touched = '1'; });
    }
})();
</script>
@endsection
