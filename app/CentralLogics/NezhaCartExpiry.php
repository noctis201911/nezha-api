<?php

namespace App\CentralLogics;

use Carbon\Carbon;

class NezhaCartExpiry
{
    public const TTL_HOURS = 24;

    public static function cutoff(): Carbon
    {
        return now()->subHours(self::TTL_HOURS);
    }

    public static function isExpired($updatedAt): bool
    {
        if (!$updatedAt) {
            return false;
        }

        return Carbon::parse($updatedAt)->lessThanOrEqualTo(self::cutoff());
    }

    public static function expiredQuery($query)
    {
        return $query->where('updated_at', '<=', self::cutoff());
    }
}
