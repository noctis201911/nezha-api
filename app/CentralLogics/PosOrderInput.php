<?php

namespace App\CentralLogics;

use App\Models\AddOn;
use App\Scopes\RestaurantScope;
use App\Scopes\ZoneScope;

final class PosOrderInput
{
    public static function validateCart(iterable $cart): ?string
    {
        foreach ($cart as $line) {
            if (! is_array($line)) {
                continue;
            }

            if (self::positiveInteger($line['quantity'] ?? null) === null) {
                return 'quantity';
            }

            $addonIds = $line['add_ons'] ?? [];
            $addonQuantities = $line['add_on_qtys'] ?? [];
            if (! is_array($addonIds) || ! is_array($addonQuantities) || count($addonIds) !== count($addonQuantities)) {
                return 'addon_quantity';
            }

            $normalizedAddonIds = [];
            foreach ($addonIds as $index => $addonId) {
                $normalizedAddonId = self::positiveInteger($addonId, PHP_INT_MAX);
                if ($normalizedAddonId === null
                    || self::positiveInteger($addonQuantities[$index] ?? null) === null) {
                    return 'addon_quantity';
                }
                $normalizedAddonIds[] = $normalizedAddonId;
            }

            if (count(array_unique($normalizedAddonIds)) !== count($normalizedAddonIds)) {
                return 'addon_quantity';
            }
        }

        return null;
    }

    public static function resolveAddons(int $restaurantId, array $addonIds, array $addonQuantities): ?array
    {
        if (count($addonIds) !== count($addonQuantities)) {
            return null;
        }

        $quantitiesById = [];
        foreach ($addonIds as $index => $addonId) {
            $normalizedAddonId = self::positiveInteger($addonId, PHP_INT_MAX);
            $normalizedQuantity = self::positiveInteger($addonQuantities[$index] ?? null);
            if ($normalizedAddonId === null || $normalizedQuantity === null || isset($quantitiesById[$normalizedAddonId])) {
                return null;
            }
            $quantitiesById[$normalizedAddonId] = $normalizedQuantity;
        }

        $addons = AddOn::withoutGlobalScopes([RestaurantScope::class, ZoneScope::class])
            ->active()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('id', array_keys($quantitiesById))
            ->get();

        if ($addons->count() !== count($quantitiesById)) {
            return null;
        }

        $normalizedQuantities = $addons
            ->map(fn (AddOn $addon): int => $quantitiesById[$addon->id])
            ->all();

        return Helpers::calculate_addon_price($addons, $normalizedQuantities);
    }

    public static function positiveInteger($value, int $maximum = 99): ?int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value)) {
            $integer = (int) $value;
        } else {
            return null;
        }

        return $integer >= 1 && $integer <= $maximum ? $integer : null;
    }
}
