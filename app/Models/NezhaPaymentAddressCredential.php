<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NezhaPaymentAddressCredential extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = [
        'secret_hash',
        'address_snapshot',
        'submitted_tx_hash',
        'revoked_reason',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'restaurant_id' => 'integer',
        'method_id' => 'integer',
        'consumed_order_id' => 'integer',
        'address_snapshot' => 'encrypted',
        'submitted_tx_hash' => 'encrypted',
        'revoked_reason' => 'encrypted',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
