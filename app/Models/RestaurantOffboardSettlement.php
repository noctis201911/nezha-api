<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 哪吒 商家退出结算工单 (DESIGN_merchant_offboard §A5)。
 *
 * 一 vendor 同时至多一条 active(active_uniq=1); 关闭态(withdrawn/rejected/failed/paid)置 active_uniq=NULL
 * → 5.7 NULL 相异使 uq_active(vendor_id, active_uniq) 成"部分唯一", 已关闭多条可并存(可重申)。
 * 应用层写入须 catch 唯一冲突当幂等、勿 500。
 *
 * 留存: 结算记录属资金/合规留存(L1-8⑤), ≥5 年、免 PII 清除, 无 purge 任务触及本表。
 */
class RestaurantOffboardSettlement extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'active_uniq'          => 'integer',
        'applied_at'           => 'datetime',
        'cooldown_until'       => 'datetime',
        'sanction_rescreen_at' => 'datetime',
        'approved_at'          => 'datetime',
        'kyc_gate_passed'      => 'boolean',
        'holder_verified'      => 'boolean',
        'leg_deposit_paid'     => 'boolean',
        'leg_ad_paid'          => 'boolean',
        'leg_guarantee_paid'   => 'boolean',
        'guarantee_amt'        => 'float',
        'deposit_amt'          => 'float',
        'ad_amt'               => 'float',
        'net_amount'           => 'float',
        'shortfall_amount'     => 'float',
        'frozen_reversal_owed' => 'float',
        'pending_clawback'     => 'float',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
