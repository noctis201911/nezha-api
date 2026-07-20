<?php

namespace App\CentralLogics\MerchantDirectPayment;

interface MerchantDirectPaymentUsdtObservationGateway
{
    /** Return a normalized read-only observation for exactly the supplied transaction hash. */
    public function observe(string $channel, string $transactionHash): array;
}
