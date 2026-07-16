<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
    const STATUS_CUSTOMER_VISIBLE  = ['pending_merchant_refund', 'disputed', 'merchant_refunded'];

    /**
     * 逾期计时口径(单一真相源): 争议维持裁决(R3)后 overdue_anchor_at=裁决时刻, 逾期从此刻重算;
     * 普通待退款单 overdue_anchor_at=NULL 时回退 created_at(生成时刻)。
     * 🔴 全站"逾期几小时/是否达阈值/是否进催办窗口/排序"必须都走它, 不得直接读 created_at, 否则显示与执行会各算各的。
     *   - SQL(筛选/排序): whereRaw/orderByRaw 用 self::OVERDUE_SINCE_SQL
     *   - PHP(单条时长):  $rec->overdue_since (accessor, 返回 Carbon)
     */
    const OVERDUE_SINCE_SQL = 'COALESCE(overdue_anchor_at, created_at)';

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
        'merchant_refunded_at' => 'datetime',
        'reviewed_at'          => 'datetime',
        'overdue_anchor_at'    => 'datetime',
    ];

    /**
     * 顾客侧退款阶段投影。orders.order_status 继续保留旧终态契约，实际退款阶段以本记录为准。
     */
    public function customerProjection(): array
    {
        return [
            'status'               => $this->status,
            'refund_amount'        => $this->refund_amount,
            'channel'              => $this->payment_channel,
            'refunded'             => $this->status === 'merchant_refunded',
            'customer_confirmed'   => (bool) $this->customer_confirmed,
            'confirmed_at'         => $this->customer_confirmed_at ? (string) $this->customer_confirmed_at : null,
            'merchant_refunded_at' => $this->merchant_refunded_at ? (string) $this->merchant_refunded_at : null,
            'refund_tx_hash'       => $this->refund_tx_hash,
            'locked_to_address'    => $this->locked_to_address,
            'chain_verify_status'  => $this->chain_verify_status,
        ];
    }

    /**
     * 一次查询取得每个订单最新的顾客可见退款阶段，避免列表/消息中心 N+1。
     */
    public static function latestCustomerVisibleByOrderIds($orderIds)
    {
        $ids = collect($orderIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return static::whereIn('order_id', $ids->all())
            ->whereIn('status', self::STATUS_CUSTOMER_VISIBLE)
            ->orderByDesc('id')
            ->get()
            ->unique('order_id')
            ->keyBy('order_id');
    }

    /**
     * 原子地认领「待商家退款 → 商家已标记退款」转换。只有转换赢家可以产生后续通知。
     */
    public static function transitionPendingToMerchantRefunded($id, array $attributes = [], $restaurantId = null): ?self
    {
        return DB::transaction(function () use ($id, $attributes, $restaurantId) {
            $record = static::whereKey($id)
                ->where('status', 'pending_merchant_refund')
                ->when($restaurantId, fn ($query) => $query->where('restaurant_id', $restaurantId))
                ->lockForUpdate()
                ->first();

            if (!$record) {
                return null;
            }

            $record->status = 'merchant_refunded';
            $record->merchant_refunded_at = now();
            foreach (['merchant_refund_note', 'refund_tx_hash', 'chain_verify_status'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $record->{$field} = $attributes[$field];
                }
            }
            $record->save();

            return $record;
        });
    }

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

    /** 逾期计时起点(锚点优先, 回退生成时刻)。见 self::OVERDUE_SINCE_SQL 注释。 */
    public function getOverdueSinceAttribute()
    {
        return $this->overdue_anchor_at ?? $this->created_at;
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
