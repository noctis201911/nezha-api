<?php

namespace App\Models;

use App\CentralLogics\Helpers;
use App\Scopes\ZoneScope;
use Illuminate\Support\Str;
use App\Scopes\RestaurantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\ReportFilter;
use Modules\TaxModule\Entities\Taxable;

class Food extends Model
{
    use HasFactory , ReportFilter;
    protected $with = ['storage','translations'];
    protected $casts = [
        'tax' => 'float',
        'price' => 'float',
        'status' => 'integer',
        'discount' => 'float',
        'avg_rating' => 'float',
        'set_menu' => 'integer',
        'category_id' => 'integer',
        'restaurant_id' => 'integer',
        'reviews_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'veg' => 'integer',
        'min' => 'integer',
        'max' => 'integer',
        'maximum_cart_quantity' => 'integer',
        'recommended' => 'integer',
        'order_count'=>'integer',
        'rating_count'=>'integer',
        'is_halal'=>'integer',
    ];
    protected $guarded = [];

    protected $appends = ['image_full_url'];
    public function getImageFullUrlAttribute(){
        $value = $this->image;
        if (count($this->storage) > 0) {
            foreach ($this->storage as $storage) {
                if ($storage['key'] == 'image') {
                    return Helpers::get_full_url('product',$value,$storage['value']);
                }
            }
        }

        return Helpers::get_full_url('product',$value,'public');
    }

    public function logs()
    {
        return $this->hasMany(Log::class,'model_id')->where('model','Food');
    }
    public function newVariations()
    {
        return $this->hasMany(Variation::class, 'food_id')->with('variationOptions');
    }

    public function newVariationOptions()
    {
        return $this->hasMany(VariationOption::class, 'food_id');
    }
    public function wishlists()
    {
        return $this->hasMany(Wishlist::class,'food_id');
    }

    public function scopeRecommended($query)
    {
        return $query->where('recommended',1);
    }

    public function carts()
    {
        return $this->morphMany(Cart::class, 'item');
    }

    public function translations()
    {
        return $this->morphMany(Translation::class, 'translationable');
    }


    // public function scopeActive($query)
    // {
    //     return $query->where('status', 1)->whereHas('restaurant', function ($query) {
    //         return $query->where('status', 1);
    //     });
    // }



    public function scopeActive($query)
    {
        return $query->where('status', 1)
        ->whereHas('restaurant', function($query) {
            $query->where('status', 1)
                    ->where(function($query) {
                        $query->where('restaurant_model', 'commission')
                                ->orWhereHas('restaurant_sub', function($query) {
                                    $query->where(function($query) {
                                        $query->where('max_order', 'unlimited')->orWhere('max_order', '>', 0);
                                    });
                                });
                    });
            })
        ->whereHas('category', function ($q) {
            $q->where(function ($q) {
                $q->where([
                        ['parent_id', '=', 0],
                        ['status', '=', 1],
                    ])
                ->orWhere(function ($q) {
                    $q->where('parent_id', '!=', 0)
                        ->whereHas('parent', fn ($p) => $p->where('status', 1));
                });
            });
        });
    }



    public function scopeAvailable($query,$time)
    {
        $query->where(function($q)use($time){
            $q->where('available_time_starts','<=',$time)->where('available_time_ends','>=',$time);
        });
    }

    public function scopePopular($query)
    {
        return $query->orderBy('order_count', 'desc');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class)->latest();
    }

    public function rating()
    {
        return $this->hasMany(Review::class, 'food_id')
            ->select(
                'food_id',
                DB::raw('AVG(rating) as average'),
                DB::raw('COUNT(*) as rating_count'),
                DB::raw('COUNT(CASE WHEN comment IS NOT NULL THEN 1 END) as review_count')
            )
            ->groupBy('food_id');
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function orders()
    {
        return $this->hasMany(OrderDetail::class);
    }

    public function storage()
    {
        return $this->morphMany(Storage::class, 'data');
    }

    protected static function booted()
    {
        // dd( app()->getLocale());
        if (auth('vendor')->check() || auth('vendor_employee')->check()) {
            static::addGlobalScope(new RestaurantScope);
        }

        static::addGlobalScope(new ZoneScope);

        // static::addGlobalScope('storage', function ($builder) {
        //     $builder->with('storage');
        // });

        static::addGlobalScope('translate', function (Builder $builder) {
            $builder->with(['translations' => function ($query) {
                return $query->where('locale', app()->getLocale());
            }]);
        });
    }


    public function scopeType($query, $type)
    {
        if ($type == 'veg') {
            return $query->where('veg', true);
        } else if ($type == 'non_veg') {
            return $query->where('veg', false);
        }

        return $query;
    }


    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }

    public function allergies()
    {
        return $this->belongsToMany(Allergy::class);
    }
    public function nutritions()
    {
        return $this->belongsToMany(Nutrition::class);
    }

    protected static function boot()
    {
        parent::boot();
        static::created(function ($food) {
            $food->slug = $food->generateSlug($food->name);
            $food->save();
        });

        static::retrieved(function ($food) {
            try {
                if ($food->stock_type != 'daily') return;

                $ordersToday = $food->orders()
                    ->whereDate('created_at', now())
                    ->exists();

                $hasAnyOrder = $food->relationLoaded('orders')
                    ? $food->orders->isNotEmpty()
                    : $food->orders()->exists();

                if ($hasAnyOrder && !$ordersToday) {
                    $food->sell_count = 0;
                    $food->save();
                    $food->newVariationOptions()?->update(['sell_count' => 0]);
                }
            } catch (\Exception $exception) {
                info([$exception->getFile(), $exception->getLine(), $exception->getMessage()]);
            }
        });
        static::saved(function ($model) {
            if($model->isDirty('image')){
                $value = Helpers::getDisk();

                DB::table('storages')->updateOrInsert([
                    'data_type' => get_class($model),
                    'data_id' => $model->id,
                    'key' => 'image',
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


    public function getNameAttribute($value){
        if (count($this->translations) > 0) {
            // info(count($this->translations));
            foreach ($this->translations as $translation) {
                if ($translation['key'] == 'name') {
                    return $translation['value'];
                }
            }
        }

        return $value;
    }

    public function getDescriptionAttribute($value){
        if (count($this->translations) > 0) {
            foreach ($this->translations as $translation) {
                if ($translation['key'] == 'description') {
                    return $translation['value'];
                }
            }
        }

        return $value;
    }
    public function getItemStockAttribute($value){
        return $value - $this->sell_count > 0 ? $value - $this->sell_count : 0 ;
    }
    public function getVariationsAttribute($value)
    {
        try {
            $this->relationLoaded('newVariationOptions');

            if ((is_null($value) || (is_string($value) && empty($decoded = json_decode($value, true)))) && !empty($this->newVariations) && !empty($this->newVariationOptions)) {
                // if( ( $value == null || (is_string($value) &&  (json_decode($value, true) == null || count(json_decode($value, true)) == 0))) && count($this->newVariations) > 0 && count($this->newVariationOptions) > 0 ){
                    $optionsByVariation = $this->newVariationOptions
                ->groupBy('variation_id')
                ->map(fn($options) => $options->map(fn($o) => [
                    'label'         => $o->option_name,
                    'optionPrice'   => $o->option_price,
                    'total_stock'   => (string) $o->total_stock,
                    'stock_type'    => $o->stock_type,
                    'sell_count'    => (string) $o->sell_count,
                    'option_id'     => (int) $o->id,
                    'current_stock' => $o->stock_type === 'unlimited' ? 0 : max(0, $o->total_stock - $o->sell_count),
                ])->all());

            $result = $this->newVariations->map(fn($v) => [
                'variation_id' => (int) $v->id,
                'name'         => $v->name,
                'type'         => $v->type,
                'min'          => (string) $v->min,
                'max'          => (string) $v->max,
                'required'     => $v->is_required ? 'on' : 'off',
                'values'       => $optionsByVariation->get($v->id, []),
            ])->all();

                    return json_encode($result);
                }
                else{
                    return $value ?? json_encode([]);
                }
            } catch (\Exception $exception) {
                info([$exception->getFile(),$exception->getLine(),$exception->getMessage()]);
                return $value ?? json_encode([]);;
        }
    }




    public function taxVats()
    {
        return $this->morphMany(Taxable::class, 'taxable');
    }
    public function foodSeoData()
    {
        return $this->hasOne(FoodSeoData::class, 'food_id', 'id');
    }

    public function scopeApplyFilters($query, array $filters)
    {
        return $query
            ->when(isset($filters['cuisine_id']) && is_array($filters['cuisine_id']) && count($filters['cuisine_id']) > 0, function($q) use($filters){
                $q->whereHas('restaurant.cuisine', function($query) use($filters){
                    $query->whereIn('cuisine_restaurant.cuisine_id', $filters['cuisine_id'])->orWhereIn('cuisines.slug', $filters['cuisine_id']);
                });
            })
            ->when(isset($filters['order_type']), function($q) use($filters){
                $orderTypes = (array)$filters['order_type'];
                $q->whereHas('restaurant', function($query) use($orderTypes){
                    $query->where(function($q) use($orderTypes){
                        if(in_array('delivery', $orderTypes)){
                            $q->orWhere('delivery', 1);
                        }
                        if(in_array('take_away', $orderTypes)){
                            $q->orWhere('take_away', 1);
                        }
                        if(in_array('dine_in', $orderTypes)){
                            $q->orWhereHas('restaurant_config', function($q){
                                $q->where('dine_in', 1);
                            });
                        }
                    });
                });
            })
            ->when(isset($filters['filter_by']) && is_array($filters['filter_by']), function ($q) use ($filters) {
                foreach ($filters['filter_by'] as $item) {
                    if ($item == 'free_delivery') {
                        $q->whereHas('restaurant', function($query){
                            $query->where('free_delivery', 1);
                        });
                    } elseif ($item == 'discounted') {
                        $q->where('discount', '>', 0);
                    } elseif ($item == 'popular') {
                        $q->orderBy('order_count', 'desc');
                    } elseif ($item == 'new_arrivals') {
                        $q->latest(); 
                    } elseif ($item == 'top_rated') {
                        $q->orderBy('avg_rating', 'desc');
                    } elseif ($item == 'veg') {
                        $q->where('veg', 1);
                    } elseif ($item == 'non_veg') {
                        $q->where('veg', 0);
                    } elseif ($item == 'currently_available') {
                        $q->available(now()->format('H:i:s'));
                    } elseif ($item == 'halal') {
                        $q->where('is_halal', 1);
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
                    ->from('restaurants')->whereColumn('restaurants.id', 'food.restaurant_id')->limit(1);
                }, 'desc')
                ->orderBy(function($query){
                    $query->selectRaw("
                        CASE
                            WHEN delivery_time LIKE '%hour%'
                                THEN CAST(SUBSTRING_INDEX(delivery_time,'-',1) AS UNSIGNED) * 60
                            WHEN delivery_time LIKE '%min%'
                                THEN CAST(SUBSTRING_INDEX(delivery_time,'-',1) AS UNSIGNED)
                            ELSE CAST(SUBSTRING_INDEX(delivery_time,'-',1) AS UNSIGNED)
                        END")
                    ->from('restaurants')->whereColumn('restaurants.id', 'food.restaurant_id')->limit(1);
                }, 'asc');
            } elseif ($sortBy == 'a_to_z') {
                $q->reorder()->orderBy('name', 'asc');
            } elseif ($sortBy == 'z_to_a') {
                $q->reorder()->orderBy('name', 'desc');
            } elseif ($sortBy == 'price_low_to_high') {
                $q->reorder()->orderBy('price', 'asc');
            } elseif ($sortBy == 'price_high_to_low') {
                $q->reorder()->orderBy('price', 'desc');
            } elseif ($sortBy == 'distance') {
                $longitude = request()->header('longitude') ?? request('longitude');
                $latitude = request()->header('latitude') ?? request('latitude');

                if ($longitude && $latitude) {
                    $q->reorder()
                        ->selectSub(function ($query) use ($longitude, $latitude) {
                            $query->selectRaw(
                                'ST_Distance_Sphere(point(longitude, latitude), point(?, ?))',
                                [$longitude, $latitude]
                            )
                            ->from('restaurants')
                            ->whereColumn('restaurants.id', 'food.restaurant_id')
                            ->limit(1);
                        }, 'distance')
                        ->orderBy('distance', 'asc');
                }
            } elseif ($sortBy == 'high_rated') {
                $q->reorder()->orderBy('avg_rating', 'desc');
            }
        });
    }

    public function scopeApplyRating($query, $request)
    {
        if (!$request) {
        return $query;
    }
        return $query->when($request->rating == 1, function($query){
            return $query->has('reviews')->withCount('reviews')->orderBy('reviews_count','desc');
        })
        ->when(($request->rating_1 == 1 || $request->rating_1_plus == 1), function($query){
            $query->where('avg_rating', '>=' , 1);
        })
        ->when(($request->rating_2 == 1 || $request->rating_2_plus == 1), function($query){
            $query->where('avg_rating', '>=' , 2);
        })
        ->when(($request->rating_3 == 1 || $request->rating_3_plus == 1), function($query){
            $query->where('avg_rating', '>=' , 3);
        })
        ->when(($request->rating_4 == 1 || $request->rating_4_plus == 1), function($query){
            $query->where('avg_rating', '>=' , 4);
        })
        ->when($request->rating_3_plus == 1, function($query){
            $query->where('avg_rating', '>' , 3);
        })
        ->when(($request->rating_4_plus == 1 && !($request->rating_5  == 1 || $request->rating_3_plus == 1 ) || ($request->rating_4_plus == 1 && $request->rating_5  == 1 && $request->rating_3_plus != 1) ), function($query){
            $query->where('avg_rating', '>' , 4);
        })
        ->when($request->rating_5 == 1 && !($request->rating_4_plus  == 1 || $request->rating_3_plus == 1) , function($query){
            $query->where('avg_rating', '>=' , 5);
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

        return $query->when($price && count($price) == 2 && is_numeric($price[0]) && is_numeric($price[1]), function ($query) use ($price) {
            $query->whereBetween('price', [round($price[0], 2), round($price[1], 2)]);
        })->when($request->min_price, function ($query) use ($request) {
            $query->where('price', '>=', $request->min_price);
        })->when($request->max_price, function ($query) use ($request) {
            $query->where('price', '<=', $request->max_price);
        });
    }
}
