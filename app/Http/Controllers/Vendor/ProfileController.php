<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\NezhaMerchantTwoFactor;
use App\Http\Controllers\MerchantTwoFactorController;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\RateLimiter;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function view()
    {
        return view('vendor-views.profile.index');
    }

    // public function bank_view()
    // {
    //     $data = Helpers::get_vendor_data();
    //     return view('vendor-views.profile.bankView', compact('data'));
    // }

    // public function edit()
    // {
    //     $data = Helpers::get_vendor_data();
    //     dd(12);
    //     return view('vendor-views.profile.edit', compact('data'));
    // }

    public function update(Request $request)
    {
        $table=auth('vendor')->check()?'vendors':'vendor_employees';
        $seller = auth('vendor')->check()?auth('vendor')->user():auth('vendor_employee')->user();
        $request->validate([
            'f_name' => 'required|max:100',
            'l_name' => 'nullable|max:100',
            'email' => 'required|unique:'.$table.',email,'.$seller->id,
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:9|max:20|unique:'.$table.',phone,'.$seller->id,
            'image' => 'nullable|max:2048',
        ], [
            'f_name.required' => translate('messages.first_name_is_required'),
        ]);
        $seller = auth('vendor')->check()?auth('vendor')->user():auth('vendor_employee')->user();
        $seller->f_name = $request->f_name;
        $seller->l_name = $request->l_name;
        $seller->phone = $request->phone;
        $seller->email = $request->email;

        if ($request->image) {
            $seller->image = Helpers::update(dir:'vendor/',old_image: $seller->image, format: 'png', image: $request->file('image'));
        }
        $seller?->save();

        Toastr::success(translate('messages.profile_updated_successfully'));
        return back();
    }

    public function settings_password_update(Request $request)
    {
        $seller = auth('vendor')->check()?Helpers::get_vendor_data():auth('vendor_employee')->user();
        $request->validate([
            'current_password' => ['required', 'string'],
            'two_factor_code' => [Rule::requiredIf((bool) $seller?->two_factor_enabled), 'nullable', 'string', 'max:16'],
            'password' => ['required', 'same:confirm_password', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
            'confirm_password' => 'required',
        ],[
            'password.min_length' => translate('The password must be at least :min characters long'),
            'password.mixed' => translate('The password must contain both uppercase and lowercase letters'),
            'password.letters' => translate('The password must contain letters'),
            'password.numbers' => translate('The password must contain numbers'),
            'password.symbols' => translate('The password must contain symbols'),
            'password.uncompromised' => translate('The password is compromised. Please choose a different one'),
            'password.custom' => translate('The password cannot contain white spaces.'),
        ]);

        $rateKeys = [
            'merchant-profile-step-up:ip:'.NezhaMerchantTwoFactor::requestHash($request->ip()),
            'merchant-profile-step-up:account:'.hash(
                'sha256',
                NezhaMerchantTwoFactor::actorType($seller).':'.$seller->getAuthIdentifier()
            ),
        ];
        if (collect($rateKeys)->contains(fn (string $key): bool => RateLimiter::tooManyAttempts($key, 5))) {
            return back()->withErrors(['current_password' => 'The current password or authenticator code could not be verified.']);
        }
        try {
            NezhaMerchantTwoFactor::verifySensitiveStepUp(
                $seller,
                (string) $request->input('current_password'),
                $request->filled('two_factor_code') ? (string) $request->input('two_factor_code') : null,
                [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'metadata' => ['channel' => 'web', 'route' => optional($request->route())->getName()],
                ]
            );
        } catch (\DomainException) {
            foreach ($rateKeys as $rateKey) {
                RateLimiter::hit($rateKey, 120);
            }

            return back()->withErrors(['current_password' => 'The current password or authenticator code could not be verified.']);
        }
        foreach ($rateKeys as $rateKey) {
            RateLimiter::clear($rateKey);
        }
        $seller->password = bcrypt($request['password']);
        $seller->save();
        NezhaMerchantTwoFactor::revokeActor($seller, 'password_changed', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => ['channel' => 'web'],
        ]);
        $guard = auth('vendor')->check() ? 'vendor' : 'vendor_employee';
        auth($guard)->logout();
        MerchantTwoFactorController::clearPending($request);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Toastr::success(translate('messages.vendor_pasword_updated_successfully'));
        $loginKey = $guard === 'vendor' ? 'restaurant_login_url' : 'restaurant_employee_login_url';

        return to_route('login', [Helpers::get_login_url($loginKey)]);
    }

    // public function bank_update(Request $request)
    // {
    //     $request->validate([
    //         'bank_name' => 'required|max:191',
    //         'branch' => 'required|max:191',
    //         'holder_name' => 'required|max:191',
    //         'account_no' => 'required|max:191',
    //     ]);
    //     $bank = Helpers::get_vendor_data();
    //     $bank->bank_name = $request->bank_name;
    //     $bank->branch = $request->branch;
    //     $bank->holder_name = $request->holder_name;
    //     $bank->account_no = $request->account_no;
    //     $bank->save();
    //     Toastr::success(translate('messages.bank_info_updated_successfully'));
    //     return redirect()->route('vendor.profile.bankView');
    // }

    // public function bank_edit()
    // {
    //     $data = Helpers::get_vendor_data();
    //     return view('vendor-views.profile.bankEdit', compact('data'));
    // }
    // public function bank_delete()
    // {
    //     $data = Helpers::get_vendor_data();
    //     $data->bank_name = null;
    //     $data->branch = null;
    //     $data->holder_name = null;
    //     $data->account_no = null;
    //     $data->save();
    //     Toastr::success(translate('messages.bank_info_updated_successfully'));
    //     return redirect()->route('vendor.profile.bankView');
    // }

}
