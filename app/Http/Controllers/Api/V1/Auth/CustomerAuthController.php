<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Models\EmailVerifications;
use App\Models\User;
use App\Models\Guest;
use Carbon\CarbonInterval;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaCartExpiry;
use Illuminate\Support\Carbon;
use App\Mail\EmailVerification;
use App\Models\BusinessSetting;
use App\CentralLogics\SMS_module;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Mail\CustomerRegistration;
use App\Models\Cart;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Modules\Gateways\Traits\SmsGateway;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Services\Auth\CustomerAuthFeatureFlags;
use App\Services\Auth\CustomerLoginFinalizer;
use App\Services\Auth\GoogleLoginService;


class CustomerAuthController extends Controller
{
    public function __construct(
        private readonly CustomerAuthFeatureFlags $authFlags,
        private readonly CustomerLoginFinalizer $loginFinalizer,
        private readonly GoogleLoginService $googleLogin,
    ) {}

    public function verify_phone_or_email(Request $request)
    {
        if (! $this->authFlags->legacySignupEnabled()) {
            return $this->authUnavailable(
                'registration_unavailable',
                'This legacy verification flow is unavailable.',
            );
        }

        $validator = Validator::make($request->all(), [
            'otp' => 'required',
            'verification_type' => 'required|in:phone,email',
            'phone' => 'required_if:verification_type,phone|min:9|max:14',
            'email' => 'required_if:verification_type,email|email',
            'login_type' => 'required|in:manual,otp',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if ($request->phone) {
            $user = User::where('phone', $request->phone)->first();
        }
        if ($request->email) {
            $user = User::where('email', $request->email)->first();
        }
        $temporaryToken = null;

        if ($user && $request->login_type == 'manual') {
            if ($request->verification_type == 'phone' && $user->is_phone_verified) {
                return response()->json([
                    'message' => translate('messages.phone_number_is_already_verified'),
                ], 200);

            }
            if ($request->verification_type == 'email' && $user->is_email_verified) {
                return response()->json([
                    'message' => translate('messages.email_number_is_already_verified'),
                ], 200);

            }

            if (env('APP_MODE') == 'test') {
                if ($request['otp'] == '123456') {
                    if ($request->verification_type == 'email') {
                        $user->is_email_verified = 1;
                    } elseif ($request->verification_type == 'phone') {
                        $user->is_phone_verified = 1;
                    }
                    $user->save();
                    $is_personal_info = 0;

                    if ($user->f_name) {
                        $is_personal_info = 1;
                    }

                    $user_email = null;
                    if ($user->email) {
                        $user_email = $user->email;
                    }
                    if (auth()->loginUsingId($user->id)) {
                        $token = $this->loginFinalizer->issue(auth()->user(), (string) $request->login_type);
                        if (isset($request['guest_id'])) {
                            $this->check_guest_cart($user, $request['guest_id']);
                        }
                    }

                    return response()->json(['token' => isset($token) ? $token : $temporaryToken, 'is_phone_verified' => 1, 'is_email_verified' => 1, 'is_personal_info' => $is_personal_info, 'is_exist_user' => null, 'login_type' => $request->login_type, 'email' => $user_email], 200);
                }

                return response()->json([
                    'message' => translate('OTP does not match'),
                ], 404);
            }

            if ($request->verification_type == 'email') {
                $data = DB::table('email_verifications')->where([
                    'email' => $request['email'],
                    'token' => $request['otp'],
                ])->first();
            } elseif ($request->verification_type == 'phone') {
                $data = DB::table('phone_verifications')->where([
                    'phone' => $request['phone'],
                    'token' => $request['otp'],
                ])->first();
            }

            if ($data) {
                if ($request->verification_type == 'email') {
                    DB::table('email_verifications')->where([
                        'email' => $request['email'],
                        'token' => $request['otp'],
                    ])->delete();

                    $user->is_email_verified = 1;
                } elseif ($request->verification_type == 'phone') {
                    DB::table('phone_verifications')->where([
                        'phone' => $request['phone'],
                        'token' => $request['otp'],
                    ])->delete();

                    $user->is_phone_verified = 1;
                }

                $user->save();
                $is_personal_info = 0;

                if ($user->f_name) {
                    $is_personal_info = 1;
                }
                $user_email = null;
                if ($user->email) {
                    $user_email = $user->email;
                }
                if ($is_personal_info == 1 && auth()->loginUsingId($user->id)) {
                    $token = $this->loginFinalizer->issue(auth()->user(), (string) $request->login_type);
                    if (isset($request['guest_id'])) {
                        $this->check_guest_cart($user, $request['guest_id']);
                    }
                }

                return response()->json(['token' => isset($token) ? $token : $temporaryToken, 'is_phone_verified' => 1, 'is_email_verified' => 1, 'is_personal_info' => $is_personal_info, 'is_exist_user' => null, 'login_type' => $request->login_type, 'email' => $user_email], 200);
            } else {
                if ($request->verification_type == 'phone') {
                    $max_otp_hit = 5;

                    $max_otp_hit_time = 60; // seconds
                    $temp_block_time = 600; // seconds

                    $verification_data = DB::table('phone_verifications')->where('phone', $request['phone'])->first();

                    if (isset($verification_data)) {
                        if (isset($verification_data->temp_block_time) && Carbon::parse($verification_data->temp_block_time)->DiffInSeconds() <= $temp_block_time) {
                            $time = $temp_block_time - Carbon::parse($verification_data->temp_block_time)->DiffInSeconds();

                            $errors = [];
                            array_push($errors, ['code' => 'otp_block_time',
                                'message' => translate('messages.please_try_again_after_').CarbonInterval::seconds($time)->cascade()->forHumans(),
                            ]);

                            return response()->json([
                                'errors' => $errors,
                            ], 405);
                        }

                        if ($verification_data->is_temp_blocked == 1 && Carbon::parse($verification_data->updated_at)->DiffInSeconds() >= $max_otp_hit_time) {
                            DB::table('phone_verifications')->updateOrInsert(['phone' => $request['phone']],
                                [
                                    'otp_hit_count' => 0,
                                    'is_temp_blocked' => 0,
                                    'temp_block_time' => null,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                        }

                        if ($verification_data->otp_hit_count >= $max_otp_hit && Carbon::parse($verification_data->updated_at)->DiffInSeconds() < $max_otp_hit_time && $verification_data->is_temp_blocked == 0) {

                            DB::table('phone_verifications')->updateOrInsert(['phone' => $request['phone']],
                                [
                                    'is_temp_blocked' => 1,
                                    'temp_block_time' => now(),
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            $errors = [];
                            array_push($errors, ['code' => 'otp_temp_blocked', 'message' => translate('messages.Too_many_attemps')]);

                            return response()->json([
                                'errors' => $errors,
                            ], 405);
                        }
                    }

                    DB::table('phone_verifications')->updateOrInsert(['phone' => $request['phone']],
                        [
                            'otp_hit_count' => DB::raw('otp_hit_count + 1'),
                            'updated_at' => now(),
                            'temp_block_time' => null,
                        ]);
                }

                return response()->json([
                    'message' => translate('OTP does not match'),
                ], 404);
            }
        }
        if ($request->login_type == 'otp') {
            $data = DB::table('phone_verifications')->where([
                'phone' => $request['phone'],
                'token' => $request['otp'],
            ])->first();

            if ($data) {
                if ($user && $user->is_phone_verified == 0) {
                    $is_exist_user = $this->exist_user($user);
                    $user_email = null;
                    if ($user->email) {
                        $user_email = $user->email;
                    }

                    return response()->json(['token' => $temporaryToken, 'is_phone_verified' => 1, 'is_email_verified' => 1, 'is_personal_info' => 1, 'is_exist_user' => $is_exist_user, 'login_type' => 'otp', 'email' => $user_email], 200);
                } elseif ($user && $user->is_phone_verified == 1) {
                    DB::table('phone_verifications')->where([
                        'phone' => $request['phone'],
                        'token' => $request['otp'],
                    ])->delete();
                    $is_personal_info = 0;
                    if ($user->f_name) {
                        $is_personal_info = 1;
                    }
                    $user_email = null;
                    if ($user->email) {
                        $user_email = $user->email;
                    }
                    if ($is_personal_info == 1 && auth()->loginUsingId($user->id)) {
                        $token = $this->loginFinalizer->issue(auth()->user(), (string) $request->login_type);
                        if (isset($request['guest_id'])) {
                            $this->check_guest_cart($user, $request['guest_id']);
                        }
                    }

                    return response()->json(['token' => isset($token) ? $token : $temporaryToken, 'is_phone_verified' => 1, 'is_email_verified' => 1, 'is_personal_info' => $is_personal_info, 'is_exist_user' => null, 'login_type' => $request->login_type, 'email' => $user_email], 200);
                } else {
                    $user = new User;
                    $user->phone = $request['phone'];
                    $user->password = bcrypt($request['phone']);
                    $user->is_phone_verified = 1;
                    $user->login_medium = 'otp';
                    $user->save();

                    $this->refer_code_check($user);

                    $is_personal_info = 0;
                    $user_email = null;
                    if ($user->email) {
                        $user_email = $user->email;
                    }

                    return response()->json(['token' => $temporaryToken, 'is_phone_verified' => 1, 'is_email_verified' => 1, 'is_personal_info' => $is_personal_info, 'is_exist_user' => null, 'login_type' => 'otp', 'email' => $user_email], 200);
                }
            } else {
                return response()->json([
                    'message' => translate('OTP does not match'),
                ], 404);
            }
        }

        return response()->json([
            'message' => translate('messages.not_found'),
        ], 404);

    }

    public function firebase_auth_verify(Request $request)
    {
        $otpEnabled = (int) (BusinessSetting::query()
            ->where('key', 'otp_login_status')

            ->value('value') ?? 0) === 1;
        if (! $otpEnabled) {
            return $this->authUnavailable('otp_login_unavailable', 'OTP login is unavailable.');
        }

        $validator = Validator::make($request->all(), [
            'session_info' => 'required',
            'phone' => 'required',
            'otp' => 'required',
            'login_type' => 'required|in:manual,otp',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if ($request->login_type === 'manual' && ! $this->authFlags->legacySignupEnabled()) {
            return $this->authUnavailable(
                'registration_unavailable',
                'This legacy account-verification flow is unavailable.',
            );
        }

        $webApiKey = BusinessSetting::where('key', 'firebase_web_api_key')->first()?->value ?? '';

        $response = Http::post('https://identitytoolkit.googleapis.com/v1/accounts:signInWithPhoneNumber?key='.$webApiKey, [
            'sessionInfo' => $request->session_info,
            'phoneNumber' => $request->phone,
            'code' => $request->otp,
        ]);

        $responseData = $response->json();

        if (isset($responseData['error'])) {
            $errors = [];
            $errors[] = ['code' => '403', 'message' => $responseData['error']['message']];

            return response()->json(['errors' => $errors], 403);
        }

        $user = User::Where(['phone' => $request->phone])->first();

        $temporaryToken = null;

        if (isset($user) && $request->login_type == 'manual') {

            if ($user->is_phone_verified) {
                return response()->json([
                    'message' => translate('messages.phone_number_is_already_verified'),
                ], 200);
            }
            $user->is_phone_verified = 1;
            $user->save();
            $is_personal_info = 0;

            if ($user->f_name) {
                $is_personal_info = 1;
            }
            $user_email = null;
            if ($user->email) {
                $user_email = $user->email;
            }
            if ($is_personal_info == 1 && auth()->loginUsingId($user->id)) {
                $token = $this->loginFinalizer->issue(auth()->user(), (string) $request->login_type);
                if (isset($request['guest_id'])) {
                    $this->check_guest_cart($user, $request['guest_id']);
                }
            }

            return response()->json(['token' => isset($token) ? $token : $temporaryToken, 'is_phone_verified' => 1, 'is_email_verified' => 1, 'is_personal_info' => $is_personal_info, 'is_exist_user' => null, 'login_type' => $request->login_type, 'email' => $user_email], 200);

        }

        if ($request->login_type == 'otp') {
            if (! $user) {
                return $this->authUnavailable(
                    'registration_unavailable',
                    'Phone verification cannot create a new account.',
                );
            }
            if ((int) $user->is_phone_verified !== 1) {
                return $this->authUnavailable(
                    'legacy_link_required',
                    'This legacy account requires recovery before it can be used.',
                );
            }

            $token = $this->loginFinalizer->issue($user, 'otp');
            if (isset($request['guest_id'])) {
                $this->check_guest_cart($user, $request['guest_id']);
            }

            return response()->json([
                'token' => $token,
                'is_phone_verified' => 1,
                'is_email_verified' => (int) $user->is_email_verified,
                'is_personal_info' => $user->f_name ? 1 : 0,
                'is_exist_user' => null,
                'login_type' => 'otp',
                'email' => $user->email,
            ], 200);
        }

        return response()->json([
            'message' => translate('messages.not_found'),
        ], 404);
    }

    public function guest_request(Request $request)
    {
        $guest = new Guest();
        // 哪吒安全(H2): 游客 id 改为 JS 安全整数范围内的随机大数, 杜绝顺序枚举
        // (原自增 id 被当凭证→可枚举他人游客订单/购物车). 上限 < 2^53 保证前端 Number/JSON 不丢精度.
        // incrementing=false: 让 save() 走普通 insert 保留我们设的 id(否则 lastInsertId 在显式 id 下会被覆盖).
        $guest->incrementing = false;
        do {
            $guest->id = random_int(100000000000000, 8000000000000000);
        } while (Guest::where('id', $guest->id)->exists());
        $guest->ip_address = $request->ip();
        $guest->fcm_token = $request->fcm_token;

        if ($guest->save()) {
            return response()->json([
                'message' => translate('messages.guest_verified'),
                'guest_id' => $guest->id,
            ], 200);
        }

        return response()->json([
            'message' => translate('messages.failed')
        ], 404);
    }
    public function register(Request $request)
    {
        if (! $this->authFlags->legacySignupEnabled()) {
            return $this->authUnavailable(
                'registration_unavailable',
                'Registration is temporarily unavailable. Please use the unified sign-in flow.',
            );
        }

        // 哪吒[防脚本注册 2026-07-01]: 顾客注册 reCAPTCHA v3 校验(复用 web 同一把 key, 后台 recaptcha 开启时生效)。
        // 软策略防误伤真实用户: token 缺失->放行(靠 throttle:signup 限流兜底, 不锁死装拦截/隐私插件用户);
        // token 在但 Google 判无效/低分(<0.5)->拒; Google 不可达->fail-open 放行(防 Google 抽风锁死注册)。
        $nz_recaptcha = \App\CentralLogics\Helpers::get_business_settings('recaptcha');
        if (isset($nz_recaptcha) && ($nz_recaptcha['status'] ?? 0) == 1) {
            $nz_token = $request->input('g-recaptcha-response');
            if (! empty($nz_token)) {
                try {
                    $nz_gr = \Illuminate\Support\Facades\Http::asForm()->timeout(8)->post('https://www.google.com/recaptcha/api/siteverify', [
                        'secret' => $nz_recaptcha['secret_key'] ?? '',
                        'response' => $nz_token,
                        'remoteip' => $request->ip(),
                    ]);
                    if ($nz_gr->successful()) {
                        $nz_body = (array) $nz_gr->json();
                        $nz_score = (float) ($nz_body['score'] ?? 0);
                        $nz_ok = (($nz_body['success'] ?? false) === true) && ($nz_score >= 0.5);
                        \Illuminate\Support\Facades\Log::info('nz_signup_recaptcha', ['ok' => $nz_ok, 'score' => $nz_score, 'ip' => $request->ip()]);
                        if (! $nz_ok) {
                            return response()->json(['errors' => [['code' => 'recaptcha', 'message' => translate('Verification failed, please try again.')]]], 403);
                        }
                    }
                    // Google 不可达/5xx -> fail-open(放行)
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('nz_signup_recaptcha_error', ['msg' => $e->getMessage()]);
                }
            } else {
                \Illuminate\Support\Facades\Log::info('nz_signup_recaptcha', ['ok' => 'absent_failopen', 'ip' => $request->ip()]);
            }
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'email' => 'unique:users',
            'phone' => 'required|unique:users',
            'password' => ['required', Password::min(8)],

        ], [
            'name.required' => translate('The name field is required.'),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $ref_by = null;
        // Save point to refeer
        if ($request->ref_code) {
            $ref_status = BusinessSetting::where('key', 'ref_earning_status')->first()?->value;
            if ($ref_status != '1') {
                return response()->json(['errors' => Helpers::error_formater('ref_code', translate('messages.referer_disable'))], 403);
            }

            $referar_user = User::where('ref_code', '=', $request->ref_code)->first();
            if (! $referar_user || ! $referar_user->status) {
                return response()->json(['errors' => Helpers::error_formater('ref_code', translate('messages.referer_code_not_found'))], 405);
            }

            if (WalletTransaction::where('reference', $request->phone)->first()) {
                return response()->json(['errors' => Helpers::error_formater('phone', translate('Referrer code already used'))], 203);
            }

            $this->sentPushNotificationToReferrer($referar_user);

            $ref_by = $referar_user->id;
        }

        $name = $request->name;
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        $user = User::create([
            'f_name' => $firstName,
            'l_name' => $lastName,
            'email' => $request->email,
            'phone' => $request->phone,
            'ref_by' => $ref_by,
            'password' => bcrypt($request->password),
        ]);
        $user->ref_code = Helpers::generate_referer_code($user);
        $user->save();

        $token = $this->loginFinalizer->issue($user, 'manual');

        $login_settings = array_column(BusinessSetting::whereIn('key', ['manual_login_status', 'otp_login_status', 'social_login_status', 'google_login_status', 'facebook_login_status', 'apple_login_status', 'email_verification_status', 'phone_verification_status',
        ])->get(['key', 'value'])->toArray(), 'value', 'key');
        $firebase_otp_verification = BusinessSetting::where('key', 'firebase_otp_verification')->first()?->value ?? 0;
        $phone = 1;
        $mail = 1;
        if (isset($login_settings['phone_verification_status']) && $login_settings['phone_verification_status'] == 1) {
            $phone = 0;
            if (! $firebase_otp_verification) {
                $otp_interval_time = 60; // seconds
                $verification_data = DB::table('phone_verifications')->where('phone', $request['phone'])->first();

                if (isset($verification_data) && Carbon::parse($verification_data->updated_at)->DiffInSeconds() < $otp_interval_time) {
                    $time = $otp_interval_time - Carbon::parse($verification_data->updated_at)->DiffInSeconds();
                    $errors = [];
                    array_push($errors, ['code' => 'otp', 'message' => translate('messages.please_try_again_after_').$time.' '.translate('messages.seconds')]);

                    return response()->json([
                        'errors' => $errors,
                    ], 405);
                }

                $otp = rand(100000, 999999);
                if (env('APP_MODE') == 'test') {
                    $otp = '123456';
                }
                DB::table('phone_verifications')->updateOrInsert(['phone' => $request['phone']],
                    [
                        'token' => $otp,
                        'otp_hit_count' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                $published_status = 0;
                $payment_published_status = config('get_payment_publish_status');
                if (isset($payment_published_status[0]['is_published'])) {
                    $published_status = $payment_published_status[0]['is_published'];
                }

                if ($published_status == 1) {
                    $response = SmsGateway::send($request['phone'], $otp);
                } else {
                    $response = SMS_module::send($request['phone'], $otp);
                }

                $token = null;
                if (env('APP_MODE') != 'test' && $response !== 'success') {
                    $errors = [];
                    array_push($errors, ['code' => 'otp', 'message' => translate('messages.failed_to_send_sms')]);

                    return response()->json([
                        'errors' => $errors,
                    ], 405);
                }
            }

        } elseif (isset($login_settings['email_verification_status']) && $login_settings['email_verification_status'] == 1) {
            $mail = 0;
            $otp = rand(100000, 999999);
            if (env('APP_MODE') == 'test') {
                $otp = '123456';
            }
            DB::table('email_verifications')->updateOrInsert(['email' => $request['email']],
                [
                    'token' => $otp,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            try {
                $mailResponse = null;
                $mail_status = Helpers::get_mail_status('registration_otp_mail_status_user');

                if (config('mail.status') && $mail_status == '1') {

                    Mail::to($request['email'])->send(new EmailVerification($otp, $request->name));
                    $mailResponse = 'success';
                }
            } catch (\Exception $ex) {
                info($ex->getMessage());
                $mailResponse = null;
            }
            $token = null;
            if (env('APP_MODE') != 'test' && $mailResponse !== 'success') {
                $errors = [];
                array_push($errors, ['code' => 'otp', 'message' => translate('messages.failed_to_send_mail')]);

                return response()->json([
                    'errors' => $errors,
                ], 405);
            }
        }

        try {
            $notification_status = Helpers::getNotificationStatusData('customer', 'customer_registration');

            if ($notification_status?->mail_status == 'active' && config('mail.status') && $request->email && Helpers::get_mail_status('registration_mail_status_user') == '1') {
                Mail::to($request->email)->send(new CustomerRegistration($request->name));
            }
        } catch (\Exception $ex) {
            info($ex->getMessage());
        }

        $user_email = null;
        if ($user->email) {
            $user_email = $user->email;
        }

        return response()->json(['token' => $token, 'is_phone_verified' => $phone, 'is_email_verified' => $mail, 'is_personal_info' => 1, 'is_exist_user' => null, 'login_type' => 'manual', 'email' => $user_email], 200);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login_type' => 'required|in:manual,otp,social',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $login_settings = array_column(BusinessSetting::whereIn('key', ['manual_login_status', 'otp_login_status', 'social_login_status', 'google_login_status', 'facebook_login_status', 'apple_login_status', 'email_verification_status', 'phone_verification_status',
        ])->get(['key', 'value'])->toArray(), 'value', 'key');

        if ($request->login_type == 'manual') {
            if ((int) ($login_settings['manual_login_status'] ?? 0) !== 1) {
                return $this->authUnavailable('manual_login_unavailable', 'Password login is unavailable.');
            }

            $validator = Validator::make($request->all(), [
                'email_or_phone' => 'required',
                'password' => 'required|min:6',
                'field_type' => 'required|in:phone,email',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }

            $request_data = [
                'email_or_phone' => $request->email_or_phone,
                'password' => $request->password,
                'guest_id' => $request->guest_id,
                'field_type' => $request->field_type,
            ];

            return $this->manual_login($request_data);
        }

        if ($request->login_type == 'otp') {
            if ((int) ($login_settings['otp_login_status'] ?? 0) !== 1) {
                return $this->authUnavailable('otp_login_unavailable', 'OTP login is unavailable.');
            }

            $validator = Validator::make($request->all(), [
                'phone' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }

            if (isset($request['verified']) && $request['verified']) {

                if (! $request->otp) {
                    $errors = [];
                    array_push($errors, ['code' => 'otp', 'message' => translate('messages.otp_id_required')]);

                    return response()->json([
                        'errors' => $errors,
                    ], 403);
                }
                $request_data = [
                    'phone' => $request->phone,
                    'guest_id' => $request->guest_id,
                    'otp' => $request->otp,
                    'verified' => $request['verified'] ?? 'default',
                ];

                return $this->otp_login($request_data);
            }

            $request_data = [
                'phone' => $request->phone,
            ];

            return $this->send_otp($request_data);
        }

        if ($request->login_type == 'social') {
            if ((int) ($login_settings['social_login_status'] ?? 0) !== 1) {
                return $this->authUnavailable('social_login_unavailable', 'Social login is unavailable.');
            }

            $validator = Validator::make($request->all(), [
                'token' => 'required',
                'unique_id' => 'required',
                'email' => 'required_if:medium,google,facebook',
                'medium' => 'required|in:google,facebook,apple',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)], 403);
            }

            if ($request['medium'] === 'google') {
                if ((int) ($login_settings['google_login_status'] ?? 0) !== 1) {
                    return $this->authUnavailable('google_login_unavailable', 'Google login is unavailable.');
                }
                if ((int) $request->access_token === 1) {
                    return $this->authUnavailable(
                        'google_credential_required',
                        'Please use the Google identity sign-in button.',
                    );
                }

                return response()->json(
                    $this->googleLogin->authenticateCredential((string) $request['token'])
                );
            }
            if ($request['medium'] === 'facebook'
                && (int) ($login_settings['facebook_login_status'] ?? 0) !== 1) {
                return $this->authUnavailable('facebook_login_unavailable', 'Facebook login is unavailable.');
            }
            if ($request['medium'] === 'apple'
                && (int) ($login_settings['apple_login_status'] ?? 0) !== 1) {
                return $this->authUnavailable('apple_login_unavailable', 'Apple login is unavailable.');
            }

            $client = new Client(['connect_timeout' => 5, 'timeout' => 8]); // 超时保护:防Google/FB验证接口卡死致登录无限挂起
            $token = $request['token'];
            $email = $request['email'];
            $unique_id = $request['unique_id'];
            try {
                if ($request['medium'] == 'facebook') {
                    $res = $client->request('GET', 'https://graph.facebook.com/'.$unique_id.'?access_token='.$token.'&&fields=name,email');
                    $data = json_decode($res->getBody()->getContents(), true);
                } elseif ($request['medium'] == 'apple') {
                    if ($request->has('verified')) {
                        $user = EmailVerifications::where('token', $unique_id)->first();
                        $data = [
                            'email' => $user->email,
                        ];
                    } else {
                        $apple_login = \App\Models\BusinessSetting::where(['key' => 'apple_login'])->first();
                        if ($apple_login) {
                            $apple_login = json_decode($apple_login->value)[0];
                        }
                        $teamId = $apple_login->team_id;
                        $keyId = $apple_login->key_id;
                        $sub = $apple_login->client_id;
                        $aud = 'https://appleid.apple.com';
                        $iat = strtotime('now');
                        $exp = strtotime('+60days');
                        $keyContent = file_get_contents(storage_path('app/public/apple-login/'.$apple_login->service_file));

                        $token = JWT::encode([
                            'iss' => $teamId,
                            'iat' => $iat,
                            'exp' => $exp,
                            'aud' => $aud,
                            'sub' => $sub,
                        ], $keyContent, 'ES256', $keyId);
                        $redirect_uri = $apple_login->redirect_url ?? 'www.example.com/apple-callback';
                        $res = Http::asForm()->post('https://appleid.apple.com/auth/token', [
                            'grant_type' => 'authorization_code',
                            'code' => $unique_id,
                            'redirect_uri' => $redirect_uri,
                            'client_id' => $sub,
                            'client_secret' => $token,
                        ]);

                        $claims = explode('.', $res['id_token'])[1];
                        $data = json_decode(base64_decode($claims), true);
                    }

                }
            } catch (\Exception $e) {
                return response()->json(['error' => 'wrong credential.', 'message' => $e->getMessage()], 403);
            }
            if (! isset($claims)) {

                if (strcmp($email, $data['email']) != 0 && (! isset($data['id']) && ! isset($data['kid']))) {
                    return response()->json(['error' => translate('messages.email_does_not_match')], 403);
                }
            } else {
                $email = $data['email'];
            }

            $request_data = [
                'token' => $token,
                'email' => $email,
                'unique_id' => $unique_id,
                'medium' => $request['medium'],
                'verified' => $request['verified'] ?? 'default',
                'guest_id' => $request['guest_id'],
            ];

            return $this->social_login($data, $request_data);
        }

        $errors = [];
        array_push($errors, ['code' => 'auth-001', 'message' => translate('messages.User_Not_Found!!!')]);

        return response()->json([
            'errors' => $errors,
        ], 401);

    }

    private function manual_login($request_data)
    {

        if ($request_data['field_type'] == 'email') {
            $data = [
                'email' => $request_data['email_or_phone'],
                'password' => $request_data['password'],
            ];
        } elseif ($request_data['field_type'] == 'phone') {
            $data = [
                'phone' => $request_data['email_or_phone'],
                'password' => $request_data['password'],
            ];
        }

        if (auth()->attempt($data)) {
            if (! auth()->user()->status) {
                $errors = [];
                array_push($errors, ['code' => 'auth-003', 'message' => translate('messages.your_account_is_blocked')]);

                return response()->json([
                    'errors' => $errors,
                ], 403);
            }
            $user = auth()->user();
            $token = $this->loginFinalizer->issue($user, 'manual');

            $this->refer_code_check($user);
            if (isset($request_data['guest_id'])) {
                $this->check_guest_cart($user, $request_data['guest_id']);
            }

            $is_personal_info = 0;
            if ($user->f_name) {
                $is_personal_info = 1;
            }

            $user->login_medium = 'manual';
            $user->save();

            $user_email = null;
            if ($user->email) {
                $user_email = $user->email;
            }

            return response()->json(['token' => $token, 'is_phone_verified' => 1, 'is_email_verified' => 1, 'is_personal_info' => $is_personal_info, 'is_exist_user' => null, 'login_type' => 'manual', 'email' => $user_email], 200);
        } else {
            $errors = [];
            array_push($errors, ['code' => 'auth-001', 'message' => translate('User_credential_does_not_match')]);

            return response()->json([
                'errors' => $errors,
            ], 401);
        }
    }

    private function otp_login($request_data)
    {
        $data = DB::table('phone_verifications')->where([
            'phone' => $request_data['phone'],
            'token' => $request_data['otp'],
        ])->first();

        if ($data) {
            $user = User::where('phone', $request_data['phone'])->first();


            if (! $user || (int) $user->is_phone_verified !== 1) {
                DB::table('phone_verifications')->where([
                    'phone' => $request_data['phone'],
                    'token' => $request_data['otp'],
                ])->delete();

                return $this->authUnavailable(
                    $user ? 'legacy_link_required' : 'registration_unavailable',
                    $user
                        ? 'This legacy account requires recovery before it can be used.'
                        : 'Phone verification cannot create a new account.',
                );
            }

            if (! $user->status) {
                $errors = [];
                array_push($errors, ['code' => 'auth-003', 'message' => translate('messages.your_account_is_blocked')]);

                return response()->json([
                    'errors' => $errors,
                ], 403);
            }

            $this->refer_code_check($user);

            $user->login_medium = 'otp';
            $user->is_phone_verified = 1;
            $user->save();

            $is_personal_info = 0;
            if ($user->f_name) {
                $is_personal_info = 1;
            }

            DB::table('phone_verifications')->where([
                'phone' => $request_data['phone'],
                'token' => $request_data['otp'],
            ])->delete();

            $token = $this->loginFinalizer->issue($user, 'otp');
            if (isset($request_data['guest_id'])) {
                $this->check_guest_cart($user, $request_data['guest_id']);
            }

            $user_email = null;
            if ($user->email) {
                $user_email = $user->email;
            }

            return response()->json(['token' => $token, 'is_phone_verified' => 1, 'is_email_verified' => (int) $user->is_email_verified, 'is_personal_info' => $is_personal_info, 'is_exist_user' => null, 'login_type' => 'otp', 'email' => $user_email], 200);
        }

        return response()->json([
            'message' => translate('OTP does not match'),
        ], 404);

    }

    /**
     * Nezha: Google 整页跳转登录(ux_mode:redirect)第一步——校验 id_token + 一次性短码暂存登录结果。
     * 替代弹窗模式(iOS Safari 把弹窗开成无 opener 新标签, 2FA 后 postMessage 回传不到原窗 -> origin 400)。
     * 安全点: token 不进 URL/历史, 浏览器只拿到短码, 凭短码再换真 token(见 social_exchange)。
     * 入参 credential=Google id_token(必填), guest_id(可选)。不碰现有弹窗 login, 纯新增。
     */
    public function google_redirect_login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'credential' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        try {
            $payload = $this->googleLogin->authenticateCredential((string) $request->credential);
        } catch (\App\Exceptions\CustomerLoginException $error) {
            throw $error;
        } catch (\Throwable $e) {
            return response()->json(['errors' => [['code' => 'credential', 'message' => 'Invalid Google credential']]], 403);
        }

        $code = Str::random(48);
        Cache::put('gauth_'.$code, $payload, 60); // 60 秒短码, 单次使用(social_exchange 取出即删)

        return response()->json(['code' => $code], 200);
    }

    /**
     * Nezha: Google 整页跳转登录第二步——用一次性短码换回登录结果。
     * 返回 social_login 原始 payload(token/is_personal_info/is_exist_user/email 或 errors)。
     * Cache::pull 取出即删, 单次有效; 无效/过期返 403。
     */
    public function social_exchange(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $payload = Cache::pull('gauth_' . $request->code);
        if (!$payload) {
            return response()->json(['errors' => [['code' => 'code', 'message' => 'Login code expired or already used']]], 403);
        }
        return response()->json($payload, 200);
    }

    private function social_login($data, $request_data)
    {

        $user = User::where('email', $data['email'])->first();
        $is_exist_user = null;

        if ($request_data['medium'] === 'google') {
            if (! filter_var($data['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return $this->authUnavailable(
                    'google_email_unverified',
                    'Google did not confirm this email address.',
                );
            }

            if ($user && (int) $user->is_email_verified !== 1 && $user->email_verified_at === null) {
                return response()->json([
                    'status' => 'legacy_link_required',
                    'errors' => [[
                        'code' => 'legacy_link_required',
                        'message' => 'Use the existing password or contact support to recover this account.',
                    ]],
                ], 409);
            }

            if (! $user && ! $this->authFlags->googleRegistrationEnabled()) {
                return $this->authUnavailable(
                    'registration_unavailable',
                    'New account registration is not available yet.',
                );
            }
        }

        // Nezha security (#2): remember if this email already belonged to a PRE-EXISTING account
        // whose email was never verified. Such a password may have been set by someone who
        // pre-registered this email without owning it. Used below to invalidate it on social merge.
        $preexistId = $user?->id;
        $preexistUnverified = $user && (int) $user->is_email_verified === 0;

        if ($user && $preexistUnverified && ! $this->authFlags->legacySignupEnabled()) {
            return response()->json([
                'status' => 'legacy_link_required',
                'errors' => [[
                    'code' => 'legacy_link_required',
                    'message' => 'Use the existing password or contact support to recover this account.',
                ]],
            ], 409);
        }

        if (! $user && ! $this->authFlags->legacySignupEnabled()) {
            return $this->authUnavailable(
                'registration_unavailable',
                'New account registration is not available yet.',
            );
        }

        if ($user && $request_data['verified'] == 'default' && $user->is_email_verified == 0) {
            $is_exist_user = $this->exist_user($user);
            $temporaryToken = null;
            if ($request_data['medium'] == 'apple') {
                DB::table('email_verifications')->updateOrInsert(['email' => $data['email']],
                    [
                        'token' => $request_data['unique_id'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
            $user_email = null;
            if ($user->email) {
                $user_email = $user->email;
            }

            return response()->json(['token' => $temporaryToken, 'is_phone_verified' => 1, 'is_email_verified' => 1, 'is_personal_info' => 1, 'is_exist_user' => $is_exist_user, 'login_type' => 'social', 'email' => $user_email], 200);
        }

        if (($user && $request_data['verified'] == 'no') || (! $user && $request_data['verified'] == 'default')) {

            if (strcmp($request_data['email'], $data['email']) === 0) {
                if ($request_data['medium'] != 'apple') {
                    if (! isset($data['id']) && ! isset($data['kid']) && ! isset($data['sub'])) {
                        return response()->json(['error' => 'wrong credential.'], 403);
                    }
                    $pk = isset($data['id']) ? $data['id'] : (isset($data['kid']) ? $data['kid'] : $data['sub']);
                }
                try {
                    if (($user && $request_data['verified'] == 'no')) {
                        $user->email = null;
                        $user->save();
                    }

                    $user = new User;
                    $user->email = $data['email'];
                    $user->login_medium = $request_data['medium'];
                    $user->temp_token = $request_data['unique_id'];
                    // Nezha: fill name from social provider so is_personal_info=1 -> token issued -> skip name/phone form
                    if (isset($data['given_name'])) {
                        $user->f_name = $data['given_name'];
                        $user->l_name = $data['family_name'] ?? '';
                    } elseif (isset($data['name'])) {
                        $user->f_name = $data['name'];
                    }
                    if ($request_data['medium'] != 'apple') {
                        $user->social_id = $pk;
                    }
                    $user->save();
                } catch (\Throwable $e) {
                    return response()->json(['error' => 'wrong credential.', 'message' => $e->getMessage()], 403);
                }
            }
        }

        if ($request_data['medium'] == 'apple') {
            DB::table('email_verifications')->where([
                'email' => $data['email'],
                'token' => $request_data['unique_id'],
            ])->delete();
        }

        if (auth()->loginUsingId($user->id)) {
            if (! auth()->user()->status) {
                $errors = [];
                array_push($errors, ['code' => 'auth-003', 'message' => translate('messages.your_account_is_blocked')]);

                return response()->json([
                    'errors' => $errors,
                ], 403);
            }
            $this->refer_code_check($user);

            $user->login_medium = $request_data['medium'];
            $user->is_email_verified = 1;
            $user->save();

            // Nezha security (#2): account pre-hijacking defense. If we just merged a verified social
            // login into a pre-existing, never-verified account, invalidate any password it carried -
            // a pre-registrant could have set it without owning this email. The real owner logs in via
            // the social provider, or uses "forgot password" (email is now verified) to set a new one.
            if ($request_data['medium'] !== 'google' && $preexistUnverified && $user->id === $preexistId && ! empty($user->password)) {
                $user->password = bcrypt(Str::random(40));
                $user->save();
            }

            // Nezha: backfill name for social users missing f_name (covers half-created rows from earlier attempts)
            if (! $user->f_name) {
                if (isset($data['given_name'])) {
                    $user->f_name = $data['given_name'];
                    $user->l_name = $data['family_name'] ?? '';
                    $user->save();
                } elseif (isset($data['name'])) {
                    $user->f_name = $data['name'];
                    $user->save();
                }
            }
            $is_personal_info = 0;
            if ($user->f_name) {
                $is_personal_info = 1;
            }

            $token = null;
            if ($is_personal_info == 1 && auth()->loginUsingId($user->id)) {
                $token = $this->loginFinalizer->issue(auth()->user(), (string) $request_data['medium']);
                if (isset($request_data['guest_id'])) {
                    $this->check_guest_cart($user, $request_data['guest_id']);
                }
            }
            $user_email = null;
            if ($user->email) {
                $user_email = $user->email;
            }

            return response()->json(['token' => $token, 'is_phone_verified' => 1, 'is_email_verified' => 1, 'is_personal_info' => $is_personal_info, 'is_exist_user' => $is_exist_user, 'login_type' => 'social', 'email' => $user_email], 200);
        } else {
            $errors = [];
            array_push($errors, ['code' => 'auth-001', 'message' => 'Unauthorized.']);

            return response()->json([
                'errors' => $errors,
            ], 401);
        }
    }

    private function verification_check($request_data){
        $firebase_otp_verification = BusinessSetting::where('key', 'firebase_otp_verification')->first()?->value??0;
        if(!$firebase_otp_verification)
        {
            $otp_interval_time= 60; //seconds

            $verification_data= DB::table('phone_verifications')->where('phone', $request_data['phone'])->first();

            if(isset($verification_data) &&  Carbon::parse($verification_data->updated_at)->DiffInSeconds() < $otp_interval_time){

                $time= $otp_interval_time - Carbon::parse($verification_data->updated_at)->DiffInSeconds();
                $errors = [];
                array_push($errors, ['code' => 'otp', 'message' =>  translate('messages.please_try_again_after_').$time.' '.translate('messages.seconds')]);

                return $errors;
            }

            $otp = rand(100000, 999999);
            if(env('APP_MODE') == 'test'){
                $otp = '123456';
            }
            DB::table('phone_verifications')->updateOrInsert(['phone' => $request_data['phone']],
                [
                    'token' => $otp,
                    'otp_hit_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $response= null;


            $published_status = 0;
            $payment_published_status = config('get_payment_publish_status');
            if (isset($payment_published_status[0]['is_published'])) {
                $published_status = $payment_published_status[0]['is_published'];
            }

            if($published_status == 1){
                $response = SmsGateway::send($request_data['phone'],$otp);
            }else{
                $response = SMS_module::send($request_data['phone'],$otp);
            }

           if((env('APP_MODE') != 'test') && $response !== 'success')
           {
               $errors = [];
               array_push($errors, ['code' => 'otp', 'message' => translate('messages.failed_to_send_sms')]);
               return $errors;
           }
        }
        return true;
    }

    private function refer_code_check($user){
        if($user->ref_code == null && isset($user->id)){
            $ref_code = Helpers::generate_referer_code($user);

            DB::table('users')->where('id', $user->id)->update(['ref_code' => $ref_code]);
        }
        return true;
    }

    private function check_guest_cart($user, $guest_id){
        if($guest_id  && isset($user->id)){

            NezhaCartExpiry::expiredQuery(Cart::where('user_id', $guest_id)->where('is_guest', 1))->delete();
            NezhaCartExpiry::expiredQuery(Cart::where('user_id', $user->id)->where('is_guest', 0))->delete();

            $userStoreIds = Cart::where('user_id', $guest_id)
                ->where('is_guest', 1)
                ->join('food', 'carts.item_id', '=', 'food.id')
                ->pluck('food.restaurant_id')
                ->toArray();

            if(Cart::where('user_id', $guest_id)->where('is_guest', 1)->count() > 0){
                Cart::where('user_id', $user->id)
                    ->where('is_guest', 0)
                    ->whereHas('item', function ($query) use ($userStoreIds) {
                        $query->whereNotIn('restaurant_id', $userStoreIds);
                    })
                    ->delete();

                Cart::where('user_id', $guest_id)->where('is_guest', 1)->update(['user_id' => $user->id,'is_guest' => 0]);
            }

        }
        return true;
    }

    private function exist_user($exist_user){
        return [
            'id' => $exist_user->id,
            'name' => $exist_user->f_name.' '.$exist_user->l_name,
            'image' => $exist_user->image_full_url
        ];
    }

    public function update_info(Request $request)
    {
        if (! $this->authFlags->legacySignupEnabled()) {
            return $this->authUnavailable(
                'registration_unavailable',
                'This legacy account-completion flow is unavailable.',
            );
        }

        $rules = [
            'name' => 'required',
            'login_type' => 'required|in:otp,social',
            'phone' => 'required|min:9|max:14',
            'email' => 'required|email',
        ];

        if ($request->login_type == 'social') {
            $rules['phone'] .= '|unique:users,phone';
        }

        if ($request->login_type == 'otp') {
            $rules['email'] .= '|unique:users,email';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $ref_by = null;
        // Save point to refeer
        if ($request->ref_code) {
            $ref_status = BusinessSetting::where('key', 'ref_earning_status')->first()?->value;
            if ($ref_status != '1') {
                return response()->json(['errors' => Helpers::error_formater('ref_code', translate('messages.referer_disable'))], 403);
            }

            $referar_user = User::where('ref_code', '=', $request->ref_code)->first();
            if (! $referar_user || ! $referar_user->status) {
                return response()->json(['errors' => Helpers::error_formater('ref_code', translate('messages.referer_code_not_found'))], 405);
            }

            if (WalletTransaction::where('reference', $request->phone)->first()) {
                return response()->json(['errors' => Helpers::error_formater('phone', translate('Referrer code already used'))], 203);
            }

            $this->sentPushNotificationToReferrer($referar_user);
            $ref_by = $referar_user->id;
        }

        $name = $request->name;
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        if ($request->login_type == 'otp') {
            $user = User::where(['phone' => $request->phone])->first();
        } else {
            $user = User::where(['email' => $request->email])->first();
        }
        if (! $user) {
            return response()->json([
                'message' => translate('messages.user_not_found'),
            ], 404);
        }
        $user->f_name = $firstName;
        $user->l_name = $lastName;
        $user->email = $request->email ?? $user->email;
        $user->phone = $request->phone ?? $user->phone;
        $user->ref_by = $ref_by;
        $user->save();
        $token = null;
        if (auth()->loginUsingId($user->id)) {
            $token = $this->loginFinalizer->issue(auth()->user(), (string) $request->login_type);

            if (isset($request['guest_id'])) {
                $this->check_guest_cart($user, $request['guest_id']);
            }
        }

        $user_email = null;
        if ($user->email) {
            $user_email = $user->email;
        }

        return response()->json(['token' => $token, 'is_phone_verified' => 1, 'is_email_verified' => 1, 'is_personal_info' => 1, 'is_exist_user' => null, 'login_type' => $request->login_type, 'email' => $user_email], 200);
    }

    private function send_otp($request_data){

        $user = User::where('phone', $request_data['phone'])->first();
        if($user  && !$user->status)
        {
            $errors = [];
            array_push($errors, ['code' => 'auth-003', 'message' => translate('messages.your_account_is_blocked')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }

        $verification_check = $this->verification_check($request_data);
        if(is_array($verification_check))
        {
            return response()->json([
                'errors' => $verification_check
            ], 405);
        }
        $temporaryToken = null;
        return response()->json(['token' => $temporaryToken, 'is_phone_verified'=>0, 'is_email_verified'=>1, 'is_personal_info' => 1, 'is_exist_user' => null, 'login_type' => 'otp', 'email' => null], 200);
    }

    private function sentPushNotificationToReferrer($referar_user){
        $message =Helpers::getPushNotificationMessage(status:'customer_new_referral_join',userType: 'user' , lang:$referar_user?->cm_firebase_token, userName: $referar_user?->f_name.' '.$referar_user?->l_name);
            if ($message && isset($referar_user?->cm_firebase_token)) {
                $data= Helpers::makeDataForPushNotification(title:translate('Your_Referral_Code_Has_Been_Used'), message:$message,orderId: '', type: 'referral_code', orderStatus: '');
                Helpers::send_push_notif_to_device($referar_user?->cm_firebase_token, $data);
                Helpers::insertDataOnNotificationTable($data , 'user', $referar_user->id);
            }
        return true;
    }

    private function authUnavailable(string $code, string $message)
    {
        return response()->json([
            'status' => $code,
            'errors' => [[
                'code' => $code,
                'message' => $message,
            ]],
        ], 403);
    }

}
