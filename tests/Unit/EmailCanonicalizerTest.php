<?php

namespace Tests\Unit;

use App\Services\Auth\EmailCanonicalizer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmailCanonicalizerTest extends TestCase
{
    #[DataProvider('validAddresses')]
    public function test_it_canonicalizes_supported_addresses(string $input, string $expected): void
    {
        $this->assertSame($expected, (new EmailCanonicalizer)->canonicalize($input));
    }

    public static function validAddresses(): array
    {
        return [
            'trims and folds case' => [' Owner@Example.COM ', 'owner@example.com'],
            'keeps plus tags' => ['User+orders@example.com', 'user+orders@example.com'],
        ];
    }

    #[DataProvider('invalidAddresses')]
    public function test_it_rejects_unsupported_addresses(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EmailCanonicalizer)->canonicalize($input);
    }

    public static function invalidAddresses(): array
    {
        return [
            'missing separator' => ['not-an-email'],
            'unicode local part' => ['用戶@example.com'],
            'too long' => [str_repeat('a', 180).'@example.com'],
        ];
    }
}
