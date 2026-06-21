@extends('layouts.admin.app')
@section('title', isset($category) && $category ? '编辑本地生活类目' : '新建本地生活类目')

@section('content')
@php
    $isEdit = isset($category) && $category;
    $action = $isEdit ? route('admin.local-life.categories.update', $category->id) : route('admin.local-life.categories.store');
    $val = fn($field, $default = '') => old($field, $isEdit ? $category->$field : $default);
@endphp

<div class="content container-fluid">
    <div class="page-header mb-1">
        <h1 class="page-header-title fs-24">{{ $isEdit ? '编辑类目' : '新建类目' }}</h1>
    </div>

    <div class="row gx-2 gx-lg-3">
        <div class="col-12 col-lg-9 mb-3">
            <div class="card">
                <div class="card-body">
                    <form action="{{ $action }}" method="post">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="input-label">类目名 <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" maxlength="60" required
                                        placeholder="如 美容美发" value="{{ $val('name') }}">
                                    <small class="text-muted">显示在金刚区格子下方，也是帖子的分类标签。建议 2-5 个字。</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="input-label">归属频道（前端粗筛） <span class="text-danger">*</span></label>
                                    <select name="tab" class="form-control" required>
                                        @foreach($tabs as $t)
                                            <option value="{{ $t }}" {{ $val('tab', '服务') === $t ? 'selected' : '' }}>{{ $t }}</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">服务型类目（按摩/美容/包车等）一般选「服务」；租房选「租房」、二手选「二手」。</small>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="input-label">图标 Emoji</label>
                                    <input type="text" name="emoji" class="form-control" maxlength="16" placeholder="💇" value="{{ $val('emoji') }}">
                                    <small class="text-muted">直接粘贴一个 emoji 作为金刚区图标。</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="input-label">图标底色</label>
                                    <input type="text" name="color" class="form-control" maxlength="40" placeholder="#FCE9F0" value="{{ $val('color') }}">
                                    <small class="text-muted">浅色十六进制色，如 #FCE9F0。留空用默认浅灰。</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="input-label">排序</label>
                                    <input type="number" name="sort_order" class="form-control" min="0" max="9999" placeholder="0" value="{{ $val('sort_order', 0) }}">
                                    <small class="text-muted">数字越小越靠前。</small>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <label class="input-label">类目性质 <span class="text-danger">*</span></label>
                                <div>
                                    <label class="form-check form-check-inline">
                                        <input type="radio" class="form-check-input" name="kind" value="ugc" {{ $val('kind','ugc')==='ugc'?'checked':'' }}>
                                        <span class="form-check-label">个人发帖（租房/二手等，留在信息流）</span>
                                    </label>
                                    <label class="form-check form-check-inline">
                                        <input type="radio" class="form-check-input" name="kind" value="merchant" {{ $val('kind')==='merchant'?'checked':'' }}>
                                        <span class="form-check-label">商家服务（移民/签证/美容等，点击进入商家列表）</span>
                                    </label>
                                </div>
                                <small class="text-muted">「个人发帖」类目的帖子显示在信息流；「商家服务」类目点击跳转到商家列表页。</small>
                            </div>

                            <div class="col-md-12">
                                <div class="form-group mb-2">
                                    <label class="input-label">合规等级 <span class="text-danger">*</span></label>
                                    @php $lvl = (string) $val('compliance_level', '0'); @endphp
                                    <select name="compliance_level" class="form-control">
                                        <option value="0" {{ $lvl === '0' ? 'selected' : '' }}>0 · 可上线（普通类目，如租房 / 二手 / 包车 / 美发）</option>
                                        <option value="1" {{ $lvl === '1' ? 'selected' : '' }}>1 · 需牌照 / 人工审（移民 · 签证 · 按摩 · 医疗等，可做信息墙但每个商家逐个人工核）</option>
                                        <option value="2" {{ $lvl === '2' ? 'selected' : '' }}>2 · 硬禁（换汇 · 加密买卖 · 医美注射 · 性服务 · 赌博 · 制裁规避等，坚决不能上线）</option>
                                    </select>
                                    <small class="text-muted">
                                        <strong class="text-warning">等级 1</strong>：发帖标红、加严违禁词扫描、商家逐个人工审。
                                        <strong class="text-danger">等级 2（硬禁）</strong>：即使勾「启用」也<strong>强制不上线、前端不渲染</strong>，用于法律禁止 / 需牌照而平台无法核验的业务。
                                    </small>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" name="status" value="1" class="form-check-input" id="status" {{ $val('status', $isEdit ? '' : '1') ? 'checked' : '' }}>
                                    <label class="form-check-label" for="status">
                                        <strong>启用</strong> —— 启用后才显示在 H5 前端金刚区。停用只是隐藏，不删数据、不影响已发布帖子。（合规等级=硬禁时此项被忽略，强制停用）
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="btn--container justify-content-end mt-4">
                            <a href="{{ route('admin.local-life.categories.list') }}" class="btn btn--reset">返回</a>
                            <button type="submit" class="btn btn--primary">{{ $isEdit ? '保存修改' : '创建' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-3 mb-3">
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-2">预览</h6>
                    <div style="text-align:center;">
                        <span id="prev-icon" style="font-size:28px; display:inline-flex; align-items:center; justify-content:center; width:56px; height:56px; border-radius:16px; background:{{ $val('color') ?: '#EEEFF4' }};">{{ $val('emoji', '🏷️') }}</span>
                        <div id="prev-name" style="margin-top:8px; font-size:13px; font-weight:600; color:#33373E;">{{ $val('name', '类目名') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var nameI = document.querySelector('input[name=name]');
    var emojiI = document.querySelector('input[name=emoji]');
    var colorI = document.querySelector('input[name=color]');
    var icon = document.getElementById('prev-icon');
    var nm = document.getElementById('prev-name');
    function upd() {
        if (icon) { icon.textContent = (emojiI.value || '🏷️'); icon.style.background = (colorI.value || '#EEEFF4'); }
        if (nm) { nm.textContent = (nameI.value || '类目名'); }
    }
    [nameI, emojiI, colorI].forEach(function (el) { if (el) el.addEventListener('input', upd); });
})();
</script>
@endsection
