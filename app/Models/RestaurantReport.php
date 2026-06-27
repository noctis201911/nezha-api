<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 顾客举报商家(餐厅)记录。
 * status: 0待处理 1已处理 2驳回
 * L1-7: description 可能含 PII(随表静态加密, 到期由 nezha:purge-restaurant-reports 置空)。
 */
class RestaurantReport extends Model
{
    public const STATUS_PENDING  = 0; // 待处理
    public const STATUS_HANDLED  = 1; // 已处理
    public const STATUS_REJECTED = 2; // 驳回

    protected $fillable = [
        'restaurant_id',
        'vendor_id',
        'user_id',
        'guest_id',
        'reason',
        'description',
        'status',
    ];

    public function statusLabel(): string
    {
        return [
            self::STATUS_PENDING  => '待处理',
            self::STATUS_HANDLED  => '已处理',
            self::STATUS_REJECTED => '已驳回',
        ][$this->status] ?? '待处理';
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id');
    }
}
