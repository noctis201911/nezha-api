<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\SerializesLocalDates;

class OfflinePayments extends Model
{
    use HasFactory, SerializesLocalDates;
    protected $casts = [
        'order_id'=>'integer',
        'nezha_auto_check'=>'array',
    ];
    protected $guarded = ['id'];
}
