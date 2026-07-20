<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only evidence attached to the existing refund aggregate. */
class NezhaRefundRecordEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'public_id',
        'refund_record_id',
        'sequence',
        'event_type',
        'state_from',
        'state_to',
        'actor_type',
        'actor_id',
        'evidence_authority',
        'payload',
        'payload_hash',
        'recorded_at',
    ];

    protected $hidden = ['payload'];

    protected $casts = [
        'refund_record_id' => 'integer',
        'sequence' => 'integer',
        'actor_id' => 'integer',
        'payload' => 'encrypted:array',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \LogicException('refund_record_events_are_append_only');
        });
        static::deleting(static function (): void {
            throw new \LogicException('refund_record_events_are_append_only');
        });
    }

    public function refundRecord()
    {
        return $this->belongsTo(MerchantDirectPaymentLateCase::class, 'refund_record_id');
    }
}
