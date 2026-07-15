<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerNotificationInstallation extends Model
{
    protected $fillable = [
        'user_id',
        'installation_id',
        'transport',
        'token',
        'token_hash',
        'platform',
        'last_seen_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token',
        'token_hash',
    ];

    protected $casts = [
        'token' => 'encrypted',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
