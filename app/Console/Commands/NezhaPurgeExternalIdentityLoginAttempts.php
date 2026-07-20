<?php

namespace App\Console\Commands;

use App\Models\ExternalIdentityLoginAttempt;
use Illuminate\Console\Command;

/**
 * L1-7: delete expired, short-lived external-login state and provider claims.
 *
 * Persistent user_external_identities are deliberately out of scope: they are
 * the ownership ledger that prevents one provider subject being rebound to a
 * different customer.
 */
class NezhaPurgeExternalIdentityLoginAttempts extends Command
{
    protected $signature = 'nezha:purge-external-identity-attempts {--dry-run}';

    protected $description = 'Delete expired external identity login attempts and temporary provider claims';

    public function handle(): int
    {
        $query = ExternalIdentityLoginAttempt::query()
            ->where('expires_at', '<', now());

        $count = (clone $query)->count();
        if ($this->option('dry-run')) {
            $this->info("[dry-run] Would delete {$count} expired external identity login attempts.");

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} expired external identity login attempts.");

        return self::SUCCESS;
    }
}
