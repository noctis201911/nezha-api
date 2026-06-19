<?php

namespace App\Models;

use App\CentralLogics\Helpers;
use App\Models\Vendor;
use App\Scopes\ZoneScope;
use App\Traits\MasksSensitiveAttributes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use App\Traits\ReportFilter;
use Illuminate\Support\Facades\Config;

class Restaurant extends Model
{
    use HasFactory, ReportFilter, MasksSensitiveAttributes;
    protected $fillable = ['food_section','status'];

    protected $with = ['restaurant_config','translations','storage'];

    protected $casts = [
        'minimum_order' => 'float',
        'comission' => 'float',
        'tax' => 'float',
        'delivery_charge' => 'float',
        'schedule_order'=>'boolean',
        'free_delivery'=>'boolean',
        'vendor_id'=>'integer',
        'status'=>'integer',
        'delivery'=>'boolean',
        'take_away'=>'boolean',
        'zone_id'=>'integer',
        'food_section'=>'boolean',
        'reviews_section'=>'boolean',
        'active'=>'boolean',
        'gst_status'=>'boolean',
        'free_delivery_distance_status'=>'boolean',
        'pos_system'=>'boolean',
        'self_delivery_system'=>'integer',
        'open'=>'integer',
        'gst_code'=>'string',
        'free_delivery_distance_value'=>'float',
        'off_day'=>'string',
        'gst'=>'string',
        'free_delivery_distance'=>'string',
        'veg'=>'integer',
        'non_veg'=>'integer',
        'announcement'=>'integer',
        'minimum_shipping_charge'=>'float',
        'per_km_shipping_charge'=>'float',
        'maximum_shipping_charge'=>'float',
        'cuisine_id'=>'integer',
        // 'order_subscription'=>'boolean',
        'order_subscription_active'=>'boolean',
        'opening_time'=>'datetime',
        'closeing_time'=>'datetime',
        'cutlery'=>'boolean',
        'foods_count'=>'integer',
        'reviews_comments_count'=>'integer',
        'package_id'=>'integer',
        'distance' => 'float',
        'meta_data' => 'array',
    ];

    protected $appends = ['gst_status','gst_code','free_delivery_distance_status','free_delivery_distance_value',
        'logo_full_url','cover_photo_full_url','meta_image_full_url','tin_certificate_image_full_url','additional_documents_full_url',
        'rmb_qr_image_full_url'];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'gst','free_delivery_distance'
    ];

    public function getLogoFullUrlAttribute(){
        $value = $this->logo;
        if (count($this->storage) > 0) {
            foreach ($this->storage as $storage) {
                if ($storage['key'] == 'logo') {
                    return Helpers::get_full_url('restaurant',$value,$storage['value']);
                }
            }
        }

        return Helpers::get_full_url('restaurant',$value,'public');
    }
    // 哪吒外卖 B方案: 商家本人人民币收款码图片完整URL (供后台 + 结算页前端显示)
    public function getRmbQrImageFullUrlAttribute(){
        $value = $this->rmb_qr_image;
        if (!$value) {
            return null;
        }
        if (count($this->storage) > 0) {
            foreach ($this->storage as $storage) {
                if ($storage['key'] == 'rmb_qr_image') {
                    return Helpers::get_full_url('restaurant/payment_qr',$value,$storage['value']);
                }
            }
        }

        return Helpers::get_full_url('restaurant/payment_qr',$value,'public');
    }
    public function getCoverPhotoFullUrlAttribute(){
        $value = $this->cover_photo;
        if (count($this->storage) > 0) {
            foreach ($this->storage as $storage) {
                if ($storage['key'] == 'cover_photo') {
                    return Helpers::get_full_url('restaurant/cover',$value,$storage['value']);
                }
            }
        }

        return Helpers::get_full_url('restaurant/cover',$value,'public');
    }
    public function getMetaImageFullUrlAttribute(){
        $value = $this->meta_image;
        if (count($this->storage) > 0) {
            foreach ($this->storage as $storage) {
                if ($storage['key'] == 'meta_image') {
                    return Helpers::get_full_url('restaurant',$value,$storage['value']);
                }
            }
        }

        return Helpers::get_full_url('restaurant',$value,'public');
    }
    public function getTinCertificateImageFullUrlAttribute(){
        $value = $this->tin_certificate_image;
        if (count($this->storage) > 0) {
            foreach ($this->storage as $storage) {
                if ($storage['key'] == 'tin_certificate_image') {
                    return Helpers::get_full_url('restaurant',$value,$storage['value']);
                }
            }
        }

        return Helpers::get_full_url('restaurant',$value,'public');
    }

    public function getAdditionalDocumentsFullUrlAttribute()
    {
        $images = [];

        $value = is_array($this->additional_documents)
            ? $this->additional_documents
            : ($this->additional_documents && is_string($this->additional_documents) && $this->isValidJson($this->additional_documents)
                ? json_decode($this->additional_documents, true)
                : []);

        if ($value) {
            foreach ($value as $group => $items) {
                if (is_array($items)) {
                    foreach ($items as $item) {
                        $file = $item['file'] ?? null;
                        $storage = $item['storage'] ?? 'public';

                        if ($file) {
                            $images[] = Helpers::get_full_url('additional_documents', $file, $storage);
                        }
                    }
                }
            }
        }

        return $images;
    }

    private function isValidJson($string)
    {
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }


    public function wallet()
    {
        return $this->hasOne(RestaurantWallet::class,'vendor_id','vendor_id');
    }


    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }


    public function characteristics()
    {
        return $this->belongsToMany(Characteristic::class);
    }

    public function restaurant_config()
    {
        return $this->hasOne(RestaurantConfig::class);
    }


    public function translations()
    {
        return $this->morphMany(Translation::class, 'translationable');
    }
    public function transaction()
    {
        return $this->hasMany(OrderTransaction::class,'vendor_id','vendor_id');
    }
    public function coupon()
    {
        return $this->hasMany(Coupon::class,'restaurant_id');
    }
    public function notification_setup()
    {
        return $this->hasMany(RestaurantNotificationSetting::class,'restaurant_id');
    }
    public function coupon_valid()
    {
        return $this->hasMany(Coupon::class,'restaurant_id')->where('status',1)->whereDate('expire_date', '>=', date('Y-m-d'))->whereDate('start_date', '<=', date('Y-m-d'));
    }

    public function restaurant_sub()
    {
        return $this->hasOne(RestaurantSubscription::class)->where('status',1)->latestOfMany();
    }

    public function restaurant_subs()
    {
        return $this->hasMany(RestaurantSubscription::class,'restaurant_id');
    }
    public function restaurant_sub_trans()
    {
        return $this->hasOne(SubscriptionTransaction::class)->latest();
    }
    public function restaurant_sub_update_application()
    {
        return $this->hasOne(RestaurantSubscription::class)->latestOfMany();
    }
    public function restaurant_all_sub_trans()
    {
        return $this->hasMany(SubscriptionTransaction::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function foods()
    {
        return $this->hasMany(Food::class);
    }
    public function foods_for_reorder()
    {
        return $this->foods()->where('status',1)->orderby('avg_rating','desc')->orderby('recommended','desc');
    }

    public function schedules()
    {
        return $this->hasMany(RestaurantSchedule::class)->orderBy('opening_time');
    }

    public function deliverymen()
    {
        return $this->hasMany(DeliveryMan::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function wishlists()
    {
        return $this->hasMany(Wishlist::class,'restaurant_id');
    }

    public function discount()
    {
        return $this->hasOne(Discount::class);
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }

    public function campaigns()
    {
        return $this->belongsToMany(Campaign::class);
    }

    public function itemCampaigns()
    {
        return $this->hasMany(ItemCampaign::class);
    }

    public function reviews()
    {
        return $this->hasManyThrough(Review::class, Food::class);
    }
    public function reviews_comments()
    {
        return $this->reviews()->whereNotNull('comment');
    }

    public function getScheduleOrderAttribute($value)
    {
        return (boolean)(\App\CentralLogics\Helpers::schedule_order()?$value:0);
    }
    public function getRatingAttribute($value)
    {
        $ratings = $value ? json_decode($value, true) : [];
        $rating5 = $ratings?$ratings[5]:0;
        $rating4 = $ratings?$ratings[4]:0;
        $rating3 = $ratings?$ratings[3]:0;
        $rating2 = $ratings?$ratings[2]:0;
        $rating1 = $ratings?$ratings[1]:0;
        return [$rating5, $rating4, $rating3, $rating2, $rating1];
    }

    public function getGstStatusAttribute()
    {
        return (boolean)($this->gst?json_decode($this->gst, true)['status']:0);
    }

    public function getGstCodeAttribute()
    {
        return (string)($this->gst?json_decode($this->gst, true)['code']:'');
    }

    public function getFreeDeliveryDistanceStatusAttribute()
    {
        return (boolean)($this->free_delivery_distance?json_decode($this->free_delivery_distance, true)['status']:0);
    }

    public function getFreeDeliveryDistanceValueAttribute()
    {
        return (string)($this->free_delivery_distance?json_decode($this->free_delivery_distance, true)['value']:'');
    }

    public function scopeDelivery($query)
    {
        $query->where('delivery',1);
    }

    public function scopeTakeaway($query)
    {
        $query->where('take_away',1);
    }

    // public function scopeActive($query)
    // {
    //     if(!\App\CentralLogics\Helpers::commission_check()){
    //         $query = $query->where('restaurant_model','!=','commission');
    //     }
    //     return $query->where('status', 1);
    // }
    public function scopeActive($query): mixed
    {
        $query =  $query->where('status', 1)
        ->where(function($query) {
            $query->where('restaurant_model', 'commission')
                    ->orWhereHas('restaurant_sub', function($query) {
                        $query->where(function($query) {
                            $query->where('max_order', 'unlimited')->orWhere('max_order', '>', 0);
                        });
                    });
            });
        return $query;
    }


    public function getSubSelfDeliveryAttribute(): mixed
    {
        if( $this->restaurant_model == 'subscription' && isset($this->restaurant_sub)){
            return (int)   $this->restaurant_sub?->self_delivery ;
            unset($this->restaurant_sub);
        }
        return $this->self_delivery_system;
    }
    public function getChatPermissionAttribute(): mixed
    {
        if( $this->restaurant_model == 'subscription' && isset($this->restaurant_sub)){
            return (int)   $this->restaurant_sub->chat ;
            unset($this->restaurant_sub);
        }
        return 0;
    }
    public function getReviewPermissionAttribute(): mixed
    {
        if( $this->restaurant_model == 'subscription' && isset($this->restaurant_sub)){
            return (int)   $this->restaurant_sub->review ;
            unset($this->restaurant_sub);
        }
        return $this->reviews_section;
    }
    public function getIsValidSubscriptionAttribute(): mixed
    {
        if( $this->restaurant_model == 'subscription' && isset($this->restaurant_sub)){
            return (int)   1 ;
            unset($this->restaurant_sub);
        }
        return 0;
    }




    public function scopeOpened($query)
    {
        return $query->where('active', 1);
    }

    public function scopeWithOpen($query,$longitude,$latitude)
    {
        $longitude = trim($longitude, "\"'");
        $latitude  = trim($latitude, "\"'");

        $longitude = (float)$longitude;
        $latitude  = (float)$latitude;
        $query->selectRaw('*, IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = '.now()->dayOfWeek.' and `restaurant_schedule`.`opening_time` < "'.now()->format('H:i:s').'" and `restaurant_schedule`.`closing_time` >"'.now()->format('H:i:s').'") > 0), true, false) as open,ST_Distance_Sphere(point(longitude, latitude),point('.$longitude.', '.$latitude.')) as distance');
    }

    public function scopeWeekday($query)
    {
        return $query->where('off_day', 'not like', "%".now()->dayOfWeek."%");
    }


    public function scopeType($query, $type)
    {
        if($type == 'veg')
        {
            return $query->where('veg', true);
        }
        else if($type == 'non_veg')
        {
            return $query->where('non_veg', true);
        }
        else if($type == 'home_delivery')
        {
            return $query->where('delivery', true);
        }
        else if($type == 'take_away')
        {
            return $query->where('take_away', true);
        }
        else if($type == 'dine_in')
        {
            return $query->whereHas('restaurant_config', function ($query) {
                $query->where('dine_in',true);
            });
        }

        return $query;

    }
    public function scopeRestaurantModel($query, $type)
    {
        if($type == 'commission')
        {
            return $query->where('restaurant_model', 'commission');
        }
        else if($type == 'subscribed')
        {
            return $query->where('restaurant_model', 'subscription');
        }
        else if($type == 'unsubscribed')
        {
            return $query->where('restaurant_model', 'unsubscribed');
        }
        else if($type == 'none')
        {
            return $query->where('restaurant_model', 'none');
        }
        return $query;
    }

    public function scopeCuisine($query, $cuisine)
    {
        if ($cuisine == 'all') {
            return $query;
        }

        return $query->whereHas('cuisine', function ($query) use ($cuisine) {

            if (is_array($cuisine)) {
                $query->whereIn('cuisine_restaurant.cuisine_id', $cuisine)
                    ->orWhereIn('cuisines.slug', $cuisine);
            } else {
                $query->where(function ($q) use ($cuisine) {
                    $q->where('cuisine_restaurant.cuisine_id', $cuisine)
                    ->orWhere('cuisines.slug', $cuisine);
                });
            }

        });
    }

    public function scopeMultiCuisine($query, $cuisine_id)
    {
        // 容忍数组或JSON字符串: 调用方可能直接传数组(空数组时勿对其json_decode, 否则PHP8 TypeError致500)
        if (is_string($cuisine_id)) {
            $cuisine_id = json_decode($cuisine_id);
        }
        if(is_array($cuisine_id) && count($cuisine_id)>0){
            return $query->whereHas('cuisine', function ($query) use ($cuisine_id){
                $query->whereIn('cuisine_restaurant.cuisine_id', $cuisine_id)->orWhereIn('cuisines.slug', $cuisine_id);
            });
        }
            return $query;
    }

    public function storage()
    {
        return $this->morphMany(Storage::class, 'data');
    }

    protected static function boot()
    {
        parent::boot();
        static::created(function ($restaurant) {
            $restaurant->slug = $restaurant->generateSlug($restaurant->name);
            $restaurant->save();
        });

        static::saved(function ($model) {
            Helpers::deleteCacheData('banners_');
            Helpers::deleteCacheData('advertisements_');
            if($model->isDirty('logo')){
                $value = Helpers::getDisk();

                DB::table('storages')->updateOrInsert([
                    'data_type' => get_class($model),
                    'data_id' => $model->id,
                    'key' => 'logo',
                ], [
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            if($model->isDirty('cover_photo')){
                $value = Helpers::getDisk();

                DB::table('storages')->updateOrInsert([
                    'data_type' => get_class($model),
                    'data_id' => $model->id,
                    'key' => 'cover_photo',
                ], [
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            if($model->isDirty('meta_image')){
                $value = Helpers::getDisk();

                DB::table('storages')->updateOrInsert([
                    'data_type' => get_class($model),
                    'data_id' => $model->id,
                    'key' => 'meta_image',
                ], [
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

    }
    private function generateSlug($name)
    {
        $slug = Str::slug($name);
        if ($max_slug = static::where('slug', 'like',"{$slug}%")->latest('id')->value('slug')) {

            if($max_slug == $slug) return "{$slug}-2";

            $max_slug = explode('-',$max_slug);
            $count = array_pop($max_slug);
            if (isset($count) && is_numeric($count)) {
                $max_slug[]= ++$count;
                return implode('-', $max_slug);
            }
        }
        return $slug;
    }

    public function cuisine()
    {
        return $this->belongsToMany(Cuisine::class);
    }
    public function disbursement_method()
    {
        return $this->hasOne(DisbursementWithdrawalMethod::class)->where('is_default',1);
    }
    public function users()
    {
        return $this->morphToMany(User::class ,'visitor_log');
    }

    public function schedule_today()
    {
        return $this->hasMany(RestaurantSchedule::class)->orderBy('opening_time')->where('day',now()->dayOfWeek);
    }


    public function getNameAttribute($value){
        if (count($this->translations) > 0) {
            foreach ($this->translations as $translation) {
                if ($translation['key'] == 'name') {
                    return $translation['value'];
                }
            }
        }

        return $value;
    }
    public function getAddressAttribute($value){
        if (count($this->translations) > 0) {
            foreach ($this->translations as $translation) {
                if ($translation['key'] == 'address') {
                    return $translation['value'];
                }
            }
        }

        return $value;
    }



    protected static function booted()
    {
        // static::addGlobalScope('storage', function ($builder) {
        //     $builder->with('storage');
        // });
        static::addGlobalScope(new ZoneScope);
        static::addGlobalScope('translate', function (Builder $builder) {
            $builder->with(['translations' => function($query){
                return $query->where('locale', app()->getLocale());
            }]);
        });

        static::retrieved(function () {
            $current_date = date('Y-m-d');
            // N+1 fix: 原每取一个餐厅都 uncached 查一次本 setting(还带 storage+translations), 列表页逐餐厅爆.
            // 用请求级容器缓存(php-fpm 每请求新建容器=请求级, 不跨请求泄漏)收敛为每请求一次;
            // DB 仍是跨请求真相源, 每日清理逻辑不变(命中后更新缓存使本请求后续不再重复跑).
            $__nz_svk = 'nz_subval_check';
            if (app()->bound($__nz_svk)) {
                $check_daily_subscription_validity_check = app($__nz_svk);
            } else {
                $check_daily_subscription_validity_check = BusinessSetting::where('key','check_daily_subscription_validity_check')->first()?->value;
                if(!$check_daily_subscription_validity_check){
                    Helpers::insert_business_settings_key('check_daily_subscription_validity_check', $current_date);
                    $check_daily_subscription_validity_check= $current_date;
                }
                app()->instance($__nz_svk, $check_daily_subscription_validity_check);
            }

            if($check_daily_subscription_validity_check != $current_date){
                $restaurantIds = RestaurantSubscription::where('status', 1)
                    ->whereDate('expiry_date', '<=', $current_date)
                    ->pluck('restaurant_id');

                Restaurant::whereIn('id', $restaurantIds)
                    ->update([
                        'status' => 0,
                        'pos_system' => 1,
                        'self_delivery_system' => 1,
                        'reviews_section' => 1,
                        'free_delivery' => 0,
                        'restaurant_model' => 'unsubscribed',
                    ]);
                RestaurantSubscription::where('status',1)->whereDate('expiry_date', '<=', $current_date)->update([
                    'status' => 0
                ]);

                Helpers::businessUpdateOrInsert(['key' => 'check_daily_subscription_validity_check'], [
                    'value' => $current_date,
                ]);
                app()->instance($__nz_svk, $current_date);

            }
        });


    }

    public function scopeApplyFilters($query, array $filters)
    {
        return $query
            ->when(isset($filters['cuisine_id']) && is_array($filters['cuisine_id']) && count($filters['cuisine_id']) > 0, function($q) use($filters){
                $q->whereHas('cuisine', function($query) use($filters){
                    $query->whereIn('cuisine_restaurant.cuisine_id', $filters['cuisine_id'])->orWhereIn('cuisines.slug', $filters['cuisine_id']);
                });
            })
            ->when(isset($filters['order_type']), function($q) use($filters){
                $orderTypes = (array)$filters['order_type'];
                if(in_array('delivery', $orderTypes)){
                    $q->where('delivery', 1);
                }elseif(in_array('take_away', $orderTypes)){
                    $q->where('take_away', 1);
                }elseif(in_array('dine_in', $orderTypes)){
                    $q->whereHas('restaurant_config', function($query){
                        $query->where('dine_in', 1);
                    });
                }
            })
            ->when(isset($filters['filter_by']) && is_array($filters['filter_by']), function ($q) use ($filters) {

                foreach ($filters['filter_by'] as $item) {

                    if ($item == 'free_delivery') {
                        $q->where('free_delivery', 1);

                    } elseif ($item == 'discounted') {
                        $q->whereHas('discount', function ($query) {
                            return $query->validate();
                        });

                    } elseif ($item == 'popular') {
                        $hasOrdersCount = false;
                        $existingColumns = $q->getQuery()->columns ?? [];
                        $grammar = $q->getQuery()->getGrammar();
                        foreach ($existingColumns as $col) {
                            $colStr = $col instanceof \Illuminate\Contracts\Database\Query\Expression
                                ? $col->getValue($grammar)
                                : (string) $col;
                            if (str_contains($colStr, 'orders_count')) {
                                $hasOrdersCount = true;
                                break;
                            }
                        }
                        if (!$hasOrdersCount) {
                            $q->withCount('orders');
                        }
                        $q->orderBy('orders_count', 'desc');

                    } elseif ($item == 'new_arrivals') {
                        $q->latest();
                    } elseif ($item == 'top_rated') {
                        $q->selectSub(function ($query) {
                            $query->selectRaw('AVG(reviews.rating)')
                                ->from('reviews')
                                ->join('food', 'food.id', '=', 'reviews.food_id')
                                ->whereColumn('food.restaurant_id', 'restaurants.id')
                                ->groupBy('food.restaurant_id');
                        }, 'avg_rating_all')->orderBy('avg_rating_all', 'desc');
                    } elseif ($item == 'veg') {
                        $q->where('veg', 1);
                    } elseif ($item == 'non_veg') {
                        $q->where('non_veg', 1);
                    } elseif ($item == 'currently_available') {
                        $q->whereHas('schedules', function($query){
                            $query->where('day', now()->dayOfWeek)
                                ->where('opening_time', '<', now()->format('H:i:s'))
                                ->where('closing_time', '>', now()->format('H:i:s'));
                        });
                    } elseif ($item == 'halal') {
                        $q->whereHas('foods', function($query){
                            $query->where('is_halal', 1);
                        });
                    }
                }
            });
    }

    public function scopeApplySorting($query, $sortBy)
    {
        return $query->when($sortBy && $sortBy !== 'default', function ($q) use ($sortBy) {

            if ($sortBy == 'fast_delivery') {
                $q->reorder()->orderBy(function($query){
                    $query->selectRaw('IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = '.now()->dayOfWeek.' and `restaurant_schedule`.`opening_time` < "'.now()->format('H:i:s').'" and `restaurant_schedule`.`closing_time` >"'.now()->format('H:i:s').'") > 0), true, false)')
                    ->from('restaurants')->whereColumn('restaurants.id', 'restaurants.id')->limit(1);
                }, 'desc')
                ->orderByRaw("
                    CASE
                        WHEN delivery_time LIKE '%hour%'
                            THEN CAST(SUBSTRING_INDEX(delivery_time,'-',1) AS UNSIGNED) * 60
                        WHEN delivery_time LIKE '%min%'
                            THEN CAST(SUBSTRING_INDEX(delivery_time,'-',1) AS UNSIGNED)
                        ELSE CAST(SUBSTRING_INDEX(delivery_time,'-',1) AS UNSIGNED)
                    END ASC
                ");

            } elseif ($sortBy == 'a_to_z') {
                $q->reorder()->orderBy('name', 'asc');

            } elseif ($sortBy == 'z_to_a') {
                $q->reorder()->orderBy('name', 'desc');
            } elseif ($sortBy == 'distance') {
                $longitude = request()?->header('longitude') ?? request('longitude');
                $latitude = request()?->header('latitude') ?? request('latitude');

                if ($longitude !== null && $latitude !== null && $longitude !== '' && $latitude !== '') {
                    $longitude = trim($longitude, "\"'");
                    $latitude  = trim($latitude, "\"'");

                    $longitude = (float)$longitude;
                    $latitude  = (float)$latitude;

                    $q->reorder()
                        ->addSelect(DB::raw("ST_Distance_Sphere(point(longitude, latitude), point($longitude, $latitude)) as distance"))
                        ->orderBy('distance', 'asc');
                }

            } elseif ($sortBy == 'high_rated') {
                $q->selectSub(function ($query) {
                    $query->selectRaw('AVG(reviews.rating)')
                        ->from('reviews')
                        ->join('food', 'food.id', '=', 'reviews.food_id')
                        ->whereColumn('food.restaurant_id', 'restaurants.id')
                        ->groupBy('food.restaurant_id');
                }, 'avg_rating_all')->reorder()->orderBy('avg_rating_all', 'desc');
            }
        });
    }

    public function scopeApplyRating($query, $request)
    {
        if (!$request) {
        return $query;
    }
        return $query->when($request->rating == 1, function($query){
            return $query->withCount('reviews')->orderBy('reviews_count','desc');
        })
        ->when(($request->rating_1 == 1 || $request->rating_1_plus == 1 || $request->rating_2 == 1 || $request->rating_2_plus == 1 || $request->rating_3 == 1 || $request->rating_3_plus == 1 || $request->rating_4 == 1 || $request->rating_4_plus == 1 || $request->rating_5 == 1), function($query) use($request){
            $query->selectSub(function ($query) {
                $query->selectRaw('AVG(reviews.rating)')
                    ->from('reviews')
                    ->join('food', 'food.id', '=', 'reviews.food_id')
                    ->whereColumn('food.restaurant_id', 'restaurants.id')
                    ->groupBy('food.restaurant_id');
            }, 'avg_rating_all')
            
            ->when(($request->rating_1 == 1 || $request->rating_1_plus == 1), function($query){
                $query->having('avg_rating_all', '>=' , 1);
            })
            ->when(($request->rating_2 == 1 || $request->rating_2_plus == 1), function($query){
                $query->having('avg_rating_all', '>=' , 2);
            })
            ->when(($request->rating_3 == 1 || $request->rating_3_plus == 1), function($query){
                $query->having('avg_rating_all', '>=' , 3);
            })
            ->when(($request->rating_4 == 1 || $request->rating_4_plus == 1), function($query){
                $query->having('avg_rating_all', '>=' , 4);
            })
            ->when($request->rating_3_plus == 1, function($query){
                $query->having('avg_rating_all', '>' , 3);
            })
            ->when(($request->rating_4_plus == 1 && !($request->rating_5  == 1 || $request->rating_3_plus == 1 ) || ($request->rating_4_plus == 1 && $request->rating_5  == 1 && $request->rating_3_plus != 1) ), function($query){
                $query->having('avg_rating_all', '>' , 4);
            })
            ->when($request->rating_5 == 1 && !($request->rating_4_plus  == 1 || $request->rating_3_plus == 1) , function($query){
                $query->having('avg_rating_all', '>=' , 5);
            });
        });
    }

    public function scopeApplyPriceRange($query, $request)
    {
        if (!$request) {
        return $query;
    }
        $price = $request->price ?? null;
        if (is_string($price)) {
            $price = str_replace(['[', ']'], '', $price);
            $price = explode(',', $price);
        }

        return $query->when(($price && count($price) == 2 && is_numeric($price[0]) && is_numeric($price[1])) || $request->min_price || $request->max_price, function ($query) use ($price, $request) {
            $query->whereHas('foods', function($q) use($price, $request){
                $q->applyPriceRange($request);
            });
        });
    }

}
