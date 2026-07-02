@extends('layouts.vendor.app')

@section('title', '菜品排序')

@push('css_or_js')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        .nz-sort-wrap { max-width: 760px; }
        .nz-sort-list { display: none; }
        .nz-sort-list.active { display: block; }
        .nz-sort-item, .nz-cat-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; margin-bottom: 8px;
            background: #fff; border: 1px solid #e7eaf3; border-radius: 10px;
            cursor: grab; user-select: none; touch-action: none;
            transition: box-shadow .15s ease, border-color .15s ease;
        }
        .nz-sort-item:hover, .nz-cat-item:hover { border-color: var(--primary, #ea4a2f); box-shadow: 0 2px 10px rgba(20,22,40,.06); }
        .nz-drag-ghost { opacity: .35; }
        .nz-drag-chosen { box-shadow: 0 8px 22px rgba(20,22,40,.16); }
        .nz-sort-handle { color: #9aa0ac; font-size: 20px; flex-shrink: 0; }
        .nz-sort-thumb { width: 46px; height: 46px; border-radius: 8px; object-fit: cover; flex-shrink: 0; background: #f4f6fb; }
        .nz-sort-body { min-width: 0; }
        .nz-sort-name { font-weight: 500; color: #1e2022; line-height: 1.3; word-break: break-word; }
        .nz-sort-price { color: #677788; font-size: 13px; margin-top: 2px; }
        .nz-sort-tail { margin-left: auto; color: #cbd0dc; font-size: 18px; flex-shrink: 0; padding-left: 6px; }
        .nz-cat-name { font-weight: 500; color: #1e2022; }
        .nz-cat-count { margin-left: auto; color: #9aa0ac; font-size: 12px; flex-shrink: 0; }
        .nz-block-title { font-size: 15px; font-weight: 600; color: #1e2022; margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
        .nz-block-sub { font-size: 12px; font-weight: 400; color: #9aa0ac; }
        .nz-sort-hint {
            display: flex; align-items: flex-start; gap: 8px;
            background: #fff8f1; border: 1px solid #ffe1c7; border-radius: 10px;
            padding: 10px 14px; color: #7a5230; font-size: 13px; line-height: 1.6; margin-bottom: 18px;
        }
        .nz-sort-hint i { margin-top: 2px; }
        .nz-divider { height: 1px; background: #eceef4; margin: 22px 0; }
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
                <div>拖动来调整<b>顾客端菜单</b>的展示顺序（顾客默认排序档）。<b>松手即自动保存</b>。<br>顾客主动切换「快送 / A→Z」等排序时，仍按对应规则显示。</div>
            </div>

            @if($groups->isEmpty())
                <div class="text-center py-5 text-body">
                    还没有菜品，先去 <a href="{{ route('vendor.food.add-new') }}">添加菜品</a>，再回来排序。
                </div>
            @else
                @if($groups->count() > 1)
                    <div class="nz-block-title">分类顺序 <span class="nz-block-sub">拖动调整分类在顾客菜单里的先后</span></div>
                    <div id="nzCatSortList" class="mb-2">
                        @foreach($groups as $g)
                            <div class="nz-cat-item" data-id="{{ $g['id'] }}">
                                <i class="tio-drag-indicator nz-sort-handle"></i>
                                <span class="nz-cat-name">{{ $g['name'] }}</span>
                                <span class="nz-cat-count">{{ count($g['foods']) }} 个菜</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="nz-divider"></div>
                @endif

                <div class="nz-block-title">菜品顺序 <span class="nz-block-sub">先选分类，再拖动分类内的菜品</span></div>
                <div class="form-group mt-2 mb-3">
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
            var dishUrl = @json(route('vendor.food.sort-save'));
            var catUrl = @json(route('vendor.food.category-sort-save'));
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

            function postOrder(url, key, ids, extra) {
                clearTimeout(timers[key]);
                timers[key] = setTimeout(function () {
                    var body = { order: ids };
                    if (extra) { for (var k in extra) body[k] = extra[k]; }
                    fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                        body: JSON.stringify(body)
                    })
                    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (res) { toast((res.j && res.j.message) || (res.ok ? '已保存' : '保存失败'), res.ok && res.j && res.j.status); })
                    .catch(function () { toast('保存失败，请检查网络', false); });
                }, 500);
            }

            function idsOf(listEl, sel) {
                return Array.prototype.map.call(listEl.querySelectorAll(sel), function (el) { return el.getAttribute('data-id'); });
            }

            // 分类顺序
            var catList = document.getElementById('nzCatSortList');
            if (catList) {
                new Sortable(catList, {
                    animation: 150, handle: '.nz-cat-item', draggable: '.nz-cat-item',
                    ghostClass: 'nz-drag-ghost', chosenClass: 'nz-drag-chosen',
                    onEnd: function () { postOrder(catUrl, 'cat', idsOf(catList, '.nz-cat-item')); }
                });
            }

            // 各分类内的菜品顺序
            document.querySelectorAll('.nz-sort-list').forEach(function (listEl) {
                new Sortable(listEl, {
                    animation: 150, handle: '.nz-sort-item', draggable: '.nz-sort-item',
                    ghostClass: 'nz-drag-ghost', chosenClass: 'nz-drag-chosen',
                    onEnd: function () {
                        var catId = listEl.getAttribute('data-category');
                        postOrder(dishUrl, 'dish-' + catId, idsOf(listEl, '.nz-sort-item'), { category_id: catId });
                    }
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
