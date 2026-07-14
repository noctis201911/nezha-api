<?php

namespace Tests\Unit;

use App\CentralLogics\NezhaTotp;
use PHPUnit\Framework\TestCase;

class NezhaTotpTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_code_at_matches_rfc_6238_sha1_vector_reduced_to_six_digits(): void
    {
        $this->assertSame('287082', NezhaTotp::codeAt(self::RFC_SECRET, 1));
    }

    public function test_matching_counter_preserves_verify_and_exposes_no_code(): void
    {
        $counter = (int) floor(time() / 30);
        $code = NezhaTotp::codeAt(self::RFC_SECRET, $counter);

        $this->assertTrue(NezhaTotp::verify(self::RFC_SECRET, $code));
        $this->assertSame($counter, NezhaTotp::matchingCounter(self::RFC_SECRET, $code));
        $this->assertNull(NezhaTotp::matchingCounter(self::RFC_SECRET, 'not-a-code'));
    }
}
