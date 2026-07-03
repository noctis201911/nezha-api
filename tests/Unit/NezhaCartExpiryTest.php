<?php

namespace Tests\Unit;

use App\CentralLogics\NezhaCartExpiry;
use Carbon\Carbon;
use Tests\TestCase;

class NezhaCartExpiryTest extends TestCase
{
    public function test_cart_expiry_uses_twenty_four_hour_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-03 12:00:00'));

        $this->assertSame(24, NezhaCartExpiry::TTL_HOURS);
        $this->assertFalse(
            NezhaCartExpiry::isExpired(Carbon::parse('2026-07-02 12:00:01'))
        );
        $this->assertTrue(
            NezhaCartExpiry::isExpired(Carbon::parse('2026-07-02 12:00:00'))
        );
    }
}
