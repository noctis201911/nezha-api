<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 哪吒外卖 退款留痕记录 (合规留存 ≥5年, 免于 PII 自动清除).
 * 表结构见 migration 2026_06_14_130000_create_nezha_refund_records_and_settings.
 *
 * 商家原路退款流 status 生命周期(应用层枚举, 列 VARCHAR(40)):
 *   pending_merchant_refund → merchant_refunded            (商家原路退款收尾)
 *   pending_merchant_refund → disputed → pending_merchant_refund  (发起争议 → 运营维持退款义务, 计时恢复)
 *   pending_merchant_refund → disputed → closed_no_payment (运营核实未收款, 留痕关闭终态·非删除·业主 2026-07-03 批准)
 * (超管超限退款流另有 recorded/pending_admin/approved/rejected, 与商家流互不干扰。)
 *
 * 🔴 新增/变更枚举值时: 只改下面的集合常量, 所有消费查询引用它们(单一真相源, 防漏算/误算)。
 */
class NezhaRefundRecord extends Model
{
    protected $guarded = ['id'];

    /**
     * —— 商家流 status 集合(单一真相源) ——
     * NEEDS_ACTION: 计需动作/徽标/逾期计时/顾客催办。争议中(disputed)不在内=不计需动作、暂停逾期与催办。
     * UNRESOLVED:   未结记录, 用于展示/归组(留在「售后」、排除出「进行中」「已完结」)。含争议中(仍属未结、须可见)。
     * RESOLVED:     已结终态, 落「已完结」。closed_no_payment 与 merchant_refunded 同属已结。
     * MERCHANT_LIFECYCLE: 商家流全生命周期, 生成幂等守卫用(存在其一即不重建, 防重开已关闭/争议记录)。
     */
    const STATUS_NEEDS_ACTION      = ['pending_merchant_refund'];
    const STATUS_UNRESOLVED        = ['pending_merchant_refund', 'disputed'];
    const STATUS_RESOLVED          = ['merchant_refunded', 'closed_no_payment'];
    const STATUS_MERCHANT_LIFECYCLE = ['pending_merchant_refund', 'disputed', 'merchant_refunded', 'closed_no_payment'];

    protected $casts = [
        'order_id'             => 'integer',
        'refund_id'            => 'integer',
        'restaurant_id'        => 'integer',
        'user_id'              => 'integer',
        'order_amount'         => 'float',
        'refund_amount'        => 'float',
        'chain_verify_detail'  => 'array',
        'risk_hit'             => 'array',
        'customer_confirmed'   => 'boolean',
        'customer_confirmed_at'=> 'datetime',
        'reviewed_at'          => 'datetime',
        'overdue_anchor_at'    => 'datetime',
    ];

    public function scopeNeedsAction($q)
    {
        return $q->whereIn('status', self::STATUS_NEEDS_ACTION);
    }

    public function scopeUnresolved($q)
    {
        return $q->whereIn('status', self::STATUS_UNRESOLVED);
    }

    public function scopeResolved($q)
    {
        return $q->whereIn('status', self::STATUS_RESOLVED);
    }

    public function disputes()
    {
        return $this->hasMany(NezhaRefundDispute::class, 'refund_record_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
