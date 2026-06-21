<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LocalLifePost extends Model
{
    use HasFactory;

    // status 值域: 0草稿 1已发布 2已下线 3待审核 4已驳回
    public const STATUS_DRAFT     = 0;
    public const STATUS_PUBLISHED = 1;
    public const STATUS_OFFLINE   = 2;
    public const STATUS_PENDING   = 3; // UGC 待审核
    public const STATUS_REJECTED  = 4; // UGC 已驳回

    protected $fillable = [
        'user_id',
        'title',
        'category',
        'tab',
        'description',
        'cover_emoji',
        'cover_color',
        'images',
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
        'reject_reason',
        'source',
        'legal_hold',
        'legal_hold_reason',
        'legal_hold_at',
    ];

    protected $casts = [
        'is_free'    => 'boolean',
        'is_urgent'  => 'boolean',
        'legal_hold' => 'boolean',
        'images'     => 'array',
        'expires_at' => 'datetime',
    ];

    public function statusLabel()
    {
        return [
            self::STATUS_DRAFT     => '草稿',
            self::STATUS_PUBLISHED => '已发布',
            self::STATUS_OFFLINE   => '已下线',
            self::STATUS_PENDING   => '待审核',
            self::STATUS_REJECTED  => '已驳回',
        ][$this->status] ?? '草稿';
    }
}
