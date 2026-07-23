<?php

namespace App\CentralLogics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 外卖 TG P2.1：零顾客 PII 的只读新单卡片。
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
        $lines[] = 'ⓘ 状态以商家后台为准（本卡片暂不自动更新）';

        return implode("\n", $lines);
    }

    public static function sendAndPersist($order, string $chatId): bool
    {
        $orderId = (int) ($order->id ?? 0);
        $restaurantId = (int) ($order->restaurant_id ?? 0);

        try {
            if ($orderId <= 0 || $chatId === '') {
                self::record('order_card', 'failed', $orderId, $restaurantId);

                return false;
            }

            $messageId = self::sendMessage($chatId, self::compose($order));
            if ($messageId === null) {
                self::record('order_card', 'failed', $orderId, $restaurantId);

                return false;
            }

            DB::table('nezha_order_tg_cards')->updateOrInsert(
                ['order_id' => $orderId],
                [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'last_state' => 'new',
                    'last_action_by_tg_uid' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            self::record('order_card', 'ok', $orderId, $restaurantId);

            return true;
        } catch (\Throwable $e) {
            Log::warning('nezha order tg card send/persist failed');
            self::record('order_card', 'failed', $orderId, $restaurantId);

            return false;
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

    private static function sendMessage(string $chatId, string $text): ?string
    {
        $token = Helpers::get_business_settings('telegram_bot_token', false);
        if (! $token || ! is_string($token) || $chatId === '' || $text === '') {
            return null;
        }

        $response = Http::asForm()
            ->connectTimeout(2)
            ->timeout(5)
            ->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ]);

        if (! $response->ok() || $response->json('ok') !== true) {
            return null;
        }

        $messageId = $response->json('result.message_id');

        return $messageId === null ? null : (string) $messageId;
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
