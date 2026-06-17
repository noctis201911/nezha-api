<?php

namespace App\CentralLogics;

use App\Exceptions\InvalidUploadException;
use App\Library\Payer;
use App\Library\Payment as PaymentInfo;
use App\Library\Receiver;
use App\Mail\OrderVerificationMail;
use App\Mail\PlaceOrder;
use App\Mail\SubscriptionRenewOrShift;
use App\Mail\SubscriptionSuccessful;
use App\Models\AddOn;
use App\Models\Allergy;
use App\Models\BusinessSetting;
use App\Models\CashBack;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\DataSetting;
use App\Models\DeliveryManWallet;
use App\Models\DMReview;
use App\Models\Expense;
use App\Models\Food;
use App\Models\NotificationMessage;
use App\Models\NotificationSetting;
use App\Models\Nutrition;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantNotificationSetting;
use App\Models\RestaurantSubscription;
use App\Models\RestaurantWallet;
use App\Models\Review;
use App\Models\Shift;
use App\Models\SubscriptionBillingAndRefundHistory;
use App\Models\SubscriptionPackage;
use App\Models\SubscriptionTransaction;
use App\Models\TimeLog;
use App\Models\Translation;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\VariationOption;
use App\Models\Vehicle;
use App\Models\VisitorLog;
use App\Models\WalletTransaction;
use App\Models\Zone;
use App\Traits\NotificationDataSetUpTrait;
use App\Traits\Payment;
use App\Traits\PaymentGatewayTrait;
use DateInterval;
use DatePeriod;
use DateTime;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use MatanYadaev\EloquentSpatial\Objects\Point;

class Helpers
{
    use NotificationDataSetUpTrait, PaymentGatewayTrait;

    public static function error_processor($validator)
    {
        $err_keeper = [];
        foreach ($validator->errors()->getMessages() as $index => $error) {
            array_push($err_keeper, ['code' => $index, 'message' => translate($error[0])]);
        }

        return $err_keeper;
    }

    public static function error_formater($key, $mesage, $errors = [])
    {
        $errors[] = ['code' => $key, 'message' => $mesage];

        return $errors;
    }

    public static function schedule_order()
    {
        return (bool) self::get_business_settings('schedule_order');
    }

    public static function variation_price($product, $variations)
    {
        $match = $variations;
        $result = 0;
        foreach ($product as $product_variation) {
            foreach ($product_variation['values'] as $option) {
                foreach ($match as $variation) {
                    if ($product_variation['name'] == $variation['name'] && isset($variation['values']) && in_array($option['label'], $variation['values']['label'])) {
                        $result += $option['optionPrice'];
                    }
                }
            }
        }

        return $result;
    }

    public static function cart_product_data_formatting($data, $selected_variation, $selected_addons, $selected_addon_quantity, $trans = false, $local = 'en')
    {

        $variations = [];
        $categories = [];
        $category_ids = gettype($data['category_ids']) == 'array' ? $data['category_ids'] : json_decode($data['category_ids'], true);
        if (!is_array($category_ids)) { $category_ids = []; }
        foreach ($category_ids as $value) {
            $category_name = Category::where('id', $value['id'])->pluck('name');
            $categories[] = ['id' => (string) $value['id'], 'position' => $value['position'] ?? 0, 'name' => data_get($category_name, '0', 'NA')];
        }
        $data['category_ids'] = $categories;

        $add_ons = gettype($data['add_ons']) == 'array' ? $data['add_ons'] : json_decode($data['add_ons'], true);
        if (!is_array($add_ons)) { $add_ons = []; }
        $data_addons = self::addon_data_formatting(AddOn::whereIn('id', $add_ons)->active()->get(), true, $trans, $local);
        // 防御: selected_addons/quantity 为 null 或长度不一致时 array_combine 会崩(空购物车项/老数据); 与上方 category_ids/add_ons 的容错一致
        if (!is_array($selected_addons)) { $selected_addons = []; }
        if (!is_array($selected_addon_quantity)) { $selected_addon_quantity = []; }
        if (count($selected_addons) !== count($selected_addon_quantity)) { $selected_addons = []; $selected_addon_quantity = []; }
        $selected_data = array_combine($selected_addons, $selected_addon_quantity);
        foreach ($data_addons as $addon) {
            $addon_id = $addon['id'];
            if (in_array($addon_id, $selected_addons)) {
                $addon['isChecked'] = true;
                $addon['quantity'] = $selected_data[$addon_id];
            } else {
                $addon['isChecked'] = false;
                $addon['quantity'] = 0;
            }
        }
        $data['addons'] = $data_addons;

        if ($data->title) {
            $data['name'] = $data->title;
            unset($data['title']);
        }
        if ($data->start_time) {
            $data['available_time_starts'] = $data->start_time->format('H:i');
            unset($data['start_time']);
        }
        if ($data->end_time) {
            $data['available_time_ends'] = $data->end_time->format('H:i');
            unset($data['end_time']);
        }
        if ($data->start_date) {
            $data['available_date_starts'] = $data->start_date->format('Y-m-d');
            unset($data['start_date']);
        }
        if ($data->end_date) {
            $data['available_date_ends'] = $data->end_date->format('Y-m-d');
            unset($data['end_date']);
        }
        $data_variation = $data['variations'] ? (gettype($data['variations']) == 'array' ? $data['variations'] : json_decode($data['variations'], true)) : [];
        if (!is_array($selected_variation)) { $selected_variation = []; }
        if (!is_array($data_variation)) { $data_variation = []; }
        foreach ($selected_variation as $item1) {
            foreach ($data_variation as &$item2) {
                if ($item1['name'] === $item2['name']) {
                    foreach ($item2['values'] as &$value) {
                        if (in_array($value['label'], $item1['values']['label'])) {
                            $value['isSelected'] = true;
                        } else {
                            $value['isSelected'] = false;
                        }
                    }
                }
            }
        }
        $discount_data = self::product_discount_calculate_data($data, $data->restaurant);

        $data['discount'] = $discount_data['discount_percentage'];
        $data['discount_type'] = $discount_data['original_discount_type'];

        $data['variations'] = $data_variation;
        $data['restaurant_name'] = $data->restaurant->name;
        $data['restaurant_status'] = (int) $data->restaurant->status;
        $data['restaurant_discount'] = self::get_restaurant_discount($data->restaurant) ? $data->restaurant->discount->discount : 0;
        $data['restaurant_opening_time'] = $data->restaurant->opening_time ? $data->restaurant->opening_time->format('H:i') : null;
        $data['restaurant_closing_time'] = $data->restaurant->closeing_time ? $data->restaurant->closeing_time->format('H:i') : null;
        $data['schedule_order'] = $data->restaurant->schedule_order;
        $data['rating_count'] = (int) ($data->rating ? array_sum(json_decode($data->rating, true)) : 0);
        $data['avg_rating'] = (float) ($data->avg_rating ? $data->avg_rating : 0);
        $data['recommended'] = (int) $data->recommended;

        $data['halal_tag_status'] = (int) $data->restaurant->restaurant_config?->halal_tag_status ?? 0;
        $data['nutritions_name'] = $data?->nutritions ? Nutrition::whereIn('id', $data?->nutritions->pluck('id'))->pluck('nutrition') : null;
        $data['allergies_name'] = $data?->allergies ? Allergy::whereIn('id', $data?->allergies->pluck('id'))->pluck('allergy') : null;
        $data['free_delivery'] = (int) $data->restaurant->free_delivery ?? 0;
        $data['min_delivery_time'] = (int) explode('-', $data->restaurant->delivery_time)[0] ?? 0;
        $data['max_delivery_time'] = (int) explode('-', $data->restaurant->delivery_time)[1] ?? 0;
        $cuisine = [];
        $cui = $data->restaurant->load('cuisine');
        if (isset($cui->cuisine)) {
            foreach ($cui->cuisine as $cu) {
                $cuisine[] = ['id' => (int) $cu->id, 'name' => $cu->name, 'image' => $cu->image];
            }
        }

        $data['cuisines'] = $cuisine;

        unset($data['restaurant']);
        unset($data['rating']);

        return $data;
    }

    public static function product_data_formatting($data, $multi_data = false, $trans = false, $local = 'en', $maxDiscount = true)
    {
        $storage = [];
        if ($multi_data == true) {
            // --- N+1 fix (perf): batch eager-load relations + prebuild id->model maps so the loop stops firing per-item queries ---
            $__nz_items = $data instanceof \Illuminate\Database\Eloquent\Collection ? $data : (is_iterable($data) ? \Illuminate\Database\Eloquent\Collection::make($data) : null);
            $__nz_catMap = collect(); $__nz_addonMap = collect(); $__nz_taxMap = collect(); $__nz_eager = false;
            if ($__nz_items && $__nz_items->isNotEmpty() && $__nz_items->first() instanceof \Illuminate\Database\Eloquent\Model) {
                $__nz_eager = true;
                $__nz_items->loadMissing([
                    'restaurant.discount', 'restaurant.cuisine', 'restaurant.restaurant_config', 'restaurant.restaurant_sub',
                    'tags', 'nutritions', 'allergies', 'taxVats', 'newVariations', 'newVariationOptions',
                    'rating' => function ($q) { $q->where('status', 1); },
                ]);
                $__nz_catIds = []; $__nz_addonIds = []; $__nz_taxIds = [];
                foreach ($__nz_items as $__nz_it) {
                    foreach (json_decode($__nz_it?->category_ids) ?: [] as $__nz_c) { if (isset($__nz_c->id)) $__nz_catIds[] = $__nz_c->id; }
                    foreach (json_decode($__nz_it['add_ons'] ?? '[]') ?: [] as $__nz_a) { $__nz_addonIds[] = $__nz_a; }
                    foreach ($__nz_it->taxVats as $__nz_tv) { $__nz_taxIds[] = $__nz_tv->tax_id; }
                }
                if ($__nz_catIds) { $__nz_catMap = Category::whereIn('id', array_unique($__nz_catIds))->get()->keyBy('id'); }
                if ($__nz_addonIds) { $__nz_addonMap = AddOn::whereIn('id', array_unique($__nz_addonIds))->active()->get()->keyBy('id'); }
                if ($__nz_taxIds) { $__nz_taxMap = \Modules\TaxModule\Entities\Tax::whereIn('id', array_unique($__nz_taxIds))->get(['id', 'name', 'tax_rate'])->keyBy('id'); }
            }
            foreach ($data as $item) {
                $variations = [];
                if ($item->title) {
                    $item['name'] = $item->title;
                    unset($item['title']);
                }
                if ($item->start_time) {
                    $item['available_time_starts'] = $item->start_time->format('H:i');
                    unset($item['start_time']);
                }
                if ($item->end_time) {
                    $item['available_time_ends'] = $item->end_time->format('H:i');
                    unset($item['end_time']);
                }

                if ($item->start_date) {
                    $item['available_date_starts'] = $item->start_date->format('Y-m-d');
                    unset($item['start_date']);
                }
                if ($item->end_date) {
                    $item['available_date_ends'] = $item->end_date->format('Y-m-d');
                    unset($item['end_date']);
                }
                $item['recommended'] = (int) $item->recommended;
                $categories = [];
                foreach (json_decode($item?->category_ids) as $value) {
                    $categories[] = ['id' => (string) $value->id, 'position' => $value->position ?? 1, 'category_name' => ($__nz_catMap->get($value->id) ?? Category::find($value->id))?->name];
                }
                $item['category_ids'] = $categories;
                if ($maxDiscount) {
                    $discount_data = self::product_discount_calculate_data($item, $item->restaurant);
                    $item['discount'] = $discount_data['discount_percentage'];
                    $item['discount_type'] = $discount_data['original_discount_type'];
                }

                $item['add_ons'] = self::addon_data_formatting(($__nz_eager ? $__nz_addonMap->only(json_decode($item['add_ons'] ?? '[]') ?: [])->values() : AddOn::whereIn('id', json_decode($item['add_ons'] ?? '[]'))->active()->get()), true, $trans, $local);
                $item['tags'] = $item->tags;
                $item['variations'] = json_decode($item['variations'], true);
                $item['restaurant_name'] = $item->restaurant->name;
                $item['restaurant_slug'] = $item->restaurant->slug;
                $item['restaurant_status'] = (int) $item->restaurant->status;
                $item['restaurant_discount'] = self::get_restaurant_discount($item->restaurant) ? $item->restaurant->discount->discount : 0;
                $item['restaurant_opening_time'] = $item->restaurant->opening_time ? $item->restaurant->opening_time->format('H:i') : null;
                $item['restaurant_closing_time'] = $item->restaurant->closeing_time ? $item->restaurant->closeing_time->format('H:i') : null;
                $item['schedule_order'] = $item->restaurant->schedule_order;
                $item['tax'] = 0;
                try {
                    $reviewsInfo = $item->relationLoaded('rating') ? $item->getRelation('rating')->first() : $item->rating()->where('status', 1)->first();
                } catch (\Exception $e) {
                    $reviewsInfo = null;
                }
                $item['rating_count'] = $reviewsInfo?->rating_count ?? 0;
                $item['avg_rating'] = $reviewsInfo?->average ?? 0;
                $item['min_delivery_time'] = (int) explode('-', $item->restaurant->delivery_time)[0] ?? 0;
                $item['max_delivery_time'] = (int) explode('-', $item->restaurant->delivery_time)[1] ?? 0;

                if ($item->restaurant->restaurant_model == 'subscription' && isset($item->restaurant->restaurant_sub)) {
                    $item->restaurant['self_delivery_system'] = (int) $item->restaurant->restaurant_sub->self_delivery;
                }

                $item['free_delivery'] = (int) $item->restaurant->free_delivery ?? 0;
                $item['halal_tag_status'] = (int) $item->restaurant->restaurant_config?->halal_tag_status ?? 0;
                $item['nutritions_name'] = $item?->nutritions ? $item->nutritions->pluck('nutrition')->values() : null;
                $item['allergies_name'] = $item?->allergies ? $item->allergies->pluck('allergy')->values() : null;

                if (self::getDeliveryFee($item->restaurant) == 'free_delivery') {
                    $item['free_delivery'] = (int) 1;
                }

                $cuisine = [];
                $cui = $item->restaurant->relationLoaded('cuisine') ? $item->restaurant : $item->restaurant->load('cuisine');
                if (isset($cui->cuisine)) {
                    foreach ($cui->cuisine as $cu) {
                        $cuisine[] = ['id' => (int) $cu->id, 'name' => $cu->name, 'image' => $cu->image];
                    }
                }

                $item['cuisines'] = $cuisine;

                $item['tax_data'] = $item?->taxVats ? ($item->relationLoaded('taxVats') ? $item->taxVats->pluck('tax_id')->toArray() : $item?->taxVats()->pluck('tax_id')->toArray()) : [];

                $item['tax_data'] = $__nz_eager ? $__nz_taxMap->only($item['tax_data'])->values() : \Modules\TaxModule\Entities\Tax::whereIn('id', $item['tax_data'])->get(['id', 'name', 'tax_rate']);
                unset($item['taxVats']);

                unset($item['restaurant']);
                unset($item['rating']);
                array_push($storage, $item);
            }
            $data = $storage;
        } else {
            $variations = [];
            $categories = [];
            foreach ((json_decode($data?->category_ids) ?: []) as $value) {
                $categories[] = ['id' => (string) ($value->id ?? ''), 'position' => $value->position ?? 0];
            }
            $data['category_ids'] = $categories;

            $data['add_ons'] = self::addon_data_formatting(AddOn::whereIn('id', json_decode($data['add_ons']) ?? [])->active()->get(), true, $trans, $local);
            if ($data->title) {
                $data['name'] = $data->title;
                unset($data['title']);
            }
            if ($data->start_time) {
                $data['available_time_starts'] = $data->start_time->format('H:i');
                unset($data['start_time']);
            }
            if ($data->end_time) {
                $data['available_time_ends'] = $data->end_time->format('H:i');
                unset($data['end_time']);
            }
            if ($data->start_date) {
                $data['available_date_starts'] = $data->start_date->format('Y-m-d');
                unset($data['start_date']);
            }
            if ($data->end_date) {
                $data['available_date_ends'] = $data->end_date->format('Y-m-d');
                unset($data['end_date']);
            }
            $data['variations'] = json_decode($data['variations'], true);
            $data['restaurant_name'] = $data->restaurant->name;
            $data['restaurant_slug'] = $data->restaurant->slug;
            $data['restaurant_status'] = (int) $data->restaurant->status;
            $data['restaurant_discount'] = self::get_restaurant_discount($data->restaurant) ? $data->restaurant->discount->discount : 0;
            $data['restaurant_opening_time'] = $data->restaurant->opening_time ? $data->restaurant->opening_time->format('H:i') : null;
            $data['restaurant_closing_time'] = $data->restaurant->closeing_time ? $data->restaurant->closeing_time->format('H:i') : null;
            $data['schedule_order'] = $data->restaurant->schedule_order;
            if ($maxDiscount) {
                $discount_data = self::product_discount_calculate_data($data, $data->restaurant);
                $data['discount'] = $discount_data['discount_percentage'];
                $data['discount_type'] = $discount_data['original_discount_type'];
            }
            try {
                $reviewsInfo = $data->rating()->where('status', 1)->first();
            } catch (\Exception $e) {
                $reviewsInfo = null;
            }
            $data['rating_count'] = (int) $reviewsInfo?->rating_count ?? 0;
            $data['review_count'] = (int) $reviewsInfo?->review_count ?? 0;
            $data['avg_rating'] = (int) $reviewsInfo?->average ?? 0;
            $data['recommended'] = (int) $data->recommended;

            if ($data->restaurant->restaurant_model == 'subscription' && isset($data->restaurant->restaurant_sub)) {
                $data->restaurant['self_delivery_system'] = (int) $data->restaurant->restaurant_sub->self_delivery;
            }

            $data['free_delivery'] = (int) $data->restaurant->free_delivery ?? 0;
            $data['halal_tag_status'] = (int) $data->restaurant->restaurant_config?->halal_tag_status ?? 0;
            $data['nutritions_name'] = $data?->nutritions ? Nutrition::whereIn('id', $data?->nutritions->pluck('id'))->pluck('nutrition') : null;
            $data['allergies_name'] = $data?->allergies ? Allergy::whereIn('id', $data?->allergies->pluck('id'))->pluck('allergy') : null;

            if (self::getDeliveryFee($data->restaurant) == 'free_delivery') {
                $data['free_delivery'] = (int) 1;
            }

            $data['min_delivery_time'] = (int) explode('-', $data->restaurant->delivery_time)[0] ?? 0;
            $data['max_delivery_time'] = (int) explode('-', $data->restaurant->delivery_time)[1] ?? 0;
            $cuisine = [];
            $cui = $data->restaurant->load('cuisine');
            if (isset($cui->cuisine)) {
                foreach ($cui->cuisine as $cu) {
                    $cuisine[] = ['id' => (int) $cu->id, 'name' => $cu->name, 'image' => $cu->image];
                }
            }

            $data['cuisines'] = $cuisine;

            $data['tax_data'] = $data?->taxVats ? $data?->taxVats()->pluck('tax_id')->toArray() : [];
            $data['tax_data'] = \Modules\TaxModule\Entities\Tax::whereIn('id', $data['tax_data'])->get(['id', 'name', 'tax_rate']);
            unset($data['taxVats']);
            $data['ratings'] = is_string($data['rating']) ? json_decode($data['rating'], true) : [];
            unset($data['restaurant']);
            unset($data['rating']);
        }

        return $data;
    }

    public static function product_data_formatting_translate($data, $multi_data = false, $trans = false, $local = 'en')
    {
        $storage = [];
        if ($multi_data == true) {
            foreach ($data as $item) {
                $variations = [];
                if ($item->title) {
                    $item['name'] = $item->title;
                    unset($item['title']);
                }
                if ($item->start_time) {
                    $item['available_time_starts'] = $item->start_time->format('H:i');
                    unset($item['start_time']);
                }
                if ($item->end_time) {
                    $item['available_time_ends'] = $item->end_time->format('H:i');
                    unset($item['end_time']);
                }
                if ($item->start_date) {
                    $item['available_date_starts'] = $item->start_date->format('Y-m-d');
                    unset($item['start_date']);
                }
                if ($item->end_date) {
                    $item['available_date_ends'] = $item->end_date->format('Y-m-d');
                    unset($item['end_date']);
                }
                $item['recommended'] = (int) $item->recommended;
                $categories = [];
                foreach ((json_decode($item['category_ids']) ?: []) as $value) {
                    $categories[] = ['id' => (string) ($value->id ?? ''), 'position' => $value->position ?? 0];
                }
                $item['category_ids'] = $categories;
                $item['attributes'] = json_decode($item['attributes']);
                $item['choice_options'] = json_decode($item['choice_options']);
                $item['add_ons'] = self::addon_data_formatting(AddOn::whereIn('id', json_decode($item['add_ons'], true) ?? [])->active()->get(), true, $trans, $local);

                $item['variations'] = json_decode($item['variations'], true);
                $item['restaurant_name'] = $item->restaurant->name;
                $item['restaurant_slug'] = $item->restaurant->slug;
                $item['zone_id'] = $item->restaurant->zone_id;
                $item['restaurant_discount'] = self::get_restaurant_discount($item->restaurant) ? $item->restaurant->discount->discount : 0;
                $item['schedule_order'] = $item->restaurant->schedule_order;
                $item['tax'] = $item->restaurant->tax;
                try {
                    $reviewsInfo = $item->rating()->first();
                } catch (\Exception $e) {
                    $reviewsInfo = null;
                }
                $item['rating_count'] = $reviewsInfo?->rating_count ?? 0;
                $item['avg_rating'] = $reviewsInfo?->average ?? 0;
                $item['recommended'] = (int) $item->recommended;
                $item['nutritions_name'] = $item?->nutritions ? Nutrition::whereIn('id', $item?->nutritions->pluck('id'))->pluck('nutrition') : null;
                $item['allergies_name'] = $item?->allergies ? Allergy::whereIn('id', $item?->allergies->pluck('id'))->pluck('allergy') : null;

                if ($trans) {
                    $item['translations'][] = [
                        'translationable_type' => 'App\Models\Food',
                        'translationable_id' => $item->id,
                        'locale' => 'en',
                        'key' => 'name',
                        'value' => $item->name,
                    ];

                    $item['translations'][] = [
                        'translationable_type' => 'App\Models\Food',
                        'translationable_id' => $item->id,
                        'locale' => 'en',
                        'key' => 'description',
                        'value' => $item->description,
                    ];
                }

                if (count($item['translations']) > 0) {
                    foreach ($item['translations'] as $translation) {
                        if ($translation['locale'] == $local) {
                            if ($translation['key'] == 'name') {
                                $item['name'] = $translation['value'];
                            }

                            if ($translation['key'] == 'title') {
                                $item['name'] = $translation['value'];
                            }

                            if ($translation['key'] == 'description') {
                                $item['description'] = $translation['value'];
                            }
                        }
                    }
                }
                if (! $trans) {
                    unset($item['translations']);
                }

                $item['tax_ids'] = $item?->taxVats ? $item?->taxVats()->pluck('tax_id')->toArray() : [];

                unset($item['taxVats']);

                unset($item['restaurant']);
                unset($item['rating']);
                array_push($storage, $item);
            }
            $data = $storage;
        } else {
            $variations = [];
            $categories = [];
            foreach (json_decode($data?->category_ids) as $value) {
                $categories[] = ['id' => (string) $value->id, 'position' => $value->position ?? 1, 'category_name' => Category::find($value->id)?->name];
            }
            $data['category_ids'] = $categories;

            $data['attributes'] = json_decode($data['attributes']);
            $data['choice_options'] = json_decode($data['choice_options']);
            $data['add_ons'] = self::addon_data_formatting(AddOn::whereIn('id', json_decode($data['add_ons']) ?? [])->active()->get(), true, $trans, $local);

            if ($data->title) {
                $data['name'] = $data->title;
                unset($data['title']);
            }
            if ($data->start_time) {
                $data['available_time_starts'] = $data->start_time->format('H:i');
                unset($data['start_time']);
            }
            if ($data->end_time) {
                $data['available_time_ends'] = $data->end_time->format('H:i');
                unset($data['end_time']);
            }
            if ($data->start_date) {
                $data['available_date_starts'] = $data->start_date->format('Y-m-d');
                unset($data['start_date']);
            }
            if ($data->end_date) {
                $data['available_date_ends'] = $data->end_date->format('Y-m-d');
                unset($data['end_date']);
            }
            $data['variations'] = json_decode($data['variations'], true);
            $data['restaurant_name'] = $data->restaurant->name;
            $data['restaurant_slug'] = $data->restaurant->slug;
            $data['zone_id'] = $data->restaurant->zone_id;
            $data['restaurant_discount'] = self::get_restaurant_discount($data->restaurant) ? $data->restaurant->discount->discount : 0;
            $data['schedule_order'] = $data->restaurant->schedule_order;
            $data['nutritions_name'] = $data?->nutritions ? Nutrition::whereIn('id', $data?->nutritions->pluck('id'))->pluck('nutrition') : null;
            $data['allergies_name'] = $data?->allergies ? Allergy::whereIn('id', $data?->allergies->pluck('id'))->pluck('allergy') : null;
            try {
                $reviewsInfo = $data->rating()->first();
            } catch (\Exception $e) {
                $reviewsInfo = null;
            }
            $data['rating_count'] = $reviewsInfo?->rating_count ?? 0;
            $data['avg_rating'] = $reviewsInfo?->average ?? 0;

            if ($trans) {
                $data['translations'][] = [
                    'translationable_type' => 'App\Models\Foos',
                    'translationable_id' => $data->id,
                    'locale' => 'en',
                    'key' => 'name',
                    'value' => $data->name,
                ];

                $data['translations'][] = [
                    'translationable_type' => 'App\Models\Food',
                    'translationable_id' => $data->id,
                    'locale' => 'en',
                    'key' => 'description',
                    'value' => $data->description,
                ];
            }

            if (count($data['translations']) > 0) {
                foreach ($data['translations'] as $translation) {
                    if ($translation['locale'] == $local) {
                        if ($translation['key'] == 'name') {
                            $data['name'] = $translation['value'];
                        }

                        if ($translation['key'] == 'title') {
                            $item['name'] = $translation['value'];
                        }

                        if ($translation['key'] == 'description') {
                            $data['description'] = $translation['value'];
                        }
                    }
                }
            }
            if (! $trans) {
                unset($data['translations']);
            }

            $data['tax_ids'] = $data?->taxVats ? $data?->taxVats()->pluck('tax_id')->toArray() : [];

            unset($data['taxVats']);

            unset($data['restaurant']);
            unset($data['rating']);
        }

        return $data;
    }

    public static function addon_data_formatting($data, $multi_data = false, $trans = false, $local = 'en')
    {
        $storage = [];
        if ($multi_data == true) {
            foreach ($data as $item) {
                $item['tax_ids'] = $item?->taxVats ? $item?->taxVats()->pluck('tax_id')->toArray() : [];
                unset($item['taxVats']);
                if ($trans) {
                    $item['translations'][] = [
                        'translationable_type' => 'App\Models\AddOn',
                        'translationable_id' => $item->id,
                        'locale' => 'en',
                        'key' => 'name',
                        'value' => $item->name,
                    ];
                }
                // if (count($item->translations) > 0) {
                //     foreach ($item['translations'] as $translation) {
                //         if ($translation['locale'] == $local && $translation['key'] == 'name') {
                //             $item['name'] = $translation['value'];
                //         }
                //     }
                // }

                // if (!$trans) {
                //     unset($item['translations']);
                // }

                $storage[] = $item;
            }
            $data = $storage;
        } elseif (isset($data)) {
            $item['tax_ids'] = $data?->taxVats ? $data?->taxVats()->pluck('tax_id')->toArray() : [];
            unset($item['taxVats']);
            if ($trans) {
                $data['translations'][] = [
                    'translationable_type' => 'App\Models\AddOn',
                    'translationable_id' => $data->id,
                    'locale' => 'en',
                    'key' => 'name',
                    'value' => $data->name,
                ];
            }

            // if (count($data->translations) > 0) {
            //     foreach ($data['translations'] as $translation) {
            //         if ($translation['locale'] == $local && $translation['key'] == 'name') {
            //             $data['name'] = $translation['value'];
            //         }
            //     }
            // }

            // if (!$trans) {
            //     unset($data['translations']);
            // }
        }

        return $data;
    }

    public static function category_data_formatting($data, $multi_data = false, $trans = false)
    {
        $storage = [];
        if ($multi_data == true) {
            foreach ($data as $item) {
                // if (count($item->translations) > 0) {
                //     $item->name = $item->translations[0]['value'];
                // }

                // if (!$trans) {
                //     unset($item['translations']);
                // }

                if ($item->relationLoaded('childes') && $item['childes']) {
                    $item['products_count'] += $item['childes']->sum('products_count');
                    // unset($item['childes']);
                }
                $storage[] = $item;
            }
            $data = $storage;
        } elseif (isset($data)) {
            // if (count($data->translations) > 0) {
            //     $data->name = $data->translations[0]['value'];
            // }

            // if (!$trans) {
            //     unset($data['translations']);
            // }
            if ($data->relationLoaded('childes') && $data['childes']) {
                $data['products_count'] += $data['childes']->sum('products_count');
                // unset($data['childes']);
            }
        }

        return $data;
    }

    public static function basic_campaign_data_formatting($data, $multi_data = false)
    {
        $storage = [];
        if ($multi_data == true) {
            foreach ($data as $item) {
                $variations = [];

                if ($item->start_date) {
                    $item['available_date_starts'] = $item->start_date->format('Y-m-d');
                    unset($item['start_date']);
                }
                if ($item->end_date) {
                    $item['available_date_ends'] = $item->end_date->format('Y-m-d');
                    unset($item['end_date']);
                }

                // if (count($item['translations']) > 0) {
                //     $translate = array_column($item['translations']->toArray(), 'value', 'key');
                //     $item['title'] = $translate['title'];
                //     $item['description'] = $translate['description'];
                // }
                if (count($item['restaurants']) > 0) {
                    $item['restaurants'] = self::restaurant_data_formatting($item['restaurants'], true);
                }

                array_push($storage, $item);
            }
            $data = $storage;
        } else {
            if ($data->start_date) {
                $data['available_date_starts'] = $data->start_date->format('Y-m-d');
                unset($data['start_date']);
            }
            if ($data->end_date) {
                $data['available_date_ends'] = $data->end_date->format('Y-m-d');
                unset($data['end_date']);
            }

            // if (count($data['translations']) > 0) {
            //     $translate = array_column($data['translations']->toArray(), 'value', 'key');
            //     $data['title'] = $translate['title'];
            //     $data['description'] = $translate['description'];
            // }
            if (count($data['restaurants']) > 0) {
                $data['restaurants'] = self::restaurant_data_formatting($data['restaurants'], true);
            }
        }

        return $data;
    }

    public static function restaurant_data_formatting($data, $multi_data = false)
    {
        $storage = [];
        $cuisines = [];
        $extra_packaging_data = self::get_business_settings('extra_packaging_charge') ?? 0;
        $can_restaurant_edit_order = self::get_business_settings('can_restaurant_edit_order') ?? 0;

        if ($multi_data == true) {
            foreach ($data as $item) {
                $item['foods'] = $item->foods()->active()->take(50)->get(["id", "image", "name", "price", "variations", "add_ons", "category_id", "restaurant_id", "veg", "status"]);
                $item['price_starts_from'] = (float) $item->foods()->active()->min('price');
                $item->load('cuisine');
                // $item['coupons'] = $item->coupon_valid;
                $restaurant_id = (string) $item->id;

                $item['coupons'] = Coupon::Where(function ($q) use ($restaurant_id) {
                    $q->Where('coupon_type', 'restaurant_wise')->whereJsonContains('data', [$restaurant_id])
                        ->where(function ($q1) {
                            $q1->WhereJsonContains('customer_id', ['all']);
                        });
                })->orwhere('restaurant_id', $restaurant_id)
                    ->active()
                    ->valid()
                    ->take(10)
                    ->get();

                if ($item->restaurant_model == 'subscription' && isset($item->restaurant_sub)) {
                    $item['self_delivery_system'] = (int) $item->restaurant_sub->self_delivery;
                }

                $item['delivery_fee'] = self::getDeliveryFee($item);

                $item['restaurant_status'] = (int) $item->status;
                $item['cuisine'] = $item->cuisine;

                if ($item->opening_time) {
                    $item['available_time_starts'] = $item->opening_time->format('H:i');
                    unset($item['opening_time']);
                }
                if ($item->closeing_time) {
                    $item['available_time_ends'] = $item->closeing_time->format('H:i');
                    unset($item['closeing_time']);
                }

                $reviewsInfo = $item->reviews()->where('reviews.status', 1)
                    ->selectRaw('avg(reviews.rating) as average_rating, count(reviews.id) as total_reviews, food.restaurant_id')
                    ->groupBy('food.restaurant_id')
                    ->first();

                $item['ratings'] = $item?->ratings ?? [];
                $item['avg_rating'] = (float) $reviewsInfo?->average_rating ?? 0;
                $item['rating_count'] = (int) $reviewsInfo?->total_reviews ?? 0;

                $positive_rating = RestaurantLogic::calculate_positive_rating($item['rating']);

                $item['positive_rating'] = (int) $positive_rating['rating'];
                $item['take_away'] = (bool) Helpers::get_business_settings('take_away') ?  $item?->take_away : 0;
                $item['delivery'] = (bool) Helpers::get_business_settings('home_delivery') ?  $item?->delivery : 0;

                $item['customer_order_date'] = (int) $item?->restaurant_config?->customer_order_date;
                $item['customer_date_order_sratus'] = (bool) $item?->restaurant_config?->customer_date_order_sratus;
                $item['instant_order'] = (bool) $item?->restaurant_config?->instant_order;
                $item['halal_tag_status'] = (bool) $item?->restaurant_config?->halal_tag_status;
                $item['current_opening_time'] = self::getNextOpeningTime($item['schedules']) ?? 'closed';

                $item['is_extra_packaging_active'] = (bool) ($extra_packaging_data == 1 ? $item?->restaurant_config?->is_extra_packaging_active : false);
                $item['extra_packaging_status'] = (bool) ($item['is_extra_packaging_active'] == 1 ? $item?->restaurant_config?->extra_packaging_status : false);
                $item['extra_packaging_amount'] = (float) ($item['is_extra_packaging_active'] == 1 ? $item?->restaurant_config?->extra_packaging_amount : 0);

                $item['is_dine_in_active'] = (bool) $item?->restaurant_config?->dine_in;
                $item['can_edit_order'] = (bool) ($can_restaurant_edit_order == 1 ? $item?->restaurant_config?->can_edit_order : 0);
                $item['schedule_advance_dine_in_booking_duration'] = (int) $item?->restaurant_config?->schedule_advance_dine_in_booking_duration;
                $item['schedule_advance_dine_in_booking_duration_time_format'] = $item?->restaurant_config?->schedule_advance_dine_in_booking_duration_time_format ?? 'min';

                $item['characteristics'] = $item->characteristics()->pluck('characteristic')->toArray();
                // $item['tags'] = $item->tags()->pluck('tag')->toArray();

                // unset($item['coupon_valid']);
                unset($item['campaigns']);
                unset($item['pivot']);
                unset($item['rating']);
                unset($item['restaurant_config']);
                array_push($storage, $item);
            }
            $data = $storage;
        } else {
            if ($data->restaurant_model == 'subscription' && isset($data->restaurant_sub)) {
                $data['self_delivery_system'] = (int) $data->restaurant_sub->self_delivery;
            }
            $data['restaurant_status'] = (int) $data->status;
            if ($data->opening_time) {
                $data['available_time_starts'] = $data->opening_time->format('H:i');
                unset($data['opening_time']);
            }
            if ($data->closeing_time) {
                $data['available_time_ends'] = $data->closeing_time->format('H:i');
                unset($data['closeing_time']);
            }

            $data['foods'] = $data->foods()->active()->take(50)->get(["id", "image", "name", "price", "variations", "add_ons", "category_id", "restaurant_id", "veg", "status"]);
            $restaurant_id = (string) $data->id;
            $data['coupons'] = Coupon::Where(function ($q) use ($restaurant_id) {
                $q->Where('coupon_type', 'restaurant_wise')->whereJsonContains('data', [$restaurant_id])
                    ->where(function ($q1) {
                        $q1->WhereJsonContains('customer_id', ['all']);
                    });
            })->orwhere('restaurant_id', $restaurant_id)
                ->active()
                ->valid()
                ->take(10)
                ->get();

            $data->load(['cuisine']);
            $data['cuisine'] = $data->cuisine;

            $reviewsInfo = $data->reviews()->where('reviews.status', 1)
                ->selectRaw('avg(reviews.rating) as average_rating, count(reviews.id) as total_reviews, food.restaurant_id')
                ->groupBy('food.restaurant_id')
                ->first();
            $data['ratings'] = $data?->rating ?? [];
            $data['avg_rating'] = (float) $reviewsInfo?->average_rating ?? 0;
            $data['rating_count'] = (int) $reviewsInfo?->total_reviews ?? 0;

            $positive_rating = RestaurantLogic::calculate_positive_rating($data['rating']);
            $data['positive_rating'] = (int) $positive_rating['rating'];
            $data['price_starts_from'] = (float) $data->foods()->active()->min('price');
            $data['customer_order_date'] = (int) $data?->restaurant_config?->customer_order_date;
            $data['customer_date_order_sratus'] = (bool) $data?->restaurant_config?->customer_date_order_sratus;
            $data['instant_order'] = (bool) $data?->restaurant_config?->instant_order;
            $data['halal_tag_status'] = (bool) $data?->restaurant_config?->halal_tag_status;
            $data['is_extra_packaging_active'] = (bool) ($extra_packaging_data == 1 ? $data?->restaurant_config?->is_extra_packaging_active : false);
            $data['extra_packaging_status'] = (bool) ($data['is_extra_packaging_active'] == 1 ? $data?->restaurant_config?->extra_packaging_status : false);
            $data['extra_packaging_amount'] = (float) ($data['is_extra_packaging_active'] == 1 ? $data?->restaurant_config?->extra_packaging_amount : 0);
            $data['delivery_fee'] = self::getDeliveryFee($data);
            $data['current_opening_time'] = self::getNextOpeningTime($data['schedules']) ?? 'closed';

            $data['is_dine_in_active'] = (bool) $data?->restaurant_config?->dine_in;
            $data['schedule_advance_dine_in_booking_duration'] = (int) $data?->restaurant_config?->schedule_advance_dine_in_booking_duration;
            $data['schedule_advance_dine_in_booking_duration_time_format'] = $data?->restaurant_config?->schedule_advance_dine_in_booking_duration_time_format ?? 'min';
            $data['tags'] = $data->tags()->pluck('tag')->toArray();
            $data['can_edit_order'] = (bool) ($can_restaurant_edit_order == 1 ? $data?->restaurant_config?->can_edit_order : 0);

            $data['take_away'] = (bool) Helpers::get_business_settings('take_away') ?  $data?->take_away : 0;
            $data['delivery'] = (bool) Helpers::get_business_settings('home_delivery') ?  $data?->delivery : 0;


            $data['characteristics'] = $data->characteristics()->pluck('characteristic')->toArray();
            unset($data['rating']);
            unset($data['campaigns']);
            unset($data['pivot']);
            unset($data['restaurant_config']);
        }

        return $data;
    }

    public static function wishlist_data_formatting($data, $multi_data = false)
    {
        $foods = [];
        $restaurants = [];
        if ($multi_data == true) {

            foreach ($data as $item) {
                if ($item->food) {
                    $foods[] = self::product_data_formatting($item->food, false, false, app()->getLocale());
                }
                if ($item->restaurant) {
                    $restaurants[] = self::restaurant_data_formatting($item->restaurant);
                }
            }
        } else {
            if ($data->food) {
                $foods[] = self::product_data_formatting($data->food, false, false, app()->getLocale());
            }
            if ($data->restaurant) {
                $restaurants[] = self::restaurant_data_formatting($data->restaurant);
            }
        }

        return ['food' => $foods, 'restaurant' => $restaurants];
    }

    public static function order_data_formatting($data, $multi_data = false)
    {
        $storage = [];
        if ($multi_data) {
            foreach ($data as $item) {
                if (isset($item['restaurant'])) {
                    $item['restaurant_name'] = $item['restaurant']['name'];
                    $item['restaurant_address'] = $item['restaurant']['address'];
                    $item['restaurant_phone'] = $item['restaurant']['phone'];
                    $item['restaurant_lat'] = $item['restaurant']['latitude'];
                    $item['restaurant_lng'] = $item['restaurant']['longitude'];
                    $item['restaurant_logo'] = $item['restaurant']['logo'];
                    $item['restaurant_logo_full_url'] = $item['restaurant']['logo_full_url'];
                    $item['restaurant_delivery_time'] = $item['restaurant']['delivery_time'];
                    $item['vendor_id'] = $item['restaurant']['vendor_id'];
                    $item['chat_permission'] = $item['restaurant']['restaurant_sub']['chat'] ?? 0;
                    $item['restaurant_model'] = $item['restaurant']['restaurant_model'];
                    unset($item['restaurant']);
                } else {
                    $item['restaurant_name'] = null;
                    $item['restaurant_address'] = null;
                    $item['restaurant_phone'] = null;
                    $item['restaurant_lat'] = null;
                    $item['restaurant_lng'] = null;
                    $item['restaurant_logo'] = null;
                    $item['restaurant_logo_full_url'] = null;
                    $item['restaurant_delivery_time'] = null;
                    $item['restaurant_model'] = null;
                    $item['chat_permission'] = null;
                }
                $item['food_campaign'] = 0;
                foreach ($item->details as $d) {
                    if ($d->item_campaign_id != null) {
                        $item['food_campaign'] = 1;
                    }
                }

                $item['delivery_address'] = $item->delivery_address ? json_decode($item->delivery_address, true) : null;
                $item['details_count'] = (int) $item->details->count();
                unset($item['details']);
                array_push($storage, $item);
            }
            $data = $storage;
        } else {
            if (isset($data['restaurant'])) {
                $data['restaurant_name'] = $data['restaurant']['name'];
                $data['restaurant_address'] = $data['restaurant']['address'];
                $data['restaurant_phone'] = $data['restaurant']['phone'];
                $data['restaurant_lat'] = $data['restaurant']['latitude'];
                $data['restaurant_lng'] = $data['restaurant']['longitude'];
                $data['restaurant_logo'] = $data['restaurant']['logo'];
                $data['restaurant_logo_full_url'] = $data['restaurant']['logo_full_url'];
                $data['restaurant_delivery_time'] = $data['restaurant']['delivery_time'];
                $data['vendor_id'] = $data['restaurant']['vendor_id'];
                $data['chat_permission'] = $data['restaurant']['restaurant_sub']['chat'] ?? 0;
                $data['restaurant_model'] = $data['restaurant']['restaurant_model'];
                unset($data['restaurant']);
            } else {
                $data['restaurant_name'] = null;
                $data['restaurant_address'] = null;
                $data['restaurant_phone'] = null;
                $data['restaurant_lat'] = null;
                $data['restaurant_lng'] = null;
                $data['restaurant_logo'] = null;
                $data['restaurant_logo_full_url'] = null;
                $data['restaurant_delivery_time'] = null;
                $data['chat_permission'] = null;
                $data['restaurant_model'] = null;
            }

            $data['food_campaign'] = 0;
            foreach ($data->details as $d) {
                if ($d->item_campaign_id != null) {
                    $data['food_campaign'] = 1;
                }
            }
            $data['delivery_address'] = $data->delivery_address ? json_decode($data->delivery_address, true) : null;
            $data['details_count'] = (int) $data->details->count();
            unset($data['details']);
        }

        return $data;
    }

    public static function order_details_data_formatting($data)
    {
        $storage = [];
        foreach ($data as $item) {
            $item['add_ons'] = json_decode($item['add_ons']);
            $item['variation'] = json_decode($item['variation']);
            $item['food_details'] = json_decode($item['food_details'], true);
            if ($item['item_id']) {
                $product = \App\Models\Food::where(['id' => $item['food_details']['id']])->first();
                $item['image_full_url'] = $product?->image_full_url;
                //                $item['images_full_url'] = $product->images_full_url;
            } else {
                $product = \App\Models\ItemCampaign::where(['id' => $item['food_details']['id']])->first();
                $item['image_full_url'] = $product?->image_full_url;
                //                $item['images_full_url'] = [];
            }
            array_push($storage, $item);
        }
        $data = $storage;

        return $data;
    }

    public static function deliverymen_list_formatting($data, $restaurant_lat = null, $restaurant_lng = null, $single_data = false)
    {
        $storage = [];
        // $map_api_key = BusinessSetting::where(['key' => 'map_api_key_server'])->first()?->value ?? null;

        if ($single_data == true) {
            $item = $data;
            if ($restaurant_lat && $restaurant_lng && $item->last_location) {
                //                    $response = Http::get('https://maps.googleapis.com/maps/api/distancematrix/json?origins=' . $restaurant_lat . ',' . $restaurant_lng . '&destinations=' . ($item->last_location ? $item->last_location->latitude : 0 ). ',' . ($item->last_location ? $item->last_location->longitude : 0) . '&key=' . $map_api_key . '&mode=walking');
                //                    $distance=  $response->json();
                //                    $distance= gettype($distance) == 'array' ? $distance: json_decode($distance,true);
                //                    $distance = data_get($distance,'rows.0.elements.0.distance.text',' ');

                $originCoordinates = [
                    $restaurant_lat,
                    $restaurant_lng,
                ];
                $destinationCoordinates = [
                    $item->last_location->latitude,
                    $item->last_location->longitude,
                ];
                $distance = self::get_distance($originCoordinates, $destinationCoordinates);

                $distance = round($distance, 2).' KM';
            }

            $data = [
                'id' => $item['id'],
                'name' => $item['f_name'].' '.$item['l_name'],
                'image' => $item['image'],
                'image_full_url' => $item['image_full_url'],
                'current_orders' => $item['current_orders'],
                'lat' => $item->last_location ? $item->last_location->latitude : '0',
                'lng' => $item->last_location ? $item->last_location->longitude : '0',
                'location' => $item->last_location ? $item->last_location->location : '',
                'distance' => $distance ?? '',
                'wallet' => $item['wallet'],
            ];

            return $data;
        }

        foreach ($data as $item) {
            if ($restaurant_lat && $restaurant_lng && $item->last_location) {
                //            $response = Http::get('https://maps.googleapis.com/maps/api/distancematrix/json?origins=' . $restaurant_lat . ',' . $restaurant_lng . '&destinations=' . ($item->last_location ? $item->last_location->latitude : 0 ). ',' . ($item->last_location ? $item->last_location->longitude : 0) . '&key=' . $map_api_key . '&mode=walking');
                //            $distance=  $response->json();
                //            $distance= gettype($distance) == 'array' ? $distance: json_decode($distance,true);
                //            $distance = data_get($distance,'rows.0.elements.0.distance.text',' ');

                $originCoordinates = [
                    $restaurant_lat,
                    $restaurant_lng,
                ];
                $destinationCoordinates = [
                    $item->last_location->latitude,
                    $item->last_location->longitude,
                ];
                $distance = self::get_distance($originCoordinates, $destinationCoordinates);
                $distance = round($distance, 2).' KM';
            }

            $storage[] = [
                'id' => $item['id'],
                'name' => $item['f_name'].' '.$item['l_name'],
                'image' => $item['image'],
                'image_full_url' => $item['image_full_url'],
                'current_orders' => $item['current_orders'],
                'lat' => $item->last_location ? $item->last_location->latitude : '0',
                'lng' => $item->last_location ? $item->last_location->longitude : '0',
                'location' => $item->last_location ? $item->last_location->location : '',
                'distance' => $distance ?? '',
                'wallet' => $item['wallet'],
                // 'wallet' => data_get($item, 'wallet'),
            ];
        }

        $data = $storage;

        return $data;
    }

    public static function address_data_formatting($data)
    {
        foreach ($data as $key => $item) {
            $data[$key]['zone_ids'] = array_column(Zone::query()->whereContains('coordinates', new Point($item->latitude, $item->longitude, POINT_SRID))->latest()->get(['id'])->toArray(), 'id');
        }

        return $data;
    }

    public static function deliverymen_data_formatting($data)
    {
        $storage = [];
        foreach ($data as $item) {
            $item['avg_rating'] = (float) (count($item->rating) ? (float) $item->rating[0]->average : 0);
            $item['rating_count'] = (int) (count($item->rating) ? $item->rating[0]->rating_count : 0);
            $item['lat'] = $item->last_location ? $item->last_location->latitude : null;
            $item['lng'] = $item->last_location ? $item->last_location->longitude : null;
            $item['location'] = $item->last_location ? $item->last_location->location : null;

            if ($item['rating']) {
                unset($item['rating']);
            }
            if ($item['last_location']) {
                unset($item['last_location']);
            }
            $storage[] = $item;
        }
        $data = $storage;

        return $data;
    }

    // public static function get_business_settings($name, $json_decode = true)
    // {
    //     $config = null;
    //     $settings = Cache::rememberForever('business_settings_all_data', function () {
    //         return BusinessSetting::all();
    //     });

    //     $data = $settings?->firstWhere('key', $name);
    //     if (isset($data)) {
    //         $config = $json_decode? json_decode($data['value'], true) : $data['value'];
    //         if (is_null($config)) {
    //             $config = $data['value'];
    //         }
    //     }
    //     return $config;
    // }
    public static function get_business_settings($key, $json_decode = true, $relations = [])
    {
        try {
            static $allSettings = null;

            $configKey = $key.'_conf';
            if (Config::has($configKey)) {
                $data = Config::get($configKey);
            } else {
                if (is_null($allSettings)) {
                    $allSettings = Cache::rememberForever('business_settings_all_data', function () {
                        return BusinessSetting::select('key', 'value')->get();
                    });
                }

                $data = $allSettings->firstWhere('key', $key);

                Config::set($configKey, $data);
            }

            if (! isset($data['value'])) {
                return null;
            }

            $value = $data['value'];
            if ($json_decode && is_string($value)) {
                $decoded = json_decode($value, true);

                return is_null($decoded) ? $value : $decoded;
            }

            return $value;
        } catch (\Throwable $th) {
            return null;
        }

    }

    public static function get_data_settings(array $keys)
    {
        return DataSetting::whereIn('key', $keys)->get()->keyBy('key');
    }

    public static function currency_code()
    {
        if (! config('currency')) {
            $currency = self::get_business_settings('currency') ?? BusinessSetting::where(['key' => 'currency'])->first()?->value;
            Config::set('currency', $currency);
        } else {
            $currency = config('currency');
        }

        return $currency;
    }

    public static function currency_symbol()
    {
        if (! config('currency_symbol')) {
            $currency_symbol = Currency::where(['currency_code' => Helpers::currency_code()])->first()?->currency_symbol;
            Config::set('currency_symbol', $currency_symbol);
        } else {
            $currency_symbol = config('currency_symbol');
        }

        return $currency_symbol;
    }

    public static function format_currency($value)
    {
        if(!$value){
            $value = 0;
        };
        if (! config('currency_symbol_position')) {
            $currency_symbol_position = self::get_business_settings('currency_symbol_position') ?? BusinessSetting::where(['key' => 'currency_symbol_position'])->first()?->value;
            Config::set('currency_symbol_position', $currency_symbol_position);
        } else {
            $currency_symbol_position = config('currency_symbol_position');
        }

        return $currency_symbol_position == 'right' ? number_format($value, config('round_up_to_digit')).' '.self::currency_symbol() : self::currency_symbol().' '.number_format($value, config('round_up_to_digit'));
    }

    public static function sendNotificationToHttp(?array $data)
    {
        $config = self::get_business_settings('push_notification_service_file_content');
        $key = (array) $config;
        if (data_get($key, 'project_id')) {
            $url = 'https://fcm.googleapis.com/v1/projects/'.$key['project_id'].'/messages:send';
            $headers = [
                'Authorization' => 'Bearer '.self::getAccessToken($key),
                'Content-Type' => 'application/json',
            ];
            try {
                $http = Http::withHeaders($headers)->post($url, $data);
                info($http->body());
            } catch (\Exception $exception) {
                info($exception->getMessage());

                return false;
            }
        }

        return false;
    }

    public static function getAccessToken($key)
    {
        $jwtToken = [
            'iss' => $key['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => time() + 3600,
            'iat' => time(),
        ];
        $jwtHeader = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $jwtPayload = base64_encode(json_encode($jwtToken));
        $unsignedJwt = $jwtHeader.'.'.$jwtPayload;
        openssl_sign($unsignedJwt, $signature, $key['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = $unsignedJwt.'.'.base64_encode($signature);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        return $response->json('access_token');
    }

    public static function send_push_notif_to_device($fcm_token, $data, $web_push_link = null)
    {
        if (isset($data['conversation_id'])) {
            $conversation_id = $data['conversation_id'];
        } else {
            $conversation_id = '';
        }
        if (isset($data['advertisement_id'])) {
            $advertisement_id = $data['advertisement_id'];
        } else {
            $advertisement_id = '';
        }
        if (isset($data['data_id'])) {
            $data_id = $data['data_id'];
        } else {
            $data_id = '';
        }
        if (isset($data['sender_type'])) {
            $sender_type = $data['sender_type'];
        } else {
            $sender_type = '';
        }
        if (isset($data['order_type'])) {
            $order_type = $data['order_type'];
        } else {
            $order_type = '';
        }

        $click_action = '';
        if ($web_push_link) {
            $click_action = ',
            "click_action": "'.$web_push_link.'"';
        }

        $postData = [
            'message' => [
                'token' => $fcm_token,
                'data' => [
                    'title' => (string) $data['title'],
                    'body' => (string) $data['description'],
                    'image' => (string) $data['image'],
                    'order_id' => (string) $data['order_id'],
                    'type' => (string) $data['type'],
                    'conversation_id' => (string) $conversation_id,
                    'advertisement_id' => (string) $advertisement_id,
                    'data_id' => (string) $data_id,
                    'sender_type' => (string) $sender_type,
                    'order_type' => (string) $order_type,
                    'click_action' => $web_push_link ? (string) $web_push_link : '',
                    'sound' => 'notification.wav',
                ],
                'notification' => [
                    'title' => (string) $data['title'],
                    'body' => (string) $data['description'],
                    'image' => (string) $data['image'],
                ],
                'android' => [
                    'notification' => [
                        'channelId' => 'stackfood',
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'notification.wav',
                        ],
                    ],
                ],
            ],
        ];

        return self::sendNotificationToHttp($postData);
    }

    public static function send_push_notif_to_topic($data, $topic, $type, $web_push_link = null)
    {
        if (isset($data['order_type'])) {
            $order_type = $data['order_type'];
        } else {
            $order_type = '';
        }

        if (isset($data['order_id'])) {
            $postData = [
                'message' => [
                    'topic' => $topic,
                    'data' => [
                        'title' => (string) $data['title'],
                        'body' => (string) $data['description'],
                        'order_id' => (string) $data['order_id'],
                        'order_type' => (string) $order_type,
                        'type' => (string) $type,
                        'image' => (string) $data['image'],
                        'title_loc_key' => (string) $data['order_id'],
                        'body_loc_key' => (string) $type,
                        'click_action' => $web_push_link ? (string) $web_push_link : '',
                        'sound' => 'notification.wav',
                    ],
                    'notification' => [
                        'title' => (string) $data['title'],
                        'body' => (string) $data['description'],
                        'image' => (string) $data['image'],
                    ],
                    'android' => [
                        'notification' => [
                            'channelId' => 'stackfood',
                        ],
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => 'notification.wav',
                            ],
                        ],
                    ],
                ],
            ];
        } else {
            $postData = [
                'message' => [
                    'topic' => $topic,
                    'data' => [
                        'title' => (string) $data['title'],
                        'body' => (string) $data['description'],
                        'order_id' => (string) $data['order_id'],
                        'type' => (string) $type,
                        'image' => (string) $data['image'],
                        'body_loc_key' => (string) $type,
                        'click_action' => $web_push_link ? (string) $web_push_link : '',
                        'sound' => 'notification.wav',
                    ],
                    'notification' => [
                        'title' => (string) $data['title'],
                        'body' => (string) $data['description'],
                        'image' => (string) $data['image'],
                    ],
                    'android' => [
                        'notification' => [
                            'channelId' => 'stackfood',
                        ],
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'sound' => 'notification.wav',
                            ],
                        ],
                    ],
                ],
            ];
        }

        return self::sendNotificationToHttp($postData);
    }

    public static function send_push_notif_for_demo_reset($data, $topic, $type)
    {
        $postData = [
            'message' => [
                'topic' => $topic,
                'data' => [
                    'title' => (string) $data['title'],
                    'body' => (string) $data['description'],
                    'type' => (string) $type,
                    'image' => (string) $data['image'],
                    'body_loc_key' => (string) $type,
                ],
            ],
        ];

        return self::sendNotificationToHttp($postData);
    }

    public static function send_push_notif_for_maintenance_mode($data, $topic, $type)
    {
        $postData = [
            'message' => [
                'topic' => $topic,
                'data' => [
                    'title' => (string) $data['title'],
                    'body' => (string) $data['description'],
                    'type' => (string) $type,
                    'image' => (string) $data['image'],
                    'body_loc_key' => (string) $type,
                ],
            ],
        ];

        return self::sendNotificationToHttp($postData);
    }

    public static function rating_count($food_id, $rating)
    {
        return Review::where(['food_id' => $food_id, 'rating' => $rating])->count();
    }

    public static function dm_rating_count($deliveryman_id, $rating)
    {
        return DMReview::where(['delivery_man_id' => $deliveryman_id, 'rating' => $rating])->count();
    }

    public static function tax_calculate($food, $price)
    {
        if ($food['tax_type'] == 'percent') {
            $price_tax = ($price / 100) * $food['tax'];
        } else {
            $price_tax = $food['tax'];
        }

        return $price_tax;
    }

    public static function discount_calculate($product, $price)
    {
        if ($product['restaurant_discount']) {
            $price_discount = ($price / 100) * $product['restaurant_discount'];
        } elseif ($product['discount_type'] == 'percent') {
            $price_discount = ($price / 100) * $product['discount'];
        } else {
            $price_discount = $product['discount'];
        }

        return $price_discount;
    }

    public static function get_product_discount($product)
    {
        $restaurant_discount = self::get_restaurant_discount($product->restaurant);
        if ($restaurant_discount) {
            $discount = $restaurant_discount['discount'].' %';
        } elseif ($product['discount_type'] == 'percent') {
            $discount = $product['discount'].' %';
        } else {
            $discount = self::format_currency($product['discount']);
        }

        return $discount;
    }

    public static function product_discount_calculate($product, $price, $restaurant)
    {
        $restaurant_discount = self::get_restaurant_discount($restaurant);
        if (isset($restaurant_discount)) {
            $price_discount = ($price / 100) * $restaurant_discount['discount'];
        } elseif ($product['discount_type'] == 'percent') {
            $price_discount = ($price / 100) * $product['discount'];
        } else {
            $price_discount = $product['discount'];
        }

        return $price_discount;
    }

    public static function product_discount_calculate_data($product, $restaurant)
    {
        $restaurant_discount = self::get_restaurant_discount($restaurant);
        if (isset($restaurant_discount)) {
            $price_discount = $restaurant_discount['discount'];
            $original_discount_type = 'percent';

        } else {
            $original_discount_type = $product['discount_type'];
            $price_discount = $product['discount'];
        }

        return [
            'discount_percentage' => $price_discount,
            'original_discount_type' => $original_discount_type,
        ];
    }

    public static function food_discount_calculate($product, $price, $restaurant, $check_restaurant_discount = true)
    {
        $discount_percentage = 0;
        $restaurant_discount_percentage = 0;
        $restaurant_discount = null;
        $restaurant_price_discount = 0;

        if ($check_restaurant_discount) {
            $restaurant_discount = self::get_restaurant_discount($restaurant);
            if (isset($restaurant_discount)) {
                $restaurant_price_discount = ($price / 100) * $restaurant_discount['discount'];
                $restaurant_discount_percentage = $restaurant_discount['discount'];
            }
        }

        $discount_percentage = $product['discount'];
        if ($product['discount_type'] == 'percent') {
            $price_discount = ($price / 100) * $product['discount'];
        } else {
            $price_discount = $product['discount'];
        }

        $discount_percentage = isset($restaurant_discount) && $price_discount == $restaurant_price_discount ? $restaurant_discount_percentage : $discount_percentage ?? 0;

        $price_discount = max($restaurant_price_discount, $price_discount);
        $discount_type = isset($restaurant_discount) && $price_discount == $restaurant_price_discount ? 'admin' : 'discount_on_product';

        return [
            'discount_type' => $discount_type,
            'discount_amount' => $price_discount,
            'discount_percentage' => $discount_type == 'admin' ? $restaurant_discount['discount'] : $product['discount'],
            'original_discount_type' => $discount_type == 'admin' ? 'percent' : $product['discount_type'],
        ];
    }

    public static function get_price_range($product, $discount = false)
    {
        $lowest_price = $product->price;

        if ($discount) {
            $lowest_price -= self::product_discount_calculate($product, $lowest_price, $product->restaurant);
        }
        $lowest_price = self::format_currency($lowest_price);

        return $lowest_price;
    }

    public static function get_restaurant_discount($restaurant)
    {
        // dd($restaurant);
        if ($restaurant->discount) {
            if (date('Y-m-d', strtotime($restaurant->discount->start_date)) <= now()->format('Y-m-d') && date('Y-m-d', strtotime($restaurant->discount->end_date)) >= now()->format('Y-m-d') && date('H:i', strtotime($restaurant->discount->start_time)) <= now()->format('H:i') && date('H:i', strtotime($restaurant->discount->end_time)) >= now()->format('H:i')) {
                return [
                    'discount' => $restaurant->discount->discount,
                    'min_purchase' => $restaurant->discount->min_purchase,
                    'max_discount' => $restaurant->discount->max_discount,
                ];
            }
        }

        return null;
    }

    public static function max_earning()
    {
        $data = Order::where(['order_status' => 'delivered'])->select('id', 'created_at', 'order_amount')
            ->get()
            ->groupBy(function ($date) {
                return Carbon::parse($date->created_at)->format('m');
            });

        $max = 0;
        foreach ($data as $month) {
            $count = 0;
            foreach ($month as $order) {
                $count += $order['order_amount'];
            }
            if ($count > $max) {
                $max = $count;
            }
        }

        return $max;
    }

    public static function max_orders()
    {
        $data = Order::select('id', 'created_at')
            ->get()
            ->groupBy(function ($date) {
                return Carbon::parse($date->created_at)->format('m');
            });

        $max = 0;
        foreach ($data as $month) {
            $count = 0;
            foreach ($month as $order) {
                $count += 1;
            }
            if ($count > $max) {
                $max = $count;
            }
        }

        return $max;
    }

    public static function order_status_update_message($status, $lang = 'default', $userType = 'user')
    {
        $key = self::getOrderPushNotificationMessageByStatus();

        if (isset($key[$status])) {
            $status = $key[$status];
        }

        $message = NotificationMessage::where('status', 1)->where('user_type', $userType)
            ->with(['translations' => function ($query) use ($lang) {
                $query->where('locale', $lang);
            }])
            ->where('key', $status)->first()?->message;

        if (in_array($message, ['', ' ', null])) {
            $message = NotificationMessage::where('status', 1)->where('user_type', $userType)->where('key', $status)->first()?->message;
        }

        return $message;

    }

    public static function getOrderPushNotificationMessageByStatus()
    {
        return [
            'pending' => 'order_pending_message',
            'confirmed' => 'order_confirmation_msg',
            'processing' => 'order_processing_message',
            'picked_up' => 'out_for_delivery_message',
            'handover' => 'order_handover_message',
            'delivered' => 'order_delivered_message',
            'delivery_boy_delivered' => 'delivery_boy_delivered_message',
            'accepted' => 'delivery_boy_assign_message',
            'canceled' => 'order_cancled_message',
            'refunded' => 'order_refunded_message',
            'refund_request_canceled' => 'refund_request_canceled',
            'offline_verified' => 'offline_order_accept_message',
            'offline_denied' => 'offline_order_deny_message',
        ];
    }

    public static function send_order_notification($order)
    {

        try {
            self::sentUserNotification($order);
            self::sentAdminPanelNotification($order);
            self::sentDeliveryManNotification($order);
            self::sentRestaurantNotification($order);
            self::sendOrderPlaceMail($order);

            return true;
        } catch (\Exception $exception) {
            info([$exception->getFile(), $exception->getLine(), $exception->getMessage()]);
        }

        return false;
    }

    public static function getOrderPushNotificationMessage($order, $status, $userType, $lang = 'en')
    {
        $message = self::order_status_update_message($status, $lang, $userType);
        if ($message == null) {
            return $message;
        }
        if ($order->is_guest) {
            $customer_details = json_decode($order['delivery_address'], true);
            $userName = $customer_details['contact_person_name'];
        } else {
            $userName = $order?->customer?->f_name.' '.$order?->customer?->l_name;
        }

        if ($order->OrderReference) {
            $token_number = $order->OrderReference?->token_number;
            $table_number = $order->OrderReference?->table_number;
        }

        return self::text_variable_data_format(value: $message, restaurant_name: $order?->restaurant?->name, order_id: $order->id, user_name: $userName, delivery_man_name : $order?->delivery_man?->f_name.' '.$order?->delivery_man?->l_name, otp: $order?->otp, token_number: $token_number ?? null, table_number: $table_number ?? null);
    }

    public static function getPushNotificationMessage($status, $userType, $lang = 'en', $restaurantName = null, $orderId = null, $userName = null, $deliveryManName = null)
    {
        $message = self::order_status_update_message($status, $lang, $userType);
        if ($message == null) {
            return $message;
        }

        return self::text_variable_data_format(value: $message, delivery_man_name : $deliveryManName, restaurant_name: $restaurantName, order_id: $orderId, user_name: $userName);
    }

    public static function sentNotificationToDeliveryManTopic($order)
    {
        $message = self::order_status_update_message('deliveryman_new_order', 'en', 'deliveryman');

        if ($message == null) {
            return true;
        }

        if ($order->is_guest) {
            $customer_details = json_decode($order['delivery_address'], true);
            $userName = $customer_details['contact_person_name'];
        } else {
            $userName = $order?->customer?->f_name.' '.$order?->customer?->l_name;
        }
        $message = self::text_variable_data_format(value: $message, restaurant_name: $order?->restaurant?->name, order_id: $order->id, user_name: $userName);
        $data = self::makeDataForPushNotification(title: translate('New_Order_Notification'), message: $message, orderId: $order->id, type: '', orderStatus: '');
        if ($order->zone && $order->order_type != 'dine_in') {
            if (!$order->vehicle_id) {
                 self::send_push_notif_to_topic($data, $order->zone->deliveryman_wise_topic, 'order_request');
                 return true;
            }
            $time = $order->schedule_at ?? $order->created_at;
            $shiftIds = Shift::where('status', 1)->whereTime('start_time', '<=', $time)->whereTime('end_time', '>=', $time)->pluck('id');
            if ($shiftIds->isNotEmpty()) {
                foreach ($shiftIds as $shiftId) {
                    $topic = "delivery_man_{$order->zone_id}_{$order->vehicle_id}_{$shiftId}";
                    self::send_push_notif_to_topic($data, $topic, 'order_request');
                }
            } else {
                $topic = "delivery_man_{$order->zone_id}_{$order->vehicle_id}";
                self::send_push_notif_to_topic($data, $topic, 'order_request');
            }
        }

        return true;

    }

    public static function sentDeliveryManNotification($order)
    {
        if ($order->order_type == 'delivery' && ! $order->scheduled && $order->order_status == 'confirmed' && ($order->payment_method != 'cash_on_delivery' || config('order_confirmation_model') == 'restaurant')) {
            $message = self::getOrderPushNotificationMessage($order, 'restaurant_order_notification', 'restaurant', lang: $order?->restaurant?->vendor?->current_language_key);
            $data = self::makeDataForPushNotification(title: translate('New_Order_Notification'), message: $message, orderId: $order->id, type: '', orderStatus: '');
            $restaurant_push_notification_status = self::getRestaurantNotificationStatusData($order?->restaurant?->id, 'restaurant_order_notification');
            if ($order->restaurant->sub_self_delivery && $message && $restaurant_push_notification_status?->push_notification_status == 'active') {
                self::send_push_notif_to_topic($data, 'restaurant_dm_'.$order->restaurant_id, 'order_request');
            } elseif (!$order->restaurant->sub_self_delivery) {
                self::sentNotificationToDeliveryManTopic($order);
            }
        }

        if (in_array($order->order_status, ['processing', 'handover']) && $order->delivery_man && $order->delivery_man->status == 1) {
            $message = self::getOrderPushNotificationMessage($order, $order->order_status == 'processing' ? 'deliveryman_order_proceed_for_cooking' : 'deliveryman_order_ready_for_delivery', 'deliveryman', $order?->delivery_man?->current_language_key??'en');

            if ($message && $order->delivery_man->fcm_token) {
                $data = self::makeDataForPushNotification(title: translate('Order_Notification'), message: $message, orderId: $order->id, type: 'order_status', orderStatus: $order->order_status);
                self::send_push_notif_to_device($order->delivery_man->fcm_token, $data);
                self::insertDataOnNotificationTable($data, 'delivery_man', $order->delivery_man->id);
            }
        }

        return true;
    }

    public static function sendTelegramOrderAlert($order)
    {
        try {
            if (!$order || !in_array($order->order_status, ['pending', 'confirmed'])) {
                return;
            }
            $token = self::get_business_settings('telegram_bot_token', false);
            $chatId = $order?->restaurant?->telegram_chat_id;
            if (!$token || !is_string($token) || !$chatId) {
                return;
            }
            if (!\Illuminate\Support\Facades\Cache::add('tg_alert_' . $order->id, 1, now()->addDay())) {
                return;
            }
            $typeMap = ['delivery' => '配送', 'take_away' => '自取', 'dine_in' => '堂食'];
            $otype = $typeMap[$order->order_type] ?? $order->order_type;
            $itemStr = '';
            try {
                $items = [];
                foreach (($order->details ?? []) as $d) {
                    $fd = is_string($d->food_details) ? json_decode($d->food_details, true) : (array) $d->food_details;
                    $items[] = ($fd['name'] ?? '商品') . ' x' . $d->quantity;
                }
                if (count($items)) {
                    $itemStr = implode('，', array_slice($items, 0, 8)) . (count($items) > 8 ? ' 等' : '');
                }
            } catch (\Throwable $e) {
                $itemStr = '';
            }
            $total = number_format((float) $order->order_amount, 0);
            $time = $order->created_at ? $order->created_at->format('H:i') : '';
            $text = "🔔 哪吒新订单\n单号 #{$order->id}\n类型：{$otype}\n合计：{$total}֏\n"
                . ($itemStr ? "商品：{$itemStr}\n" : '')
                . ($time ? "时间：{$time}\n" : '')
                . "—— 详情请登录商家后台查看";
            $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'chat_id' => $chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ]),
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('telegram order alert failed: ' . $e->getMessage());
        }
    }

    public static function sentRestaurantNotification($order)
    {
        try { self::sendTelegramOrderAlert($order); } catch (\Throwable $e) {}
        $message = self::getOrderPushNotificationMessage($order, 'restaurant_order_notification', 'restaurant', lang: $order?->restaurant?->vendor?->current_language_key);
        $restaurant_push_notification_status = self::getRestaurantNotificationStatusData($order?->restaurant?->id, 'restaurant_order_notification');
        if ($message == null || $restaurant_push_notification_status?->push_notification_status != 'active') {
            return true;
        }

        $web_push_link = url('/').'/restaurant-panel/order/list/all';
        $data = self::makeDataForPushNotification(title: translate('Order_Notification'), message: $message, orderId: $order->id, type: 'order_status', orderStatus: $order->order_status);

        if ($order->order_status == 'picked_up' && $order?->restaurant?->vendor?->firebase_token) {
            self::send_push_notif_to_device($order->restaurant->vendor->firebase_token, $data);
            self::insertDataOnNotificationTable($data, 'vendor', $order->restaurant->vendor_id);
        }

        $data = self::makeDataForPushNotification(title: translate('New_Order_Notification'), message: $message, orderId: $order->id, type: 'new_order', orderStatus: '');

        if (in_array($order->order_type, ['dine_in', 'delivery']) && ! $order->scheduled && $order->order_status == 'pending' && $order->payment_method == 'cash_on_delivery' && config('order_confirmation_model') == 'deliveryman') {

            if ($order->restaurant->sub_self_delivery) {
                if ($order?->restaurant?->vendor?->firebase_token) {
                    self::send_push_notif_to_device($order->restaurant->vendor->firebase_token, $data);
                }
                self::insertDataOnNotificationTable($data, 'vendor', $order->restaurant->vendor_id);

                self::send_push_notif_to_topic($data, "restaurant_panel_{$order->restaurant_id}_message", 'new_order', $web_push_link);
            } elseif (! $order->restaurant->sub_self_delivery) {
                self::sentNotificationToDeliveryManTopic($order);
            }
        } elseif ($order->order_type == 'delivery' && ! $order->scheduled && $order->order_status == 'pending' && $order->payment_method == 'cash_on_delivery' && config('order_confirmation_model') == 'restaurant') {

            if ($order?->restaurant?->vendor?->firebase_token) {
                self::send_push_notif_to_device($order->restaurant->vendor->firebase_token, $data);
            }
            self::insertDataOnNotificationTable($data, 'vendor', $order->restaurant->vendor_id);
            self::send_push_notif_to_topic($data, "restaurant_panel_{$order->restaurant_id}_message", 'new_order', $web_push_link);
        } elseif (! $order->scheduled && ((in_array($order->order_type, ['dine_in', 'take_away']) && $order->order_status == 'pending') || ($order->payment_method != 'cash_on_delivery' && $order->order_status == 'confirmed'))) {

            if ($order?->restaurant?->vendor?->firebase_token) {
                self::send_push_notif_to_device($order->restaurant->vendor->firebase_token, $data);
            }
            self::insertDataOnNotificationTable($data, 'vendor', $order->restaurant->vendor_id);
            self::send_push_notif_to_topic($data, "restaurant_panel_{$order->restaurant->id}_message", 'new_order', $web_push_link);
        } elseif ($order->order_status == 'confirmed' && $order->order_type != 'take_away' && config('order_confirmation_model') == 'deliveryman' && $order->payment_method == 'cash_on_delivery') {
            if ($order->restaurant->sub_self_delivery && ! in_array($order->order_type, ['dine_in'])) {

                $data = self::makeDataForPushNotification(title: translate('New_Order_Notification'), message: $message, orderId: $order->id, type: '', orderStatus: '');

                self::send_push_notif_to_topic($data, 'restaurant_dm_'.$order->restaurant_id, 'new_order');
            } else {

                $data = self::makeDataForPushNotification(title: translate('New_Order_Notification'), message: $message, orderId: $order->id, type: 'new_order', orderStatus: '');

                if ($order?->restaurant?->vendor?->firebase_token) {
                    self::send_push_notif_to_device($order->restaurant->vendor->firebase_token, $data);
                }
                self::insertDataOnNotificationTable($data, 'vendor', $order->restaurant->vendor_id);
                self::send_push_notif_to_topic($data, "restaurant_panel_{$order->restaurant_id}_message", 'new_order', $web_push_link);

            }

        }

        return true;
    }

    public static function sentUserNotification($order)
    {
        $status = ($order->order_status == 'delivered' && $order->delivery_man) ? 'delivery_boy_delivered' : $order->order_status;
        $user_fcm = $order->is_guest ? $order?->guest?->fcm_token : $order?->customer?->cm_firebase_token;
        $lang = $order->customer ? ($order?->customer?->current_language_key ?: 'en') : 'en';
        $value = self::getOrderPushNotificationMessage($order, $status, 'user', $lang);

        if ($value) {
            // 标题本地化: translate('Order_Notification') 无 zh 译文会回退成英文 "Order Notification" 并烘焙进推送+站内信,
            // 故按顾客 current_language_key 直接给中文标题, 与前端消息中心(按 type 显示)保持一致
            $isZh = $lang && stripos($lang, 'zh') === 0;
            if ($order->order_status == 'refund_request_canceled') {
                $title = $isZh ? '退款被拒' : 'Refund Rejected';
            } else {
                $title = $isZh ? '订单通知' : 'Order Notification';
            }
            $data = self::makeDataForPushNotification(title: $title, message: $value, orderId: $order->id, type: 'order_status', orderStatus: $order->order_status);

            // 实际 FCM 推送只在有 token 时发 (iOS Safari/Chrome 不支持 Web Push, 多数顾客无 token)
            if ($user_fcm) {
                self::send_push_notif_to_device($user_fcm, $data);
            }
            // 站内信不再依赖 token: 登录顾客一律入库, 保证 iOS/未授权推送的顾客出餐后也能在消息中心看到提醒
            if (!$order->is_guest && $order->user_id) {
                self::insertDataOnNotificationTable($data, 'user', $order->user_id);
            }
        }

        return true;
    }

    public static function sentAdminPanelNotification($order)
    {

        if ($order->checked != 1 && ($order->subscription_id == null && (in_array($order->payment_method, ['cash_on_delivery', 'offline_payment']) && $order->order_status == 'pending') || (! in_array($order->payment_method, ['cash_on_delivery', 'offline_payment']) && $order->order_status == 'confirmed'))) {
            $data = self::makeDataForPushNotification(title: translate('New_Order_Notification'), message: translate('New order alert, confirm to proceed'), orderId: $order->id, type: 'new_order_admin');
            self::send_push_notif_to_topic($data, 'admin_message', 'order_request', url('/').'/admin/order/list/all');
        }

        return true;
    }

    public static function makeDataForPushNotification($title, $message, $type, $orderId = null, $orderStatus = null, $dataId = null, $advertisement_id = null, $amount = null)
    {
        return [
            'title' => $title ?? '',
            'description' => $message ?? '',
            'order_id' => $orderId ?? '',
            'image' => '',
            'type' => $type ?? '',
            'order_status' => $orderStatus ?? '',
            'data_id' => $dataId ?? '',
            'advertisement_id' => $advertisement_id ?? '',
            'amount' => $amount ?? '',
        ];
    }

    public static function insertDataOnNotificationTable($data, $userType, $id)
    {
        $validTypes = ['user', 'vendor', 'delivery_man'];

        if (! in_array($userType, $validTypes)) {
            return false;
        }

        $notification = new UserNotification([
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            $userType.'_id' => $id,
        ]);

        return $notification->save();
    }

    public static function sendOrderPlaceMail($order)
    {

        try {
            if ($order?->customer?->email && config('mail.status') && $order->is_guest == 0) {
                $notification_status = Helpers::getNotificationStatusData('customer', 'customer_order_notification');
                if ($notification_status?->mail_status == 'active' && $order->order_status == 'confirmed' && $order->payment_method != 'cash_on_delivery' && Helpers::get_mail_status('place_order_mail_status_user') == '1') {
                    Mail::to($order->customer?->getRawOriginal('email'))->send(new PlaceOrder($order->id));
                }

                $notification_status = Helpers::getNotificationStatusData('customer', 'customer_delivery_verification');
                if ($notification_status?->mail_status == 'active' && $order->order_status == 'pending' && config('order_delivery_verification') == 1 && Helpers::get_mail_status('order_verification_mail_status_user') == '1') {
                    Mail::to($order->customer?->getRawOriginal('email'))->send(new OrderVerificationMail($order->otp, $order->customer->f_name));
                }
            }
        } catch (\Exception $exception) {
            info([$exception->getFile(), $exception->getLine(), $exception->getMessage()]);
        }

        return true;
    }

    public static function day_part()
    {
        $part = '';
        $morning_start = date('h:i:s', strtotime('5:00:00'));
        $afternoon_start = date('h:i:s', strtotime('12:01:00'));
        $evening_start = date('h:i:s', strtotime('17:01:00'));
        $evening_end = date('h:i:s', strtotime('21:00:00'));

        if (time() >= $morning_start && time() < $afternoon_start) {
            $part = 'morning';
        } elseif (time() >= $afternoon_start && time() < $evening_start) {
            $part = 'afternoon';
        } elseif (time() >= $evening_start && time() <= $evening_end) {
            $part = 'evening';
        } else {
            $part = 'night';
        }

        return $part;
    }

    public static function env_update($key, $value)
    {
        $path = base_path('.env');
        if (file_exists($path)) {
            file_put_contents($path, str_replace(
                $key.'='.env($key),
                $key.'='.$value,
                file_get_contents($path)
            ));
        }
    }

    public static function env_key_replace($key_from, $key_to, $value)
    {
        $path = base_path('.env');
        if (file_exists($path)) {
            file_put_contents($path, str_replace(
                $key_from.'='.env($key_from),
                $key_to.'='.$value,
                file_get_contents($path)
            ));
        }
    }

    public static function remove_dir($dir)
    {
        //        if (DOMAIN_POINTED_DIRECTORY == 'public') {
        //            $dir = '../'.$dir;
        //        }
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != '.' && $object != '..') {
                    if (filetype($dir.'/'.$object) == 'dir') {
                        Helpers::remove_dir($dir.'/'.$object);
                    } else {
                        unlink($dir.'/'.$object);
                    }
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }

    public static function get_restaurant_id()
    {
        if (auth('vendor_employee')->check()) {
            return auth('vendor_employee')->user()->restaurant->id;
        }

        return auth('vendor')->user()->restaurants[0]->id;
    }

    public static function get_vendor_id()
    {
        if (auth('vendor')->check()) {
            return auth('vendor')->id();
        } elseif (auth('vendor_employee')->check()) {
            return auth('vendor_employee')->user()->vendor_id;
        }

        return 0;
    }

    public static function get_vendor_data()
    {
        if (auth('vendor')->check()) {
            return auth('vendor')->user();
        } elseif (auth('vendor_employee')->check()) {
            return auth('vendor_employee')->user()->vendor;
        }

        return 0;
    }

    public static function get_loggedin_user()
    {
        if (auth('vendor')->check()) {
            return auth('vendor')->user();
        } elseif (auth('vendor_employee')->check()) {
            return auth('vendor_employee')->user();
        }

        return 0;
    }

    public static function get_restaurant_data()
    {
        if (auth('vendor_employee')->check()) {
            return auth('vendor_employee')->user()->restaurant;
        }

        return auth('vendor')->user()->restaurants[0];
    }

    public static function getDisk()
    {
        $config = self::get_business_settings('local_storage');

        return isset($config) ? ($config == 0 ? 's3' : 'public') : 'public';
    }



    public static function upload(string $dir, string $format, $image = null)
    {
        $validExtForWebp = ['jpg', 'jpeg', 'png'];
        try {
            if ($image != null) {
                self::validateFile($image);

                $format = $image->getClientOriginalExtension();
                if (in_array($format, $validExtForWebp)) {
                    $manager = new ImageManager(Driver::class);
                    $image = $manager->read($image);
                    $image = $image->encode(new WebpEncoder(quality: 80));
                    $format = 'webp';
                }
                $imageName = \Carbon\Carbon::now()->toDateString().'-'.uniqid().'.'.$format;

                if (! Storage::disk(self::getDisk())->exists($dir)) {
                    Storage::disk(self::getDisk())->makeDirectory($dir);
                }

                if ($image instanceof UploadedFile) {
                    Storage::disk(self::getDisk())->putFileAs($dir, $image, $imageName);
                } else {
                    Storage::disk(self::getDisk())->put($dir.'/'.$imageName, $image->toString());
                }

            } else {
                $imageName = 'def.png';
            }
        } catch (InvalidUploadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new InvalidUploadException(
                'Image upload failed. Please try again.'
            );
        }

        return $imageName;
    }

    public static function update(string $dir, $old_image, string $format, $image = null)
    {
        if ($image == null) {
            return $old_image;
        }
        try {
            if ($old_image && Storage::disk(self::getDisk())->exists($dir.$old_image)) {
                Storage::disk(self::getDisk())->delete($dir.$old_image);
            }
        } catch (\Exception $e) {
        }
        $imageName = Helpers::upload($dir, $format, $image);

        return $imageName;
    }

    public static function check_and_delete(string $dir, $old_image)
    {

        try {
            if (Storage::disk('public')->exists($dir.$old_image)) {
                Storage::disk('public')->delete($dir.$old_image);
            }
            if (Storage::disk('s3')->exists($dir.$old_image)) {
                Storage::disk('s3')->delete($dir.$old_image);
            }
        } catch (\Exception $e) {
        }

        return true;
    }

    public static function get_full_url($path, $data, $type, $placeholder = null)
    {
        $place_holders = [
            'default' => dynamicAsset('assets/admin/img/100x100/no-image-found.png'),
            'admin' => dynamicAsset('assets/admin/img/160x160/img1.jpg'),
            'restaurant' => dynamicAsset('assets/admin/img/100x100/1.png'),
            'business' => dynamicAsset('assets/admin/img/160x160/img2.jpg'),
            'product' => dynamicAsset('assets/admin/img/100x100/food-default-image.png'),
            'payment_modules/gateway_image' => dynamicAsset('assets/admin/img/blank3.png'),
            'banner' => dynamicAsset('assets/admin/img/900x400/img1.jpg'),
            'upload_image' => dynamicAsset('assets/admin/img/upload-img.png'),
            'upload_1_1' => dynamicAsset('assets/admin/img/upload-3.png'),
            'upload_placeholder' => dynamicAsset('assets/admin/img/upload-placeholder.png'),
            'email_template' => dynamicAsset('assets/admin/img/blank1.png'),
            'campaign' => dynamicAsset('assets/admin/img/900x400/img1.png'),
            'category' => dynamicAsset('assets/admin/img/900x400/img1.jpg'),
            'cuisine' => dynamicAsset('assets/admin/img/upload-6.png'),
            'delivery-man' => dynamicAsset('assets/admin/img/160x160/img1.jpg'),
            'react_promotional_banner' => dynamicAsset('assets/admin/img/upload-3.png'),
            'react_service_image' => dynamicAsset('assets/admin/img/aspect-1.png'),
            'conversation' => dynamicAsset('assets/admin/img/900x400/img1.png'),
            'notification' => dynamicAsset('assets/admin/img/900x400/img1.png'),
            'vendor' => dynamicAsset('assets/admin/img/160x160/img1.jpg'),
            'react_restaurant_section_image' => dynamicAsset('assets/admin/img/upload-3.png'),
            'react_delivery_section_image' => dynamicAsset('assets/admin/img/upload-3.png'),
            'favicon' => dynamicAsset('assets/admin/img/favicon.png'),
            'authfav' => dynamicAsset('assets/admin/img/auth-fav.png'),
            'refund' => dynamicAsset('assets/admin/img/160x160/img2.jpg'),
            'order' => dynamicAsset('assets/admin/img/160x160/img2.jpg'),
            'ad_cover' => dynamicAsset('assets/admin/img/900x400/img1.png'),
        ];

        try {
            if ($data && $type == 's3' && Storage::disk('s3')->exists($path.'/'.$data)) {
                return Storage::disk('s3')->url($path.'/'.$data);
                //                $awsUrl = config('filesystems.disks.s3.url');
                //                $awsBucket = config('filesystems.disks.s3.bucket');
                //                return rtrim($awsUrl, '/') . '/' . ltrim($awsBucket . '/' . $path . '/' . $data, '/');
            }
        } catch (\Exception $e) {
        }

        if ($data && Storage::disk('public')->exists($path.'/'.$data)) {
            return dynamicStorage('storage/app/public').'/'.$path.'/'.$data;
        }

        if (request()->is('api/*')) {
            return null;
        }

        if (isset($placeholder) && array_key_exists($placeholder, $place_holders)) {
            return $place_holders[$placeholder];
        } elseif (array_key_exists($path, $place_holders)) {
            return $place_holders[$path];
        } else {
            return $place_holders['default'];
        }

        return 'def.png';
    }

    public static function format_coordiantes($coordinates)
    {
        $data = [];
        foreach ($coordinates as $coord) {
            $data[] = (object) ['lat' => $coord[1], 'lng' => $coord[0]];
        }

        return $data;
    }

    public static function module_permission_check($mod_name)
    {

        if (! auth('admin')->user()->role) {
            return false;
        }

        if ($mod_name == 'zone' && auth('admin')->user()->zone_id) {
            return false;
        }

        $permission = auth('admin')->user()->role->modules;
        if (isset($permission) && in_array($mod_name, (array) json_decode($permission)) == true) {
            return true;
        }

        if (auth('admin')->user()->role_id == 1) {
            return true;
        }

        return false;
    }

    public static function employee_module_permission_check($mod_name)
    {

        if (auth('vendor')->check()) {
            if ($mod_name == 'reviews') {
                return auth('vendor')->user()->restaurants[0]->reviews_section;
            } elseif ($mod_name == 'deliveryman') {
                return auth('vendor')->user()->restaurants[0]->self_delivery_system;
            } elseif ($mod_name == 'pos') {
                return auth('vendor')->user()->restaurants[0]->pos_system;
            }

            return true;
        } elseif (auth('vendor_employee')->check()) {
            $permission = auth('vendor_employee')->user()->role->modules;
            if (isset($permission) && in_array($mod_name, (array) json_decode($permission)) == true) {
                if ($mod_name == 'reviews') {
                    return auth('vendor_employee')->user()->restaurant->reviews_section;
                } elseif ($mod_name == 'deliveryman') {
                    return auth('vendor_employee')->user()->restaurant->self_delivery_system;
                } elseif ($mod_name == 'pos') {
                    return auth('vendor_employee')->user()->restaurant->pos_system;
                }

                return true;
            }
        }

        return false;
    }

    public static function calculate_addon_price($addons, $add_on_qtys, $incrementCount = false, $old_selected_addons = [])
    {
        $add_ons_cost = 0;
        $data = [];
        if ($addons) {
            foreach ($addons as $key2 => $addon) {
                if ($add_on_qtys == null) {
                    $add_on_qty = 1;
                } else {
                    $add_on_qty = $add_on_qtys[$key2];
                }
                // if($add_on_qty > 0 ){
                if ($addon->stock_type != 'unlimited') {

                    $availableStock = $addon->addon_stock;

                    if (data_get($old_selected_addons, $addon->id)) {
                        $availableStock = $availableStock + data_get($old_selected_addons, $addon->id);
                    }

                    if ($availableStock <= 0 || $availableStock < $add_on_qty) {
                        return ['out_of_stock' => $addon->name.' '.translate('Addon_is_out_of_stock_!!!'),
                            'id' => $addon->id,
                            'current_stock' => $availableStock > 0 ? $availableStock : 0,
                            'type' => 'addon',
                        ];
                    }
                }
                if ($incrementCount == true) {
                    $addon->increment('sell_count', $add_on_qty);
                }
                // }

                $data[] = ['id' => $addon->id, 'name' => $addon->name, 'price' => $addon->price, 'quantity' => $add_on_qty];
                $add_ons_cost += $addon['price'] * $add_on_qty;
            }

            return ['addons' => $data, 'total_add_on_price' => $add_ons_cost];
        }

        return null;
    }

    public static function get_settings($name)
    {
        $config = null;
        $data = BusinessSetting::where(['key' => $name])->first();
        if (isset($data)) {
            $config = json_decode($data['value'], true);
            if (is_null($config)) {
                $config = $data['value'];
            }
        }

        return $config;
    }

    public static function get_settings_storage($name)
    {
        $config = 'public';
        $data = BusinessSetting::where(['key' => $name])->first();
        if (isset($data) && count($data->storage) > 0) {
            $config = $data->storage[0]['value'];
        }

        return $config;
    }

    public static function setEnvironmentValue($envKey, $envValue)
    {
        $envFile = app()->environmentFilePath();
        $str = file_get_contents($envFile);
        $oldValue = env($envKey);
        if (strpos($str, $envKey) !== false) {
            $str = str_replace("{$envKey}={$oldValue}", "{$envKey}={$envValue}", $str);
        } else {
            $str .= "{$envKey}={$envValue}\n";
        }
        $fp = fopen($envFile, 'w');
        fwrite($fp, $str);
        fclose($fp);

        return $envValue;
    }

    public static function insert_business_settings_key($key, $value = null)
    {
        $data = BusinessSetting::where('key', $key)->first();
        if (! $data) {
            Helpers::businessUpdateOrInsert(['key' => $key], [
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return true;
    }

    public static function insert_data_settings_key($key, $type, $value = null)
    {
        $data = DataSetting::where('key', $key)->where('type', $type)->first();
        if (! $data) {
            DataSetting::updateOrCreate(['key' => $key, 'type' => $type], [
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return true;
    }

    public static function get_language_name($key)
    {
        $languages = [
            'af' => 'Afrikaans',
            'sq' => 'Albanian - shqip',
            'am' => 'Amharic - አማርኛ',
            'ar' => 'Arabic - العربية',
            'an' => 'Aragonese - aragonés',
            'hy' => 'Armenian - հայերեն',
            'ast' => 'Asturian - asturianu',
            'az' => 'Azerbaijani - azərbaycan dili',
            'eu' => 'Basque - euskara',
            'be' => 'Belarusian - беларуская',
            'bn' => 'Bengali - বাংলা',
            'bs' => 'Bosnian - bosanski',
            'br' => 'Breton - brezhoneg',
            'bg' => 'Bulgarian - български',
            'ca' => 'Catalan - català',
            'ckb' => 'Central Kurdish - کوردی (دەستنوسی عەرەبی)',
            'zh' => '中文',
            'zh-HK' => 'Chinese (Hong Kong) - 中文（香港）',
            'zh-CN' => 'Chinese (Simplified) - 中文（简体）',
            'zh-TW' => 'Chinese (Traditional) - 中文（繁體）',
            'co' => 'Corsican',
            'hr' => 'Croatian - hrvatski',
            'cs' => 'Czech - čeština',
            'da' => 'Danish - dansk',
            'nl' => 'Dutch - Nederlands',
            'en' => '英文',
            'en-AU' => 'English (Australia)',
            'en-CA' => 'English (Canada)',
            'en-IN' => 'English (India)',
            'en-NZ' => 'English (New Zealand)',
            'en-ZA' => 'English (South Africa)',
            'en-GB' => 'English (United Kingdom)',
            'en-US' => 'English (United States)',
            'eo' => 'Esperanto - esperanto',
            'et' => 'Estonian - eesti',
            'fo' => 'Faroese - føroyskt',
            'fil' => 'Filipino',
            'fi' => 'Finnish - suomi',
            'fr' => 'French - français',
            'fr-CA' => 'French (Canada) - français (Canada)',
            'fr-FR' => 'French (France) - français (France)',
            'fr-CH' => 'French (Switzerland) - français (Suisse)',
            'gl' => 'Galician - galego',
            'ka' => 'Georgian - ქართული',
            'de' => 'German - Deutsch',
            'de-AT' => 'German (Austria) - Deutsch (Österreich)',
            'de-DE' => 'German (Germany) - Deutsch (Deutschland)',
            'de-LI' => 'German (Liechtenstein) - Deutsch (Liechtenstein)',
            'de-CH' => 'German (Switzerland) - Deutsch (Schweiz)',
            'el' => 'Greek - Ελληνικά',
            'gn' => 'Guarani',
            'gu' => 'Gujarati - ગુજરાતી',
            'ha' => 'Hausa',
            'haw' => 'Hawaiian - ʻŌlelo Hawaiʻi',
            'he' => 'Hebrew - עברית',
            'hi' => 'Hindi - हिन्दी',
            'hu' => 'Hungarian - magyar',
            'is' => 'Icelandic - íslenska',
            'id' => 'Indonesian - Indonesia',
            'ia' => 'Interlingua',
            'ga' => 'Irish - Gaeilge',
            'it' => 'Italian - italiano',
            'it-IT' => 'Italian (Italy) - italiano (Italia)',
            'it-CH' => 'Italian (Switzerland) - italiano (Svizzera)',
            'ja' => 'Japanese - 日本語',
            'kn' => 'Kannada - ಕನ್ನಡ',
            'kk' => 'Kazakh - қазақ тілі',
            'km' => 'Khmer - ខ្មែរ',
            'ko' => 'Korean - 한국어',
            'ku' => 'Kurdish - Kurdî',
            'ky' => 'Kyrgyz - кыргызча',
            'lo' => 'Lao - ລາວ',
            'la' => 'Latin',
            'lv' => 'Latvian - latviešu',
            'ln' => 'Lingala - lingála',
            'lt' => 'Lithuanian - lietuvių',
            'mk' => 'Macedonian - македонски',
            'ms' => 'Malay - Bahasa Melayu',
            'ml' => 'Malayalam - മലയാളം',
            'mt' => 'Maltese - Malti',
            'mr' => 'Marathi - मराठी',
            'mn' => 'Mongolian - монгол',
            'ne' => 'Nepali - नेपाली',
            'no' => 'Norwegian - norsk',
            'nb' => 'Norwegian Bokmål - norsk bokmål',
            'nn' => 'Norwegian Nynorsk - nynorsk',
            'oc' => 'Occitan',
            'or' => 'Oriya - ଓଡ଼ିଆ',
            'om' => 'Oromo - Oromoo',
            'ps' => 'Pashto - پښتو',
            'fa' => 'Persian - فارسی',
            'pl' => 'Polish - polski',
            'pt' => 'Portuguese - português',
            'pt-BR' => 'Portuguese (Brazil) - português (Brasil)',
            'pt-PT' => 'Portuguese (Portugal) - português (Portugal)',
            'pa' => 'Punjabi - ਪੰਜਾਬੀ',
            'qu' => 'Quechua',
            'ro' => 'Romanian - română',
            'mo' => 'Romanian (Moldova) - română (Moldova)',
            'rm' => 'Romansh - rumantsch',
            'ru' => 'Russian - русский',
            'gd' => 'Scottish Gaelic',
            'sr' => 'Serbian - српски',
            'sh' => 'Serbo-Croatian - Srpskohrvatski',
            'sn' => 'Shona - chiShona',
            'sd' => 'Sindhi',
            'si' => 'Sinhala - සිංහල',
            'sk' => 'Slovak - slovenčina',
            'sl' => 'Slovenian - slovenščina',
            'so' => 'Somali - Soomaali',
            'st' => 'Southern Sotho',
            'es' => 'Spanish - español',
            'es-AR' => 'Spanish (Argentina) - español (Argentina)',
            'es-419' => 'Spanish (Latin America) - español (Latinoamérica)',
            'es-MX' => 'Spanish (Mexico) - español (México)',
            'es-ES' => 'Spanish (Spain) - español (España)',
            'es-US' => 'Spanish (United States) - español (Estados Unidos)',
            'su' => 'Sundanese',
            'sw' => 'Swahili - Kiswahili',
            'sv' => 'Swedish - svenska',
            'tg' => 'Tajik - тоҷикӣ',
            'ta' => 'Tamil - தமிழ்',
            'tt' => 'Tatar',
            'te' => 'Telugu - తెలుగు',
            'th' => 'Thai - ไทย',
            'ti' => 'Tigrinya - ትግርኛ',
            'to' => 'Tongan - lea fakatonga',
            'tr' => 'Turkish - Türkçe',
            'tk' => 'Turkmen',
            'tw' => 'Twi',
            'uk' => 'Ukrainian - українська',
            'ur' => 'Urdu - اردو',
            'ug' => 'Uyghur',
            'uz' => 'Uzbek - o‘zbek',
            'vi' => 'Vietnamese - Tiếng Việt',
            'wa' => 'Walloon - wa',
            'cy' => 'Welsh - Cymraeg',
            'fy' => 'Western Frisian',
            'xh' => 'Xhosa',
            'yi' => 'Yiddish',
            'yo' => 'Yoruba - Èdè Yorùbá',
            'zu' => 'Zulu - isiZulu',
        ];

        return array_key_exists($key, $languages) ? $languages[$key] : $key;
    }

    public static function get_view_keys()
    {
        $keys = BusinessSetting::whereIn('key', ['toggle_veg_non_veg', 'toggle_dm_registration', 'toggle_restaurant_registration'])->get();
        $data = [];
        foreach ($keys as $key) {
            $data[$key->key] = (bool) $key->value ?? 0;
        }

        return $data;
    }

    public static function system_default_language()
    {
        $languages = self::get_business_settings('system_language');
        $lang = 'en';

        foreach ($languages as $key => $language) {

            if ($language['default']) {
                $lang = $language['code'];
            }
        }

        return $lang;
    }

    public static function system_default_direction()
    {
        $languages = self::get_business_settings('system_language');
        $lang = 'en';

        foreach ($languages as $key => $language) {
            if ($language['default']) {
                $lang = $language['direction'];
            }
        }

        return $lang;
    }

    public static function generate_referer_code()
    {
        $ref_code = strtoupper(Str::random(10));
        if (self::referer_code_exists($ref_code)) {
            return self::generate_referer_code();
        }

        return $ref_code;
    }

    public static function referer_code_exists($ref_code)
    {
        return User::where('ref_code', '=', $ref_code)->exists();
    }

    public static function remove_invalid_charcaters($str)
    {
        return str_ireplace(['\'', '"', ';', '<', '>'], ' ', $str);
    }

    public static function set_time_log($user_id, $date, $online = null, $offline = null, $shift_id = null)
    {
        try {
            $time_log = TimeLog::where(['user_id' => $user_id, 'date' => $date, 'shift_id' => $shift_id])->first();

            if ($time_log && $time_log->online && $online) {
                return true;
            }

            if ($time_log && $offline) {
                $time_log->offline = $offline;

                if ($time_log->online) {
                    $time_log->working_hour = (strtotime($offline) - strtotime($time_log->online)) / 60;
                } else {
                    $time_log->online = $offline;
                    $time_log->working_hour = 0;
                }

                $time_log->shift_id = $shift_id;
                $time_log->save();

                return true;
            }

            if (! $time_log) {
                $time_log = new TimeLog;
                $time_log->date = $date;
                $time_log->user_id = $user_id;
                $time_log->offline = $offline;
                $time_log->online = $online ?? $offline;
                $time_log->working_hour = 0;
                $time_log->shift_id = $shift_id;
                $time_log->save();
            }

            return true;
        } catch (\Exception $e) {
            info(["line___{$e->getLine()}", $e->getMessage()]);
        }

        return false;
    }

    public static function push_notification_export_data($data)
    {
        $format = [];
        foreach ($data as $key => $item) {
            $format[] = [
                '#' => $key + 1,
                translate('title') => $item['title'],
                translate('description') => $item['description'],
                translate('zone') => $item->zone ? $item->zone->name : translate('messages.all_zones'),
                translate('tergat') => $item['tergat'],
                translate('status') => $item['status'],
            ];
        }

        return $format;
    }

    public static function export_restaurants($collection)
    {
        $data = [];

        foreach ($collection as $key => $item) {

            $data[] = [
                'id' => $item->id,
                'ownerID' => $item->vendor->id,
                'ownerFirstName' => $item->vendor->f_name,
                'ownerLastName' => $item->vendor->l_name,
                'restaurantName' => $item->name,
                'CoverPhoto' => $item->cover_photo,
                'logo' => $item->logo,
                'phone' => $item->vendor->phone,
                'email' => $item->vendor->email,
                'latitude' => $item->latitude,
                'longitude' => $item->longitude,
                'zone_id' => $item->zone_id,
                'Address' => $item->address ?? null,
                'Slug' => $item->slug ?? null,
                'MinimumOrderAmount' => $item->minimum_order,
                'Comission' => $item->comission ?? 0,
                'Tax' => $item->tax ?? 0,

                'DeliveryTime' => $item->delivery_time ?? '20-30',
                'MinimumDeliveryFee' => $item->minimum_shipping_charge ?? 0,
                'PerKmDeliveryFee' => $item->per_km_shipping_charge ?? 0,
                'MaximumDeliveryFee' => $item->maximum_shipping_charge ?? 0,
                // 'order_count'=>$item->order_count,
                // 'total_order'=>$item->total_order,
                'RestaurantModel' => $item->restaurant_model,
                'ScheduleOrder' => $item->schedule_order == 1 ? 'yes' : 'no',
                'FreeDelivery' => $item->free_delivery == 1 ? 'yes' : 'no',
                'TakeAway' => $item->take_away == 1 ? 'yes' : 'no',
                'Delivery' => $item->delivery == 1 ? 'yes' : 'no',
                'Veg' => $item->veg == 1 ? 'yes' : 'no',
                'NonVeg' => $item->non_veg == 1 ? 'yes' : 'no',
                'OrderSubscription' => $item->order_subscription_active == 1 ? 'yes' : 'no',
                'Status' => $item->status == 1 ? 'active' : 'inactive',
                'FoodSection' => $item->food_section == 1 ? 'active' : 'inactive',
                'ReviewsSection' => $item->reviews_section == 1 ? 'active' : 'inactive',
                'SelfDeliverySystem' => $item->self_delivery_system == 1 ? 'active' : 'inactive',
                'PosSystem' => $item->pos_system == 1 ? 'active' : 'inactive',
                'RestaurantOpen' => $item->active == 1 ? 'yes' : 'no',
                // 'gst'=>$item->restaurants[0]->gst ?? null,
            ];
        }

        return $data;
    }

    public static function export_attributes($collection)
    {
        $data = [];
        foreach ($collection as $key => $item) {
            $data[] = [
                'SL' => $key + 1,
                translate('messages.id') => $item['id'],
                translate('messages.name') => $item['name'],
            ];
        }

        return $data;
    }

    public static function get_varient(array $product_variations, array $variations)
    {
        $result = [];
        $variation_price = 0;
        $optionIds = [];
        foreach ($variations as $k => $variation) {
            foreach ($product_variations as $product_variation) {
                if (isset($variation['values']) && isset($product_variation['values']) && $product_variation['name'] == $variation['name']) {
                    $result[$k] = $product_variation;
                    $result[$k]['values'] = [];
                    foreach ($product_variation['values'] as $key => $option) {
                        if (in_array($option['label'], $variation['values']['label'])) {
                            $result[$k]['values'][] = $option;
                            $variation_price += $option['optionPrice'];
                            $optionIds[] = data_get($option, 'option_id', null);
                        }
                    }
                }
            }
        }

        return ['price' => $variation_price, 'variations' => array_values($result), 'optionIds' => $optionIds];
    }

    public static function getVariationPrice(array $variations)
    {
        $result = [];
        $variation_price = 0;
        VariationOption::whereIn('id', $variations)->get()->each(function ($variation) use (&$result, &$variation_price) {
            $result[] = $variation;
            $variation_price += $variation->option_price;
        });

        return ['price' => $variation_price, 'variations' => array_values($result)];
    }

    public static function get_edit_varient(array $product_variations, $variations)
    {
        $result = [];
        $variation_price = 0;

        foreach ($variations as $k => $variation) {
            foreach ($product_variations as $product_variation) {
                if (
                    isset($variation['values']) &&
                    isset($product_variation['values']) &&
                    $product_variation['name'] == $variation['name']
                ) {
                    $result[$k] = $product_variation;
                    $result[$k]['values'] = [];

                    foreach ($product_variation['values'] as $option) {
                        foreach ($variation['values'] as $selected) {
                            if (isset($selected['label']) && $option['label'] === $selected['label']) {
                                $result[$k]['values'][] = $option;
                                $variation_price += $option['optionPrice'];
                                break;
                            }
                        }
                    }
                }
            }
        }

        return ['price' => $variation_price, 'variations' => $result];
    }


    private static function getBusinessModel(): array
    {
        $default = [
            'commission'   => 1,
            'subscription' => 0,
        ];

        $business_model = Helpers::get_business_settings('business_model');

        if (!$business_model) {
            Helpers::insert_business_settings_key(
                'business_model',
                json_encode($default)
            );

            return $default;
        }

        return $business_model;
    }



    public static function subscription_check(): bool
    {
        return (self::getBusinessModel()['subscription'] ?? 0) == 1;
    }

    public static function commission_check(): bool
    {
        return (self::getBusinessModel()['commission'] ?? 0) == 1;
    }

    public static function check_subscription_validity()
    {
        // only For supscription order
        $current_date = date('Y-m-d');
        $check_subscription_validity_on = Helpers::get_business_settings('check_subscription_validity_on');
        if (! $check_subscription_validity_on) {
            Helpers::insert_business_settings_key('check_subscription_validity_on', date('Y-m-d'));
        }
        if ($check_subscription_validity_on && $check_subscription_validity_on != $current_date) {
            Helpers::insert_business_settings_key('check_subscription_validity_on', $current_date);
            Helpers::create_subscription_order_logs();
        }

        return false;
    }

    public static function subscription_plan_chosen($restaurant_id, $package_id, $payment_method, $discount = 0, $pending_bill = 0, $reference = null, $type = null)
    {
        $restaurant = Restaurant::find($restaurant_id);
        $package = SubscriptionPackage::withoutGlobalScope('translate')->find($package_id);
        $add_days = 0;
        $add_orders = 0;

        try {
            $restaurant_subscription = $restaurant->restaurant_sub;
            if (isset($restaurant_subscription) && $type == 'renew') {
                $restaurant_subscription->total_package_renewed = $restaurant_subscription->total_package_renewed + 1;

                $day_left = $restaurant_subscription->expiry_date_parsed->format('Y-m-d');
                if (Carbon::now()->diffInDays($day_left, false) > 0 && $restaurant_subscription->is_canceled != 1) {
                    $add_days = Carbon::now()->subDays(1)->diffInDays($day_left, false);
                }
                if ($restaurant_subscription->max_order != 'unlimited' && $restaurant_subscription->max_order > 0) {
                    $add_orders = $restaurant_subscription->max_order;
                }

            } elseif ($restaurant->restaurant_sub_update_application && $restaurant->restaurant_sub_update_application->package_id == $package->id && $type == 'renew') {
                $restaurant_subscription = $restaurant->restaurant_sub_update_application;
                $restaurant_subscription->total_package_renewed = $restaurant_subscription->total_package_renewed + 1;
            } else {
                self::calculateSubscriptionRefundAmount($restaurant);
                RestaurantSubscription::where('restaurant_id', $restaurant->id)->update([
                    'status' => 0,
                ]);
                $restaurant_subscription = new RestaurantSubscription;
                $restaurant_subscription->total_package_renewed = 0;

            }

            $restaurant_subscription->is_trial = 0;
            $restaurant_subscription->renewed_at = now();
            $restaurant_subscription->package_id = $package->id;
            $restaurant_subscription->restaurant_id = $restaurant->id;
            if ($payment_method == 'free_trial') {

                $free_trial_period = (int) BusinessSetting::where(['key' => 'subscription_free_trial_days'])->first()?->value ?? 1;

                $restaurant_subscription->expiry_date = Carbon::now()->addDays($free_trial_period)->format('Y-m-d');
                $restaurant_subscription->validity = $free_trial_period;
            } else {
                $restaurant_subscription->expiry_date = Carbon::now()->addDays((int) ($package->validity + $add_days))->format('Y-m-d');
                $restaurant_subscription->validity = $package->validity + $add_days;
            }
            if ($package->max_order != 'unlimited') {
                $restaurant_subscription->max_order = $package->max_order + $add_orders;
            } else {
                $restaurant_subscription->max_order = $package->max_order;
            }

            $restaurant_subscription->max_product = $package->max_product;
            $restaurant_subscription->pos = $package->pos;
            $restaurant_subscription->mobile_app = $package->mobile_app;
            $restaurant_subscription->chat = $package->chat;
            $restaurant_subscription->review = $package->review;
            $restaurant_subscription->self_delivery = $package->self_delivery;
            $restaurant_subscription->is_canceled = 0;
            $restaurant_subscription->canceled_by = 'none';

            $restaurant->food_section = 1;
            $restaurant->pos_system = 1;
            if ($type == 'new_join' && $restaurant->vendor?->status == 0) {
                $restaurant->status = 0;
                $restaurant_subscription->status = 0;

            } else {
                $restaurant->status = 1;
                $restaurant_subscription->status = 1;

            }

            // For Restaurant Free Delivery
            if ($restaurant->free_delivery == 1 && $package->self_delivery == 1) {
                $restaurant->free_delivery = 1;
            } else {
                $restaurant->free_delivery = 0;
                $restaurant->coupon()->where('created_by', 'vendor')->where('coupon_type', 'free_delivery')->delete();
            }

            $restaurant->reviews_section = 1;
            $restaurant->self_delivery_system = 1;
            $restaurant->restaurant_model = 'subscription';

            $subscription_transaction = new SubscriptionTransaction;
            $subscription_transaction->id = (string) Str::uuid();

            $subscription_transaction->package_id = $package->id;
            $subscription_transaction->restaurant_id = $restaurant->id;
            $subscription_transaction->price = $package->price;

            $subscription_transaction->validity = $package->validity;
            $subscription_transaction->paid_amount = $package->price - (($package->price * $discount) / 100) + $pending_bill;

            $subscription_transaction->payment_status = 'success';
            $subscription_transaction->created_by = in_array($payment_method, ['wallet_payment_by_admin', 'manual_payment_by_admin', 'plan_shift_by_admin']) ? 'Admin' : 'Restaurant';

            if ($payment_method == 'free_trial') {
                $subscription_transaction->validity = $free_trial_period;
                $subscription_transaction->paid_amount = 0;
                $subscription_transaction->is_trial = 1;
                $restaurant_subscription->is_trial = 1;
            } elseif ($payment_method == 'pay_now') {
                $subscription_transaction->payment_status = 'on_hold';
                $subscription_transaction->transaction_status = 0;
                $restaurant_subscription->status = 0;
            }

            $subscription_transaction->payment_method = $payment_method;
            $subscription_transaction->reference = $reference ?? null;
            $subscription_transaction->discount = $discount ?? 0;
            $subscription_transaction->plan_type = 'first_purchased';

            if (in_array($type, ['renew', 'free_trial'])) {
                $subscription_transaction->plan_type = $type;
            } elseif (RestaurantSubscription::where('restaurant_id', $restaurant->id)->where('is_trial', 0)->count() > 0 || $reference == 'plan_shift_by_admin') {
                $subscription_transaction->plan_type = 'new_plan';
            }

            $subscription_transaction->package_details = [
                'pos' => $package->pos,
                'review' => $package->review,
                'self_delivery' => $package->self_delivery,
                'chat' => $package->chat,
                'mobile_app' => $package->mobile_app,
                'max_order' => $package->max_order,
                'max_product' => $package->max_product,
            ];
            DB::beginTransaction();
            $restaurant->save();
            $subscription_transaction->save();
            $restaurant_subscription->save();
            DB::commit();
            $subscription_transaction->restaurant_subscription_id = $restaurant_subscription->id;
            $subscription_transaction->save();

            SubscriptionBillingAndRefundHistory::where(['restaurant_id' => $restaurant->id,
                'transaction_type' => 'pending_bill', 'is_success' => 0])->update([
                    'is_success' => 1,
                    'reference' => 'payment_via_'.$payment_method.' _transaction_id_'.$subscription_transaction->id,
                ]);

            if ($reference == 'plan_shift_by_admin') {
                $billing = new SubscriptionBillingAndRefundHistory;
                $billing->restaurant_id = $restaurant->id;
                $billing->subscription_id = $restaurant_subscription->id;
                $billing->package_id = $restaurant_subscription->package_id;
                $billing->transaction_type = 'pending_bill';
                $billing->is_success = 0;
                $billing->amount = $package->price;
                $billing->save();
            }

        } catch (\Exception $e) {
            DB::rollBack();
            info(["line___{$e->getLine()}", $e->getMessage()]);

            return false;
        }

        if (data_get(self::subscriptionConditionsCheck(restaurant_id: $restaurant->id, package_id: $package->id), 'disable_item_count') > 0) {
            $disable_item_count = data_get(Helpers::subscriptionConditionsCheck(restaurant_id: $restaurant->id, package_id: $package->id), 'disable_item_count');
            $restaurant->food_section = 0;
            $restaurant->save();

            Food::where('restaurant_id', $restaurant->id)->oldest()->take($disable_item_count)->update([
                'status' => 0,
            ]);
        }

        try {

            if ($type == 'renew') {
                $notification_status = 'restaurant_subscription_renew';
                $title = translate('subscription_renewed');
            } elseif ($type != 'renew' && $subscription_transaction->plan_type != 'first_purchased') {
                $title = translate('subscription_shifted');
                $notification_status = 'restaurant_subscription_shift';
            } else {
                $title = translate('subscription_successful');
                $notification_status = 'restaurant_subscription_success';

            }
            $vendor = $restaurant->vendor;
            $restaurant_push_notification_status = self::getRestaurantNotificationStatusData($restaurant->id, $notification_status);
            $message = Helpers::getPushNotificationMessage(status: $notification_status, userType: 'restaurant', lang: $vendor->current_language_key, restaurantName: $restaurant->name);
            if ($restaurant_push_notification_status?->push_notification_status == 'active' && $message && isset($vendor->firebase_token)) {
                $data = Helpers::makeDataForPushNotification(title: $title, message: $message, orderId: '', type: 'subscription', orderStatus: '');
                Helpers::send_push_notif_to_device($vendor->firebase_token, $data);
                Helpers::insertDataOnNotificationTable($data, 'vendor', $vendor->id);
            }

            $notification_status = self::getNotificationStatusData('restaurant', $notification_status);
            if (config('mail.status') && $restaurant_push_notification_status?->mail_status == 'active' && $notification_status?->mail_status == 'active') {

                if (Helpers::get_mail_status('subscription_renew_mail_status_restaurant') == '1' && $type == 'renew') {
                    Mail::to($restaurant?->getRawOriginal('email'))->send(new SubscriptionRenewOrShift($type, $restaurant->name));
                } elseif (Helpers::get_mail_status('subscription_shift_mail_status_restaurant') == '1' && $type != 'renew' && $subscription_transaction->plan_type != 'first_purchased') {
                    Mail::to($restaurant?->getRawOriginal('email'))->send(new SubscriptionRenewOrShift($type, $restaurant->name));
                } elseif (Helpers::get_mail_status('subscription_successful_mail_status_restaurant') == '1' && $subscription_transaction->plan_type == 'first_purchased') {
                    $url = route('subscription_invoice', ['id' => base64_encode($subscription_transaction->id)]);
                    Mail::to($restaurant?->getRawOriginal('email'))->send(new SubscriptionSuccessful($restaurant->name, $url));
                }
            }

        } catch (\Exception $ex) {
            info($ex->getMessage());
        }

        return $subscription_transaction->id;
    }

    public static function expenseCreate($amount, $type, $datetime, $created_by, $order_id = null, $restaurant_id = null, $user_id = null, $description = '', $delivery_man_id = null)
    {
        $expense = new Expense;
        $expense->amount = $amount;
        $expense->type = $type;
        $expense->order_id = $order_id;
        $expense->created_by = $created_by;
        $expense->restaurant_id = $restaurant_id;
        $expense->delivery_man_id = $delivery_man_id;
        $expense->user_id = $user_id;
        $expense->description = $description;
        $expense->created_at = $datetime;
        $expense->updated_at = $datetime;

        return $expense->save();
    }

    public static function hex_to_rbg($color)
    {
        [$r, $g, $b] = sscanf($color, '#%02x%02x%02x');
        $output = "$r, $g, $b";

        return $output;
    }

    public static function increment_order_count($data)
    {
        $restaurant = $data;
        $rest_sub = $restaurant->restaurant_sub;
        if ($restaurant->restaurant_model == 'subscription' && isset($rest_sub) && $rest_sub->max_order != 'unlimited') {
            $rest_sub->increment('max_order', 1);
        }

        return true;
    }

    public static function number_format_short($n)
    {
        if ($n < 900) {
            // 0 - 900
            $n = $n;
            $suffix = '';
        } elseif ($n < 900000) {
            // 0.9k-850k
            $n = $n / 1000;
            $suffix = 'K';
        } elseif ($n < 900000000) {
            // 0.9m-850m
            $n = $n / 1000000;
            $suffix = 'M';
        } elseif ($n < 900000000000) {
            // 0.9b-850b
            $n = $n / 1000000000;
            $suffix = 'B';
        } else {
            // 0.9t+
            $n = $n / 1000000000000;
            $suffix = 'T';
        }

        if (! session()->has('currency_symbol_position')) {
            $currency_symbol_position = BusinessSetting::where(['key' => 'currency_symbol_position'])->first()->value;
            session()->put('currency_symbol_position', $currency_symbol_position);
        }
        $currency_symbol_position = session()->get('currency_symbol_position');

        return $currency_symbol_position == 'right' ? number_format($n, config('round_up_to_digit')).$suffix.' '.self::currency_symbol() : self::currency_symbol().' '.number_format($n, config('round_up_to_digit')).$suffix;
    }

    public static function gen_mpdf($view, $file_prefix, $file_postfix)
    {
        $mpdf = new \Mpdf\Mpdf(['tempDir' => __DIR__.'/../../storage/tmp', 'default_font' => 'FreeSerif', 'mode' => 'utf-8', 'format' => [190, 250]]);
        /* $mpdf->AddPage('XL', '', '', '', '', 10, 10, 10, '10', '270', ''); */
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;

        $mpdf_view = $view;
        $mpdf_view = $mpdf_view->render();
        $mpdf->WriteHTML($mpdf_view);
        $mpdf->Output($file_prefix.$file_postfix.'.pdf', 'D');
    }

    public static function down_mpdf($view, $file_prefix, $file_postfix)
    {
        $mpdf = new \Mpdf\Mpdf(['tempDir' => __DIR__.'/../../storage/tmp', 'default_font' => 'FreeSerif', 'mode' => 'utf-8', 'format' => [190, 250]]);
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;

        $mpdf_view = $view->render();
        $mpdf->WriteHTML($mpdf_view);

        $file_name = $file_prefix.$file_postfix.'.pdf';
        $file_path = storage_path('app/public/pdfs/'.$file_name);

        if (! file_exists(storage_path('app/public/pdfs'))) {
            mkdir(storage_path('app/public/pdfs'), 0777, true);
        }

        $mpdf->Output($file_path, 'F');

        return $file_name;
    }

    public static function product_tax($price, $tax, $is_include = false)
    {
        $price_tax = ($price * $tax) / (100 + ($is_include ? $tax : 0));

        return $price_tax;
    }

    public static function dm_wallet_transaction($delivery_man_id, $amount, $referance = null, $type = 'dm_admin_bonus')
    {
        if (!$amount) {
            return false;
        }
        $dmwallet = DeliveryManWallet::firstOrNew(['delivery_man_id' => $delivery_man_id]);
        $wallet_transaction = new WalletTransaction;
        $wallet_transaction->transaction_id = Str::uuid();
        $wallet_transaction->reference = $referance;
        $wallet_transaction->transaction_type = $type;
        $wallet_transaction->admin_bonus = $amount;
        $wallet_transaction->credit = $amount;
        $wallet_transaction->debit = 0;
        $wallet_transaction->balance = $dmwallet->total_earning + $amount;
        $wallet_transaction->created_at = now();
        $wallet_transaction->updated_at = now();
        $wallet_transaction->delivery_man_id = $delivery_man_id;
        try {
            DB::beginTransaction();
            $wallet_transaction->save();
            $dmwallet->total_earning = $dmwallet->total_earning + $amount;
            $dmwallet->save();
            Helpers::expenseCreate(amount: $amount, type: $type, datetime: now(), created_by: 'admin', delivery_man_id: $delivery_man_id);
            DB::commit();

            return true;
        } catch (Exception $ex) {
            DB::rollBack();
            info(['dm_wallet_transaction_error', 'code' => $ex->getLine(), 'message' => $ex->getMessage()]);

            return false;
        }
    }

    public static function get_subscription_schedules($type, $startDate, $endDate, $days)
    {
        $arrayOfDate = [];
        $startDate = Carbon::parse($startDate);
        $endDate = Carbon::parse($endDate);
        $days = $type != 'daily' ? array_column($days, 'time', 'day') : $days;
        for ($date = $startDate; $date->lte($endDate); $date->addDay()) {

            if ($type == 'weekly') {
                if (isset($days[$date->weekday()])) {
                    $arrayOfDate[] = $date->format('Y-m-d ').$days[$date->weekday()];
                }
            } elseif ($type == 'monthly') {
                if (isset($days[$date->day])) {
                    $arrayOfDate[] = $date->format('Y-m-d ').$days[$date->day];
                }
            } else {
                $arrayOfDate[] = $date->format('Y-m-d ').$days[0]['itme'];
            }
        }

        return $arrayOfDate;
    }

    public static function visitor_log($model, $user_id, $visitor_log_id, $order_count = false)
    {
        if ($model == 'restaurant') {
            $visitor_log_type = 'App\Models\Restaurant';
        } else {
            $visitor_log_type = 'App\Models\Category';
        }
        VisitorLog::updateOrInsert(
            ['visitor_log_type' => $visitor_log_type,
                'user_id' => $user_id,
                'visitor_log_id' => $visitor_log_id,
            ],
            [
                'visit_count' => $order_count == false ? DB::raw('visit_count + 1') : DB::raw('visit_count'),
                'order_count' => $order_count == true ? DB::raw('order_count + 1') : DB::raw('order_count'),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

    }

    public static function getLanguageCode(string $country_code): string
    {
        $locales = [
            'en-English(default)',
            'af-Afrikaans',
            'sq-Albanian - shqip',
            'am-Amharic - አማርኛ',
            'ar-Arabic - العربية',
            'an-Aragonese - aragonés',
            'hy-Armenian - հայերեն',
            'ast-Asturian - asturianu',
            'az-Azerbaijani - azərbaycan dili',
            'eu-Basque - euskara',
            'be-Belarusian - беларуская',
            'bn-Bengali - বাংলা',
            'bs-Bosnian - bosanski',
            'br-Breton - brezhoneg',
            'bg-Bulgarian - български',
            'ca-Catalan - català',
            'ckb-Central Kurdish - کوردی (دەستنوسی عەرەبی)',
            'zh-Chinese - 中文',
            'zh-HK-Chinese (Hong Kong) - 中文（香港）',
            'zh-CN-Chinese (Simplified) - 中文（简体）',
            'zh-TW-Chinese (Traditional) - 中文（繁體）',
            'co-Corsican',
            'hr-Croatian - hrvatski',
            'cs-Czech - čeština',
            'da-Danish - dansk',
            'nl-Dutch - Nederlands',
            'en-AU-English (Australia)',
            'en-CA-English (Canada)',
            'en-IN-English (India)',
            'en-NZ-English (New Zealand)',
            'en-ZA-English (South Africa)',
            'en-GB-English (United Kingdom)',
            'en-US-English (United States)',
            'eo-Esperanto - esperanto',
            'et-Estonian - eesti',
            'fo-Faroese - føroyskt',
            'fil-Filipino',
            'fi-Finnish - suomi',
            'fr-French - français',
            'fr-CA-French (Canada) - français (Canada)',
            'fr-FR-French (France) - français (France)',
            'fr-CH-French (Switzerland) - français (Suisse)',
            'gl-Galician - galego',
            'ka-Georgian - ქართული',
            'de-German - Deutsch',
            'de-AT-German (Austria) - Deutsch (Österreich)',
            'de-DE-German (Germany) - Deutsch (Deutschland)',
            'de-LI-German (Liechtenstein) - Deutsch (Liechtenstein)
            ',
            'de-CH-German (Switzerland) - Deutsch (Schweiz)',
            'el-Greek - Ελληνικά',
            'gn-Guarani',
            'gu-Gujarati - ગુજરાતી',
            'ha-Hausa',
            'haw-Hawaiian - ʻŌlelo Hawaiʻi',
            'he-Hebrew - עברית',
            'hi-Hindi - हिन्दी',
            'hu-Hungarian - magyar',
            'is-Icelandic - íslenska',
            'id-Indonesian - Indonesia',
            'ia-Interlingua',
            'ga-Irish - Gaeilge',
            'it-Italian - italiano',
            'it-IT-Italian (Italy) - italiano (Italia)',
            'it-CH-Italian (Switzerland) - italiano (Svizzera)',
            'ja-Japanese - 日本語',
            'kn-Kannada - ಕನ್ನಡ',
            'kk-Kazakh - қазақ тілі',
            'km-Khmer - ខ្មែរ',
            'ko-Korean - 한국어',
            'ku-Kurdish - Kurdî',
            'ky-Kyrgyz - кыргызча',
            'lo-Lao - ລາວ',
            'la-Latin',
            'lv-Latvian - latviešu',
            'ln-Lingala - lingála',
            'lt-Lithuanian - lietuvių',
            'mk-Macedonian - македонски',
            'ms-Malay - Bahasa Melayu',
            'ml-Malayalam - മലയാളം',
            'mt-Maltese - Malti',
            'mr-Marathi - मराठी',
            'mn-Mongolian - монгол',
            'ne-Nepali - नेपाली',
            'no-Norwegian - norsk',
            'nb-Norwegian Bokmål - norsk bokmål',
            'nn-Norwegian Nynorsk - nynorsk',
            'oc-Occitan',
            'or-Oriya - ଓଡ଼ିଆ',
            'om-Oromo - Oromoo',
            'ps-Pashto - پښتو',
            'fa-Persian - فارسی',
            'pl-Polish - polski',
            'pt-Portuguese - português',
            'pt-BR-Portuguese (Brazil) - português (Brasil)',
            'pt-PT-Portuguese (Portugal) - português (Portugal)',
            'pa-Punjabi - ਪੰਜਾਬੀ',
            'qu-Quechua',
            'ro-Romanian - română',
            'mo-Romanian (Moldova) - română (Moldova)',
            'rm-Romansh - rumantsch',
            'ru-Russian - русский',
            'gd-Scottish Gaelic',
            'sr-Serbian - српски',
            'sh-Serbo-Croatian - Srpskohrvatski',
            'sn-Shona - chiShona',
            'sd-Sindhi',
            'si-Sinhala - සිංහල',
            'sk-Slovak - slovenčina',
            'sl-Slovenian - slovenščina',
            'so-Somali - Soomaali',
            'st-Southern Sotho',
            'es-Spanish - español',
            'es-AR-Spanish (Argentina) - español (Argentina)',
            'es-419-Spanish (Latin America) - español (Latinoamérica)
            ',
            'es-MX-Spanish (Mexico) - español (México)',
            'es-ES-Spanish (Spain) - español (España)',
            'es-US-Spanish (United States) - español (Estados Unidos)
            ',
            'su-Sundanese',
            'sw-Swahili - Kiswahili',
            'sv-Swedish - svenska',
            'tg-Tajik - тоҷикӣ',
            'ta-Tamil - தமிழ்',
            'tt-Tatar',
            'te-Telugu - తెలుగు',
            'th-Thai - ไทย',
            'ti-Tigrinya - ትግርኛ',
            'to-Tongan - lea fakatonga',
            'tr-Turkish - Türkçe',
            'tk-Turkmen',
            'tw-Twi',
            'uk-Ukrainian - українська',
            'ur-Urdu - اردو',
            'ug-Uyghur',
            'uz-Uzbek - o‘zbek',
            'vi-Vietnamese - Tiếng Việt',
            'wa-Walloon - wa',
            'cy-Welsh - Cymraeg',
            'fy-Western Frisian',
            'xh-Xhosa',
            'yi-Yiddish',
            'yo-Yoruba - Èdè Yorùbá',
            'zu-Zulu - isiZulu',
        ];

        foreach ($locales as $locale) {
            $locale_region = explode('-', $locale);
            if ($country_code == $locale_region[0]) {
                return $locale_region[0];
            }
        }

        return 'en';
    }

    public static function auto_translator($q, $sl, $tl)
    {
        $res = file_get_contents('https://translate.googleapis.com/translate_a/single?client=gtx&ie=UTF-8&oe=UTF-8&dt=bd&dt=ex&dt=ld&dt=md&dt=qca&dt=rw&dt=rm&dt=ss&dt=t&dt=at&sl='.$sl.'&tl='.$tl.'&hl=hl&q='.urlencode($q), $_SERVER['DOCUMENT_ROOT'].'/transes.html');
        $res = json_decode($res);

        return str_replace('_', ' ', $res[0][0][0]);
    }

    public static function language_load()
    {
        if (\session()->has('language_settings')) {
            $language = \session('language_settings');
        } else {
            $language = BusinessSetting::where('key', 'system_language')->first();
            \session()->put('language_settings', $language);
        }

        return $language;
    }

    public static function vendor_language_load()
    {
        if (\session()->has('vendor_language_settings')) {
            $language = \session('vendor_language_settings');
        } else {
            $language = BusinessSetting::where('key', 'system_language')->first();
            \session()->put('vendor_language_settings', $language);
        }

        return $language;
    }

    public static function create_subscription_order_logs()
    {
        $order_schedule_day = now()->dayOfWeek;
        $o = Order::HasSubscriptionTodayGet()->with(['restaurant.schedule_today', 'subscription.schedule_today'])->whereHas('restaurant.schedules', function ($q) use ($order_schedule_day) {
            $q->where('day', $order_schedule_day);
        })
            ->get();
        foreach ($o as $order) {
            foreach ($order->restaurant->schedule_today as $rest_sh) {
                if (Carbon::parse($rest_sh->opening_time) <= Carbon::parse($order->subscription->schedule_today->time) && Carbon::parse($rest_sh->closing_time) >= Carbon::parse($order->subscription->schedule_today->time)) {
                    OrderLogic::create_subscription_log($order->id);
                }
            }
        }

        return true;
    }

    // public static function create_all_logs($object , $action_type, $model){
    //     $restaurant_id = null;
    //     if ((auth('vendor_employee')->check() || auth('vendor')->check() || request('vendor') || auth('admin')->check()) || (request()->token && DeliveryMan::where('auth_token' , request()->token)->exists()) ) {
    //         if (auth('vendor_employee')->check()) {
    //             $loable_type = 'App\Models\VendorEmployee';
    //             $logable_id = auth('vendor_employee')->id();
    //             $restaurant_id=auth('vendor_employee')->user() != null && isset(auth('vendor_employee')->user()->restaurant) ? auth('vendor_employee')->user()->restaurant->id : null;
    //         } elseif (auth('vendor')->check() || request('vendor')) {
    //             $restaurant_id=auth('vendor')->user() != null && isset(auth('vendor')->user()->restaurants[0]) ? auth('vendor')->user()->restaurants[0]->id : null;
    //             $loable_type = 'App\Models\Vendor';
    //             $logable_id = auth('vendor')->id();

    //             if(request('vendor')){
    //                 $logable_id =request('vendor')->id;
    //                 $restaurant_id= isset(request('vendor')->restaurants[0]) ? request('vendor')->restaurants[0]->id : null;
    //             }
    //         //    dd(request('vendor')->restaurants[0]->id);
    //         } elseif (auth('admin')->check()) {
    //             $loable_type = 'App\Models\Admin';
    //             $logable_id = auth('admin')->id();
    //         }elseif (request()->token && DeliveryMan::where('auth_token' , request()->token)->exists()) {
    //             $loable_type = 'App\Models\DeliveryMan';
    //             $dm =DeliveryMan::where('auth_token' , request()->token)->with('restaurant')->first();
    //             $logable_id = $dm->id;
    //             if($dm->type == 'restaurant_wise' && $dm->restaurant){
    //                 $restaurant_id= $dm->restaurant->id;
    //             }
    //         }

    //         $log = new Log();
    //         $log->logable_type = $loable_type;
    //         $log->logable_id = $logable_id;
    //         $log->action_type = $action_type;
    //         $log->model = $model;
    //         $log->restaurant_id = $restaurant_id;
    //         $log->model_id = $object->id;
    //         $log->ip_address = request()->ip();
    //         $log->before_state = json_encode($object->getOriginal());
    //         $log->after_state = json_encode($object->getDirty());
    //         $log->save();
    //     }
    //     return true;
    // }

    public static function landing_language_load()
    {
        if (\session()->has('landing_language_settings')) {
            $language = \session('landing_language_settings');
        } else {
            $language = BusinessSetting::where('key', 'system_language')->first();
            \session()->put('landing_language_settings', $language);
        }

        return $language;
    }

    public static function generate_reset_password_code()
    {
        $code = strtoupper(Str::random(15));
        if (self::reset_password_code_exists($code)) {
            return self::generate_reset_password_code();
        }

        return $code;
    }

    public static function reset_password_code_exists($code)
    {
        return DB::table('password_resets')->where('token', '=', $code)->exists();
    }

    public static function Export_generator($datas)
    {
        foreach ($datas as $data) {
            yield $data;
        }

        return true;
    }

    public static function vehicle_extra_charge(float $distance_data)
    {
        $data = [];
        $vehicle = Vehicle::active()
            ->where(function ($query) use ($distance_data) {
                $query->where('starting_coverage_area', '<=', $distance_data)->where('maximum_coverage_area', '>=', $distance_data)
                    ->orWhere(function ($query) use ($distance_data) {
                        $query->where('starting_coverage_area', '>=', $distance_data);
                    });
            })->orderBy('starting_coverage_area')->first();
        if (empty($vehicle)) {
            $vehicle = Vehicle::active()->orderBy('maximum_coverage_area', 'desc')->first();
        }
        $data['extra_charge'] = $vehicle->extra_charges ?? 0;
        $data['vehicle_id'] = $vehicle->id ?? null;

        return $data;
    }

    public static function react_services_formater($data)
    {
        $storage = [];
        foreach ($data as $item) {
            $storage[] = [
                'Id' => $item['id'],
                'Title' => $item['title'],
                'Sub_title' => $item['sub_title'],
                'Status' => $item['status'] == 1 ? 'active' : 'inactive',
            ];
        }
        $data = $storage;

        return $data;
    }

    public static function react_react_promotional_banner_formater($data)
    {
        $storage = [];
        foreach ($data as $item) {
            $storage[] = [
                'Id' => $item['id'],
                'Title' => $item['title'],
                'Description' => $item['description'],
                'Status' => $item['status'] == 1 ? 'active' : 'inactive',
            ];
        }
        $data = $storage;

        return $data;
    }

    public static function get_mail_status($name)
    {
        return self::get_business_settings($name);
    }

    public static function text_variable_data_format($value, $user_name = null, $restaurant_name = null, $delivery_man_name = null, $transaction_id = null, $order_id = null, $add_id = null, $otp = null, $token_number = null, $table_number = null)
    {
        $data = $value;
        if ($value) {
            if ($user_name) {
                $data = str_replace('{userName}', $user_name, $data);
            }

            if ($restaurant_name) {
                $data = str_replace('{restaurantName}', $restaurant_name, $data);
            }

            if ($delivery_man_name) {
                $data = str_replace('{deliveryManName}', $delivery_man_name, $data);
            }

            if ($transaction_id) {
                $data = str_replace('{transactionId}', $transaction_id, $data);
            }

            if ($order_id) {
                $data = str_replace('{orderId}', $order_id, $data);
            }
            if ($add_id) {
                $data = str_replace('{advertisementId}', $add_id, $data);
            }
            if ($otp) {
                $data = str_replace('{otp}', $otp, $data);
            }
            if ($token_number) {
                $data = str_replace('{tokenNumber}', $token_number, $data);
            }
            if ($table_number) {
                $data = str_replace('{tableNumber}', $table_number, $data);
            }
        }

        return $data;
    }

    public static function get_login_url($type)
    {
        $data = DataSetting::whereIn('key', ['restaurant_employee_login_url', 'restaurant_login_url', 'admin_employee_login_url', 'admin_login_url',
        ])->pluck('key', 'value')->toArray();

        return array_search($type, $data);
    }

    public static function time_date_format($data)
    {
        $time = config('timeformat') ?? 'H:i';

        return Carbon::parse($data)->locale(app()->getLocale())->translatedFormat('d M Y '.$time);
    }

    public static function date_format($data)
    {
        return Carbon::parse($data)->locale(app()->getLocale())->translatedFormat('d M Y');
    }

    public static function human_time_format($data)
    {
        $time = Carbon::parse($data);

        if ($time->lt(now()->subWeek())) {
            return self::date_format($data);
        }

        return $time->locale(app()->getLocale())->diffForHumans();
    }


    public static function time_format($data)
    {
        $time = config('timeformat') ?? 'H:i';

        return Carbon::parse($data)->locale(app()->getLocale())->translatedFormat($time);
    }

    public static function get_zones_name($zones)
    {
        if (is_array($zones)) {
            $data = Zone::whereIn('id', $zones)->pluck('name')->toArray();
        } else {
            $data = Zone::where('id', $zones)->pluck('name')->toArray();
        }
        $data = implode(', ', $data);

        return $data;
    }

    public static function get_restaurant_name($restaurant)
    {
        if (is_array($restaurant)) {
            $data = Restaurant::whereIn('id', $restaurant)->pluck('name')->toArray();
        } else {
            $data = Restaurant::where('id', $restaurant)->pluck('name')->toArray();
        }
        $data = implode(', ', $data);

        return $data;
    }

    public static function get_category_name($id)
    {
        $id = json_decode($id, true);
        $id = data_get($id, '0.id', 'NA');
        $data = Category::with('translations')->where('id', $id)->first()?->name ?? translate('messages.uncategorize');

        return $data;
    }

    public static function get_sub_category_name($id)
    {
        $id = json_decode($id, true);
        $id = data_get($id, '1.id', 'NA');

        return Category::where('id', $id)->first()?->name;
    }

    public static function get_food_variations($variations)
    {
        try {
            $data = [];
            $data2 = [];
            foreach ((array) json_decode($variations, true) as $key => $choice) {
                if (data_get($choice, 'values', null)) {
                    foreach (data_get($choice, 'values', []) as $k => $v) {
                        $data2[$k] = $v['label'];
                        // if(!next($choice['values'] )) {
                        //     $data2[$k] =  $v['label'].";";
                        // }
                    }
                    $data[$choice['name']] = $data2;
                }
            }

            return str_ireplace(['\'', '"', '{', '}', '[', ']', '<', '>', '?'], ' ', json_encode($data));
        } catch (\Exception $ex) {
            info(["line___{$ex->getLine()}", $ex->getMessage()]);

            return 0;
        }

    }

    public static function get_customer_name($id)
    {
        $user = User::where('id', $id)->first();

        return $user->f_name.' '.$user->l_name;
    }

    public static function get_addon_data($id)
    {
        try {
            $data = [];
            $addon = AddOn::whereIn('id', json_decode($id, true))->get(['name', 'price'])->toArray();
            foreach ($addon as $key => $value) {
                $data[$key] = $value['name'].' - '.\App\CentralLogics\Helpers::format_currency($value['price']);
            }

            return str_ireplace(['\'', '"', '{', '}', '[', ']', '<', '>', '?'], ' ', json_encode($data, JSON_UNESCAPED_UNICODE));
        } catch (\Exception $ex) {
            info(["line___{$ex->getLine()}", $ex->getMessage()]);

            return 0;
        }
    }

    public static function get_business_data($name)
    {
        return self::get_business_settings($name);
    }

    public static function add_or_update_translations($request, $key_data, $name_field, $model_name, $data_id, $data_value)
    {
        try {
            $model = 'App\\Models\\'.$model_name;
            $default_lang = str_replace('_', '-', app()->getLocale());
            foreach ($request->lang as $index => $key) {
                if ($default_lang == $key && ! ($request->{$name_field}[$index])) {
                    if ($key != 'default') {
                        Translation::updateorcreate(
                            [
                                'translationable_type' => $model,
                                'translationable_id' => $data_id,
                                'locale' => $key,
                                'key' => $key_data,
                            ],
                            ['value' => $data_value]
                        );
                    }
                } else {
                    if ($request->{$name_field}[$index] && $key != 'default' && ! in_array($request->{$name_field}[$index], ['', ' '])) {
                        Translation::updateorcreate(
                            [
                                'translationable_type' => $model,
                                'translationable_id' => $data_id,
                                'locale' => $key,
                                'key' => $key_data,
                            ],
                            ['value' => $request->{$name_field}[$index]]
                        );
                    }
                }
            }

            return true;
        } catch (\Exception $e) {
            info(["line___{$e->getLine()}", $e->getMessage()]);

            return false;
        }
    }

    /**
     * 哪吒: 顾客离线支付凭证里"付款截图"字段的完整可访问 URL。
     * 仅当 $value 是一个【真实存在于磁盘】的图片相对路径(如 offline_payment/xxx.webp)时返回 URL,
     * 否则返回 null(纯文本字段/已过期清除/伪造的图片扩展名都会得到 null,从而仍按文本展示)。
     * 与 PurgePaymentProofs::looksLikeStoredFile 的判定保持一致(扩展名白名单 + 文件真实存在)。
     */
    public static function offline_payment_proof_url($value)
    {
        if (! is_string($value) || $value === '' || strlen($value) > 255) {
            return null;
        }
        if (! preg_match('/\.(png|jpe?g|webp|gif|pdf)$/i', $value)) {
            return null;
        }
        $disk = self::getDisk();
        try {
            if (! Storage::disk($disk)->exists($value)) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        $dir = trim(dirname($value), '/.');
        if ($dir === '' || $dir === '.') {
            $dir = 'offline_payment';
        }

        return self::get_full_url($dir, basename($value), $disk, 'order');
    }

    public static function offline_payment_formater($user_data)
    {
        $userInputs = [];

        $user_inputes = json_decode($user_data->payment_info, true) ?: [];
        $method_name = $user_inputes['method_name'] ?? null;
        $method_id = $user_inputes['method_id'] ?? null;

        foreach ($user_inputes as $key => $value) {
            if (! in_array($key, ['method_name', 'method_id'])) {
                // 哪吒: 截图字段 → 附带可访问的图片 URL(顾客端/后台据此渲染可点开的图片)。
                $fileUrl = self::offline_payment_proof_url($value);
                $userInput = [
                    'user_input' => $key,
                    'user_data' => $value,
                    'is_file' => $fileUrl !== null,
                    'file_url' => $fileUrl,
                ];
                $userInputs[] = $userInput;
            }
        }

        $data = [
            'status' => $user_data->status,
            'method_id' => $method_id,
            'method_name' => $method_name,
            'customer_note' => $user_data->customer_note,
            'admin_note' => $user_data->note,
        ];

        $result = [
            'input' => $userInputs,
            'data' => $data,
            'method_fields' => json_decode($user_data->method_fields, true),
        ];

        return $result;
    }

    public static function getDeliveryFee($restaurant): string
    {
        if (! request()->header('latitude') || ! request()->header('longitude')) {
            return 'out_of_range';
        }
        $zone = Zone::where('id', $restaurant->zone_id)->whereContains('coordinates', new Point(request()->header('latitude') && request()->header('longitude'), POINT_SRID))->first();
        if (! $zone) {
            return 'out_of_range';
        }
        if (isset($restaurant->distance) && $restaurant->distance > 0) {
            $distance = $restaurant->distance / 1000;
            $distance = round($distance, 5);
        } elseif ($restaurant->latitude && $restaurant->longitude) {

            $originCoordinates = [
                $restaurant->latitude,
                $restaurant->longitude,
            ];
            $destinationCoordinates = [
                request()->header('latitude'),
                request()->header('longitude'),
            ];
            $distance = self::get_distance($originCoordinates, $destinationCoordinates);
            $distance = round($distance, 5);
        } else {
            return 'out_of_range';
        }

        if ($restaurant['self_delivery_system'] == 1) {

            if ($restaurant->free_delivery == 1) {
                return 'free_delivery';
            }
            if ($restaurant->free_delivery_distance_status == 1 && $distance <= $restaurant->free_delivery_distance_value) {
                return 'free_delivery';
            }

            $per_km_shipping_charge = $restaurant->per_km_shipping_charge ?? 0;
            $minimum_shipping_charge = $restaurant->minimum_shipping_charge ?? 0;
            $maximum_shipping_charge = $restaurant->maximum_shipping_charge ?? 0;
            $extra_charges = 0;
            $increased = 0;

        } else {

            $businessSettings = BusinessSetting::whereIn('key', ['free_delivery_over', 'free_delivery_distance', 'admin_free_delivery_status', 'admin_free_delivery_option'])->pluck('value', 'key');

            $free_delivery_over = (float) ($businessSettings['free_delivery_over'] ?? 0);
            $free_delivery_distance = (float) ($businessSettings['free_delivery_distance'] ?? 0);
            $admin_free_delivery_status = (int) ($businessSettings['admin_free_delivery_status'] ?? 0);
            $admin_free_delivery_option = $businessSettings['admin_free_delivery_option'] ?? null;

            if ($admin_free_delivery_status == 1) {

                if ($admin_free_delivery_option === 'free_delivery_to_all_store' || ($admin_free_delivery_option === 'free_delivery_by_specific_criteria' && ($free_delivery_distance > 0 && $distance <= $free_delivery_distance))) {
                    return 'free_delivery';
                }
            }

            $per_km_shipping_charge = $zone->per_km_shipping_charge ?? 0;
            $minimum_shipping_charge = $zone->minimum_shipping_charge ?? 0;
            $maximum_shipping_charge = $zone->maximum_shipping_charge ?? 0;
            $increased = 0;
            if ($zone->increased_delivery_fee_status == 1) {
                $increased = $zone->increased_delivery_fee ?? 0;
            }
            $data = self::vehicle_extra_charge(distance_data: $distance);
            $extra_charges = (float) (isset($data) ? $data['extra_charge'] : 0);

        }

        $original_delivery_charge = ($distance * $per_km_shipping_charge > $minimum_shipping_charge) ? $distance * $per_km_shipping_charge + $extra_charges : $minimum_shipping_charge + $extra_charges;
        if ($increased > 0 && $original_delivery_charge > 0) {
            $increased_fee = ($original_delivery_charge * $increased) / 100;
            $original_delivery_charge = $original_delivery_charge + $increased_fee;
        }

        return (string) $original_delivery_charge;

    }

    public static function get_distance(array $originCoordinates, array $destinationCoordinates, $unit = 'K'): float
    {
        $lat1 = (float) $originCoordinates[0];
        $lat2 = (float) $destinationCoordinates[0];
        $lon1 = (float) $originCoordinates[1];
        $lon2 = (float) $destinationCoordinates[1];

        if (($lat1 == $lat2) && ($lon1 == $lon2)) {
            return 0;
        } else {
            $theta = $lon1 - $lon2;
            $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
            $dist = acos($dist);
            $dist = rad2deg($dist);
            $miles = $dist * 60 * 1.1515;
            $unit = strtoupper($unit);
            if ($unit == 'K') {
                return $miles * 1.609344;
            } elseif ($unit == 'N') {
                return $miles * 0.8684;
            } else {
                return $miles;
            }
        }
    }

    public static function onerror_image_helper($data, $src, $error_src, $path)
    {

        if (isset($data) && strlen($data) > 1 && Storage::disk('public')->exists($path.$data)) {
            return $src;
        }

        return $error_src;
    }

    public static function getNextOpeningTime($schedule)
    {
        $currentTime = now()->format('H:i');
        if ($schedule) {
            foreach ($schedule as $entry) {
                if ($entry['day'] == now()->format('w')) {
                    if ($currentTime >= $entry['opening_time'] && $currentTime <= $entry['closing_time']) {
                        return $entry['opening_time'];
                    } elseif ($currentTime < $entry['opening_time']) {
                        return $entry['opening_time'];
                    }
                }
            }
        }

        return 'closed';
    }

    public static function generateDatesForSubscriptionOrders($start_at, $end_at, $scheduleDates, $scheduleTime, $pauseArray, $scheduleType)
    {
        $start = new DateTime($start_at);
        $end = new DateTime($end_at);
        $interval = new DateInterval('P1D');
        $end->modify('+1 day');
        $period = new DatePeriod($start, $interval, $end);

        $result = [];
        foreach ($period as $date) {
            $skipDate = false;
            foreach ($pauseArray as $pauseStart => $pauseEnd) {
                if ($date >= new DateTime($pauseStart) && $date <= new DateTime($pauseEnd)) {
                    $skipDate = true;
                    break;
                }
            }
            if (! $skipDate && $date->format('Y-m-d') > now()->format('Y-m-d') && (in_array($date->format('j'), $scheduleDates) || in_array($date->format('w'), $scheduleDates) || in_array('daily', $scheduleDates))) {
                foreach ($scheduleTime as $key => $time) {
                    if (($date->format('j') == $key && $scheduleType == 'monthly') || ($date->format('w') == $key && $scheduleType == 'weekly') || in_array('daily', $scheduleDates)) {
                        $result[] = $date->format('Y-m-d').' '.$time;
                    }
                }
            }
        }

        return $result;
    }

    public static function getCalculatedCashBackAmount($amount, $customer_id)
    {
        $data = [
            'calculated_amount' => (float) 0,
            'cashback_amount' => 0,
            'cashback_type' => '',
            'min_purchase' => 0,
            'max_discount' => 0,
            'id' => 0,
        ];

        try {
            $percent_bonus = CashBack::active()
                ->where('cashback_type', 'percentage')
                ->Running()
                ->where('min_purchase', '<=', $amount)
                ->where(function ($query) use ($customer_id) {
                    $query->whereJsonContains('customer_id', [(string) $customer_id])->orWhereJsonContains('customer_id', ['all']);
                })
                ->when(is_numeric($customer_id), function ($q) use ($customer_id) {
                    $q->where('same_user_limit', '>', function ($query) use ($customer_id) {
                        $query->select(DB::raw('COUNT(*)'))
                            ->from('cash_back_histories')
                            ->where('user_id', $customer_id)
                            ->whereColumn('cash_back_id', 'cash_backs.id');
                    });
                })
                ->orderBy('cashback_amount', 'desc')
                ->first();

            $amount_bonus = CashBack::active()->where('cashback_type', 'amount')
                ->Running()
                ->where(function ($query) use ($customer_id) {
                    $query->whereJsonContains('customer_id', [(string) $customer_id])->orWhereJsonContains('customer_id', ['all']);
                })
                ->where('min_purchase', '<=', $amount)
                ->when(is_numeric($customer_id), function ($q) use ($customer_id) {
                    $q->where('same_user_limit', '>', function ($query) use ($customer_id) {
                        $query->select(DB::raw('COUNT(*)'))
                            ->from('cash_back_histories')
                            ->where('user_id', $customer_id)
                            ->whereColumn('cash_back_id', 'cash_backs.id');
                    });
                })
                ->orderBy('cashback_amount', 'desc')->first();

            if ($percent_bonus && ($amount >= $percent_bonus->min_purchase)) {
                $p_bonus = ($amount * $percent_bonus->cashback_amount) / 100;
                $p_bonus = $p_bonus > $percent_bonus->max_discount ? $percent_bonus->max_discount : $p_bonus;
                $p_bonus = round($p_bonus, config('round_up_to_digit'));
            } else {
                $p_bonus = 0;
            }

            if ($amount_bonus && ($amount >= $amount_bonus->min_purchase)) {
                $a_bonus = $amount_bonus ? $amount_bonus->cashback_amount : 0;
                $a_bonus = round($a_bonus, config('round_up_to_digit'));
            } else {
                $a_bonus = 0;
            }

            $cashback_amount = max([$p_bonus, $a_bonus]);

            if ($p_bonus == $cashback_amount) {
                $data = [
                    'calculated_amount' => (float) $cashback_amount,
                    'cashback_amount' => $percent_bonus?->cashback_amount ?? 0,
                    'cashback_type' => $percent_bonus?->cashback_type ?? '',
                    'min_purchase' => $percent_bonus?->min_purchase ?? 0,
                    'max_discount' => $percent_bonus?->max_discount ?? 0,
                    'id' => $percent_bonus?->id,
                ];

            } elseif ($a_bonus == $cashback_amount) {
                $data = [
                    'calculated_amount' => (float) $cashback_amount,
                    'cashback_amount' => $amount_bonus?->cashback_amount ?? 0,
                    'cashback_type' => $amount_bonus?->cashback_type ?? '',
                    'min_purchase' => $amount_bonus?->min_purchase ?? 0,
                    'max_discount' => $amount_bonus?->max_discount ?? 0,
                    'id' => $amount_bonus?->id,
                ];
            }

            return $data;
        } catch (\Exception $exception) {
            info([$exception->getFile(), $exception->getLine(), $exception->getMessage()]);

            return $data;
        }

    }

    public static function getCusromerFirstOrderDiscount($order_count, $user_creation_date, $refby, $price = null)
    {

        $data = [
            'is_valid' => false,
            'discount_amount' => 0,
            'discount_amount_type' => '',
            'validity' => '',
            'calculated_amount' => 0,
        ];
        if ($order_count > 0 || ! $refby) {
            return $data ?? [];
        }
        $settings = array_column(BusinessSetting::whereIn('key', ['new_customer_discount_status', 'new_customer_discount_amount', 'new_customer_discount_amount_type', 'new_customer_discount_amount_validity', 'new_customer_discount_validity_type'])->get()->toArray(), 'value', 'key');

        $validity_value = data_get($settings, 'new_customer_discount_amount_validity');
        $validity_unit = data_get($settings, 'new_customer_discount_validity_type');

        if ($validity_unit == 'day') {
            $validity_end_date = (new DateTime($user_creation_date))->modify("+$validity_value day");

        } elseif ($validity_unit == 'month') {
            $validity_end_date = (new DateTime($user_creation_date))->modify("+$validity_value month");

        } elseif ($validity_unit == 'year') {
            $validity_end_date = (new DateTime($user_creation_date))->modify("+$validity_value year");
        } else {
            $validity_end_date = (new DateTime($user_creation_date))->modify('-1 day');
        }

        $is_valid = false;
        $current_date = new DateTime;
        if ($validity_end_date >= $current_date) {
            $is_valid = true;
        }

        if ($order_count == 0 && $is_valid && data_get($settings, 'new_customer_discount_status') == 1 && data_get($settings, 'new_customer_discount_amount') > 0) {
            $calculated_amount = 0;
            if (data_get($settings, 'new_customer_discount_amount_type') == 'percentage' && isset($price)) {
                $calculated_amount = ($price / 100) * data_get($settings, 'new_customer_discount_amount');
            } else {
                $calculated_amount = data_get($settings, 'new_customer_discount_amount');
            }

            $data = [
                'is_valid' => $is_valid,
                'discount_amount' => data_get($settings, 'new_customer_discount_amount'),
                'discount_amount_type' => data_get($settings, 'new_customer_discount_amount_type'),
                'validity' => data_get($settings, 'new_customer_discount_amount_validity').' '.translate(Str::plural((data_get($settings, 'new_customer_discount_validity_type') ?? 'day'), data_get($settings, 'new_customer_discount_amount_validity'))),
                'calculated_amount' => round($calculated_amount, config('round_up_to_digit')),
            ];
        }

        return $data ?? [];
    }

    public static function addonAndVariationStockCheck($product, $quantity = 1, $add_on_qtys = 1, $variation_options = null, $add_on_ids = null, $incrementCount = false, $old_selected_variations = [], $old_selected_without_variation = 0, $old_selected_addons = [])
    {

        if ($product?->stock_type && $product?->stock_type !== 'unlimited') {
            $availableMainStock = $product->item_stock + $old_selected_without_variation;
            if ($availableMainStock <= 0 || $availableMainStock < $quantity) {
                return [
                    'out_of_stock' => $availableMainStock > 0 ? translate('Only').' '.$availableMainStock.' '.translate('Quantity_is_abailable_for').' '.$product?->name : $product?->name.' '.translate('is_out_of_stock_!!!'),
                    'id' => $product->id,
                    'current_stock' => $availableMainStock > 0 ? $availableMainStock : 0,
                ];
            }
            if ($product?->stock_type && $incrementCount == true) {
                $product->increment('sell_count', $quantity);
            }

            if (is_array($variation_options) && (data_get($variation_options, 0) != '' || data_get($variation_options, 0) != null)) {
                $variation_options = VariationOption::whereIn('id', $variation_options)->get();
                foreach ($variation_options as $variation_option) {
                    if ($variation_option->stock_type !== 'unlimited') {
                        $availableStock = $variation_option->total_stock - $variation_option->sell_count;
                        if (is_array($old_selected_variations) && data_get($old_selected_variations, $variation_option->id)) {
                            $availableStock = $availableStock + data_get($old_selected_variations, $variation_option->id);
                        }
                        if ($availableStock <= 0 || $availableStock < $quantity) {
                            return ['out_of_stock' => $availableStock > 0 ? translate('Only').' '.$availableStock.' '.translate('Quantity_is_abailable_for').' '.$product?->name.' \'s '.$variation_option->option_name.' '.translate('Variation_!!!') : $product?->name.' \'s '.$variation_option->option_name.' '.translate('Variation_is_out_of_stock_!!!'),
                                'id' => $variation_option->id,
                                'current_stock' => $availableStock > 0 ? $availableStock : 0,
                            ];
                        }
                        if ($incrementCount == true) {
                            $variation_option->increment('sell_count', $quantity);
                        }
                    }
                }
            }
        }

        if (is_array($add_on_ids) && count($add_on_ids) > 0) {
            return Helpers::calculate_addon_price(addons: AddOn::whereIn('id', $add_on_ids)->get(), add_on_qtys: $add_on_qtys, incrementCount: $incrementCount, old_selected_addons: $old_selected_addons);
        }

        return null;
    }

    public static function decreaseSellCount($order_details)
    {
        foreach ($order_details as $detail) {
            $optionIds = [];
            if ($detail->variation != '[]') {
                foreach (json_decode($detail->variation, true) as $value) {
                    foreach (data_get($value, 'values', []) as $item) {
                        if (data_get($item, 'option_id', null) != null) {
                            $optionIds[] = data_get($item, 'option_id', null);
                        }
                    }
                }
                VariationOption::whereIn('id', $optionIds)->where('sell_count', '>', 0)->decrement('sell_count', $detail->quantity);
            }
            $detail->food()->where('sell_count', '>', 0)->decrement('sell_count', $detail->quantity);

            foreach (json_decode($detail->add_ons, true) as $add_ons) {
                if (data_get($add_ons, 'id', null) != null) {
                    AddOn::where('id', data_get($add_ons, 'id', null))->where('sell_count', '>', 0)->decrement('sell_count', data_get($add_ons, 'quantity', 1));
                }
            }
        }

        return true;
    }

    public static function notificationDataSetup()
    {
        $data = self::getAdminNotificationSetupData();
        $data = NotificationSetting::upsert($data, ['key', 'type'], ['title', 'mail_status', 'sms_status', 'push_notification_status', 'sub_title']);

        return true;
    }

    public static function restaurantNotificationDataSetup($id)
    {
        $data = self::getRestaurantNotificationSetupData($id);
        $data = RestaurantNotificationSetting::upsert($data, ['key', 'restaurant_id'], ['title', 'mail_status', 'sms_status', 'push_notification_status', 'sub_title']);

        return true;
    }

    public static function getNotificationStatusData($user_type, $key)
    {
        $data = NotificationSetting::where('type', $user_type)->where('key', $key)->select(['mail_status', 'push_notification_status', 'sms_status'])->first();

        return $data ?? null;
    }

    public static function getRestaurantNotificationStatusData($restaurant_id, $key)
    {
        $data = RestaurantNotificationSetting::where('restaurant_id', $restaurant_id)->where('key', $key)->select(['mail_status', 'push_notification_status', 'sms_status'])->first();
        if (! $data) {
            self::addNewRestaurantNotificationSetupData($restaurant_id);
            $data = RestaurantNotificationSetting::where('restaurant_id', $restaurant_id)->where('key', $key)->select(['mail_status', 'push_notification_status', 'sms_status'])->first();
            if (! $data) {
                self::restaurantNotificationDataSetup($restaurant_id);
                $data = RestaurantNotificationSetting::where('restaurant_id', $restaurant_id)->where('key', $key)->select(['mail_status', 'push_notification_status', 'sms_status'])->first();
            }
        }

        return $data ?? null;
    }

    public static function addNewAdminNotificationSetupDataSetup()
    {
        self::addNewAdminNotificationSetupData();

        return true;
    }

    public static function getActivePaymentGateways()
    {

        if (! Schema::hasTable('addon_settings')) {
            return [];
        }
        $digital_payment = \App\CentralLogics\Helpers::get_business_settings('digital_payment');

        if ($digital_payment && $digital_payment['status'] == 0) {
            return [];
        }

        $published_status = 0;
        $payment_published_status = config('get_payment_publish_status');
        if (isset($payment_published_status[0]['is_published'])) {
            $published_status = $payment_published_status[0]['is_published'];
        }

        if ($published_status == 1) {
            $methods = DB::table('addon_settings')->where('is_active', 1)->where('settings_type', 'payment_config')->get();
            $env = env('APP_ENV') == 'live' ? 'live' : 'test';
            $credentials = $env.'_values';

        } else {
            $methods = DB::table('addon_settings')->where('is_active', 1)->whereIn('settings_type', ['payment_config'])->whereIn('key_name', ['ssl_commerz', 'paypal', 'stripe', 'razor_pay', 'senang_pay', 'paytabs', 'paystack', 'paymob_accept', 'paytm', 'flutterwave', 'liqpay', 'bkash', 'mercadopago'])->get();
            $env = env('APP_ENV') == 'live' ? 'live' : 'test';
            $credentials = $env.'_values';

        }

        $data = [];
        foreach ($methods as $method) {
            $credentialsData = json_decode($method->$credentials);
            $additional_data = json_decode($method->additional_data);
            if ($credentialsData->status == 1) {
                $data[] = [
                    'gateway' => $method->key_name,
                    'gateway_title' => $additional_data?->gateway_title,
                    'gateway_image' => $additional_data?->gateway_image,
                    'gateway_image_full_url' => Helpers::get_full_url('payment_modules/gateway_image', $additional_data?->gateway_image, $additional_data?->storage ?? 'public'),
                ];
            }
        }

        return $data;

    }

    public static function checkCurrency($data, $type = null)
    {

        $digital_payment = self::get_business_settings('digital_payment');

        if ($digital_payment && $digital_payment['status'] == 1) {
            if ($type === null) {
                if (is_array(self::getActivePaymentGateways())) {
                    foreach (self::getActivePaymentGateways() as $payment_gateway) {

                        if (! empty(self::getPaymentGatewaySupportedCurrencies($payment_gateway['gateway'])) && ! array_key_exists($data, self::getPaymentGatewaySupportedCurrencies($payment_gateway['gateway']))) {
                            return $payment_gateway['gateway'];
                        }
                    }
                }
            } elseif ($type == 'payment_gateway') {
                $currency = BusinessSetting::where('key', 'currency')->first()?->value;
                if (! empty(self::getPaymentGatewaySupportedCurrencies($data)) && ! array_key_exists($currency, self::getPaymentGatewaySupportedCurrencies($data))) {
                    return $data;
                }
            }
        }

        return true;
    }

    public static function updateStorageTable($dataType, $dataId, $image)
    {
        $value = Helpers::getDisk();
        DB::table('storages')->updateOrInsert([
            'data_type' => $dataType,
            'data_id' => $dataId,
            'key' => 'image',
        ], [
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function add_fund_push_notification($user_id, $amount = '')
    {
        $user = User::where('id', $user_id)->first();

        $message = Helpers::getPushNotificationMessage(status: 'customer_add_fund_to_wallet', userType: 'user', lang: $user?->cm_firebase_token, userName: $user?->f_name.' '.$user?->l_name);
        if ($message && isset($user?->cm_firebase_token)) {
            $data = Helpers::makeDataForPushNotification(title: translate('messages.Fund_added'), message: $message, orderId: '', type: 'add_fund', orderStatus: '', amount: $amount);
            Helpers::send_push_notif_to_device($user?->cm_firebase_token, $data);
            Helpers::insertDataOnNotificationTable($data, 'user', $user_id);
        }

        return true;
    }

    public static function getImageForExport($imagePath)
    {
        $temporaryImage = self::getTemporaryImageForExport($imagePath);
        $pngImage = imagecreatetruecolor(imagesx($temporaryImage), imagesy($temporaryImage));
        imagealphablending($pngImage, false);
        imagesavealpha($pngImage, true);
        imagecopy($pngImage, $temporaryImage, 0, 0, 0, 0, imagesx($temporaryImage), imagesy($temporaryImage));

        return $pngImage;
    }

    public static function getTemporaryImageForExport($imagePath)
    {
        try {
            $imageData = file_get_contents($imagePath);

            return imagecreatefromstring($imageData);
        } catch (\Throwable $th) {
            $imageData = file_get_contents(dynamicAsset('assets/admin/img/100x100/no-image-found.png'));

            return imagecreatefromstring($imageData);

        }
    }

    public static function CheckOldSubscriptionSettings()
    {
        if (BusinessSetting::where(['key' => 'free_trial_period'])->exists()) {
            $old_trial_data = BusinessSetting::where(['key' => 'free_trial_period'])->first();
            $data = json_decode($old_trial_data?->value, true);
            if (isset($data['status']) && $data['status'] == 1) {
                $type = data_get($data, 'type');

                if ($type == 'year') {
                    $free_trial_period = data_get($data, 'data') * 365;
                } elseif ($type == 'month') {
                    $free_trial_period = data_get($data, 'data') * 30;
                } else {
                    $free_trial_period = data_get($data, 'data', 1);
                }

                $key = ['subscription_free_trial_days', 'subscription_free_trial_type', 'subscription_free_trial_status'];
                foreach ($key as $value) {
                    $status = BusinessSetting::firstOrNew([
                        'key' => $value,
                    ]);
                    if ($value == 'subscription_free_trial_days') {
                        $status->value = $free_trial_period;
                    } elseif ($value == 'subscription_free_trial_type') {
                        $status->value = $type ?? 'day';
                    } elseif ($value == 'subscription_free_trial_status') {
                        $status->value = $data['status'];
                    }
                    $status->save();
                }
            }

            $old_trial_data?->delete();
        }
    }

    public static function calculateSubscriptionRefundAmount($restaurant, $return_data = null)
    {

        $restaurant_subscription = $restaurant->restaurant_sub;
        if ($restaurant_subscription && $restaurant_subscription?->is_canceled === 0 && $restaurant_subscription?->is_trial === 0) {
            $day_left = $restaurant_subscription->expiry_date_parsed->format('Y-m-d');
            if (Carbon::now()->diffInDays($day_left, false) > 0) {
                $add_days = Carbon::now()->diffInDays($day_left, false);
                $validity = $restaurant_subscription?->validity;
                $subscription_usage_max_time = BusinessSetting::where('key', 'subscription_usage_max_time')->first()?->value ?? 50;
                $subscription_usage_max_time = ($validity * $subscription_usage_max_time) / 100;

                if (($validity - $add_days) < $subscription_usage_max_time) {
                    $per_day = $restaurant->restaurant_sub_trans->price / $restaurant->restaurant_sub_trans->validity;
                    $back_amount = $per_day * $add_days;

                    if ($return_data == true) {
                        return ['back_amount' => $back_amount, 'days' => $add_days];
                    }

                    $vendorWallet = RestaurantWallet::firstOrNew(
                        ['vendor_id' => $restaurant->vendor_id]
                    );
                    $vendorWallet->total_earning = $vendorWallet->total_earning + $back_amount;
                    $vendorWallet->save();

                    $refund = new SubscriptionBillingAndRefundHistory;
                    $refund->restaurant_id = $restaurant->id;
                    $refund->subscription_id = $restaurant_subscription->id;
                    $refund->package_id = $restaurant_subscription->package_id;
                    $refund->transaction_type = 'refund';
                    $refund->is_success = 1;
                    $refund->amount = $back_amount;
                    $refund->reference = 'validity_left_'.$add_days;
                    $refund->save();

                }
            }

        }

        return true;
    }

    public static function subscriptionConditionsCheck($restaurant_id, $package_id)
    {
        $restaurant = Restaurant::findOrFail($restaurant_id);
        $package = SubscriptionPackage::withoutGlobalScope('translate')->find($package_id);

        $total_food = $restaurant->foods()->withoutGlobalScope(\App\Scopes\RestaurantScope::class)->count();
        if ($package->max_product != 'unlimited' && $total_food >= $package->max_product) {
            return ['disable_item_count' => $total_food - $package->max_product];
            // return 'downgrade_error';
        }

        return null;
    }

    public static function subscriptionPayment($restaurant_id, $package_id, $payment_gateway, $url, $pending_bill = 0, $type = 'payment', $payment_platform = 'web')
    {
        $restaurant = Restaurant::where('id', $restaurant_id)->first();
        $package = SubscriptionPackage::where('id', $package_id)->first();
        $type == null ? 'payment' : $type;

        $payer = new Payer(
            $restaurant->name,
            $restaurant->email,
            $restaurant->phone,
            ''
        );
        $restaurant_logo = BusinessSetting::where(['key' => 'logo'])->first();
        $additional_data = [
            'business_name' => BusinessSetting::where(['key' => 'business_name'])->first()?->value,
            'business_logo' => \App\CentralLogics\Helpers::get_full_url('business', $restaurant_logo?->value, $restaurant_logo?->storage[0]?->value ?? 'public'),
        ];
        $payment_info = new PaymentInfo(
            success_hook: 'sub_success',
            failure_hook: 'sub_fail',
            currency_code: Helpers::currency_code(),
            payment_method: $payment_gateway,
            payment_platform: $payment_platform,
            payer_id: $restaurant->id,
            receiver_id: $package->id,
            additional_data: $additional_data,
            payment_amount: $package->price + $pending_bill,
            external_redirect_link: $url,
            attribute: 'restaurant_subscription_'.$type,
            attribute_id: $package->id,
        );
        $receiver_info = new Receiver('Admin', 'example.png');
        $redirect_link = Payment::generate_link($payer, $payment_info, $receiver_info);

        return $redirect_link;
    }

    public static function getSettingsDataFromConfig($settings, $relations = [])
    {
        try {
            if (! config($settings.'_conf')) {
                $data = BusinessSetting::where('key', $settings)->with($relations)->first();
                Config::set($settings.'_conf', $data);
            } else {
                $data = config($settings.'_conf');
            }

            return $data;
        } catch (\Throwable $th) {
            return null;
        }
    }

    public static function businessUpdateOrInsert($key, $value)
    {
        $businessSetting = BusinessSetting::where(['key' => $key['key']])->first();
        if ($businessSetting) {
            $businessSetting->value = $value['value'];
            $businessSetting->save();
        } else {
            $businessSetting = new BusinessSetting;
            $businessSetting->key = $key['key'];
            $businessSetting->value = $value['value'];
            $businessSetting->save();
        }
    }

    public static function dataUpdateOrInsert($key, $value)
    {
        $businessSetting = DataSetting::where(['key' => $key['key'], 'type' => $key['type']])->first();
        if ($businessSetting) {
            $businessSetting->value = $value['value'];
            $businessSetting->save();
        } else {
            $businessSetting = new DataSetting;
            $businessSetting->key = $key['key'];
            $businessSetting->type = $key['type'];
            $businessSetting->value = $value['value'];
            $businessSetting->save();
        }
    }

    public static function checkAdminDiscount($price, $discount, $max_discount, $min_purchase, $item_wise_price = null)
    {
        if ($price > 0 && $discount > 0) {
            $discount = ($price * $discount) / 100;
            $discount = $discount > $max_discount ? $max_discount : $discount;
            $discount = $price >= $min_purchase ? $discount : 0;
        }

        if ($discount > 0 && $item_wise_price > 0) {
            $discount = ($item_wise_price / $price) * $discount;
        }

        return $discount ?? 0;
    }

    public static function getFinalCalculatedTax($details_data, $additionalCharges, $totalDiscount, $price, $storeId, $storeData = true)
    {
        $addonIds = [];
        $products = [];
        $tempList = [];
        $taxData = [];

        $productDiscountTotal = 0;
        $addonDiscountTotal = 0;
        $totalAfterOwnDiscounts = 0;
        if (addon_published_status('TaxModule')) {

            foreach ($details_data as $item) {
                $item_id = $item['food_id'] ?? data_get($item, 'item_campaign_id');
                $itemWiseDiscount = $item['discount_type'] === 'discount_on_product' ? $item['discount_on_food'] * $item['quantity'] : $item['discount_on_food'];
                $productDiscountTotal += $itemWiseDiscount;

                $itemTotal = $item['price'] * $item['quantity'];
                $itemFinal = $itemTotal - $itemWiseDiscount;

                $tempList[] = [
                    'type' => 'product',
                    'id' => $item_id,
                    'original_price' => $item['price'],
                    'quantity' => $item['quantity'],
                    'category_id' => $item['category_id'],
                    'discount' => $item['discount_on_food'],
                    'discount_type' => $item['discount_type'],
                    'base_final' => $itemFinal,
                    'is_campaign_item' => data_get($item, 'item_campaign_id') ? true : false,
                ];

                $totalAfterOwnDiscounts += $itemFinal;

                $addons = json_decode($item['add_ons'], true) ?? [];
                $addonDiscount = $item['addon_discount'] ?? 0;
                $addonTotalPrice = $item['total_add_on_price'] ?? 1;

                $addonTotalPrice = max($addonTotalPrice, 1);
                $addonDiscountTotal += $addonDiscount;

                foreach ($addons as $addon) {
                    $addonPrice = $addon['price'] * $addon['quantity'];
                    $discountPart = $addonDiscount * ($addonPrice / $addonTotalPrice);
                    $addonFinal = $addonPrice - $discountPart;

                    $tempList[] = [
                        'type' => 'addon',
                        'addon_id' => $addon['id'],
                        'item_id' => $item_id,
                        'quantity' => $addon['quantity'],
                        'category_id' => $addon['category_id'] ?? null,
                        'original_price' => $addon['price'],
                        'base_final' => $addonFinal,
                        'total_addon_addon_price' => $addonTotalPrice,
                        'total_addon_discount' => $addonDiscount,
                    ];

                    $totalAfterOwnDiscounts += $addonFinal;
                }

            }

            $otherDiscounts = $totalDiscount - ($productDiscountTotal + $addonDiscountTotal);

            foreach ($tempList as $entry) {
                $share = ($entry['base_final'] / $totalAfterOwnDiscounts) * $otherDiscounts;
                $finalPrice = $entry['base_final'] - $share;

                if ($entry['type'] === 'product') {
                    $products[] = [
                        'id' => $entry['id'],
                        'original_price' => $entry['original_price'],
                        'quantity' => $entry['quantity'],
                        'category_id' => $entry['category_id'],
                        'discount' => $entry['discount'],
                        'discount_type' => $entry['discount_type'],
                        'after_discount_final_price' => $finalPrice,
                        'is_campaign_item' => $entry['is_campaign_item'],
                    ];
                } else {
                    $addonIds[] = [
                        'addon_id' => $entry['addon_id'],
                        'item_id' => $entry['item_id'],
                        'quantity' => $entry['quantity'],
                        'category_id' => $entry['category_id'],
                        'original_price' => $entry['original_price'],
                        'after_discount_final_price' => $finalPrice,
                        'total_addon_addon_price' => $entry['total_addon_addon_price'],
                        'total_addon_discount' => $entry['total_addon_discount'],
                    ];
                }
            }

            $taxData = \Modules\TaxModule\Services\CalculateTaxService::getCalculatedTax(
                amount: $price,
                productIds: $products,
                taxPayer: 'vendor',
                storeData: $storeData,
                additionalCharges: $additionalCharges,
                addonIds: $addonIds,
                orderId: null,
                storeId: $storeId
            );

            $tax_amount = $taxData['totalTaxamount'];
            $tax_included = $taxData['include'];
            $tax_status = $tax_included ? 'included' : 'excluded';

            foreach ($taxData['productWiseData'] ?? [] as $key => $item) {
                $taxMap[$key] = $item;
            }
        }

        return [
            'tax_amount' => $tax_amount ?? 0,
            'tax_included' => $tax_included ?? null,
            'tax_status' => $tax_status ?? 'excluded',
            'taxMap' => $taxMap ?? [],
            'taxType' => data_get($taxData, 'taxType'),
            'taxData' => $taxData ?? [],
        ];
    }

    public static function getTaxSystemType($getTaxVatList = true, $tax_payer = 'vendor')
    {
        if (addon_published_status('TaxModule')) {
            $SystemTaxVat = \Modules\TaxModule\Entities\SystemTaxSetup::where('is_active', 1)
                ->where('tax_payer', $tax_payer)->where('is_default', 1)->first();
            if (! $SystemTaxVat) {
                return ['productWiseTax' => false, 'categoryWiseTax' => false, 'taxVats' => []];
            }
            if ($getTaxVatList) {
                $taxVats = \Modules\TaxModule\Entities\Tax::where('is_active', 1)->where('is_default', 1)->get(['id', 'name', 'tax_rate']);
            }

            if ($SystemTaxVat?->tax_type == 'product_wise') {
                $productWiseTax = true;
            } elseif ($SystemTaxVat?->tax_type == 'category_wise') {
                $categoryWiseTax = true;
            }
        }

        return ['productWiseTax' => $productWiseTax ?? false, 'categoryWiseTax' => $categoryWiseTax ?? false, 'taxVats' => $taxVats ?? []];
    }

    public static function deleteCacheData($prefix)
    {
        $cacheKeys = DB::table('cache')
            ->where('key', 'like', '%'.$prefix.'%')
            ->pluck('key');
        $appName = env('APP_NAME').'_cache';
        $remove_prefix = strtolower(str_replace('=', '', $appName));
        $sanitizedKeys = $cacheKeys->map(function ($key) use ($remove_prefix) {
            $key = str_replace($remove_prefix, '', $key);

            return $key;
        });
        foreach ($sanitizedKeys as $key) {
            Cache::forget($key);
        }
    }

    public static function coupon_check($coupon_code, $restaurant_id, $user_id, $is_guest = false)
    {

        $coupon = Coupon::active()->where(['code' => $coupon_code])->first();
        if (isset($coupon)) {
            if ($is_guest) {
                $staus = CouponLogic::is_valid_for_guest(coupon: $coupon, restaurant_id: $restaurant_id);
            } else {
                $staus = CouponLogic::is_valide(coupon: $coupon, user_id: $user_id, restaurant_id: $restaurant_id);
            }

            $message = match ($staus) {
                407 => translate('messages.coupon_expire'),
                408 => translate('messages.You_are_not_eligible_for_this_coupon'),
                406 => translate('messages.coupon_usage_limit_over'),
                404 => translate('messages.not_found'),
                default => null,
            };
            if ($message != null) {
                return ['code' => 'coupon', 'message' => $message, 'status_code' => $staus];
            }
            $coupon->increment('total_uses');
            $coupon_created_by = $coupon->created_by;
            if ($coupon->coupon_type == 'free_delivery') {
                $delivery_charge = 0;
                $free_delivery_by = $coupon_created_by;
                $coupon_created_by = null;
                $coupon = null;
            }

            return ['coupon' => $coupon, 'coupon_created_by' => $coupon_created_by, 'delivery_charge' => $delivery_charge ?? null, 'free_delivery_by' => $free_delivery_by ?? null];
        } else {
            return ['code' => 'coupon', 'message' => translate('messages.not_found'), 'status_code' => 404];
        }
    }

    public static function getFoodSEOData($request, $foodId, $seoData, $type = null): array
    {
        if ($seoData) {
            if ($request->file('meta_image') && $seoData?->image) {
                $metaImage = self::update(dir: 'product/meta/', old_image: $seoData->image, format: 'png', image: $request['meta_image']);
            } elseif ($request->file('meta_image')) {
                $metaImage = self::upload(dir: 'product/meta', format: 'png', image: $request->file('meta_image'));
            } else {
                $metaImage = $seoData?->image ?? null;
            }
        } else {
            if ($request->has('meta_image')) {
                $metaImage = self::upload(dir: 'product/meta', format: 'png', image: $request->file('meta_image'));
            } else {
                $metaImage = null;
            }
        }
        if ($type == 'ItemCampaign') {
            $itemCampaignId = $foodId;
            $foodId = null;
        }

        return [
            'food_id' => $foodId,
            'item_campaign_id' => $itemCampaignId ?? null,
            'title' => $request['meta_title'] ?? ($seoData ? $seoData['title'] : null),
            'description' => $request['meta_description'] ?? ($seoData ? $seoData['description'] : null),
            'index' => $request['meta_index'] == 'index' ? '' : 'noindex',
            'no_follow' => $request['meta_no_follow'] ? 'nofollow' : '',
            'no_image_index' => $request['meta_no_image_index'] ? 'noimageindex' : '',
            'no_archive' => $request['meta_no_archive'] ? 'noarchive' : '',
            'no_snippet' => $request['meta_no_snippet'] ?? 0,
            'max_snippet' => $request['meta_max_snippet'] ?? 0,
            'max_snippet_value' => $request['meta_max_snippet_value'] ?? 0,
            'max_video_preview' => $request['meta_max_video_preview'] ?? 0,
            'max_video_preview_value' => $request['meta_max_video_preview_value'] ?? 0,
            'max_image_preview' => $request['meta_max_image_preview'] ?? 0,
            'max_image_preview_value' => $request['meta_max_image_preview_value'] ?? 'small',
            'image' => $metaImage ?? ($seoData ? $seoData['image'] : null),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public static function formatMetaData(array $input, $oldMeta = [])
    {
        $meta = $oldMeta ?? [];
        $meta['meta_index'] = ($input['meta_index'] ?? 1);
        $meta['meta_no_follow'] = $input['meta_no_follow'] ?? null;
        $meta['meta_no_image_index'] = $input['meta_no_image_index'] ?? null;
        $meta['meta_no_archive'] = $input['meta_no_archive'] ?? null;
        $meta['meta_no_snippet'] = $input['meta_no_snippet'] ?? null;
        $meta['meta_max_snippet'] = (int) ($input['meta_max_snippet'] ?? 0);
        $meta['meta_max_snippet_value'] = isset($input['meta_max_snippet_value']) ? (int) $input['meta_max_snippet_value'] : null;
        $meta['meta_max_video_preview'] = (int) ($input['meta_max_video_preview'] ?? 0);
        $meta['meta_max_video_preview_value'] = isset($input['meta_max_video_preview_value']) ? (int) $input['meta_max_video_preview_value'] : null;
        $meta['meta_max_image_preview'] = (int) ($input['meta_max_image_preview'] ?? 0);
        $meta['meta_max_image_preview_value'] = $input['meta_max_image_preview_value'] ?? null;

        return $meta;
    }

    public static function seoPageList()
    {
        return [
            'vendor_list',
            'category_list',
            'campaign',
            'cuisine_list',
            'home_page',
            'contact_us_page',
            'about_us_page',
            'restaurant_join_page',
            'deliveryman_join_page',
            'terms_and_conditions_page',
            'privacy_policy_page',
            'refund_policy_page',
            'cancellation_policy_page',
            'shipping_policy_page',
            'popular_foods',
            'new_restaurants',
            'most_reviewed_foods'
        ];
    }

    public static function getZoneIds($request)
    {

        if (! $request->hasHeader('zoneId') || empty($request->header('zoneId'))) {
            $zoneId = Zone::where('status', 1)->where('is_default', 1)->first()?->id ?? Zone::first()?->id ?? 1;
            $request->headers->set('zoneId', json_encode([$zoneId]));
        }

        return true;
    }

    public static function getCoordinatesZone($lat, $lng)
    {
        return Zone::whereContains('coordinates', new Point($lat, $lng, POINT_SRID))->where('status', 1)->first();
    }

    public static function bulkAddOrUpdateBusinessSettings($settings)
    {
        foreach ($settings as $key => $value) {
            $data = BusinessSetting::where('key', $key)->first();
            if (! $data) {
                BusinessSetting::create([
                    'key' => $key,
                    'value' => $value,
                ]);
            } else {
                $data->value = $value;
                $data->save();
            }
        }
    }

    public static function dineInOrderTokenUpdatePushNotification($order, $tableNumber = null, $tokenNumber = null)
    {
        // $tableNumber &&  $tableNumber ?  translate('Table No -') .' '.$tableNumber  .' '. translate('&_Token No -') .' '.$tokenNumber : ($tableNumber  ? translate('Table No -') .' '.$tableNumber  :  translate('Token No -') .' '.$tokenNumber );
        $fcm_token = ($order->is_guest == 0 ? $order?->customer?->cm_firebase_token : $order?->guest?->fcm_token) ?? null;
        $message = Helpers::getOrderPushNotificationMessage($order, 'customer_dine_in_table_or_token', 'user', $order->customer ? $order?->customer?->current_language_key : 'en');

        if ($message && isset($fcm_token)) {
            $title = $tableNumber && $tokenNumber ? translate('Here_is_your_Table_and_Token_Number') : ($tableNumber ? translate('Here_is_your_Table_Number') : translate('Here_is_your_Token_Number'));
            $data = Helpers::makeDataForPushNotification(title: $title, message: $message, orderId: $order->id, type: 'order_status', orderStatus: $order->order_status);
            Helpers::send_push_notif_to_device($fcm_token, $data);
            Helpers::insertDataOnNotificationTable($data, 'user', $order->user_id);
        }

        return true;
    }

    public static function import_food_data($collections, $action = 'import', $restaurant = null)
    {
        $data = [];
        $totalNewFoods = 0;
        $restaurantId = $restaurant ? $restaurant->id : null;

        foreach ($collections as $index => $collection) {
            $row = $index + 2;

            // Basic Validation
            if (
                ! isset($collection['Id']) ||
                empty(trim($collection['Name'])) ||
                ! isset($collection['CategoryId']) ||
                ! isset($collection['Price']) ||
                empty($collection['AvailableTimeStarts']) ||
                empty($collection['AvailableTimeEnds']) ||
                ! isset($collection['Discount'])
            ) {
                throw new \Exception(translate('messages.please_fill_all_required_fields')." (Row: {$row})");
            }

            // Restaurant ID Validation (for Admin)
            if (! $restaurant && ! isset($collection['RestaurantId'])) {
                throw new \Exception(translate('messages.please_fill_all_required_fields')." (Row: {$row})");
            }

            $id = $collection['Id'];
            $price = (float) $collection['Price'];
            $discount = (float) ($collection['Discount'] ?? 0);
            $currentRestaurantId = $restaurant ? $restaurantId : $collection['RestaurantId'];

            // Value Validations
            if ($price <= 0) {
                throw new \Exception(translate('messages.Price_must_be_greater_then_0_on_id')." {$id} (Row: {$row})");
            }
            if ($discount < 0) {
                throw new \Exception(translate('messages.Discount_must_be_greater_then_0_on_id')." {$id} (Row: {$row})");
            }
            if (! empty($collection['Image']) && strlen($collection['Image']) > 30) {
                throw new \Exception(translate('messages.Image_name_must_be_in_30_char_on_id')." {$id} (Row: {$row})");
            }

            try {
                $t1 = Carbon::parse($collection['AvailableTimeStarts']);
                $t2 = Carbon::parse($collection['AvailableTimeEnds']);
                if ($t1->gt($t2)) {
                    throw new \Exception(translate('messages.AvailableTimeEnds_must_be_greater_then_AvailableTimeStarts_on_id')." {$id} (Row: {$row})");
                }
            } catch (\Exception $e) {
                throw new \Exception(translate('messages.Invalid_AvailableTimeEnds_or_AvailableTimeStarts_on_id')." {$id} (Row: {$row})");
            }

            // Category Setup
            $category = [['id' => $collection['CategoryId'], 'position' => 1]];
            if (! empty($collection['SubCategoryId'])) {
                $category[] = ['id' => $collection['SubCategoryId'], 'position' => 2];
            }

            $foodData = [
                'name' => $collection['Name'],
                'description' => $collection['Description'] ?? null,
                'image' => $collection['Image'] ?? null,
                'category_id' => $collection['SubCategoryId'] ?: $collection['CategoryId'],
                'category_ids' => json_encode($category),
                'restaurant_id' => $currentRestaurantId,
                'price' => $price,
                'discount' => $discount,
                'discount_type' => $collection['DiscountType'] ?? 'percent',
                'available_time_starts' => $collection['AvailableTimeStarts'],
                'available_time_ends' => $collection['AvailableTimeEnds'],
                'add_ons' => ! empty($collection['Addons']) ? $collection['Addons'] : json_encode([]),
                'veg' => strtolower($collection['Veg'] ?? '') === 'yes' ? 1 : 0,
                'recommended' => strtolower($collection['Recommended'] ?? '') === 'yes' ? 1 : 0,
                'status' => strtolower($collection['Status'] ?? '') === 'active' ? 1 : 0,
            ];

            if ($action === 'import') {
                $foodData['created_at'] = now();
                $foodData['updated_at'] = now();
            } else {
                $foodData['updated_at'] = now();
            }

            $existingFood = Food::withoutGlobalScope(\App\Scopes\RestaurantScope::class)->find($id);

            if ($action === 'import' && $existingFood) {
                throw new \Exception("Food ID {$id} already exists.");
            }

            if ($action === 'update' && ! $existingFood) {
                throw new \Exception("Food ID {$id} not found for update. (Row: {$row})");
            }

            // DB Operations
            if ($existingFood) {
                $existingFood->update($foodData);
                $foodId = $existingFood->id;
            } else {
                $foodId = DB::table('food')->insertGetId($foodData);
                $totalNewFoods++;
            }

            // Image Update
            if (! empty($collection['Image'])) {
                self::updateStorageTable(Food::class, $foodId, $collection['Image']);
            }

            // Variations Logic
            if (! empty($collection['Variations'])) {
                $variationsJson = is_string($collection['Variations'])
                    ? $collection['Variations']
                    : json_encode($collection['Variations']);
                $variations = json_decode($variationsJson, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($variations)) {
                    DB::table('variations')->where('food_id', $foodId)->delete();
                    DB::table('variation_options')->where('food_id', $foodId)->delete();

                    foreach ($variations as $option) {
                        if (empty($option['name']) || empty($option['values'])) {
                            continue;
                        }

                        $variation = new \App\Models\Variation;
                        $variation->food_id = $foodId;
                        $variation->name = $option['name'];
                        $variation->type = $option['type'] ?? 'single';
                        $variation->min = $option['min'] ?? 0;
                        $variation->max = $option['max'] ?? 0;
                        $variation->is_required = ! empty($option['required']);
                        $variation->save();

                        foreach ($option['values'] as $value) {
                            if (empty($value['label'])) {
                                continue;
                            }

                            $variationOption = new \App\Models\VariationOption;
                            $variationOption->food_id = $foodId;
                            $variationOption->variation_id = $variation->id;
                            $variationOption->option_name = $value['label'];
                            $variationOption->option_price = $value['optionPrice'] ?? 0;
                            $variationOption->stock_type = $collection['StockType'] ?? 'unlimited';
                            $variationOption->total_stock = ($variationOption->stock_type === 'unlimited') ? 0 : ($value['total_stock'] ?? 0);
                            $variationOption->save();
                        }
                    }
                }
            }

            $data[] = $foodId;
        }

        // Subscription Logic (Vendor only)
        if ($action === 'import' && $restaurant && $restaurant->restaurant_model == 'subscription') {
            $restSub = $restaurant->restaurant_sub;
            if ($restSub && $restSub->max_product !== 'unlimited') {
                $currentFoods = Food::where('restaurant_id', $restaurantId)->count();
                if ($currentFoods > $restSub->max_product) {
                    throw new \Exception(translate('messages.you_have_reached_the_maximum_limit_of_food'));
                }

                $restSub->decrement('max_product', $totalNewFoods);
                if ($restSub->max_product <= 0) {
                    $restaurant->update(['food_section' => 0]);
                }
            }
        }

        return $data;
    }

    public static function validateFile($image)
    {
        if (! $image instanceof UploadedFile) {
            throw new InvalidUploadException('Invalid file upload.');
        }

        if ($image->getSize() > MAX_FILE_SIZE * 1024 * 1024) {
            throw new InvalidUploadException('File size exceeds the limit of '.MAX_FILE_SIZE.'MB');
        }

        $allowedExtensions = explode(',', IMAGE_EXTENSION.','.VIDEO_EXTENSION.','.DOCUMENT_EXTENSION.','.AUDIO_EXTENSION.','.FILE_EXTENSION);
        $allowedExtensions = array_map(function ($ext) {
            return str_replace('.', '', trim($ext));
        }, $allowedExtensions);

        $extension = strtolower($image->getClientOriginalExtension());
        if(!$extension || $extension == '') {
            $extension= self::extensionFromMimeType($image->getMimeType());
        }

        if (! in_array($extension, $allowedExtensions)) {
            throw new InvalidUploadException('File type not allowed.');
        }

        // 哪吒安全加固: 扩展名白名单之外再做内容嗅探(finfo MIME, 非客户端声明),
        // 挡住伪装成合法扩展名的 HTML/SVG/PHP/JS 文件(防存储型XSS/避免内容嗅探被滥用).
        $detectedMime = strtolower((string) $image->getMimeType());
        $dangerousMimes = ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/x-php', 'text/x-php', 'application/x-httpd-php', 'application/javascript', 'text/javascript', 'application/x-javascript'];
        if (in_array($detectedMime, $dangerousMimes, true)) {
            throw new InvalidUploadException('File type not allowed.');
        }
        // 图片扩展名的文件其真实内容必须是图片(挡住 shell.png 这类非图片伪装).
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($extension, $imageExts, true) && strncmp($detectedMime, 'image/', 6) !== 0) {
            throw new InvalidUploadException('File type not allowed.');
        }
    }

    public static function extensionFromMimeType(string $mimeType): string
    {
        $mimeType = strtolower($mimeType);

        $map = [
        // images
        'image/jpeg' => 'jpg',   // jpeg / jpg
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',

        // video
        'video/mp4'  => 'mp4',
        'video/webm' => 'webm',
        'video/ogg'  => 'ogg',

        // audio
        'audio/mpeg' => 'mp3',
        'audio/wav'  => 'wav',
        'audio/ogg'  => 'ogg',

        // documents
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'excel',

        // archive / misc
        'application/zip' => 'zip',
        'application/octet-stream' => 'p8',
        ];

        if (isset($map[$mimeType])) {
            return $map[$mimeType];
        }
        return explode('/', $mimeType)[1] ?? '';
    }

    // public static function getDeliveryManTopics($delivery_man): array
    // {
    //     $zone = $delivery_man->zone;

    //     if (!$zone) {
    //         return [
    //             $delivery_man->type === 'restaurant_wise' ? "restaurant_dm_{$delivery_man->restaurant_id}" : 'No_topic_found'
    //         ];
    //     }

    //     if (!$delivery_man->earning) {
    //         return ["delivery_man_{$zone->id}"];
    //     }

    //     if (!$delivery_man->vehicle_id) {
    //         return [
    //             $delivery_man->type === 'zone_wise' ? $zone->deliveryman_wise_topic : "restaurant_dm_{$delivery_man->restaurant_id}"
    //         ];
    //     }

    //     $shifts = $delivery_man->shifts()
    //         ->orderBy('is_full_day')
    //         ->get()
    //         ->groupBy('is_full_day');

    //     $selectedShifts = $shifts[0] ?? $shifts[1] ?? collect();

    //     if ($selectedShifts->isNotEmpty()) {
    //         return $selectedShifts->map(fn ($shift) =>
    //             "delivery_man_{$zone->id}_{$delivery_man->vehicle_id}_{$shift->id}"
    //         )->toArray();
    //     }

    //     return ["delivery_man_{$zone->id}_{$delivery_man->vehicle_id}"];
    // }

    public static function getDeliveryManTopics($delivery_man): array
    {
        $topics = [];

        $zone = $delivery_man->zone;

        if (!$zone) {
            $topics[] = $delivery_man->type === 'restaurant_wise'
                ? "restaurant_dm_{$delivery_man->restaurant_id}"
                : 'No_topic_found';

            return $topics;
        }

        $zoneId = $zone->id;
        $vehicleId = $delivery_man->vehicle_id;
        $restaurantId = $delivery_man->restaurant_id;

        if (!$delivery_man->earning) {
            $topics[] = "delivery_man_{$zoneId}";
            $topics[] = "zone_{$zoneId}_delivery_man";
            return $topics;
        }

        if (!$vehicleId) {
            $topics[] = $delivery_man->type === 'zone_wise'
                ? $zone->deliveryman_wise_topic
                : "restaurant_dm_{$restaurantId}";

            return $topics;
        }

        $shifts = $delivery_man->shifts()
            ->orderBy('is_full_day')
            ->get()
            ->groupBy('is_full_day');

        $selectedShifts = $shifts[0] ?? $shifts[1] ?? collect();

        if ($selectedShifts->isNotEmpty()) {
            foreach ($selectedShifts as $shift) {
                $topics[] = "delivery_man_{$zoneId}_{$vehicleId}_{$shift->id}";
                $topics[] = "zone_{$zoneId}_delivery_man";
            }

            return $topics;
        }

        $topics[] = "delivery_man_{$zoneId}_{$vehicleId}";
        $topics[] = "zone_{$zoneId}_delivery_man";

        return $topics;
    }


    public static function generateHumanReadableId($model, $column = 'transaction_id')
    {
        $id_val = $model->id ?? rand(1000, 9999);
        $randomLength = 10 - strlen($id_val);
        $random = Str::upper(Str::random($randomLength));
        $id = $id_val . $random;

        if ($model->where($column, $id)->exists()) {
            return self::generateHumanReadableId($model, $column);
        }
        return $id;
    }
    public static function getDecimalPlaces()
    {
        $decimalPlaces = (int) config('round_up_to_digit');
         return number_format(pow(10, -$decimalPlaces), $decimalPlaces, '.', '');

    }


    public static function clearUnUsedBusinessSettings()
    {
          $keys = ['app_activation','react_setup'];

           return BusinessSetting::whereIn('key',$keys)->delete();

    }

}
