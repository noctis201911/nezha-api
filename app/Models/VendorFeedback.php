<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 商家 → 平台 反馈/求助 (方案B)。
 *  - type: commission(佣金) / settlement(结算) / feature(功能) / other(其他)
 *  - status: open(待处理) / in_progress(处理中) / resolved(已处理)
 *  - admin_note: 平台处理回复, 商家在自己的反馈列表可见(闭环"被听到")。
 */
class VendorFeedback extends Model
{
    protected $table = 'vendor_feedback';

    protected $fillable = [
        'vendor_id', 'restaurant_id', 'type', 'subject', 'description',
        'status', 'admin_note', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public const TYPE_LABELS = [
        'commission' => '佣金',
        'settlement' => '结算',
        'feature' => '功能',
        'other' => '其他',
    ];

    public const STATUS_LABELS = [
        'open' => '待处理',
        'in_progress' => '处理中',
        'resolved' => '已处理',
    ];
}
