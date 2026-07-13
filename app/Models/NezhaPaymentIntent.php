<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NezhaPaymentIntent extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'order_id' => 'integer',
        'restaurant_id' => 'integer',
        'snapshot' => 'array',
        'expires_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
