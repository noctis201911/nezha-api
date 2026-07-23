<?php

namespace App\Mail;

use App\Models\BusinessSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomerEmailVerificationCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        private readonly string $code,
        private readonly string $copyLocale = 'zh-CN',
        private readonly int $ttlSeconds = 600,
    ) {}

    public function build(): self
    {
        $copy = $this->copy();

        return $this
            ->subject($copy['subject'])
            ->view('emails.customer-email-verification')
            ->with([
                'code' => $this->code,
                'title' => $copy['title'],
                'body' => $copy['body'],
                'expiry' => $copy['expiry'],
                'safety' => $copy['safety'],
                'brand' => (string) (BusinessSetting::where('key', 'business_name')->value('value') ?: '哪吒外卖'),
                'htmlLang' => $copy['html_lang'],
            ]);
    }

    private function copy(): array
    {
        $minutes = max(1, (int) ceil($this->ttlSeconds / 60));

        if (str_starts_with(strtolower($this->copyLocale), 'hy')) {
            return [
                'html_lang' => 'hy',
                'subject' => 'Մուտքի հաստատման կոդ',
                'title' => 'Հաստատեք ձեր էլ. փոստը',
                'body' => 'Դուք մուտք եք գործում կամ հաշիվ եք ստեղծում: Օգտագործեք ստորև նշված կոդը՝ շարունակելու համար։',
                'expiry' => "Կոդը վավեր է {$minutes} րոպե։",
                'safety' => 'Եթե սա դուք չեք եղել, պարզապես անտեսեք այս նամակը։',
            ];
        }

        if (str_starts_with(strtolower($this->copyLocale), 'en')) {
            return [
                'html_lang' => 'en',
                'subject' => 'Your sign-in verification code',
                'title' => 'Verify your email',
                'body' => 'You are signing in or creating an account. Use the code below to continue.',
                'expiry' => "This code expires in {$minutes} ".($minutes === 1 ? 'minute.' : 'minutes.'),
                'safety' => 'If you did not request this, you can safely ignore this email.',
            ];
        }

        return [
            'html_lang' => 'zh-CN',
            'subject' => '您的登录验证码',
            'title' => '验证您的邮箱',
            'body' => '您正在登录或创建账号，请使用下方验证码继续。',
            'expiry' => "验证码 {$minutes} 分钟内有效。",
            'safety' => '如果不是您本人操作，请忽略这封邮件。',
        ];
    }
}
