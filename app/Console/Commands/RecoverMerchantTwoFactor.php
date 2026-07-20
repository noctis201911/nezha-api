<?php

namespace App\Console\Commands;

use App\CentralLogics\NezhaMerchantTwoFactor;
use App\Mail\MerchantTwoFactorRecoveryMail;
use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class RecoverMerchantTwoFactor extends Command
{
    protected $signature = 'nezha:merchant-2fa-recover
        {actor_type : owner or employee}
        {actor_id : Merchant actor numeric ID}
        {approver_email : Approving super-admin email}
        {--second-approver= : Optional second, distinct super-admin email}
        {--reason= : Required support recovery reason}';

    protected $description = 'Audit and reset merchant 2FA with super-admin approval';

    public function handle(): int
    {
        $type = (string) $this->argument('actor_type');
        $id = filter_var($this->argument('actor_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = trim((string) $this->option('reason'));
        if (! in_array($type, [NezhaMerchantTwoFactor::OWNER, NezhaMerchantTwoFactor::EMPLOYEE], true)
            || ! $id || $reason === '') {
            $this->error('actor_type, positive actor_id, and --reason are required.');

            return self::INVALID;
        }

        $one = Admin::query()
            ->where('role_id', 1)
            ->where('email', (string) $this->argument('approver_email'))
            ->first();
        if (! $one) {
            $this->error('An existing super-admin approver is required.');

            return self::FAILURE;
        }

        // A second approver stays supported but optional: the platform runs with a
        // single super-admin, and a dual-approval gate that can never be satisfied
        // is not a control. Reason + audit event + notification remain mandatory.
        $two = null;
        $secondEmail = trim((string) $this->option('second-approver'));
        if ($secondEmail !== '') {
            $two = Admin::query()->where('role_id', 1)->where('email', $secondEmail)->first();
            if (! $two || (int) $two->id === (int) $one->id) {
                $this->error('The optional second approver must be a distinct super-admin.');

                return self::FAILURE;
            }
        }

        $actor = NezhaMerchantTwoFactor::actor($type, (int) $id);
        if (! $actor) {
            $this->error('Merchant actor not found.');

            return self::FAILURE;
        }

        $recovered = NezhaMerchantTwoFactor::revokeActor(
            $actor,
            'support_recovery',
            [
                'initiator_type' => 'support_cli',
                'approver_one_id' => $one->id,
                'approver_two_id' => $two?->id,
                'reason' => $reason,
                'metadata' => ['channel' => 'support'],
            ],
            true
        );

        if (! $recovered->email) {
            $this->error('Recovery completed and sessions were revoked, but the actor has no notification address.');

            return self::FAILURE;
        }

        try {
            Mail::to($recovered->getRawOriginal('email'))->send(new MerchantTwoFactorRecoveryMail);
        } catch (\Throwable) {
            $this->error('Recovery completed and sessions were revoked, but the no-secret notification failed. Repeat the approved recovery command to retry notification.');

            return self::FAILURE;
        }

        $this->info('Merchant 2FA recovery recorded; two-factor authentication was disabled and all sessions were revoked.');

        return self::SUCCESS;
    }
}
