<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// 哪吒[多级满减] 店铺满减活动(Discount)下的单个门槛档。
// discount_type: amount=满 min_purchase 减 discount(固定额) / percent=减 discount% 封顶 max_discount。
class DiscountTier extends Model
{
    protected $fillable = [
        'discount_id', 'min_purchase', 'discount_type', 'discount', 'max_discount', 'sort',
    ];

    protected $casts = [
        'min_purchase' => 'float',
        'discount' => 'float',
        'max_discount' => 'float',
        'discount_id' => 'integer',
        'sort' => 'integer',
    ];

    public function activity()
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }
}
