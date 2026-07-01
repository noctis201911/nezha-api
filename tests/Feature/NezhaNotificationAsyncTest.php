<?php

namespace Tests\Feature;

use App\CentralLogics\Helpers;
use App\Jobs\SendPushNotificationJob;
use App\Jobs\SendTelegramMessageJob;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 哪吒: 订单通知异步化 —— 证明:
 *  (A) 灰度开关 nezha_notif_async_status 开时, 网络原语「入队」而非「内联同步发」;
 *  (B) 开关关(默认)时, 保持异步化前的内联行为(不入队) —— 灰度安全。
 * 全部 DB-free: get_business_settings 先看 Config::has(key.'_conf'), 故用 Config::set 强制开关值,
 * 且 Queue::fake 拦截 dispatch 不执行 handle, 不触发真实 HTTP。
 */
class NezhaNotificationAsyncTest extends TestCase
{
    private function enableAsync(): void
    {
        // get_business_settings('nezha_notif_async_status') 会命中此 config, 返回 '1' -> (int)1 = 开。
        Config::set('nezha_notif_async_status_conf', ['value' => '1']);
    }

    private function disableAsync(): void
    {
        Config::set('nezha_notif_async_status_conf', ['value' => '0']);
    }

    /** A: 开关开 -> send_push_notif_to_device 入队 SendPushNotificationJob(不内联发 FCM)。 */
    public function test_push_device_is_enqueued_when_flag_on(): void
    {
        Queue::fake();
        $this->enableAsync();

        Helpers::send_push_notif_to_device('fake-fcm-token', [
            'title' => 'T', 'description' => 'D', 'image' => '',
            'order_id' => '1', 'type' => 'order_status',
        ]);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
    }

    /** A: 开关开 -> 底层 sendNotificationToHttp 直调也入队(topic 推送共用此原语)。 */
    public function test_send_notification_to_http_enqueues_when_flag_on(): void
    {
        Queue::fake();
        $this->enableAsync();

        Helpers::sendNotificationToHttp(['message' => ['token' => 'x']]);

        Queue::assertPushed(SendPushNotificationJob::class, 1);
    }

    /** A: 开关开 + 有 chat_id -> Telegram 入队, 携带正确标量 chatId/text。 */
    public function test_telegram_restaurant_is_enqueued_with_scalars_when_flag_on(): void
    {
        Queue::fake();
        $this->enableAsync();

        $r = new Restaurant();
        $r->telegram_chat_id = '999888';

        Helpers::sendTelegramToRestaurant($r, '测试消息');

        Queue::assertPushed(SendTelegramMessageJob::class, function ($job) {
            return $job->chatId === '999888' && $job->text === '测试消息';
        });
    }

    /** 守卫: 开关开但 chat_id 为空 -> 不入队(廉价前置校验, 不产生无效 job)。 */
    public function test_telegram_skips_enqueue_when_no_chat_id(): void
    {
        Queue::fake();
        $this->enableAsync();

        $r = new Restaurant(); // 无 telegram_chat_id
        Helpers::sendTelegramToRestaurant($r, 'x');

        Queue::assertNotPushed(SendTelegramMessageJob::class);
    }

    /** 守卫: 开关开但 text 为空 -> 不入队。 */
    public function test_telegram_skips_enqueue_when_no_text(): void
    {
        Queue::fake();
        $this->enableAsync();

        $r = new Restaurant();
        $r->telegram_chat_id = '123';
        Helpers::sendTelegramToRestaurant($r, '');

        Queue::assertNotPushed(SendTelegramMessageJob::class);
    }

    /** B(灰度安全): 开关关 -> 推送不入队(走内联同步 pushHttpSyncSend, = 异步化前行为)。 */
    public function test_push_stays_inline_when_flag_off(): void
    {
        Queue::fake();
        $this->disableAsync();

        Helpers::send_push_notif_to_device('fake-fcm-token', [
            'title' => 'T', 'description' => 'D', 'image' => '',
            'order_id' => '1', 'type' => 'order_status',
        ]);

        Queue::assertNotPushed(SendPushNotificationJob::class);
    }

    /** B(灰度安全): 开关关 -> Telegram 不入队(走内联同步 telegramSyncSend)。 */
    public function test_telegram_stays_inline_when_flag_off(): void
    {
        Queue::fake();
        $this->disableAsync();

        $r = new Restaurant();
        $r->telegram_chat_id = '999888';
        Helpers::sendTelegramToRestaurant($r, 'x');

        Queue::assertNotPushed(SendTelegramMessageJob::class);
    }
}
