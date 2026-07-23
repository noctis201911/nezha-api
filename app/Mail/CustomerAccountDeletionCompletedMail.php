<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomerAccountDeletionCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $completedAt) {}

    public function build(): self
    {
        return $this
            ->subject('哪吒外卖：账号注销已完成 / Nezha.am')
            ->view('emails.customer-account-deletion-completed')
            ->with(['completedAt' => $this->completedAt]);
    }
}
