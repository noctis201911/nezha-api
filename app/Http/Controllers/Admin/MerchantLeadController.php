<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MerchantLead;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;

class MerchantLeadController extends Controller
{
    public function list(Request $request)
    {
        $search = $request['search'];
        if ($request->has('search') && $search) {
            $key = explode(' ', $search);
            $leads = MerchantLead::where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('store_name', 'like', "%{$value}%")
                        ->orWhere('contact_name', 'like', "%{$value}%")
                        ->orWhere('phone', 'like', "%{$value}%");
                }
            });
        } else {
            $leads = new MerchantLead();
        }
        $leads = $leads->latest()->paginate(config('default_pagination'));
        return view('admin-views.merchant-lead.list', compact('leads', 'search'));
    }

    public function view($id)
    {
        $lead = MerchantLead::findOrFail($id);
        if (!$lead->seen) {
            $lead->update(['seen' => 1]);
        }
        return view('admin-views.merchant-lead.view', compact('lead'));
    }

    public function updateStatus(Request $request, $id)
    {
        $lead = MerchantLead::findOrFail($id);
        $lead->update(['status' => (int) $request->status]);
        Toastr::success('状态已更新');
        return back();
    }

    public function destroy(Request $request)
    {
        $lead = MerchantLead::find($request->id);
        if ($lead) {
            $lead->delete();
            Toastr::success('已删除');
        }
        return redirect()->route('admin.merchant-lead.list');
    }
}
