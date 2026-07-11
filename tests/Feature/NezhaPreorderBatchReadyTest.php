<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPreorder;
use Tests\TestCase;

/**
 * 哪吒 预约下单 M7 —— 批量「标出餐」资格谓词断言(canBatchReady·纯函数·无 DB)。
 * 端点侧(总闸门/IDOR/逐单锁 fresh 复核/通知/confirmed→handover)触 DB 留 staging。
 * 业主 2026-07-11 定:待出餐=confirmed → 标出餐 → handover(跳过 processing);转入配送不批量翻 picked_up(走逐单 Yandex)。
 */
class NezhaPreorderBatchReadyTest extends TestCase
{
    /** @test 预约 + confirmed(待出餐)→ 可批量标出餐。 */
    public function scheduled_confirmed_is_batch_ready(): void
    {
        $this->assertTrue(NezhaPreorder::canBatchReady(1, 'confirmed'));
    }

    /** @test 即时单(scheduled=0)→ 不进批量标出餐(预约专属)。 */
    public function instant_order_not_batch_ready(): void
    {
        $this->assertFalse(NezhaPreorder::canBatchReady(0, 'confirmed'));
    }

    /** @test 非 confirmed 态一律不合格:pending(未接单)/processing(备餐)/handover(已出餐)/delivered/canceled。 */
    public function non_confirmed_status_not_batch_ready(): void
    {
        foreach (['pending', 'processing', 'handover', 'picked_up', 'delivered', 'canceled', 'failed'] as $st) {
            $this->assertFalse(NezhaPreorder::canBatchReady(1, $st), "$st 不应可批量标出餐");
        }
    }
}
