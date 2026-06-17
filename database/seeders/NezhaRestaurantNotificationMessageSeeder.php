<?php

namespace Database\Seeders;

use App\Models\NotificationMessage;
use App\Models\Translation;
use Illuminate\Database\Seeder;

/**
 * 哪吒: 商家端推送文案（notification_messages user_type='restaurant'）。
 *
 * 配套 NezhaUserNotificationMessageSeeder（顾客端）。同根因: notification_messages
 * 表被洗空 → Helpers::order_status_update_message() 返 null → 商家通知文案为空。
 * 幂等，可反复跑: php artisan db:seed --class=NezhaRestaurantNotificationMessageSeeder
 *
 * 🔴 只补 B 方案真正会用到的 key:
 *   - restaurant_order_notification: 新订单提醒（核心）
 *   - restaurant_account_block / _unblock: 平台暂停/恢复店铺
 * 故意【不补】的 restaurant key 及原因:
 *   - restaurant_withdraw_approve / _rejaction: B 方案平台不碰钱、无提现，文案会与合规模型冲突
 *   - restaurant_campaign_* / advertisement_* / subscription_*: MVP 未启用这些功能
 *
 * locale 说明: 商家通知 lang 取 vendor.current_language_key，本项目 vendor 全是 'zh'
 * (注意与顾客的 'zh-CN' 不同)。基础 message 列存中文兜底，另写 'zh'/'en' 译文。
 *
 * ⚠️ 重要: 单有文案【不等于】商家就能收到推送。新订单的 FCM 发送在
 * Helpers::sentRestaurantNotification()，受 order_confirmation_model / 支付方式 / topic 订阅 /
 * vendor firebase_token / telegram_chat_id 等多重门槛限制——见 ADMIN_GUIDE 9.7。
 */
class NezhaRestaurantNotificationMessageSeeder extends Seeder
{
    public function run(): void
    {
        // key => [zh(中文/基础兜底), en(英文)]
        $messages = [
            'restaurant_order_notification' => [
                '您有一笔新订单（#{orderId}），请尽快登录商家后台确认接单。',
                'You have a new order (#{orderId}). Please log in to the merchant panel and accept it as soon as possible.',
            ],
            'restaurant_account_block' => [
                '您的店铺已被平台暂停营业，如有疑问请联系平台客服。',
                'Your store has been suspended by the platform. Please contact platform support if you have any questions.',
            ],
            'restaurant_account_unblock' => [
                '您的店铺已恢复营业，现在可以正常接单了。',
                'Your store has been reactivated. You can accept orders again now.',
            ],
        ];

        $model = 'App\\Models\\NotificationMessage';

        foreach ($messages as $key => [$zh, $en]) {
            $row = NotificationMessage::updateOrCreate(
                ['user_type' => 'restaurant', 'key' => $key],
                ['message' => $zh, 'status' => 1]
            );

            $localeValues = ['zh' => $zh, 'en' => $en];
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

        $this->command?->info('哪吒商家端推送文案已补齐: '.count($messages).' 条 key (含 zh/en 译文)。');
    }
}
