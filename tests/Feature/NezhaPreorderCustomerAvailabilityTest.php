<?php

namespace Tests\Feature;

use App\CentralLogics\NezhaPreorder;
use Tests\TestCase;

class NezhaPreorderCustomerAvailabilityTest extends TestCase
{
    public function test_hard_blocker_always_resolves_to_closed(): void
    {
        $this->assertSame(
            NezhaPreorder::CUSTOMER_CLOSED,
            NezhaPreorder::resolveCustomerAvailability(true, true, true)
        );
    }

    public function test_instant_wins_when_both_modes_are_available(): void
    {
        $this->assertSame(
            NezhaPreorder::CUSTOMER_INSTANT,
            NezhaPreorder::resolveCustomerAvailability(false, true, true)
        );
    }

    public function test_preorder_requires_a_real_selectable_point(): void
    {
        $this->assertSame(
            NezhaPreorder::CUSTOMER_PREORDER,
            NezhaPreorder::resolveCustomerAvailability(false, false, true)
        );
        $this->assertSame(
            NezhaPreorder::CUSTOMER_CLOSED,
            NezhaPreorder::resolveCustomerAvailability(false, false, false)
        );
    }

    public function test_database_ranks_map_to_the_public_contract(): void
    {
        $this->assertSame(NezhaPreorder::CUSTOMER_INSTANT, NezhaPreorder::customerAvailabilityFromRank(3));
        $this->assertSame(NezhaPreorder::CUSTOMER_PREORDER, NezhaPreorder::customerAvailabilityFromRank(2));
        $this->assertSame(NezhaPreorder::CUSTOMER_CLOSED, NezhaPreorder::customerAvailabilityFromRank(1));
        $this->assertSame(NezhaPreorder::CUSTOMER_CLOSED, NezhaPreorder::customerAvailabilityFromRank(null));
    }
}
