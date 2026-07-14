<?php

namespace App\CentralLogics;

use App\Models\NezhaPaymentAddressChange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only queue shared by the reviewer page and its sidebar badge.
 */
final class NezhaPaymentAddressReviewQueue
{
    public const LIMIT = 100;

    public static function query(): Builder
    {
        return NezhaPaymentAddressChange::query()
            ->where('state', 'pending_distinct_admin')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit(self::LIMIT);
    }

    public static function get(): Collection
    {
        return self::query()
            ->with(['restaurant:id,name', 'requestedByAdmin:id,f_name,l_name,email'])
            ->get();
    }

    public static function count(): int
    {
        try {
            return (int) DB::query()
                ->fromSub(self::query()->select('id'), 'payment_address_review_queue')
                ->count();
        } catch (\Throwable) {
            // The candidate may be deployed only after its separately-authorized
            // migrations. Until then, a missing table must not break every admin page.
            return 0;
        }
    }
}
