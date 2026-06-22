<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * 哪吒 B方案 — 商家「逾期未退款」催办邮件。
 * 引导商家尽快原路退款给顾客, 并去商家后台「订单 → 待退款」标记已退款;
 * 持续逾期将计入风控档案并可能被暂停接单。自包含简单模板。
 * 平台不碰钱(L1-1): 邮件只催办, 退款由商家自己原路退。
 */
class NezhaRefundOverdueMail extends Mailable
{
    use Queueable, SerializesModels;

    public $restaurant_name;
    public $order_id;
    public $refund_amount;
    public $overdue_label;

    public function __construct($restaurant_name, $order_id, $refund_amount, $overdue_label)
    {
        $this->restaurant_name = $restaurant_name;
        $this->order_id        = $order_id;
        $this->refund_amount   = $refund_amount;
        $this->overdue_label   = $overdue_label;
    }

    public function build()
    {
        return $this->subject(translate('哪吒外卖 · 待退款订单已逾期, 请尽快原路退款'))
            ->view('email-templates.nezha-refund-overdue', [
                'restaurant_name' => $this->restaurant_name,
                'order_id'        => $this->order_id,
                'refund_amount'   => $this->refund_amount,
                'overdue_label'  => $this->overdue_label,
            ]);
    }
}
