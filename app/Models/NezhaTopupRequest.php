<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 哪吒 预存佣金/广告/押金 自助充值申请 (A3).
 * 见 migration 2026_07_03_120000_create_nezha_topup_requests.
 * account_type: deposit/guarantee/ad ; direction: topup/refund(押金退口dormant).
 * original_ref 加密留痕(押金腿回执号·L1-4), 对齐 RestaurantDepositTransaction.
 */
class NezhaTopupRequest extends Model
{
    protected $fillable = [
        'vendor_id', 'restaurant_id', 'account_type', 'direction',
        'amount_claimed', 'amount_credited', 'currency',
        'original_amount', 'original_ref', 'proof_path', 'note',
        'status', 'reason', 'operator_id', 'transaction_id', 'reviewed_at',
        // S3-B 中途退押金列
        'sanction_rescreen_at', 'holder_verified', 'approved_at',
        'scheduled_pay_at', 'guarantee_snapshot', 'payout_ref', 'active_refund_uniq',
        'kyc_apply_fp',
    ];

    protected $casts = [
        'amount_claimed'  => 'float',
        'amount_credited' => 'float',
        'original_amount' => 'float',
        'original_ref'    => 'encrypted',
        'reviewed_at'     => 'datetime',
        'holder_verified'      => 'boolean',
        'guarantee_snapshot'   => 'float',
        'sanction_rescreen_at' => 'datetime',
        'approved_at'          => 'datetime',
        'scheduled_pay_at'     => 'datetime',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id', 'id');
    }
}