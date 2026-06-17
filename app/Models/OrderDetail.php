<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Traits\ReportFilter;
class OrderDetail extends Model
{
    use HasFactory,ReportFilter;

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

    // 与 Order 模型保持一致：把时间戳序列化成裸的埃里温墙钟（"Y-m-d H:i:s"，无 Z）。
    // 默认序列化会转成 UTC（埃里温 14:23 → 10:23Z），前端 moment 再按浏览器时区换算，
    // 与列表/时间线(裸串)不一致，导致追踪页头部下单时间偏移 4 小时（2026-06-17 修）。
    protected function serializeDate(\DateTimeInterface $date)
    {
        return \Illuminate\Support\Carbon::instance($date)->timezone('Asia/Yerevan')->format('Y-m-d H:i:s');
    }

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
