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
        {approver_one_email : First super-admin email}
        {approver_two_email : Second, distinct super-admin email}
        {--reason= : Required support recovery reason}';

    protected $description = 'Audit and reset merchant 2FA with two distinct super-admin approvals';

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
            ->where('email', (string) $this->argument('approver_one_email'))
            ->first();
        $two = Admin::query()
            ->where('role_id', 1)
            ->where('email', (string) $this->argument('approver_two_email'))
            ->first();
        if (! $one || ! $two || $one->id === $two->id) {
            $this->error('Two distinct super-admin approvers are required.');

            return self::FAILURE;
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
                'approver_two_id' => $two->id,
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

        $this->info('Merchant 2FA recovery recorded; sessions revoked and re-enrollment required.');

        return self::SUCCESS;
    }
}
