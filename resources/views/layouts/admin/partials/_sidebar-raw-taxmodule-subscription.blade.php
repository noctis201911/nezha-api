{{-- TaxModule插件+订阅管理 原均嵌套在业务设置组(settings闸)内,原样保留须补回外层闸(D1提交说明已注明) --}}
                    @if (\App\CentralLogics\Helpers::module_permission_check('settings'))
{{-- TaxModule插件税务模块(addon未安装则恒不可见) 原文件1768-1803行 --}}
                        @if (addon_published_status('TaxModule'))
                            <li class="navbar-vertical-aside-has-menu @yield('taxModule')">
                                <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle"
                                    href="javascript:">
                                    <i class="tio-wallet nav-icon"></i>
                                    <span
                                        class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{ translate('System_Tax') }}</span>
                                </a>
                                <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                    style="display: @yield('taxModuleDisplay', 'none')">


                                    <li class="navbar-vertical-aside-has-menu @yield('tax_setup')">
                                        <a class="js-navbar-vertical-aside-menu-link nav-link"
                                            href="{{ route('taxvat.index') }}" title="{{ translate('navbar') }}">
                                            <i class="tio-chart-line-up nav-icon"></i>
                                            <span
                                                class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                                {{ translate('Create_Taxes') }}
                                            </span>
                                        </a>
                                    </li>
                                    <li class="navbar-vertical-aside-has-menu @yield('tax_system_setup')">
                                        <a class="js-navbar-vertical-aside-menu-link nav-link"
                                            href="{{ route('taxvat.systemTaxvat') }}"
                                            title="{{ translate('Setup_Taxes') }}">
                                            <i class="tio-calculator nav-icon"></i>
                                            <span
                                                class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                                {{ translate('Setup_Taxes') }}
                                            </span>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        @endif
{{-- 订阅管理(哪吒M1藏,§A#10: 佣金模式非订阅制业主既定决策,非仅当前business_model.subscription=0的临时态) 原文件1804-1844行 --}}
                        <!-- Subscription-->
                        @if (false && \App\CentralLogics\Helpers::subscription_check() == true && \App\CentralLogics\Helpers::module_permission_check('restaurant'))
                            <li
                                class="navbar-vertical-aside-has-menu {{ Request::is('admin/subscription*') ? 'active' : '' }}">
                                <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle"
                                    href="javascript:" title="{{ translate('messages.subscription') }}">
                                    <i class="tio-crown  nav-icon"></i>
                                    <span
                                        class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{ translate('messages.subscription_management') }}</span>
                                </a>
                                <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                    style="display: {{ Request::is('admin/subscription*') ? 'block' : 'none' }}">
                                    <li
                                        class="nav-item @yield('subscription_index') {{ Request::is('admin/subscription/package/*') || Request::is('admin/subscription/search') || Request::is('admin/subscription/transcation/list/*') ? 'active' : '' }}">
                                        <a class="nav-link " href="{{ route('admin.subscription.package_list') }}"
                                            title="{{ translate('messages.Package_list') }}">
                                            <span class="tio-circle nav-indicator-icon"></span>
                                            <span
                                                class="text-truncate">{{ translate('messages.subscription_Packages') }}</span>
                                        </a>
                                    </li>
                                    <li class="nav-item @yield('subscriberList')">
                                        <a class="nav-link "
                                            href="{{ route('admin.subscription.subscription_list') }}"
                                            title="{{ translate('messages.Subscriber_list') }}">
                                            <span class="tio-circle nav-indicator-icon"></span>
                                            <span class="text-truncate">{{ translate('Subscriber_list') }}</span>
                                        </a>
                                    </li>
                            </li>
                            <li class="nav-item {{ Request::is('admin/subscription/settings') ? 'active' : '' }}">
                                <a class="nav-link " href="{{ route('admin.subscription.settings') }}"
                                    title="{{ translate('messages.settings') }}">
                                    <span class="tio-circle nav-indicator-icon"></span>
                                    <span class="text-truncate">{{ translate('messages.settings') }}</span>
                                </a>
                            </li>
                </ul>
                </li>
                @endif
                    @endif
