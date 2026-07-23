<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAccountDeletionNotice extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'recipient_ciphertext',
        'owner_token',
    ];

    protected $casts = [
        'purge_completed_at' => 'datetime',
        'send_due_at' => 'datetime',
        'legal_due_at' => 'datetime',
        'claimed_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'sent_at' => 'datetime',
        'recipient_cleared_at' => 'datetime',
        'attempt_count' => 'integer',
    ];
}
