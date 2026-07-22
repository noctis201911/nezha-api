<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAuthConsent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'terms_version',
        'privacy_version',
        'locale',
        'channel',
        'auth_method',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];
}
