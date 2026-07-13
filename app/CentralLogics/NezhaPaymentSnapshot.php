<?php

namespace App\CentralLogics;

use App\Models\NezhaPaymentIntent;
use App\Models\OfflinePaymentMethod;
use App\Models\Order;
use App\Models\Restaurant;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * B 方案付款事实快照：顾客直付商家，平台只冻结并展示付款事实，不经手资金。
 */
class NezhaPaymentSnapshot
{
    public static function build(
        Order $order,
        Restaurant $restaurant,
        ?Collection $methods = null,
        ?array $rates = null,
        ?CarbonInterface $frozenAt = null,
        ?CarbonInterface $expiresAt = null,
        ?float $expectedAmdOverride = null
    ): array {
        $frozenAt = $frozenAt ?: now();
        $rates = $rates ?: self::rates();
        $rateCny = (float) ($rates['cny_to_amd'] ?? 55);
        $rateUsd = (float) ($rates['usd_to_amd'] ?? 400);
        $expectedAmd = $expectedAmdOverride ?? (float) ($order->order_amount ?? 0);
        $expectedUsdt = $rateUsd > 0 ? round($expectedAmd / $rateUsd, 2) : 0;
        $expectedRmb = $rateCny > 0 ? round($expectedAmd / $rateCny) : 0;
        $methods = $methods ?: OfflinePaymentMethod::where('status', 1)->get();

        $paymentMethods = $methods
            ->map(function (OfflinePaymentMethod $method) use (
                $restaurant,
                $expectedAmd,
                $expectedUsdt,
                $expectedRmb
            ) {
                $name = (string) $method->method_name;
                if (preg_match('/微信|wechat|weixin/i', $name) && ! preg_match('/支付宝|alipay/i', $name)) {
                    return null;
                }

                $base = [
                    'method_id' => (int) $method->id,
                    'method_name' => $name,
                    'method_fields' => $method->method_fields ?? [],
                    'expected_amd' => $expectedAmd,
                ];

                if (preg_match('/usdt/i', $name)) {
                    $isBep20 = (bool) preg_match('/bep ?20|bsc|bnb/i', $name);
                    $address = trim((string) ($isBep20
                        ? ($restaurant->usdt_bep20_address ?? '')
                        : ($restaurant->usdt_address ?? '')));
                    if ($address === '') {
                        return null;
                    }

                    return $base + [
                        'kind' => 'usdt',
                        'currency' => 'USDT',
                        'network' => $isBep20 ? 'BEP20' : 'TRC20',
                        'address' => $address,
                        'expected_amount' => $expectedUsdt,
                    ];
                }

                $qrImageUrl = $restaurant->rmb_qr_image_full_url;
                if (! $qrImageUrl) {
                    return null;
                }

                return $base + [
                    'kind' => 'alipay',
                    'currency' => 'CNY',
                    'expected_amount' => $expectedRmb,
                    'payee_name' => $restaurant->payee_name,
                    'qr_image_url' => $qrImageUrl,
                    'qr_deep_link' => $restaurant->rmb_qr_url,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'version' => 1,
            'frozen_at' => $frozenAt->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'order_amount_amd' => $expectedAmd,
            'rates' => [
                'cny_to_amd' => $rateCny,
                'usd_to_amd' => $rateUsd,
            ],
            'restaurant' => [
                'id' => (int) $restaurant->id,
                'name' => (string) $restaurant->name,
            ],
            'methods' => $paymentMethods,
        ];
    }

    public static function forCustomer(?NezhaPaymentIntent $intent): ?array
    {
        if (! $intent) {
            return null;
        }

        return array_merge($intent->snapshot ?? [], [
            'intent_id' => (int) $intent->id,
            'status' => (string) $intent->status,
        ]);
    }

    public static function methodFor(?NezhaPaymentIntent $intent, $methodId): ?array
    {
        if (! $intent) {
            return null;
        }

        foreach (($intent->snapshot['methods'] ?? []) as $method) {
            if ((string) ($method['method_id'] ?? '') === (string) $methodId) {
                return $method;
            }
        }

        return null;
    }

    private static function rates(): array
    {
        $rows = DB::table('business_settings')
            ->whereIn('key', ['nezha_rate_cny_to_amd', 'nezha_rate_usd_to_amd'])
            ->pluck('value', 'key');

        return [
            'cny_to_amd' => (float) ($rows['nezha_rate_cny_to_amd'] ?? 55),
            'usd_to_amd' => (float) ($rows['nezha_rate_usd_to_amd'] ?? 400),
        ];
    }
}
