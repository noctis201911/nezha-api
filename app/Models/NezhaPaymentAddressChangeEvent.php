<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only address-change audit event. */
class NezhaPaymentAddressChangeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'change_id',
        'network_state_id',
        'event_type',
        'state_from',
        'state_to',
        'actor_type',
        'actor_id',
        'totp_counter',
        'context',
    ];

    protected $hidden = ['context', 'totp_counter'];

    protected $casts = [
        'change_id' => 'integer',
        'network_state_id' => 'integer',
        'actor_id' => 'integer',
        'totp_counter' => 'integer',
        'context' => 'encrypted:array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \LogicException('payment_address_change_events_are_append_only');
        });
        static::deleting(static function (): void {
            throw new \LogicException('payment_address_change_events_are_append_only');
        });
    }
}
