<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\DB;

final class VendorDeviceTokenSessions
{
    public const SESSION_KEY = 'nezha_alarm_token_hash';

    public static function remember(string $tokenHash): void
    {
        if (self::isValidHash($tokenHash)) {
            session()->put(self::SESSION_KEY, $tokenHash);
        }
    }

    public static function forgetIfMatches(string $tokenHash): void
    {
        if (hash_equals((string) session()->get(self::SESSION_KEY, ''), $tokenHash)) {
            session()->forget(self::SESSION_KEY);
        }
    }

    public static function deactivateCurrent(): int
    {
        $tokenHash = (string) session()->pull(self::SESSION_KEY, '');
        if (! self::isValidHash($tokenHash)) {
            return 0;
        }

        $vendorId = Helpers::get_restaurant_data()?->vendor_id;
        if (! $vendorId) {
            return 0;
        }

        return DB::table('vendor_device_tokens')
            ->where('vendor_id', $vendorId)
            ->where('token_hash', $tokenHash)
            ->where('is_active', 1)
            ->update([
                'is_active' => 0,
                'updated_at' => now(),
            ]);
    }

    private static function isValidHash(string $tokenHash): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $tokenHash) === 1;
    }
}
