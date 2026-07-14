<?php

namespace Tests\Unit;

use App\CentralLogics\NezhaUsdtAddress;
use PHPUnit\Framework\TestCase;

class NezhaUsdtAddressTest extends TestCase
{
    private const TRON_USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    private const BSC_USDT_CONTRACT = '0x55d398326f99059fF775485246999027B3197955';

    public function test_accepts_tron_base58check_address_and_preserves_case(): void
    {
        $result = NezhaUsdtAddress::inspect(self::TRON_USDT_CONTRACT, 'TRC20');

        $this->assertTrue($result['valid']);
        $this->assertSame(NezhaUsdtAddress::TRC20, $result['network']);
        $this->assertSame(self::TRON_USDT_CONTRACT, $result['normalized']);
    }

    public function test_rejects_tron_address_with_bad_checksum(): void
    {
        $result = NezhaUsdtAddress::inspect(
            substr(self::TRON_USDT_CONTRACT, 0, -1).'u',
            'TRC20'
        );

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_tron_checksum', $result['error']);
    }

    public function test_tron_comparison_is_case_sensitive(): void
    {
        $mutated = substr_replace(self::TRON_USDT_CONTRACT, 'n', 3, 1);

        $this->assertFalse(NezhaUsdtAddress::equals(self::TRON_USDT_CONTRACT, $mutated, 'TRC20'));
    }

    public function test_accepts_and_normalizes_bep20_address(): void
    {
        $result = NezhaUsdtAddress::inspect(self::BSC_USDT_CONTRACT, 'BSC');

        $this->assertTrue($result['valid']);
        $this->assertSame(NezhaUsdtAddress::BEP20, $result['network']);
        $this->assertSame(strtolower(self::BSC_USDT_CONTRACT), $result['normalized']);
        $this->assertTrue(NezhaUsdtAddress::equals(
            self::BSC_USDT_CONTRACT,
            strtolower(self::BSC_USDT_CONTRACT),
            'BEP20'
        ));
    }

    public function test_rejects_zero_and_malformed_bep20_addresses(): void
    {
        $this->assertFalse(NezhaUsdtAddress::isValid('0x'.str_repeat('0', 40), 'BEP20'));
        $this->assertFalse(NezhaUsdtAddress::isValid('0x1234', 'BEP20'));
        $this->assertFalse(NezhaUsdtAddress::isValid(self::BSC_USDT_CONTRACT, 'unknown'));
    }

    public function test_fingerprint_is_network_scoped_and_canonical(): void
    {
        $mixed = NezhaUsdtAddress::fingerprint(self::BSC_USDT_CONTRACT, 'BEP20');
        $lower = NezhaUsdtAddress::fingerprint(strtolower(self::BSC_USDT_CONTRACT), 'BSC');

        $this->assertSame($mixed, $lower);
        $this->assertNotSame(
            $mixed,
            NezhaUsdtAddress::fingerprint(self::TRON_USDT_CONTRACT, 'TRC20')
        );
    }

    public function test_network_column_mapping_is_closed_to_supported_chains(): void
    {
        $this->assertSame('usdt_address', NezhaUsdtAddress::columnForNetwork('TRON'));
        $this->assertSame('usdt_bep20_address', NezhaUsdtAddress::columnForNetwork('BSC'));
        $this->assertNull(NezhaUsdtAddress::columnForNetwork('ERC20'));
    }
}
