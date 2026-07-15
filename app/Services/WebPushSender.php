<?php

namespace App\Services;

use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

class WebPushSender
{
    public function send(array $subscription, array $payload): MessageSentReport
    {
        $vapid = config('webpush.vapid', []);
        foreach (['subject', 'public_key', 'private_key'] as $requiredKey) {
            if (! is_string($vapid[$requiredKey] ?? null) || trim($vapid[$requiredKey]) === '') {
                throw new RuntimeException("Web Push VAPID {$requiredKey} is not configured.");
            }
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $vapid['subject'],
                'publicKey' => $vapid['public_key'],
                'privateKey' => $vapid['private_key'],
            ],
        ], [
            'TTL' => 300,
            'urgency' => 'high',
        ], 10, [
            'allow_redirects' => false,
            'connect_timeout' => 3,
        ]);

        return $webPush->sendOneNotification(
            Subscription::create($subscription),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }
}
