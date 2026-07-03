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

        // 哪吒 A3·S4②: 自助充值(预存佣金)已开时, 邮件给「去充值」直链到对账中心充值卡;
        // 未开(dormant)则不给死链, 保持原「联系客服」文案(见模板 @if($topup_open))。
        $topupOpen = \App\CentralLogics\NezhaTopup::accountOpen('deposit');
        // 邮件必须绝对 URL; APP_URL 现为 http, 强制 https 免去收件人点击时的 http→https 跳转(APP_URL 若改 https 则此替换为 no-op)。
        $topupUrl  = $topupOpen
            ? \Illuminate\Support\Str::replaceFirst('http://', 'https://', route('vendor.nezha-deposit.index', ['account' => 'deposit'])) . '#nz-topup-card'
            : null;

        return $this->subject($subject)->view('email-templates.deposit-low-balance', [
            'restaurant_name' => $this->restaurant_name,
            'balance'         => $this->balance,
            'threshold'       => $this->threshold,
            'is_negative'     => $this->is_negative,
            'topup_open'      => $topupOpen,
            'topup_url'       => $topupUrl,
        ]);
    }
}
