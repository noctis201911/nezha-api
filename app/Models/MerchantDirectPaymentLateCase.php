<?php

namespace App\Models;

/**
 * V2 late-payment projection over the existing refund aggregate table.
 *
 * Funds keeps the ordinary-refund model and writer. This projection only adds
 * late-domain casts and relations without changing ordinary refund queries.
 */
final class MerchantDirectPaymentLateCase extends NezhaRefundRecord
{
    public const SOURCE = 'direct_payment_late_v2';

    protected $table = 'nezha_refund_records';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->mergeCasts([
            'state_version' => 'integer',
            'credential_id' => 'integer',
            'method_id' => 'integer',
            'token_decimals' => 'integer',
            'late_payment_event_index' => 'integer',
            'late_refund_event_index' => 'integer',
            'late_payment_tx_hash' => 'encrypted',
            'late_refund_destination' => 'encrypted',
            'late_refund_tx_hash' => 'encrypted',
            'reported_at' => 'datetime',
            'payment_attributed_at' => 'datetime',
            'refund_submitted_at' => 'datetime',
            'closed_at' => 'datetime',
        ]);
    }

    public function events()
    {
        return $this->hasMany(NezhaRefundRecordEvent::class, 'refund_record_id');
    }
}
