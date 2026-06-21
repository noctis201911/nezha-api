<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MerchantLead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class MerchantLeadController extends Controller
{
    // 商家入驻意向提交（H5「商家入驻」表单）
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_name'   => 'required|string|max:120',
            'contact_name' => 'required|string|max:60',
            'phone'        => 'required|string|max:120',
            'wechat'       => 'nullable|string|max:60',
            'address'      => 'nullable|string|max:255',
            'category'     => 'nullable|string|max:60',
            'note'         => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->all()], 403);
        }

        $lead = MerchantLead::create([
            'store_name'   => $request->store_name,
            'contact_name' => $request->contact_name,
            'phone'        => $request->phone,
            'wechat'       => $request->wechat,
            'address'      => $request->address,
            'category'     => $request->category,
            'note'         => $request->note,
            'source'       => 'h5',
            'status'       => 0,
            'ip'           => $request->ip(),
        ]);

        // 邮件同步给运营，避免一直盯后台
        $this->notifyOperator($lead);

        return response()->json([
            'message' => '已收到您的入驻申请，我们会尽快与您联系',
            'id'      => $lead->id,
        ], 200);
    }

    private function notifyOperator($lead)
    {
        try {
            $to = 'support@nezha.am'; // 固定发到官方邮箱(写死,不依赖后台可空设置)
            $body = "新的商家入驻申请\n\n"
                . "店铺名称：{$lead->store_name}\n"
                . "联系人：{$lead->contact_name}\n"
                . "邮箱：{$lead->phone}\n"
                . "微信：" . ($lead->wechat ?: '-') . "\n"
                . "店铺地址：" . ($lead->address ?: '-') . "\n"
                . "经营品类：" . ($lead->category ?: '-') . "\n"
                . "备注：" . ($lead->note ?: '-') . "\n"
                . "来源：H5\n"
                . "提交时间：" . $lead->created_at . "\n\n"
                . "登录后台「商家入驻」查看与跟进。";

            Mail::raw($body, function ($message) use ($to, $lead) {
                $message->to($to)->subject('【哪吒】新商家入驻申请 - ' . $lead->store_name);
            });
        } catch (\Throwable $th) {
            info('merchant lead mail failed: ' . $th->getMessage());
        }
    }
}
