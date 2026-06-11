<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Mail\UserPasswordResetMail;
use App\Models\BusinessSetting;
use App\Models\PasswordReset;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use Illuminate\Support\Carbon;
use App\CentralLogics\SMS_module;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Modules\Gateways\Traits\SmsGateway;

class PasswordResetController extends Controller
{
    public function resetPasswordRequest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'verification_method' => 'required|in:phone,email',
            'phone' => 'required_if:verification_method,phone',
            'email' => 'required_if:verification_method,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $identifier = $request->verification_method;
        $value = $request->$identifier;

        $firebaseOtpVerification = BusinessSetting::where('key', 'firebase_otp_verification')->value('value') ?? 0;

        $customer = User::where($identifier, $value)->first();

        if (!$customer) {
            return response()->json(['errors' => [
                ['code' => 'not-found', 'message' => translate('messages.user_not_found!')]
            ]], 404);
        }

        if ($firebaseOtpVerification) {
            return response()->json(['message' => translate('messages.otp_sent_successfull')], 200);
        }

        $otpInterval = 60; // seconds
        $now = now();

        $resetData = DB::table('password_resets')
            ->where($identifier, $value)
            ->first();

        if ($resetData && Carbon::parse($resetData->created_at)->diffInSeconds($now) < $otpInterval) {
            $wait = $otpInterval - Carbon::parse($resetData->created_at)->diffInSeconds($now);
            return response()->json([
                'errors' => [
                    ['code' => 'otp', 'message' => translate('messages.please_try_again_after_') . $wait . ' ' . translate('messages.seconds')]
                ]
            ], 405);
        }

        // Generate token
        $token = env('APP_MODE') === 'test' ? '123456' : rand(100000, 999999);

        DB::table('password_resets')->updateOrInsert(
            [$identifier => $value],
            ['token' => $token, 'created_at' => $now]
        );

        if (env('APP_MODE') === 'test') {
            return response()->json(['message' => translate('messages.Use_test_OTP')], 200);
        }

        // Send via SMS
        $isSmsActive = Setting::whereJsonContains('live_values->status', '1')
            ->where('settings_type', 'sms_config')
            ->exists();

        if ($isSmsActive && $identifier === 'phone') {
            $response = null;

            $publishedStatus = config('get_payment_publish_status')[0]['is_published'] ?? 0;

            if ($publishedStatus == 1) {
                $response = SmsGateway::send($value, $token);
            } else {
                $response = SMS_module::send($value, $token);
            }

            if ($response === 'success') {
                return response()->json(['message' => translate('messages.Otp_Successfully_Sent_To_Your_Phone')], 200);
            } else {
                return response()->json(['errors' => [
                    ['code' => 'otp', 'message' => translate('messages.failed_to_send_sms')]
                ]], 403);
            }
        }

        // Send via Mail
        if (config('mail.status') && $identifier === 'email') {
            try {
                $mailResponse = null;
                if (Helpers::get_mail_status('forget_password_mail_status_user') == '1' && $customer->email) {
                    Mail::to($customer?->getRawOriginal('email'))->send(new UserPasswordResetMail($token, $customer->f_name));
                    $mailResponse = 'success';
                }

                if ($mailResponse === 'success') {
                    return response()->json(['message' => translate('messages.Otp_Successfully_Sent_To_Your_Mail')], 200);
                }
            } catch (\Throwable $th) {
                info($th->getMessage());
            }

            return response()->json(['errors' => [
                ['code' => 'otp', 'message' => translate('messages.failed_to_send_mail')]
            ]], 403);
        }

        // Fallback
        return response()->json(['errors' => [
            ['code' => 'otp', 'message' => translate('messages.failed_to_send_otp')]
        ]], 403);
    }

    public function verifyToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reset_token' => 'required',
            'verification_method' => 'required|in:phone,email',
            'phone' => 'nullable|required_if:verification_method,phone|min:9',
            'email' => 'nullable|required_if:verification_method,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $identifier = $request->verification_method;
        $value = $request->$identifier;

        $user = User::where($identifier, $value)->first();
        if (!$user) {
            return response()->json(['errors' => [
                ['code' => 'not-found', 'message' => translate('Phone_number_not_found!')]
            ]], 404);
        }

        if (env('APP_MODE') === 'test') {
            if ($request->reset_token === '123456') {
                return response()->json(['message' => translate('OTP found, you can proceed')], 200);
            }
            return response()->json(['errors' => [
                ['code' => 'invalid', 'message' => translate('Invalid OTP.')]
            ]], 400);
        }

        $resetData = DB::table('password_resets')
            ->where('token', $request->reset_token)
            ->where($identifier, $value)
            ->first();

        if ($resetData) {
            return response()->json(['message' => translate('OTP found, you can proceed')], 200);
        }

        // OTP invalid handling
        $maxOtpHit = 5;
        $maxOtpHitTime = 60; // seconds
        $tempBlockTime = 600; // seconds

        $verificationData = DB::table('password_resets')
            ->where($identifier, $value)
            ->first();

        if ($verificationData) {
            $now = now();

            if ($verificationData->is_temp_blocked) {
                $blockTime = Carbon::parse($verificationData->temp_block_time);
                $diff = $now->diffInSeconds($blockTime);

                if ($diff <= $tempBlockTime) {
                    $wait = $tempBlockTime - $diff;
                    return response()->json(['errors' => [
                        ['code' => 'otp_block_time', 'message' => translate('messages.please_try_again_after_') . CarbonInterval::seconds($wait)->cascade()->forHumans()]
                    ]], 405);
                }
            }

            if (
                $verificationData->otp_hit_count >= $maxOtpHit &&
                Carbon::parse($verificationData->created_at)->diffInSeconds($now) < $maxOtpHitTime &&
                !$verificationData->is_temp_blocked
            ) {
                DB::table('password_resets')->updateOrInsert(
                    [$identifier => $value],
                    [
                        'is_temp_blocked' => 1,
                        'temp_block_time' => $now,
                        'created_at' => $now,
                    ]
                );

                return response()->json(['errors' => [
                    ['code' => 'otp_temp_blocked', 'message' => translate('messages.Too_many_attemps')]
                ]], 405);
            }

            if (
                $verificationData->is_temp_blocked &&
                Carbon::parse($verificationData->created_at)->diffInSeconds($now) >= $maxOtpHitTime
            ) {
                DB::table('password_resets')->updateOrInsert(
                    [$identifier => $value],
                    [
                        'otp_hit_count' => 0,
                        'is_temp_blocked' => 0,
                        'temp_block_time' => null,
                        'created_at' => $now,
                    ]
                );
            }
        }

        // Update hit count
        DB::table('password_resets')->updateOrInsert(
            [$identifier => $value],
            [
                'otp_hit_count' => DB::raw('otp_hit_count + 1'),
                'created_at' => now(),
                'temp_block_time' => null,
            ]
        );

        return response()->json(['errors' => [
            ['code' => 'invalid', 'message' => translate('Invalid OTP.')]
        ]], 400);
    }

    public function resetPasswordSubmit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reset_token' => 'required',
            'password' => ['required', Password::min(8)],
            'confirm_password' => 'required|same:password',
            'verification_method' => 'required|in:phone,email',
            'phone' => 'nullable|required_if:verification_method,phone|min:9|exists:users,phone',
            'email' => 'nullable|required_if:verification_method,email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $identifierType = $request->verification_method;
        $identifierValue = $request->{$identifierType};

        $user = User::where($identifierType, $identifierValue)->first();

        $resetData = DB::table('password_resets')
            ->where('token', $request->reset_token)
            ->where($identifierType, $identifierValue)
            ->first();

        if (env('APP_MODE') === 'test' && $request->reset_token === '123456') {
            $user->password = bcrypt($request->password);
            $user->save();

            if ($resetData) {
                DB::table('password_resets')
                    ->where('token', $request->reset_token)
                    ->where($identifierType, $identifierValue)
                    ->delete();
            }

            return response()->json(['message' => translate('Password changed successfully.')], 200);
        }

        if ($resetData) {
            $user->password = bcrypt($request->password);
            $user->save();

            DB::table('password_resets')
                ->where('token', $request->reset_token)
                ->where($identifierType, $identifierValue)
                ->delete();

            return response()->json(['message' => translate('Password changed successfully.')], 200);
        }

        return response()->json(['errors' => [
            ['code' => 'invalid', 'message' => translate('messages.invalid_otp')]
        ]], 400);
    }

    public function firebase_auth_verify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sessionInfo' => 'required',
            'phoneNumber' => 'required',
            'code' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }


        $webApiKey = BusinessSetting::where('key', 'firebase_web_api_key')->first()->value??'';

//        $firebaseOTPVerification = Helpers::get_business_settings('firebase_otp_verification');
//        $webApiKey = $firebaseOTPVerification ? $firebaseOTPVerification['web_api_key'] : '';

        $response = Http::post('https://identitytoolkit.googleapis.com/v1/accounts:signInWithPhoneNumber?key='. $webApiKey, [
            'sessionInfo' => $request->sessionInfo,
            'phoneNumber' => $request->phoneNumber,
            'code' => $request->code,
        ]);

        $responseData = $response->json();

        if (isset($responseData['error'])) {
            $errors = [];
            $errors[] = ['code' => "403", 'message' => $responseData['error']['message']];
            return response()->json(['errors' => $errors], 403);
        }

        $user = User::Where(['phone' => $request->phoneNumber])->first();

        if (isset($user)){
            if ($request['is_reset_token'] == 1){
                DB::table('password_resets')->updateOrInsert(['phone' => $user->phone],
                    [
                        'token' => $request->code,
                        'created_at' => now(),
                    ]);
                return response()->json(['message'=>"OTP found, you can proceed"], 200);
            }else{
                if ($user->is_phone_verified) {
                    return response()->json([
                        'message' => translate('messages.phone_number_is_already_varified')
                    ], 200);
                }
                $user->is_phone_verified = 1;
                $user->save();

                return response()->json([
                    'message' => translate('messages.phone_number_varified_successfully'),
                    'otp' => 'inactive'
                ], 200);
            }
        }

        return response()->json([
            'message' => translate('messages.not_found')
        ], 404);
    }
}
