<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantTwoFactorChallenge extends Model
{
    protected $guarded = ['id'];

    protected $hidden = [
        'token_hash',
        'pending_secret',
        'ip_hash',
    ];

    protected $casts = [
        'pending_secret' => 'encrypted',
        'auth_generation' => 'integer',
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
