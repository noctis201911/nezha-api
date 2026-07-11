<?php

namespace App\Traits;

use App\CentralLogics\Helpers;
use Exception;
use App\Models\Setting;
use App\Models\PaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Illuminate\Http\RedirectResponse;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;

trait  Processor
{
    public function response_formatter($constant, $content = null, $errors = []): array
    {
        $constant = (array)$constant;
        $constant['content'] = $content;
        $constant['errors'] = $errors;
        return $constant;
    }

    public function error_processor($validator): array
    {
        $errors = [];
        foreach ($validator->errors()->getMessages() as $index => $error) {
            $errors[] = ['error_code' => $index, 'message' => self::translate($error[0])];
        }
        return $errors;
    }

    public function translate($key)
    {
        try {
            App::setLocale('en');
            $lang_array = include(base_path('resources/lang/' . 'en' . '/lang.php'));
            $processed_key = ucfirst(str_replace('_', ' ', str_ireplace(['\'', '"', ',', ';', '<', '>', '?'], ' ', $key)));
            if (!array_key_exists($key, $lang_array)) {
                // 哪吒[复发根因修 2026-07-02]: 仅本地自动回写 en/lang.php; 生产/测试禁写(同 drift 根因); 缺失 key 仍返回 fallback
                if (app()->environment('local')) {
                    $lang_array[$key] = $processed_key;
                    $str = "<?php return " . var_export($lang_array, true) . ";";
                    file_put_contents(base_path('resources/lang/' . 'en' . '/lang.php'), $str);
                }
                $result = $processed_key;
            } else {
                $result = __('lang.' . $key);
            }
            return $result;
        } catch (\Exception $exception) {
            return $key;
        }
    }

    public function payment_config($key, $settings_type): object|null
    {
        try {
            $config = DB::table('addon_settings')->where('key_name', $key)
                ->where('settings_type', $settings_type)->first();
        } catch (Exception $exception) {
            return new Setting();
        }

        return (isset($config)) ? $config : null;
    }

    public static function getDisk()
    {
        $config=\App\CentralLogics\Helpers::get_business_settings('local_storage');

        return isset($config)?($config==0?'s3':'public'):'public';
    }
    public function file_uploader(string $dir, string $format, $image = null, $old_image = null)
    {
        // if ($image == null) return $old_image ?? 'def.png';

        // if (isset($old_image)) Storage::disk(self::getDisk())->delete($dir . $old_image);

        // $imageName = \Carbon\Carbon::now()->toDateString() . "-" . uniqid() . "." . $format;
        // if (!Storage::disk(self::getDisk())->exists($dir)) {
        //     Storage::disk(self::getDisk())->makeDirectory($dir);
        // }
        // Storage::disk(self::getDisk())->put($dir . $imageName, file_get_contents($image));

        return Helpers::update($dir,$old_image, $format,$image);
    }

    public function payment_response($payment_info, $payment_flag): Application|JsonResponse|Redirector|RedirectResponse|\Illuminate\Contracts\Foundation\Application
    {
        $payment_info = PaymentRequest::find($payment_info->id);
        $token_string = 'payment_method=' . $payment_info->payment_method . '&&attribute_id=' . $payment_info->attribute_id . '&&transaction_reference=' . $payment_info->transaction_id;
        // 哪吒安全(2026-07-11 N-11): 跳转 sink 补白名单二次校验——external_redirect_link 源头有未过滤路径(Wallet/DM callback·NZ-SEC-004 只修了 PaymentController), 挡开放跳转 + base64 token 外泄; 不安全则回退站内 payment-* 命名路由。
        if (in_array($payment_info->payment_platform, ['web', 'app']) && $payment_info['external_redirect_link'] != null && $this->nezhaIsSafeRedirect($payment_info['external_redirect_link'])) {
            return redirect($payment_info['external_redirect_link'] . '?flag=' . $payment_flag . '&&token=' . base64_encode($token_string));
        }
        return redirect()->route('payment-' . $payment_flag, ['token' => base64_encode($token_string)]);
    }

    // 哪吒安全(2026-07-11 N-11): 支付回调跳转白名单(同 PaymentController::isSafeCallback 口径)——站内相对路径或本平台 host 的 http(s); 拒外部 host/协议相对//、js:data:vbscript:、CR-LF/控制字符、超长。
    private function nezhaIsSafeRedirect($url): bool
    {
        if (!is_string($url) || $url === '' || strlen($url) > 512) {
            return false;
        }
        if (preg_match('/[\x00-\x1f\x7f]|\s/', $url)) {
            return false;
        }
        $lower = strtolower($url);
        if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'data:') || str_starts_with($lower, 'vbscript:')) {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }
        return in_array(strtolower($parts['host']), ['nezha.am', 'www.nezha.am', 'api.nezha.am'], true);
    }
}
