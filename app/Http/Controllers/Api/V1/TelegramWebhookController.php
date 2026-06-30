<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaCsAssistant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            // 阶段D-③: 非回复的纯文本 → 尝试当绑定码(发码自动绑)
            elseif ($chatId !== null && $text !== '') {
                $name = NezhaCsAssistant::consumeBindCode($text, $chatId);
                if ($name) {
                    Helpers::sendTelegramRaw((string) $chatId, "✅ 已绑定到店铺「{$name}」，今后该店的新订单 / 超时提醒会发送到这里。", $msg['message_id'] ?? null);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('nezha tg webhook: ' . $e->getMessage());
        }

        // 始终 200，避免 Telegram 重试风暴
        return response()->json(['ok' => true]);
    }
}
