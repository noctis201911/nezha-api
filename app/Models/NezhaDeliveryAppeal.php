<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 哪吒外卖 配送申诉留痕（「没有收到餐 / 配送异常」专用，区别于通用退款申请）。
 *
 * B 方案下平台不碰钱：本记录只做留痕 + 通知商家/客服，**不触发任何自动退款**。
 * 真实退款仍走「联系商家原路退回」(见 NezhaRefundRecord / L1-1、L1-2)。
 *
 * status: open(待处理) | merchant_contacted(已联系商家) | resolved(已解决) | rejected(已驳回)
 * 表结构见 migration 2026_06_19_000100_create_nezha_delivery_appeals_table。
 */
class NezhaDeliveryAppeal extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'order_id'    => 'integer',
        'user_id'     => 'integer',
        'evidence'    => 'array',
        'sla_due_at'  => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
