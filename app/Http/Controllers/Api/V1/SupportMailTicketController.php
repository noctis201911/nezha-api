<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\ContactMessage;
use App\Models\MailConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class SupportMailTicketController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'email' => 'nullable|email|max:191|required_without:phone',
            'phone' => 'nullable|string|max:40|required_without:email',
            'category' => 'required|string|max:80',
            'category_label' => 'nullable|string|max:120',
            'description' => 'required|string|max:5000',
            'order_id' => 'nullable|string|max:80',
            'order_summary' => 'nullable|string|max:2000',
            'attachments.*' => 'nullable|image|max:5120',
        ], [
            'name.required' => '请留下您的称呼',
            'email.required_without' => '请至少留下邮箱或电话',
            'email.email' => '邮箱格式不正确',
            'phone.required_without' => '请至少留下邮箱或电话',
            'category.required' => '请选择问题类型',
            'description.required' => '请描述遇到的问题',
            'attachments.*.image' => '附件仅支持图片',
            'attachments.*.max' => '单张图片不能超过 5MB',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $categoryLabel = $request->input('category_label') ?: $request->input('category');
        $orderId = $request->input('order_id');
        $subject = '客服工单 - ' . $categoryLabel . ($orderId ? ' - 订单#' . $orderId : '');
        $submittedAt = now()->format('Y-m-d H:i:s');

        $messageLines = [
            '问题类型: ' . $categoryLabel,
            '客户称呼: ' . $request->input('name'),
            '客户邮箱: ' . ($request->input('email') ?: '未填写'),
            '客户电话: ' . ($request->input('phone') ?: '未填写'),
            '提交时间: ' . $submittedAt,
        ];

        if ($orderId) {
            $messageLines[] = '关联订单: #' . $orderId;
        }

        if ($request->filled('order_summary')) {
            $messageLines[] = '';
            $messageLines[] = '订单摘要:';
            $messageLines[] = $request->input('order_summary');
        }

        $messageLines[] = '';
        $messageLines[] = '问题描述:';
        $messageLines[] = $request->input('description');

        $attachments = $request->file('attachments', []);
        if (!empty($attachments)) {
            $messageLines[] = '';
            $messageLines[] = '附件: ' . count($attachments) . ' 张图片，已随邮件附上。';
        }

        $body = implode("\n", $messageLines);

        $contact = ContactMessage::create([
            'name' => $request->input('name'),
            'email' => $request->input('email') ?: 'no-email@nezha.am',
            'mobile_number' => $request->input('phone'),
            'subject' => $subject,
            'message' => $body,
            'seen' => 0,
            'status' => 1,
        ]);

        $recipient = $this->supportMailbox();
        $businessName = BusinessSetting::where('key', 'business_name')->value('value') ?: '哪吒外卖';

        if (!$recipient) {
            return response()->json([
                'message' => '工单已记录，但客服通知暂时异常。我们已保留您的信息，请稍后再试。',
                'ticket_id' => $contact->id,
            ], 500);
        }

        $mailer = $this->configureMailTransport($recipient, $businessName);

        if (!$mailer) {
            return response()->json([
                'message' => '工单已记录，但客服通知暂时异常。我们已保留您的信息，请稍后再试。',
                'ticket_id' => $contact->id,
            ], 500);
        }

        try {
            Mail::mailer($mailer)->raw($body, function ($mail) use ($recipient, $subject, $attachments, $request, $businessName) {
                $mail->to($recipient, $businessName)->subject($subject);

                if ($request->filled('email')) {
                    $mail->replyTo($request->input('email'), $request->input('name'));
                }

                foreach ($attachments as $file) {
                    $mail->attach($file->getRealPath(), [
                        'as' => $file->getClientOriginalName(),
                        'mime' => $file->getMimeType(),
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            info('Support mail ticket send failed: ' . $exception->getMessage());

            return response()->json([
                'message' => '工单已记录，但客服通知暂时异常。我们已保留您的信息，请稍后再试。',
                'ticket_id' => $contact->id,
            ], 500);
        }

        return response()->json([
            'message' => '已提交，我们会通过邮箱或电话尽快联系您。',
            'ticket_id' => $contact->id,
        ], 200);
    }

    private function supportMailbox(): ?string
    {
        $email = BusinessSetting::where('key', 'email_address')->value('value')
            ?: BusinessSetting::where('key', 'email')->value('value')
            ?: config('mail.from.address');

        $email = is_string($email) ? trim($email) : null;

        return $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function configureMailTransport(string $recipient, string $businessName): ?string
    {
        $mailConfig = MailConfig::withoutGlobalScopes()
            ->whereNotNull('host')
            ->where('host', '!=', '')
            ->latest('id')
            ->first();

        if ($mailConfig) {
            $driver = $mailConfig->driver ?: 'smtp';
            $fromAddress = filter_var($mailConfig->email, FILTER_VALIDATE_EMAIL) ? $mailConfig->email : $recipient;

            config([
                'mail.default' => $driver,
                "mail.mailers.{$driver}" => [
                    'transport' => $driver,
                    'host' => $mailConfig->host,
                    'port' => $mailConfig->port,
                    'encryption' => $mailConfig->encryption ?: null,
                    'username' => $mailConfig->username,
                    'password' => $mailConfig->password,
                    'timeout' => null,
                    'auth_mode' => null,
                ],
                'mail.from.address' => $fromAddress,
                'mail.from.name' => $businessName,
            ]);

            $this->refreshMailManager($driver);

            return $driver;
        }

        $mailer = config('mail.default') ?: env('MAIL_MAILER');
        $host = config('mail.mailers.smtp.host') ?: env('MAIL_HOST');

        if (!$mailer && $host) {
            $mailer = 'smtp';
        }

        if (!$mailer) {
            return null;
        }

        config([
            'mail.default' => $mailer,
            'mail.from.address' => config('mail.from.address') ?: env('MAIL_FROM_ADDRESS') ?: $recipient,
            'mail.from.name' => config('mail.from.name') ?: env('MAIL_FROM_NAME') ?: $businessName,
        ]);

        if ($mailer === 'smtp') {
            $host = $host ?: config('mail.mailers.smtp.host');

            if (!$host) {
                return null;
            }

            config([
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => $host,
                'mail.mailers.smtp.port' => config('mail.mailers.smtp.port') ?: env('MAIL_PORT') ?: 587,
                'mail.mailers.smtp.encryption' => config('mail.mailers.smtp.encryption') ?: env('MAIL_ENCRYPTION') ?: 'tls',
                'mail.mailers.smtp.username' => config('mail.mailers.smtp.username') ?: env('MAIL_USERNAME'),
                'mail.mailers.smtp.password' => config('mail.mailers.smtp.password') ?: env('MAIL_PASSWORD'),
            ]);
        }

        if ($mailer === 'sendmail') {
            if (!is_executable('/usr/sbin/sendmail') && !is_executable('/usr/lib/sendmail')) {
                return null;
            }

            $this->refreshMailManager($mailer);

            return $mailer;
        }

        $this->refreshMailManager($mailer);

        return $mailer;
    }

    private function refreshMailManager(string $mailer): void
    {
        $manager = app('mail.manager');

        if (method_exists($manager, 'setDefaultDriver')) {
            $manager->setDefaultDriver($mailer);
        }

        if (method_exists($manager, 'forgetMailers')) {
            $manager->forgetMailers();
            return;
        }

        if (method_exists($manager, 'purge')) {
            $manager->purge($mailer);
        }
    }
}
