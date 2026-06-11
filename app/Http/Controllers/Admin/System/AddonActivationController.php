<?php

namespace App\Http\Controllers\Admin\System;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;

use App\Traits\ActivationClass;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;

class AddonActivationController extends Controller
{
    use ActivationClass;


    public function index()
    {
        return view('admin-views.addon-activation.index');
    }

    public function activation(Request $request): Redirector|RedirectResponse|Application
    {
        $data = $this->addonActivationProcess(request: $request);
        if ($data['status']) {
            Helpers::businessUpdateOrInsert(['key' => $request['key']], [
                'value' => json_encode([
                    'activation_status' => $request['status'] ?? 0,
                    'username' => $request['username'],
                    'purchase_key' => $request['purchase_key'],
                ])
            ]);
            Toastr::success(translate('activated_successfully'));
        } else {
            Toastr::error($data['message']);
        }
        return back();
    }



    public function getCurrentDomain(): string
    {
        return str_replace(["http://", "https://", "www."], "", url('/'));
    }

    public function addonActivationProcess(object $request): array
    {
        $response = $this->getRequestConfig(
            username: $request['username'],
            purchaseKey: $request['purchase_key'],
            softwareId: $request['software_id'] ?? SOFTWARE_ID,
            softwareType: $request['software_type'] ?? base64_decode('cHJvZHVjdA==')
        );

        $status = $response['active'] ?? 0;
        $message = $response['message'] ?? translate('Activation_failed');
        if ($response['active'] == 1 && $request['status'] == 1) {
            $response['active'] = 1;
        } else {
            $response['active'] = 0;
        }
        $this->updateActivationConfig(app: $request['addon_name'], response: $response);

        if ((int)$status) {
            return [
                'status' => (int)$status,
                'activation_status' => 1,
                'username' => $request['username'],
                'purchase_code' => $request['purchase_code'],
            ];
        }

        return [
            'status' => (int)$status,
            'message' => $message
        ];
    }
}
