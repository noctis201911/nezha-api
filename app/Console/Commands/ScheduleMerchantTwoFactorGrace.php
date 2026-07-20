<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ScheduleMerchantTwoFactorGrace extends Command
{
    protected $signature = 'nezha:merchant-2fa-schedule
        {deadline : Legacy compatibility argument; ignored because enforcement is disabled}';

    protected $description = 'Disabled: merchant 2FA is voluntary and cannot be scheduled for enforcement';

    public function handle(): int
    {
        $this->error('Merchant two-factor authentication is voluntary; enforcement scheduling is disabled.');

        return self::FAILURE;
    }
}
