<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MerchantTwoFactorRecoveryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function build()
    {
        return $this
            ->subject('Nezha merchant account security recovery')
            ->text('emails.merchant-two-factor-recovery');
    }
}
