<?php

namespace App\Console\Commands;

use App\CentralLogics\NezhaMerchantTwoFactor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ScheduleMerchantTwoFactorGrace extends Command
{
    protected $signature = 'nezha:merchant-2fa-schedule
        {deadline : Exact ISO-8601 enforcement deadline for legacy merchant actors}';

    protected $description = 'Record the approved merchant 2FA deadline for legacy actors still awaiting scheduling';

    public function handle(): int
    {
        try {
            $input = (string) $this->argument('deadline');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $input)) {
                throw new \InvalidArgumentException('The merchant 2FA deadline must be an exact ISO-8601 timestamp with a timezone.');
            }

            $deadline = Carbon::parse($input);
            $counts = NezhaMerchantTwoFactor::scheduleLegacyGrace($deadline);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Merchant 2FA deadline recorded as %s (owners=%d, employees=%d).',
            $deadline->toIso8601String(),
            $counts[NezhaMerchantTwoFactor::OWNER],
            $counts[NezhaMerchantTwoFactor::EMPLOYEE]
        ));

        return self::SUCCESS;
    }
}
