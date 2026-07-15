<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\DB;

final class NezhaBackofficeEmailBoundary
{
    private const TABLES = [
        'admins',
        'vendors',
        'vendor_employees',
    ];

    public static function normalize($email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    public static function conflicts($email, ?string $ignoreTable = null, ?int $ignoreId = null): bool
    {
        $normalizedEmail = self::normalize($email);
        if ($normalizedEmail === '') {
            return false;
        }

        foreach (self::TABLES as $table) {
            $query = DB::table($table)->whereRaw('LOWER(email) = ?', [$normalizedEmail]);

            if ($table === $ignoreTable && $ignoreId !== null) {
                $query->where('id', '!=', $ignoreId);
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }
}
