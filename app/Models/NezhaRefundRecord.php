<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 哪吒外卖 退款留痕记录 (合规留存 ≥5年, 免于 PII 自动清除).
 * 表结构见 migration 2026_06_14_130000_create_nezha_refund_records_and_settings.
 */
class NezhaRefundRecord extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'order_id'             => 'integer',
        'refund_id'            => 'integer',
        'restaurant_id'        => 'integer',
        'user_id'              => 'integer',
        'order_amount'         => 'float',
        'refund_amount'        => 'float',
        'chain_verify_detail'  => 'array',
        'risk_hit'             => 'array',
        'customer_confirmed'   => 'boolean',
        'customer_confirmed_at'=> 'datetime',
        'reviewed_at'          => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
