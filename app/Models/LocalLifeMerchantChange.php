<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 本地生活商户「待审变更」快照。全复审：商户任何提交先落此表，超管过审才应用到线上。
 * payload = 商户提议的自改字段（已解析/规范化，字段白名单内）。
 * base_snapshot = 提交时线上值（审核台算 diff / 防并发覆盖）。
 * 驳回不删（留证）。
 */
class LocalLifeMerchantChange extends Model
{
    protected $table = 'local_life_merchant_changes';

    const STATUS_PENDING  = 0;
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 2;

    protected $fillable = [
        'merchant_id', 'account_id', 'payload', 'base_snapshot', 'status',
        'review_note', 'reviewed_by', 'reviewed_at', 'submit_ip', 'submit_ua',
    ];

    protected $casts = [
        'payload'       => 'array',
        'base_snapshot' => 'array',
        'status'        => 'integer',
        'reviewed_at'   => 'datetime',
    ];

    public function merchant()
    {
        return $this->belongsTo(LocalLifeMerchant::class, 'merchant_id');
    }

    public function account()
    {
        return $this->belongsTo(LocalLifeMerchantAccount::class, 'account_id');
    }

    public function isPending(): bool
    {
        return (int) $this->status === self::STATUS_PENDING;
    }
}
