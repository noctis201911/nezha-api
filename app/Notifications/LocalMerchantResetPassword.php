<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * 本地生活商户面板「设置/重置登录密码」邮件通知。
 * 兼作「首次设密」（admin 建号后触发 sendResetLink）与「忘记密码」——同一链接机制。
 * 链接指向商户面板 /m/reset/{token}（api.nezha.am 同源）。
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
        $base = rtrim((string) config('app.url'), '/');
        $url  = $base . '/m/reset/' . $this->token . '?email=' . urlencode($notifiable->getEmailForPasswordReset());
        $mins = (int) config('auth.passwords.local_merchants.expire', 1440);
        $hours = max(1, intdiv($mins, 60));

        return (new MailMessage)
            ->subject('哪吒商户管理面 · 设置登录密码')
            ->greeting('您好')
            ->line('这是哪吒平台本地生活商户管理面的密码设置链接。点击下方按钮设置或重置您的登录密码。')
            ->action('设置登录密码', $url)
            ->line('链接在 ' . $hours . ' 小时内有效。若非您本人操作，请忽略本邮件，账号不会有任何变化。');
    }
}
