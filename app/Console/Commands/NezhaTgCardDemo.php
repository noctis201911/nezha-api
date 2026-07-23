<?php

namespace App\Console\Commands;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaOrderTgCard;
use Illuminate\Console\Command;

/**
 * 向业主/指定 chat 发送零 PII 构造卡片；不读真实订单、不写 cards 表。
 */
class NezhaTgCardDemo extends Command
{
    protected $signature = 'nezha:tg-card-demo {chat_id? : Telegram chat id；省略则用 nezha_risk_admin_chat_id}';

    protected $description = '发送外卖 TG P2.1 零 PII 构造演示卡片（不读真实订单、不写 cards 表）';

    public function handle(): int
    {
        $chatId = trim((string) ($this->argument('chat_id')
            ?: Helpers::get_business_settings('nezha_risk_admin_chat_id', false)));
        if ($chatId === '') {
            $this->error('未提供 chat_id，且 nezha_risk_admin_chat_id 未配置。');

            return self::FAILURE;
        }

        $order = (object) [
            'id' => 260723000001,
            'restaurant_id' => 0,
            'restaurant' => (object) ['name' => '北方烧烤（演示）'],
            'order_type' => 'delivery',
            'order_amount' => 8500,
            'payment_method' => 'cash_on_delivery',
            'scheduled' => 0,
            'schedule_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'details' => [
                (object) ['food_details' => json_encode(['name' => '烤羊肉串'], JSON_UNESCAPED_UNICODE), 'quantity' => 4],
                (object) ['food_details' => json_encode(['name' => '烤茄子'], JSON_UNESCAPED_UNICODE), 'quantity' => 1],
                (object) ['food_details' => json_encode(['name' => '拉瓦什'], JSON_UNESCAPED_UNICODE), 'quantity' => 2],
            ],
        ];

        $text = NezhaOrderTgCard::compose($order)."\n⚠️ 演示卡片 · 构造数据";
        if (! NezhaOrderTgCard::sendDemo($chatId, $text)) {
            $this->error('演示卡片发送失败。');

            return self::FAILURE;
        }

        $this->info('演示卡片已发送。');

        return self::SUCCESS;
    }
}
