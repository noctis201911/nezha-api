<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NezhaPaymentNetworkState extends Model
{
    protected $fillable = [
        'restaurant_id',
        'network',
        'state',
        'active_address_fingerprint',
        'active_version',
        'pending_change_id',
        'drain_until',
        'paused_at',
        'paused_by_admin_id',
        'pause_reason',
    ];

    protected $hidden = ['pause_reason'];

    protected $casts = [
        'restaurant_id' => 'integer',
        'active_version' => 'integer',
        'pending_change_id' => 'integer',
        'paused_by_admin_id' => 'integer',
        'pause_reason' => 'encrypted',
        'drain_until' => 'datetime',
        'paused_at' => 'datetime',
    ];
}
