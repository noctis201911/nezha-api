<?php

namespace Tests\Unit;

use Firebase\JWT\JWT;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use function JmesPath\search;

class DependencyCompatibilityTest extends TestCase
{
    public function test_firebase_jwt_seven_keeps_the_apple_client_secret_signature(): void
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));

        $token = JWT::encode(
            ['iss' => 'team', 'aud' => 'https://appleid.apple.com'],
            $privateKey,
            'ES256',
            'key-id'
        );

        $this->assertCount(3, explode('.', $token));
    }

    public function test_spreadsheet_patch_keeps_xlsx_round_trip_working(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'nezha-sheet-');
        $this->assertNotFalse($path);

        $spreadsheet = new Spreadsheet();
        try {
            $spreadsheet->getActiveSheet()->setCellValue('A1', 'safe export');
            (new Xlsx($spreadsheet))->save($path);

            $loaded = IOFactory::load($path);
            try {
                $this->assertSame('safe export', $loaded->getActiveSheet()->getCell('A1')->getValue());
            } finally {
                $loaded->disconnectWorksheets();
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($path);
        }
    }

    public function test_jmespath_patch_keeps_admin_order_queries_working(): void
    {
        $result = search('orders[?total > `10`].id', [
            'orders' => [
                ['id' => 1, 'total' => 8],
                ['id' => 2, 'total' => 12],
            ],
        ]);

        $this->assertSame([2], $result);
    }
}
