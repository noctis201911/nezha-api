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
 *
 * P0(0711): telegramSyncSend 已返送达真值(读 ok 字段)。本 Job 由 tries=1 → tries=3+backoff:
 * 发送失败(ok≠true/超时/传输错)时 handle 主动抛异常触发队列重试(10s、30s), 三次仍失败 → failed_jobs 留痕可审计。
 * 接单/报警类通知「重复一条 ≪ 漏一单」, 且单号相同人眼自去重; 成功即不抛=不重试=不刷屏。
 * 注: 400/403 等永久错也会重试满 3 次(P0 可接受, 浪费有界); 错误分类(永久错免重试)排在 P4 outbox。
 */
class SendTelegramMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** 每次重试前的等待秒数(第1次失败后 10s, 之后 30s); 末值对剩余重试重复。 */
    public array $backoff = [10, 30];

    public int $timeout = 15;

    public function __construct(public string $chatId, public string $text)
    {
    }

    public function handle(): void
    {
        $ok = Helpers::telegramSyncSend($this->chatId, $this->text);
        if (!$ok) {
            // 抛出 → 消耗一次尝试并按 backoff 重新入队; tries 用尽后落 failed_jobs(可审计, 非静默假成功)。
            throw new \RuntimeException('telegram send failed for chat …' . substr($this->chatId, -4));
        }
    }
}