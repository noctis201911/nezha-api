<div id="headerMain" class="d-none">
    <header id="header"
        class="navbar navbar-expand-lg navbar-fixed navbar-height navbar-flush navbar-container navbar-bordered">
        <div class="navbar-nav-wrap">
            <div class="navbar-brand-wrapper">
                @php($restaurant_logo = \App\CentralLogics\Helpers::getSettingsDataFromConfig(settings: 'logo', relations: ['storage']))
                <a class="navbar-brand d-none d-md-block"
                    href="{{ route('admin.payment-address-review.pending') }}" aria-label="哪吒外卖独立复核">
                    <img class="navbar-brand-logo brand--logo-design-2"
                        src="{{ \App\CentralLogics\Helpers::get_full_url('business', $restaurant_logo?->value, $restaurant_logo?->storage[0]?->value ?? 'public', 'favicon') }}"
                        alt="哪吒外卖">
                    <img class="navbar-brand-logo-mini brand--logo-design-2"
                        src="{{ \App\CentralLogics\Helpers::get_full_url('business', $restaurant_logo?->value, $restaurant_logo?->storage[0]?->value ?? 'public', 'favicon') }}"
                        alt="哪吒外卖">
                </a>
            </div>

            <div class="navbar-nav-wrap-content-left d--xl-none">
                <button type="button" class="js-navbar-vertical-aside-toggle-invoker close mr-3"
                    aria-label="展开或收起侧栏">
                    <i class="tio-first-page navbar-vertical-aside-toggle-short-align"></i>
                    <i class="tio-last-page navbar-vertical-aside-toggle-full-align"></i>
                </button>
            </div>

            <div class="navbar-nav-wrap-content-right">
                <ul class="navbar-nav align-items-center flex-row">
                    @php($__nzEnv = app()->environment('production'))
                    <li class="nav-item d-none d-md-inline-block mr-3">
                        <span class="nz-env-badge {{ $__nzEnv ? 'nz-env-badge--prod' : 'nz-env-badge--staging' }}">
                            {{ $__nzEnv ? '生产' : 'STAGING' }}
                        </span>
                    </li>
                    <li class="nav-item d-none d-sm-inline-block mr-4">
                        <span class="badge badge-soft-info px-3 py-2">独立复核</span>
                    </li>
                    <li class="nav-item">
                        <div class="hs-unfold">
                            <a class="js-hs-unfold-invoker navbar-dropdown-account-wrapper" href="javascript:;"
                                data-hs-unfold-options='{"target":"#accountNavbarDropdown","type":"css-animation"}'>
                                <div class="cmn--media dropdown-toggle d-flex align-items-center">
                                    <div class="avatar avatar-sm avatar-circle">
                                        <img class="avatar-img"
                                            src="{{ auth('admin')?->user()?->image_full_url }}" alt="复核员头像">
                                        <span class="avatar-status avatar-sm-status avatar-status-success"></span>
                                    </div>
                                    <div class="media-body pl-2">
                                        <span class="card-title h5 text-right line--limit-1">
                                            {{ auth('admin')->user()->f_name }} {{ auth('admin')->user()->l_name }}
                                        </span>
                                        <span class="card-text overflow-wrap-anywhere max-w-130px line--limit-1">
                                            {{ auth('admin')->user()->email }}
                                        </span>
                                    </div>
                                </div>
                            </a>

                            <div id="accountNavbarDropdown"
                                class="hs-unfold-content dropdown-unfold dropdown-menu dropdown-menu-right navbar-dropdown-menu navbar-dropdown-account w-16rem">
                                <div class="dropdown-item-text">
                                    <strong>收款地址独立复核员</strong>
                                    <div class="text-muted fs-12 mt-1">该账号不能进入其它后台模块</div>
                                </div>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="javascript:;" onclick="Swal.fire({
                                    title: `{{ translate('messages.Do_You_Want_To_Sign_Out_?') }}`,
                                    showCancelButton: true,
                                    confirmButtonColor: `#FC6A57`,
                                    cancelButtonColor: `#363636`,
                                    confirmButtonText: `{{ translate('messages.Yes') }}`,
                                    cancelButtonText: `{{ translate('messages.cancel') }}`
                                }).then((result) => { if (result.value) location.href=`{{ route('logout') }}`; })">
                                    <span class="text-truncate pr-2">{{ translate('messages.sign_out') }}</span>
                                </a>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </header>
</div>
<div id="headerFluid" class="d-none"></div>
<div id="headerDouble" class="d-none"></div>

<style>
    .nz-env-badge{display:inline-block;padding:4px 11px;border-radius:6px;font-size:12px;font-weight:600;letter-spacing:.5px;line-height:1.4}
    .nz-env-badge--prod{background:#102A4C;color:#fff}
    .nz-env-badge--staging{background:#FCF1E3;color:#D97A08;border:1px solid #D97A08}
</style>
