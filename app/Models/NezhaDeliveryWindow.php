<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 哪吒 预约下单 — 商家配送时段窗口。
 * day: 0-6（0=周日，对齐 restaurant_schedule.day / now()->dayOfWeek）。
 * capacity: null=不限（Phase 2 才启用业务容量）。active: 关=顾客可选列表里立即消失（弱逃生阀）。
 */
class NezhaDeliveryWindow extends Model
{
    protected $table = 'nezha_delivery_windows';

    protected $guarded = ['id'];

    protected $casts = [
        'restaurant_id' => 'integer',
        'day'           => 'integer',
        'capacity'      => 'integer',
        'active'        => 'boolean',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}
