{{--
    哪吒超管M1(D1): 侧栏条目递归渲染局部。由 _sidebar.blade.php 的 $__navGroups 配置数组驱动。
    条目字段见 _sidebar.blade.php 顶部注释。$item['gate'] 缺省时回退到 $groupGate(顶层条目所在组的闸,未传则true);
    子项(depth>0)缺省回退true——原文件里子项从不单独比父项闸更严格,已逐组核对。
    $depth: 0=顶层(用 <i> 图标 + navbar-vertical-aside-mini-mode-hidden-elements 包裹文字), >0=子项(恒用 tio-circle 圆点)。
--}}
@if (($item['gate'] ?? ($groupGate ?? true)))
    @php
        $__label = !empty($item['label_raw']) ? $item['label'] : translate($item['label']);
        $__titleKey = $item['title'] ?? ($item['label_raw'] ?? false ? null : $item['label']);
        $__title = isset($item['title_literal']) ? $item['title_literal'] : ($__titleKey ? translate($__titleKey) : $__label);
        $__yields = (array) ($item['yield'] ?? []);
        $__isChild = ($depth ?? 0) > 0;
        $__activeClass = !empty($item['active']) ? 'active' . (!empty($item['active_extra_token']) ? ' ' . $item['active_extra_token'] : '') : '';
        $__expanded = $__isChild && !empty($item['expanded_child_style']); // 原文件里少数子项(如「新增餐厅」)照抄了顶层式markup,逐项核对后照抄保留
        $__liClass = implode(' ', array_filter([$item['li_base'] ?? ($__isChild ? 'nav-item' : 'navbar-vertical-aside-has-menu'), $__activeClass, $item['extra_class'] ?? '']));
        $__aClass = (!empty($item['plain_link']) || ($__isChild && !$__expanded)) ? 'nav-link' : 'js-navbar-vertical-aside-menu-link nav-link';
        $__miniMode = ((!$__isChild || $__expanded) && empty($item['plain_link'])) || !empty($item['mini_mode_span']);
        $__spanClass = implode(' ', array_filter([$__miniMode ? 'navbar-vertical-aside-mini-mode-hidden-elements' : null, 'text-truncate', (!empty($item['badge']) && empty($item['badge_no_container'])) ? 'sidebar--badge-container' : null]));
        $__iconClass = implode(' ', array_filter([$item['icon'] ?? null, $item['icon_extra_class'] ?? null, 'nav-icon']));
    @endphp
    @if (empty($item['children']))
        <li class="{{ $__liClass }}@foreach ($__yields as $__y) @yield($__y)@endforeach">
            <a class="{{ $__aClass }}"
                href="{{ $item['route'] }}" title="{{ $__title }}">
                @if ($__isChild)
                    <span class="tio-circle nav-indicator-icon"></span>
                @else
                    <i class="{{ $__iconClass }}"></i>
                @endif
                <span
                    class="{{ $__spanClass }}">{{ $__label }}@if (!empty($item['badge']))
                        <span class="badge {{ $item['badge']['class'] }} badge-pill ml-1">{{ $item['badge']['value'] }}</span>
                    @endif</span>
            </a>
        </li>
    @else
        <li class="navbar-vertical-aside-has-menu {{ $__activeClass }}@foreach ($__yields as $__y) @yield($__y)@endforeach">
            <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:" title="{{ $__title }}">
                <i class="{{ $item['icon'] ?? '' }} nav-icon"></i>
                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{ $__label }}</span>
            </a>
            <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                style="display: {{ !empty($item['expanded'] ?? $item['active']) ? 'block' : 'none' }}">
                @foreach ($item['children'] as $__child)
                    @include('layouts.admin.partials._sidebar-item', ['item' => $__child, 'depth' => ($depth ?? 0) + 1])
                @endforeach
            </ul>
        </li>
    @endif
@endif
