<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * 哪吒 B方案 组4 — 商家预存佣金低额/欠款邮件提醒.
 * 自包含简单模板(不走 EmailTemplate 系统)。
 */
class DepositLowBalanceMail extends Mailable
{
    use Queueable, SerializesModels;

    public $restaurant_name;
    public $balance;
    public $threshold;
    public $is_negative;

    public function __construct($restaurant_name, $balance, $threshold)
    {
        $this->restaurant_name = $restaurant_name;
        $this->balance = $balance;
        $this->threshold = $threshold;
        $this->is_negative = $balance < 0;
    }

    public function build()
    {
        $subject = $this->is_negative
            ? translate('哪吒外卖 · 预存佣金已欠款, 请尽快充值')
            : translate('哪吒外卖 · 预存佣金余额不足提醒');

        return $this->subject($subject)->view('email-templates.deposit-low-balance', [
            'restaurant_name' => $this->restaurant_name,
            'balance'         => $this->balance,
            'threshold'       => $this->threshold,
            'is_negative'     => $this->is_negative,
        ]);
    }
}
