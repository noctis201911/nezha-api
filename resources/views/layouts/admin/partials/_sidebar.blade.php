<?php
use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\Cache;

// 哪吒M2-D1: 订单计数改读单一真相源 NezhaAdminCounts(60s缓存·平台全量), 退役 order_stats_summary
// /order_scheduled_stats 的 rememberForever 永久漂移(修「全部28 vs 列表31」根因)。键不重叠, 一份即可。
$__nzAdminCounts = \App\CentralLogics\NezhaAdminCounts::all();
$order = (object) $__nzAdminCounts;
$order_sch = $order;

// 哪吒M2-D4: 驾驶舱队列计数(逾期未退款/争议等)单一真相源, 供侧栏徽标与顶栏铃铛/驾驶舱同源(60s缓存·防御式绝不抛)。
$__nzDash = \App\CentralLogics\NezhaAdminDashboard::counts();

/**
 * 哪吒超管M1(D1 侧栏配置数组化 + D3 8组重排)。
 * 条目schema: label(可见文本translate键,必填) / label_raw(true=label是字面中文,不走translate) /
 *   title(tooltip translate键,省略则等于label) / title_literal(预拼好的字符串,绕过translate) /
 *   route / icon(仅顶层用) / active(预算好的布尔值) / expanded(可选,子菜单展开态) / badge{value,class} /
 *   badge_no_container / yield(单个或数组,legacy @yield标记) / extra_class / li_base / plain_link /
 *   mini_mode_span / children(同schema递归,存在则渲染为可展开toggle父项) / gate(条目级权限,缺省回退组gate) /
 *   hide(true=藏,§A清单,渲染为HTML注释不出现在侧栏) / hide_reason。
 * 组schema: gate(预算好的布尔值) / subtitle(translate键或中文字面,配合subtitle_raw) / subtitle_raw(true=中文直书不走translate) /
 *   subtitle_title(tooltip translate键) / items。
 * D3 8组顺序: ①今天(仅数据看板占位,M2建驾驶舱) / ②订单 / ③钱·风控 / ④商家 / ⑤内容·审核 / ⑥顾客·客服 / ⑦洞察 / ⑧系统。
 * 所有值在本次请求渲染时一次性求值,与原文件逐条内联求值等价。
 * 已死/插件态代码块(骑手管理/分账/顾客钱包&钱包报表/TaxModule插件/订阅管理/withdraw/deliveryman-earning-report/addon_menus动态段)
 * 保持原样字面 blade 不纳入本数组,原样保留在文件对应位置(TaxModule经raw_include插入⑧系统组内)。
 */
$__navGroups = [];

// ==== ①今天(M2-D4 驾驶舱并存: 第一项「今天」=驾驶舱, 第二项「数据看板」=旧dashboard; 默认落点暂不切, 稳定一周后 1 行切换) ====
$__navGroups[] = [
    'gate' => true,
    'subtitle' => null,
    'items' => [
        ['label' => '今天', 'label_raw' => true, 'route' => route('admin.nezha-today'), 'icon' => 'tio-today',
            'active' => Request::is('admin/nezha-today')],
        ['label' => '数据看板', 'label_raw' => true, 'route' => route('admin.dashboard'), 'icon' => 'tio-dashboard-vs',
            'active' => Request::is('admin')],
    ],
];
// POS(哪吒M1藏§A#1,D2已处理): 保留原group位置,不并入任何D3新组,藏匿与分组正交
$__navGroups[] = [
    'gate' => Helpers::module_permission_check('pos'),
    'subtitle' => null,
    'items' => [
        ['label' => 'messages.Point_of_Sale', 'title' => 'messages.pos', 'route' => route('admin.pos.index'), 'icon' => 'tio-receipt',
            'active' => Request::is('admin/pos'), 'hide' => true, 'hide_reason' => '哪吒M1藏(§A#1): 代下单场景不存在(游客单已关),POS orders恒0'],
    ],
];

// ==== ②订单 ====
$__navGroups[] = [
    'gate' => Helpers::module_permission_check('order'),
    'subtitle' => '订单', 'subtitle_raw' => true,
    'items' => [
        // 哪吒M2-D2: 订单 12 状态子项收敛为单条(badge=待处理组 grp_pending)+离线付款核验独立; 页内组 tab 承接状态细分; 旧 admin/order/list/{status} 全保留可达
        [
            'label' => '订单', 'label_raw' => true, 'icon' => 'tio-file-text-outlined',
            'route' => route('admin.order.list', ['grp_pending']),
            'active' => Request::is('admin/order/list*') || Request::is('admin/order/details*') || Request::is('admin/order-cancel-reasons'),
            'badge' => ['value' => $order->grp_pending, 'class' => 'badge-soft-info'],
        ],
        [
            'label' => 'messages.Offline_Payments', 'title' => 'Offline_Payments', 'icon' => 'tio-receipt-outlined', 'route' => route('admin.order.offline_verification_list', ['all']), 'active' => Request::is('admin/order/offline/payment/list*'), 'badge' => ['value' => $order->offline_payments, 'class' => 'badge-soft-danger bg-light']],
        [
            'label' => 'messages.Subscription_orders', 'route' => route('admin.order.subscription.index'), 'icon' => 'tio-appointment',
            'active' => Request::is('admin/order/subscription*'), 'hide' => true, 'hide_reason' => '哪吒M1藏(§A#2): 佣金模式非订阅制(business_model.subscription=0)',
        ],
        [
            'label' => 'messages.Dispatch_Management', 'icon' => 'tio-clock',
            'active' => Request::is('admin/dispatch/*'),
            'expanded' => Request::is('admin/dispatch*'),
            'hide' => true, 'hide_reason' => '哪吒M1藏(§A#3): 平台无自营骑手,调度永空(orders_with_dm恒0)',
            'children' => [
                ['label' => 'messages.searching_DeliveryMan', 'title_literal' => translate('messages.searching_DeliveryMan') . ' ' . $order_sch->searching_dm, 'route' => route('admin.dispatch.list', ['searching_for_deliverymen']), 'active' => Request::is('admin/dispatch/list/searching_for_deliverymen'), 'badge' => ['value' => $order_sch->searching_dm, 'class' => 'badge-soft-info'], 'badge_no_container' => true],
                ['label' => 'messages.ongoing_Orders', 'route' => route('admin.dispatch.list', ['on_going']), 'active' => Request::is('admin/dispatch/list/on_going'), 'badge' => ['value' => $order_sch->ongoing, 'class' => 'badge-soft-dark bg-light']],
            ],
        ],
    ],
];

// ==== ③钱·风控(风控中心原样迁入 + 原"交易/资金管理"组的deposit系5项合并进来;搜索需求/顾客取消理由已按§B移出至⑦洞察) ====
$__navGroups[] = [
    'gate' => Helpers::module_permission_check('risk') || Helpers::module_permission_check('risk_settings') || Helpers::module_permission_check('refund') || Helpers::module_permission_check('kyc') || Helpers::module_permission_check('deposit'),
    'subtitle' => '钱·风控', 'subtitle_raw' => true,
    'items' => [
        [
            'label' => '风控中心', 'label_raw' => true, 'icon' => 'tio-shield',
            'active' => Request::is('admin/nezha-risk*') || Request::is('admin/nezha-refund*') || Request::is('admin/nezha-kyc*'),
            'children' => array_merge(
                Helpers::module_permission_check('risk') ? [
                    ['label' => '审核队列', 'label_raw' => true, 'route' => route('admin.nezha-risk.queue'), 'active' => Request::is('admin/nezha-risk/queue')],
                    ['label' => '风控日志', 'label_raw' => true, 'route' => route('admin.nezha-risk.logs'), 'active' => Request::is('admin/nezha-risk/logs')],
                ] : [],
                Helpers::module_permission_check('risk_settings') ? [
                    ['label' => '风控设置', 'label_raw' => true, 'route' => route('admin.nezha-risk.settings'), 'active' => Request::is('admin/nezha-risk/settings')],
                ] : [],
                Helpers::module_permission_check('refund') ? [
                    ['label' => '退款留痕/审核', 'label_raw' => true, 'route' => route('admin.nezha-refund.records'), 'active' => Request::is('admin/nezha-refund/records')],
                    // 哪吒M2-D4: 徽标=驾驶舱钱卡同源(NezhaAdminDashboard), 满足 DoD#1「侧栏=卡=列表」对账; 0→无徽标
                    ['label' => '逾期未退款', 'label_raw' => true, 'route' => route('admin.nezha-refund.overdue'), 'active' => Request::is('admin/nezha-refund/overdue'),
                        'badge' => ((int) ($__nzDash['refund_overdue'] ?? 0) > 0) ? ['value' => (int) $__nzDash['refund_overdue'], 'class' => 'badge-soft-danger'] : null],
                    ['label' => '退款争议裁决', 'label_raw' => true, 'route' => route('admin.nezha-refund.disputes'), 'active' => Request::is('admin/nezha-refund/disputes'),
                        'badge' => ((int) ($__nzDash['disputes'] ?? 0) > 0) ? ['value' => (int) $__nzDash['disputes'], 'class' => 'badge-soft-warning'] : null],
                ] : [],
                Helpers::module_permission_check('kyc') ? [
                    ['label' => '商家KYC', 'label_raw' => true, 'route' => route('admin.nezha-kyc.index'), 'active' => Request::is('admin/nezha-kyc*')],
                ] : [],
            ),
        ],
        ['label' => '佣金充值管理', 'label_raw' => true, 'route' => route('admin.nezha-deposit.index'), 'icon' => 'tio-wallet', 'active' => Request::is('admin/nezha-deposit*'), 'gate' => Helpers::module_permission_check('deposit')],
        ['label' => '商家退出结算', 'label_raw' => true, 'route' => route('admin.nezha-offboard.index'), 'icon' => 'tio-logout', 'active' => Request::is('admin/nezha-offboard*'), 'gate' => Helpers::module_permission_check('deposit')],
        ['label' => '充值申请', 'label_raw' => true, 'route' => route('admin.nezha-topup.index'), 'icon' => 'tio-add-circle', 'active' => Request::is('admin/nezha-topup*'), 'gate' => Helpers::module_permission_check('deposit')],
        ['label' => '押金退款', 'label_raw' => true, 'route' => route('admin.nezha-topup.refunds'), 'icon' => 'tio-undo', 'active' => Request::is('admin/nezha-topup/refunds*'), 'gate' => Helpers::module_permission_check('deposit')],
        ['label' => '平台集运申报', 'label_raw' => true, 'route' => route('admin.nezha-consolidation.index'), 'icon' => 'tio-cube', 'active' => Request::is('admin/nezha-consolidation*'), 'gate' => true],
    ],
];

// ==== ④商家(zone_setup未见于§B文字,按"商家基础设施"就近判断留此,判断口径见D3报告) ====
$__navGroups[] = [
    'gate' => Helpers::module_permission_check('zone') || Helpers::module_permission_check('restaurant') || Helpers::module_permission_check('nezha_cs'),
    'subtitle' => '商家', 'subtitle_raw' => true,
    'items' => array_merge(
        Helpers::module_permission_check('zone') ? [
            ['label' => 'messages.zone_setup', 'route' => route('admin.zone.home'), 'icon' => 'tio-poi-outlined', 'active' => Request::is('admin/zone*')],
        ] : [],
        Helpers::module_permission_check('restaurant') ? [
            [
                'label' => 'messages.restaurants', 'icon' => 'tio-restaurant',
                'active' => (Request::is('admin/restaurant/*') && !Request::is('admin/restaurant/withdraw_list') && !Request::is('admin/restaurant/withdraw-view*')),
                'expanded' => (Request::is('admin/restaurant/*') && !Request::is('admin/restaurant/withdraw_list')) || stripos(Request()->fullUrl(), 'pending-list', 5),
                'children' => [
                    ['label' => 'messages.add_restaurant', 'route' => route('admin.restaurant.add'), 'active' => Request::is('admin/restaurant/add'), 'expanded_child_style' => true, 'li_base' => 'navbar-vertical-aside-has-menu'],
                    ['label' => 'messages.restaurants_list', 'route' => route('admin.restaurant.list'), 'yield' => 'restaurant_list', 'expanded_child_style' => true, 'li_base' => 'navbar-item',
                        'active' => (!stripos(Request()->fullUrl(), 'pending-list', 5) && (Request::is('admin/restaurant/list') || Request::is('admin/restaurant/transcation/*') || Request::is('admin/restaurant/view*')))],
                    ['label' => 'messages.New_joining_request', 'route' => route('admin.restaurant.pending'), 'yield' => 'restaurant_new_join', 'expanded_child_style' => true, 'li_base' => 'navbar-item',
                        'active' => (stripos(Request()->fullUrl(), 'pending-list', 5) || Request::is('admin/restaurant/pending/list*') || Request::is('admin/restaurant/denied/list*'))],
                    ['label' => 'messages.bulk_import', 'route' => route('admin.restaurant.bulk-import'), 'extra_class' => 'text-capitalize', 'active' => Request::is('admin/restaurant/bulk-import')],
                    ['label' => 'messages.bulk_export', 'route' => route('admin.restaurant.bulk-export-index'), 'extra_class' => 'text-capitalize', 'active' => Request::is('admin/restaurant/bulk-export')],
                ],
            ],
            ['label' => '商家入驻申请', 'label_raw' => true, 'route' => route('admin.merchant-lead.list'), 'icon' => 'tio-shop', 'active' => Request::is('admin/merchant-lead/*')],
        ] : [],
        [
            ['label' => '商家反馈', 'label_raw' => true, 'route' => route('admin.vendor-feedback.index'), 'icon' => 'tio-comment-text-outlined', 'active' => Request::is('admin/vendor-feedback*'), 'gate' => Helpers::module_permission_check('nezha_cs')],
        ],
    ),
];

// ==== ⑤内容·审核(addon 034-036未见于§B文字,按"食品配置"域就近归入,判断口径见D3报告) ====
$__navGroups[] = [
    'gate' => Helpers::module_permission_check('restaurant') || Helpers::module_permission_check('category') || Helpers::module_permission_check('addon') || Helpers::module_permission_check('food') || Helpers::module_permission_check('campaign') || Helpers::module_permission_check('coupon') || Helpers::module_permission_check('advertisement') || Helpers::module_permission_check('banner') || Helpers::module_permission_check('settings'),
    'subtitle' => '内容·审核', 'subtitle_raw' => true,
    'items' => array_merge(
        Helpers::module_permission_check('restaurant') ? [
            ['label' => 'messages.cuisine', 'route' => route('admin.cuisine.add'), 'icon' => 'tio-link', 'active' => Request::is('admin/cuisine/add')],
        ] : [],
        Helpers::module_permission_check('category') ? [[
            'label' => 'messages.categories', 'icon' => 'tio-category', 'active' => Request::is('admin/category*'),
            'children' => [
                ['label' => 'messages.category', 'route' => route('admin.category.add'), 'active' => (Request::is('admin/category/add') || Request::is('admin/category/edit/*'))],
                ['label' => 'messages.sub_category', 'route' => route('admin.category.add-sub-category'), 'active' => Request::is('admin/category/add-sub-category')],
                ['label' => 'messages.bulk_import', 'route' => route('admin.category.bulk-import'), 'extra_class' => 'text-capitalize', 'active' => Request::is('admin/category/bulk-import')],
                ['label' => 'messages.bulk_export', 'route' => route('admin.category.bulk-export-index'), 'extra_class' => 'text-capitalize', 'active' => Request::is('admin/category/bulk-export')],
            ],
        ]] : [],
        Helpers::module_permission_check('addon') ? [[
            'label' => 'messages.addons', 'icon' => 'tio-add-circle-outlined', 'active' => Request::is('admin/addon/*'),
            'children' => [
                ['label' => 'messages.Addon_Category', 'route' => route('admin.addon.addon-category'), 'active' => Request::is('admin/addon/addon-category')],
                ['label' => 'messages.list', 'title' => 'messages.addon_list', 'route' => route('admin.addon.add-new'), 'active' => (Request::is('admin/addon/add-new') || Request::is('admin/addon/edit/*'))],
                ['label' => 'messages.bulk_import', 'route' => route('admin.addon.bulk-import'), 'extra_class' => 'text-capitalize', 'active' => Request::is('admin/addon/bulk-import')],
                ['label' => 'messages.bulk_export', 'route' => route('admin.addon.bulk-export-index'), 'extra_class' => 'text-capitalize', 'active' => Request::is('admin/addon/bulk-export')],
            ],
        ]] : [],
        Helpers::module_permission_check('food') ? [[
            'label' => 'messages.foods', 'icon' => 'tio-fastfood', 'active' => Request::is('admin/food*'),
            'children' => [
                ['label' => 'messages.add_new', 'route' => route('admin.food.add-new'), 'active' => Request::is('admin/food/add-new')],
                ['label' => 'messages.list', 'title' => 'messages.food_list', 'route' => route('admin.food.list'), 'active' => (Request::is('admin/food/list') || Request::is('admin/food/view/*'))],
                ['label' => 'messages.review', 'title' => 'messages.review_list', 'route' => route('admin.food.reviews'), 'active' => Request::is('admin/food/reviews')],
                ['label' => 'messages.bulk_import', 'route' => route('admin.food.bulk-import'), 'extra_class' => 'text-capitalize', 'active' => Request::is('admin/food/bulk-import')],
                ['label' => 'messages.bulk_export', 'route' => route('admin.food.bulk-export-index'), 'extra_class' => 'text-capitalize', 'active' => Request::is('admin/food/bulk-export')],
            ],
        ]] : [],
        Helpers::module_permission_check('campaign') ? [[
            'label' => 'messages.campaigns', 'icon' => 'tio-notice', 'active' => Request::is('admin/campaign*'),
            'children' => [
                ['label' => 'messages.basic_campaign', 'route' => route('admin.campaign.list', 'basic'), 'active' => Request::is('admin/campaign/basic/*')],
                ['label' => 'messages.food_campaign', 'route' => route('admin.campaign.list', 'item'), 'active' => Request::is('admin/campaign/item/*')],
            ],
        ]] : [],
        Helpers::module_permission_check('coupon') ? [
            ['label' => 'messages.coupons', 'route' => route('admin.coupon.add-new'), 'icon' => 'tio-ticket', 'active' => Request::is('admin/coupon*')],
        ] : [],
        Helpers::module_permission_check('cashback') ? [
            ['label' => 'messages.cashback', 'route' => route('admin.cashback.add-new'), 'icon' => 'tio-settings-back', 'active' => Request::is('admin/cashback*'), 'hide' => true, 'hide_reason' => '哪吒M1藏(§A#8): 返现未启用,cashback_history恒0'],
        ] : [],
        Helpers::module_permission_check('banner') ? [
            ['label' => 'messages.banners', 'route' => route('admin.banner.add-new'), 'icon' => 'tio-bookmark', 'icon_extra_class' => 'side-nav-icon--design', 'active' => (Request::is('admin/banner*') && !Request::is('admin/banner/promotional-banner*'))],
            ['label' => 'messages.promotional_banner', 'route' => route('admin.banner.promotional_banner'), 'icon' => 'tio-tabs', 'icon_extra_class' => 'side-nav-icon--design', 'active' => Request::is('admin/banner/promotional-banner*')],
        ] : [],
        Helpers::module_permission_check('advertisement') ? [[
            'label' => 'messages.advertisement', 'icon' => 'tio-tv-old',
            'active' => false, 'yield' => 'advertisement',
            'expanded' => Request::is('admin/advertisement*'),
            'children' => [
                ['label' => 'messages.New_Advertisement', 'route' => route('admin.advertisement.create'), 'active' => false, 'yield' => 'advertisement_create'],
                ['label' => 'messages.Ad_Requests', 'route' => route('admin.advertisement.requestList'), 'active' => false, 'yield' => 'advertisement_request'],
                ['label' => 'messages.Ads_list', 'route' => route('admin.advertisement.index'), 'active' => false, 'yield' => 'advertisement_list'],
                ['label' => '竞价参数设置', 'label_raw' => true, 'route' => route('admin.advertisement.auction-settings'), 'active' => false, 'yield' => 'advertisement_auction'],
                ['label' => '广告余额充值', 'label_raw' => true, 'route' => route('admin.advertisement.ad-recharge'), 'active' => false, 'yield' => 'advertisement_recharge'],
            ],
        ]] : [],
        [
            ['label' => '本地生活', 'label_raw' => true, 'route' => route('admin.local-life.list'), 'icon' => 'tio-poi-outlined', 'active' => Request::is('admin/local-life*'), 'gate' => Helpers::module_permission_check('settings')],
            ['label' => '本地生活类目', 'label_raw' => true, 'route' => route('admin.local-life.categories.list'), 'icon' => 'tio-folder-labeled', 'active' => Request::is('admin/local-life/categories*'), 'gate' => Helpers::module_permission_check('settings')],
            ['label' => '本地生活商家', 'label_raw' => true, 'route' => route('admin.local-life.merchants.list'), 'icon' => 'tio-shop-outlined', 'active' => Request::is('admin/local-life/merchants*'), 'gate' => Helpers::module_permission_check('settings')],
            ['label' => '举报商家', 'label_raw' => true, 'route' => route('admin.restaurant-report.list'), 'icon' => 'tio-flag', 'active' => Request::is('admin/restaurant-report*'), 'gate' => Helpers::module_permission_check('settings') && Helpers::module_permission_check('restaurant')],
        ],
    ),
];

// ==== ⑥顾客·客服(帮助与支持组的nezha-cs/Chattings/Contact + 营销组的push_notification + 顾客组customerList/subscribed 合并) ====
$__navGroups[] = [
    'gate' => Helpers::module_permission_check('chat') || Helpers::module_permission_check('nezha_cs') || Helpers::module_permission_check('contact_message') || Helpers::module_permission_check('notification') || Helpers::module_permission_check('customerList'),
    'subtitle' => '顾客·客服', 'subtitle_raw' => true,
    'items' => array_merge(
        [
            ['label' => 'messages.Chattings', 'route' => route('admin.message.list', ['tab' => 'customer']), 'icon' => 'tio-chat', 'active' => Request::is('admin/message/list'), 'gate' => Helpers::module_permission_check('chat')],
            ['label' => 'AI在线客服', 'label_raw' => true, 'route' => route('admin.nezha-cs.index'), 'icon' => 'tio-online', 'active' => Request::is('admin/nezha-cs*'), 'gate' => Helpers::module_permission_check('nezha_cs')],
            ['label' => 'messages.Contact_messages', 'route' => route('admin.contact.list'), 'icon' => 'tio-messages', 'active' => Request::is('admin/contact/*'), 'gate' => Helpers::module_permission_check('contact_message')],
        ],
        Helpers::module_permission_check('notification') ? [
            ['label' => 'messages.push_notification', 'route' => route('admin.notification.add-new'), 'icon' => 'tio-notifications-on', 'active' => Request::is('admin/notification*')],
        ] : [],
        Helpers::module_permission_check('customerList') ? [
            ['label' => 'messages.customeres', 'title' => 'messages.Customer_List', 'route' => route('admin.customer.list'), 'icon' => 'tio-poi-user', 'active' => false, 'yield' => 'customerDetails'],
            [
                'label' => 'messages.loyalty_point', 'icon' => 'tio-medal', 'active' => Request::is('admin/customer/loyalty-point-report*'),
                'hide' => true, 'hide_reason' => '哪吒M1藏(§A#8): 忠诚积分未启用,与自动满减哲学冲突,loyalty_tx恒0',
                'children' => [
                    ['label' => 'messages.report', 'route' => route('admin.customer.loyalty-point.report'), 'active' => Request::is('admin/customer/loyalty-point-report*')],
                ],
            ],
            ['label' => 'messages.subscribed_mail_list', 'title' => 'messages.Subscribed_Emails', 'route' => route('admin.customer.subscribed'), 'icon' => 'tio-email-outlined', 'active' => Request::is('admin/customer/subscribed')],
        ] : [],
    ),
];

// ==== ⑦洞察(留4张报表 + 搜索需求/顾客取消理由;原两个2子项父级拆分,非留4张的子项挪入⑧报表存档折叠) ====
$__navGroups[] = [
    'gate' => true,
    'subtitle' => '洞察', 'subtitle_raw' => true,
    'items' => [
        ['label' => '搜索需求', 'label_raw' => true, 'route' => route('admin.nezha-search-demand.index'), 'icon' => 'tio-search', 'active' => Request::is('admin/nezha-search-demand*'), 'gate' => true],
        ['label' => '顾客取消理由', 'label_raw' => true, 'route' => route('admin.nezha-order-cancel-demand.index'), 'icon' => 'tio-clear-circle', 'active' => Request::is('admin/nezha-order-cancel-demand*'), 'gate' => true],
        ['label' => 'messages.transaction_report', 'route' => route('admin.report.day-wise-report'), 'icon' => 'tio-chart-pie-1', 'active' => Request::is('admin/report/transaction-report'), 'plain_link' => true, 'gate' => Helpers::module_permission_check('report')],
        ['label' => 'messages.Regular_order_report', 'title' => 'messages.order_report', 'route' => route('admin.report.order-report'), 'icon' => 'tio-user', 'extra_class' => 'text-capitalize', 'active' => Request::is('admin/report/order-report'), 'plain_link' => true, 'gate' => Helpers::module_permission_check('report')],
        ['label' => 'messages.restaurant_report', 'route' => route('admin.report.restaurant-report'), 'icon' => 'tio-files', 'active' => Request::is('admin/report/restaurant-report'), 'plain_link' => true, 'gate' => Helpers::module_permission_check('report')],
        ['label' => 'Admin_Earning_Report', 'route' => route('admin.report.admin-earning-report'), 'icon' => 'tio-account-circle', 'active' => false, 'yield' => 'admin_earning_report', 'extra_class' => 'text-capitalize', 'plain_link' => true, 'gate' => Helpers::module_permission_check('report')],
    ],
];

// ==== ⑧系统 ====
$__navGroups[] = [
    'gate' => Helpers::module_permission_check('settings') || Helpers::module_permission_check('system_settings') || Helpers::module_permission_check('system_addon') || Helpers::module_permission_check('custom_role') || Helpers::module_permission_check('employee') || Helpers::module_permission_check('audit') || Helpers::module_permission_check('report'),
    'subtitle' => '系统', 'subtitle_raw' => true,
    'items' => array_merge(
        Helpers::module_permission_check('settings') ? [
            ['label' => 'messages.business_setup', 'route' => route('admin.business-settings.business-setup'), 'icon' => 'tio-settings',
                'active' => (Request::is('admin/business-settings/business-setup*') || Request::is('admin/business-settings/refund/settings*') || Request::is('admin/business-settings/language*')), 'plain_link' => true],
            ['label' => 'messages.email_template', 'route' => route('admin.business-settings.email-setup', ['admin', 'forgot-password']), 'icon' => 'tio-email', 'active' => Request::is('admin/business-settings/email-setup*'), 'plain_link' => true],
            [
                'label' => '政策页', 'label_raw' => true, 'icon' => 'tio-pages',
                'active' => Request::is('admin/business-settings/pages*'),
                'children' => [
                    ['label' => 'messages.terms_and_condition', 'route' => route('admin.business-settings.terms-and-conditions'), 'active' => Request::is('admin/business-settings/pages/terms-and-conditions')],
                    ['label' => 'messages.privacy_policy', 'route' => route('admin.business-settings.privacy-policy'), 'active' => Request::is('admin/business-settings/pages/privacy-policy')],
                    ['label' => 'messages.about_us', 'route' => route('admin.business-settings.about-us'), 'active' => Request::is('admin/business-settings/pages/about-us')],
                    ['label' => 'messages.refund_policy', 'route' => route('admin.business-settings.refund-policy'), 'active' => Request::is('admin/business-settings/pages/refund-policy')],
                    ['label' => 'messages.shipping_policy', 'route' => route('admin.business-settings.shipping-policy'), 'active' => Request::is('admin/business-settings/pages/shipping-policy')],
                    ['label' => 'messages.cancellation_policy', 'route' => route('admin.business-settings.cancellation-policy'), 'active' => Request::is('admin/business-settings/pages/cancellation-policy')],
                ],
            ],
            [
                'label' => '第三方与集成', 'label_raw' => true, 'icon' => 'tio-plugin', 'yield' => '3rd_party',
                'active' => (Request::is('admin/business-settings/fcm-*') || Request::is('admin/business-settings/payment-method') || Request::is('admin/business-settings/sms-module') || Request::is('admin/business-settings/mail-config') || Request::is('admin/social-login/view') || Request::is('admin/business-settings/offline*') || Request::is('admin/business-settings/config*') || Request::is('admin/business-settings/recaptcha*') || Request::is('admin/business-settings/*')),
                'expanded' => (Request::is('admin/business-settings/deliveryman/join-us/*') || Request::is('admin/business-settings/restaurant/join-us/*') || Request::is('admin/business-settings/fcm-*') || Request::is('admin/business-settings/payment-method') || Request::is('admin/business-settings/sms-module') || Request::is('admin/business-settings/mail-config') || Request::is('admin/social-login/view') || Request::is('admin/business-settings/config*') || Request::is('admin/business-settings/recaptcha*') || Request::is('admin/business-settings/offline*') || Request::is('admin/business-settings/marketing/*') || Request::is('admin/business-settings/open-ai') || Request::is('admin/business-settings/open-ai-settings') || Request::is('admin/business-settings/firebase-otp*') || Request::is('admin/business-settings/storage-connection*') || Request::is('admin/business-settings/notification-setup*') || Request::is('admin/business-settings/notificationMessages*')),
                'children' => array_merge(
                    [
                        ['label' => 'messages.3rd_Party', 'route' => route('admin.business-settings.payment-method'), 'yield' => ['firebase_otp', 'storage'],
                            'active' => (Request::is('admin/business-settings/payment-method') || Request::is('admin/business-settings/sms-module') || Request::is('admin/business-settings/mail-config') || Request::is('admin/social-login/view') || Request::is('admin/business-settings/config*') || Request::is('admin/business-settings/recaptcha*'))],
                        ['label' => 'messages.Firebase_Notification', 'route' => route('admin.business-settings.fcm-index'), 'active' => Request::is('admin/business-settings/fcm-*')],
                    ],
                    Helpers::get_mail_status('offline_payment_status') ? [
                        ['label' => 'messages.Offline_Payment_Setup', 'route' => route('admin.business-settings.offline'), 'active' => Request::is('admin/business-settings/offline*')],
                    ] : [],
                    [
                        ['label' => 'messages.Join_us_page_setup', 'route' => route('admin.business-settings.restaurant_page_setup'), 'active' => false, 'yield' => 'reg_page'],
                        ['label' => 'Analytics_Script', 'route' => route('admin.business-settings.marketing.analytic'), 'active' => false, 'yield' => 'analytics_Script'],
                        ['label' => 'AI_Setup', 'route' => route('admin.business-settings.openAI'), 'active' => false, 'yield' => 'openAI'],
                        ['label' => 'messages.Notification_Channels', 'route' => route('admin.business-settings.notification_setup'), 'active' => false, 'yield' => 'notification_setup'],
                        ['label' => 'messages.Notification_Messages', 'route' => route('admin.business-settings.notificationMessages'), 'active' => false, 'yield' => 'notification_message'],
                    ],
                ),
            ],
        ] : [],
        Helpers::module_permission_check('custom_role') ? [
            ['label' => 'messages.employee_Role', 'route' => route('admin.custom-role.create'), 'icon' => 'tio-incognito', 'active' => Request::is('admin/custom-role*')],
        ] : [],
        Helpers::module_permission_check('employee') ? [[
            'label' => 'messages.employees', 'title' => 'Employees', 'icon' => 'tio-user', 'active' => Request::is('admin/employee*'),
            'children' => [
                ['label' => 'messages.Add_New_Employee', 'title' => 'messages.add_new_Employee', 'route' => route('admin.employee.add-new'), 'active' => Request::is('admin/employee/add-new')],
                ['label' => 'messages.Employee_List', 'title' => 'messages.Employee_list', 'route' => route('admin.employee.list'), 'active' => (Request::is('admin/employee/list') || Request::is('admin/employee/update/*'))],
            ],
        ]] : [],
        Helpers::module_permission_check('audit') ? [
            ['label' => '安全审计日志', 'label_raw' => true, 'route' => route('admin.nezha-audit.logs'), 'icon' => 'tio-file-lock', 'active' => Request::is('admin/nezha-audit*')],
        ] : [],
        Helpers::module_permission_check('settings') ? [[
            'label' => '装配', 'label_raw' => true, 'icon' => 'tio-puzzle',
            'active' => (Request::is('admin/business-settings/theme-settings*') || Request::is('admin/file-manager*') || Request::is('admin/login-settings*') || Request::is('admin/business-settings/invoice-setup*') || Request::is('admin/business-settings/social-media') || Request::is('admin/business-settings/registration-page/react*') || Request::is('admin/business-settings/app-settings*') || Request::is('admin/page-meta-data*') || Request::is('admin/addon-activation*') || Request::is('admin/react-landing-page*') || Request::is('admin/landing-page*')),
            'children' => [
                ['label' => 'messages.theme_settings', 'route' => route('admin.business-settings.theme-settings'), 'active' => Request::is('admin/business-settings/theme-settings*')],
                ['label' => 'messages.gallery', 'route' => route('admin.file-manager.index'), 'extra_class' => 'text-capitalize', 'active' => Request::is('admin/file-manager*')],
                ['label' => 'messages.login_setup', 'route' => route('admin.login-settings.index'), 'extra_class' => 'text-capitalize', 'active' => Request::is('admin/login-settings*')],
                ['label' => 'messages.invoice_setup', 'route' => route('admin.business-settings.invoice-setup'), 'active' => Request::is('admin/business-settings/invoice-setup*')],
                ['label' => 'messages.Social_Media', 'route' => route('admin.business-settings.social-media.index'), 'active' => Request::is('admin/business-settings/social-media')],
                ['label' => 'messages.react_registration', 'route' => route('admin.business-settings.react-registration-page.hero'), 'active' => Request::is('admin/business-settings/registration-page/react*')],
                ['label' => 'messages.App_&_Web_Settings', 'route' => route('admin.business-settings.app-settings'), 'active' => Request::is('admin/business-settings/app-settings*')],
                ['label' => 'Page_Meta_data', 'route' => route('admin.pageMetaData'), 'active' => Request::is('admin/page-meta-data*')],
                ['label' => 'messages.Addon_Activation', 'route' => route('admin.addon-activation.index'), 'active' => Request::is('admin/addon-activation*')],
                ['label' => 'messages.Admin_landing_page', 'route' => route('admin.landing_page.setup'), 'active' => Request::is('admin/landing-page*'), 'mini_mode_span' => true],
                ['label' => 'messages.React_landing_page', 'route' => route('admin.react_landing_page.react_header'), 'active' => Request::is('admin/react-landing-page*'), 'mini_mode_span' => true],
                ['label' => 'messages.system_addons', 'route' => route('admin.business-settings.system-addon.index'), 'active' => Request::is('admin/business-settings/system-addon'), 'gate' => Helpers::module_permission_check('system_addon')],
            ],
        ]] : [],
        Helpers::module_permission_check('settings') ? [[
            'label' => '危险区', 'label_raw' => true, 'icon' => 'tio-warning-outlined',
            'active' => Request::is('admin/business-settings/db-index'),
            'children' => [
                ['label' => 'messages.clean_database', 'route' => route('admin.business-settings.db-index'), 'active' => Request::is('admin/business-settings/db-index')],
            ],
        ]] : [],
        Helpers::module_permission_check('report') ? [[
            'label' => '报表(存档)', 'label_raw' => true, 'icon' => 'tio-archive',
            'active' => (Request::is('admin/report/expense-report') || Request::is('admin/report/food-wise-report') || Request::is('admin/report/campaign-order-report') || Request::is('admin/customer/overview/report*') || Request::is('admin/report/getTaxReport') || Request::is('admin/report/vendorWiseTaxes') || Request::is('admin/report/restaurant-earning-report*') || Request::is('admin/report/subscription-report')),
            'children' => array_merge(
                [
                    ['label' => 'messages.expense_report', 'route' => route('admin.report.expense-report'), 'active' => Request::is('admin/report/expense-report'), 'plain_link' => true],
                    ['label' => 'messages.food_report', 'route' => route('admin.report.food-wise-report'), 'active' => Request::is('admin/report/food-wise-report'), 'plain_link' => true],
                    ['label' => 'messages.Campaign_Order_Report', 'route' => route('admin.report.campaign_order-report'), 'extra_class' => 'text-capitalize', 'active' => Request::is('admin/report/campaign-order-report'), 'plain_link' => true],
                    ['label' => 'messages.Customer_Overview_Report', 'route' => route('admin.customer.overview.report'), 'extra_class' => 'text-capitalize', 'active' => Request::is('admin/customer/overview/report*')],
                    ['label' => 'Tax_Report', 'route' => route('admin.report.getTaxReport'), 'active' => false, 'yield' => 'tax_report', 'extra_class' => 'text-capitalize', 'plain_link' => true],
                    ['label' => 'Restaurant_VAT_Report', 'route' => route('admin.report.vendorWiseTaxes'), 'active' => false, 'yield' => 'vendor_tax_report', 'extra_class' => 'text-capitalize', 'plain_link' => true],
                    ['label' => 'Restaurant_Earning_Report', 'route' => route('admin.report.restaurant-earning-report'), 'active' => Request::is('admin/report/restaurant-earning-report*'), 'yield' => 'restaurant_earning_report', 'extra_class' => 'text-capitalize', 'plain_link' => true],
                ],
                (Helpers::subscription_check() == true) ? [['label' => 'messages.Subscription_report', 'route' => route('admin.report.subscription-report'), 'active' => Request::is('admin/report/subscription-report'), 'plain_link' => true]] : [],
            ),
        ]] : [],
        [
            [
                'label' => 'messages.disbursement_report', 'icon' => 'tio-saving',
                'active' => Request::is('admin/report/disbursement-report/restaurant') || Request::is('admin/report/disbursement-report/delivery_man'),
                'gate' => Helpers::module_permission_check('report'),
                'hide' => true, 'hide_reason' => '哪吒M1藏(§A#11): 结算腿危险面同§A#5语义,disbursement_restaurant/dm恒0(L1-5打款腿已拔)',
                'children' => [
                    ['label' => 'messages.restaurants', 'route' => route('admin.report.disbursement_report', ['tab' => 'restaurant']), 'active' => Request::is('admin/report/disbursement-report/restaurant'), 'li_base' => 'navbar-vertical-aside-has-menu', 'plain_link' => true],
                    ['label' => 'messages.delivery_men', 'route' => route('admin.report.disbursement_report', ['tab' => 'delivery_man']), 'active' => Request::is('admin/report/disbursement-report/delivery_man'), 'li_base' => 'navbar-vertical-aside-has-menu', 'plain_link' => true],
                ],
            ],
            ['label' => 'messages.DeliveryMan_Payments', 'route' => route('admin.provide-deliveryman-earnings.index'), 'icon' => 'tio-send', 'active' => Request::is('admin/provide-deliveryman-earnings*'), 'gate' => Helpers::module_permission_check('provide_dm_earning'), 'hide' => true, 'hide_reason' => '哪吒M1藏(§A#6/#9): 平台无自营骑手·不打款(L1-5打款腿已拔),provide_dm_earning恒0'],
        ],
    ),
];
// ---- TaxModule插件块 + 订阅管理块(均嵌套在settings闸内,原样字面blade,插入⑧系统组尾部;真实环境TaxModule addon已发布会渲染) ----
$__navGroups[] = ['raw_include' => 'layouts.admin.partials._sidebar-raw-taxmodule-subscription'];

?>
<div id="sidebarMain" class="d-none">
    <aside
        class="js-navbar-vertical-aside navbar navbar-vertical-aside navbar-vertical navbar-vertical-fixed navbar-expand-xl navbar-bordered  ">
        <div class="navbar-vertical-container">
            <div class="navbar__brand-wrapper navbar-brand-wrapper justify-content-between">
                <!-- Logo -->
                @php($restaurant_logo = \App\CentralLogics\Helpers::getSettingsDataFromConfig(settings: 'logo', relations: ['storage']))
                <a class="navbar-brand d-block p-0" href="{{ route('admin.dashboard') }}" aria-label="Front">
                    <img class="navbar-brand-logo sidebar--logo-design"
                        src="{{ Helpers::get_full_url('business', $restaurant_logo?->value, $restaurant_logo?->storage[0]?->value ?? 'public', 'favicon') }}"
                        alt="image">
                    <img class="navbar-brand-logo-mini sidebar--logo-design-2"
                        src="{{ Helpers::get_full_url('business', $restaurant_logo?->value, $restaurant_logo?->storage[0]?->value ?? 'public', 'favicon') }}"
                        alt="image">
                </a>
                <!-- End Logo -->

                <!-- Navbar Vertical Toggle -->
                <button type="button"
                    class="js-navbar-vertical-aside-toggle-invoker navbar-vertical-aside-toggle btn btn-icon btn-xs btn-ghost-dark">
                    <i class="tio-clear tio-lg"></i>
                </button>
                <!-- End Navbar Vertical Toggle -->

                <div class="navbar-nav-wrap-content-left d-none d-xl-block">
                    <!-- Navbar Vertical Toggle -->
                    <button type="button" class="js-navbar-vertical-aside-toggle-invoker close">
                        <i class="tio-first-page navbar-vertical-aside-toggle-short-align" data-toggle="tooltip"
                            data-placement="right" title="Collapse"></i>
                        <i class="tio-last-page navbar-vertical-aside-toggle-full-align"
                            data-template='<div class="tooltip d-none" role="tooltip"><div class="arrow"></div><div class="tooltip-inner"></div></div>'
                            data-toggle="tooltip" data-placement="right" title="Expand"></i>
                    </button>
                    <!-- End Navbar Vertical Toggle -->
                </div>

            </div>

            <!-- Content -->
            <div class="navbar-vertical-content bg-334257" id="navbar-vertical-content">
                <!-- Search Form -->
                <form class="sidebar--search-form" autocomplete="off">
                    <input autocomplete="false" name="hidden" type="text" class="d-none">
                    <div class="search--form-group">
                        <button type="button" class="btn"><i class="tio-search"></i></button>
                        <input type="text" id="search" class="form-control form--control"
                            placeholder="过滤菜单…">
                    </div>
                </form>
                <!-- Search Form -->
                <ul class="navbar-nav navbar-nav-lg nav-tabs mt-3">
                @foreach ($__navGroups as $__group)
                    @if (!empty($__group['raw_include']))
                        @include($__group['raw_include'])
                        @continue
                    @endif
                    @if ($__group['gate'])
                        @if (!is_null($__group['subtitle']))
                            <li class="nav-item">
                                <small class="nav-subtitle"@if (!empty($__group['subtitle_title'])) title="{{ translate($__group['subtitle_title']) }}"@endif>{{ !empty($__group['subtitle_raw']) ? $__group['subtitle'] : translate($__group['subtitle']) }}</small>
                                <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                            </li>
                        @endif
                    @endif
                    @foreach ($__group['items'] as $__item)
                        @include('layouts.admin.partials._sidebar-item', ['item' => $__item, 'depth' => 0, 'groupGate' => $__group['gate']])
                    @endforeach
                @endforeach

{{-- ===== 以下为哪吒M1原样保留的已死/插件态代码块(逐字节照抄自原文件,不迁移入配置数组; 详见D1提交说明) ===== --}}
{{-- 订单退款(StackFood旧退款申请列表,已停用) 原文件388-422行 --}}
                        @if(false){{-- 哪吒隐藏: StackFood 退款申请列表已停用(refund_active_status 已关+顾客端入口已删, 再造不出 refund_requested); 路由/页面仍在, 恢复即删 @if(false)/@endif --}}
<li
                            class="navbar-vertical-aside-has-menu {{ Request::is('admin/refund/*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:"
                                title="{{ translate('messages.Order_Refunds') }}">
                                <i class="tio-receipt nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('messages.Order_Refunds') }}
                                </span>
                            </a>
                            <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                style="display: {{ Request::is('admin/refund*') ? 'block' : 'none' }}">
                                <li
                                    class="nav-item {{ Request::is('admin/refund/requested') ||
                                    Request::is('admin/refund/refunded') ||
                                    Request::is('admin/refund/rejected')
                                        ? 'active'
                                        : '' }}">
                                    <a class="nav-link "
                                        href="{{ route('admin.refund.refund_attr', ['requested']) }}"
                                        title="{{ translate('messages.New_Refund_Requests') }} ">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="text-truncate sidebar--badge-container">
                                            {{ translate('messages.New_Refund_Requests') }}
                                            <span class="badge badge-soft-danger badge-pill ml-1">
                                                {{-- {{ \App\Models\Order::Refund_requested()->count() }}= --}}
                                                {{ $order->refund_requested }}
                                            </span>
                                        </span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                        @endif
                        <!-- Order refund End-->
{{-- 骑手管理整组(已死) 原文件1178-1305行 --}}
                    <!-- DeliveryMan -->
                    {{-- 哪吒B方案隐藏「骑手管理」全块(管理/列表/评价): 平台无自营骑手,配送由商家手动叫Yandex(见 yandex-delivery-bridge)。StackFood残留菜单。恢复把 false 改回即可;路由未动 --}}
                    @if (false && Helpers::module_permission_check('deliveryman'))
                        <li class="nav-item">
                            <small class="nav-subtitle"
                                title="{{ translate('messages.deliveryman_section') }}">{{ translate('messages.deliveryman_management') }}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>


                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('admin/vehicle/*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                                href="{{ route('admin.vehicle.list') }}"
                                title="{{ translate('messages.vehicles_category_setup') }}">
                                <i class="tio-car nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('messages.vehicles_category_setup') }}
                                </span>
                            </a>
                        </li>
                        <li class="navbar-vertical-aside-has-menu {{ Request::is('admin/shift*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                                href="{{ route('admin.shift.list') }}"
                                title="{{ translate('messages.Shift_setup') }}">
                                <i class="tio-calendar nav-icon"></i>
                                <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                    {{ translate('messages.Shift_setup') }}
                                </span>
                            </a>
                        </li>

                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('admin/delivery-man*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:"
                                title="{{ translate('messages.deliveryman') }}">
                                <i class="tio-running nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{ translate('messages.deliveryman') }}</span>
                            </a>

                            <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                style="display: {{ Request::is('admin/delivery-man*') ? 'block' : 'none' }}">

                                <li
                                    class="navbar-vertical-aside-has-menu {{ Request::is('admin/delivery-man/pending-delivery-man-view/*') || Request::is('admin/delivery-man/pending/list') || Request::is('admin/delivery-man/denied/list') ? 'active' : '' }}">
                                    <a class="js-navbar-vertical-aside-menu-link nav-link"
                                        href="{{ route('admin.delivery-man.pending') }}"
                                        title="{{ translate('messages.New_joining_request') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                            {{ translate('messages.New_join_request') }}
                                        </span>
                                    </a>
                                </li>


                                <li class="nav-item {{ Request::is('admin/delivery-man/add') ? 'active' : '' }}">
                                    <a class="nav-link" href="{{ route('admin.delivery-man.add') }}"
                                        title="{{ translate('messages.add_delivery_man') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                            {{ translate('messages.add_new_deliveryman') }}
                                        </span>
                                    </a>
                                </li>

                                <li
                                    class="navbar-vertical-aside-has-menu {{ Request::is('admin/delivery-man/edit/*') || Request::is('admin/delivery-man/list') || Request::is('admin/delivery-man/preview/*') ? 'active' : '' }}">
                                    <a class="js-navbar-vertical-aside-menu-link nav-link"
                                        href="{{ route('admin.delivery-man.list') }}"
                                        title="{{ translate('messages.deliveryman_list') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                            {{ translate('messages.deliveryman_list') }}
                                        </span>
                                    </a>
                                </li>

                                <li
                                    class="navbar-vertical-aside-has-menu {{ Request::is('admin/delivery-man/reviews/list') ? 'active' : '' }}">
                                    <a class="js-navbar-vertical-aside-menu-link nav-link"
                                        href="{{ route('admin.delivery-man.reviews.list') }}"
                                        title="{{ translate('messages.reviews') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                            {{ translate('messages.Deliveryman_Reviews') }}
                                        </span>
                                    </a>
                                </li>

                                <li
                                    class="navbar-vertical-aside-has-menu {{ Request::is('admin/delivery-man/bonus') ? 'active' : '' }}">
                                    <a class="js-navbar-vertical-aside-menu-link nav-link"
                                        href="{{ route('admin.delivery-man.bonus') }}"
                                        title="{{ translate('messages.bonus') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span
                                            class="text-truncate text-capitalize">{{ translate('messages.bonus') }}</span>
                                    </a>
                                </li>


                                <li
                                    class="navbar-vertical-aside-has-menu {{ Request::is('admin/delivery-man/incentive') ? 'active' : '' }}">
                                    <a class="js-navbar-vertical-aside-menu-link nav-link"
                                        href="{{ route('admin.delivery-man.incentive') }}"
                                        title=" {{ translate('messages.incentive_Requests') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                            {{ translate('messages.incentive_Requests') }}
                                        </span>
                                    </a>
                                </li>
                                <li
                                    class="navbar-vertical-aside-has-menu {{ Request::is('admin/delivery-man/incentive-history') ? 'active' : '' }}">
                                    <a class="js-navbar-vertical-aside-menu-link nav-link"
                                        href="{{ route('admin.delivery-man.incentive-history') }}"
                                        title="{{ translate('messages.incentives_history') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">
                                            {{ translate('messages.incentives_history') }}</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    @endif
                    <!-- End DeliveryMan -->
{{-- 分账/打款管理整组(已死) 原文件1312-1349行 --}}

                    {{-- 哪吒B方案隐藏「分账/打款管理」全块(商家结算/骑手结算/分账报表): 平台不向商家/骑手打款(L1-1/L1-5,打款腿已拔),Disbursement恒空。StackFood残留菜单。恢复把 false 改回即可;路由未动 --}}
                    @if (false && Helpers::module_permission_check('disbursement'))
                        <li class="nav-item">
                            <small class="nav-subtitle"
                                title="{{ translate('messages.business_section') }}">{{ translate('messages.disbursement_management') }}</small>
                            <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                        </li>
                        <!-- disbursement -->
                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('admin/restaurant-disbursement*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                                href="{{ route('admin.restaurant-disbursement.list', ['status' => 'all']) }}"
                                title="{{ translate('messages.restaurant_disbursement') }}">
                                <i class="tio-wallet-outlined nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{ translate('messages.Restaurant_Disbursement') }}
                                    <span class="badge badge-soft-info badge-pill ml-1">
                                        {{ \App\Models\Disbursement::where('created_for', 'restaurant')->count() }}
                                    </span></span>

                            </a>
                        </li>

                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('admin/dm-disbursement*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                                href="{{ route('admin.dm-disbursement.list', ['status' => 'all']) }}"
                                title="{{ translate('messages.dm_disbursement') }}">
                                <i class="tio-saving-outlined nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{ translate('messages.Deliveryman_Disbursement') }}
                                    <span class="badge badge-soft-info badge-pill ml-1">
                                        {{ \App\Models\Disbursement::where('created_for', 'delivery_man')->count() }}
                                    </span></span>
                            </a>
                        </li>
                    @endif
{{-- 顾客钱包(加款/返现,已死) 原文件1100-1137行 --}}
                    {{-- 哪吒B方案隐藏「顾客钱包」(加款/返现): 平台不持币·不给顾客钱包充值(L1-1 平台不碰钱),StackFood残留菜单。恢复把 false 改回即可;路由 admin.customer.wallet.* 未动 --}}
                    @if (false && Helpers::module_permission_check('customer_wallet'))
                        <li
                            class="navbar-vertical-aside-has-menu {{ !Request::is('admin/customer/wallet/report*') && Request::is('admin/customer/wallet*') ? 'active' : '' }}">

                            <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:"
                                title="{{ translate('messages.Customer_Wallet') }}">
                                <i class="tio-wallet nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate  text-capitalize">
                                    {{ translate('messages.wallet') }}
                                </span>
                            </a>

                            <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                                style="display: {{ !Request::is('admin/customer/wallet/report*') && Request::is('admin/customer/wallet*') ? 'block' : 'none' }}">
                                <li
                                    class="nav-item {{ Request::is('admin/customer/wallet/add-fund') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('admin.customer.wallet.add-fund') }}"
                                        title="{{ translate('messages.add_fund') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span
                                            class="text-truncate text-capitalize">{{ translate('messages.add_fund') }}</span>
                                    </a>
                                </li>
                                <li
                                    class="nav-item {{ Request::is('admin/customer/wallet/bonus*') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('admin.customer.wallet.bonus.add-new') }}"
                                        title="{{ translate('messages.bonus') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span
                                            class="text-truncate text-capitalize">{{ translate('messages.bonus') }}</span>
                                    </a>
                                </li>

                            </ul>
                        </li>
                    @endif
{{-- 顾客钱包报表(已死) 原文件1510-1521行 --}}
                                {{-- 哪吒B方案隐藏「顾客钱包报表」: 平台不持顾客钱包(L1-1)。恢复删掉本 @if(false)/@endif 包裹即可;路由未动 --}}
                                @if (false)
                                <li
                                    class="nav-item {{ Request::is('admin/customer/wallet/report*') ? 'active' : '' }}">
                                    <a class="nav-link " href="{{ route('admin.customer.wallet.report') }}"
                                        title="{{ translate('messages.Customer_Wallet_Report') }}">
                                        <span class="tio-circle nav-indicator-icon"></span>
                                        <span
                                            class="text-truncate text-capitalize">{{ translate('messages.Customer_Wallet_Report') }}</span>
                                    </a>
                                </li>
                                @endif
{{-- 骑手收入报表(已死) 原文件1560-1570行 --}}
                        {{-- 哪吒B方案隐藏「骑手收入报表」: 无自营骑手(配送走Yandex)。恢复删掉本 @if(false)/@endif 包裹即可;路由未动 --}}
                        @if (false)
                        <li class="navbar-vertical-aside-has-menu @yield('deliveryman_earning_report') {{ Request::is('admin/report/deliveryman-earning-report*') ? 'active' : '' }}">
                            <a class="nav-link " href="{{ route('admin.report.deliveryman-earning-report') }}"
                                title="{{ translate('Deliveryman_Earning_Report') }}">
                                <span class="tio-artboard nav-icon"></span>
                                <span
                                    class="text-truncate text-capitalize">{{ translate('Deliveryman_Earning_Report') }}</span>
                            </a>
                        </li>
                        @endif
{{-- 提现列表(已死) 原文件1651-1663行 --}}
                    {{-- 哪吒 B方案隐藏「提现列表」: 平台不向商家打款(INVARIANTS L1-1/L1-5,提现/打款腿已拔除),withdraw_requests 恒空且审批不触发真实打款,此为StackFood残留菜单,避免误导运营。恢复直付台账时把 false 改回即可;路由 admin.restaurant.withdraw_list 未动 --}}
                    @if (false && Helpers::module_permission_check('withdraw_list'))
                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('admin/restaurant/withdraw*') ||Request::is('admin/restaurant/withdraw-view*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                                href="{{ route('admin.restaurant.withdraw_list') }}"
                                title="{{ translate('messages.restaurant_withdraws') }}">
                                <i class="tio-table nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{ translate('messages.restaurant_withdraws') }}</span>
                            </a>
                        </li>
                    @endif
{{-- 提现方式(已死) 原文件1679-1691行 --}}
                    {{-- 哪吒 B方案隐藏「提现方式」: 同上,平台不打款,提现方式无实义。路由 admin.business-settings.withdraw-method.list 未动 --}}
                    @if (false && Helpers::module_permission_check('withdraw_list'))
                        <li
                            class="navbar-vertical-aside-has-menu {{ Request::is('admin/withdraw-method*') ? 'active' : '' }}">
                            <a class="js-navbar-vertical-aside-menu-link nav-link"
                                href="{{ route('admin.business-settings.withdraw-method.list') }}"
                                title="{{ translate('messages.withdraw_method') }}">
                                <i class="tio-savings nav-icon"></i>
                                <span
                                    class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{ translate('messages.withdraw_method') }}</span>
                            </a>
                        </li>
                    @endif
                {{-- 防御: 某个插件 admin_routes.php 返回非数组(string等)时不应崩掉整个后台侧边栏 --}}
                @php($addonRoutes = is_array(config('addon_admin_routes')) ? config('addon_admin_routes') : [])
                @if (count($addonRoutes) > 0 && Helpers::module_permission_check('system_addon'))
                    <li class="nav-item">
                        <small class="nav-subtitle">{{ translate('messages.addon_menus') }}</small>
                        <small class="tio-more-horizontal nav-subtitle-replacer"></small>
                    </li>
                    <li
                        class="navbar-vertical-aside-has-menu {{ Request::is('admin/payment/configuration/*') || Request::is('admin/sms/configuration/*') ? 'active' : '' }}">
                        <a class="js-navbar-vertical-aside-menu-link nav-link nav-link-toggle" href="javascript:">
                            <i class="tio-puzzle nav-icon"></i>
                            <span
                                class="navbar-vertical-aside-mini-mode-hidden-elements text-truncate">{{ translate('Addon_Menus') }}</span>
                        </a>
                        <ul class="js-navbar-vertical-aside-submenu nav nav-sub"
                            style="display: {{ Request::is('admin/payment/configuration/*') || Request::is('admin/sms/configuration/*') ? 'block' : 'none' }}">
                            @foreach ($addonRoutes as $routes)
                                @foreach ((is_array($routes) ? $routes : []) as $route)
                                    @continue(!is_array($route) || !isset($route['path'], $route['url'], $route['name']))
                                    <li
                                        class="navbar-vertical-aside-has-menu {{ Request::is($route['path']) ? 'active' : '' }}">
                                        <a class="js-navbar-vertical-aside-menu-link nav-link "
                                            href="{{ $route['url'] }}" title="{{ translate($route['name']) }}">
                                            <span class="tio-circle nav-indicator-icon"></span>
                                            <span class="text-truncate">{{ translate($route['name']) }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                    </li>
                @endif
                <!-- End Business Settings -->




                <!--addon end-->

                <li class="nav-item pt-100px">

                </li>
                </ul>
            </div>
            <!-- End Content -->
        </div>
    </aside>
</div>

<div id="sidebarCompact" class="d-none">

</div>
