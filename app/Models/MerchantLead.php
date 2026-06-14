<?php

namespace App\Models;

use App\Traits\MasksSensitiveAttributes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MerchantLead extends Model
{
    use HasFactory, MasksSensitiveAttributes;

    protected $guarded = ['id'];

    protected $casts = [
        'status' => 'integer',
        'seen' => 'boolean',
    ];

    public function statusLabel()
    {
        return [
            0 => '待跟进',
            1 => '跟进中',
            2 => '已完成',
            3 => '无效',
        ][$this->status] ?? '待跟进';
    }
}
