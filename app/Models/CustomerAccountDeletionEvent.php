<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAccountDeletionEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'state_id' => 'integer',
        'state_version' => 'integer',
    ];
}
