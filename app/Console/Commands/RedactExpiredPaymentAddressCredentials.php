<?php

namespace App\Console\Commands;

use App\CentralLogics\NezhaPaymentAddressCredentialService;
use Illuminate\Console\Command;

class RedactExpiredPaymentAddressCredentials extends Command
{
    protected $signature = 'nezha:payment-address-credential-retain {--limit=1000}';

    protected $description = 'Clear sensitive fields from unconsumed address credentials after 30 days';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $result = NezhaPaymentAddressCredentialService::redactExpiredUnconsumed($limit);
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
