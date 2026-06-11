<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 哪吒外卖 B方案 组4: 保证金流水.
 * 见 migration 2026_06_10_140000_create_restaurant_deposit_transactions.
 */
class RestaurantDepositTransaction extends Model
{
    protected $fillable = [
        'vendor_id', 'restaurant_id', 'order_id', 'type',
        'amount', 'commission', 'balance_after', 'note', 'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'commission' => 'float',
        'balance_after' => 'float',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id', 'id');
    }
}
