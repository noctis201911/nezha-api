<?php

namespace App\Console\Commands;

use App\CentralLogics\NezhaPaymentAddressChangeService;
use Illuminate\Console\Command;

class MaintainPaymentAddressChanges extends Command
{
    protected $signature = 'nezha:payment-address-maintain {--limit=100}';

    protected $description = 'Expire stale approvals and atomically apply drained USDT address changes';

    public function handle(): int
    {
        if (! NezhaPaymentAddressChangeService::enabled()) {
            $this->line('{"status":"disabled"}');
            return self::SUCCESS;
        }
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $expired = NezhaPaymentAddressChangeService::expireStaleChanges($limit);
        $applied = NezhaPaymentAddressChangeService::applyReadyChanges($limit);
        $this->line(json_encode(['expired' => $expired] + $applied, JSON_UNESCAPED_SLASHES));

        return $applied['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
