<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocalLifePost extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'category',
        'tab',
        'description',
        'cover_emoji',
        'cover_color',
        'price_amd',
        'price_suffix',
        'is_free',
        'area_label',
        'location_label',
        'is_urgent',
        'want_count',
        'contact_info',
        'expires_at',
        'status',
        'source',
    ];

    protected $casts = [
        'is_free'    => 'boolean',
        'is_urgent'  => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function statusLabel()
    {
        return ['草稿', '已发布', '已下线'][$this->status] ?? '草稿';
    }
}
