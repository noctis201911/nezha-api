<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerEmailAuthChallenge extends Model
{
    protected $fillable = [
        'public_id',
        'purpose',
        'email_ciphertext',
        'email_lookup_hash',
        'active_email_hash',
        'otp_hash',
        'browser_secret_hash',
        'completion_token_hash',
        'target_user_id',
        'status',
        'attempts_remaining',
        'generation',
        'registration_payload',
        'expires_at',
        'resend_after',
        'delivery_succeeded_at',
        'verified_at',
        'consumed_at',
    ];

    protected $hidden = [
        'email_ciphertext',
        'email_lookup_hash',
        'active_email_hash',
        'otp_hash',
        'browser_secret_hash',
        'completion_token_hash',
        'registration_payload',
    ];

    protected $casts = [
        'email_ciphertext' => 'encrypted',
        'registration_payload' => 'encrypted:array',
        'expires_at' => 'datetime',
        'resend_after' => 'datetime',
        'delivery_succeeded_at' => 'datetime',
        'verified_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts_remaining' => 'integer',
        'generation' => 'integer',
    ];

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
