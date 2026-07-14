<?php

namespace App\CentralLogics;

final class DeliveryOptionLogic
{
    public static function resolve($zone, $requestedType, float $deliveryCharge): array
    {
        $none = ['delivery_type' => null, 'delivery_type_charge' => 0.0];
        $deliveryCharge = max(0, $deliveryCharge);

        if (! $zone || ! (bool) ($zone->additional_delivery_option_status ?? false) || $deliveryCharge <= 0) {
            return $none;
        }

        $minimumCharge = max(0, (float) ($zone->minimum_delivery_charge ?? 0));
        if ($minimumCharge > 0 && $deliveryCharge < $minimumCharge) {
            return $none;
        }

        $option = null;
        foreach (($zone->deliveryOptions ?? []) as $candidate) {
            if (is_numeric($requestedType)) {
                if ((int) ($candidate->id ?? 0) === (int) $requestedType) {
                    $option = $candidate;
                    break;
                }
            } elseif ((string) ($candidate->delivery_type ?? '') === (string) $requestedType) {
                $option = $candidate;
                break;
            }
        }

        $type = (string) ($option->delivery_type ?? '');
        if (! in_array($type, ['standard', 'express', 'slightly_delay'], true)) {
            return $none;
        }

        $charge = match ($type) {
            'express' => max(0, (float) ($option->extra_charge ?? 0)),
            'slightly_delay' => min($deliveryCharge, max(0, (float) ($option->reduce_charge ?? 0))),
            default => 0.0,
        };

        return [
            'delivery_type' => $type,
            'delivery_type_charge' => (float) $charge,
        ];
    }
}
