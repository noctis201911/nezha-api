<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 本地生活商家页「笔记」（批N）。图文笔记，商家或客户发布，人工审核后展示。
 * 笔记 ≠ 评价：无星级/无点赞/无好评率（§②-1）。
 */
class LocalLifeMerchantNote extends Model
{
    // status 值域（审核态）: 0待审 1过审 2驳回 3下架
    public const STATUS_PENDING  = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_REJECTED = 2;
    public const STATUS_OFFLINE  = 3;

    public const AUTHOR_MERCHANT = 'merchant';
    public const AUTHOR_CUSTOMER = 'customer';

    protected $fillable = [
        'merchant_id',
        'author_type',
        'user_id',
        'title',
        'body',
        'images',
        'status',
        'reject_reason',
    ];

    protected $casts = [
        'images' => 'array',
    ];

    public function statusLabel(): string
    {
        return [
            self::STATUS_PENDING  => '待审核',
            self::STATUS_APPROVED => '已展示',
            self::STATUS_REJECTED => '已驳回',
            self::STATUS_OFFLINE  => '已下架',
        ][$this->status] ?? '待审核';
    }

    public function merchant()
    {
        return $this->belongsTo(LocalLifeMerchant::class, 'merchant_id');
    }

    /** 客户作者；author_type=merchant 或用户已注销时为 null（回落「用户已注销」） */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
