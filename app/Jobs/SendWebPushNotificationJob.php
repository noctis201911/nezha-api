<?php

namespace App\Jobs;

use App\Models\CustomerNotificationInstallation;
use App\Services\WebPushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SendWebPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(
        public string $installationId,
        public array $subscription,
        public array $payload
    ) {}

    public function handle(WebPushSender $sender): void
    {
        $report = $sender->send($this->subscription, $this->payload);

        if ($report->isSubscriptionExpired()) {
            CustomerNotificationInstallation::query()
                ->where('installation_id', $this->installationId)
                ->where('transport', 'web_push')
                ->update(['revoked_at' => now()]);

            return;
        }

        if (! $report->isSuccess()) {
            throw new RuntimeException('Web Push provider rejected the notification: '.$report->getReason());
        }
    }
}
