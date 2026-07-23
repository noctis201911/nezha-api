<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NezhaCustomerRefundAddressCredential extends Model
{
    protected $guarded = ['id'];

    protected $hidden = [
        'secret_hash',
        'address_snapshot',
        'control_challenge_hash',
        'control_evidence',
        'payment_tx_hash',
        'payment_from_address',
        'revoked_reason',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'restaurant_id' => 'integer',
        'method_id' => 'integer',
        'consumed_order_id' => 'integer',
        'asset_decimals' => 'integer',
        'address_snapshot' => 'encrypted',
        'control_evidence' => 'encrypted',
        'payment_tx_hash' => 'encrypted',
        'payment_from_address' => 'encrypted',
        'revoked_reason' => 'encrypted',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'control_verified_at' => 'datetime',
        'revoked_at' => 'datetime',
        'redacted_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'consumed_order_id');
    }
}
