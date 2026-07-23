<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaCsAssistant;
use App\CentralLogics\NezhaNotifyLog;
use App\CentralLogics\NezhaOrderTgCardActions;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒 阶段D: Telegram 入站 webhook —— 超管/商家在 Telegram 里回复 → 回写进平台会话 → 推送顾客。
 *
 * 安全：Telegram setWebhook 时配 secret_token，每次回调带 X-Telegram-Bot-Api-Secret-Token 头；
 *      不匹配一律 403（防伪造）。任何异常都返回 200，避免 Telegram 反复重试。
 *
 * 仅处理：① 对"我们推过去的某条消息"的『回复』(reply_to) → 按映射 nezha_cs_tg_map 找到会话回写。
 *        其它更新(普通消息/命令/加群等)暂忽略（绑定改造在后续步骤）。
 */
class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $expected = Helpers::get_business_settings('nezha_cs_tg_webhook_secret');
        $got = $request->header('X-Telegram-Bot-Api-Secret-Token');
        if (!$expected || !is_string($expected) || !$got || !hash_equals((string) $expected, (string) $got)) {
            return response()->json(['ok' => false], 403);
        }

        try {
            $msg = $request->input('message') ?? $request->input('edited_message');
            if (!is_array($msg)) {
                $callbackQuery = $request->input('callback_query');
                if (is_array($callbackQuery)) {
                    NezhaOrderTgCardActions::handle(
                        $callbackQuery,
                        $request->filled('update_id') ? (int) $request->input('update_id') : null
                    );
                }

                return response()->json(['ok' => true]);
            }
            $chatId = $msg['chat']['id'] ?? null;
            $text = trim((string) ($msg['text'] ?? ''));
            $replyToId = $msg['reply_to_message']['message_id'] ?? null;

            // 只处理：对我们推过去的消息的『回复』
            if ($chatId !== null && $replyToId && $text !== '') {
                $map = DB::table('nezha_cs_tg_map')
                    ->where('tg_chat_id', (string) $chatId)
                    ->where('tg_message_id', (string) $replyToId)
                    ->orderByDesc('id')
                    ->first();
                if ($map) {
                    $ok = NezhaCsAssistant::postHumanReply($map->conversation_id, $text, $map->scope ?? 'admin');
                    // 给回复人一个轻确认（可选，失败不影响）
                    if ($ok) {
                        Helpers::sendTelegramRaw((string) $chatId, '✅ 已回复顾客。', $msg['message_id'] ?? null);
                    } else {
                        Helpers::sendTelegramRaw((string) $chatId, '⚠️ 回复失败（会话可能已变化），请登录后台处理。', $msg['message_id'] ?? null);
                    }
                }
            }
            // 阶段D-③: 非回复的纯文本 → 先尝试当绑定码(发码自动绑); 不是绑定码再看是否"索取提示音"关键词。
            elseif ($chatId !== null && $text !== '') {
                $name = NezhaCsAssistant::consumeBindCode($text, $chatId);
                if ($name) {
                    Helpers::sendTelegramRaw((string) $chatId, "✅ 已绑定到店铺「{$name}」，今后该店的新订单 / 超时提醒会发送到这里。", $msg['message_id'] ?? null);
                } else {
                    // 哪吒 #14: 已绑商家发「语音/提示音/铃声」→ 回一条 3 秒提示音(sendAudio), 供长按"保存为提示音"。
                    $this->maybeSendVoiceSample((string) $chatId, $text);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('nezha tg webhook: ' . $e->getMessage());
        }

        // 始终 200，避免 Telegram 重试风暴
        return response()->json(['ok' => true]);
    }

    /**
     * 哪吒 #14: 已绑定商家在 Telegram 里索取"提示音/语音"关键词时, 回一条 3 秒提示音文件(sendAudio),
     * 供其长按 →「保存为提示音(Save for Notifications)」→ 通知设置里选中。
     *
     * additive 末位分支: 只对**已绑定本店**的 chat 生效(未绑/陌生 chat 静默); 每 chat 60 秒限流;
     * 任何异常吞掉, 绝不影响绑定/客服回复两支。纯通知能力(与绑定同级的显式索取), 无顾客 PII、无新开关。
     * 复用 telegramSyncSend 的健壮性(读 HTTP 码 + 解析 ok 真值); 用 Http 客户端而非裸 curl, 以便 Http::fake 覆盖。
     */
    private function maybeSendVoiceSample(string $chatId, string $text): void
    {
        // 仅"语音 / 提示音 / 铃声 / sound"关键词触发(其它文本一律不打扰)。
        if (! preg_match('/语音|提示音|铃声|sound/iu', $text)) {
            return;
        }
        // 必须命中"已绑定本店"的 chat(未绑/陌生 chat 静默, 与本 webhook 其它分支同纪律)。
        $restaurant = DB::table('restaurants')->select('id')->where('telegram_chat_id', $chatId)->first();
        if (! $restaurant) {
            return;
        }
        // 每 chat 60 秒限流(防连点刷屏); 抢不到锁 = 近期已发, 直接跳过。
        if (! Cache::add('nz_voice_file_' . $chatId, 1, 60)) {
            return;
        }

        $ok = false;
        try {
            $token = Helpers::get_business_settings('telegram_bot_token', false);
            if ($token && is_string($token)) {
                $resp = Http::asForm()->connectTimeout(3)->timeout(8)
                    ->post('https://api.telegram.org/bot' . $token . '/sendAudio', [
                        'chat_id' => $chatId,
                        'audio'   => dynamicAsset('assets/admin/sound/new-order-voice.mp3'),
                        'caption' => '哪吒新单提示音（3 秒）。长按本条语音 → 选择「保存为提示音（Save for Notifications）」→ 点击顶部名称进入通知设置，在「声音」中选中即可。',
                    ]);
                $ok = $resp->ok() && ($resp->json('ok') === true);
            }
        } catch (\Throwable $e) {
            Log::info('nz voice sample fail: chat=…' . substr($chatId, -4) . ' ' . $e->getMessage());
        }

        try {
            NezhaNotifyLog::record('telegram', 'merchant', 'voice_file', $ok ? 'ok' : 'failed', null, (int) $restaurant->id);
        } catch (\Throwable $e) {
            // best-effort 记账, 绝不影响 webhook 200。
        }
    }
}
