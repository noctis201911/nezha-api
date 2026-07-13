<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\DataSetting;
use App\Models\EmailTemplate;
use App\Models\NotificationMessage;
use App\Models\NotificationSetting;
use App\Models\OrderCancelReason;
use App\Models\PriorityList;
use App\Models\Restaurant;
use App\Models\RestaurantConfig;
use App\Models\RestaurantSubscription;
use App\Models\Setting;
use App\Models\Translation;
use App\Traits\Processor;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class BusinessSettingsController extends Controller
{
    use Processor;

    public function business_index(Request $request, $tab = 'business')
    {
        if (! Helpers::module_permission_check('settings')) {
            Toastr::error(translate('messages.access_denied'));

            return back();
        }
        if ($tab == 'business') {
            return view('admin-views.business-settings.business-index');
        } elseif ($tab == 'customer') {
            $data = BusinessSetting::where('key', 'like', 'wallet_%')
                ->orWhere('key', 'like', 'loyalty_%')
                ->orWhere('key', 'like', 'ref_earning_%')
                ->orWhere('key', 'like', 'add_fund_status%')
                ->orWhere('key', 'like', 'customer_%')
                ->orWhere('key', 'like', 'guest_checkout_status')
                ->orWhere('key', 'like', 'wallet_status')
                ->orWhere('key', 'like', 'new_customer_discount_%')->get();
            $data = array_column($data->toArray(), 'value', 'key');

            return view('admin-views.business-settings.customer-index', compact('data'));
        } elseif ($tab == 'deliveryman') {
            return view('admin-views.business-settings.deliveryman-index');
        } elseif ($tab == 'order') {

            $key = ['canceled_by_restaurant', 'canceled_by_deliveryman', 'order_delivery_verification', 'admin_order_notification', 'home_delivery', 'take_away', 'instant_order', 'repeat_order_option', 'order_subscription', 'schedule_order', 'customer_date_order_sratus', 'canceled_by_restaurant', 'canceled_by_deliveryman', 'order_confirmation_model', 'schedule_order_slot_duration', 'schedule_order_slot_duration_time_formate', 'customer_order_date', 'dine_in_order_option', 'can_restaurant_edit_order', 'order_notification_type'];
            $settings = array_column(BusinessSetting::whereIn('key', $key)->get()->toArray(), 'value', 'key');
            $language = getWebConfig('language');
            $user_type = $request['user_type'] ?? '';
            $search = $request['search'] ?? '';
            $reasons = OrderCancelReason::when($user_type, function ($query) use ($user_type) {
                $query->where('user_type', $user_type);
            })
                ->when($search, function ($query) use ($search) {
                    $query->where('reason', 'like', "%{$search}%");
                })
                ->latest()
                ->paginate(config('default_pagination'));

            return view('admin-views.business-settings.order-index', compact('settings', 'reasons', 'language', 'user_type', 'search'));
        } elseif ($tab == 'restaurant') {
            return view('admin-views.business-settings.restaurant-index');
        } elseif ($tab == 'disbursement') {
            return view('admin-views.business-settings.disbursement-index');
        } elseif ($tab == 'priority') {
            return view('admin-views.business-settings.priority-index');
        } elseif ($tab == 'payment-setup') {
            $keys = [
                'cash_on_delivery',
                'digital_payment',
                'offline_payment_status',
                'partial_payment_status',
                'partial_payment_method',
            ];

            $settings = BusinessSetting::whereIn('key', $keys)
                ->pluck('value', 'key');

            $cash_on_delivery = isset($settings['cash_on_delivery'])
                ? json_decode($settings['cash_on_delivery'], true)['status']
                : 0;

            $digital_payment = isset($settings['digital_payment'])
                ? json_decode($settings['digital_payment'], true)['status']
                : 0;

            $offline_payment = $settings['offline_payment_status'] ?? 0;
            $partial_payment = $settings['partial_payment_status'] ?? 0;
            $partial_payment_method = $settings['partial_payment_method'] ?? 'offline_payment';

            return view(
                'admin-views.business-settings.payment-setup',
                compact('offline_payment', 'partial_payment', 'cash_on_delivery', 'digital_payment', 'partial_payment_method')
            );
        } elseif ($tab == 'food') {
            $key = ['toggle_veg_non_veg'];
            $settings = array_column(BusinessSetting::whereIn('key', $key)->get()->toArray(), 'value', 'key');

            return view('admin-views.business-settings.food-setup', compact('settings'));
        }
    }

    public function updatePaymentSetup(Request $request)
    {
        $offline = $request->offline_payment_status ? 1 : 0;
        $cod = $request->cash_on_delivery ? 1 : 0;
        $digital = $request->digital_payment ? 1 : 0;

        if ($offline == 0 && $cod == 0 && $digital == 0) {
            Toastr::error(translate('messages.at_least_one_payment_method_must_be_active'));

            return back();
        }
        $options = [
            'offline_payment_status' => $offline,
            'cash_on_delivery' => ['status' => $cod],
            'digital_payment' => ['status' => $digital],
            'partial_payment_status' => $request->partial_payment_status ? 1 : 0,
            'partial_payment_method' => $request->partial_payment_method,
        ];

        foreach ($options as $key => $value) {
            Helpers::businessUpdateOrInsert(
                ['key' => $key],
                [
                    'value' => is_array($value) ? json_encode($value) : $value,
                    'updated_at' => now(),
                ]
            );
        }

        Toastr::success(translate('messages.updated_successfully'));

        return back();
    }

    public function update_restaurant(Request $request)
    {
        $settings = [
            'cash_in_hand_overflow_restaurant' => $request['cash_in_hand_overflow_restaurant'] ?? 0,
            'cash_in_hand_overflow_restaurant_amount' => $request['cash_in_hand_overflow_restaurant_amount'],
            'min_amount_to_pay_restaurant' => $request['min_amount_to_pay_restaurant'],
            'canceled_by_restaurant' => $request['canceled_by_restaurant'],
            'restaurant_review_reply' => $request['restaurant_review_reply'],
            'extra_packaging_charge' => $request['extra_packaging_charge'] ?? 0,
            'toggle_restaurant_registration' => $request['toggle_restaurant_registration'] ?? 0,
        ];

        Helpers::bulkAddOrUpdateBusinessSettings($settings);
        Toastr::success(translate('messages.successfully_updated_to_changes_restart_app'));

        return back();
    }

    public function update_dm(Request $request)
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('messages.update_option_is_disable_for_demo'));

            return back();
        }
        Helpers::businessUpdateOrInsert(['key' => 'min_amount_to_pay_dm'], [
            'value' => $request['min_amount_to_pay_dm'],
        ]);
        Helpers::businessUpdateOrInsert(['key' => 'cash_in_hand_overflow_delivery_man'], [
            'value' => $request['cash_in_hand_overflow_delivery_man'] ?? 0,
        ]);
        Helpers::businessUpdateOrInsert(['key' => 'dm_tips_status'], [
            'value' => $request['dm_tips_status'],
        ]);
        Helpers::businessUpdateOrInsert(['key' => 'dm_tips_status'], [
            'value' => $request['dm_tips_status'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'dm_maximum_orders'], [
            'value' => $request['dm_maximum_orders'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'canceled_by_deliveryman'], [
            'value' => $request['canceled_by_deliveryman'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'show_dm_earning'], [
            'value' => $request['show_dm_earning'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'toggle_dm_registration'], [
            'value' => $request['dm_self_registration'],
        ]);
        Helpers::businessUpdateOrInsert(['key' => 'dm_max_cash_in_hand'], [
            'value' => $request['dm_max_cash_in_hand'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'dm_picture_upload_status'], [
            'value' => $request['dm_picture_upload_status'],
        ]);

        Toastr::success(translate('messages.successfully_updated_to_changes_restart_app'));

        return back();
    }

    public function update_disbursement(Request $request)
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('messages.update_option_is_disable_for_demo'));

            return back();
        }

        Helpers::businessUpdateOrInsert(['key' => 'disbursement_type'], [
            'value' => $request['disbursement_type'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'restaurant_disbursement_time_period'], [
            'value' => $request['restaurant_disbursement_time_period'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'restaurant_disbursement_week_start'], [
            'value' => $request['restaurant_disbursement_week_start'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'restaurant_disbursement_waiting_time'], [
            'value' => $request['restaurant_disbursement_waiting_time'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'restaurant_disbursement_create_time'], [
            'value' => $request['restaurant_disbursement_create_time'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'restaurant_disbursement_min_amount'], [
            'value' => $request['restaurant_disbursement_min_amount'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'dm_disbursement_time_period'], [
            'value' => $request['dm_disbursement_time_period'],
        ]);
        Helpers::businessUpdateOrInsert(['key' => 'dm_disbursement_week_start'], [
            'value' => $request['dm_disbursement_week_start'],
        ]);
        Helpers::businessUpdateOrInsert(['key' => 'dm_disbursement_waiting_time'], [
            'value' => $request['dm_disbursement_waiting_time'],
        ]);
        Helpers::businessUpdateOrInsert(['key' => 'dm_disbursement_create_time'], [
            'value' => $request['dm_disbursement_create_time'],
        ]);
        Helpers::businessUpdateOrInsert(['key' => 'dm_disbursement_min_amount'], [
            'value' => $request['dm_disbursement_min_amount'],
        ]);
        Helpers::businessUpdateOrInsert(['key' => 'system_php_path'], [
            'value' => $request['system_php_path'],
        ]);

        if (function_exists('exec')) {
            $data = self::generateCronCommand(disbursement_type: $request['disbursement_type']);
            $scriptPath = 'script.sh';
            exec('sh '.$scriptPath);
            Helpers::businessUpdateOrInsert(['key' => 'restaurant_disbursement_command'], [
                'value' => $data['restaurantCronCommand'],
            ]);
            Helpers::businessUpdateOrInsert(['key' => 'dm_disbursement_command'], [
                'value' => $data['dmCronCommand'],
            ]);
            Toastr::success(translate('messages.successfully_updated_disbursement_functionality'));

            return back();
        } else {
            $data = self::generateCronCommand(disbursement_type: $request['disbursement_type']);
            Helpers::businessUpdateOrInsert(['key' => 'restaurant_disbursement_command'], [
                'value' => $data['restaurantCronCommand'],
            ]);
            Helpers::businessUpdateOrInsert(['key' => 'dm_disbursement_command'], [
                'value' => $data['dmCronCommand'],
            ]);
            if ($request['disbursement_type'] == 'automated') {
                Session::flash('disbursement_exec', true);
                Toastr::warning(translate('messages.Servers_PHP_exec_function_is_disabled_check_dependencies_&_start_cron_job_manualy_in_server'));
            }
            Toastr::success(translate('messages.successfully_updated_disbursement_functionality'));

            return back();
        }

    }

    private function dmSchedule()
    {
        $key = [
            'dm_disbursement_time_period',
            'dm_disbursement_week_start',
            'dm_disbursement_create_time',
        ];
        $settings = array_column(BusinessSetting::whereIn('key', $key)->get()->toArray(), 'value', 'key');

        $scheduleFrequency = $settings['dm_disbursement_time_period'] ?? 'daily';
        $weekDay = $settings['dm_disbursement_week_start'] ?? 'sunday';
        $time = $settings['dm_disbursement_create_time'] ?? '12:00';

        $time = explode(':', $time);

        $hour = $time[0];
        $min = $time[1];

        $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $day = array_search($weekDay, $days);
        $schedule = '* * * * *';
        if ($scheduleFrequency == 'daily') {
            $schedule = $min.' '.$hour.' '.'* * *';

        } elseif ($scheduleFrequency == 'weekly') {

            $schedule = $min.' '.$hour.' '.'* * '.$day;
        } elseif ($scheduleFrequency == 'monthly') {
            $schedule = $min.' '.$hour.' '.'28-31 * *';

        }

        return $schedule;
    }

    private function restaurantSchedule()
    {
        $key = [
            'restaurant_disbursement_time_period',
            'restaurant_disbursement_week_start',
            'restaurant_disbursement_create_time',
        ];
        $settings = array_column(BusinessSetting::whereIn('key', $key)->get()->toArray(), 'value', 'key');

        $scheduleFrequency = $settings['restaurant_disbursement_time_period'] ?? 'daily';
        $weekDay = $settings['restaurant_disbursement_week_start'] ?? 'sunday';
        $time = $settings['restaurant_disbursement_create_time'] ?? '12:00';

        $time = explode(':', $time);

        $hour = $time[0];
        $min = $time[1];

        $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $day = array_search($weekDay, $days);
        $schedule = '* * * * *';
        if ($scheduleFrequency == 'daily') {
            $schedule = $min.' '.$hour.' '.'* * *';

        } elseif ($scheduleFrequency == 'weekly') {

            $schedule = $min.' '.$hour.' '.'* * '.$day;
        } elseif ($scheduleFrequency == 'monthly') {
            $schedule = $min.' '.$hour.' '.'28-31 * *';

        }

        return $schedule;
    }

    private function generateCronCommand($disbursement_type = 'automated')
    {
        $system_php_path = BusinessSetting::where('key', 'system_php_path')->first();
        $system_php_path = $system_php_path ? $system_php_path->value : '/usr/bin/php';
        $dmSchedule = self::dmSchedule();
        $restaurantSchedule = self::restaurantSchedule();
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'];
        $rootPath = dirname($scriptFilename);
        $phpCommand = $system_php_path;
        $dmScriptPath = $rootPath.'/artisan dm:disbursement';
        $restaurantScriptPath = $rootPath.'/artisan restaurant:disbursement';
        $dmClearCronCommand = "(crontab -l | grep -v \"$phpCommand $dmScriptPath\") | crontab -";
        $dmCronCommand = $disbursement_type == 'automated' ? "(crontab -l ; echo \"$dmSchedule $phpCommand $dmScriptPath\") | crontab -" : '';
        $restaurantClearCronCommand = "(crontab -l | grep -v \"$phpCommand $restaurantScriptPath\") | crontab -";
        $restaurantCronCommand = $disbursement_type == 'automated' ? "(crontab -l ; echo \"$restaurantSchedule $phpCommand $restaurantScriptPath\") | crontab -" : '';
        $scriptContent = "#!/bin/bash\n";
        $scriptContent .= $dmClearCronCommand."\n";
        $scriptContent .= $dmCronCommand."\n";
        $scriptContent .= $restaurantClearCronCommand."\n";
        $scriptContent .= $restaurantCronCommand."\n";
        $scriptFilePath = $rootPath.'/script.sh';
        file_put_contents($scriptFilePath, $scriptContent);

        return [
            'dmCronCommand' => $dmCronCommand,
            'restaurantCronCommand' => $restaurantCronCommand,
        ];
    }

    public function update_order(Request $request)
    {

        if ($request?->home_delivery == null && $request?->take_away == null) {
            Toastr::warning(translate('messages.can_not_disable_both_take_away_and_delivery'));

            return back();
        }
        if ($request?->instant_order == null && $request?->schedule_order == null) {
            Toastr::warning(translate('messages.can_not_disable_both_schedule_order_and_instant_order'));

            return back();
        }
        $time = $request['schedule_order_slot_duration'];
        if ($request['schedule_order_slot_duration_time_formate'] == 'hour') {
            $time = $request['schedule_order_slot_duration'] * 60;
        }
        $settings = [
            'order_delivery_verification' => $request['order_delivery_verification'] ?? 0,
            'order_notification_type' => $request['order_notification_type'],
            'admin_order_notification' => $request['admin_order_notification'],
            'instant_order' => $request['instant_order'] ?? 0,
            'customer_date_order_sratus' => $request['customer_date_order_sratus'] ?? 0,
            'customer_order_date' => $request['customer_order_date'] ?? 0,
            'schedule_order' => $request['schedule_order'] ?? 0,
            'home_delivery' => $request['home_delivery'] ?? 0,
            'take_away' => $request['take_away'] ?? 0,
            'dine_in_order_option' => $request['dine_in_order_option'] ?? 0,
            'repeat_order_option' => $request['repeat_order_option'] ?? 0,
            'order_subscription' => $request['order_subscription'] ?? 0,
            'schedule_order_slot_duration' => $time,
            'schedule_order_slot_duration_time_formate' => $request['schedule_order_slot_duration_time_formate'],
            'canceled_by_restaurant' => $request['canceled_by_restaurant'],
            'canceled_by_deliveryman' => $request['canceled_by_deliveryman'],
            'can_restaurant_edit_order' => $request['can_restaurant_edit_order'],
            'order_confirmation_model' => $request['order_confirmation_model'],
            'admin_free_delivery_status' => $request['admin_free_delivery_status'] ? $request['admin_free_delivery_status'] : null,
            'admin_free_delivery_option' => $request['admin_free_delivery_status'] && $request['admin_free_delivery_option'] ? $request['admin_free_delivery_option'] : null,
            'free_delivery_over' => $request['admin_free_delivery_status'] && $request['free_delivery_over'] > 0 && $request['admin_free_delivery_option'] == 'free_delivery_by_specific_criteria' ? $request['free_delivery_over'] : null,
            'free_delivery_distance' => $request['admin_free_delivery_status'] && $request['free_delivery_distance'] > 0 && $request['admin_free_delivery_option'] == 'free_delivery_by_specific_criteria' ? $request['free_delivery_distance'] : null,
        ];

        // if ($request['take_away'] != 1) {
        //     Restaurant::where('take_away', 1)->update(['take_away' => 0]);
        // }
        if ($request['dine_in_order_option'] != 1) {
            RestaurantConfig::where('dine_in', true)->update(['dine_in' => 0]);
        }
        // if ($request['home_delivery'] != 1) {
        //     Restaurant::where('delivery', 1)->update(['delivery' => 0]);
        // }
        if($request['order_subscription']  ==! 1){
            Restaurant::where('order_subscription_active', 1)->update([ 'order_subscription_active' => 0]);
        }
        Helpers::bulkAddOrUpdateBusinessSettings($settings);

        Toastr::success(translate('messages.successfully_updated_to_changes_restart_app'));

        return back();
    }

    public function updateFood(Request $request)
    {
        Helpers::businessUpdateOrInsert(['key' => 'toggle_veg_non_veg'], [
            'value' => $request['vnv'] ?? 0,
        ]);

        Toastr::success(translate('messages.successfully_updated_to_changes_restart_app'));

        return back();
    }

    public function update_priority(Request $request)
    {
        $list = ['popular_food', 'popular_restaurant', 'new_restaurant', 'all_restaurant', 'campaign_food', 'best_reviewed_food', 'category_list', 'cuisine_list', 'category_food', 'search_bar'];
        foreach ($list as $item) {
            Helpers::businessUpdateOrInsert(['key' => $item.'_default_status'], [
                'value' => $request[$item.'_default_status'] ?? 0,
            ]);

            if ($request[$item.'_default_status'] == '0') {

                if (! $request[$item.'_sort_by_general'] && $item != 'search_bar') {
                    Toastr::error(translate('you_must_selcet_an_option_for').' '.translate($item));

                    return back();
                }

                if ($request[$item.'_sort_by_general']) {
                    PriorityList::query()->updateOrInsert(['name' => $item.'_sort_by_general', 'type' => 'general'], [
                        'value' => $request[$item.'_sort_by_general'],
                    ]);
                }
                if ($request[$item.'_sort_by_unavailable']) {
                    PriorityList::query()->updateOrInsert(['name' => $item.'_sort_by_unavailable', 'type' => 'unavailable'], [
                        'value' => $request[$item.'_sort_by_unavailable'],
                    ]);
                }
                if ($request[$item.'_sort_by_temp_closed']) {
                    PriorityList::query()->updateOrInsert(['name' => $item.'_sort_by_temp_closed', 'type' => 'temp_closed'], [
                        'value' => $request[$item.'_sort_by_temp_closed'],
                    ]);
                }
                if ($request[$item.'_sort_by_rating']) {
                    PriorityList::query()->updateOrInsert(['name' => $item.'_sort_by_rating', 'type' => 'rating'], [
                        'value' => $request[$item.'_sort_by_rating'],
                    ]);
                }
            }
        }

        Toastr::success(translate('messages.successfully_updated_to_changes_restart_app'));

        return back();
    }

    public function update_payment_setup(Request $request)
    {
        return back();
    }

    public function business_setup(Request $request)
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('messages.update_option_is_disable_for_demo'));

            return back();
        }

        $key = ['logo', 'icon'];
        $settings = array_column(BusinessSetting::whereIn('key', $key)->get()->toArray(), 'value', 'key');

        $validator = Validator::make($request->all(), [
            'logo' => isset($settings['logo']) ? 'nullable|max:'.MAX_FILE_SIZE * 1024 : 'required|max:'.MAX_FILE_SIZE * 1024,
            'icon' => isset($settings['icon']) ? 'nullable|max:'.MAX_FILE_SIZE * 1024 : 'required|max:'.MAX_FILE_SIZE * 1024,
        ], [
            'logo.required' => translate('Logo is required'),
            'icon.required' => translate('Favicon is required'),
            'logo.max' => translate('Image size must be within '.MAX_FILE_SIZE.'mb'),
            'icon.max' => translate('Image size must be within '.MAX_FILE_SIZE.'mb'),
        ]);

        if ($validator->fails()) {
            Toastr::error($validator->errors()->first());

            return back();
        }

        $key = ['logo', 'icon'];
        $settings = array_column(BusinessSetting::whereIn('key', $key)->get()->toArray(), 'value', 'key');


        Helpers::businessUpdateOrInsert(['key' => 'country_picker_status'], [
            'value' => $request['country_picker_status'] ? $request['country_picker_status'] : 0,
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'additional_charge_status'], [
            'value' => $request['additional_charge_status'] ? $request['additional_charge_status'] : null,
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'additional_charge_name'], [
            'value' => $request['additional_charge_name'] ? $request['additional_charge_name'] : null,
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'additional_charge'], [
            'value' => $request['additional_charge'] ? $request['additional_charge'] : null,
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'business_name'], [
            'value' => $request['restaurant_name'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'currency'], [
            'value' => $request['currency'],
        ]);

        Config::set('currency', $request['currency']);
        Config::set('currency_symbol_position', $request['currency_symbol_position']);
        Config::set('currency_symbol', $request['currency_symbol']);

        Helpers::businessUpdateOrInsert(['key' => 'timezone'], [
            'value' => $request['timezone'],
        ]);

        if ($request->has('logo')) {

            $image_name = Helpers::update(dir: 'business/', old_image: $settings['logo'], format: 'png', image: $request->file('logo'));
        } else {
            $image_name = $settings['logo'];
        }

        Helpers::businessUpdateOrInsert(['key' => 'logo'], [
            'value' => $image_name,
        ]);

        if ($request->has('icon')) {

            $image_name = Helpers::update(dir: 'business/', old_image: $settings['icon'], format: 'png', image: $request->file('icon'));
        } else {
            $image_name = $settings['icon'];
        }

        Helpers::businessUpdateOrInsert(['key' => 'icon'], [
            'value' => $image_name,
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'phone'], [
            'value' => $request['phone'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'email_address'], [
            'value' => $request['email'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'address'], [
            'value' => $request['address'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'footer_text'], [
            'value' => $request['footer_text'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'cookies_text'], [
            'value' => $request['cookies_text'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'currency_symbol_position'], [
            'value' => $request['currency_symbol_position'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'admin_commission'], [
            'value' => $request['admin_commission'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'country'], [
            'value' => $request['country'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'default_location'], [
            'value' => json_encode(['lat' => $request['latitude'], 'lng' => $request['longitude']]),
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'timeformat'], [
            'value' => $request['time_format'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'digit_after_decimal_point'], [
            'value' => $request['digit_after_decimal_point'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'delivery_charge_comission'], [
            'value' => $request['admin_comission_in_delivery_charge'],
        ]);

        if (! isset($request->commission) && ! isset($request->subscription)) {
            Toastr::error(translate('You_must_select_at_least_one_business_model_between_commission_and_subscription'));

            return back();
        }

        // For commission Model
        if (isset($request->commission) && ! isset($request->subscription)) {

            if (RestaurantSubscription::where('status', 1)->count() > 0) {
                Toastr::warning(translate('You_need_to_switch_your_subscribers_to_commission_first'));

                return back();
            }

            Helpers::businessUpdateOrInsert(['key' => 'business_model'], [
                'value' => json_encode(['commission' => 1, 'subscription' => 0]),
            ]);
            $business_model = BusinessSetting::where('key', 'business_model')->first()?->value;
            $business_model = json_decode($business_model, true) ?? [];

            if ($business_model && $business_model['subscription'] == 0) {
                Restaurant::query()->update(['restaurant_model' => 'commission']);
            }
        }
        // For subscription model
        elseif (isset($request->subscription) && ! isset($request->commission)) {
            Helpers::businessUpdateOrInsert(['key' => 'business_model'], [
                'value' => json_encode(['commission' => 0, 'subscription' => 1]),
            ]);
            $business_model = BusinessSetting::where('key', 'business_model')->first()?->value;
            $business_model = json_decode($business_model, true) ?? [];

            if ($business_model && $business_model['commission'] == 0) {
                Restaurant::where('restaurant_model', 'commission')
                    ->update([
                        'restaurant_model' => 'unsubscribed',
                        'status' => 0,
                    ]);
            }
        } else {
            Helpers::businessUpdateOrInsert(['key' => 'business_model'], [
                'value' => json_encode(['commission' => 1, 'subscription' => 1]),
            ]);
        }
        Toastr::success(translate('Successfully updated. To see the changes in app restart the app.'));

        return back();
    }

    public function storage_connection_index(Request $request)
    {
        return view('admin-views.business-settings.storage-connection-index');
    }

    public function openAI()
    {
        return view('admin-views.business-settings.3rd_party.open_ai_config');
    }

    public function openAISettings()
    {
        $data = array_column(BusinessSetting::whereIn('key', [
            'section_wise_ai_limit',
            'image_upload_limit_for_ai',
        ])->get(['key', 'value'])->toArray(), 'value', 'key');

        return view('admin-views.business-settings.3rd_party.open_ai_settings', compact('data'));
    }

    public function openAISettingsUpdate(Request $request)
    {
        $limits = [
            'section_wise_ai_limit' => $request->section_wise_ai_limit ?? 0,
            'image_upload_limit_for_ai' => $request->image_upload_limit_for_ai ?? 0,
        ];

        foreach ($limits as $key => $value) {
            Helpers::businessUpdateOrInsert(['key' => $key], [
                'key' => $key,
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        Toastr::success(translate('messages.updated_successfully'));

        return back();
    }

    public function storage_connection_update(Request $request, $name)
    {
        if ($name == 'local_storage') {
            Helpers::businessUpdateOrInsert(['key' => 'local_storage'], [
                'key' => 'local_storage',
                'value' => $request->status ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Helpers::businessUpdateOrInsert(['key' => '3rd_party_storage'], [
                'key' => '3rd_party_storage',
                'value' => $request->status == '1' ? 0 : 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if ($name == '3rd_party_storage') {
            Helpers::businessUpdateOrInsert(['key' => '3rd_party_storage'], [
                'key' => '3rd_party_storage',
                'value' => $request->status ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Helpers::businessUpdateOrInsert(['key' => 'local_storage'], [
                'key' => 'local_storage',
                'value' => $request->status == '1' ? 0 : 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if ($name == 'storage_connection') {
            Helpers::businessUpdateOrInsert(['key' => 's3_credential'], [
                'key' => 's3_credential',
                'value' => json_encode([
                    'key' => $request['key'],
                    'secret' => $request['secret'],
                    'region' => $request['region'],
                    'bucket' => $request['bucket'],
                    'url' => $request['url'],
                    'end_point' => $request['end_point'],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Toastr::success(translate('messages.updated_successfully'));

        return back();
    }

    public function mail_index()
    {
        return view('admin-views.business-settings.mail-index');
    }

    public function mail_config(Request $request)
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('messages.update_option_is_disable_for_demo'));

            return back();
        }
        Helpers::businessUpdateOrInsert(
            ['key' => 'mail_config'],
            [
                'value' => json_encode([
                    'status' => $request['status'],
                    'name' => $request['name'],
                    'host' => $request['host'],
                    'driver' => $request['driver'],
                    'port' => $request['port'],
                    'username' => $request['username'],
                    'email_id' => $request['email'],
                    'encryption' => $request['encryption'],
                    'password' => $request['password'],
                ]),
                'updated_at' => now(),
            ]
        );
        Toastr::success(translate('messages.configuration_updated_successfully'));

        return back();
    }

    public function mail_config_status(Request $request)
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('messages.update_option_is_disable_for_demo'));

            return back();
        }
        $config = BusinessSetting::where(['key' => 'mail_config'])->first();

        $data = $config ? json_decode($config['value'], true) : null;

        Helpers::businessUpdateOrInsert(
            ['key' => 'mail_config'],
            [
                'value' => json_encode([
                    'status' => $request['status'] ?? 0,
                    'name' => $data['name'] ?? '',
                    'host' => $data['host'] ?? '',
                    'driver' => $data['driver'] ?? '',
                    'port' => $data['port'] ?? '',
                    'username' => $data['username'] ?? '',
                    'email_id' => $data['email_id'] ?? '',
                    'encryption' => $data['encryption'] ?? '',
                    'password' => $data['password'] ?? '',
                ]),
                'updated_at' => now(),
            ]
        );
        Toastr::success(translate('messages.configuration_updated_successfully'));

        return back();
    }

    public function openAIConfigStatus(Request $request)
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('messages.update_option_is_disable_for_demo'));

            return back();
        }
        $config = BusinessSetting::where(['key' => 'openai_config'])->first();

        $data = $config ? json_decode($config['value'], true) : null;

        Helpers::businessUpdateOrInsert(
            ['key' => 'openai_config'],
            [
                'value' => json_encode([
                    'status' => $request['status'] ?? 0,
                    'OPENAI_ORGANIZATION' => $data['OPENAI_ORGANIZATION'] ?? '',
                    'OPENAI_API_KEY' => $data['OPENAI_API_KEY'] ?? '',
                ]),
                'updated_at' => now(),
            ]
        );
        Toastr::success(translate('messages.configuration_updated_successfully'));

        return back();
    }

    public function openAIConfigUpdate(Request $request)
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('messages.update_option_is_disable_for_demo'));

            return back();
        }
        $config = BusinessSetting::where(['key' => 'openai_config'])->first();

        $data = $config ? json_decode($config['value'], true) : null;

        Helpers::businessUpdateOrInsert(
            ['key' => 'openai_config'],
            [
                'value' => json_encode([
                    'status' => $data['status'] ?? 0,
                    'OPENAI_ORGANIZATION' => $request['OPENAI_ORGANIZATION'] ?? '',
                    'OPENAI_API_KEY' => $request['OPENAI_API_KEY'] ?? '',
                ]),
                'updated_at' => now(),
            ]
        );
        Toastr::success(translate('messages.configuration_updated_successfully'));

        return back();
    }

    public function payment_index()
    {
        $published_status = addon_published_status('Gateways');

        $routes = config('addon_admin_routes');
        $desiredName = 'payment_setup';
        $payment_url = '';
        foreach ($routes as $routeArray) {
            foreach ($routeArray as $route) {
                if ($route['name'] === $desiredName) {
                    $payment_url = $route['url'];
                    break 2;
                }
            }
        }
        $data_values = Setting::whereIn('settings_type', ['payment_config'])->whereIn('key_name', ['ssl_commerz', 'paypal', 'stripe', 'razor_pay', 'senang_pay', 'paytabs', 'paystack', 'paymob_accept', 'paytm', 'flutterwave', 'liqpay', 'bkash', 'mercadopago'])->get();

        return view('admin-views.business-settings.payment-index', compact('published_status', 'payment_url', 'data_values'));
    }

    public function payment_config_update(Request $request)
    {
        $request['status'] = $request->status ?? 0;

        $validation = [
            'gateway' => 'required|in:ssl_commerz,paypal,stripe,razor_pay,senang_pay,paytabs,paystack,paymob_accept,paytm,flutterwave,liqpay,bkash,mercadopago',
            'mode' => 'required|in:live,test',
        ];

        $currency_check = Helpers::checkCurrency($request['gateway'], 'payment_gateway');

        if ($request['status'] == 1 && $currency_check !== true) {
            Toastr::warning(translate($currency_check).' '.translate('does_not_support_your_current_currency'));
        }

        $additional_data = [];

        if ($request['gateway'] == 'ssl_commerz') {
            $additional_data = [
                'status' => 'required|in:1,0',
                'store_id' => 'required_if:status,1',
                'store_password' => 'required_if:status,1',
            ];
        } elseif ($request['gateway'] == 'paypal') {
            $additional_data = [
                'status' => 'required|in:1,0',
                'client_id' => 'required_if:status,1',
                'client_secret' => 'required_if:status,1',
            ];
        } elseif ($request['gateway'] == 'stripe') {
            $additional_data = [
                'status' => 'required|in:1,0',
                'api_key' => 'required_if:status,1',
                'published_key' => 'required_if:status,1',
            ];
        } elseif ($request['gateway'] == 'razor_pay') {
            $additional_data = [
                'status' => 'required|in:1,0',
                'api_key' => 'required_if:status,1',
                'api_secret' => 'required_if:status,1',
            ];
        } elseif ($request['gateway'] == 'senang_pay') {
            $additional_data = [
                'status' => 'required|in:1,0',
                'callback_url' => 'required_if:status,1',
                'secret_key' => 'required_if:status,1',
                'merchant_id' => 'required_if:status,1',
            ];
        } elseif ($request['gateway'] == 'paytabs') {
            $additional_data = [
                'status' => 'required|in:1,0',
                'profile_id' => 'required_if:status,1',
                'server_key' => 'required_if:status,1',
                'base_url' => 'required_if:status,1',
            ];
        } elseif ($request['gateway'] == 'paystack') {
            $additional_data = [
                'status' => 'required|in:1,0',
                'public_key' => 'required_if:status,1',
                'secret_key' => 'required_if:status,1',
                'merchant_email' => 'required_if:status,1',
            ];
        } elseif ($request['gateway'] == 'paymob_accept') {
            $additional_data = [
                'status' => 'required|in:1,0',
                'callback_url' => 'required_if:status,1',
                'api_key' => 'required_if:status,1',
                'iframe_id' => 'required_if:status,1',
                'integration_id' => 'required_if:status,1',
                'hmac' => 'required_if:status,1',
            ];
        } elseif ($request['gateway'] == 'mercadopago') {
            $additional_data = [
                'status' => 'required|in:1,0',
                'access_token' => 'required_if:status,1',
                'public_key' => 'required_if:status,1',
                'supported_country' => 'required_if:status,1',
            ];
        } elseif ($request['gateway'] == 'liqpay') {
            $additional_data = [
                'status' => 'required|in:1,0',
                'private_key' => 'required_if:status,1',
                'public_key' => 'required_if:status,1',
            ];
        } elseif ($request['gateway'] == 'flutterwave') {
            $additional_data = [
                'status' => 'required|in:1,0',
                'secret_key' => 'required_if:status,1',
                'public_key' => 'required_if:status,1',
                'hash' => 'required_if:status,1',
            ];
        } elseif ($request['gateway'] == 'paytm') {
            $additional_data = [
                'status' => 'required|in:1,0',
                'merchant_key' => 'required_if:status,1',
                'merchant_id' => 'required_if:status,1',
                'merchant_website_link' => 'required_if:status,1',
            ];
        } elseif ($request['gateway'] == 'bkash') {
            $additional_data = [
                'status' => 'required|in:1,0',
                'app_key' => 'required_if:status,1',
                'app_secret' => 'required_if:status,1',
                'username' => 'required_if:status,1',
                'password' => 'required_if:status,1',
            ];
        }

        $request->validate(array_merge($validation, $additional_data));
        if ($request['gateway']) {
            $settings = Setting::where('key_name', $request['gateway'])->where('settings_type', 'payment_config')->first();
            $additional_data_image = $settings['additional_data'] != null ? json_decode($settings['additional_data']) : null;
            $storage = $additional_data_image?->storage ?? 'public';
            if ($request->has('gateway_image')) {
                $gateway_image = $this->file_uploader('payment_modules/gateway_image/', 'png', $request['gateway_image'], $additional_data_image != null ? $additional_data_image->gateway_image : '');
                $storage = Helpers::getDisk();
            } else {
                $gateway_image = $additional_data_image != null ? $additional_data_image->gateway_image : '';

            }

            $payment_additional_data = [
                'gateway_title' => $request['gateway_title'],
                'gateway_image' => $gateway_image,
                'storage' => $storage,
            ];
            $validator = Validator::make($request->all(), array_merge($validation, $additional_data));

            Setting::updateOrCreate(['key_name' => $request['gateway'], 'settings_type' => 'payment_config'], [
                'key_name' => $request['gateway'],
                'live_values' => $validator->validate(),
                'test_values' => $validator->validate(),
                'settings_type' => 'payment_config',
                'mode' => $request['mode'],
                'is_active' => $request['status'],
                'additional_data' => json_encode($payment_additional_data),
            ]);
        }

        Toastr::success(GATEWAYS_DEFAULT_UPDATE_200['message']);

        return back();
    }

    public function theme_settings()
    {
        return view('admin-views.business-settings.theme-settings');
    }

    public function update_theme_settings(Request $request)
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('messages.update_option_is_disable_for_demo'));

            return back();
        }
        Helpers::businessUpdateOrInsert(['key' => 'theme'], [
            'value' => $request['theme'],
        ]);
        Toastr::success(translate('theme_settings_updated'));

        return back();
    }

    public function app_settings()
    {
        $key = [
            'app_minimum_version_android_restaurant','app_minimum_version_android','app_url_android',
            'app_url_android_restaurant','app_minimum_version_ios_restaurant','app_url_ios_restaurant','app_minimum_version_android_deliveryman','tax_included','order_subscription','app_minimum_version_ios',
            'app_url_android_deliveryman','app_url_ios','popular_food','popular_restaurant','new_restaurant',
            'most_reviewed_foods','app_minimum_version_ios_deliveryman','app_url_ios_deliveryman'
        ];
        $settings = BusinessSetting::whereIn('key', $key)->pluck('value','key')->toArray();
        $app_minimum_version_android=$settings['app_minimum_version_android'] ?? null;
        $app_url_android=$settings['app_url_android']?? null;
        $app_minimum_version_ios=$settings['app_minimum_version_ios'] ?? null;
        $app_url_ios=$settings['app_url_ios'] ?? null;
        $popular_food=$settings['popular_food'] ?? null;
        $popular_restaurant=$settings['popular_restaurant'] ?? null;
        $new_restaurant=$settings['new_restaurant'] ?? null;
        $most_reviewed_foods=$settings['most_reviewed_foods'] ?? null;
        $app_minimum_version_android_restaurant=$settings['app_minimum_version_android_restaurant'] ?? null;
        $app_url_android_restaurant= $settings['app_url_android_restaurant'] ?? null;
        $app_minimum_version_ios_restaurant=$settings['app_minimum_version_ios_restaurant'] ?? null;
        $app_url_ios_restaurant=$settings['app_url_ios_restaurant'] ?? null;
        $app_minimum_version_android_deliveryman=$settings['app_minimum_version_android_deliveryman'] ?? null;
        $app_url_android_deliveryman=$settings['app_url_android_deliveryman'] ?? null;
        $app_url_ios_deliveryman=$settings['app_url_ios_deliveryman'] ?? null;
        $app_minimum_version_ios_deliveryman=$settings['app_minimum_version_ios_deliveryman'] ?? null;
        
        $user_app_download_status = \App\Models\DataSetting::where('key', 'user_app_download_status')->first()?->value ?? 0;
        $user_app_download_title = \App\Models\DataSetting::withoutGlobalScope('translate')->with('translations')->where('key', 'user_app_download_title')->first();

        return view('admin-views.business-settings.app-settings', compact(
            'app_minimum_version_android',
            'app_url_android',
            'app_minimum_version_ios',
            'app_url_ios',
            'popular_food',
            'popular_restaurant',
            'new_restaurant',
            'most_reviewed_foods',
            'app_minimum_version_android_restaurant',
            'app_url_android_restaurant',
            'app_minimum_version_ios_restaurant',
            'app_url_ios_restaurant',
            'app_minimum_version_android_deliveryman',
            'app_url_android_deliveryman',
            'app_url_ios_deliveryman',
            'app_minimum_version_ios_deliveryman',
            'user_app_download_status',
            'user_app_download_title'
        ));
    }

    public function user_app_download_update(Request $request)
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('messages.update_option_is_disable_for_demo'));
            return back();
        }

        $this->update_data_setting_data($request, 'user_app_download_title');
        DataSetting::updateOrCreate(
            ['key' => 'user_app_download_status'],
            [
                'type' => 'app_settings',
                'value' => $request->user_app_download_status ?? 0,
            ]
        );

        Toastr::success(translate('messages.User_app_download_updated'));
        return back();
    }

    public function update_app_settings(Request $request)
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('messages.update_option_is_disable_for_demo'));

            return back();
        }
        if ($request->type == 'user_app') {
            Helpers::businessUpdateOrInsert(['key' => 'app_minimum_version_android'], [
                'value' => $request['app_minimum_version_android'],
            ]);
            Helpers::businessUpdateOrInsert(['key' => 'app_url_android'], [
                'value' => $request['app_url_android'],
            ]);
            Helpers::businessUpdateOrInsert(['key' => 'app_minimum_version_ios'], [
                'value' => $request['app_minimum_version_ios'],
            ]);

            Helpers::businessUpdateOrInsert(['key' => 'app_url_ios'], [
                'value' => $request['app_url_ios'],
            ]);
            Toastr::success(translate('messages.User_app_settings_updated'));

            return back();
        }
        if ($request->type == 'restaurant_app') {
            Helpers::businessUpdateOrInsert(['key' => 'app_minimum_version_android_restaurant'], [
                'value' => $request['app_minimum_version_android_restaurant'],
            ]);
            Helpers::businessUpdateOrInsert(['key' => 'app_url_android_restaurant'], [
                'value' => $request['app_url_android_restaurant'],
            ]);
            Helpers::businessUpdateOrInsert(['key' => 'app_minimum_version_ios_restaurant'], [
                'value' => $request['app_minimum_version_ios_restaurant'],
            ]);
            Helpers::businessUpdateOrInsert(['key' => 'app_url_ios_restaurant'], [
                'value' => $request['app_url_ios_restaurant'],
            ]);
            Toastr::success(translate('messages.Restaurant_app_settings_updated'));

            return back();
        }
        if ($request->type == 'delivery_app') {
            Helpers::businessUpdateOrInsert(['key' => 'app_minimum_version_android_deliveryman'], [
                'value' => $request['app_minimum_version_android_deliveryman'],
            ]);
            Helpers::businessUpdateOrInsert(['key' => 'app_url_android_deliveryman'], [
                'value' => $request['app_url_android_deliveryman'],
            ]);
            Helpers::businessUpdateOrInsert(['key' => 'app_minimum_version_ios_deliveryman'], [
                'value' => $request['app_minimum_version_ios_deliveryman'],
            ]);
            Helpers::businessUpdateOrInsert(['key' => 'app_url_ios_deliveryman'], [
                'value' => $request['app_url_ios_deliveryman'],
            ]);

            Toastr::success(translate('messages.Delivery_app_settings_updated'));

            return back();
        }

        return back();
    }

    private function update_data_setting_data($request, $key_data)
    {
        $data = DataSetting::firstOrNew(
            [
                'key' => $key_data,
            ],
        );

        $data->type = 'app_settings';
        $data->value = $request->{$key_data}[array_search('default', $request->lang)];
        $data->save();
        $default_lang = str_replace('_', '-', app()->getLocale());
        foreach ($request->lang as $index => $key) {
            if ($default_lang == $key && ! ($request->{$key_data}[$index])) {
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\DataSetting',
                            'translationable_id' => $data->id,
                            'locale' => $key,
                            'key' => $key_data,
                        ],
                        ['value' => $data->value]
                    );
                }
            } else {
                if ($request->{$key_data}[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\DataSetting',
                            'translationable_id' => $data->id,
                            'locale' => $key,
                            'key' => $key_data,
                        ],
                        ['value' => $request->{$key_data}[$index]]
                    );
                }
            }
        }
    }


    private function update_data($request, $key_data)
    {
        $data = DataSetting::firstOrNew(
            [
                'key' => $key_data,
                'type' => 'admin_landing_page',
            ],
        );

        $data->value = $request->{$key_data}[array_search('default', $request->lang)];
        $data->save();
        $default_lang = str_replace('_', '-', app()->getLocale());
        foreach ($request->lang as $index => $key) {
            if ($default_lang == $key && ! ($request->{$key_data}[$index])) {
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\DataSetting',
                            'translationable_id' => $data->id,
                            'locale' => $key,
                            'key' => $key_data,
                        ],
                        ['value' => $data->value]
                    );
                }
            } else {
                if ($request->{$key_data}[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\DataSetting',
                            'translationable_id' => $data->id,
                            'locale' => $key,
                            'key' => $key_data,
                        ],
                        ['value' => $request->{$key_data}[$index]]
                    );
                }
            }
        }

        $this->clear_data_settings_cache($key_data);

        return true;
    }

    private function policy_status_update($key_data, $status)
    {
        $data = DataSetting::firstOrNew(
            [
                'key' => $key_data,
                'type' => 'admin_landing_page',
            ],
        );
        $data->value = $status;
        $data->save();
        $this->clear_data_settings_cache($key_data);

        return true;
    }

    private function clear_data_settings_cache($key)
    {
        $locales = ['en', 'zh', 'zh-cn', 'zh-CN', 'zh_CN', ''];
        try {
            $sys = json_decode(\Illuminate\Support\Facades\DB::table('business_settings')->where('key', 'system_language')->value('value'), true) ?? [];
            foreach ($sys as $l) {
                if (!empty($l['code'])) {
                    $locales[] = $l['code'];
                }
            }
        } catch (\Throwable $e) {
        }
        foreach (array_unique($locales) as $loc) {
            \Illuminate\Support\Facades\Cache::forget('data_settings_' . $key . '_' . $loc);
        }
    }

    public function terms_and_conditions()
    {
        $terms_and_conditions = DataSetting::withoutGlobalScope('translate')->where('type', 'admin_landing_page')->where('key', 'terms_and_conditions')->first();

        return view('admin-views.business-settings.terms-and-conditions', compact('terms_and_conditions'));
    }

    public function terms_and_conditions_update(Request $request)
    {
        $this->update_data($request, 'terms_and_conditions');
        Toastr::success(translate('messages.terms_and_condition_updated'));

        return back();
    }

    public function privacy_policy()
    {
        $privacy_policy = DataSetting::withoutGlobalScope('translate')->where('type', 'admin_landing_page')->where('key', 'privacy_policy')->first();

        return view('admin-views.business-settings.privacy-policy', compact('privacy_policy'));
    }

    public function privacy_policy_update(Request $request)
    {
        $this->update_data($request, 'privacy_policy');
        Toastr::success(translate('messages.privacy_policy_updated'));

        return back();
    }

    public function refund_policy()
    {
        $refund_policy = DataSetting::withoutGlobalScope('translate')->where('type', 'admin_landing_page')->where('key', 'refund_policy')->first();
        $refund_policy_status = DataSetting::where('type', 'admin_landing_page')->where('key', 'refund_policy_status')->first();

        return view('admin-views.business-settings.refund_policy', compact('refund_policy', 'refund_policy_status'));
    }

    public function refund_policy_update(Request $request)
    {
        $this->update_data($request, 'refund_policy');
        Toastr::success(translate('messages.refund_policy_updated'));

        return back();
    }

    public function refund_policy_status($status)
    {
        $this->policy_status_update('refund_policy_status', $status);

        return response()->json(['status' => 'changed']);
    }

    public function shipping_policy()
    {

        $shipping_policy = DataSetting::withoutGlobalScope('translate')->where('type', 'admin_landing_page')->where('key', 'shipping_policy')->first();
        $shipping_policy_status = DataSetting::where('type', 'admin_landing_page')->where('key', 'shipping_policy_status')->first();

        return view('admin-views.business-settings.shipping_policy', compact('shipping_policy', 'shipping_policy_status'));
    }

    public function shipping_policy_update(Request $request)
    {
        $this->update_data($request, 'shipping_policy');
        Toastr::success(translate('messages.shipping_policy_updated'));

        return back();
    }

    public function shipping_policy_status($status)
    {
        $this->policy_status_update('shipping_policy_status', $status);

        return response()->json(['status' => 'changed']);
    }

    public function cancellation_policy()
    {
        $cancellation_policy = DataSetting::withoutGlobalScope('translate')->where('type', 'admin_landing_page')->where('key', 'cancellation_policy')->first();
        $cancellation_policy_status = DataSetting::where('type', 'admin_landing_page')->where('key', 'cancellation_policy_status')->first();

        return view('admin-views.business-settings.cancellation_policy', compact('cancellation_policy', 'cancellation_policy_status'));
    }

    public function cancellation_policy_update(Request $request)
    {
        $this->update_data($request, 'cancellation_policy');
        Toastr::success(translate('messages.cancellation_policy_updated'));

        return back();
    }

    public function cancellation_policy_status($status)
    {
        $this->policy_status_update('cancellation_policy_status', $status);

        return response()->json(['status' => 'changed']);
    }

    public function about_us()
    {
        $about_us = DataSetting::withoutGlobalScope('translate')->where('type', 'admin_landing_page')->where('key', 'about_us')->first();

        return view('admin-views.business-settings.about-us', compact('about_us'));
    }

    public function about_us_update(Request $request)
    {
        $this->update_data($request, 'about_us');
        Toastr::success(translate('messages.about_us_updated'));

        return back();
    }

    public function fcm_index()
    {
        $fcm_credentials = Helpers::get_business_settings('fcm_credentials');

        return view('admin-views.business-settings.fcm-config', compact('fcm_credentials'));
    }

    public function notificationMessages(Request $request)
    {
        $messageKey = $this->getPushNotificationMessageKey($request['message-type'] ?? 'user');
        $notificationMessage = NotificationMessage::withOutGlobalScope('translate')->where('user_type', $request['message-type'] ?? 'user')->whereIn('key', $messageKey)->with('translations')->select(['id', 'key', 'message', 'status'])
            ->get()->keyBy('key');

        $cart_reminder_after_time = DataSetting::where(['key' => 'cart_reminder_after_time', 'type' => 'notification_settings'])->first()?->value ?? 0;
        $cart_reminder_after = DataSetting::where(['key' => 'cart_reminder_after', 'type' => 'notification_settings'])->first()?->value ?? 'min';

        return view('admin-views.business-settings.notification.notification_message', compact('notificationMessage', 'messageKey', 'cart_reminder_after_time', 'cart_reminder_after'));
    }

    public function update_fcm(Request $request)
    {
        Helpers::businessUpdateOrInsert(['key' => 'push_notification_service_file_content'], [
            'value' => $request['push_notification_service_file_content'],
        ]);
        Helpers::businessUpdateOrInsert(['key' => 'fcm_project_id'], [
            'value' => $request['projectId'],
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'fcm_credentials'], [
            'value' => json_encode([
                'apiKey' => $request->apiKey,
                'authDomain' => $request->authDomain,
                'projectId' => $request->projectId,
                'storageBucket' => $request->storageBucket,
                'messagingSenderId' => $request->messagingSenderId,
                'appId' => $request->appId,
                'measurementId' => $request->measurementId,
            ]),
        ]);

        self::firebase_message_config_file_gen();

        Toastr::success(translate('messages.settings_updated'));
        session()->put('fcm_updated', 1);

        return redirect()->back();
    }

    public function firebase_message_config_file_gen()
    {
        $config = Helpers::get_business_settings('fcm_credentials');

        $apiKey = $config['apiKey'] ?? '';
        $authDomain = $config['authDomain'] ?? '';
        $projectId = $config['projectId'] ?? '';
        $storageBucket = $config['storageBucket'] ?? '';
        $messagingSenderId = $config['messagingSenderId'] ?? '';
        $appId = $config['appId'] ?? '';
        $measurementId = $config['measurementId'] ?? '';

        $filePath = base_path('firebase-messaging-sw.js');

        try {
            if (file_exists($filePath) && ! is_writable($filePath)) {
                if (! chmod($filePath, 0644)) {
                    throw new \Exception('File is not writable and permission change failed: '.$filePath);
                }
            }

            $fileContent = <<<JS
                importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-app.js');
                importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-messaging.js');

                firebase.initializeApp({
                    apiKey: "$apiKey",
                    authDomain: "$authDomain",
                    projectId: "$projectId",
                    storageBucket: "$storageBucket",
                    messagingSenderId: "$messagingSenderId",
                    appId: "$appId",
                    measurementId: "$measurementId"
                });

                const messaging = firebase.messaging();
                messaging.setBackgroundMessageHandler(function (payload) {
                    return self.registration.showNotification(payload.data.title, {
                        body: payload.data.body ? payload.data.body : '',
                        icon: payload.data.icon ? payload.data.icon : ''
                    });
                });
                JS;

            if (file_put_contents($filePath, $fileContent) === false) {
                throw new \Exception('Failed to write to file: '.$filePath);
            }
        } catch (\Exception $e) {
            //
        }
    }

    private function getPushNotificationMessageKey($userType)
    {
        if ($userType == 'deliveryman') {

            $messageKey = [
                'deliveryman_account_block_message' => 'deliveryman_account_block',
                'deliveryman_account_unblock_message' => 'deliveryman_account_unblock',
                'deliveryman_collect_cash_message' => 'deliveryman_collect_cash',
                'deliveryman_order_assign_message' => 'deliveryman_order_assign',
                'deliveryman_order_unassign_message' => 'deliveryman_order_unassign',
                'deliveryman_order_proceed_for_cooking_message' => 'deliveryman_order_proceed_for_cooking',
                'deliveryman_order_ready_for_delivery_message' => 'deliveryman_order_ready_for_delivery',
                'deliveryman_new_order_message' => 'deliveryman_new_order',
            ];

        } elseif ($userType == 'restaurant') {
            $messageKey = [
                'restaurant_account_block_message' => 'restaurant_account_block',
                'restaurant_account_unblock_message' => 'restaurant_account_unblock',
                'restaurant_withdraw_approve_message' => 'restaurant_withdraw_approve',
                'restaurant_withdraw_rejaction_message' => 'restaurant_withdraw_rejaction',
                'restaurant_campaign_join_approve_message' => 'restaurant_campaign_join_approve',
                'restaurant_campaign_join_rejaction_message' => 'restaurant_campaign_join_rejaction',
                'restaurant_order_notification_message' => 'restaurant_order_notification',
                'restaurant_advertisement_added_by_admin_message' => 'restaurant_advertisement_added_by_admin',
                'restaurant_advertisement_approve_message' => 'restaurant_advertisement_approve',
                'restaurant_advertisement_deny_message' => 'restaurant_advertisement_deny',
                'restaurant_advertisement_pause_message' => 'restaurant_advertisement_pause',
                'restaurant_advertisement_resume_message' => 'restaurant_advertisement_resume',
                'restaurant_subscription_success_message' => 'restaurant_subscription_success',
                'restaurant_subscription_renew_message' => 'restaurant_subscription_renew',
                'restaurant_subscription_shift_message' => 'restaurant_subscription_shift',
                'restaurant_subscription_cancel_message' => 'restaurant_subscription_cancel',
                'restaurant_subscription_plan_update_from_admin_panel' => 'restaurant_subscription_plan_update',
            ];

        } else {
            $messageKey = [
                'order_pending_message' => 'order_pending_message',
                'order_confirmation_message' => 'order_confirmation_msg',
                'order_processing_message' => 'order_processing_message',
                'restaurant_handover_message' => 'order_handover_message',
                'order_out_for_delivery_message' => 'out_for_delivery_message',
                'order_delivered_message' => 'order_delivered_message',
                'deliveryman_assign_message' => 'delivery_boy_assign_message',
                'deliveryman_delivered_message' => 'delivery_boy_delivered_message',
                'order_canceled_message' => 'order_cancled_message',
                'order_refunded_message' => 'order_refunded_message',
                'order_refund_cancel_message' => 'refund_request_canceled',
                'offline_order_accept_message' => 'offline_order_accept_message',
                'offline_order_deny_message' => 'offline_order_deny_message',
                'customer_delivery_verification_message' => 'customer_delivery_verification',
                'customer_dine_in_table_or_token_message' => 'customer_dine_in_table_or_token',
                'customer_add_fund_to_wallet_message' => 'customer_add_fund_to_wallet',
                'customer_referral_bonus_earning_message' => 'customer_referral_bonus_earning',
                'customer_new_referral_join_message' => 'customer_new_referral_join',
                'customer_cashback_message' => 'customer_cashback',
                'customer_account_block_message' => 'customer_account_block',
                'customer_account_unblock_message' => 'customer_account_unblock',
                'cart_abandon'=>'cart_abandon'
            ];
        }

        return $messageKey;
    }

    public function updateFcmMessages(Request $request)
    {

        $messageKey = $this->getPushNotificationMessageKey($request->user_type);
        foreach ($messageKey as $msgKey) {

            $defaultLangIndex = array_search('default', $request->lang, true);

            if ($defaultLangIndex !== false) {
                $request->validate([
                    "{$msgKey}.{$defaultLangIndex}" => "required_if:{$msgKey}_status,1",
                ], [
                    "{$msgKey}.{$defaultLangIndex}.required_if" =>$msgKey .' '. translate('default field is required when status is active.'),
                ]);
            }

            if ($request[$msgKey][array_search('default', $request->lang)] != '') {
                $notification = NotificationMessage::firstOrNew([
                    'user_type' => $request->user_type,
                    'key' => $msgKey,
                ]);

                $notification->message = $request[$msgKey][array_search('default', $request->lang)];
                $notification->status = $request[$msgKey.'_status'] ?? 0;
                $notification->user_type = $request->user_type;
                $notification->save();
                Helpers::add_or_update_translations(request: $request, key_data: $msgKey, name_field: $msgKey, model_name: 'NotificationMessage', data_id: $notification->id, data_value: $notification->message);
            }

        }

        if ($request->user_type == 'user') {
            if ($request->has('cart_reminder_after_time')) {
                DataSetting::updateOrCreate(
                    ['key' => 'cart_reminder_after_time', 'type' => 'notification_settings'],
                    ['value' => $request->cart_reminder_after_time]
                );
            }

            if ($request->has('cart_reminder_after')) {
                DataSetting::updateOrCreate(
                    ['key' => 'cart_reminder_after', 'type' => 'notification_settings'],
                    ['value' => $request->cart_reminder_after]
                );
            }

            if ($request->input('cart_abandon_status') == 1) {
                Session::flash('cart_abandon_dependency', true);
            }
        }
        
        Toastr::success(translate('Push notification messages updated successfully'));

        return back();
    }

    public function config_setup()
    {
        return view('admin-views.business-settings.config');
    }

    public function config_update(Request $request)
    {
        DB::transaction(function () use ($request): void {
            $this->syncMapApiKeyRows('map_api_key', $request->input('map_api_key'));
            $this->syncMapApiKeyRows('map_api_key_server', $request->input('map_api_key_server'));
        });

        Cache::forget('business_settings_all_data');
        Cache::forget('business_settings_keys');

        Toastr::success(translate('messages.config_data_updated'));

        return back();
    }

    private function syncMapApiKeyRows(string $key, mixed $value): void
    {
        $value = $value === null ? null : trim((string) $value);
        $query = DB::table('business_settings')->where('key', $key);
        $timestamp = now();

        if ($query->exists()) {
            $query->update(['value' => $value, 'updated_at' => $timestamp]);

            return;
        }

        DB::table('business_settings')->insert([
            'key' => $key,
            'value' => $value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function toggle_settings($key, $value)
    {
        Helpers::businessUpdateOrInsert(['key' => $key], [
            'value' => $value,
        ]);

        Toastr::success(translate('messages.app_settings_updated'));

        return back();
    }

    public function viewSocialLogin()
    {
        $data = BusinessSetting::where('key', 'social_login')->first();
        if (! $data) {
            Helpers::insert_business_settings_key('social_login', '[{"login_medium":"google","client_id":"","client_secret":"","status":"0"},{"login_medium":"facebook","client_id":"","client_secret":"","status":""}]');
            $data = BusinessSetting::where('key', 'social_login')->first();
        }
        $apple = BusinessSetting::where('key', 'apple_login')->first();
        if (! $apple) {
            Helpers::insert_business_settings_key('apple_login', '[{"login_medium":"apple","client_id":"","client_secret":"","team_id":"","key_id":"","service_file":"","redirect_url":"","status":""}]');
            $apple = BusinessSetting::where('key', 'apple_login')->first();
        }
        $appleLoginServices = json_decode($apple?->value, true);
        $socialLoginServices = json_decode($data?->value, true);

        return view('admin-views.business-settings.social-login.view', compact('socialLoginServices', 'appleLoginServices'));
    }

    public function updateSocialLogin($service, Request $request)
    {
        $login_setup_status = Helpers::get_business_settings($service.'_login_status') ?? 0;
        if ($login_setup_status && ($request['status'] == 0)) {
            Toastr::warning(translate($service.'_login_status_is_enabled_in_login_setup._First_disable_from_login_setup.'));

            return redirect()->back();
        }
        $socialLogin = BusinessSetting::where('key', 'social_login')->first();
        $credential_array = [];
        foreach (json_decode($socialLogin['value'], true) as $key => $data) {
            if ($data['login_medium'] == $service) {
                $cred = [
                    'login_medium' => $service,
                    'client_id' => $request['client_id'],
                    'client_secret' => $request['client_secret'],
                    'status' => $request['status'],
                ];
                array_push($credential_array, $cred);
            } else {
                array_push($credential_array, $data);
            }
        }
        Helpers::businessUpdateOrInsert(['key' => 'social_login'], [
            'value' => $credential_array,
        ]);

        Toastr::success(translate('messages.credential_updated', ['service' => $service]));

        return redirect()->back();
    }

    public function updateAppleLogin($service, Request $request)
    {
        $login_setup_status = Helpers::get_business_settings($service.'_login_status') ?? 0;
        if ($login_setup_status && ($request['status'] == 0)) {
            Toastr::warning(translate($service.'_login_status_is_enabled_in_login_setup._First_disable_from_login_setup.'));

            return redirect()->back();
        }
        $appleLogin = BusinessSetting::where('key', 'apple_login')->first();
        $credential_array = [];
        if ($request->hasfile('service_file')) {
            $fileName = Helpers::upload(dir: 'apple-login/', format: 'p8', image: $request->file('service_file'));
        }
        foreach (json_decode($appleLogin['value'], true) as $key => $data) {
            if ($data['login_medium'] == $service) {
                $cred = [
                    'login_medium' => $service,
                    'client_id' => $request['client_id'],
                    'client_secret' => $request['client_secret'],
                    'status' => $request['status'],
                    'team_id' => $request['team_id'],
                    'key_id' => $request['key_id'],
                    'service_file' => isset($fileName) ? $fileName : $data['service_file'],
                    'redirect_url' => $request['redirect_url'],
                ];
                array_push($credential_array, $cred);
            } else {
                array_push($credential_array, $data);
            }
        }
        Helpers::businessUpdateOrInsert(['key' => 'apple_login'], [
            'value' => $credential_array,
        ]);
        Toastr::success(translate('messages.credential_updated', ['service' => $service]));

        return redirect()->back();
    }

    // recaptcha
    public function recaptcha_index(Request $request)
    {
        return view('admin-views.business-settings.recaptcha-index');
    }

    public function recaptcha_update(Request $request)
    {
        // dd( $request['status']);
        Helpers::businessUpdateOrInsert(['key' => 'recaptcha'], [
            'key' => 'recaptcha',
            'value' => json_encode([
                'status' => $request['status'],
                'site_key' => $request['site_key'],
                'secret_key' => $request['secret_key'],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Toastr::success(translate('messages.updated_successfully'));

        return back();
    }

    public function send_mail(Request $request)
    {
        $response_flag = 0;
        $message = 'success';
        try {

            Mail::to($request->email)->send(new \App\Mail\TestEmailSender);
            $response_flag = 1;
        } catch (\Exception $exception) {
            info([$exception->getFile(), $exception->getLine(), $exception->getMessage()]);
            $response_flag = 2;
            $message = $exception->getMessage();
        }

        return response()->json(['success' => $response_flag, 'message' => $message]);
    }

    public function site_direction(Request $request)
    {
        if (env('APP_MODE') == 'demo') {
            session()->put('site_direction', ($request->status == 1 ? 'ltr' : 'rtl'));

            return response()->json();
        }
        if ($request->status == 1) {
            Helpers::businessUpdateOrInsert(['key' => 'site_direction'], [
                'value' => 'ltr',
            ]);
        } else {
            Helpers::businessUpdateOrInsert(['key' => 'site_direction'], [
                'value' => 'rtl',
            ]);
        }

    }

    public function email_index(Request $request, $type, $tab)
    {
        $template = $request->query('template', null);
        if ($tab == 'new-order') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.place-order-format', compact('template'));
        } elseif ($tab == 'forgot-password') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.forgot-pass-format', compact('template'));
        } elseif ($tab == 'restaurant-registration') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.restaurant-registration-format', compact('template'));
        } elseif ($tab == 'dm-registration') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.dm-registration-format', compact('template'));
        } elseif ($tab == 'registration') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.registration-format', compact('template'));
        } elseif ($tab == 'approve') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.approve-format', compact('template'));
        } elseif ($tab == 'deny') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.deny-format', compact('template'));
        } elseif ($tab == 'withdraw-request') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.withdraw-request-format', compact('template'));
        } elseif ($tab == 'withdraw-approve') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.withdraw-approve-format', compact('template'));
        } elseif ($tab == 'withdraw-deny') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.withdraw-deny-format', compact('template'));
        } elseif ($tab == 'campaign-request') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.campaign-request-format', compact('template'));
        } elseif ($tab == 'campaign-approve') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.campaign-approve-format', compact('template'));
        } elseif ($tab == 'campaign-deny') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.campaign-deny-format', compact('template'));
        } elseif ($tab == 'refund-request') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.refund-request-format', compact('template'));
        } elseif ($tab == 'login') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.login-format', compact('template'));
        } elseif ($tab == 'suspend') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.suspend-format', compact('template'));
        } elseif ($tab == 'unsuspend') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.unsuspend-format', compact('template'));
        } elseif ($tab == 'cash-collect') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.cash-collect-format', compact('template'));
        } elseif ($tab == 'registration-otp') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.registration-otp-format', compact('template'));
        } elseif ($tab == 'login-otp') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.login-otp-format', compact('template'));
        } elseif ($tab == 'order-verification') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.order-verification-format', compact('template'));
        } elseif ($tab == 'refund-request-deny') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.refund-request-deny-format', compact('template'));
        } elseif ($tab == 'add-fund') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.add-fund-format', compact('template'));
        } elseif ($tab == 'refund-order') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.refund-order-format', compact('template'));
        } elseif ($tab == 'offline-payment-approve') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.offline-approved-format', compact('template'));
        } elseif ($tab == 'offline-payment-deny') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.offline-deny-format', compact('template'));
        } elseif ($tab == 'pos-registration') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.pos-registration-format', compact('template'));
        } elseif ($tab == 'new-advertisement') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.new-advertisement-format', compact('template'));
        } elseif ($tab == 'update-advertisement') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.update-advertisement-format', compact('template'));
        } elseif ($tab == 'advertisement-create') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.advertisement-create-format', compact('template'));
        } elseif ($tab == 'advertisement-approved') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.advertisement-approved-format', compact('template'));
        } elseif ($tab == 'advertisement-deny') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.advertisement-deny-format', compact('template'));
        } elseif ($tab == 'advertisement-resume') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.advertisement-resume-format', compact('template'));
        } elseif ($tab == 'advertisement-pause') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.advertisement-pause-format', compact('template'));
        } elseif ($tab == 'subscription-successful') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.subscription-successful-format', compact('template'));
        } elseif ($tab == 'subscription-renew') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.subscription-renew-format', compact('template'));
        } elseif ($tab == 'subscription-shift') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.subscription-shift-format', compact('template'));
        } elseif ($tab == 'subscription-cancel') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.subscription-cancel-format', compact('template'));
        } elseif ($tab == 'subscription-deadline') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.subscription-deadline-format', compact('template'));
        } elseif ($tab == 'subscription-plan_upadte') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.subscription-plan_upadte-format', compact('template'));
        } elseif ($tab == 'profile-verification') {
            return view('admin-views.business-settings.email-format-setting.'.$type.'-email-formats.profile-verification-format', compact('template'));

        }

    }

    public function update_email_index(Request $request, $type, $tab)
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('messages.update_option_is_disable_for_demo'));

            return back();
        }
        if ($tab == 'new-order') {
            $email_type = 'new_order';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'new_order')->first();
        } elseif ($tab == 'forget-password') {
            $email_type = 'forget_password';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'forget_password')->first();
        } elseif ($tab == 'restaurant-registration') {
            $email_type = 'restaurant_registration';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'restaurant_registration')->first();
        } elseif ($tab == 'dm-registration') {
            $email_type = 'dm_registration';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'dm_registration')->first();
        } elseif ($tab == 'registration') {
            $email_type = 'registration';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'registration')->first();
        } elseif ($tab == 'approve') {
            $email_type = 'approve';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'approve')->first();
        } elseif ($tab == 'deny') {
            $email_type = 'deny';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'deny')->first();
        } elseif ($tab == 'withdraw-request') {
            $email_type = 'withdraw_request';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'withdraw_request')->first();
        } elseif ($tab == 'withdraw-approve') {
            $email_type = 'withdraw_approve';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'withdraw_approve')->first();
        } elseif ($tab == 'withdraw-deny') {
            $email_type = 'withdraw_deny';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'withdraw_deny')->first();
        } elseif ($tab == 'campaign-request') {
            $email_type = 'campaign_request';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'campaign_request')->first();
        } elseif ($tab == 'campaign-approve') {
            $email_type = 'campaign_approve';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'campaign_approve')->first();
        } elseif ($tab == 'campaign-deny') {
            $email_type = 'campaign_deny';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'campaign_deny')->first();
        } elseif ($tab == 'refund-request') {
            $email_type = 'refund_request';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'refund_request')->first();
        } elseif ($tab == 'login') {
            $email_type = 'login';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'login')->first();
        } elseif ($tab == 'suspend') {
            $email_type = 'suspend';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'suspend')->first();
        } elseif ($tab == 'unsuspend') {
            $email_type = 'unsuspend';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'unsuspend')->first();
        } elseif ($tab == 'cash-collect') {
            $email_type = 'cash_collect';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'cash_collect')->first();
        } elseif ($tab == 'registration-otp') {
            $email_type = 'registration_otp';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'registration_otp')->first();
        } elseif ($tab == 'login-otp') {
            $email_type = 'login_otp';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'login_otp')->first();
        } elseif ($tab == 'order-verification') {
            $email_type = 'order_verification';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'order_verification')->first();
        } elseif ($tab == 'refund-request-deny') {
            $email_type = 'refund_request_deny';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'refund_request_deny')->first();
        } elseif ($tab == 'add-fund') {
            $email_type = 'add_fund';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'add_fund')->first();
        } elseif ($tab == 'refund-order') {
            $email_type = 'refund_order';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'refund_order')->first();
        } elseif ($tab == 'offline-payment-deny') {
            $email_type = 'offline_payment_deny';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'offline_payment_deny')->first();
        } elseif ($tab == 'offline-payment-approve') {
            $email_type = 'offline_payment_approve';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'offline_payment_approve')->first();
        } elseif ($tab == 'pos-registration') {
            $email_type = 'pos_registration';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'pos_registration')->first();
        } elseif ($tab == 'new-advertisement') {
            $email_type = 'new_advertisement';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'new_advertisement')->first();
        } elseif ($tab == 'update-advertisement') {
            $email_type = 'update_advertisement';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'update_advertisement')->first();
        } elseif ($tab == 'advertisement-pause') {
            $email_type = 'advertisement_pause';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'advertisement_pause')->first();
        } elseif ($tab == 'advertisement-approved') {
            $email_type = 'advertisement_approved';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'advertisement_approved')->first();
        } elseif ($tab == 'advertisement-create') {
            $email_type = 'advertisement_create';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'advertisement_create')->first();
        } elseif ($tab == 'advertisement-deny') {
            $email_type = 'advertisement_deny';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'advertisement_deny')->first();
        } elseif ($tab == 'advertisement-resume') {
            $email_type = 'advertisement_resume';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'advertisement_resume')->first();
        } elseif ($tab == 'subscription-successful') {
            $email_type = 'subscription-successful';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'subscription-successful')->first();
        } elseif ($tab == 'subscription-renew') {
            $email_type = 'subscription-renew';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'subscription-renew')->first();
        } elseif ($tab == 'subscription-shift') {
            $email_type = 'subscription-shift';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'subscription-shift')->first();
        } elseif ($tab == 'subscription-cancel') {
            $email_type = 'subscription-cancel';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'subscription-cancel')->first();
        } elseif ($tab == 'subscription-deadline') {
            $email_type = 'subscription-deadline';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'subscription-deadline')->first();
        } elseif ($tab == 'subscription-plan_upadte') {
            $email_type = 'subscription-plan_upadte';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'subscription-plan_upadte')->first();
        } elseif ($tab == 'profile-verification') {
            $email_type = 'profile_verification';
            $template = EmailTemplate::where('type', $type)->where('email_type', 'profile_verification')->first();
        }
        if ($template == null) {
            $template = new EmailTemplate;
        }

        // dd($type,$tab,$template);
        $template->title = $request->title[array_search('default', $request->lang)];
        $template->body = $request->body[array_search('default', $request->lang)];

        $template->body_2 = $request?->body_2 ? $request->body_2[array_search('default', $request->lang)] : null;
        $template->button_name = $request->button_name ? $request->button_name[array_search('default', $request->lang)] : '';
        $template->footer_text = $request->footer_text[array_search('default', $request->lang)];
        $template->copyright_text = $request->copyright_text[array_search('default', $request->lang)];
        $template->background_image = $request->has('background_image') ? Helpers::update('email_template/', $template->background_image, 'png', $request->file('background_image')) : $template->background_image;
        $template->image = $request->has('image') ? Helpers::update('email_template/', $template->image, 'png', $request->file('image')) : $template->image;
        $template->logo = $request->has('logo') ? Helpers::update('email_template/', $template->logo, 'png', $request->file('logo')) : $template->logo;
        $template->icon = $request->has('icon') ? Helpers::update('email_template/', $template->icon, 'png', $request->file('icon')) : $template->icon;
        $template->email_type = $email_type;
        $template->type = $type;
        $template->button_url = $request->button_url ?? '';
        $template->email_template = $request->email_template;
        $template->privacy = $request->privacy ? '1' : 0;
        $template->refund = $request->refund ? '1' : 0;
        $template->cancelation = $request->cancelation ? '1' : 0;
        $template->contact = $request->contact ? '1' : 0;
        $template->facebook = $request->facebook ? '1' : 0;
        $template->instagram = $request->instagram ? '1' : 0;
        $template->twitter = $request->twitter ? '1' : 0;
        $template->linkedin = $request->linkedin ? '1' : 0;
        $template->pinterest = $request->pinterest ? '1' : 0;
        $template->save();
        $default_lang = str_replace('_', '-', app()->getLocale());
        foreach ($request->lang as $index => $key) {
            if ($default_lang == $key && ! ($request->title[$index])) {
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\EmailTemplate',
                            'translationable_id' => $template->id,
                            'locale' => $key,
                            'key' => 'title',
                        ],
                        ['value' => $template->title]
                    );
                }
            } else {

                if ($request->title[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\EmailTemplate',
                            'translationable_id' => $template->id,
                            'locale' => $key,
                            'key' => 'title',
                        ],
                        ['value' => $request->title[$index]]
                    );
                }
            }
            if ($default_lang == $key && ! ($request->body[$index])) {
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\EmailTemplate',
                            'translationable_id' => $template->id,
                            'locale' => $key,
                            'key' => 'body',
                        ],
                        ['value' => $template->body]
                    );
                }
            } else {

                if ($request->body[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\EmailTemplate',
                            'translationable_id' => $template->id,
                            'locale' => $key,
                            'key' => 'body',
                        ],
                        ['value' => $request->body[$index]]
                    );
                }
            }

            if ($request?->body_2 && $default_lang == $key && ! ($request->body_2[$index])) {
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\EmailTemplate',
                            'translationable_id' => $template->id,
                            'locale' => $key,
                            'key' => 'body_2',
                        ],
                        ['value' => $template->body_2]
                    );
                }
            } else {

                if ($request?->body_2 && $request->body_2[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\EmailTemplate',
                            'translationable_id' => $template->id,
                            'locale' => $key,
                            'key' => 'body_2',
                        ],
                        ['value' => $request->body_2[$index]]
                    );
                }
            }
            if ($default_lang == $key && ! ($request->button_name && $request->button_name[$index])) {
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\EmailTemplate',
                            'translationable_id' => $template->id,
                            'locale' => $key,
                            'key' => 'button_name',
                        ],
                        ['value' => $template->button_name]
                    );
                }
            } else {

                if ($request->button_name && $request->button_name[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\EmailTemplate',
                            'translationable_id' => $template->id,
                            'locale' => $key,
                            'key' => 'button_name',
                        ],
                        ['value' => $request->button_name[$index]]
                    );
                }
            }
            if ($default_lang == $key && ! ($request->footer_text[$index])) {
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\EmailTemplate',
                            'translationable_id' => $template->id,
                            'locale' => $key,
                            'key' => 'footer_text',
                        ],
                        ['value' => $template->footer_text]
                    );
                }
            } else {

                if ($request->footer_text[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\EmailTemplate',
                            'translationable_id' => $template->id,
                            'locale' => $key,
                            'key' => 'footer_text',
                        ],
                        ['value' => $request->footer_text[$index]]
                    );
                }
            }
            if ($default_lang == $key && ! ($request->copyright_text[$index])) {
                if ($key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\EmailTemplate',
                            'translationable_id' => $template->id,
                            'locale' => $key,
                            'key' => 'copyright_text',
                        ],
                        ['value' => $template->copyright_text]
                    );
                }
            } else {

                if ($request->copyright_text[$index] && $key != 'default') {
                    Translation::updateOrInsert(
                        [
                            'translationable_type' => 'App\Models\EmailTemplate',
                            'translationable_id' => $template->id,
                            'locale' => $key,
                            'key' => 'copyright_text',
                        ],
                        ['value' => $request->copyright_text[$index]]
                    );
                }
            }
        }

        Toastr::success(translate('messages.template_added_successfully'));

        return back();
    }

    public function update_email_status(Request $request, $type, $tab, $status)
    {
        if (env('APP_MODE') == 'demo') {
            Toastr::info(translate('messages.update_option_is_disable_for_demo'));

            return back();
        }

        if ($tab == 'place-order') {
            Helpers::businessUpdateOrInsert(['key' => 'place_order_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'forgot-password') {
            Helpers::businessUpdateOrInsert(['key' => 'forget_password_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'restaurant-registration') {
            Helpers::businessUpdateOrInsert(['key' => 'restaurant_registration_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'dm-registration') {
            Helpers::businessUpdateOrInsert(['key' => 'dm_registration_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'registration') {
            Helpers::businessUpdateOrInsert(['key' => 'registration_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'approve') {
            Helpers::businessUpdateOrInsert(['key' => 'approve_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'deny') {
            Helpers::businessUpdateOrInsert(['key' => 'deny_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'withdraw-request') {
            Helpers::businessUpdateOrInsert(['key' => 'withdraw_request_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'withdraw-approve') {
            Helpers::businessUpdateOrInsert(['key' => 'withdraw_approve_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'withdraw-deny') {
            Helpers::businessUpdateOrInsert(['key' => 'withdraw_deny_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'campaign-request') {
            Helpers::businessUpdateOrInsert(['key' => 'campaign_request_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'campaign-approve') {
            Helpers::businessUpdateOrInsert(['key' => 'campaign_approve_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'campaign-deny') {
            Helpers::businessUpdateOrInsert(['key' => 'campaign_deny_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'refund-request') {
            Helpers::businessUpdateOrInsert(['key' => 'refund_request_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'login') {
            Helpers::businessUpdateOrInsert(['key' => 'login_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'suspend') {
            Helpers::businessUpdateOrInsert(['key' => 'suspend_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'unsuspend') {
            Helpers::businessUpdateOrInsert(['key' => 'unsuspend_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'cash-collect') {
            Helpers::businessUpdateOrInsert(['key' => 'cash_collect_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'registration-otp') {
            Helpers::businessUpdateOrInsert(['key' => 'registration_otp_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'login-otp') {
            Helpers::businessUpdateOrInsert(['key' => 'login_otp_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'order-verification') {
            Helpers::businessUpdateOrInsert(['key' => 'order_verification_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'refund-request-deny') {
            Helpers::businessUpdateOrInsert(['key' => 'refund_request_deny_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'add-fund') {
            Helpers::businessUpdateOrInsert(['key' => 'add_fund_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'refund-order') {
            Helpers::businessUpdateOrInsert(['key' => 'refund_order_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'offline-payment-deny') {
            Helpers::businessUpdateOrInsert(['key' => 'offline_payment_deny_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'offline-payment-approve') {
            Helpers::businessUpdateOrInsert(['key' => 'offline_payment_approve_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'pos-registration') {
            Helpers::businessUpdateOrInsert(['key' => 'pos_registration_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'new-advertisement') {
            Helpers::businessUpdateOrInsert(['key' => 'new_advertisement_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'update-advertisement') {
            Helpers::businessUpdateOrInsert(['key' => 'update_advertisement_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'advertisement-resume') {
            Helpers::businessUpdateOrInsert(['key' => 'advertisement_resume_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'advertisement-approved') {
            Helpers::businessUpdateOrInsert(['key' => 'advertisement_approved_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'advertisement-create') {
            Helpers::businessUpdateOrInsert(['key' => 'advertisement_create_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'advertisement-pause') {
            Helpers::businessUpdateOrInsert(['key' => 'advertisement_pause_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'advertisement-deny') {
            Helpers::businessUpdateOrInsert(['key' => 'advertisement_deny_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'subscription-successful') {
            Helpers::businessUpdateOrInsert(['key' => 'subscription_successful_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'subscription-renew') {
            Helpers::businessUpdateOrInsert(['key' => 'subscription_renew_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'subscription-shift') {
            Helpers::businessUpdateOrInsert(['key' => 'subscription_shift_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'subscription-cancel') {
            Helpers::businessUpdateOrInsert(['key' => 'subscription_cancel_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'subscription-deadline') {
            Helpers::businessUpdateOrInsert(['key' => 'subscription_deadline_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'subscription-plan_upadte') {
            Helpers::businessUpdateOrInsert(['key' => 'subscription_plan_upadte_mail_status_'.$type], [
                'value' => $status,
            ]);
        } elseif ($tab == 'profile-verification') {
            Helpers::businessUpdateOrInsert(['key' => 'profile_verification_mail_status_'.$type], [
                'value' => $status,
            ]);
        }
        Toastr::success(translate('messages.email_status_updated'));

        return back();

    }

    public function login_settings()
    {
        $data = array_column(BusinessSetting::whereIn('key', [
            'manual_login_status',
            'otp_login_status',
            'social_login_status',
            'google_login_status',
            'facebook_login_status',
            'apple_login_status',
            'email_verification_status',
            'phone_verification_status',
        ])->get(['key', 'value'])->toArray(), 'value', 'key');

        return view('admin-views.login-setup.login_page', compact('data'));
    }

    public function login_settings_update(Request $request)
    {
        $social_login = [];
        $social_login_data = Helpers::get_business_settings('social_login') ?? [];
        foreach ($social_login_data as $social) {
            $social_login[$social['login_medium']] = (bool) $social['status'];
        }
        $social_login_data = Helpers::get_business_settings('apple_login') ?? [];
        foreach ($social_login_data as $social) {
            $social_login[$social['login_medium']] = (bool) $social['status'];
        }

        $is_firebase_active = Helpers::get_business_settings('firebase_otp_verification') ?? 0;

        $is_sms_active = Setting::where('is_active', 1)->whereJsonContains('live_values->status', '1')->where('settings_type', 'sms_config')->exists();

        $is_mail_active = config('mail.status');

        if (! $request['manual_login_status'] && ! $request['otp_login_status'] && ! $request['social_login_status']) {
            Session::flash('select-one-method', true);

            return back();
        }

        if ($request['otp_login_status'] && ! $is_sms_active && ! $is_firebase_active) {
            Session::flash('sms-config', true);

            return back();
        }

        if (! $request['manual_login_status'] && ! $request['otp_login_status'] && $request['social_login_status']) {
            if (! $request['google_login_status'] && ! $request['facebook_login_status']) {
                Session::flash('select-one-method-android', true);

                return back();
            }
        }
        if ($request['social_login_status'] && ! $request['google_login_status'] && ! $request['facebook_login_status'] && ! $request['apple_login_status']) {
            Session::flash('select-one-method-social-login', true);

            return back();
        }

        if (($request['social_login_status'] && $request['google_login_status'] && ! isset($social_login['google'])) || ($request['social_login_status'] && ($request['google_login_status'] && isset($social_login['google'])) && ! $social_login['google'])) {
            Session::flash('setup-google', true);

            return back();
        }

        if (($request['social_login_status'] && $request['facebook_login_status'] && ! isset($social_login['facebook'])) || ($request['social_login_status'] && ($request['facebook_login_status'] && isset($social_login['facebook'])) && ! $social_login['facebook'])) {
            Session::flash('setup-facebook', true);

            return back();
        }

        if (($request['social_login_status'] && $request['apple_login_status'] && ! isset($social_login['apple'])) || ($request['social_login_status'] && ($request['apple_login_status'] && isset($social_login['apple'])) && ! $social_login['apple'])) {
            Session::flash('setup-apple', true);

            return back();
        }

        if ($request['phone_verification_status'] && ! $is_sms_active && ! $is_firebase_active) {
            Session::flash('sms-config-verification', true);

            return back();
        }

        if ($request['email_verification_status'] && ! $is_mail_active) {
            Session::flash('mail-config-verification', true);

            return back();
        }

        Helpers::businessUpdateOrInsert(['key' => 'manual_login_status'], [
            'value' => $request['manual_login_status'] ? 1 : 0,
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'otp_login_status'], [
            'value' => $request['otp_login_status'] ? 1 : 0,
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'social_login_status'], [
            'value' => $request['social_login_status'] ? 1 : 0,
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'google_login_status'], [
            'value' => $request['social_login_status'] ? ($request['google_login_status'] ? 1 : 0) : 0,
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'facebook_login_status'], [
            'value' => $request['social_login_status'] ? ($request['facebook_login_status'] ? 1 : 0) : 0,
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'apple_login_status'], [
            'value' => $request['social_login_status'] ? ($request['apple_login_status'] ? 1 : 0) : 0,
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'email_verification_status'], [
            'value' => $request['email_verification_status'] ? 1 : 0,
        ]);

        Helpers::businessUpdateOrInsert(['key' => 'phone_verification_status'], [
            'value' => $request['phone_verification_status'] ? 1 : 0,
        ]);

        Toastr::success(translate('messages.login_settings_data_updated_successfully'));

        return back();
    }

    public function firebase_otp_index(Request $request)
    {
        $is_sms_active = Setting::where('is_active', 1)->whereJsonContains('live_values->status', '1')->where('settings_type', 'sms_config')
            ->exists();
        $is_mail_active = config('mail.status');

        return view('admin-views.business-settings.firebase-otp-index', compact('is_sms_active', 'is_mail_active'));
    }

    public function firebase_otp_update(Request $request)
    {
        $login_setup_status = Helpers::get_business_settings('otp_login_status') ?? 0;
        $phone_verification_status = Helpers::get_business_settings('phone_verification_status') ?? 0;
        $is_sms_active = Setting::where('is_active', 1)->whereJsonContains('live_values->status', '1')->where('settings_type', 'sms_config')
            ->exists();
        if (! $is_sms_active && $login_setup_status && ($request['firebase_otp_verification'] == 0)) {
            Toastr::warning(translate('otp_login_status_is_enabled_in_login_setup._First_disable_from_login_setup.'));

            return redirect()->back();
        }
        if (! $is_sms_active && $phone_verification_status && ($request['firebase_otp_verification'] == 0)) {
            Toastr::warning(translate('phone_verification_status_is_enabled_in_login_setup._First_disable_from_login_setup.'));

            return redirect()->back();
        }
        Helpers::businessUpdateOrInsert(['key' => 'firebase_otp_verification'], [
            'value' => $request['firebase_otp_verification'] ?? 0,
        ]);
        Helpers::businessUpdateOrInsert(['key' => 'firebase_web_api_key'], [
            'value' => $request['firebase_web_api_key'],
        ]);

        Toastr::success(translate('messages.updated_successfully'));

        return back();
    }

    public function login_url_page()
    {
        $data = array_column(DataSetting::whereIn('key', [
            'restaurant_employee_login_url',
            'restaurant_login_url',
            'admin_employee_login_url',
            'admin_login_url',
        ])->get(['key', 'value'])->toArray(), 'value', 'key');

        return view('admin-views.login-setup.login_setup', compact('data'));
    }

    public function login_url_page_update(Request $request)
    {

        $request->validate([
            'type' => 'required',
            'admin_login_url' => 'nullable|regex:/^[a-zA-Z0-9\-\_]+$/u|unique:data_settings,value',
            'admin_employee_login_url' => 'nullable|regex:/^[a-zA-Z0-9\-\_]+$/u|unique:data_settings,value',
            'restaurant_login_url' => 'nullable|regex:/^[a-zA-Z0-9\-\_]+$/u|unique:data_settings,value',
            'restaurant_employee_login_url' => 'nullable|regex:/^[a-zA-Z0-9\-\_]+$/u|unique:data_settings,value',
        ]);

        if ($request->type == 'admin') {
            Helpers::dataUpdateOrInsert(['key' => 'admin_login_url', 'type' => 'login_admin'], [
                'value' => $request->admin_login_url,
            ]);
            // Config::set('admin_login_url', $request->admin_login_url);
        } elseif ($request->type == 'admin_employee') {
            Helpers::dataUpdateOrInsert(['key' => 'admin_employee_login_url', 'type' => 'login_admin_employee'], [
                'value' => $request->admin_employee_login_url,
            ]);
        } elseif ($request->type == 'restaurant') {
            Helpers::dataUpdateOrInsert(['key' => 'restaurant_login_url', 'type' => 'login_restaurant'], [
                'value' => $request->restaurant_login_url,
            ]);
        } elseif ($request->type == 'restaurant_employee') {
            Helpers::dataUpdateOrInsert(['key' => 'restaurant_employee_login_url', 'type' => 'login_restaurant_employee'], [
                'value' => $request->restaurant_employee_login_url,
            ]);
        }
        Toastr::success(translate('messages.update_successfull'));

        return back();
    }

    public function remove_image(Request $request)
    {

        $request->validate([
            'model_name' => 'required',
            'id' => 'required',
            'image_path' => 'required',
            'field_name' => 'required',
        ]);
        try {

            $model_name = $request->model_name;
            $model = app("\\App\\Models\\{$model_name}");
            $data = $model->where('id', $request->id)->first();
            // dd($request->image_path);

            $data_value = $data?->{$request->field_name};
            if (! $data_value) {
                $data_value = json_decode($data?->value, true);
            }

            //         dd($data_value);

            if ($request?->json == 1) {
                Helpers::check_and_delete($request->image_path.'/', $data_value[$request->field_name]);
                $data_value[$request->field_name] = null;
                $data->value = json_encode($data_value);
            } else {
                Helpers::check_and_delete($request->image_path.'/', $data_value);
                $data->{$request->field_name} = null;
            }

            $data?->save();

        } catch (\Throwable $th) {
            Toastr::error($th->getMessage().'Line....'.$th->getLine());

            return back();
        }
        Toastr::success(translate('messages.Image_removed_successfully'));

        return back();
    }

    public function notification_setup(Request $request)
    {

        if (NotificationSetting::count() == 0) {
            Helpers::notificationDataSetup();
        }
        Helpers::addNewAdminNotificationSetupDataSetup();
        $data = NotificationSetting::when($request?->type == null || $request?->type == 'admin', function ($query) {
            $query->where('type', 'admin');
        })
            ->when($request?->type == 'restaurant', function ($query) {
                $query->where('type', 'restaurant');
            })
            ->when($request?->type == 'customers', function ($query) {
                $query->where('type', 'customer');
            })
            ->when($request?->type == 'deliveryman', function ($query) {
                $query->where('type', 'deliveryman');
            })->get();

        $business_name = BusinessSetting::where('key', 'business_name')->first()?->value;

        return view('admin-views.business-settings.notification.notification_setup', compact('business_name', 'data'));

    }

    public function notification_status_change($key, $user_type, $type)
    {
        $data = NotificationSetting::where('type', $user_type)->where('key', $key)->first();
        if (! $data) {
            Toastr::error(translate('messages.Notification_settings_not_found'));

            return back();
        }
        if ($type == 'Mail') {
            $data->mail_status = $data->mail_status == 'active' ? 'inactive' : 'active';
        } elseif ($type == 'push_notification') {
            $data->push_notification_status = $data->push_notification_status == 'active' ? 'inactive' : 'active';
        } elseif ($type == 'SMS') {
            $data->sms_status = $data->sms_status == 'active' ? 'inactive' : 'active';
        }
        $data?->save();

        Toastr::success(translate('messages.Notification_settings_updated'));

        return back();
    }

    public function invoiceSetup(Request $request)
    {
        if ($request->isMethod('post')) {
            $settings = [
                'invoice_logo_status' => $request->invoice_logo_status ? 1 : 0,
                'invoice_logo_type' => $request->invoice_logo_type,
                'business_identity_status' => $request->business_identity_status ? 1 : 0,
                'business_identity_type' => $request->business_identity_type,
                'tax_number' => $request->tax_number,
                'bin_number' => $request->bin_number,
                'musak_number' => $request->musak_number,
                'terms&condition_status' => $request->input('terms&condition_status') ? 1 : 0,
                'terms&condition_content' => $request->input('terms&condition_content'),
                'copyright_text_status' => $request->copyright_text_status ? 1 : 0,
                'copyright_text_content' => $request->copyright_text_content,
            ];

            foreach ($settings as $key => $value) {
                Helpers::dataUpdateOrInsert(['key' => $key, 'type' => 'invoice_settings'], [
                    'value' => $value,
                ]);
            }

            if ($request->invoice_logo_type == 'upload_new_logo' && $request->has('invoice_logo')) {
                $image = DataSetting::where('key', 'invoice_logo')->where('type', 'invoice_settings')->first();
                $imageName = Helpers::update('business/', $image?->value, 'png', $request->file('invoice_logo'));
                Helpers::dataUpdateOrInsert(['key' => 'invoice_logo', 'type' => 'invoice_settings'], [
                    'value' => $imageName,
                ]);
            }

            Toastr::success(translate('messages.invoice_settings_updated_successfully'));

            return back();
        }

        $data = array_column(DataSetting::whereIn('key', [
            'invoice_logo_status',
            'invoice_logo_type',
            'invoice_logo',
            'business_identity_status',
            'business_identity_type',
            'tax_number',
            'bin_number',
            'musak_number',
            'terms&condition_status',
            'terms&condition_content',
            'copyright_text_status',
            'copyright_text_content',
        ])->where('type', 'invoice_settings')->get(['key', 'value'])->toArray(), 'value', 'key');

        return view('admin-views.business-settings.invoice-setup.index', compact('data'));
    }
}
