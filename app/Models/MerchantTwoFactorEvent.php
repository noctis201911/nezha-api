<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantTwoFactorEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'auth_generation' => 'integer',
        'metadata' => 'array',
    ];
}
