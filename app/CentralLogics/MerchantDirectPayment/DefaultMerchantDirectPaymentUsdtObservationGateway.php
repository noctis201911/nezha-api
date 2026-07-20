<?php

namespace App\CentralLogics\MerchantDirectPayment;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/** Uses the existing read-only chain endpoint settings; it never writes funds or chain state. */
final class DefaultMerchantDirectPaymentUsdtObservationGateway implements MerchantDirectPaymentUsdtObservationGateway
{
    public function observe(string $channel, string $transactionHash): array
    {
        try {
            $normalizedHash = MerchantDirectPaymentHash::normalize($transactionHash);
        } catch (InvalidArgumentException) {
            return [
                'provider_status' => 'unavailable',
                'provider_evidence' => ['reason' => 'invalid_transaction_hash'],
            ];
        }

        try {
            $provider = match ($channel) {
                MerchantDirectPaymentLateCasePolicy::CHANNEL_USDT_BEP20 => new BscPaymentObservationProvider(
                    $this->trustedHttpsSetting('nezha_refund_chain_rpc_bsc', 'BSC RPC')
                ),
                MerchantDirectPaymentLateCasePolicy::CHANNEL_USDT_TRC20 => new TronPaymentObservationProvider(
                    $this->trustedHttpsSetting('nezha_refund_tron_api_base', 'Tron provider'),
                    $this->optionalSetting('nezha_refund_trongrid_api_key')
                ),
                default => throw new InvalidArgumentException('chain_observation_requires_usdt_channel'),
            };

            $observation = $provider->observe($normalizedHash);
        } catch (Throwable) {
            $observation = [
                'provider_status' => 'unavailable',
                'provider_evidence' => ['reason' => 'server_misconfigured'],
            ];
        }

        $observation['attested_transaction_hash'] = $normalizedHash;

        return $observation;
    }

    private function trustedHttpsSetting(string $key, string $label): string
    {
        $url = trim((string) DB::table('business_settings')->where('key', $key)->value('value'));
        $parts = $url === '' ? false : parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new InvalidArgumentException("{$label} is not configured safely.");
        }

        return rtrim($url, '/');
    }

    private function optionalSetting(string $key): ?string
    {
        $value = DB::table('business_settings')->where('key', $key)->value('value');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
