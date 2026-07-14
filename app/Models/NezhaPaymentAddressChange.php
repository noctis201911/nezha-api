<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NezhaPaymentAddressChange extends Model
{
    protected $fillable = [
        'public_id',
        'restaurant_id',
        'network',
        'source_state',
        'old_address',
        'new_address',
        'old_fingerprint',
        'new_fingerprint',
        'expected_version',
        'state',
        'requested_by_admin_id',
        'idempotency_hash',
        'reason',
        'merchant_confirmed_by_vendor_id',
        'merchant_confirmed_at',
        'approved_by_admin_id',
        'approved_at',
        'drain_until',
        'expires_at',
        'applied_at',
        'rejected_at',
        'canceled_at',
        'expired_at',
        'failed_at',
        'failure_code',
    ];

    protected $hidden = [
        'old_address',
        'new_address',
        'reason',
        'idempotency_hash',
    ];

    protected $casts = [
        'restaurant_id' => 'integer',
        'expected_version' => 'integer',
        'requested_by_admin_id' => 'integer',
        'merchant_confirmed_by_vendor_id' => 'integer',
        'approved_by_admin_id' => 'integer',
        'old_address' => 'encrypted',
        'new_address' => 'encrypted',
        'reason' => 'encrypted',
        'merchant_confirmed_at' => 'datetime',
        'approved_at' => 'datetime',
        'drain_until' => 'datetime',
        'expires_at' => 'datetime',
        'applied_at' => 'datetime',
        'rejected_at' => 'datetime',
        'canceled_at' => 'datetime',
        'expired_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
