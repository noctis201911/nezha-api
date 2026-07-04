<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 哪吒 · 退款留痕记录的争议 + 运营裁决留痕(denied 凭证争议流 R1)。
 * 表 nezha_refund_disputes: refund_record_id UNIQUE(单记录争议上限 1 次)。
 * 审计记录, 留存 ≥5 年、免 PII 自动清除(同 nezha_refund_records / L1-4)。
 */
class NezhaRefundDispute extends Model
{
    protected $table = 'nezha_refund_disputes';

    protected $guarded = ['id'];

    protected $casts = [
        'refund_record_id' => 'integer',
        'order_id'         => 'integer',
        'restaurant_id'    => 'integer',
        'operator_id'      => 'integer',
        'opened_at'        => 'datetime',
        'resolved_at'      => 'datetime',
    ];

    public function record()
    {
        return $this->belongsTo(NezhaRefundRecord::class, 'refund_record_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}
