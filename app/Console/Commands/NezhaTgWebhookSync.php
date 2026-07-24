<?php

namespace App\Console\Commands;

use App\CentralLogics\Helpers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * 只提供幂等同步工具；写入本命令不等于运行，部署批次获批后才能执行。
 */
class NezhaTgWebhookSync extends Command
{
    protected $signature = 'nezha:tg-webhook-sync';

    protected $description = '同步 Telegram webhook，并订阅 message/edited_message/callback_query';

    public function handle(): int
    {
        $token = Helpers::get_business_settings('telegram_bot_token', false);
        $secret = Helpers::get_business_settings('nezha_cs_tg_webhook_secret', false);
        if (! $token || ! is_string($token) || ! $secret || ! is_string($secret)) {
            $this->error('Telegram bot token 或 webhook secret 未配置。');

            return self::FAILURE;
        }

        $base = 'https://api.telegram.org/bot'.$token.'/';
        $before = $this->getInfo($base);
        $this->printInfo('同步前', $before);
        $webhookUrl = $this->webhookUrl();
        if ($webhookUrl === null) {
            $this->error('APP_URL 无法转换为有效的 HTTPS webhook 地址。');

            return self::FAILURE;
        }

        $response = Http::asForm()
            ->connectTimeout(3)
            ->timeout(10)
            ->post($base.'setWebhook', [
                'url' => $webhookUrl,
                'secret_token' => $secret,
                'allowed_updates' => json_encode([
                    'message',
                    'edited_message',
                    'callback_query',
                ], JSON_UNESCAPED_SLASHES),
            ]);
        if (! $response->ok() || $response->json('ok') !== true) {
            $this->error('setWebhook 失败：'.(string) $response->json('description', '未知错误'));

            return self::FAILURE;
        }

        $after = $this->getInfo($base);
        $this->printInfo('同步后', $after);

        $this->info('Telegram webhook 已同步。');

        return self::SUCCESS;
    }

    private function webhookUrl(): ?string
    {
        $appUrl = rtrim(trim((string) config('app.url')), '/');
        if (str_starts_with(strtolower($appUrl), 'http://')) {
            $appUrl = 'https://'.substr($appUrl, 7);
        }

        if (filter_var($appUrl, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }

        return $appUrl.'/api/v1/nezha/telegram-webhook';
    }

    private function getInfo(string $base): array
    {
        try {
            $response = Http::connectTimeout(3)->timeout(10)->get($base.'getWebhookInfo');
            if ($response->ok() && $response->json('ok') === true) {
                return (array) $response->json('result', []);
            }
        } catch (\Throwable $e) {
            // 只在命令输出安全的统一失败状态，不回显 token/secret。
        }

        return [];
    }

    private function printInfo(string $label, array $info): void
    {
        $safe = [
            'url' => (string) ($info['url'] ?? ''),
            'allowed_updates' => array_values((array) ($info['allowed_updates'] ?? [])),
            'pending_update_count' => (int) ($info['pending_update_count'] ?? 0),
            'last_error_date' => $info['last_error_date'] ?? null,
            'last_error_message' => $info['last_error_message'] ?? null,
        ];

        $this->line($label.'：'.json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
