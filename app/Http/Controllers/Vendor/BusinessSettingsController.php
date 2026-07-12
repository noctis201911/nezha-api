<?php

namespace App\Http\Controllers\Vendor;

use App\Models\Category;
use App\Models\Characteristic;
use App\Models\Cuisine;
use App\Models\Restaurant;
use App\Models\RestaurantNotificationSetting;
use App\Models\Tag;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Models\BusinessSetting;
use App\Models\RestaurantConfig;
use App\Models\RestaurantSchedule;
use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\Validator;

class BusinessSettingsController extends Controller
{

    private $restaurant;

    public function restaurant_index()
    {
        $restaurant =  Restaurant::withoutGlobalScope('translate')->with('translations')->findOrFail(Helpers::get_restaurant_id());
        $cuisineNames = Cuisine::pluck('name')->toArray();
        $categoryNames = Category::pluck('name')->toArray();
        $combinedNames = array_merge($cuisineNames, $categoryNames);
        $combinedNames = '[' . implode(', ', array_map(fn($name) => "'$name'", $combinedNames)) . ']';

        $language = getWebConfig('language');
        $meta = $restaurant->meta_data ?? [];
        return view('vendor-views.business-settings.restaurant-index', compact('restaurant','combinedNames','language','meta'));
    }

    public function notification_index(Request $request)
    {
        if (RestaurantNotificationSetting::count() == 0) {
            Helpers::restaurantNotificationDataSetup(Helpers::get_restaurant_id());
        }

        $query = RestaurantNotificationSetting::where('restaurant_id', Helpers::get_restaurant_id());

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('key', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('sub_title', 'like', "%{$search}%");
            });
        }

        $data = $query->get();

        $business_name = BusinessSetting::where('key', 'business_name')->first()?->value;

        // 哪吒: 接单/超时提醒通道设置 — 当前店 + Telegram 一次性绑定验证码
        $restaurant = Restaurant::find(Helpers::get_restaurant_id());
        $botUser = '@Nz_order_bot';
        $tgCode = null;
        if ($restaurant) {
            $codeKey = 'nezha_tg_bind_code_' . $restaurant->id;
            $tgCode = \Illuminate\Support\Facades\Cache::get($codeKey);
            if (!$tgCode) {
                $tgCode = 'NZ' . $restaurant->id . '-' . strtoupper(\Illuminate\Support\Str::random(5));
                \Illuminate\Support\Facades\Cache::put($codeKey, $tgCode, now()->addMinutes(30));
            }
            \App\CentralLogics\NezhaCsAssistant::rememberBindCode($tgCode, 'restaurant', $restaurant->id);
        }

        // 预约单叫车提醒开关(平台级·07 稿)。总闸 nezha_preorder_status 关时页不显此项(dormant)。
        $preorderOn = \App\CentralLogics\NezhaPreorder::enabled();
        $poRemindOn = (bool) (BusinessSetting::where('key', 'nezha_preorder_dispatch_remind_push')->first()->value ?? 1);

        return view('vendor-views.business-settings.notification-index', compact('business_name', 'data', 'restaurant', 'botUser', 'tgCode', 'preorderOn', 'poRemindOn'));
    }

    // 哪吒: 商家保存「接单/超时提醒通道」偏好(Telegram/邮箱开关 + 自填通知邮箱)
    public function nezhaNotifySave(Request $request)
    {
        $restaurant = Restaurant::findOrFail(Helpers::get_restaurant_id());
        $validator = Validator::make($request->all(), [
            'nezha_notify_email' => 'nullable|email|max:191',
        ], [
            'nezha_notify_email.email' => '请填写正确的邮箱地址',
        ]);
        if ($validator->fails()) {
            Toastr::error($validator->errors()->first());
            return back();
        }
        $tg = $request->boolean('timeout_notify_telegram') ? 1 : 0;
        $mail = $request->boolean('timeout_notify_email') ? 1 : 0;

        // 防漏单地板: 两个推送通道都关 -> 必须显式确认「靠常开后台设备接单」
        if ($tg === 0 && $mail === 0 && !$request->boolean('ack_all_off')) {
            Toastr::error('两个提醒都关闭前，请先确认你的店铺靠常开电脑后台接单');
            return back();
        }

        $restaurant->timeout_notify_telegram = $tg;
        $restaurant->timeout_notify_email = $mail;
        $restaurant->nezha_notify_email = $request->filled('nezha_notify_email') ? trim($request->input('nezha_notify_email')) : null;
        if ($request->boolean('tg_unbind')) {
            $restaurant->telegram_chat_id = null;
        }
        // 全关=常开设备, 置豁免(满足上线硬闸); 任一通道开则回到非豁免由通道兜底
        $restaurant->nezha_alert_exempt = ($tg === 0 && $mail === 0) ? 1 : 0;
        $restaurant->save();

        // 预约单叫车提醒(平台级开关·07 稿·关=只停推送、作业台横幅照亮)。总闸关时页不显此项→不提交→不写(dormant)。MVP 平台级, 每商家粒度为后续。
        if (\App\CentralLogics\NezhaPreorder::enabled()) {
            BusinessSetting::updateOrCreate(
                ['key' => 'nezha_preorder_dispatch_remind_push'],
                ['value' => $request->boolean('nezha_preorder_dispatch_remind') ? 1 : 0]
            );
        }

        Toastr::success('提醒通道设置已更新');
        return back();
    }

    // 哪吒: 商家自助绑定 Telegram — 按一次性验证码在 getUpdates 里精确匹配本店会话(只认本店的码, 不暴露别家会话)
    public function nezhaTelegramDetect(Request $request)
    {
        $restaurant = Restaurant::find(Helpers::get_restaurant_id());
        if (!$restaurant) {
            return response()->json(['ok' => false, 'msg' => '店铺不存在']);
        }
        // 阶段D-③: webhook 已激活 → 绑定由入站 webhook 自动完成, 这里只查状态(不再 getUpdates)。
        if (Helpers::get_business_settings('nezha_cs_tg_webhook_secret', false)) {
            $restaurant->refresh();
            if ($restaurant->telegram_chat_id) {
                return response()->json(['ok' => true, 'chat_id' => (string) $restaurant->telegram_chat_id, 'msg' => 'Telegram 已连接成功']);
            }
            return response()->json(['ok' => false, 'msg' => '还没检测到。请确认已把验证码发给机器人（发送后会自动绑定，约几秒），再点一次。']);
        }
        $code = \Illuminate\Support\Facades\Cache::get('nezha_tg_bind_code_' . $restaurant->id);
        if (!$code) {
            return response()->json(['ok' => false, 'msg' => '验证码已过期，请刷新页面后重试']);
        }
        $token = Helpers::get_business_settings('telegram_bot_token', false);
        if (!$token || !is_string($token)) {
            return response()->json(['ok' => false, 'msg' => '平台暂未配置 Telegram 机器人']);
        }
        $foundChat = null;
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 5]]);
            $raw = @file_get_contents('https://api.telegram.org/bot' . $token . '/getUpdates', false, $ctx);
            $upd = json_decode($raw, true);
            foreach (($upd['result'] ?? []) as $it) {
                $msg = $it['message'] ?? ($it['channel_post'] ?? null);
                $text = trim((string) ($msg['text'] ?? ''));
                if ($text !== '' && strcasecmp($text, $code) === 0 && isset($msg['chat']['id'])) {
                    $foundChat = (string) $msg['chat']['id'];
                }
            }
        } catch (\Throwable $e) {
        }
        if (!$foundChat) {
            return response()->json(['ok' => false, 'msg' => '还没检测到。请先在 Telegram 里把验证码发给机器人，再点一次检测。']);
        }
        $restaurant->telegram_chat_id = $foundChat;
        $restaurant->timeout_notify_telegram = 1;
        $restaurant->nezha_alert_exempt = 0;
        $restaurant->save();
        \Illuminate\Support\Facades\Cache::forget('nezha_tg_bind_code_' . $restaurant->id);

        return response()->json(['ok' => true, 'chat_id' => $foundChat, 'msg' => 'Telegram 已连接成功']);
    }

    public function notification_status_change($key, $type){
        $data= RestaurantNotificationSetting::where('restaurant_id',Helpers::get_restaurant_id())->where('key',$key)->first();
        if(!$data){
            Toastr::error(translate('messages.Notification_settings_not_found'));
            return back();
        }
        if($type == 'Mail' ) {
            $data->mail_status =  $data->mail_status == 'active' ? 'inactive' : 'active';
        }
        elseif($type == 'push_notification' ) {
            $data->push_notification_status =  $data->push_notification_status == 'active' ? 'inactive' : 'active';
        }
        elseif($type == 'SMS' ) {
            $data->sms_status =  $data->sms_status == 'active' ? 'inactive' : 'active';
        }
        $data?->save();

        Toastr::success(translate('messages.Notification_settings_updated'));
        return back();
    }

    public function restaurant_setup(Restaurant $restaurant, Request $request)
    {
        $request->validate([
            'gst' => 'required_if:gst_status,1',
            'free_delivery_distance' => 'required_if:free_delivery_distance_status,1',
            'per_km_delivery_charge'=>'required_with:minimum_delivery_charge|numeric|between:1,999999999999.99',
            'minimum_delivery_charge'=>'required_with:per_km_delivery_charge|numeric|between:1,999999999999.99',
            'maximum_shipping_charge'=>'nullable|gt:minimum_delivery_charge',
        ], [
            'gst.required_if' => translate('messages.gst_can_not_be_empty'),
        ]);

        // 分享缩略图必填(已有则不强制重传): 新店/从未上传过的必须先传一张分享图才能保存本页;
        // 已有图的商家改其它设置时不被打扰。该图用于顾客把店铺分享到微信/Telegram等时的封面缩略图。
        if (!$request->hasFile('meta_image') && empty($restaurant->meta_image)) {
            Toastr::error('请先上传「分享缩略图」——顾客把您的店分享到微信/Telegram等时显示的就是这张图，关系到点击率与拉新');
            return back()->withInput();
        }

        if ($request->schedule_advance_dine_in_booking_duration_time_format == 'min' &&   $request->schedule_advance_dine_in_booking_duration > 60) {
            Toastr::error(translate('messages.Dine_in_dine_in_booking_duration_time_must_be_in_60_minute'));
            return back();
        }
        elseif ($request->schedule_advance_dine_in_booking_duration_time_format == 'hour' &&   $request->schedule_advance_dine_in_booking_duration > 24) {
            Toastr::error(translate('messages.Dine_in_dine_in_booking_duration_time_must_be_in_24_hour'));
            return back();
        }
        elseif ($request->schedule_advance_dine_in_booking_duration_time_format == 'day' &&   $request->schedule_advance_dine_in_booking_duration > 365) {
            Toastr::error(translate('messages.Dine_in_dine_in_booking_duration_time_must_be_in_356_days'));
            return back();
        }

        if($request->instant_order == 0 && $request->schedule_order == 0){
            Toastr::error(translate('messages.Instant_Order_and_Scheduled_Order_cannot_both_be_disabled_at_the_same_time'));
            return back();
        }

        if($request->delivery == 0 && $request->take_away == 0 && $request->dine_in == 0){
            Toastr::error(translate('You_must_enable_at_least_one_delivery_option'));
            return back();
        }


        $data =0;
        if (($restaurant->restaurant_model == 'subscription' && $restaurant?->restaurant_sub?->self_delivery == 1)  || ($restaurant->restaurant_model == 'commission' &&  $restaurant->self_delivery_system == 1) ){
            $data =1;
        }
        $cuisine_ids = [];
        $cuisine_ids=$request->cuisine_ids;
        $tag_ids = [];
        if ($request->tags != null) {
            $tags = explode(",", $request->tags);
        }
        if(isset($tags)){
            foreach ($tags as $key => $value) {
                $tag = Tag::firstOrNew(
                    ['tag' => $value]
                );
                $tag->save();
                array_push($tag_ids,$tag->id);
            }
        }
        $characteristic_ids = [];
        if ($request->characteristics != null) {
            $characteristics = explode(",", $request->characteristics);
        }
        if(isset($characteristics)){
            foreach ($characteristics as $key => $value) {
                $characteristic = Characteristic::firstOrNew(
                    ['characteristic' => $value]
                );
                $characteristic->save();
                array_push($characteristic_ids,$characteristic->id);
            }
        }
        $off_day = $request->off_day?implode('',$request->off_day):'';

        //restaurant
        $restaurant->delivery = $request->delivery ? 1: 0;
        $restaurant->take_away = $request->take_away ? 1: 0;
        $restaurant->schedule_order = $request->schedule_order ? 1: 0;
        $restaurant->order_subscription_active = $request->order_subscription_active ? 1: 0;
        $restaurant->veg = $request->veg ? 1: 0;
        $restaurant->non_veg = $request->non_veg ? 1: 0;
        $restaurant->cutlery = $request->cutlery ? 1: 0;

        $restaurant->minimum_order = $request->minimum_order;
        $restaurant->opening_time = $request->opening_time;
        $restaurant->closeing_time = $request->closeing_time;
        $restaurant->off_day = $off_day;
        $restaurant->gst = json_encode(['status'=>$request->gst_status, 'code'=>$request->gst]);
        $restaurant->free_delivery = $request->free_delivery ? 1: 0;
        $restaurant->free_delivery_distance = json_encode(['status'=>$request->free_delivery_distance_status, 'value'=>$request->free_delivery_distance]);
        $restaurant->minimum_shipping_charge = $data?$request->minimum_delivery_charge??0: $restaurant->minimum_shipping_charge;
        $restaurant->per_km_shipping_charge = $data?$request->per_km_delivery_charge??0: $restaurant->per_km_shipping_charge;
        $restaurant->maximum_shipping_charge = $request->maximum_shipping_charge ?? null;

        //meta data

            $restaurant->meta_image = $request->has('meta_image') ? Helpers::update('restaurant/', $restaurant->meta_image, 'png', $request->file('meta_image')) : $restaurant->meta_image;
            $restaurant->meta_title = $request->meta_title;
            $restaurant->meta_description = $request->meta_description;
            $restaurant->meta_data = Helpers::formatMetaData($request->all(), $restaurant->meta_data);


        $restaurant->save();
        $restaurant->cuisine()->sync($cuisine_ids);
        $restaurant->tags()->sync($tag_ids);
        $restaurant->characteristics()->sync($characteristic_ids);

        $conf = RestaurantConfig::firstOrNew(
            ['restaurant_id' =>  $restaurant->id]
        );

        $conf->dine_in = $request->dine_in ? 1: 0;
        $conf->instant_order = $request->instant_order ? 1: 0;
        $conf->is_extra_packaging_active = $request->is_extra_packaging_active ? 1: 0;
        $conf->halal_tag_status = $request->halal_tag_status ? 1: 0;
        $conf->opening_closing_status = $request->opening_closing_status;
        $conf->same_time_for_every_day = $request->same_time_for_every_day ? 1: 0;
        $conf->customer_order_date = $request->customer_order_date ?? 0;
        $conf->customer_date_order_sratus = $request->customer_date_order_sratus ?? 0;
        $conf->extra_packaging_status = $request?->extra_packaging_status??0;
        $conf->extra_packaging_amount = $request->extra_packaging_amount;
        $conf->schedule_advance_dine_in_booking_duration = $request->schedule_advance_dine_in_booking_duration ?? 0;
        $conf->schedule_advance_dine_in_booking_duration_time_format = $request->schedule_advance_dine_in_booking_duration_time_format ?? 'min';
        $conf->save();

        Toastr::success(translate('messages.business_configuration_updated'));
        return back();
    }

    public function restaurant_status(Restaurant $restaurant, Request $request)
    {
        if($request->menu == "schedule_order" && !Helpers::schedule_order())
        {
            Toastr::warning(translate('messages.schedule_order_disabled_warning'));
            return back();
        }

        $home_delivery = BusinessSetting::where('key', 'home_delivery')->first()?->value ?? null;
        if ($request->menu == "delivery" && !$home_delivery) {
            Toastr::warning(translate('messages.Home_delivery_is_disabled_by_admin'));
            return back();
        }
        $take_away = BusinessSetting::where('key', 'take_away')->first()?->value ?? null;
        if ($request->menu == "take_away" && !$take_away) {
            Toastr::warning(translate('messages.Take_away_is_disabled_by_admin'));
            return back();
        }
        $dine_in_order_option = BusinessSetting::where('key', 'dine_in_order_option')->first()?->value ?? null;
        if ($request->menu == "dine_in" && !$dine_in_order_option && $request->status == 1 ) {
            Toastr::warning(translate('messages.dine_in_order_option_is_disabled_by_admin'));
            return back();
        }


        $instant_order = BusinessSetting::where('key', 'instant_order')->first()?->value ?? null;
        if ($request->menu == "instant_order" && !$instant_order && $request->status == 1 ) {
            Toastr::warning(translate('messages.instant_order_is_disabled_by_admin'));
            return back();
        }

        if((($request->menu == "delivery" && $restaurant->take_away==0) || ($request->menu == "take_away" && $restaurant->delivery==0)) &&  $request->status == 0 )
        {
            Toastr::warning(translate('messages.can_not_disable_both_take_away_and_delivery'));
            return back();
        }

        if((($request->menu == "instant_order" && $restaurant->schedule_order==0) || ( isset($restaurant->restaurant_config)   && ($request->menu == "schedule_order" && $restaurant?->restaurant_config?->instant_order ==0))) &&  $request->status == 0 && $instant_order )
        {
            Toastr::warning(translate('messages.can_not_disable_both_instant_order_and_schedule_order'));
            return back();
        }

        if((($request->menu == "veg" && $restaurant->non_veg==0) || ($request->menu == "non_veg" && $restaurant->veg==0)) &&  $request->status == 0 )
        {
            Toastr::warning(translate('messages.veg_non_veg_disable_warning'));
            return back();
        }

        if($request->menu == 'free_delivery' &&(($restaurant->restaurant_model == 'subscription' && $restaurant?->restaurant_sub?->self_delivery == 0) || ($restaurant->restaurant_model == 'unsubscribed'))){
            Toastr::error(translate('your_subscription_plane_does_not_have_this_feature'));
            return back();
        }

        if( in_array($request->menu,['instant_order','customer_date_order_sratus','halal_tag_status' ,'is_extra_packaging_active','dine_in'] ) ){

            $conf = RestaurantConfig::firstOrNew(
                ['restaurant_id' =>  $restaurant->id]
            );
            $conf[$request->menu] = $request->status;
            $conf->save();

            Toastr::success(translate('messages.Restaurant settings updated!'));
            return back();
        }



        $restaurant[$request->menu] = $request->status;
        $restaurant->save();
        Toastr::success(translate('messages.Restaurant settings updated!'));
        return back();
    }

    /**
     * 哪吒 预约下单 M4 —— 商家「接单模式」三态统一写入端点(即时 / 即时+预约 / 只接预约)。
     * 三态 → 底座两 flag(restaurants.schedule_order + restaurant_config.instant_order)权威映射走
     * NezhaPreorder::modeToFlags;0/0(停业态)由三态构造天然不可能。全程收在总闸 nezha_preorder_status(默认关)下。
     * 与现有逐 flag toggle-settings 并存、不替换;仅切「怎么接单」,不碰钱/退款/L1(L2/L3·PLAN §9)。
     * 守卫:(a) 平台级 instant/schedule 被 admin 关时不许启用对应 flag(与 restaurant_status 一致);
     *       (b) 净新增——预约模式(schedule_order=1)须先配 ≥1 个启用中的配送时段(mockup 01 状态B)。
     */
    public function nezha_accept_mode(Request $request)
    {
        // 总闸门(默认关)——功能未开放时端点不生效(防御纵深,UI 关时也不该到这)。
        if (!\App\CentralLogics\NezhaPreorder::enabled()) {
            Toastr::warning('预约下单功能尚未开放');
            return back();
        }

        $flags = \App\CentralLogics\NezhaPreorder::modeToFlags((string) $request->mode);
        if ($flags === null) {
            Toastr::warning('无效的接单模式');
            return back();
        }

        $restaurant = Helpers::get_restaurant_data();

        // 平台级门(与 restaurant_status 逐 toggle 一致):admin 全局关掉 instant/schedule 时不许商家启用对应 flag。
        if ($flags['instant_order'] == 1) {
            $admin_instant = BusinessSetting::where('key', 'instant_order')->first()?->value ?? null;
            if (!$admin_instant) {
                Toastr::warning(translate('messages.instant_order_is_disabled_by_admin'));
                return back();
            }
        }
        if ($flags['schedule_order'] == 1 && !Helpers::schedule_order()) {
            Toastr::warning(translate('messages.schedule_order_disabled_warning'));
            return back();
        }

        // 净新增守卫:预约模式须先有 ≥1 个启用中的配送时段,否则顾客无时段可选(mockup 01 状态B:保存置灰 + 去配置引导)。
        if ($flags['schedule_order'] == 1 && !\App\CentralLogics\NezhaPreorder::hasActiveWindow($restaurant->id)) {
            Toastr::warning('请先配置至少 1 个配送时段，再开启预约接单');
            return back();
        }

        // 原子写两 flag(与现网列/语义一致,不新增真相源;避免中途出现 0/0 或半态)。
        \Illuminate\Support\Facades\DB::transaction(function () use ($restaurant, $flags) {
            $restaurant->schedule_order = $flags['schedule_order'];
            $restaurant->save();
            $conf = RestaurantConfig::firstOrNew(['restaurant_id' => $restaurant->id]);
            $conf->instant_order = $flags['instant_order'];
            $conf->save();
        });

        Toastr::success('接单模式已更新');
        return back();
    }

    public function active_status(Request $request)
    {
        $restaurant = Helpers::get_restaurant_data();
        // 哪吒: 手动打烊改用 nezha_temp_closed(店铺保持 active=1 顾客端可见、显"休息中"+拦下单), 不再 active=0 致店铺从顾客端消失。
        $restaurant->nezha_temp_closed = $restaurant->nezha_temp_closed ? 0 : 1;
        if ($restaurant->nezha_temp_closed) {
            $restaurant->active = 1; // 防御: 打烊时确保店铺仍可见(休息中), 不消失
        }
        $restaurant->save();
        // 哪吒 W5: 店态切换操作留痕(作业台胶囊 / 门店页共用此端点)——记谁、何店、切到哪态, 供事后追溯。
        \Illuminate\Support\Facades\Log::info('nezha_store_status_toggle', [
            'restaurant_id'     => $restaurant->id,
            'by'                => optional(auth('vendor')->user())->id,
            'nezha_temp_closed' => (int) $restaurant->nezha_temp_closed,
            'action'            => $restaurant->nezha_temp_closed ? 'pause' : 'open',
            'at'                => now()->toIso8601String(),
        ]);
        return response()->json(['message' => $restaurant->nezha_temp_closed?translate('messages.restaurant_temporarily_closed'):translate('messages.restaurant_opened')], 200);
    }

    /**
     * 哪吒 忙碌模式 / 定时挂起 —— 作业台店态胶囊三档写路径。GET(与 active_status 同风格·auth vendor·绑本店)。
     *   mode=open  → 恢复营业(清 busy/pause)
     *   mode=busy  → 仍接单 + 挂"出餐约需 minutes 分钟"横幅(reason=peak/prep/short); busy_until=now+minutes
     *   mode=pause → 暂停接单; minutes>0 = 到点自动恢复(pause_until=now+minutes), minutes=0 = 无限期手动暂停
     * 🔴 只动本店本功能状态位, 不碰钱/订单/L1。顾客端展示仍受总闸 nezha_busy_mode_status gate(见 nezha_store_extra)。
     */
    public function nezha_store_mode(Request $request)
    {
        $restaurant = Helpers::get_restaurant_data();
        if (!$restaurant) {
            return response()->json(['message' => '没找到您的店铺信息，请刷新后重试。'], 404);
        }
        $mode = (string) $request->input('mode');
        if (!in_array($mode, ['open', 'busy', 'pause'], true)) {
            return response()->json(['message' => '参数错误'], 422);
        }
        $minutes = (int) $request->input('minutes', 0);

        if ($mode === 'open') {
            $restaurant->nezha_temp_closed = 0;
            $restaurant->nezha_pause_until = null;
            $restaurant->nezha_busy_until = null;
            $restaurant->nezha_busy_min = null;
            $restaurant->nezha_busy_reason = null;
        } elseif ($mode === 'busy') {
            $minutes = max(5, min(240, $minutes ?: 30));
            $reason = (string) $request->input('reason', 'peak');
            $restaurant->nezha_temp_closed = 0;                 // 忙碌 = 仍营业接单
            $restaurant->nezha_pause_until = null;
            $restaurant->nezha_busy_until = now()->addMinutes($minutes);
            $restaurant->nezha_busy_min = $minutes;
            $restaurant->nezha_busy_reason = in_array($reason, ['peak', 'prep', 'short'], true) ? $reason : 'peak';
        } else { // pause
            $restaurant->nezha_temp_closed = 1;
            $restaurant->active = 1;                            // 暂停时保持可见(休息中), 与 active_status 一致
            $restaurant->nezha_pause_until = $minutes > 0 ? now()->addMinutes(min(1440, $minutes)) : null; // 0=无限期
            $restaurant->nezha_busy_until = null;               // 暂停清忙碌
            $restaurant->nezha_busy_min = null;
            $restaurant->nezha_busy_reason = null;
        }
        $restaurant->save();

        \Illuminate\Support\Facades\Log::info('nezha_store_mode', [
            'restaurant_id' => $restaurant->id,
            'by'            => optional(auth('vendor')->user())->id,
            'mode'          => $mode,
            'minutes'       => $minutes,
            'reason'        => $restaurant->nezha_busy_reason,
            'via'           => 'workbench',
            'at'            => now()->toIso8601String(),
        ]);

        // 回读真实态返回(单一真相源)
        $sr = \Illuminate\Support\Facades\DB::table('restaurants')->where('id', $restaurant->id)
            ->first(['nezha_temp_closed', 'nezha_pause_until', 'nezha_busy_until', 'nezha_busy_min', 'nezha_busy_reason']);
        $busy = $sr->nezha_busy_until && \Carbon\Carbon::parse($sr->nezha_busy_until)->isFuture();
        return response()->json([
            'message'     => $mode === 'open' ? '已恢复营业' : ($mode === 'busy' ? '已设为忙碌中' : '已暂停接单'),
            'temp_closed' => (int) $sr->nezha_temp_closed,
            'busy'        => (bool) $busy,
            'busy_min'    => $busy ? (int) $sr->nezha_busy_min : null,
            'busy_reason' => $busy ? $sr->nezha_busy_reason : null,
            'pause_until' => ((int) $sr->nezha_temp_closed === 1) ? $sr->nezha_pause_until : null,
        ], 200);
    }

    public function add_schedule(Request $request)
    {
        $validator = Validator::make($request->all(),[
            'start_time'=>'required|date_format:H:i',
            'end_time'=>'required|date_format:H:i|after:start_time',
            'day'=>'required',
        ],[
            'end_time.after'=>translate('messages.End time must be after the start time')
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }
        $temp = RestaurantSchedule::where('day', $request->day)->where('restaurant_id',Helpers::get_restaurant_id())
        ->where(function($q)use($request){
            return $q->where(function($query)use($request){
                return $query->where('opening_time', '<=' , $request->start_time)->where('closing_time', '>=', $request->start_time);
            })->orWhere(function($query)use($request){
                return $query->where('opening_time', '<=' , $request->end_time)->where('closing_time', '>=', $request->end_time);
            });
        })
        ->first();

        if(isset($temp))
        {
            return response()->json(['errors' => [
                ['code'=>'time', 'message'=>translate('messages.Time overlap detected.')]
            ]]);
        }

        $restaurant = Helpers::get_restaurant_data();
        $restaurant_schedule = RestaurantSchedule::insert(['restaurant_id'=>Helpers::get_restaurant_id(),'day'=>$request->day,'opening_time'=>$request->start_time,'closing_time'=>$request->end_time]);

        $restaurantConfig = RestaurantConfig::where('restaurant_id', $restaurant->id)->first();

        if ($restaurantConfig->same_time_for_every_day) {

            $restaurantId = $restaurantConfig->restaurant_id;
            $dayOneSchedules = RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->where('day', 1)
                ->get();

            if ($dayOneSchedules->isEmpty()) {
                RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->delete();
                return response()->json();
            }
            RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->where('day', '!=', 1)
                ->delete();

            $insertData = [];

            foreach ($dayOneSchedules as $schedule) {
                foreach (range(0, 6) as $day) {
                    if ($day == 1) {
                        continue;
                    }

                    $insertData[] = [
                        'restaurant_id' => $restaurantId,
                        'day'           => $day,
                        'opening_time'  => $schedule->opening_time,
                        'closing_time'  => $schedule->closing_time,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];
                }
            }

            if (!empty($insertData)) {
                RestaurantSchedule::insert($insertData);
            }
        }

        return response()->json([
            'view' => view('vendor-views.business-settings.partials._schedule', compact('restaurant'))->render(),
        ]);
    }

    public function remove_schedule($restaurant_schedule)
    {
        $restaurant = Helpers::get_restaurant_data();
        $schedule = RestaurantSchedule::where('restaurant_id', $restaurant->id)->find($restaurant_schedule);
        if(!$schedule)
        {
            return response()->json([],404);
        }
        $schedule->delete();

        $restaurantConfig = RestaurantConfig::where('restaurant_id', $restaurant->id)->first();

        if ($restaurantConfig->same_time_for_every_day) {

            $restaurantId = $restaurantConfig->restaurant_id;
            $dayOneSchedules = RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->where('day', 1)
                ->get();

            if ($dayOneSchedules->isEmpty()) {
                RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->delete();
                return response()->json([
                    'view' => view('vendor-views.business-settings.partials._schedule', compact('restaurant'))->render(),
                ]);
            }
            RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->where('day', '!=', 1)
                ->delete();

            $insertData = [];

            foreach ($dayOneSchedules as $schedule) {
                foreach (range(0, 6) as $day) {
                    if ($day == 1) {
                        continue;
                    }

                    $insertData[] = [
                        'restaurant_id' => $restaurantId,
                        'day'           => $day,
                        'opening_time'  => $schedule->opening_time,
                        'closing_time'  => $schedule->closing_time,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];
                }
            }

            if (!empty($insertData)) {
                RestaurantSchedule::insert($insertData);
            }
        }

        return response()->json([
            'view' => view('vendor-views.business-settings.partials._schedule', compact('restaurant'))->render(),
        ]);
    }

    public function site_direction_vendor(Request $request){
        session()->put('site_direction_vendor', ($request->status == 1?'ltr':'rtl'));
        return response()->json();
    }

    public function updateOpeningClosingStatus(Request $request, $id)
    {
        $restaurant = Helpers::get_restaurant_data();
        $config = RestaurantConfig::firstOrNew(['restaurant_id' => $id]);

        $config->opening_closing_status = $request->opening_closing_status ? 1 : 0;
        $config->same_time_for_every_day = $request->same_time_for_every_day ? 1 : 0;
        $config->save();

        if ($config->opening_closing_status) {

            RestaurantSchedule::where('restaurant_id', $restaurant->id)->delete();

            $insertData = [];

            foreach (range(0, 6) as $day) {
                $insertData[] = [
                    'restaurant_id' => $restaurant->id,
                    'day'           => $day,
                    'opening_time'  => '00:00:00',
                    'closing_time'  => '23:59:59',
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];
            }

            RestaurantSchedule::insert($insertData);

            return [
                'view' => view('vendor-views.business-settings.partials._schedule', compact('restaurant'))->render(),
            ];
        }

        if ($config->same_time_for_every_day) {

            $restaurantId = $config->restaurant_id;
            $dayOneSchedules = RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->where('day', 1)
                ->get();

            if ($dayOneSchedules->isEmpty()) {
                RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->delete();
                return response()->json([
                    'view' => view('vendor-views.business-settings.partials._schedule', compact('restaurant'))->render(),
                ]);
            }
            RestaurantSchedule::where('restaurant_id', $restaurantId)
                ->where('day', '!=', 1)
                ->delete();

            $insertData = [];

            foreach ($dayOneSchedules as $schedule) {
                foreach (range(0, 6) as $day) {
                    if ($day == 1) {
                        continue;
                    }

                    $insertData[] = [
                        'restaurant_id' => $restaurantId,
                        'day'           => $day,
                        'opening_time'  => $schedule->opening_time,
                        'closing_time'  => $schedule->closing_time,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];
                }
            }

            if (!empty($insertData)) {
                RestaurantSchedule::insert($insertData);
            }
        }

        return response()->json([
            'view' => view('vendor-views.business-settings.partials._schedule', compact('restaurant'))->render(),
        ]);
    }

}
