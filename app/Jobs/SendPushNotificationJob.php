<?php

namespace App\Jobs;

use App\CentralLogics\Helpers;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 哪吒: FCM 推送异步化 —— 把跨境 Google 往返(getAccessToken OAuth + messages:send)
 * 甩到 nezha-queue worker, 不再吊住下单/状态更新等请求主路径。
 * payload = send_push_notif_to_device/_to_topic 拼好的 FCM message 数组(纯标量, 无 Eloquent 模型, 序列化安全)。
 * 真实发送逻辑见 Helpers::pushHttpSyncSend()(与异步化前一致)。
 */
class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 30;

    public function __construct(public array $payload)
    {
    }

    public function handle(): void
    {
        Helpers::pushHttpSyncSend($this->payload);
    }
}