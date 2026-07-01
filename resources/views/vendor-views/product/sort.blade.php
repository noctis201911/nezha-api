@extends('layouts.vendor.app')

@section('title', '菜品排序')

@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        .nz-sort-wrap { max-width: 760px; }
        .nz-sort-list { display: none; }
        .nz-sort-list.active { display: block; }
        .nz-sort-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; margin-bottom: 8px;
            background: #fff; border: 1px solid #e7eaf3; border-radius: 10px;
            cursor: grab; user-select: none; touch-action: none;
            transition: box-shadow .15s ease, border-color .15s ease;
        }
        .nz-sort-item:hover { border-color: var(--primary, #ea4a2f); box-shadow: 0 2px 10px rgba(20,22,40,.06); }
        .nz-drag-ghost { opacity: .35; }
        .nz-drag-chosen { box-shadow: 0 8px 22px rgba(20,22,40,.16); }
        .nz-sort-handle { color: #9aa0ac; font-size: 20px; flex-shrink: 0; }
        .nz-sort-thumb { width: 46px; height: 46px; border-radius: 8px; object-fit: cover; flex-shrink: 0; background: #f4f6fb; }
        .nz-sort-body { min-width: 0; }
        .nz-sort-name { font-weight: 500; color: #1e2022; line-height: 1.3; word-break: break-word; }
        .nz-sort-price { color: #677788; font-size: 13px; margin-top: 2px; }
        .nz-sort-tail { margin-left: auto; color: #cbd0dc; font-size: 18px; flex-shrink: 0; padding-left: 6px; }
        .nz-sort-hint {
            display: flex; align-items: flex-start; gap: 8px;
            background: #fff8f1; border: 1px solid #ffe1c7; border-radius: 10px;
            padding: 10px 14px; color: #7a5230; font-size: 13px; line-height: 1.6; margin-bottom: 18px;
        }
        .nz-sort-hint i { margin-top: 2px; }
    </style>
@endpush

@section('content')
<div class="content container-fluid">
    <div class="page-header">
        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-between">
            <h1 class="page-header-title mb-0"><i class="tio-sort"></i> 菜品排序</h1>
            <a href="{{ route('vendor.food.list') }}" class="btn btn-sm btn--reset"><i class="tio-back-ui mr-1"></i>返回菜品列表</a>
        </div>
    </div>

    <div class="card nz-sort-wrap">
        <div class="card-body">
            <div class="nz-sort-hint">
                <i class="tio-info-outined"></i>
                <div>拖动菜品，调整它在<b>顾客端菜单</b>里的陈列顺序（顾客默认排序档）。<b>松手即自动保存</b>。<br>顾客主动切换「快送 / A→Z」等排序时，仍按对应规则显示。新加的菜默认排在分类末尾，可拖上来置顶。</div>
            </div>

            @if($groups->isEmpty())
                <div class="text-center py-5 text-body">
                    还没有菜品，先去 <a href="{{ route('vendor.food.add-new') }}">添加菜品</a>，再回来排序。
                </div>
            @else
                <div class="form-group mb-4">
                    <label class="input-label">选择分类</label>
                    <select id="nzCatSelect" class="form-control">
                        @foreach($groups as $g)
                            <option value="{{ $g['id'] }}">{{ $g['name'] }}（{{ count($g['foods']) }} 个）</option>
                        @endforeach
                    </select>
                </div>

                @foreach($groups as $gi => $g)
                    <div class="nz-sort-list {{ $gi === 0 ? 'active' : '' }}" data-category="{{ $g['id'] }}">
                        @foreach($g['foods'] as $food)
                            <div class="nz-sort-item" data-id="{{ $food['id'] }}">
                                <i class="tio-drag-indicator nz-sort-handle"></i>
                                <img class="nz-sort-thumb onerror-image" src="{{ $food['image_full_url'] }}"
                                     data-onerror-image="{{ dynamicAsset('assets/admin/img/100x100/food-default-image.png') }}" alt="">
                                <div class="nz-sort-body">
                                    <div class="nz-sort-name">{{ ucwords(Str::limit($food['name'], 30, '...')) }}</div>
                                    <div class="nz-sort-price">{{ \App\CentralLogics\Helpers::format_currency($food['price']) }}</div>
                                </div>
                                <i class="tio-sort nz-sort-tail"></i>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            @endif
        </div>
    </div>
</div>
@endsection

@push('script_2')
    <script src="{{ dynamicAsset('assets/admin/js/nezha-sortable.min.js') }}"></script>
    <script>
        "use strict";
        (function () {
            if (typeof Sortable === 'undefined') { return; }
            var saveUrl = @json(route('vendor.food.sort-save'));
            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var csrf = tokenEl ? tokenEl.getAttribute('content') : '';
            var timers = {};

            function toast(msg, ok) {
                if (typeof toastr !== 'undefined') { ok ? toastr.success(msg) : toastr.error(msg); return; }
                var t = document.createElement('div');
                t.textContent = msg;
                t.style.cssText = 'position:fixed;z-index:11000;left:50%;bottom:40px;transform:translateX(-50%);'
                    + 'padding:10px 18px;border-radius:8px;color:#fff;font-size:14px;'
                    + 'box-shadow:0 6px 20px rgba(0,0,0,.18);background:' + (ok ? '#2fa36b' : '#e0503a');
                document.body.appendChild(t);
                setTimeout(function () { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; }, 1400);
                setTimeout(function () { t.remove(); }, 1900);
            }

            function saveCategory(listEl) {
                var catId = listEl.getAttribute('data-category');
                var ids = Array.prototype.map.call(
                    listEl.querySelectorAll('.nz-sort-item'),
                    function (el) { return el.getAttribute('data-id'); }
                );
                clearTimeout(timers[catId]);
                timers[catId] = setTimeout(function () {
                    fetch(saveUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                        body: JSON.stringify({ category_id: catId, order: ids })
                    })
                    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (res) { toast((res.j && res.j.message) || (res.ok ? '已保存' : '保存失败'), res.ok && res.j && res.j.status); })
                    .catch(function () { toast('保存失败，请检查网络', false); });
                }, 500);
            }

            document.querySelectorAll('.nz-sort-list').forEach(function (listEl) {
                new Sortable(listEl, {
                    animation: 150,
                    handle: '.nz-sort-item',
                    draggable: '.nz-sort-item',
                    ghostClass: 'nz-drag-ghost',
                    chosenClass: 'nz-drag-chosen',
                    onEnd: function () { saveCategory(listEl); }
                });
            });

            var sel = document.getElementById('nzCatSelect');
            if (sel) {
                sel.addEventListener('change', function () {
                    document.querySelectorAll('.nz-sort-list').forEach(function (l) {
                        l.classList.toggle('active', l.getAttribute('data-category') === sel.value);
                    });
                });
            }
        })();
    </script>
@endpush
