<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * 本地生活商家（后台录入）。前端商家列表/商家店铺页以本表为准。
 * 营业状态按商家所在地埃里温(Asia/Yerevan)时区判断（与全站时间口径一致）。
 */
class LocalLifeMerchant extends Model
{
    protected $fillable = [
        'name', 'category', 'logo', 'images', 'wechat_qr',
        'rating', 'google_rating', 'google_rating_url',
        'area', 'address', 'latitude', 'longitude',
        'open_days', 'open_time', 'close_time', 'hours_note',
        'intro', 'services', 'has_offer', 'offer_text',
        'is_sensitive', 'sort_order', 'status',
    ];

    protected $casts = [
        'images'        => 'array',
        'open_days'     => 'array',
        'services'      => 'array',
        'rating'        => 'float',
        'google_rating' => 'float',
        'latitude'      => 'float',
        'longitude'     => 'float',
        'has_offer'     => 'boolean',
        'is_sensitive'  => 'boolean',
        'status'        => 'boolean',
        'sort_order'    => 'integer',
    ];

    /** 当前是否营业中（按埃里温时区；无营业时间数据时返回 null=未知） */
    public function isOpenNow(): ?bool
    {
        if (empty($this->open_time) || empty($this->close_time)) {
            return null;
        }
        $now = Carbon::now('Asia/Yerevan');
        $dow = (int) $now->dayOfWeek; // 0=周日..6=周六
        $days = is_array($this->open_days) ? $this->open_days : [];
        if (!empty($days) && !in_array($dow, array_map('intval', $days), true)) {
            return false;
        }
        $cur   = $now->format('H:i');
        $open  = $this->open_time;
        $close = $this->close_time;
        if ($close > $open) {
            return $cur >= $open && $cur < $close;     // 当日时段
        }
        // 跨夜（如 20:00-02:00）
        return $cur >= $open || $cur < $close;
    }

    /** 今日营业时间文字（店铺页「营业中 周三：09:00-18:00」用） */
    public function todayHoursLabel(): string
    {
        if (empty($this->open_time) || empty($this->close_time)) {
            return $this->hours_note ?: '营业时间以商家为准';
        }
        $names = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
        $dow   = (int) Carbon::now('Asia/Yerevan')->dayOfWeek;
        return $names[$dow] . '：' . $this->open_time . '-' . $this->close_time;
    }
}
