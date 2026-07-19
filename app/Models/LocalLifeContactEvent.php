<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 本地生活「联系意图」埋点事件（A）。append-only 聚合事实，零主体标识。
 *
 * 🔴 表内不含 user_id/IP/UA/设备/platform（业主 0719 拍板·甲）—— 非个人数据，无 PII 清除义务。
 * question 为快捷提问 key（promo/price/hours/booking）；wechat/phone 恒 NULL。
 */
class LocalLifeContactEvent extends Model
{
    // append-only 事件流：只有 created_at，无 updated_at
    public const UPDATED_AT = null;

    protected $fillable = [
        'merchant_id',
        'channel',
        'question',
    ];

    public function merchant()
    {
        return $this->belongsTo(LocalLifeMerchant::class, 'merchant_id');
    }
}
