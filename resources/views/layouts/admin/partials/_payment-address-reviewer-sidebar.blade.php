<div id="sidebarMain" class="d-none">
    <aside class="js-navbar-vertical-aside navbar navbar-vertical-aside navbar-vertical navbar-vertical-fixed navbar-expand-xl navbar-bordered">
        <div class="navbar-vertical-container">
            <div class="navbar__brand-wrapper navbar-brand-wrapper justify-content-between">
                @php($restaurant_logo = \App\CentralLogics\Helpers::getSettingsDataFromConfig(settings: 'logo', relations: ['storage']))
                <a class="navbar-brand d-block p-0" href="{{ route('admin.payment-address-review.pending') }}"
                    aria-label="哪吒外卖独立复核">
                    <img class="navbar-brand-logo sidebar--logo-design"
                        src="{{ \App\CentralLogics\Helpers::get_full_url('business', $restaurant_logo?->value, $restaurant_logo?->storage[0]?->value ?? 'public', 'favicon') }}"
                        alt="哪吒外卖">
                    <img class="navbar-brand-logo-mini sidebar--logo-design-2"
                        src="{{ \App\CentralLogics\Helpers::get_full_url('business', $restaurant_logo?->value, $restaurant_logo?->storage[0]?->value ?? 'public', 'favicon') }}"
                        alt="哪吒外卖">
                </a>
                <button type="button"
                    class="js-navbar-vertical-aside-toggle-invoker navbar-vertical-aside-toggle btn btn-icon btn-xs btn-ghost-dark"
                    aria-label="关闭侧栏">
                    <i class="tio-clear tio-lg"></i>
                </button>
                <div class="navbar-nav-wrap-content-left d-none d-xl-block">
                    <button type="button" class="js-navbar-vertical-aside-toggle-invoker close" aria-label="收起侧栏">
                        <i class="tio-first-page navbar-vertical-aside-toggle-short-align"></i>
                        <i class="tio-last-page navbar-vertical-aside-toggle-full-align"></i>
                    </button>
                </div>
            </div>

            <div class="navbar-vertical-content bg-334257" id="navbar-vertical-content">
                <ul class="navbar-nav navbar-nav-lg nav-tabs mt-3">
                    <li class="nav-item">
                        <small class="nav-subtitle">钱·风控</small>
                        <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                    </li>
                    <li class="nav-item active">
                        <a class="js-navbar-vertical-aside-menu-link nav-link"
                            href="{{ route('admin.payment-address-review.pending') }}"
                            title="收款地址复核" aria-current="page">
                            <i class="tio-shield nav-icon"></i>
                            <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                收款地址复核
                            </span>
                        </a>
                    </li>
                    <li class="nav-item px-3 mt-4">
                        <div class="text-white-50 fs-12 line-height-lg">
                            仅显示商家已确认、等待不同管理员复核的申请。
                        </div>
                    </li>
                    <li class="nav-item pt-100px"></li>
                </ul>
            </div>
        </div>
    </aside>
</div>
<div id="sidebarCompact" class="d-none"></div>
