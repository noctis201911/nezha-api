<?php

namespace App\Http\Controllers;


use App\Models\User;
use App\Traits\Processor;
use Illuminate\Http\Request;
use App\Models\PaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Redirector;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\Validator;
use Illuminate\Contracts\Foundation\Application;


class SenangPayController extends Controller
{
    use Processor;

    private $config_values;

    private PaymentRequest $payment;
    private $user;

    public function __construct(PaymentRequest $payment, User $user)
    {
        $config = $this->payment_config('senang_pay', 'payment_config');
        if (!is_null($config) && $config->mode == 'live') {
            $this->config_values = json_decode($config->live_values);
        } elseif (!is_null($config) && $config->mode == 'test') {
            $this->config_values = json_decode($config->test_values);
        }
        $this->payment = $payment;
        $this->user = $user;
    }

    public function index(Request $request): View|Factory|JsonResponse|Application
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|uuid'
        ]);

        if ($validator->fails()) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $this->error_processor($validator)), 400);
        }

        $payment_data = $this->payment::where(['id' => $request['payment_id']])->where(['is_paid' => 0])->first();
        if (!isset($payment_data)) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }
        $payer = json_decode($payment_data['payer_information']);
        $config = $this->config_values;
        session()->put('payment_id', $payment_data->id);
        return view('payment-views.senang-pay', compact('payment_data', 'payer', 'config'));
    }

    public function return_senang_pay(Request $request): JsonResponse|Redirector|RedirectResponse|Application
    {
        // 哪吒安全(2026-07-11 N-09): digital_payment 开关门——网关休眠(数字支付关)时拒回调, 断"无签名回调伪造已付"链; 启用数字支付前必须先补支付商签名校验(硬前置)。
        $nz_dp = \App\CentralLogics\Helpers::get_business_settings('digital_payment');
        if (!is_array($nz_dp) || ($nz_dp['status'] ?? 0) != 1) {
            return redirect()->route('payment-fail');
        }
        if ($request['status_id'] == 1) {
            $this->payment::where(['id' => session()->get('payment_id')])->update([
                'payment_method' => 'senang_pay',
                'is_paid' => 1,
                'transaction_id' => $request['transaction_id'],
            ]);
            $data = $this->payment::where(['id' => session()->get('payment_id')])->first();
            if (isset($data) && function_exists($data->success_hook)) {
                call_user_func($data->success_hook, $data);
            }
            return $this->payment_response($data,'success');
        }
        $payment_data = $this->payment::where(['id' => session()->get('payment_id')])->first();
        if (isset($payment_data) && function_exists($payment_data->failure_hook)) {
            call_user_func($payment_data->failure_hook, $payment_data);
        }
        return $this->payment_response($payment_data,'fail');
    }
}
