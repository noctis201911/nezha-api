<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAccountDeletionState extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'challenge_hash',
        'challenge_auth_context',
        'execution_owner_token',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'last_blocker_cleared_at' => 'datetime',
        'countdown_started_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'sessions_revoke_requested_at' => 'datetime',
        'sessions_revoked_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'execution_started_at' => 'datetime',
        'account_closed_at' => 'datetime',
        'purge_completed_at' => 'datetime',
        'legal_hold_expires_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'challenge_expires_at' => 'datetime',
        'blocker_mask' => 'integer',
        'attempt_count' => 'integer',
        'obligation_epoch' => 'integer',
        'obligation_epoch_at_claim' => 'integer',
        'state_version' => 'integer',
    ];
}
