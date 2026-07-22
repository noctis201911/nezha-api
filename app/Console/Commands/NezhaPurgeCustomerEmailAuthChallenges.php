<?php

namespace App\Console\Commands;

use App\Models\CustomerEmailAuthChallenge;
use Illuminate\Console\Command;

class NezhaPurgeCustomerEmailAuthChallenges extends Command
{
    protected $signature = 'nezha:purge-customer-email-auth-challenges {--dry-run}';

    protected $description = 'Delete expired customer email authentication challenges';

    public function handle(): int
    {
        $query = CustomerEmailAuthChallenge::query()
            ->where('expires_at', '<', now()->subDay());
        $count = (clone $query)->count();

        if (! $this->option('dry-run')) {
            $query->delete();
        }

        $this->info(($this->option('dry-run') ? 'Would delete ' : 'Deleted ').$count.' challenge rows.');

        return self::SUCCESS;
    }
}
