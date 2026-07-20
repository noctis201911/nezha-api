<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 外卖挂牌店「联系意图」埋点事件。append-only 聚合事实，零主体标识。
 *
 * 🔴 表内不含 user_id/IP/UA/设备/platform —— 非个人数据，无 PII 清除义务。
 * question 为快捷提问 key（hours/order/delivery/recommend）；wechat/phone 恒 NULL。
 * 与本地生活的 LocalLifeContactEvent 是两张独立表，刻意不合并（主键空间不同，合并会污染其看板）。
 */
class NezhaRestaurantContactEvent extends Model
{
    protected $table = 'nezha_restaurant_contact_events';

    // append-only 事件流：只有 created_at，无 updated_at
    public const UPDATED_AT = null;

    protected $fillable = [
        'restaurant_id',
        'channel',
        'question',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id');
    }
}
