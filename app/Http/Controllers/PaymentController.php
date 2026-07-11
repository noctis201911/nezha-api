<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Models\BusinessSetting;

use App\Library\Payer;
use App\Traits\Payment;
use App\Library\Receiver;
use App\Library\Payment as PaymentInfo;


class PaymentController extends Controller
{
    public function __construct(){
        if (is_dir('App\Traits') && trait_exists('App\Traits\Payment')) {
            $this->extendWithPaymentGatewayTrait();
        }
    }

    private function extendWithPaymentGatewayTrait()
    {
        $extendedControllerClass = $this->generateExtendedControllerClass();
        eval($extendedControllerClass);
    }

    private function generateExtendedControllerClass()
    {
        $baseControllerClass = get_class($this);
        $traitClassName = 'App\Traits\Payment';

        $extendedControllerClass = "
            class ExtendedController extends $baseControllerClass {
                use $traitClassName;
            }
        ";

        return $extendedControllerClass;
    }
    public function payment(Request $request)
    {
        session()->put('customer_id', $request['customer_id']);
        session()->put('payment_platform', $request['payment_platform']);
        session()->put('order_id', $request->order_id);

        $order = Order::where(['id' => $request->order_id, 'user_id' => $request['customer_id']])->first();

        if(!$order){
            return response()->json(['errors' => ['code' => 'order-payment', 'message' => 'Data not found']], 403);
        }

        // 哪吒安全(2026-07-11 NZ-SEC-004): callback 只写入已通过 id+user_id 归属校验的本单, 且必须过站内白名单。
        //   原实现两处漏洞: ①跨单污染——在归属校验前按 order_id 单键 update 任意订单的 callback(免登录可写他人单);
        //                 ②开放跳转——success/fail/cancel 直跳 $order->callback 无 scheme/host 校验(可跳钓鱼站/deep link)。
        //   合法 callback 恒为 window.location.origin 下的站内地址(见前端 CheckoutPage), 故白名单=站内 host 或相对路径。
        $safeCallback = ($request->has('callback') && $this->isSafeCallback($request['callback'])) ? $request['callback'] : null;
        if ($safeCallback !== null) {
            $order->callback = $safeCallback;
            $order->save();
        }

        //guest user check
        if ($order->is_guest) {
            $address = json_decode($order['delivery_address'] , true);
            $customer = collect([
                'first_name' => $address['contact_person_name'],
                'last_name' => '',
                'phone' => $address['contact_person_number'],
                'email' => $address['contact_person_email'],
            ]);

        } else {
            $customer = User::find($request['customer_id']);
            $customer = collect([
                'first_name' => $customer['f_name'],
                'last_name' => $customer['l_name'],
                'phone' => $customer['phone'],
                'email' => $customer['email'],
            ]);
        }



        if (session()->has('payment_method') == false) {
            session()->put('payment_method', 'ssl_commerz_payment');
        }

        $order_amount = $order->order_amount - $order->partially_paid_amount;

            if (!isset($customer)) {
                return response()->json(['errors' => ['message' => 'Customer not found']], 403);
            }

            if (!isset($order_amount)) {
                return response()->json(['errors' => ['message' => 'Amount not found']], 403);
            }

            if (!$request->has('payment_method')) {
                return response()->json(['errors' => ['message' => 'Payment not found']], 403);
            }

            $payer = new Payer($customer['first_name'].' '.$customer['last_name'], $customer['email'], $customer['phone'], '');

            $currency=BusinessSetting::where(['key'=>'currency'])->first()->value;
            $additional_data = [
                'business_name' => BusinessSetting::where(['key'=>'business_name'])->first()?->value,
                'business_logo' => dynamicStorage('storage/app/public/business') . '/' .BusinessSetting::where(['key' => 'logo'])->first()?->value
            ];
            $payment_info = new PaymentInfo(
                success_hook: 'order_place',
                failure_hook: 'order_failed',
                currency_code: $currency,
                payment_method: $request->payment_method,
                payment_platform: $request['payment_platform'],
                payer_id: $request['customer_id'],
                receiver_id: '100',
                additional_data: $additional_data,
                payment_amount: $order_amount,
                external_redirect_link: $safeCallback ?: session('callback'),
                attribute: 'order',
                attribute_id: $order->id
            );

            $receiver_info = new Receiver('receiver_name','example.png');

            $redirect_link = Payment::generate_link($payer, $payment_info, $receiver_info);

            return redirect($redirect_link);


        //for default payment gateway

        if (isset($customer) && isset($order)) {
            $data = [
                'name' => $customer['f_name'],
                'email' => $customer['email'],
                'phone' => $customer['phone'],
            ];
            session()->put('data', $data);
            return view('payment-view');
        }

        return response()->json(['errors' => ['code' => 'order-payment', 'message' => 'Data not found']], 403);

    }


    public function success()
    {
        $order = Order::where(['id' => session('order_id'), 'user_id'=>session('customer_id')])->first();
        if (isset($order) && $order->callback != null && $this->isSafeCallback($order->callback)) {
            return redirect($order->callback . '&status=success');
        }
        return response()->json(['message' => 'Payment succeeded'], 200);
    }

    public function fail()
    {
        $order = Order::where(['id' => session('order_id'), 'user_id'=>session('customer_id')])->first();
        if (isset($order) && $order->callback != null && $this->isSafeCallback($order->callback)) {
            return redirect($order->callback . '&status=fail');
        }
        return response()->json(['message' => 'Payment failed'], 403);
    }
    public function cancel(Request $request)
    {
        $order = Order::where(['id' => session('order_id'), 'user_id'=>session('customer_id')])->first();
        if (isset($order) && $order->callback != null && $this->isSafeCallback($order->callback)) {
            return redirect($order->callback . '&status=fail');
        }
        return response()->json(['message' => 'Payment failed'], 403);
    }

    /**
     * 哪吒安全(2026-07-11 NZ-SEC-004): 支付回调 URL 白名单——防开放跳转/头注入。
     * 仅放行: ①站内相对路径(单 / 开头, 非 // 协议相对) ②http(s) 且 host 属本平台。
     * 拒绝: 外部 host / javascript:/data:/vbscript: / 协议相对 // / 含 CR-LF 或控制字符 / 超长。
     */
    private function isSafeCallback($url): bool
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
        // 站内相对路径 (排除 // 协议相对)
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
        $host = strtolower($parts['host']);
        $allowed = ['nezha.am', 'www.nezha.am', 'api.nezha.am'];
        return in_array($host, $allowed, true);
    }

}
