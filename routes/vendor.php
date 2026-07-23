<?php

use App\Http\Controllers\Vendor\AddOnController;
use App\Http\Controllers\Vendor\AdvertisementController;
use App\Http\Controllers\Vendor\NezhaTieredDiscountController;
use App\Http\Controllers\Vendor\NezhaDeliveryWindowController;
use App\Http\Controllers\Vendor\BusinessSettingsController;
use App\Http\Controllers\Vendor\CampaignController;
use App\Http\Controllers\Vendor\CategoryController;
use App\Http\Controllers\Vendor\ConversationController;
use App\Http\Controllers\Vendor\CouponController;
use App\Http\Controllers\Vendor\CustomRoleController;
use App\Http\Controllers\Vendor\DashboardController;
use App\Http\Controllers\Vendor\DeliveryManController;
use App\Http\Controllers\Vendor\EmployeeController;
use App\Http\Controllers\Vendor\FoodController;
use App\Http\Controllers\Vendor\LanguageController;
use App\Http\Controllers\Vendor\OrderController;
use App\Http\Controllers\Vendor\WorkbenchController;
use App\Http\Controllers\Vendor\OrderSubscriptionController;
use App\Http\Controllers\Vendor\POSController;
use App\Http\Controllers\Vendor\ProfileController;
use App\Http\Controllers\Vendor\ReportController;
use App\Http\Controllers\Vendor\RestaurantEarningReportController;
use App\Http\Controllers\Vendor\RestaurantController;
use App\Http\Controllers\Vendor\ReviewController;
use App\Http\Controllers\Vendor\SearchRoutingController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Vendor\SubscriptionController;
use App\Http\Controllers\Vendor\VendorTaxReportController;
use App\Http\Controllers\Vendor\WalletController;
use App\Http\Controllers\Vendor\NezhaDepositController;
use App\Http\Controllers\Vendor\NezhaTopupController;
use App\Http\Controllers\Vendor\NezhaConsolidationController;
use App\Http\Controllers\Vendor\NezhaConsolidationRoundController;
use App\Http\Controllers\Vendor\WalletMethodController;
use App\Http\Controllers\Vendor\NezhaPaymentAddressChangeController;

Route::group(['namespace' => 'Vendor', 'as' => 'vendor.'], function () {
    Route::group(['middleware' => ['vendor' ,'maintenance','actch:admin_panel']], function () {
        // 哪吒(QA): vendor 订单页地图画配送区边界需 zone 坐标; 复用 admin ZoneController::get_coordinates(zone 边界非敏感),
        // 原 vendor blade 直调 /admin/zone/... 被 admin 中间件 401(实测商家详情 console 报错)。
        Route::get('zone/get-coordinates/{id}', [\App\Http\Controllers\Admin\ZoneController::class, 'get_coordinates'])->name('zone.get-coordinates');

        Route::get('lang/{locale}', [LanguageController::class, 'lang'])->name('lang');

        Route::post('search-routing', [SearchRoutingController::class, 'index'])->name('search.routing');
        Route::get('recent-search', [SearchRoutingController::class, 'recentSearch'])->name('recent.search');
        Route::post('store-clicked-route', [SearchRoutingController::class, 'storeClickedRoute'])->name('store.clicked.route');


        Route::get('/', [DashboardController::class, 'dashboard'])->name('dashboard');
        Route::get('/get-restaurant-data', [DashboardController::class, 'restaurant_data'])->name('get-restaurant-data');
        Route::post('/store-token', [DashboardController::class, 'updateDeviceToken'])->name('store.token');
        // 哪吒商家版App: FCM报警token注册/注销(多设备表), 由App WebView登录后调用
        Route::post('/nezha-alarm-token/register', [\App\Http\Controllers\Vendor\NezhaAlarmTokenController::class, 'register'])->name('nezha-alarm-token.register');
        Route::post('/nezha-alarm-token/deregister', [\App\Http\Controllers\Vendor\NezhaAlarmTokenController::class, 'deregister'])->name('nezha-alarm-token.deregister');
        Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews')->middleware(['module:reviews' ,'subscription:reviews']);
        Route::post('/store-reply/{id}', [ReviewController::class, 'update_reply'])->name('review-reply')->middleware(['module:reviews' ,'subscription:reviews']);

        // 哪吒 商家助手 (AI)
        Route::get('nezha-assistant', [\App\Http\Controllers\Vendor\NezhaAssistantController::class, 'index'])->name('nezha-assistant.index');
        Route::post('nezha-assistant/ask', [\App\Http\Controllers\Vendor\NezhaAssistantController::class, 'ask'])->name('nezha-assistant.ask');
        Route::post('nezha-assistant/stream', [\App\Http\Controllers\Vendor\NezhaAssistantController::class, 'stream'])->name('nezha-assistant.stream');
        Route::get('nezha-assistant/history', [\App\Http\Controllers\Vendor\NezhaAssistantController::class, 'history'])->name('nezha-assistant.history'); // UX1-E 只读分页(查看更早)


        Route::group(['prefix' => 'pos', 'as' => 'pos.'], function () {
            Route::post('variant_price', [POSController::class, 'variant_price'])->name('variant_price');
            Route::group(['middleware' => ['module:pos','subscription:pos']], function () {
                Route::get('/', [POSController::class, 'index'])->name('index');
                Route::get('quick-view', [POSController::class, 'quick_view'])->name('quick-view');
                Route::get('quick-view-cart-item', [POSController::class, 'quick_view_card_item'])->name('quick-view-cart-item');
                Route::post('add-to-cart', [POSController::class, 'addToCart'])->name('add-to-cart');
                Route::post('add-delivery-info', [POSController::class, 'addDeliveryInfo'])->name('add-delivery-info');
                Route::post('remove-from-cart', [POSController::class, 'removeFromCart'])->name('remove-from-cart');
                Route::post('cart-items', [POSController::class, 'cart_items'])->name('cart_items');
                Route::get('get-delivery-types', [POSController::class, 'getDeliveryTypes'])->name('get-delivery-types');
                Route::get('set-delivery-type', [POSController::class, 'setDeliveryType'])->name('set-delivery-type');
                Route::post('update-quantity', [POSController::class, 'updateQuantity'])->name('updateQuantity');
                Route::post('empty-cart', [POSController::class, 'emptyCart'])->name('emptyCart');
                Route::post('tax', [POSController::class, 'update_tax'])->name('tax');
                Route::post('paid', [POSController::class, 'update_paid'])->name('paid');
                Route::post('discount', [POSController::class, 'update_discount'])->name('discount');
                Route::get('customers', [POSController::class, 'get_customers'])->name('customers');
                Route::post('order', [POSController::class, 'place_order'])->name('order');
                Route::get('orders', [POSController::class, 'order_list'])->name('orders');
                Route::post('search', [POSController::class, 'search'])->name('search');
                Route::get('order-details/{id}', [POSController::class, 'order_details'])->name('order-details');
                Route::get('invoice/{id}', [POSController::class, 'generate_invoice']);
                Route::post('customer-store', [POSController::class, 'customer_store'])->name('customer-store');
                Route::get('data', [POSController::class, 'extra_charge'])->name('extra_charge');

                Route::get('get-user-data', [POSController::class, 'getUserData'])->name('getUserData');
                Route::get('get-user-address', [POSController::class, 'getUserAddress'])->name('getUserAddress');
                Route::get('choose-address', [POSController::class, 'chooseAddress'])->name('chooseAddress');
                Route::get('edit-address', [POSController::class, 'editAddress'])->name('editAddress');
                Route::get('clear-user-data', [POSController::class, 'clearUserData'])->name('clearUserData');
                Route::get('set-order-type', [POSController::class, 'setOrderType'])->name('setOrderType');
            });
        });

        Route::group(['prefix' => 'advertisement', 'as' => 'advertisement.', 'middleware' => ['subscription:advertisement']], function () {

            Route::group(['middleware' => ['module:ads_list']], function () {
                Route::get('/', [AdvertisementController::class, 'index'])->name('index');
                Route::get('details/{advertisement}', [AdvertisementController::class, 'show'])->name('show');
                Route::get('{advertisement}/edit', [AdvertisementController::class, 'edit'])->name('edit');
                Route::put('update/{advertisement}', [AdvertisementController::class, 'update'])->name('update');
                Route::delete('delete/{id}', [AdvertisementController::class, 'destroy'])->name('destroy');
                Route::get('/status', [AdvertisementController::class, 'status'])->name('status');
                // [哪吒广告竞价 Slice B] 商家竞价推广 3 旋钮面板
                Route::get('/promotion', [AdvertisementController::class, 'promotion'])->name('promotion');
                Route::post('/promotion', [AdvertisementController::class, 'savePromotion'])->name('promotion.save');
            });

            Route::group(['middleware' => ['module:new_ads']], function () {
                Route::get('create/', [AdvertisementController::class, 'create'])->name('create');
                Route::post('store', [AdvertisementController::class, 'store'])->name('store');
                Route::get('/copy-advertisement/{advertisement}', [AdvertisementController::class, 'copyAdd'])->name('copyAdd');
                Route::post('/copy-add-post/{advertisement}', [AdvertisementController::class, 'copyAddPost'])->name('copyAddPost');
            });

        });



        // 哪吒作业台(今天): W1 summary 惰性只读接口(未接线, 上线零行为变化)。
        // 复用 NezhaOrderCounts / NezhaOrderNextAction / NezhaBadReview 单一真相源; 仅父级 vendor 鉴权(与 dashboard 同级, 不加 module 闸, 防作为落地首页时误挡员工)。
        Route::group(['prefix' => 'workbench', 'as' => 'workbench.'], function () {
            Route::get('/', [WorkbenchController::class, 'index'])->name('index');
            Route::get('summary', [WorkbenchController::class, 'summary'])->name('summary');
            // W4: 可刷新分区 HTML 片段(并入全局 6s 心跳, 不另开轮询)。只读, 与 index/summary 同 buildSummary 契约。
            Route::get('refresh', [WorkbenchController::class, 'refresh'])->name('refresh');
            // 哪吒 P3 接单机模式: 图文指引页(纯静态说明)。
            Route::get('guide', [WorkbenchController::class, 'guide'])->name('guide');
            // 哪吒 自动下线: 商家「恢复接单」一键自助(POST·清本店 nezha_auto_offline 标记·仅本店作用域)。
            Route::post('autooffline-recover', [WorkbenchController::class, 'recoverAutoOffline'])->name('autooffline-recover');
        });

        Route::group(['prefix' => 'dashboard', 'as' => 'dashboard.'], function () {
            Route::post('order-stats', [DashboardController::class, 'order_stats'])->name('order-stats');
        });

        Route::group(['prefix' => 'category', 'as' => 'category.', 'middleware' => ['module:category','subscription:food']], function () {
            Route::get('get-all', [CategoryController::class, 'get_all'])->name('get-all');
            Route::get('list', [CategoryController::class, 'index'])->name('add');
            Route::get('sub-category-list', [CategoryController::class, 'sub_index'])->name('add-sub-category');
        });

        Route::group(['prefix' => 'custom-role', 'as' => 'custom-role.', 'middleware' => ['module:role_management','subscription:custom_role']], function () {
            Route::get('create', [CustomRoleController::class, 'create'])->name('create');
            Route::post('create', [CustomRoleController::class, 'store'])->name('store');
            Route::get('edit/{id}', [CustomRoleController::class, 'edit'])->name('edit');
            Route::post('update/{id}', [CustomRoleController::class, 'update'])->name('update');
            Route::delete('delete/{id}', [CustomRoleController::class, 'destroy'])->name('delete');
        });

        Route::group(['prefix' => 'delivery-man', 'as' => 'delivery-man.', 'middleware' => ['module:deliveryman','subscription:deliveryman']], function () {
            Route::get('add', [DeliveryManController::class, 'index'])->name('add');
            Route::post('store', [DeliveryManController::class, 'store'])->name('store');
            Route::get('list', [DeliveryManController::class, 'list'])->name('list');
            Route::get('preview/{id}/{tab?}', [DeliveryManController::class, 'preview'])->name('preview');
            Route::get('status/{id}/{status}', [DeliveryManController::class, 'status'])->name('status');
            Route::get('earning/{id}/{status}', [DeliveryManController::class, 'earning'])->name('earning');
            Route::get('edit/{id}', [DeliveryManController::class, 'edit'])->name('edit');
            Route::post('update/{id}', [DeliveryManController::class, 'update'])->name('update');
            Route::delete('delete/{id}', [DeliveryManController::class, 'delete'])->name('delete');
            Route::get('get-deliverymen', [DeliveryManController::class, 'get_deliverymen'])->name('get-deliverymen');

            Route::group(['prefix' => 'reviews', 'as' => 'reviews.'], function () {
                Route::get('list', [DeliveryManController::class, 'reviews_list'])->name('list');
            });
        });

        Route::group(['prefix' => 'employee', 'as' => 'employee.', 'middleware' => ['module:all_employee','subscription:employee']], function () {
            Route::get('add-new', [EmployeeController::class, 'add_new'])->name('add-new');
            Route::post('add-new', [EmployeeController::class, 'store']);
            Route::get('list', [EmployeeController::class, 'list'])->name('list');
            Route::get('list-export', [EmployeeController::class, 'list_export'])->name('export-employee');
            Route::get('edit/{id}', [EmployeeController::class, 'edit'])->name('edit');
            Route::post('update/{id}', [EmployeeController::class, 'update'])->name('update');
            Route::delete('delete/{id}', [EmployeeController::class, 'destroy'])->name('delete');
            Route::post('search', [EmployeeController::class, 'search'])->name('search');
        });

        Route::post('food/food-variation-generate', [FoodController::class, 'food_variation_generator'])->name('food.food-variation-generate');
        Route::group(['prefix' => 'food', 'as' => 'food.', 'middleware' => ['module:food','subscription:food']], function () {
            Route::get('add-new', [FoodController::class, 'index'])->name('add-new');
            Route::post('store', [FoodController::class, 'store'])->name('store');
            Route::get('edit/{id}', [FoodController::class, 'edit'])->name('edit');
            Route::post('update/{id}', [FoodController::class, 'update'])->name('update');
            Route::get('list', [FoodController::class, 'list'])->name('list');
            Route::delete('delete/{id}', [FoodController::class, 'delete'])->name('delete');
            Route::get('status/{id}/{status}', [FoodController::class, 'status'])->name('status');
            Route::get('recommended/{id}/{status}', [FoodController::class, 'recommended'])->name('recommended');
            Route::get('sold-out/{id}/{flag}', [FoodController::class, 'soldOut'])->name('sold-out');
            Route::get('sort', [FoodController::class, 'sortIndex'])->name('sort');
            Route::post('sort-save', [FoodController::class, 'sortSave'])->name('sort-save');
            Route::post('category-sort-save', [FoodController::class, 'categorySortSave'])->name('category-sort-save');
            Route::post('search', [FoodController::class, 'search'])->name('search');
            Route::get('view/{id}', [FoodController::class, 'view'])->name('view');
            Route::get('get-categories', [FoodController::class, 'get_categories'])->name('get-categories');
            Route::get('out-of-stock-list', [FoodController::class, 'stockOutList'])->name('stockOutList');
            Route::post('update-stock', [FoodController::class, 'updateStock'])->name('updateStock');
            Route::post('update-price', [FoodController::class, 'updatePrice'])->name('updatePrice');
            // 哪吒[菜品批量操作]: 全选后批量上下架/改价/改分类/删除 (RestaurantScope 限本店防越权)
            Route::post('bulk-status', [FoodController::class, 'bulkStatus'])->name('bulk-status');
            Route::post('bulk-price', [FoodController::class, 'bulkPrice'])->name('bulk-price');
            Route::post('bulk-category', [FoodController::class, 'bulkCategory'])->name('bulk-category');
            Route::post('bulk-delete', [FoodController::class, 'bulkDelete'])->name('bulk-delete');
            Route::post('/add-to-session', [FoodController::class, 'addToSession'])->name('addToSession');
            Route::get('export', [FoodController::class, 'export'])->name('export');


            //Import and export
            Route::get('bulk-import', [FoodController::class, 'bulk_import_index'])->name('bulk-import');
            Route::post('bulk-import', [FoodController::class, 'bulk_import_data']);
            Route::get('bulk-export', [FoodController::class, 'bulk_export_index'])->name('bulk-export-index');
            Route::post('bulk-export', [FoodController::class, 'bulk_export_data'])->name('bulk-export');
        });


        Route::group(['prefix' => 'campaign', 'as' => 'campaign.', 'middleware' => ['module:campaign','subscription:campaign']], function () {
            Route::get('list', [CampaignController::class, 'list'])->name('list');
            Route::get('item/list', [CampaignController::class, 'itemlist'])->name('itemlist');
            Route::get('remove-restaurant/{campaign}/{restaurant}', [CampaignController::class, 'remove_restaurant'])->name('remove-restaurant');
            Route::get('add-restaurant/{campaign}/{restaurant}', [CampaignController::class, 'addrestaurant'])->name('addrestaurant');
            Route::get('status/{id}', [CampaignController::class, 'status'])->name('status');
            Route::get('/view/{campaign}', [CampaignController::class, 'view'])->name('view');
        });

        Route::group(['prefix' => 'wallet', 'as' => 'wallet.', 'middleware' => ['module:my_wallet','subscription:wallet']], function () {
            Route::get('/', [WalletController::class, 'index'])->name('index');
            Route::post('request', [WalletController::class, 'w_request'])->name('withdraw-request');
            Route::delete('close/{id}', [WalletController::class, 'close_request'])->name('close-request');
            Route::get('method-list', [WalletController::class, 'method_list'])->name('method-list');
            Route::post('make-collected-cash-payment', [WalletController::class, 'make_payment'])->name('make_payment');
            Route::post('make-wallet-adjustment', [WalletController::class, 'make_wallet_adjustment'])->name('make_wallet_adjustment');

            Route::get('wallet-payment-list', [WalletController::class, 'wallet_payment_list'])->name('wallet_payment_list');
            Route::get('disbursement-list', [WalletController::class, 'getDisbursementList'])->name('getDisbursementList');
            Route::get('export', [WalletController::class, 'getDisbursementExport'])->name('export');

        });

        // 哪吒 B方案 组4: 商家预存佣金余额 + 低额邮件告警自助设置
        Route::group(['prefix' => 'nezha-deposit', 'as' => 'nezha-deposit.', 'middleware' => ['module:nezha_deposit']], function () {
            Route::get('/', [NezhaDepositController::class, 'index'])->name('index');
            Route::post('update-alert', [NezhaDepositController::class, 'update_alert'])->name('update-alert');
            Route::get('export', [NezhaDepositController::class, 'export'])->name('export');
            // 哪吒 退出平台(step4-4): 商家申请/撤回退出(服务端强制开关 nezha_offboard_status)
            Route::post('offboard-apply', [NezhaDepositController::class, 'offboardApply'])->name('offboard-apply');
            Route::post('offboard-withdraw', [NezhaDepositController::class, 'offboardWithdraw'])->name('offboard-withdraw');
            // 哪吒 自助充值申请(A3 dormant·nezha_topup_status 默认关)
            Route::post('topup-apply', [NezhaTopupController::class, 'topupApply'])->name('topup-apply');
            Route::post('topup-cancel', [NezhaTopupController::class, 'topupCancel'])->name('topup-cancel');
            Route::post('refund-apply', [NezhaTopupController::class, 'refundApply'])->name('refund-apply'); // 押金中途退款申请(S3-B dormant)
        });

        // 哪吒 平台集运申报: 商家需求登记表
        Route::group(['prefix' => 'nezha-consolidation', 'as' => 'nezha-consolidation.'], function () {
            Route::get('/', [NezhaConsolidationController::class, 'index'])->name('index');
            Route::post('store', [NezhaConsolidationController::class, 'store'])->name('store');
            Route::post('dismiss-promo', [NezhaConsolidationController::class, 'dismissPromo'])->name('dismiss-promo'); // A-1 dashboard 提示卡关闭
        });

        // 哪吒 平台集运 · 期次报名(包2·B骨架): 平台按期次发布货代/价格/截止, 商家登记本店预估货量意向。
        // 总闸 NezhaConsolidationRound::enabled() 在控制器内统一门禁(闸关 abort 404, 零透出)。
        Route::group(['prefix' => 'nezha-consolidation-rounds', 'as' => 'nezha-consolidation-rounds.'], function () {
            Route::get('/', [NezhaConsolidationRoundController::class, 'index'])->name('index');
            Route::post('store', [NezhaConsolidationRoundController::class, 'store'])->name('store');
            Route::post('update/{id}', [NezhaConsolidationRoundController::class, 'update'])->name('update')->whereNumber('id');
            Route::post('cancel/{id}', [NezhaConsolidationRoundController::class, 'cancel'])->name('cancel')->whereNumber('id');
        });

Route::group(['prefix' => 'withdraw-method', 'as' => 'wallet-method.', 'middleware' => ['module:wallet_method','subscription:wallet']], function () {
            Route::get('/', [WalletMethodController::class, 'index'])->name('index');
            Route::post('store/', [WalletMethodController::class, 'store'])->name('store');
            Route::get('default/{id}/{default}', [WalletMethodController::class, 'default'])->name('default');
            Route::delete('delete/{id}', [WalletMethodController::class, 'delete'])->name('delete');

            Route::get('edit/{id}', [WalletMethodController::class, 'edit'])->name('edit');
            Route::put('update', [WalletMethodController::class, 'update'])->name('update');
            Route::post('status-update', [WalletMethodController::class, 'status_update'])->name('status-update');
            Route::post('default-status-update', [WalletMethodController::class, 'default_status_update'])->name('default-status-update');

        });

        // 默认关闭；VendorMiddleware 也容纳员工，控制器会再次硬性要求 vendor owner guard。
        Route::group(['prefix' => 'payment-address-change', 'as' => 'payment-address-change.'], function () {
            Route::get('{change}', [NezhaPaymentAddressChangeController::class, 'show'])->name('show');
            Route::post('{change}/confirm', [NezhaPaymentAddressChangeController::class, 'confirm'])->name('confirm');
            Route::post('{change}/reject', [NezhaPaymentAddressChangeController::class, 'reject'])->name('reject');
        });


        Route::group(['prefix' => 'coupon', 'as' => 'coupon.', 'middleware' => ['module:coupon','subscription:coupon']], function () {
            Route::get('add-new', [CouponController::class, 'add_new'])->name('add-new');
            Route::post('store', [CouponController::class, 'store'])->name('store');
            Route::get('update/{id}', [CouponController::class, 'edit'])->name('update');
            Route::post('update/{id}', [CouponController::class, 'update']);
            Route::get('status/{id}/{status}', [CouponController::class, 'status'])->name('status');
            Route::delete('delete/{id}', [CouponController::class, 'delete'])->name('delete');
            Route::post('search', [CouponController::class, 'search'])->name('search');
            Route::get('check-code', [CouponController::class, 'checkCode'])->name('check.code');
            Route::get('view/{coupon}', [CouponController::class, 'view'])->name('view');
            Route::get('coupon-export', [CouponController::class, 'coupon_export'])->name('coupon_export');
        });

        // 哪吒[多级满减] 商家自助配置本店满额自动减(多档)。菜单侧栏按 nezha_tiered_discount_status 门控。
        Route::group(['prefix' => 'nezha-discount', 'as' => 'nezha-discount.'], function () {
            Route::get('/', [NezhaTieredDiscountController::class, 'index'])->name('index');
            Route::post('save', [NezhaTieredDiscountController::class, 'save'])->name('save');
        });

        Route::group(['prefix' => 'addon', 'as' => 'addon.', 'middleware' => ['module:addon','subscription:addon']], function () {
            Route::get('add-new', [AddOnController::class, 'index'])->name('add-new');
            Route::post('store', [AddOnController::class, 'store'])->name('store');
            Route::get('edit/{id}', [AddOnController::class, 'edit'])->name('edit');
            Route::post('update/{id}', [AddOnController::class, 'update'])->name('update');
            Route::delete('delete/{id}', [AddOnController::class, 'delete'])->name('delete');
            Route::get('status/{id}/{status}', [AddOnController::class, 'status'])->name('status');
            Route::get('export-addons', [AddOnController::class, 'export'])->name('export');

        });

        Route::group(['prefix' => 'order', 'as' => 'order.' , 'middleware' => ['module:regular_order']], function () {
            Route::get('list/{status}', [OrderController::class, 'list'])->name('list');
            Route::put('status-update/{id}', [OrderController::class, 'status'])->name('status-update');
            // 哪吒 B方案: 商家填写 Yandex 配送追踪链接 -> 订单进入「配送中」, 顾客端可实时查看
            Route::put('set-yandex-delivery/{id}', [OrderController::class, 'set_yandex_delivery'])->name('set-yandex-delivery');
            // 哪吒 B方案: 商家一拍标记配送中(不需链接, 与贴链接解耦)
            Route::put('mark-dispatched/{id}', [OrderController::class, 'mark_dispatched'])->name('mark-dispatched');
            Route::put('mark-delivered/{id}', [OrderController::class, 'mark_delivered'])->name('mark-delivered');
            // 哪吒效率(2026-06-21): 商家在「备餐中」更新本单预计出餐时间(单笔级, 不改店铺默认)
            Route::put('update-processing-time/{id}', [OrderController::class, 'update_processing_time'])->name('update-processing-time');
            // 哪吒 B方案: 商家自营「确认收款 / 拒收」离线支付
            Route::put('confirm-offline-payment/{id}', [OrderController::class, 'confirm_offline_payment'])->name('confirm-offline-payment');
            Route::put('deny-offline-payment/{id}', [OrderController::class, 'deny_offline_payment'])->name('deny-offline-payment');
            // 哪吒 F-4: 商家「标记已退款」直付单
            Route::put('mark-refunded/{id}', [OrderController::class, 'mark_refunded'])->name('mark-refunded');
            Route::put('refund-dispute/{id}', [OrderController::class, 'dispute_refund'])->name('refund-dispute'); // denied 凭证争议流 R1(dormant·开关 nezha_refund_dispute_status)
            // 哪吒 B方案: 商家主动拒单(仅 pending/confirmed) + 处理顾客取消申请(同意/拒绝)
            Route::put('reject/{id}', [OrderController::class, 'reject_order'])->name('reject');
            Route::put('cancel-request-decision/{id}', [OrderController::class, 'cancel_request_decision'])->name('cancel-request-decision');
            Route::post('search', [OrderController::class, 'search'])->name('search');
            Route::post('add-to-cart', [OrderController::class, 'add_to_cart'])->name('add-to-cart');
            Route::post('remove-from-cart', [OrderController::class, 'remove_from_cart'])->name('remove-from-cart');
            Route::get('update/{order}', [OrderController::class, 'update'])->name('update');
            Route::get('edit-order/{order}', [OrderController::class, 'edit'])->name('edit');
            Route::get('details/{id}', [OrderController::class, 'details'])->name('details')->withoutMiddleware(['module:regular_order']);
            Route::get('status', [OrderController::class, 'status'])->name('status');
            Route::get('quick-view', [OrderController::class, 'quick_view'])->name('quick-view');
            Route::get('quick-view-cart-item', [OrderController::class, 'quick_view_cart_item'])->name('quick-view-cart-item');
            Route::get('generate-invoice/{id}', [OrderController::class, 'generate_invoice'])->name('generate-invoice')->withoutMiddleware(['module:regular_order']);
                Route::get('generate-invoice-batch', [OrderController::class, 'generate_invoice_batch'])->name('generate-invoice-batch')->withoutMiddleware(['module:regular_order']);
            Route::post('add-payment-ref-code/{id}', [OrderController::class, 'add_payment_ref_code'])->name('add-payment-ref-code');

            Route::get('orders-export/{status}', [OrderController::class, 'orders_export'])->name('export');
            Route::post('add-order-proof/{id}', [OrderController::class, 'add_order_proof'])->name('add-order-proof');
            Route::get('remove-proof-image', [OrderController::class, 'remove_proof_image'])->name('remove-proof-image');
            Route::get('add-delivery-man/{order_id}/{delivery_man_id}', [OrderController::class, 'add_delivery_man'])->name('add-delivery-man');
            Route::put('add-dine-in-table-number/{order}', [OrderController::class, 'add_dine_in_table_number'])->name('add_dine_in_table_number');

            //order update
            Route::post('add-to-cart', [OrderController::class, 'add_to_cart'])->name('add-to-cart');
            Route::post('remove-from-cart', [OrderController::class, 'remove_from_cart'])->name('remove-from-cart');
            Route::post('update/{order}', [OrderController::class, 'update'])->name('update');
            Route::get('edit-order/{order}', [OrderController::class, 'edit'])->name('edit');
            Route::get('quick-view', [OrderController::class, 'quick_view'])->name('quick-view');
            Route::get('quick-view-cart-item', [OrderController::class, 'quick_view_cart_item'])->name('quick-view-cart-item');
            Route::get('getSearchedFoods', [OrderController::class, 'getSearchedFoods'])->name('getSearchedFoods');
            Route::post('getSingleFoodPrice', [OrderController::class, 'getSingleFoodPrice'])->name('getSingleFoodPrice');
            Route::post('updateSchedule', [OrderController::class, 'updateSchedule'])->name('updateSchedule');
            Route::post('update-shipping/{order}', [OrderController::class, 'update_shipping'])->name('update-shipping');

        });


        Route::group(['prefix' => 'order','as' => 'order.subscription.', 'middleware' => ['module:subscription_order']], function () {
            Route::get('subscription/update-status/{supscription_id}/{status}', [OrderSubscriptionController::class, 'view'])->name('update-status');
            Route::get('subscription', [OrderSubscriptionController::class, 'index'])->name('index');
            Route::get('subscription/show/{subscription}', [OrderSubscriptionController::class, 'show'])->name('show');
            Route::get('subscription/edit/{subscription}', [OrderSubscriptionController::class, 'edit'])->name('edit');
            Route::put('subscription/update/{subscription}', [OrderSubscriptionController::class, 'update'])->name('update');

        });

        Route::group(['prefix' => 'business-settings', 'as' => 'business-settings.', 'middleware' => ['subscription:restaurant_setup' ]], function () {
            Route::get('restaurant-setup', [BusinessSettingsController::class, 'restaurant_index'])->name('restaurant-setup')->middleware('module:restaurant_config');
            Route::get('notification-setup', [BusinessSettingsController::class, 'notification_index'])->name('notification-setup')->middleware('module:notification_setup');
            Route::get('notification-status-change/{key}/{type}', [BusinessSettingsController::class, 'notification_status_change'])->name('notification_status_change')->middleware('module:notification_setup');;
            Route::post('nezha-notify', [BusinessSettingsController::class, 'nezhaNotifySave'])->name('nezha-notify');
            Route::post('nezha-new-order-repeat', [BusinessSettingsController::class, 'nezhaNewOrderRepeatSave'])->name('nezha-new-order-repeat'); // 哪吒: 新单反复提醒设置(提示音面板 AJAX 保存)
            Route::get('nezha-telegram-detect', [BusinessSettingsController::class, 'nezhaTelegramDetect'])->name('nezha-telegram-detect');
            Route::post('add-schedule', [BusinessSettingsController::class, 'add_schedule'])->name('add-schedule');
            Route::get('remove-schedule/{restaurant_schedule}', [BusinessSettingsController::class, 'remove_schedule'])->name('remove-schedule');
            Route::get('update-active-status', [BusinessSettingsController::class, 'active_status'])->name('update-active-status');
            Route::get('nezha-store-mode', [BusinessSettingsController::class, 'nezha_store_mode'])->name('nezha-store-mode');
            Route::post('update-setup/{restaurant}', [BusinessSettingsController::class, 'restaurant_setup'])->name('update-setup');
            Route::get('toggle-settings-status/{restaurant}/{status}/{menu}', [BusinessSettingsController::class, 'restaurant_status'])->name('toggle-settings');
            Route::post('nezha-accept-mode', [BusinessSettingsController::class, 'nezha_accept_mode'])->name('nezha-accept-mode'); // 哪吒预约下单 M4 三态接单模式
            // 哪吒预约下单 M5 配送时段窗口 CRUD(全收总闸 nezha_preorder_status·IDOR 按 session 商家作用域)
            Route::get('nezha-windows', [NezhaDeliveryWindowController::class, 'index'])->name('nezha-window.index'); // M5前端·配送时段配置页(mockup02)
            Route::post('nezha-window', [NezhaDeliveryWindowController::class, 'store'])->name('nezha-window.store');
            Route::post('nezha-window/{id}/toggle', [NezhaDeliveryWindowController::class, 'toggle'])->name('nezha-window.toggle');
            Route::post('nezha-window/{id}/delete', [NezhaDeliveryWindowController::class, 'destroy'])->name('nezha-window.destroy');
            Route::post('nezha-window-batch-ready', [NezhaDeliveryWindowController::class, 'batchMarkReady'])->name('nezha-window.batch-ready'); // M7 批量标出餐(confirmed→handover)
            Route::get('site_direction_vendor', [BusinessSettingsController::class, 'site_direction_vendor'])->name('site_direction_vendor');
            Route::post('update-meta-data/{restaurant}', [BusinessSettingsController::class, 'updateStoreMetaData'])->name('update-meta-data');
            Route::post('update-opening-closing-status/{restaurant}', [BusinessSettingsController::class, 'updateOpeningClosingStatus'])->name('update-opening-closing-status');

        });

        Route::group(['prefix' => 'profile', 'as' => 'profile.', 'middleware' => ['module:bank_info','subscription:bank_info' ]], function () {
            Route::get('view', [ProfileController::class, 'view'])->name('view');
            // Route::get('update', 'ProfileController@edit')->name('update');
            Route::post('update', [ProfileController::class, 'update'])->name('update');
            Route::post('settings-password', [ProfileController::class, 'settings_password_update'])->name('settings-password');
            // Route::get('bank-view', 'ProfileController@bank_view')->name('bankView');
            // Route::get('bank-edit', 'ProfileController@bank_edit')->name('bankInfo');
            // Route::post('bank-update', 'ProfileController@bank_update')->name('bank_update');
            // Route::post('bank-delete', 'ProfileController@bank_delete')->name('bank_delete');
        });

        Route::group(['prefix' => 'restaurant', 'as' => 'shop.', 'middleware' => ['module:my_restaurant','subscription:my_shop' ]], function () {
            Route::get('view', [RestaurantController::class, 'view'])->name('view');
            Route::get('brand', [RestaurantController::class, 'brand'])->name('brand');
            Route::get('edit', [RestaurantController::class, 'edit'])->name('edit');
            Route::post('update', [RestaurantController::class, 'update'])->name('update');
            Route::post('logo/update', [RestaurantController::class, 'logo_update'])->name('logo-update');
            Route::post('cover/update', [RestaurantController::class, 'cover_update'])->name('cover-update');
            Route::post('meta-image/update', [RestaurantController::class, 'meta_image_update'])->name('meta-image-update');
            Route::post('update-message', [RestaurantController::class, 'update_message'])->name('update-message');
            Route::post('qr-store', [RestaurantController::class, 'qr_store'])->name('qr-store');
            Route::get('qr-view', [RestaurantController::class, 'qr_view'])->name('qr-view')->withoutMiddleware('module:my_restaurant')->middleware('module:my_qr_code');
            Route::get('qr-pdf', [RestaurantController::class, 'qr_pdf'])->name('qr-pdf');
            Route::get('qr-print', [RestaurantController::class, 'qr_print'])->name('qr-print');
        });

        Route::group(['prefix' => 'message', 'as' => 'message.', 'middleware' => ['module:chat','subscription:chat'] ], function () {
            Route::get('list', [ConversationController::class, 'list'])->name('list');
            Route::get('live-status', [ConversationController::class, 'live_status'])->name('live-status');
            Route::post('store/{user_id}/{user_type}', [ConversationController::class, 'store'])->name('store');
            Route::get('view/{conversation_id}/{user_id}', [ConversationController::class, 'view'])->name('view');
        });

        Route::group(['prefix' => 'feedback', 'as' => 'feedback.'], function () {
            Route::get('/', [\App\Http\Controllers\Vendor\FeedbackController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\Vendor\FeedbackController::class, 'store'])->name('store');
        });

        Route::group(['prefix' => 'subscription' , 'as' => 'subscriptionackage.'], function () {
            Route::get('/subscriber-detail',  [SubscriptionController::class, 'subscriberDetail'])->name('subscriberDetail')->middleware('module:business_plan');
            Route::get('/invoice/{id}',  [SubscriptionController::class, 'invoice'])->name('invoice');
            Route::get('/subscriber-list',  [SubscriptionController::class, 'subscriberList'])->name('subscriberList');
            Route::post('/cancel-subscription/{id}',  [SubscriptionController::class, 'cancelSubscription'])->name('cancelSubscription');
            Route::post('/switch-to-commission/{id}',  [SubscriptionController::class, 'switchToCommission'])->name('switchToCommission');
            Route::get('/package-view/{id}/{store_id}',  [SubscriptionController::class, 'packageView'])->name('packageView');
            Route::get('/subscriber-transactions/{id}',  [SubscriptionController::class, 'subscriberTransactions'])->name('subscriberTransactions');
            Route::get('/subscriber-transaction-export',  [SubscriptionController::class, 'subscriberTransactionExport'])->name('subscriberTransactionExport');
            Route::get('/subscriber-wallet-transactions',  [SubscriptionController::class, 'subscriberWalletTransactions'])->name('subscriberWalletTransactions');
            Route::post('/package-buy',  [SubscriptionController::class, 'packageBuy'])->name('packageBuy');
            Route::post('/add-to-session',  [SubscriptionController::class, 'addToSession'])->name('addToSession');
        });

        Route::group(['prefix' => 'report', 'as' => 'report.', 'middleware' => ['subscription:report']], function () {

            // Routes without module-based middleware
            Route::post('set-date', [ReportController::class, 'set_date'])->name('set-date');
            Route::get('generate-statement/{id}', [ReportController::class, 'generate_statement'])->name('generate-statement');

            Route::get('restaurant-earning-report', [RestaurantEarningReportController::class, 'getRestaurantEarningReport'])->name('restaurant-earning-report');
            Route::get('restaurant-earning-summary', [RestaurantEarningReportController::class, 'getRestaurantEarningSummary'])->name('restaurant-earning-summary');
            Route::get('restaurant-earning-breakdown', [RestaurantEarningReportController::class, 'getRestaurantEarningBreakdown'])->name('restaurant-earning-breakdown');
            Route::get('restaurant-expense-breakdown', [RestaurantEarningReportController::class, 'getRestaurantExpenseBreakdown'])->name('restaurant-expense-breakdown');
            Route::get('restaurant-earning-trend', [RestaurantEarningReportController::class, 'getRestaurantEarningTrend'])->name('restaurant-earning-trend');
            Route::get('top-selling-foods', [RestaurantEarningReportController::class, 'getTopSellingFoods'])->name('top-selling-foods');
            Route::get('restaurant-earning-export', [RestaurantEarningReportController::class, 'export'])->name('restaurant-earning-export');
            Route::get('restaurant-earning-transactions', [RestaurantEarningReportController::class, 'getRestaurantEarningTransactions'])->name('restaurant-earning-transactions');
            Route::get('restaurant-earning-transactions-export', [RestaurantEarningReportController::class, 'exportRestaurantEarningTransactions'])->name('restaurant-earning-transactions-export');

            Route::group(['middleware' => ['module:tax_report']], function () {
                Route::get('food-report', [ReportController::class, 'food_report'])->name('food-report');
                Route::get('food-report-export', [ReportController::class, 'food_report_export'])->name('food-report-export');
                Route::get('vendor-tax-report', [VendorTaxReportController::class, 'vendorTax'])->name('vendorTax');
                Route::get('vendor-tax-export', [VendorTaxReportController::class, 'vendorTaxExport'])->name('vendorTaxExport');
            });

            // expense_report group
            Route::group(['middleware' => ['module:expense_report']], function () {
                Route::get('expense-report', [ReportController::class, 'expense_report'])->name('expense-report');
                Route::get('expense-export', [ReportController::class, 'expense_export'])->name('expense-export');
                Route::post('expense-report-search', [ReportController::class, 'expense_search'])->name('expense-report-search');
            });

            // transaction group
            Route::group(['middleware' => ['module:transaction']], function () {
                Route::get('transaction-report', [ReportController::class, 'day_wise_report'])->name('day-wise-report');
                Route::get('transaction-report-export', [ReportController::class, 'day_wise_report_export'])->name('day-wise-report-export');
            });

            // order_report group
            Route::group(['middleware' => ['module:order_report']], function () {
                Route::get('order-report', [ReportController::class, 'order_report'])->name('order-report');
                Route::get('order-report-export', [ReportController::class, 'order_report_export'])->name('order-report-export');
                Route::get('campaign-order-report', [ReportController::class, 'campaign_order_report'])->name('campaign_order-report');
                Route::get('campaign-order-report-export', [ReportController::class, 'campaign_report_export'])->name('campaign_report_export');
            });

            // food_report group
            Route::group(['middleware' => ['module:food_report']], function () {
                Route::get('food-wise-report', [ReportController::class, 'food_wise_report'])->name('food-wise-report');
                Route::get('food-wise-report-export', [ReportController::class, 'food_wise_report_export'])->name('food-wise-report-export');
            });

            // disbursement group
            Route::group(['middleware' => ['module:disbursement']], function () {
                Route::get('disbursement-report', [ReportController::class, 'disbursement_report'])->name('disbursement-report');
                Route::get('disbursement-report-export/{type}', [ReportController::class, 'disbursement_report_export'])->name('disbursement-report-export');
            });
        });

        Route::group(['prefix' => 'file-manager', 'as' => 'file-manager.'], function () {
            Route::get('/download/{order}/{file_name}/{storage?}', [OrderController::class, 'download'])->name('download');
        });
    });

    Route::post('digital_payment', [SubscriptionController::class, 'digital_payment'])->name('subscription.digital_payment');
    Route::get('pay/now/{subscription_transaction_id}', [SubscriptionController::class, 'getPaymentMethods'])->name('subscription.digital_payment_methods');
});
