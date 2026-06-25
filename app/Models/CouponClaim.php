<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// 哪吒[券包 2026-06-25 Slice2]: 顾客领取到券包的拥有记录。不碰资金。
class CouponClaim extends Model
{
    protected $table = 'coupon_claims';

    protected $fillable = ['user_id', 'coupon_id', 'claimed_at', 'used_at'];

    protected $casts = [
        'user_id'    => 'integer',
        'coupon_id'  => 'integer',
        'claimed_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    public function coupon()
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
