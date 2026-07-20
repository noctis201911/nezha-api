<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\TelegramWebhookController;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 哪吒 #14 — Telegram 入站 webhook「发语音」分支测试(v3.3)。
 *
 * 🔴 安全: phpunit.xml 已强制 DB_CONNECTION=sqlite / DB_DATABASE=:memory:, 本组用例**不碰任何生产库**。
 *   建表走 Tests\Support\IsolatedDatabaseFixtures; 仍保留 DatabaseTransactions 保证造店零残留。
 *   (旧注曾称「仍连生产 MySQL」, 随内存库切换已失效, 2026-07-20 更正。)
 *   Http::fake 拦截 api.telegram.org → 零真实网络。Config::set('<key>_conf'=['value'=>..]) 注入
 *   secret/token 走 get_business_settings 的 Config 缝(绕 DB/静态缓存, 可靠且随测试回滚)。
 *   限流键 nz_voice_file_* 走 Cache(不随事务回滚), 每例前后 forget 防跨例污染。
 *
 * 覆盖四断言: ①已绑 chat 发关键词→sendAudio 1 次 ②60s 内二次→限流跳过 ③未绑 chat→零调用 ④非关键词文本不触发。
 */
class NezhaVoiceSampleWebhookTest extends TestCase
{
    use DatabaseTransactions;

    private string $secret = 'nz-test-secret-9f3a';

    protected function setUp(): void
    {
        parent::setUp();
        // webhook 真实运行在 HTTP 入口(public/index.php 定义 DOMAIN_POINTED_DIRECTORY); 控制台/测试上下文无此常量,
        // dynamicAsset() 会抛"未定义常量"。此处补定义以忠实模拟生产 HTTP 上下文(生产 webhook 恒有该常量)。
        if (! defined('DOMAIN_POINTED_DIRECTORY')) {
            define('DOMAIN_POINTED_DIRECTORY', 'public');
        }
        // Config 缝: get_business_settings 先查 <key>_conf, 值须为 ['value'=>...] 形。
        Config::set('nezha_cs_tg_webhook_secret_conf', ['value' => $this->secret]);
        Config::set('telegram_bot_token_conf', ['value' => 'TEST_TOKEN']);
        Config::set('nezha_notif_log_status_conf', ['value' => '0']); // 关记账(测试期不写通知日志表)
    }

    private function mkBoundRestaurant(string $chatId): int
    {
        $vid = (int) (DB::table('vendors')->value('id') ?: 1);

        return (int) DB::table('restaurants')->insertGetId([
            'name' => 'NZ语音测试店', 'phone' => '00000000', 'vendor_id' => $vid,
            'zone_id' => 3, 'telegram_chat_id' => $chatId, 'timeout_notify_telegram' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function hit(string $chatId, string $text): void
    {
        $req = Request::create('/api/v1/nezha/telegram-webhook', 'POST', [
            'message' => ['chat' => ['id' => $chatId], 'text' => $text, 'message_id' => 11],
        ]);
        $req->headers->set('X-Telegram-Bot-Api-Secret-Token', $this->secret);
        (new TelegramWebhookController())->handle($req);
    }

    public function test_bound_chat_keyword_sends_audio_once(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        $chat = '990101';
        Cache::forget('nz_voice_file_' . $chat);
        $this->mkBoundRestaurant($chat);

        $this->hit($chat, '语音');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendAudio') && ($r['chat_id'] ?? null) === $chat);
        Http::assertSentCount(1);
        Cache::forget('nz_voice_file_' . $chat);
    }

    public function test_second_within_60s_is_rate_limited(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $chat = '990102';
        Cache::forget('nz_voice_file_' . $chat);
        $this->mkBoundRestaurant($chat);

        $this->hit($chat, '提示音');
        $this->hit($chat, '提示音'); // 60s 内二次 → 限流跳过

        Http::assertSentCount(1);
        Cache::forget('nz_voice_file_' . $chat);
    }

    public function test_unbound_chat_sends_nothing(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $chat = '990103-unbound';
        Cache::forget('nz_voice_file_' . $chat);
        // 不造店 → 该 chat 未绑定任何店

        $this->hit($chat, '语音');

        Http::assertNothingSent();
        Cache::forget('nz_voice_file_' . $chat);
    }

    public function test_non_keyword_text_does_not_trigger(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $chat = '990104';
        Cache::forget('nz_voice_file_' . $chat);
        $this->mkBoundRestaurant($chat);

        $this->hit($chat, 'A1B2C3'); // 绑定码形态的非关键词文本 → 不被语音分支截胡

        Http::assertNothingSent();
        Cache::forget('nz_voice_file_' . $chat);
    }
}
