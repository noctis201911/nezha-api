<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Traits\ReportFilter;
use App\Traits\SerializesLocalDates;
class OrderDetail extends Model
{
    use HasFactory,ReportFilter,SerializesLocalDates;

    protected $casts = [
        'price' => 'float',
        'discount_on_food' => 'float',
        'total_add_on_price' => 'float',
        'tax_amount' => 'float',
        'food_id'=> 'integer',
        'order_id'=> 'integer',
        'quantity'=>'integer',
        'item_campaign_id'=>'integer',
        'quantity' => 'integer',

    ];

    protected $primaryKey   = 'id';

    // 时间戳序列化为裸埃里温墙钟见 SerializesLocalDates trait（修追踪页头部时间偏移）。

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
    public function vendor()
    {
        return $this->order->restaurant();
    }
    public function food()
    {
        return $this->belongsTo(Food::class,'food_id');
    }
    public function campaign()
    {
        return $this->belongsTo(ItemCampaign::class, 'item_campaign_id');
    }
    protected static function boot(){
        parent::boot();
        static::addGlobalScope('order', function (Builder $builder) {
            $builder->Has('order');
                });
    }
}
