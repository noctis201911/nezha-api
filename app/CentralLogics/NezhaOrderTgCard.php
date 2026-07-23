<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 外卖 TG P2.1/P2.2a：零顾客 PII 新单卡片 + 默认关闭的确认收款按钮。
 *
 * 🔴 compose() 只允许读取店名、订单内部字段、菜品、金额与时间；
 * 禁止读取 delivery_address、顾客姓名/电话、经纬度、order_note 或付款截图。
 */
class NezhaOrderTgCard
{
    public static function compose($order): string
    {
        $shop = trim((string) ($order?->restaurant?->name ?? ''));
        if ($shop === '') {
            $shop = '店铺 #'.(string) ($order->restaurant_id ?? '?');
        }

        $typeMap = [
            'delivery' => '配送',
            'take_away' => '自取',
            'dine_in' => '堂食',
        ];
        $orderType = (string) ($order->order_type ?? '');
        $type = $typeMap[$orderType] ?? $orderType;

        $lines = [
            '🔔 新订单 #'.(string) ($order->id ?? ''),
            '🏪 '.$shop,
            $type,
            '──────────',
        ];

        $items = [];
        try {
            foreach (($order->details ?? []) as $detail) {
                $raw = $detail->food_details ?? null;
                $food = is_string($raw) ? json_decode($raw, true) : (array) $raw;
                $name = trim((string) ($food['name'] ?? '商品'));
                $items[] = ($name !== '' ? $name : '商品').' ×'.(string) ($detail->quantity ?? 0);
            }
        } catch (\Throwable $e) {
            $items = [];
        }

        foreach (array_slice($items, 0, 8) as $item) {
            $lines[] = $item;
        }
        if (count($items) > 8) {
            $lines[] = '…等'.count($items).'道';
        }

        $lines[] = '──────────';
        $lines[] = '💰 合计 '.number_format((float) ($order->order_amount ?? 0), 0).' ֏';
        $lines[] = '💳 '.self::paymentLine((string) ($order->payment_method ?? ''));

        if ((int) ($order->scheduled ?? 0) === 1 && ! empty($order->schedule_at)) {
            $scheduledAt = strtotime((string) $order->schedule_at);
            if ($scheduledAt !== false) {
                $lines[] = '📅 预约 '.date('m-d H:i', $scheduledAt);
            }
        }

        // Order::getCreatedAtAttribute 返回字符串，禁止调用 ->format()。
        $createdAt = ! empty($order->created_at) ? strtotime((string) $order->created_at) : false;
        if ($createdAt !== false) {
            $lines[] = '🕐 下单 '.date('H:i', $createdAt);
        }
        $lines[] = self::statusLine($order);
        $lines[] = '🚕 叫车/贴链接暂在商家后台操作';

        return implode("\n", $lines);
    }

    /**
     * 缩范围裁决：只显示「确认收款」。其它动作一律不渲染。
     */
    public static function keyboardFor($order, string $chatId, bool $actionsOn): ?array
    {
        if (! $actionsOn || (int) $chatId <= 0 || self::isFinalized($order)) {
            return null;
        }
        if ((string) ($order->payment_method ?? '') !== 'offline_payment'
            || ! ($order->offline_payments ?? null)) {
            return null;
        }

        return [
            'inline_keyboard' => [[
                [
                    'text' => '💰 确认收款',
                    'callback_data' => self::callbackData('pay', (int) $order->id),
                ],
            ]],
        ];
    }

    public static function prepTimeKeyboard(int $orderId): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '15分', 'callback_data' => self::callbackData('pay_t', $orderId, 15)],
                ['text' => '30分', 'callback_data' => self::callbackData('pay_t', $orderId, 30)],
                ['text' => '45分', 'callback_data' => self::callbackData('pay_t', $orderId, 45)],
                ['text' => '店默认', 'callback_data' => self::callbackData('pay_t', $orderId, 0)],
            ]],
        ];
    }

    public static function sendAndPersist($order, string $chatId): bool
    {
        $orderId = (int) ($order->id ?? 0);
        $restaurantId = (int) ($order->restaurant_id ?? 0);

        if ($orderId <= 0 || $chatId === '') {
            self::record('order_card', 'failed', $orderId, $restaurantId);

            return false;
        }

        try {
            $keyboard = self::keyboardFor(
                $order,
                $chatId,
                (int) Helpers::get_business_settings('nezha_order_tg_card_actions_status') === 1
            );
            $messageId = self::sendMessage($chatId, self::compose($order), $keyboard);
            if ($messageId === null) {
                self::record('order_card', 'failed', $orderId, $restaurantId);

                return false;
            }
        } catch (\Throwable $e) {
            Log::warning('nezha order tg card send failed');
            self::record('order_card', 'failed', $orderId, $restaurantId);

            return false;
        }

        try {
            DB::table('nezha_order_tg_cards')->updateOrInsert(
                ['order_id' => $orderId],
                [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'last_state' => (string) ($order->order_status ?? 'new'),
                    'last_action_by_tg_uid' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            self::record('order_card', 'ok', $orderId, $restaurantId);

            return true;
        } catch (\Throwable $e) {
            // 消息已经送达，落库失败不能再回旧文本，否则同一订单会双发。
            Log::warning('nezha order tg card persist_failed');
            self::record('order_card', 'persist_failed', $orderId, $restaurantId);

            return true;
        }
    }

    /**
     * 演示专用：发送构造卡片但绝不写 nezha_order_tg_cards。
     */
    public static function sendDemo(string $chatId, string $text): bool
    {
        try {
            $ok = self::sendMessage($chatId, $text) !== null;
            self::record('order_card_demo', $ok ? 'ok' : 'failed', null, null);

            return $ok;
        } catch (\Throwable $e) {
            Log::warning('nezha order tg card demo failed');
            self::record('order_card_demo', 'failed', null, null);

            return false;
        }
    }

    public static function answerCallbackQuery(
        string $callbackId,
        ?string $text = null,
        bool $showAlert = false
    ): bool {
        $token = Helpers::get_business_settings('telegram_bot_token', false);
        if (! $token || ! is_string($token) || $callbackId === '') {
            return false;
        }

        $payload = ['callback_query_id' => $callbackId];
        if ($text !== null && $text !== '') {
            $payload['text'] = mb_substr($text, 0, 200);
            $payload['show_alert'] = $showAlert;
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(2)
                ->timeout(5)
                ->post('https://api.telegram.org/bot'.$token.'/answerCallbackQuery', $payload);

            return $response->ok() && $response->json('ok') === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 按官方 Bot API 用 editMessageText 同时更新文字和 inline keyboard。
     * 编辑失败才重发，并把 cards.message_id 改到新消息。
     */
    public static function editOrResend(
        $order,
        $card,
        ?array $keyboard,
        string $lastState,
        ?string $tgUserId
    ): bool {
        $chatId = (string) ($card->chat_id ?? '');
        $messageId = (string) ($card->message_id ?? '');
        $text = self::compose($order);
        if ($chatId === '' || $messageId === '') {
            return false;
        }

        $edited = self::editMessage($chatId, $messageId, $text, $keyboard);
        if ($edited === 'ok' || $edited === 'not_modified') {
            DB::table('nezha_order_tg_cards')
                ->where('order_id', (int) $order->id)
                ->where('chat_id', $chatId)
                ->update([
                    'last_state' => $lastState,
                    'last_action_by_tg_uid' => $tgUserId,
                    'updated_at' => now(),
                ]);
            self::record('order_card_edit', 'ok', (int) $order->id, (int) $order->restaurant_id);

            return true;
        }

        $newMessageId = self::sendMessage($chatId, $text, $keyboard);
        if ($newMessageId === null) {
            self::record('order_card_edit', 'failed', (int) $order->id, (int) $order->restaurant_id);

            return false;
        }

        DB::table('nezha_order_tg_cards')
            ->where('order_id', (int) $order->id)
            ->where('chat_id', $chatId)
            ->update([
                'message_id' => $newMessageId,
                'last_state' => $lastState,
                'last_action_by_tg_uid' => $tgUserId,
                'updated_at' => now(),
            ]);
        Log::warning('nezha order tg card edit failed; resent once');
        self::record('order_card_edit_fallback', 'ok', (int) $order->id, (int) $order->restaurant_id);

        return true;
    }

    private static function sendMessage(string $chatId, string $text, ?array $keyboard = null): ?string
    {
        $token = Helpers::get_business_settings('telegram_bot_token', false);
        if (! $token || ! is_string($token) || $chatId === '' || $text === '') {
            return null;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];
        if ($keyboard !== null) {
            $payload['reply_markup'] = self::replyMarkup($keyboard);
        }

        $response = Http::asForm()
            ->connectTimeout(2)
            ->timeout(5)
            ->post('https://api.telegram.org/bot'.$token.'/sendMessage', $payload);

        if (! $response->ok() || $response->json('ok') !== true) {
            return null;
        }

        $messageId = $response->json('result.message_id');

        return $messageId === null ? null : (string) $messageId;
    }

    private static function editMessage(
        string $chatId,
        string $messageId,
        string $text,
        ?array $keyboard
    ): string {
        $token = Helpers::get_business_settings('telegram_bot_token', false);
        if (! $token || ! is_string($token)) {
            return 'failed';
        }

        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            // 显式传空键盘，确保成功后移除旧按钮。
            'reply_markup' => self::replyMarkup($keyboard ?? ['inline_keyboard' => []]),
        ];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::asForm()
                    ->connectTimeout(2)
                    ->timeout(5)
                    ->post('https://api.telegram.org/bot'.$token.'/editMessageText', $payload);
                if ($response->ok() && $response->json('ok') === true) {
                    return 'ok';
                }
                if (str_contains(strtolower((string) $response->json('description')), 'message is not modified')) {
                    return 'not_modified';
                }
            } catch (\Throwable $e) {
                // 限流/瞬时错误只重试一次；仍失败再走单次重发降级。
            }
        }

        return 'failed';
    }

    private static function callbackData(string $action, int $orderId, ?int $time = null): string
    {
        $data = ['v' => 1, 'a' => $action, 'o' => $orderId];
        if ($time !== null) {
            $data['t'] = $time;
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function replyMarkup(array $keyboard): string
    {
        return json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function isFinalized($order): bool
    {
        if (is_object($order) && method_exists($order, 'isFinalized')) {
            return $order->isFinalized();
        }

        return in_array(
            (string) ($order->order_status ?? ''),
            ['canceled', 'failed', 'refunded', 'refund_requested', 'refund_request_canceled', 'delivered'],
            true
        ) || ! empty($order->delivered);
    }

    private static function statusLine($order): string
    {
        $offlineStatus = (string) (($order->offline_payments ?? null)?->status ?? '');
        if ((string) ($order->payment_method ?? '') === 'offline_payment') {
            if ($offlineStatus === 'pending') {
                return 'ⓘ 待确认收款';
            }
            if ($offlineStatus === 'denied') {
                return 'ⓘ 收款未通过，请在商家后台处理';
            }
            if ($offlineStatus === 'verified') {
                $minutes = (int) ($order->processing_time ?? 0);

                return 'ⓘ 已确认收款'
                    .((string) ($order->order_status ?? '') === 'processing' ? ' · 备餐中' : ' · 已接单')
                    .($minutes > 0 ? "（预计 {$minutes} 分钟）" : '');
            }
        }

        $status = match ((string) ($order->order_status ?? '')) {
            'confirmed' => '已接单',
            'processing' => '备餐中',
            'handover' => '已出餐',
            'picked_up' => '配送中',
            'delivered' => '已送达',
            'canceled', 'failed' => '已结束',
            'refunded' => '已退款',
            default => '待处理',
        };

        return 'ⓘ '.$status;
    }

    private static function paymentLine(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'cash_on_delivery' => '现金（货到付）',
            'offline_payment' => '线下付款 · 凭证在后台核对',
            'partial_payment' => '部分付款 · 详情见后台',
            default => '在线支付（'.$paymentMethod.'）',
        };
    }

    private static function record(string $event, string $outcome, ?int $orderId, ?int $restaurantId): void
    {
        NezhaNotifyLog::record(
            'telegram',
            'merchant',
            $event,
            $outcome,
            $orderId ?: null,
            $restaurantId ?: null,
            null
        );
    }
}
