<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 哪吒风控① 命中记录 (审计日志 + 人工审核队列).
 * action=reject → status=auto (系统自动拒单, 无需人工)
 * action=review → status=pending → approved(放行)/rejected(清退)/cleared(已退款)
 */
class NezhaRiskRecord extends Model
{
    protected $table = 'nezha_risk_records';
    protected $guarded = ['id'];
    protected $casts = [
        'hit_rules'    => 'array',
        'snapshot'     => 'array',
        'order_amount' => 'float',
        'reviewed_at'  => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
