<?php

namespace App\Http\Controllers\Vendor;


use App\Models\DisbursementWithdrawalMethod;
use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaPaymentAddressChangeView;
use App\Models\WithdrawalMethod;
use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class WalletMethodController extends Controller
{
    public function index(Request $request)
    {
        // 哪吒外卖 B方案: 平台不经手资金(INVARIANTS L1-1), 提现打款腿已拔除(L1-5),
        // StackFood 原版"提现方式管理"在直付模式下已无意义。商家收款方式由超管(平台)
        // 代为登记在 restaurant 表, 此页改为只读"我的收款方式"核对页, 让商家核对平台
        // 给自己设置的收款信息是否正确(如有误需联系平台修改, 防误改导致收不到款)。
        $restaurant = \App\Models\Restaurant::find(Helpers::get_restaurant_id());
        $isOwner = auth('vendor')->check();
        $paymentAddressSecurity = $restaurant
            ? NezhaPaymentAddressChangeView::merchant($restaurant, $isOwner)
            : ['enabled' => false, 'storage_ready' => false, 'is_owner' => $isOwner, 'open_changes' => collect(), 'notifications' => collect()];
        $viewedSecurityNotifications = $isOwner
            ? NezhaPaymentAddressChangeView::markMerchantSecurityNotificationsViewed((int) auth('vendor')->id())
            : 0;

        return view('vendor-views.wallet-method.index', compact('restaurant', 'paymentAddressSecurity', 'viewedSecurityNotifications'));
    }

    public function store(Request $request)
    {
        $method = WithdrawalMethod::find($request['withdraw_method']);
        $fields = array_column($method->method_fields, 'input_name');
        $values = $request->all();

        $method_data = [];
        foreach ($fields as $field) {
            if(key_exists($field, $values)) {
                $method_data[$field] = $values[$field];
            }
        }

        $data = [
            'restaurant_id' => Helpers::get_restaurant_id(),
            'withdrawal_method_id' => $method['id'],
            'method_name' => $method['method_name'],
            'method_fields' => json_encode($method_data),
            'is_default' => 0,
            'created_at' => now(),
            'updated_at' => now()
        ];

        DB::table('disbursement_withdrawal_methods')->insert($data);
        Toastr::success(translate('Withdraw method stored.'));
        return redirect()->back();
    }

    public function default(Request $request)
    {
        // 哪吒安全(2026-07-11 N-06): IDOR——只操作本店收款方式(restaurant_id 作用域)。
        $method = DisbursementWithdrawalMethod::where('id', $request->id)->where('restaurant_id', Helpers::get_restaurant_id())->first();
        if (!$method) { Toastr::error(translate('messages.not_found')); return back(); }
        $method->is_default = $request->default;
        $method->save();
        DisbursementWithdrawalMethod::whereNot('id', $request->id)->where('restaurant_id',Helpers::get_restaurant_id())->update(['is_default' => 0]);
        Toastr::success(translate('messages.default_method_updated'));
        return back();
    }

    public function delete(Request $request)
    {
        // 哪吒安全(2026-07-11 N-06): IDOR——只删本店收款方式(restaurant_id 作用域)。
        $method = DisbursementWithdrawalMethod::where('id', $request->id)->where('restaurant_id', Helpers::get_restaurant_id())->first();
        if (!$method) { Toastr::error(translate('messages.not_found')); return back(); }
          if($method->is_default == 1){
            Toastr::error('Default withdrawal method can not be deleted!');
            return back();
        }

        $method->delete();
        Toastr::success(translate('messages.method_deleted_successfully'));
        return back();
    }


    public function status_update(Request $request)
    {
        // 哪吒安全(2026-07-11 N-06): IDOR——只操作本店收款方式(restaurant_id 作用域)。
        $withdrawal_method = DisbursementWithdrawalMethod::where('id', $request->id)->where('restaurant_id', Helpers::get_restaurant_id())->first();
        if (!$withdrawal_method) { return response()->json(['success' => 0, 'message' => translate('messages.not_found')], 404); }

        $withdrawal_method->is_active = !$withdrawal_method->is_active;
        if($withdrawal_method->is_default == 1  && $withdrawal_method->is_active == 0){
            return response()->json([
            'success' => 0,'message' => translate('messages.You_cannot_disable_a_default_method')
        ], 200);
        }
        $withdrawal_method->save();

        return response()->json([
            'success' => 1,'message' =>   translate('messages.status_updated_successfully')
        ], 200);
    }

        public function default_status_update(Request $request)
    {

        // 哪吒安全(2026-07-11 N-06): IDOR——只操作本店收款方式(restaurant_id 作用域)。
        $withdrawal_method = DisbursementWithdrawalMethod::where('id', $request->id)->where('restaurant_id', Helpers::get_restaurant_id())->first();
        if (!$withdrawal_method) { return response()->json(['success' => 0, 'message' => translate('messages.not_found')], 404); }

        if ($withdrawal_method->is_default == 1) {
            return response()->json([
                'success' => 0,'message' => translate('messages.This_is_already_a_default_method')
            ], 200);

        }

        DisbursementWithdrawalMethod::where('id', '!=', $request->id)->where('restaurant_id', Helpers::get_restaurant_id())->update(['is_default' => 0]); // 哪吒安全(2026-07-11 N-06): 加 restaurant_id——防一次调用清空全平台所有餐厅默认收款标记(原无过滤=跨租户数据破坏)
        $withdrawal_method->is_default = 1;
        $withdrawal_method->is_active = 1;
        $withdrawal_method->save();
        return response()->json([
            'success' => 1,'message' =>   translate('messages.default_method_updated_successfully')
        ], 200);
    }


       public function edit($id)
    {
        // 哪吒安全(2026-07-11 N-06): IDOR——只看本店收款方式(restaurant_id 作用域)。
        $withdrawal_method = DisbursementWithdrawalMethod::with('withdrawMethod')->where('id', $id)->where('restaurant_id', Helpers::get_restaurant_id())->first();
        if (!$withdrawal_method || !$withdrawal_method->withdrawMethod) {
        return response()->json([
                    'error' => 1,'message' => translate('messages.Withdrawal_method_not_found')
                ]);
        }


 return response()->json([
            'view' => view('vendor-views.wallet-method._edit_withdraw_method', compact('withdrawal_method'))->render(),
        ]);

    }

    public function update(Request $request)
    {

        // 哪吒安全(2026-07-11 N-06): IDOR——只改本店收款方式(防跨店篡改打款账户字段·restaurant_id 作用域)。
        $withdrawal_method = DisbursementWithdrawalMethod::with('withdrawMethod')->where('id', $request['id'])->where('restaurant_id', Helpers::get_restaurant_id())->first();

        if(!isset($withdrawal_method)) {
            Toastr::error('Withdrawal method not found!');
            return back();
        }

        $method = $withdrawal_method->withdrawMethod;
        $fields = array_column($method->method_fields, 'input_name');
        $values = $request->all();

        $method_data = [];
        foreach ($fields as $field) {
            if(key_exists($field, $values)) {
                $method_data[$field] = $values[$field];
            }
        }


        $withdrawal_method->method_name = $method['method_name'];
        $withdrawal_method->method_fields =json_encode($method_data);
        $withdrawal_method->save();


        Toastr::success('Withdrawal method update successfully');
        return back();

    }


}
