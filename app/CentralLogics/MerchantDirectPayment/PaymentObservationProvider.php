<?php

namespace App\CentralLogics\MerchantDirectPayment;

interface PaymentObservationProvider
{
    public function observe(string $normalizedTxHash): array;
}
