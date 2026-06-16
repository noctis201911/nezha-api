<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\OfflinePaymentMethod;
use Brian2694\Toastr\Facades\Toastr;

class OfflinePaymentMethodController extends Controller
{

    protected OfflinePaymentMethod $OfflinePaymentMethod;

    public function __construct(OfflinePaymentMethod $OfflinePaymentMethod)
    {
        $this->OfflinePaymentMethod = $OfflinePaymentMethod;
    }

    public function index(Request $request)
    {
        if (request()->has('status') && (request('status') == 'active' || request('status') == 'inactive'))
        {
            $methods = OfflinePaymentMethod::when(request('status') == 'active', function($query){
                return $query->where('status', 1);
            })->when(request('status') == 'inactive', function($query){
                return $query->where('status', 0);
            })->paginate(10);
        } else if(request()->has('search')) {
            $methods = OfflinePaymentMethod::where(function ($query) {
                $query->orWhere('method_name', 'like', "%".request('search')."%");
            })->paginate(10);
        }else{
            $methods = OfflinePaymentMethod::paginate(10);
        }

        return view('admin-views.business-settings.offline-payment.index', compact('methods'));
    }


    public function create()
    {
        return view('admin-views.business-settings.offline-payment.new');
    }


    public function store(Request $request)
    {
        $request->validate([
            'method_name' => 'required|unique:offline_payment_methods|max:255',
            'field_label' => 'required|array|min:1',
        ], [
            'field_label.required' => translate('Payment_information_details_required'),
        ]);

        $method_fields = $this->buildMethodFields($request);
        if (empty($method_fields)) {
            Toastr::error(translate('Payment_information_details_required'));
            return back()->withInput();
        }

        $this->OfflinePaymentMethod->insert([
            'method_name' => $request->method_name,
            'method_fields' => json_encode($method_fields, JSON_UNESCAPED_UNICODE),
            'method_informations' => $request->filled('payment_note') ? $request->payment_note : '[]',
            'status' => 1,
            'created_at' => Carbon::now(),
        ]);

        Toastr::success(translate('offline_payment_method_added_successfully'));
        return to_route('admin.business-settings.offline');
    }


    public function edit($id)
    {
        $data = $this->OfflinePaymentMethod->where('id', $id)->first();

        if($data)
        {
            return view('admin-views.business-settings.offline-payment.edit', compact('data'));
        }else{
            Toastr::error(translate('offline_payment_method_not_found'));
            return to_route('admin.business-settings.offline');
        }
    }


    public function update(Request $request)
    {
        $request->validate([
            'method_name' => 'required|max:255|unique:offline_payment_methods,method_name,'.$request->id,
            'field_label' => 'required|array|min:1',
        ], [
            'field_label.required' => translate('Payment_information_details_required'),
        ]);

        $method_fields = $this->buildMethodFields($request);
        if (empty($method_fields)) {
            Toastr::error(translate('Payment_information_details_required'));
            return back()->withInput();
        }

        $update = [
            'method_name' => $request->method_name,
            'method_fields' => json_encode($method_fields, JSON_UNESCAPED_UNICODE),
        ];
        if ($request->has('payment_note')) {
            $update['method_informations'] = $request->filled('payment_note') ? $request->payment_note : '[]';
        }
        $this->OfflinePaymentMethod->where('id', $request->id)->update($update);

        Toastr::success(translate('offline_payment_method_update_successfully'));
        return to_route('admin.business-settings.offline');
    }

    /** 哪吒: 从表单 field_label[]/field_type[]/field_required[] 构建 canonical method_fields
     *  (input_field_name/input_type/is_required), 与顾客端 Api/V1/OrderController 读取严格一致。 */
    private function buildMethodFields(Request $request): array
    {
        $labels = $request->input('field_label', []);
        $types  = $request->input('field_type', []);
        $reqs   = $request->input('field_required', []);
        $phs    = $request->input('field_placeholder', []);
        $out = [];
        foreach ($labels as $i => $label) {
            $label = trim((string) $label);
            if ($label === '') continue;
            $out[] = [
                'input_field_name' => $label,
                'input_type' => (isset($types[$i]) && $types[$i] === 'file') ? 'file' : 'text',
                'placeholder' => isset($phs[$i]) ? trim((string) $phs[$i]) : '',
                'is_required' => (isset($reqs[$i]) && (string) $reqs[$i] === '1') ? 1 : 0,
            ];
        }
        return $out;
    }


    public function delete(Request $request)
    {
        $this->OfflinePaymentMethod->where('id', $request->id)->delete();
        Toastr::success(translate('offline_payment_method_delete_successfully'));
        return to_route('admin.business-settings.offline');
    }

    public function status($id)
    {
        $data = $this->OfflinePaymentMethod->where('id', $id)->first();
        $message = '';

        if (isset($data)) {
            $data->update([
                'status' => $data->status == 1 ? 0:1,
            ]);
            $message = translate("status_updated_successfully");
        } else {
            $message = translate("status_update_failed");
        }

        Toastr::success(translate($message));
        return to_route('admin.business-settings.offline');
    }
}
