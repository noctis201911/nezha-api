<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalIdentityLoginAttempt extends Model
{
    protected $fillable = [
        'provider',
        'state_hash',
        'exchange_code_hash',
        'browser_secret_hash',
        'oidc_nonce',
        'code_verifier',
        'provider_subject',
        'provider_payload',
        'target_user_id',
        'status',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'state_hash',
        'exchange_code_hash',
        'browser_secret_hash',
        'oidc_nonce',
        'code_verifier',
        'provider_subject',
        'provider_payload',
    ];

    protected $casts = [
        'code_verifier' => 'encrypted',
        'provider_payload' => 'encrypted:array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
