<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * 哪吒 B方案 — 订单超时给商家的邮件提醒（自包含模板，不走 EmailTemplate 系统）。
 *
 * type:
 *   remind        -> 商家迟迟未处理，催促尽快确认/接单
 *   cancel_refund -> 订单已因超时自动取消；若顾客已付款，请按原路退还
 *   prep_overtime -> 备餐严重超时，请尽快出餐或联系顾客
 */
class NezhaOrderTimeoutMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $type;
    public int $order_id;
    public string $restaurant_name;
    public int $waited_minutes;
    public bool $paid;

    public function __construct(string $type, int $order_id, string $restaurant_name, int $waited_minutes, bool $paid = false)
    {
        $this->type            = $type;
        $this->order_id        = $order_id;
        $this->restaurant_name = $restaurant_name;
        $this->waited_minutes  = $waited_minutes;
        $this->paid            = $paid;
    }

    public function build()
    {
        $subjects = [
            'remind'        => '哪吒外卖 · 订单 #' . $this->order_id . ' 待处理提醒',
            'cancel_refund' => '哪吒外卖 · 订单 #' . $this->order_id . ' 已超时自动取消（需您退款）',
            'prep_overtime' => '哪吒外卖 · 订单 #' . $this->order_id . ' 备餐超时',
        ];
        $subject = $subjects[$this->type] ?? ('哪吒外卖 · 订单 #' . $this->order_id);

        return $this->subject($subject)->view('email-templates.nezha-order-timeout', [
            'type'            => $this->type,
            'order_id'        => $this->order_id,
            'restaurant_name' => $this->restaurant_name,
            'waited_minutes'  => $this->waited_minutes,
            'paid'            => $this->paid,
        ]);
    }
}
