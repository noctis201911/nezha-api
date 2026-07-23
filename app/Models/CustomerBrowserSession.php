<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBrowserSession extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'token_hash',
        'csrf_token_encrypted',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'idle_expires_at' => 'datetime',
        'absolute_expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
