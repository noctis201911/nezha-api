<?php

namespace App\Console\Commands;

use App\Services\CustomerAccountDeletion\CustomerAccountDeletionService;
use Illuminate\Console\Command;

class CustomerAccountDeletionWork extends Command
{
    protected $signature = 'nezha:customer-account-deletion-work {--limit=100}';

    protected $description = 'Reconcile and advance the durable customer account-deletion lifecycle';

    public function handle(CustomerAccountDeletionService $service): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $result = [
            'reconciled' => $service->reconcileActive($limit),
            'sessions_revoked' => $service->revokePendingSessions($limit),
            'executed' => $service->executeDue(min($limit, 100)),
            'notices_sent' => $service->deliverPendingNotices(min($limit, 100)),
        ];
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
