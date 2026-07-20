<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NezhaMerchantPasswordResetSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private string|false $previousAppMode;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('password_resets')) {
            Schema::create('password_resets', function (Blueprint $table): void {
                $table->string('email')->index();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
                $table->unsignedTinyInteger('otp_hit_count')->default(0);
                $table->boolean('is_blocked')->default(false);
                $table->boolean('is_temp_blocked')->default(false);
                $table->timestamp('temp_block_time')->nullable();
                $table->string('created_by', 50)->nullable()->default('user');
            });
        }
        if (! Schema::hasColumn('vendors', 'auth_token')) {
            Schema::table('vendors', function (Blueprint $table): void {
                $table->string('auth_token', 191)->nullable();
            });
        }

        $this->previousAppMode = getenv('APP_MODE');
        putenv('APP_MODE=production');
        DB::table('vendors')->where('id', 1)->update([
            'email' => 'merchant-reset@example.test',
            'password' => Hash::make('Old-Horse-9!'),
            'two_factor_enabled' => true,
            'auth_generation' => 8,
            'auth_token' => 'stale-reset-token',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->previousAppMode === false) {
            putenv('APP_MODE');
        } else {
            putenv('APP_MODE='.$this->previousAppMode);
        }

        parent::tearDown();
    }

    public function test_expired_app_reset_code_cannot_change_password(): void
    {
        $this->storeReset('7744', now()->subSeconds(601));

        $this->putJson('/api/v1/auth/vendor/reset-password', $this->payload('7744'))
            ->assertStatus(400)
            ->assertJsonPath('errors.0.code', 'invalid');

        $vendor = DB::table('vendors')->where('id', 1)->first();
        $this->assertTrue(Hash::check('Old-Horse-9!', $vendor->password));
        $this->assertSame(8, (int) $vendor->auth_generation);
        $this->assertSame('stale-reset-token', $vendor->auth_token);
    }

    public function test_active_app_reset_lockout_is_rechecked_on_submit(): void
    {
        $this->storeReset('7744', now(), [
            'otp_hit_count' => 5,
            'is_temp_blocked' => true,
            'temp_block_time' => now(),
        ]);

        $this->putJson('/api/v1/auth/vendor/reset-password', $this->payload('7744'))
            ->assertStatus(405)
            ->assertJsonPath('errors.0.code', 'otp_temp_blocked');

        $this->assertTrue(Hash::check(
            'Old-Horse-9!',
            DB::table('vendors')->where('id', 1)->value('password')
        ));
    }

    public function test_fresh_app_reset_code_is_one_time_and_revokes_all_authentication(): void
    {
        $this->storeReset('7744', now());

        $this->putJson('/api/v1/auth/vendor/reset-password', $this->payload('7744'))
            ->assertOk();

        $vendor = DB::table('vendors')->where('id', 1)->first();
        $this->assertTrue(Hash::check('New-Horse-9!Secure', $vendor->password));
        $this->assertSame(9, (int) $vendor->auth_generation);
        $this->assertNull($vendor->auth_token);
        $this->assertSame(0, DB::table('password_resets')->where('email', 'merchant-reset@example.test')->count());

        $this->putJson('/api/v1/auth/vendor/reset-password', $this->payload('7744'))
            ->assertStatus(400)
            ->assertJsonPath('errors.0.code', 'invalid');
    }

    private function storeReset(string $token, $createdAt, array $overrides = []): void
    {
        DB::table('password_resets')->insert(array_merge([
            'email' => 'merchant-reset@example.test',
            'token' => $token,
            'created_at' => $createdAt,
            'otp_hit_count' => 0,
            'is_blocked' => false,
            'is_temp_blocked' => false,
            'temp_block_time' => null,
            'created_by' => 'vendor',
        ], $overrides));
    }

    private function payload(string $token): array
    {
        return [
            'email' => 'merchant-reset@example.test',
            'reset_token' => $token,
            'password' => 'New-Horse-9!Secure',
            'confirm_password' => 'New-Horse-9!Secure',
        ];
    }
}
