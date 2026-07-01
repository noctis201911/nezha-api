<?php

namespace App\Jobs;

use App\CentralLogics\Helpers;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 哪吒: Telegram 通知异步化 —— 把 3s+4s 超时的 curl 甩到 nezha-queue worker。
 * 只带标量 chatId + text(序列化安全); token 在 worker 内解析。
 * 真实发送见 Helpers::telegramSyncSend()。
 * tries=1(至多一次): 非幂等外部发送, 不重试防 Telegram 重复刷屏。
 */
class SendTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public string $chatId, public string $text)
    {
    }

    public function handle(): void
    {
        Helpers::telegramSyncSend($this->chatId, $this->text);
    }
}