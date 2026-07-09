<?php

namespace App\CentralLogics;

use App\Models\BusinessSetting;
use Carbon\Carbon;

/**
 * 哪吒[顾客联系方式限时可见 · 0709]
 * 商家端顾客电话/邮箱: 下单后 N 小时内(默认 24)对履约商家完整可见, 便于订单有问题时直接联系顾客;
 * 超过窗口自动回落打码(Helpers::mask_phone / mask_email), 把 PII 暴露限定在有业务需要的时段。
 * 窗口小时数读后台设置 nezha_customer_contact_reveal_hours(缺省 24; <=0 表示始终打码)。
 * 定级 L3 展示策略 —— 非 L1(L1-7 管加密存储+到期删除, 与本展示打码策略无关)。
 */
class NezhaContactVisibility
{
    /** 可见窗口小时数(本请求内 memo; 缺省 24)。 */
    public static function revealHours(): int
    {
        static $h = null;
        if ($h === null) {
            $v = BusinessSetting::where('key', 'nezha_customer_contact_reveal_hours')->value('value');
            $h = is_numeric($v) ? (int) $v : 24;
        }
        return $h;
    }

    /** 该下单时间是否仍在可见窗口内。created_at 缺失/不可解析 → 视为超窗(打码)。 */
    public static function visible($created_at): bool
    {
        $hours = self::revealHours();
        if ($hours <= 0 || empty($created_at)) {
            return false;
        }
        try {
            $t = $created_at instanceof Carbon ? $created_at : Carbon::parse($created_at);
        } catch (\Throwable $e) {
            return false;
        }
        return $t->greaterThan(now()->subHours($hours));
    }

    /** 窗口内返回完整电话, 窗口外返回打码电话。 */
    public static function phone($phone, $created_at): string
    {
        if (empty($phone)) {
            return '';
        }
        return self::visible($created_at) ? (string) $phone : Helpers::mask_phone($phone);
    }

    /** 窗口内返回完整邮箱, 窗口外返回打码邮箱。 */
    public static function email($email, $created_at): string
    {
        if (empty($email)) {
            return '';
        }
        return self::visible($created_at) ? (string) $email : Helpers::mask_email($email);
    }
}