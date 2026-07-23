<?php

namespace App\Console\Commands;

use App\Services\CustomerAccountDeletion\CustomerAccountDeletionService;
use Illuminate\Console\Command;

class CustomerAccountDeletionRestoreReplay extends Command
{
    protected $signature = 'nezha:customer-account-deletion-restore-replay {--execute} {--limit=500}';

    protected $description = 'Detect or replay completed account-deletion purges after a backup restore';

    public function handle(CustomerAccountDeletionService $service): int
    {
        $execute = (bool) $this->option('execute');
        $count = $service->replayCompleted(! $execute, max(1, min(5000, (int) $this->option('limit'))));
        $this->line(json_encode([
            'mode' => $execute ? 'execute' : 'dry-run',
            'requests_requiring_replay' => $count,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $execute || $count === 0 ? self::SUCCESS : self::FAILURE;
    }
}
