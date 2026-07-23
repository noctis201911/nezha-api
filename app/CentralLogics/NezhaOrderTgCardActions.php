<?php

namespace App\CentralLogics;

use App\Exceptions\SanctionScreenException;
use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 外卖 TG P2.2a：只处理「确认收款 → 选择备餐时间」。
 *
 * 鉴权只认 callback 所在 chat 的餐厅绑定，再用 order_id + restaurant_id 复合作用域取单。
 * callback data 永不携带 restaurant_id。
 */
class NezhaOrderTgCardActions
{
    public static function handle(array $callbackQuery, ?int $updateId): void
    {
        $callbackId = trim((string) ($callbackQuery['id'] ?? ''));

        // Telegram 要求及时回答 callback；先消除客户端 loading，再做后续检查。
        NezhaOrderTgCard::answerCallbackQuery($callbackId);

        try {
            if ($callbackId === '' || ! $updateId) {
                self::feedback($callbackId, '请求无效，请稍后重试', true);

                return;
            }
            if (! Cache::add('nz_tg_upd_'.$updateId, 1, now()->addHour())) {
                self::feedback($callbackId, '该操作已处理', true);

                return;
            }
            if ((int) Helpers::get_business_settings('nezha_order_tg_card_actions_status') !== 1) {
                self::feedback($callbackId, '功能未开启');

                return;
            }

            $chatId = (string) ($callbackQuery['message']['chat']['id'] ?? '');
            if ($chatId === '' || (int) $chatId <= 0) {
                self::feedback($callbackId, '群绑定暂为只读', true);

                return;
            }

            $payload = json_decode((string) ($callbackQuery['data'] ?? ''), true);
            if (! is_array($payload)
                || (int) ($payload['v'] ?? 0) !== 1
                || ! in_array((string) ($payload['a'] ?? ''), ['pay', 'pay_t'], true)
                || (int) ($payload['o'] ?? 0) <= 0) {
                self::feedback($callbackId, '按钮已失效，请查看最新卡片', true);

                return;
            }

            $restaurant = Restaurant::where('telegram_chat_id', $chatId)->first();
            if (! $restaurant) {
                self::feedback($callbackId, '该聊天未绑定店铺', true);

                return;
            }

            $orderId = (int) $payload['o'];
            $order = Order::with(['offline_payments', 'restaurant'])
                ->where('id', $orderId)
                ->where('restaurant_id', $restaurant->id)
                ->first();
            if (! $order) {
                self::feedback($callbackId, '无权操作该订单', true);

                return;
            }

            $messageId = (string) ($callbackQuery['message']['message_id'] ?? '');
            $card = DB::table('nezha_order_tg_cards')
                ->where('order_id', $orderId)
                ->where('chat_id', $chatId)
                ->where('message_id', $messageId)
                ->first();
            if (! $card) {
                self::feedback($callbackId, '卡片已失效，请查看最新消息', true);

                return;
            }
            if ($order->isFinalized()) {
                self::feedback($callbackId, '该订单已结束', true);

                return;
            }
            if ($order->payment_method !== 'offline_payment'
                || ! $order->offline_payments
                || $order->offline_payments->status !== 'pending') {
                self::feedback($callbackId, '该订单已处理', true);

                return;
            }

            $tgUserId = isset($callbackQuery['from']['id'])
                ? (string) $callbackQuery['from']['id']
                : null;
            if ((string) $payload['a'] === 'pay') {
                $ok = NezhaOrderTgCard::editOrResend(
                    $order,
                    $card,
                    NezhaOrderTgCard::prepTimeKeyboard($orderId),
                    'awaiting_prep_time',
                    $tgUserId
                );
                self::feedback($callbackId, $ok ? '请选择备餐时间' : '卡片更新失败，请稍后重试', ! $ok);

                return;
            }

            $time = (int) ($payload['t'] ?? -1);
            if (! in_array($time, [0, 15, 30, 45], true)) {
                self::feedback($callbackId, '备餐时间无效，请重新选择', true);

                return;
            }

            self::confirmPayment(
                $callbackId,
                $restaurant,
                $order,
                $card,
                $tgUserId,
                $time === 0 ? null : $time
            );
        } catch (\Throwable $e) {
            Log::warning('nezha tg card action failed');
            self::feedback($callbackId, '操作失败，请稍后重试', true);
        }
    }

    private static function confirmPayment(
        string $callbackId,
        Restaurant $restaurant,
        Order $order,
        $card,
        ?string $tgUserId,
        ?int $processingTime
    ): void {
        $orderId = (int) $order->id;
        $lockKey = 'nz_tg_confirm_pay_'.$orderId;
        if (! Cache::add($lockKey, 1, now()->addHour())) {
            self::feedback($callbackId, '该订单已处理', true);

            return;
        }

        try {
            // 业务前检必须在真正调用核心前再做一次，挡住不同 update_id 的重复点击。
            $order = Order::with(['offline_payments', 'restaurant'])
                ->where('id', $orderId)
                ->where('restaurant_id', $restaurant->id)
                ->first();
            if (! $order || $order->isFinalized()) {
                self::feedback($callbackId, '该订单已结束', true);

                return;
            }
            if ($order->payment_method !== 'offline_payment'
                || ! $order->offline_payments
                || $order->offline_payments->status !== 'pending') {
                self::feedback($callbackId, '该订单已处理', true);

                return;
            }

            $result = OrderLogic::confirm_offline_payment(
                $order,
                'vendor',
                $restaurant->vendor_id,
                false,
                $processingTime
            );
            if ($result === false) {
                self::feedback($callbackId, '该订单已结束', true);

                return;
            }

            // 只回写当前订单，绝不批量静音同店其它未处理订单。
            Order::where('id', $orderId)
                ->where('restaurant_id', $restaurant->id)
                ->update(['checked' => 1]);

            $fresh = Order::with(['offline_payments', 'restaurant', 'details'])
                ->where('id', $orderId)
                ->where('restaurant_id', $restaurant->id)
                ->firstOrFail();
            $card = DB::table('nezha_order_tg_cards')
                ->where('order_id', $orderId)
                ->where('chat_id', (string) $restaurant->telegram_chat_id)
                ->first();
            if ($card) {
                NezhaOrderTgCard::editOrResend(
                    $fresh,
                    $card,
                    null,
                    (string) $fresh->order_status,
                    $tgUserId
                );
            }

            self::feedback($callbackId, '已确认收款');
        } catch (SanctionScreenException $e) {
            if (str_contains($e->getMessage(), '命中制裁名单')) {
                // 核心已自动拒收；只回固定文案，绝不外发异常里的 from/tx/sdn_uid。
                Order::where('id', $orderId)
                    ->where('restaurant_id', $restaurant->id)
                    ->update(['checked' => 1]);
                $fresh = Order::with(['offline_payments', 'restaurant', 'details'])
                    ->where('id', $orderId)
                    ->where('restaurant_id', $restaurant->id)
                    ->first();
                if ($fresh) {
                    NezhaOrderTgCard::editOrResend(
                        $fresh,
                        $card,
                        null,
                        'sanction_rejected',
                        $tgUserId
                    );
                }
                self::feedback(
                    $callbackId,
                    '该单付款来源命中制裁名单，已自动拒收，请勿出餐并联系平台',
                    true
                );
            } else {
                Cache::forget($lockKey);
                self::feedback($callbackId, '付款来源核验中，暂不能确认收款，请稍后重试', true);
            }
        } catch (\Throwable $e) {
            Cache::forget($lockKey);
            Log::warning('nezha tg card confirm payment failed');
            self::feedback($callbackId, '操作失败，请稍后重试', true);
        }
    }

    private static function feedback(string $callbackId, string $text, bool $showAlert = false): void
    {
        if ($callbackId !== '') {
            NezhaOrderTgCard::answerCallbackQuery($callbackId, $text, $showAlert);
        }
    }
}
