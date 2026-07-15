<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NezhaMerchantNewOrderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $orderId,
        public string $restaurantName,
        public string $orderType,
        public string $amount
    ) {}

    public function build()
    {
        return $this->subject("哪吒外卖 · 新订单 #{$this->orderId} 待处理")
            ->view('email-templates.nezha-merchant-new-order');
    }
}
