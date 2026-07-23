<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaOrderTgCard;
use Tests\TestCase;

class NezhaOrderTgCardZeroPiiTest extends TestCase
{
    public function test_compose_contains_zero_customer_pii_sentinels(): void
    {
        $order = (object) [
            'id' => 91001,
            'restaurant_id' => 6,
            'restaurant' => (object) ['name' => '零 PII 测试店'],
            'order_type' => 'delivery',
            'order_amount' => 8500,
            'payment_method' => 'offline_payment',
            'scheduled' => 1,
            'schedule_at' => '2026-07-24 18:30:00',
            'created_at' => '2026-07-23 11:15:00',
            'delivery_address' => json_encode([
                'address' => 'SENTINEL_ADDR',
                'latitude' => '40.99999',
                'longitude' => '44.99999',
                'contact_person_name' => 'SENTINEL_NAME',
                'contact_person_number' => 'SENTINEL_PHONE',
            ]),
            'order_note' => 'SENTINEL_NOTE',
            'payment_screenshot' => 'SENTINEL_SCREENSHOT',
            'details' => [
                (object) ['food_details' => json_encode(['name' => '烤羊肉串'], JSON_UNESCAPED_UNICODE), 'quantity' => 2],
            ],
        ];

        $text = NezhaOrderTgCard::compose($order);

        foreach ([
            'SENTINEL_ADDR',
            '40.99999',
            '44.99999',
            'SENTINEL_NAME',
            'SENTINEL_PHONE',
            'SENTINEL_NOTE',
            'SENTINEL_SCREENSHOT',
        ] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $text);
        }

        $this->assertStringContainsString('🔔 新订单 #91001', $text);
        $this->assertStringContainsString('💳 线下付款 · 凭证在后台核对', $text);
        $this->assertStringContainsString('📅 预约 07-24 18:30', $text);
    }

    public function test_switch_registry_and_prelaunch_doc_keep_card_dormant(): void
    {
        $config = require base_path('config/nezha_switches.php');
        $entry = collect($config['switches'])->firstWhere('key', 'nezha_order_tg_card_status');
        $actions = collect($config['switches'])->firstWhere('key', 'nezha_order_tg_card_actions_status');
        $docs = (string) file_get_contents(base_path('docs/PRELAUNCH_SWITCHES.md'));
        $migration = (string) file_get_contents(base_path(
            'database/migrations/2026_07_23_180000_add_nezha_order_tg_card_actions_setting.php'
        ));

        $this->assertIsArray($entry);
        $this->assertSame('D', $entry['section']);
        $this->assertSame('L1-1邻', $entry['level']);
        $this->assertSame(0, $entry['expected']);
        $this->assertSame('bool', $entry['value_type']);
        $this->assertStringContainsString('邻区·纯通知零顾客PII·不碰钱/状态', $entry['l1_clause']);
        $this->assertStringContainsString('| `nezha_order_tg_card_status` | 0 |', $docs);

        $this->assertIsArray($actions);
        $this->assertSame('D', $actions['section']);
        $this->assertSame('L1-6', $actions['level']);
        $this->assertSame(0, $actions['expected']);
        $this->assertSame('bool', $actions['value_type']);
        $this->assertStringContainsString('| `nezha_order_tg_card_actions_status` | 0 |', $docs);
        $this->assertStringContainsString("'key' => 'nezha_order_tg_card_actions_status'", $migration);
        $this->assertStringContainsString("'value' => '0'", $migration);
    }
}
