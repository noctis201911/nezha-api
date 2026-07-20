<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExternalIdentityLoginAttemptPurgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('external_identity_login_attempts');
        Schema::dropIfExists('user_external_identities');

        Schema::create('external_identity_login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->char('state_hash', 64)->unique();
            $table->char('exchange_code_hash', 64)->nullable()->unique();
            $table->char('browser_secret_hash', 64);
            $table->string('oidc_nonce', 191)->nullable();
            $table->text('code_verifier')->nullable();
            $table->string('provider_subject', 191)->nullable();
            $table->text('provider_payload')->nullable();
            $table->unsignedBigInteger('target_user_id')->nullable();
            $table->string('status', 32)->default('initiated');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_external_identities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 32);
            $table->string('provider_subject', 191);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_command_deletes_only_expired_attempts_and_supports_dry_run(): void
    {
        $now = now();
        DB::table('external_identity_login_attempts')->insert([
            $this->attempt('expired', $now->copy()->subHour()),
            $this->attempt('active', $now->copy()->addHour()),
        ]);
        DB::table('user_external_identities')->insert([
            'user_id' => 123,
            'provider' => 'telegram',
            'provider_subject' => 'persistent-subject',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->artisan('nezha:purge-external-identity-attempts', ['--dry-run' => true])
            ->expectsOutput('[dry-run] Would delete 1 expired external identity login attempts.')
            ->assertSuccessful();
        $this->assertDatabaseCount('external_identity_login_attempts', 2);

        $this->artisan('nezha:purge-external-identity-attempts')
            ->expectsOutput('Deleted 1 expired external identity login attempts.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('external_identity_login_attempts', ['provider_subject' => 'expired']);
        $this->assertDatabaseHas('external_identity_login_attempts', ['provider_subject' => 'active']);
        $this->assertDatabaseHas('user_external_identities', ['provider_subject' => 'persistent-subject']);
    }

    public function test_command_is_scheduled_every_fifteen_minutes(): void
    {
        // Laravel 12 materializes withSchedule callbacks when a schedule
        // command resolves the console schedule, not at application boot.
        $this->artisan('schedule:list')->assertSuccessful();

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => str_contains(
                (string) $event->command,
                'nezha:purge-external-identity-attempts'
            ));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
    }

    private function attempt(string $subject, mixed $expiresAt): array
    {
        return [
            'provider' => 'telegram',
            'state_hash' => hash('sha256', 'state-'.$subject),
            'exchange_code_hash' => null,
            'browser_secret_hash' => hash('sha256', 'browser-'.$subject),
            'provider_subject' => $subject,
            'status' => 'initiated',
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
