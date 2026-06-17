<?php

namespace Database\Seeders;

use App\Models\NotificationMessage;
use App\Models\Translation;
use Illuminate\Database\Seeder;

/**
 * 哪吒: 顾客端订单状态推送文案（notification_messages user_type='user'）。
 *
 * 背景: notification_messages 表曾被 migrate/导库洗空(0 行)，导致
 * Helpers::order_status_update_message() 查不到文案返回 null，
 * sentUserNotification() 的 `if($value && $user_fcm)` 不成立 → 商家点任何状态变更
 * (含「出餐/handover」)顾客端零推送、站内信也不写。本 seeder 幂等补齐这些文案，
 * 防表再次被洗空时可一键重跑: php artisan db:seed --class=NezhaUserNotificationMessageSeeder
 *
 * locale 说明(关键): 推送查找用的是 customer.current_language_key，本项目真实顾客是
 * 'en' 与 'zh-CN'(不是系统语言码 'zh')。所以:
 *   - 基础 message 列 = 中文(兜底，覆盖 zh-CN 顾客及任何未匹配 locale)
 *   - translations: locale='en'(英文顾客) + 'zh-CN'(精确匹配) + 'zh'(后台 UI 一致)
 *
 * 文案约定: 出餐(handover)不在推送里塞取餐号——号靠消息中心轮询 track 接口拿
 * (commit bdc1b02)，推送只「提醒去看」。可用占位符见 Helpers::text_variable_data_format
 * ({restaurantName}/{orderId}/{userName}/{otp}/{tokenNumber} 等，订单恒有 restaurantName)。
 */
class NezhaUserNotificationMessageSeeder extends Seeder
{
    public function run(): void
    {
        // key => [zh(中文/基础兜底), en(英文)]
        $messages = [
            'order_pending_message' => [
                '您在「{restaurantName}」的订单已提交，正在等商家确认，请稍候。',
                'Your order at {restaurantName} has been placed and is awaiting the restaurant\'s confirmation.',
            ],
            'order_confirmation_msg' => [
                '「{restaurantName}」已接单，正在为您准备，可在 App 查看订单进度。',
                '{restaurantName} has accepted your order and started preparing it. Track the progress in the app.',
            ],
            'order_processing_message' => [
                '「{restaurantName}」正在为您备餐，请耐心等待出餐通知。',
                '{restaurantName} is preparing your order. We\'ll notify you when it\'s ready.',
            ],
            // 出餐——本次修复的核心。不塞取餐号，提醒去消息中心看。
            'order_handover_message' => [
                '您在「{restaurantName}」的订单已出餐，请在 App 查看取餐号并尽快前往取餐。',
                'Your order at {restaurantName} is ready! Open the app to see your pickup code and collect it soon.',
            ],
            'out_for_delivery_message' => [
                '您的订单已出发配送，请留意送达。',
                'Your order is on its way. Please stay tuned for the delivery.',
            ],
            'order_delivered_message' => [
                '您在「{restaurantName}」的订单已完成，感谢惠顾，欢迎再次光临！',
                'Your order at {restaurantName} is complete. Thank you, and see you again soon!',
            ],
            'delivery_boy_delivered_message' => [
                '您的订单已送达，感谢惠顾！',
                'Your order has been delivered. Thank you!',
            ],
            'delivery_boy_assign_message' => [
                '骑手已接单，正在前往取餐。',
                'A rider has accepted your order and is heading to pick it up.',
            ],
            'order_cancled_message' => [
                '很抱歉，您在「{restaurantName}」的订单已取消，如有疑问请联系商家或客服。',
                'Sorry, your order at {restaurantName} has been canceled. Contact the restaurant or support if you have questions.',
            ],
            'order_refunded_message' => [
                '您的订单退款已处理，款项将原路退回，请留意到账。',
                'Your refund has been processed and will be returned via the original payment method.',
            ],
            'refund_request_canceled' => [
                '您的退款申请未通过，如有疑问请联系客服。',
                'Your refund request was not approved. Please contact support if you have any questions.',
            ],
            'offline_order_accept_message' => [
                '商家已确认收到您的付款，订单继续处理中，可在 App 查看进度。',
                'The restaurant has confirmed your payment. Your order is being processed — track it in the app.',
            ],
            'offline_order_deny_message' => [
                '商家未能确认您的付款，请在 App 核对付款信息或联系商家。',
                'The restaurant could not confirm your payment. Please check your payment details in the app or contact the restaurant.',
            ],
        ];

        $model = 'App\\Models\\NotificationMessage';

        foreach ($messages as $key => [$zh, $en]) {
            // 基础 message 列 = 中文兜底
            $row = NotificationMessage::updateOrCreate(
                ['user_type' => 'user', 'key' => $key],
                ['message' => $zh, 'status' => 1]
            );

            // 按真实顾客 locale 写译文
            $localeValues = [
                'en'    => $en,
                'zh-CN' => $zh,
                'zh'    => $zh,
            ];
            foreach ($localeValues as $locale => $value) {
                Translation::updateOrCreate(
                    [
                        'translationable_type' => $model,
                        'translationable_id'   => $row->id,
                        'locale'               => $locale,
                        'key'                  => $key,
                    ],
                    ['value' => $value]
                );
            }
        }

        $this->command?->info('哪吒顾客端订单推送文案已补齐: '.count($messages).' 条 key (含 en/zh-CN/zh 译文)。');
    }
}
