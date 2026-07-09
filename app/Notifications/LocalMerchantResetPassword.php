<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * 本地生活商户面板「设置/重置登录密码」邮件通知。
 * 兼作「首次设密」（admin 建号后触发 sendResetLink）与「忘记密码」——同一链接机制。
 * 链接指向商户面板 /m/reset/{token}（api.nezha.am 同源）。
 *
 * 🔴 走哪吒自有品牌中文邮件视图 emails.local-merchant-reset，不用 Laravel 默认 markdown——
 *    默认 markdown 的头部/落款取 config('app.name')=stackfood…（StackFood 默认名）会泄露且英文混排。
 */
class LocalMerchantResetPassword extends Notification
{
    use Queueable;

    public string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        // 强制 https（站点走 https，config('app.url') 仍是 http 会让邮件链接走一次 301 跳转）
        $base  = 'https://' . preg_replace('#^https?://#i', '', rtrim((string) config('app.url'), '/'));
        $url   = $base . '/m/reset/' . $this->token . '?email=' . urlencode($notifiable->getEmailForPasswordReset());
        $mins  = (int) config('auth.passwords.local_merchants.expire', 1440);
        $hours = max(1, intdiv($mins, 60));

        return (new MailMessage)
            ->subject('哪吒商户管理面 · 设置登录密码')
            ->view('emails.local-merchant-reset', ['url' => $url, 'hours' => $hours]);
    }
}
