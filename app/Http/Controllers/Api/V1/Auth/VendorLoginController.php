<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\CentralLogics\NezhaMerchantTwoFactor;
use App\Models\Tag;
use App\Rules\UniqueBackofficeEmail;
use App\Models\VendorEmployee;
use App\Models\Zone;
use App\Models\Admin;
use App\Models\Vendor;
use App\Models\Restaurant;
use App\Models\Translation;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Models\BusinessSetting;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use MatanYadaev\EloquentSpatial\Objects\Point;


class VendorLoginController extends Controller
{
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required',
            'password' => 'required|min:6',
            'vendor_type' => ['required', Rule::in(['owner', 'employee'])],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $vendorType = (string) $request->vendor_type;
        $loginRateKeys = $this->loginRateKeys($request, $vendorType, (string) $request->email);
        if (RateLimiter::tooManyAttempts($loginRateKeys['ip'], 20)
            || RateLimiter::tooManyAttempts($loginRateKeys['account'], 5)) {
            return $this->unauthorized();
        }
        $guard = $vendorType === 'owner' ? auth('vendor') : auth('vendor_employee');
        $credentials = [
            'email' => $request->email,
            'password' => $request->password,
        ];
        $actor = $guard->getProvider()->retrieveByCredentials($credentials);
        if (! $actor
            || ($actor instanceof VendorEmployee && ! $actor->status)
            || ! $guard->getProvider()->validateCredentials($actor, $credentials)) {
            foreach ($loginRateKeys as $rateKey) {
                RateLimiter::hit($rateKey, 120);
            }

            return $this->unauthorized();
        }
        foreach ($loginRateKeys as $rateKey) {
            RateLimiter::clear($rateKey);
        }

        $restaurant = $actor instanceof Vendor ? $actor->restaurants[0] ?? null : $actor->restaurant;
        if ($accessError = $this->restaurantAccessError($restaurant, $actor)) {
            return $accessError;
        }

        if (NezhaMerchantTwoFactor::state($actor) === NezhaMerchantTwoFactor::STATE_OPTIONAL) {
            return $this->issueTokenResponse($actor);
        }

        $startRateKeys = $this->challengeStartRateKeys($actor, $request->ip());
        if (RateLimiter::tooManyAttempts($startRateKeys['ip'], 10)
            || RateLimiter::tooManyAttempts($startRateKeys['account'], 5)) {
            return $this->unauthorized();
        }
        foreach ($startRateKeys as $rateKey) {
            RateLimiter::hit($rateKey, 300);
        }
        $challenge = NezhaMerchantTwoFactor::startAppChallenge($actor, $request->ip());

        return $this->noStore(response()->json([
            'two_factor_required' => true,
            'purpose' => $challenge['purpose'],
            'challenge_token' => $challenge['challenge_token'],
            'expires_at' => $challenge['expires_at']->toIso8601String(),
            'setup' => $challenge['purpose'] === 'enroll' ? [
                'secret' => $challenge['secret'],
                'otpauth_uri' => $challenge['otpauth_uri'],
            ] : null,
        ], 202));
    }

    public function verifyTwoFactor(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'challenge_token' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $plainToken = (string) $request->challenge_token;
        $ipKey = 'merchant-app-2fa:ip:'.NezhaMerchantTwoFactor::requestHash($request->ip());
        $accountKey = NezhaMerchantTwoFactor::challengeAccountRateKey($plainToken);
        if (RateLimiter::tooManyAttempts($ipKey, 10)
            || ($accountKey && RateLimiter::tooManyAttempts($accountKey, 5))) {
            return $this->unauthorized();
        }

        try {
            $result = NezhaMerchantTwoFactor::completeAppChallenge(
                $plainToken,
                (string) $request->code,
                $request->ip()
            );
        } catch (\DomainException) {
            RateLimiter::hit($ipKey, 120);
            if ($accountKey) {
                RateLimiter::hit($accountKey, 120);
            }

            return $this->unauthorized();
        }

        RateLimiter::clear($ipKey);
        if ($accountKey) {
            RateLimiter::clear($accountKey);
        }
        foreach ($this->challengeStartRateKeys($result['actor'], $request->ip()) as $rateKey) {
            RateLimiter::clear($rateKey);
        }

        return $this->issueTokenResponse($result['actor']);
    }

    public function logout(Request $request)
    {
        $actor = $request['vendor_employee'] ?? $request['vendor'];
        NezhaMerchantTwoFactor::revokeActor($actor, 'app_logout', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => ['channel' => 'app'],
        ]);

        return response()->json(['message' => 'Logged out.']);
    }

    public function logoutAll(Request $request)
    {
        $actor = $request['vendor_employee'] ?? $request['vendor'];
        NezhaMerchantTwoFactor::revokeActor($actor, 'all_sessions_revoked', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => ['channel' => 'app'],
        ]);

        return response()->json(['message' => 'All sessions were revoked.']);
    }

    private function generateToken(): string
    {
        do {
            $token = Str::random(120);
        } while (Vendor::where('auth_token', $token)->exists()
            || VendorEmployee::where('auth_token', $token)->exists());

        return $token;
    }

    private function issueTokenResponse(Vendor|VendorEmployee $actor)
    {
        $token = $this->generateToken();
        $restaurant = $actor instanceof Vendor ? $actor->restaurants[0] ?? null : $actor->restaurant;
        $subscriptionCheck = $this->restaurantSubscriptionCheck($restaurant, $actor, $token);
        if (data_get($subscriptionCheck, 'type') !== null) {
            return $this->noStore(response()->json(
                data_get($subscriptionCheck, 'data'),
                data_get($subscriptionCheck, 'code')
            ));
        }

        $actor->auth_token = $token;
        $actor->save();

        $payload = [
            'token' => $token,
            'zone_wise_topic' => $restaurant?->zone?->restaurant_wise_topic,
        ];
        if ($actor instanceof VendorEmployee) {
            $payload['role'] = $actor->role ? json_decode($actor->role->modules) : [];
        }

        return $this->noStore(response()->json($payload));
    }

    private function noStore($response)
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    private function loginRateKeys(Request $request, string $vendorType, string $email): array
    {
        return [
            'ip' => 'merchant-app-login:ip:'.NezhaMerchantTwoFactor::requestHash($request->ip()),
            'account' => 'merchant-app-login:account:'.hash(
                'sha256',
                $vendorType.':'.mb_strtolower(trim($email))
            ),
        ];
    }

    private function challengeStartRateKeys(Vendor|VendorEmployee $actor, ?string $ip): array
    {
        return [
            'ip' => 'merchant-app-2fa-start:ip:'.NezhaMerchantTwoFactor::requestHash($ip),
            'account' => 'merchant-app-2fa-start:account:'.hash(
                'sha256',
                NezhaMerchantTwoFactor::actorType($actor).':'.$actor->getAuthIdentifier()
            ),
        ];
    }

    private function restaurantAccessError($restaurant, Vendor|VendorEmployee $actor)
    {
        if (! $restaurant) {
            return $this->unauthorized();
        }
        if ($restaurant->restaurant_model === 'none') {
            return null;
        }
        if (! $restaurant->status && ! $actor->status) {
            return response()->json(['errors' => [[
                'code' => 'auth-002',
                'message' => translate('messages.Your_registration_is_not_approved_yet._You_can_login_once_admin_approved_the_request'),
            ]]], 403);
        }
        if (! $restaurant->status || ($actor instanceof Vendor && ! $actor->status)) {
            return response()->json(['errors' => [[
                'code' => 'auth-002',
                'message' => translate('messages.Your_account_is_suspended'),
            ]]], 403);
        }
        if ($restaurant->restaurant_model === 'subscription'
            && $restaurant->restaurant_sub
            && ! $restaurant->restaurant_sub->mobile_app) {
            return response()->json(['errors' => [[
                'code' => 'no_mobile_app',
                'message' => translate('messages.Your Subscription Plan is not Active for Mobile App'),
            ]]], 401);
        }

        return null;
    }

    private function unauthorized()
    {
        return response()->json(['errors' => [[
            'code' => 'auth-001',
            'message' => translate('Credential_do_not_match,_please_try_again'),
        ]]], 401);
    }


    public function register(Request $request)
    {
        $status = BusinessSetting::where('key', 'toggle_restaurant_registration')->first();
        if (!isset($status) || $status->value == '0') {
            return response()->json(['errors' => Helpers::error_formater('self-registration', translate('messages.restaurant_self_registration_disabled'))]);
        }

        $validator = Validator::make($request->all(), [
            'fName' => 'required',
            'lat' => 'required|numeric|min:-90|max:90',
            'lng' => 'required|numeric|min:-180|max:180',
            'email' => ['required', 'email', new UniqueBackofficeEmail()],
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:9|unique:vendors',
            'min_delivery_time' => 'required',
            'max_delivery_time' => 'required',
            'password' => ['required', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
            'zone_id' => 'required',
            'logo' => 'required|max:2048',
            'cover_photo' => 'nullable|max:2048',
            'delivery_time_type' => 'required',

        ], [
            'password.min_length' => translate('The password must be at least :min characters long'),
            'password.mixed' => translate('The password must contain both uppercase and lowercase letters'),
            'password.letters' => translate('The password must contain letters'),
            'password.numbers' => translate('The password must contain numbers'),
            'password.symbols' => translate('The password must contain symbols'),
            'password.uncompromised' => translate('The password is compromised. Please choose a different one'),
            'password.custom' => translate('The password cannot contain white spaces.'),
        ]);

        if ($request->zone_id) {
            $zone = Zone::query()
                ->whereContains('coordinates', new Point($request->lat, $request->lng, POINT_SRID))
                ->where('id', $request->zone_id)
                ->first();
            if (!$zone) {
                $validator->getMessageBag()->add('latitude', translate('messages.Please_select_a_location_within_the_selected_zone'));
            }
        }

        $data = json_decode($request->translations, true);

        if (count($data) < 1) {
            $validator->getMessageBag()->add('translations', translate('messages.Name and description in english is required'));
        }

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $tag_ids = [];
        if ($request->tags != null) {
            $tags = explode(",", $request->tags);
        }
        if (isset($tags)) {
            foreach ($tags as $key => $value) {
                $tag = Tag::firstOrNew(
                    ['tag' => $value]
                );
                $tag->save();
                array_push($tag_ids, $tag->id);
            }
        }

        $vendor = new Vendor();
        $vendor->f_name = $request->fName;
        $vendor->l_name = $request->lName;
        $vendor->email = $request->email;
        $vendor->phone = $request->phone;
        $vendor->password = bcrypt($request->password);
        $vendor->status = null;
        $vendor->save();

        $restaurant = new Restaurant;
        $restaurant->onboard_source = 'self_register'; // 不可变来路(DESIGN §E1)
        $restaurant->name = $data[0]['value'];
        $restaurant->phone = $request->phone;
        $restaurant->email = $request->email;
        $restaurant->logo = Helpers::upload(dir: 'restaurant/', format: 'png', image: $request->file('logo'));
        $restaurant->cover_photo = Helpers::upload(dir: 'restaurant/cover/', format: 'png', image: $request->file('cover_photo'));
        $restaurant->address = $data[1]['value'];

        $restaurant->latitude = $request->lat;
        $restaurant->longitude = $request->lng;
        $restaurant->vendor_id = $vendor->id;
        $restaurant->zone_id = $request->zone_id;
        $restaurant->tin = $request->tin;
        $restaurant->tin_expire_date = $request->tin_expire_date;
        $extension = $request->has('tin_certificate_image') ? $request->file('tin_certificate_image')->getClientOriginalExtension() : 'png';
        $restaurant->tin_certificate_image = $request->has('tin_certificate_image') ? Helpers::upload('restaurant/', $extension, $request->file('tin_certificate_image')): null;
        $restaurant->delivery_time = $request->min_delivery_time . '-' . $request->max_delivery_time . '-' . $request->delivery_time_type;
        $restaurant->status = 0;
        $restaurant->restaurant_model = 'none';

        if (isset($request->additional_data)  && count(json_decode($request->additional_data, true)) > 0) {
            $restaurant->additional_data = $request->additional_data;
        }

        $additional_documents = [];
        if ($request->additional_documents) {
            foreach ($request->additional_documents as $key => $imagedata) {
                $additional = [];
                foreach ($imagedata as $file) {
                    if (is_file($file)) {
                        $file_name = Helpers::upload('additional_documents/', $file->getClientOriginalExtension(), $file);
                        $additional[] = ['file' => $file_name, 'storage' => Helpers::getDisk()];
                    }
                    $additional_documents[$key] = $additional;
                }
            }
            $restaurant->additional_documents = json_encode($additional_documents);
        }

        $restaurant->save();
        $restaurant->tags()->sync($tag_ids);

        foreach ($data as $key => $i) {
            $data[$key]['translationable_type'] = 'App\Models\Restaurant';
            $data[$key]['translationable_id'] = $restaurant->id;
        }
        Translation::insert($data);

        $cuisine_ids = [];
        $cuisine_ids = json_decode($request->cuisine_ids, true);
        $restaurant?->cuisine()?->sync($cuisine_ids);
        try {
            $admin = Admin::where('role_id', 1)->first();
            $notification_status = Helpers::getNotificationStatusData('restaurant', 'restaurant_registration');
            if ($notification_status?->mail_status == 'active' && config('mail.status') && Helpers::get_mail_status('registration_mail_status_restaurant') == '1') {
                Mail::to($request['email'])->send(new \App\Mail\VendorSelfRegistration('pending', $vendor->f_name . ' ' . $vendor->l_name));
            }

            $notification_status = null;
            $notification_status = Helpers::getNotificationStatusData('admin', 'restaurant_self_registration');
            if ($notification_status?->mail_status == 'active' && config('mail.status') && Helpers::get_mail_status('restaurant_registration_mail_status_admin') == '1') {
                Mail::to($admin?->getRawOriginal('email'))->send(new \App\Mail\RestaurantRegistration('pending', $vendor->f_name . ' ' . $vendor->l_name));
            }
        } catch (\Exception $ex) {
            info($ex->getMessage());
        }

        if (Helpers::subscription_check()) {
            if ($request->business_plan == 'subscription' && $request->package_id != null) {
                $restaurant->package_id = $request->package_id;
                $restaurant->save();

                return response()->json([
                    'restaurant_id' => $restaurant->id,
                    'package_id' => $restaurant->package_id,
                    'type' => 'subscription',
                    'message' => translate('messages.application_placed_successfully')
                ], 200);
            } elseif ($request->business_plan == 'commission') {
                $restaurant->restaurant_model = 'commission';
                $restaurant->save();
                return response()->json([
                    'restaurant_id' => $restaurant->id,
                    'type' => 'commission',
                    'message' => translate('messages.application_placed_successfully')
                ], 200);
            } else {
                return response()->json([
                    'restaurant_id' => $restaurant->id,
                    'type' => 'business_model_fail',
                    'message' => translate('messages.application_placed_successfully')
                ], 200);
            }
        } else {
            $restaurant->restaurant_model = 'commission';
            $restaurant->save();
            return response()->json([
                'restaurant_id' => $restaurant->id,
                'type' => 'commission',
                'message' => translate('messages.application_placed_successfully')
            ], 200);
        }

        return response()->json([
            'restaurant_id' => $restaurant->id,
            'message' => translate('messages.application_placed_successfully')
        ], 200);
    }



    private function restaurantSubscriptionCheck($restaurant, $vendor, $token)
    {
        if ($restaurant?->restaurant_model == 'none') {
            $vendor->auth_token = $token;
            $vendor?->save();
            return [
                'type' => 'subscribed',
                'code' => 200,
                'data' => [
                    'subscribed' => [
                        'restaurant_id' => $restaurant?->id,
                        'token' => $token,
                        'package_id' => $restaurant?->package_id,
                        'zone_wise_topic' => $restaurant?->zone?->restaurant_wise_topic,
                        'type' => 'new_join'
                    ]
                ]
            ];
        } elseif ($restaurant->status == 0 && $vendor->status == 0) {
            return [
                'type' => 'errors',
                'code' => 403,
                'data' => [
                    'errors' => [
                        ['code' => 'auth-002', 'message' => translate('messages.Your_registration_is_not_approved_yet._You_can_login_once_admin_approved_the_request')]
                    ]
                ]
            ];
        } elseif ($restaurant->status == 0 && $vendor->status == 1 && in_array($restaurant?->restaurant_model ,['subscription' ,'commission']) ) {
            return [
                'type' => 'errors',
                'code' => 403,
                'data' => [
                    'errors' => [
                        ['code' => 'auth-002', 'message' => translate('messages.Your_account_is_suspended')]
                    ]
                ]
            ];
        } elseif ($restaurant?->restaurant_model == 'subscription') {
            $restaurant_sub = $restaurant?->restaurant_sub;
            if (isset($restaurant_sub)) {
                if ($restaurant_sub?->mobile_app == 0) {
                    return [
                        'type' => 'errors',
                        'code' => 401,
                        'data' => [
                            'errors' => [
                                ['code' => 'no_mobile_app', 'message' => translate('messages.Your Subscription Plan is not Active for Mobile App')]
                            ]
                        ]
                    ];
                }
            }
        } elseif ($restaurant?->restaurant_model == 'unsubscribed' && isset($restaurant?->restaurant_sub_update_application)) {
            return null;
        } elseif ($restaurant?->restaurant_model == 'unsubscribed' && !isset($restaurant?->restaurant_sub_update_application)) {
            $vendor->auth_token = $token;
            $vendor?->save();
            return [
                'type' => 'subscribed',
                'code' => 200,
                'data' => [
                    'subscribed' => [
                        'restaurant_id' => $restaurant?->id,
                        'token' => $token,
                        'zone_wise_topic' => $restaurant?->zone?->restaurant_wise_topic,
                        'type' => 'new_join'
                    ]
                ]
            ];
        }
        return null;
    }
}
