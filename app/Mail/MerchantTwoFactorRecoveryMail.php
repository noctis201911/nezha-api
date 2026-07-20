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
            ->subject('哪吒商家账号：两步验证已重置')
            ->text('emails.merchant-two-factor-recovery');
    }
}
