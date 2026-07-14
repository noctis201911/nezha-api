<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NezhaPaymentAddressCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'secret_hash',
        'user_id',
        'restaurant_id',
        'method_id',
        'network',
        'address_snapshot',
        'address_fingerprint',
        'issued_at',
        'expires_at',
        'consumed_at',
        'consumed_order_id',
        'submitted_tx_hash',
        'revoked_at',
        'revoked_reason',
        'redacted_at',
    ];

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
        'redacted_at' => 'datetime',
    ];
}
