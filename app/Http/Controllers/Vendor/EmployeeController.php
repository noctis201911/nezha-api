<?php

namespace App\Http\Controllers\Vendor;

use App\CentralLogics\NezhaMerchantTwoFactor;
use App\Models\EmployeeRole;
use App\Rules\UniqueBackofficeEmail;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Models\VendorEmployee;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;
use Maatwebsite\Excel\Facades\Excel;
use Rap2hpoutre\FastExcel\FastExcel;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use App\Exports\RestaurantEmployeeListExport;

class EmployeeController extends Controller
{
    public function add_new()
    {
        $rls = EmployeeRole::where('restaurant_id',Helpers::get_restaurant_id())->get();
        return view('vendor-views.employee.add-new', compact('rls'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'f_name' => 'required',
            'l_name' => 'nullable|max:100',
            'role_id' => [
                'required',
                Rule::exists('employee_roles', 'id')->where(function ($query) {
                    $query->where('restaurant_id', Helpers::get_restaurant_id());
                }),
            ],
            'image' => 'required|max:2048',
            'email' => ['required', new UniqueBackofficeEmail()],
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|max:20|unique:vendor_employees',
            'password' => ['required', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
        ],[
            'password.min_length' => translate('The password must be at least :min characters long'),
            'password.mixed' => translate('The password must contain both uppercase and lowercase letters'),
            'password.letters' => translate('The password must contain letters'),
            'password.numbers' => translate('The password must contain numbers'),
            'password.symbols' => translate('The password must contain symbols'),
            'password.uncompromised' => translate('The password is compromised. Please choose a different one'),
            'password.custom' => translate('The password cannot contain white spaces.'),
        ]);

        $vendor = new VendorEmployee();
        $vendor->f_name = $request->f_name;
        $vendor->l_name = $request->l_name;
        $vendor->phone = $request->phone;
        $vendor->email = $request->email;
        $vendor->employee_role_id = $request->role_id;
        $vendor->password = bcrypt($request->password);
        $vendor->vendor_id = Helpers::get_vendor_id();
        $vendor->restaurant_id = Helpers::get_restaurant_id();
        $vendor->image = Helpers::upload(dir:'vendor/', format:'png', image: $request->file('image'));
        $vendor->save();

        Toastr::success('Employee added successfully!');
        return redirect()->route('vendor.employee.list');
    }

    function list(Request $request)
    {
        $key = explode(' ', $request['search']);
        $em = VendorEmployee::where('restaurant_id', Helpers::get_restaurant_id())->with(['role'])
        ->when(isset($key) , function($query) use($key) {
            $query->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('f_name', 'like', "%{$value}%");
                    $q->orWhere('l_name', 'like', "%{$value}%");
                    $q->orWhere('phone', 'like', "%{$value}%");
                    $q->orWhere('email', 'like', "%{$value}%");
                }
            });
        })
        ->latest()->paginate(config('default_pagination'));

        return view('vendor-views.employee.list', compact('em'));
    }

    public function edit($id)
    {
        $e = VendorEmployee::where('restaurant_id', Helpers::get_restaurant_id())->where(['id' => $id])->firstOrFail();

        if (auth('vendor_employee')->id()  == $e['id']){
            Toastr::error(translate('messages.You_can_not_edit_your_own_info'));
            return redirect()->route('vendor.employee.list');
        }

        $rls = EmployeeRole::where('restaurant_id',Helpers::get_restaurant_id())->get();
        return view('vendor-views.employee.edit', compact('rls', 'e'));
    }

    public function update(Request $request, $id)
    {
        if (!$request->filled('password')) {
            $request->request->remove('password');
        }
        $request->validate([
            'f_name' => 'required',
            'l_name' => 'nullable|max:100',
            'role_id' => [
                'required',
                Rule::exists('employee_roles', 'id')->where(function ($query) {
                    $query->where('restaurant_id', Helpers::get_restaurant_id());
                }),
            ],
            'email' => ['required', new UniqueBackofficeEmail('vendor_employees', (int) $id)],
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|max:20|unique:vendor_employees,phone,'.$id,
            'image' => 'nullable|max:2048',
            'password' => ['nullable', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
        ], [
            'f_name.required' => translate('messages.first_name_is_required'),
            'password.min_length' => translate('The password must be at least :min characters long'),
            'password.mixed' => translate('The password must contain both uppercase and lowercase letters'),
            'password.letters' => translate('The password must contain letters'),
            'password.numbers' => translate('The password must contain numbers'),
            'password.symbols' => translate('The password must contain symbols'),
            'password.uncompromised' => translate('The password is compromised. Please choose a different one'),
            'password.custom' => translate('The password cannot contain white spaces.'),
        ]);

        $e = VendorEmployee::where('restaurant_id', Helpers::get_restaurant_id())->findOrFail($id);

        if (auth('vendor_employee')->id()  == $e['id']){
            Toastr::error(translate('messages.You_can_not_edit_your_own_info'));
            return redirect()->route('vendor.employee.list');
        }

        $passwordChanged = $request['password'] != null;
        if (! $passwordChanged) {
            $pass = $e['password'];
        } else {
            $pass = bcrypt($request['password']);
        }

        if ($request->has('image')) {
            $e['image'] = Helpers::update(dir:'vendor/', old_image: $e->image, format:'png',  image:$request->file('image'));
        }

        DB::table('vendor_employees')->where(['id' => $id])->update([
            'f_name' => $request->f_name,
            'l_name' => $request->l_name,
            'phone' => $request->phone,
            'email' => $request->email,
            'employee_role_id' => $request->role_id,
            'vendor_id'=> Helpers::get_vendor_id(),
            'restaurant_id'=> Helpers::get_restaurant_id(),
            'password' => $pass,
            'image' => $e['image'],
            'updated_at' => now(),
        ]);

        if ($passwordChanged) {
            NezhaMerchantTwoFactor::revokeActor($e->fresh(), 'password_changed_by_owner', [
                'initiator_type' => 'vendor',
                'initiator_id' => auth('vendor')->id(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => ['channel' => 'web'],
            ]);
        }

        Toastr::success('Employee updated successfully!');
        return redirect()->route('vendor.employee.list');
    }

    public function destroy($id)
    {
        $role = VendorEmployee::where('restaurant_id', Helpers::get_restaurant_id())->where(['id' => $id])->first();
        // 哪吒[修复 2026-06-25]: 方法名 distroy→destroy(原与路由 'destroy' 不符→点删除必 500); 加 null 守卫防坏id 500。
        if (!$role) { Toastr::error(translate('messages.not_found')); return back(); }
        if (auth('vendor_employee')->id() == $role['id']){
            Toastr::error(translate('messages.You_can_not_edit_your_own_info'));
            return redirect()->route('vendor.employee.list');
        }

        NezhaMerchantTwoFactor::revokeActor($role, 'employee_deleted', [
            'initiator_type' => 'vendor',
            'initiator_id' => auth('vendor')->id(),
            'metadata' => ['channel' => 'web'],
        ]);
        $role->delete();
        Toastr::info(translate('messages.employee_deleted_successfully'));
        return back();
    }

    public function search(Request $request){
        $key = explode(' ', $request['search']);
        $employees = VendorEmployee::where('restaurant_id', Helpers::get_restaurant_id())->
        where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('f_name', 'like', "%{$value}%");
                $q->orWhere('l_name', 'like', "%{$value}%");
                $q->orWhere('phone', 'like', "%{$value}%");
                $q->orWhere('email', 'like', "%{$value}%");
            }
        })->limit(50)->get();

        return response()->json([
            'view' => view('vendor-views.employee.partials._table',compact('employees'))->render(),
            'count' => $employees->count()
        ]);
    }

    public function list_export(Request $request){
        $key = explode(' ', $request['search']);
        $em = VendorEmployee::where('restaurant_id', Helpers::get_restaurant_id())->with(['role'])
        ->when(isset($key) , function($query) use($key) {
            $query->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('f_name', 'like', "%{$value}%");
                    $q->orWhere('l_name', 'like', "%{$value}%");
                    $q->orWhere('phone', 'like', "%{$value}%");
                    $q->orWhere('email', 'like', "%{$value}%");
                }
            });
        })
        ->latest()->get();

        $data = [
            'employees' => $em,
            'search' => $request->search ?? null,
        ];

        if ($request->type == 'excel') {
            return Excel::download(new RestaurantEmployeeListExport($data), 'Employees.xlsx');
        } else if ($request->type == 'csv') {
            return Excel::download(new RestaurantEmployeeListExport($data), 'Employees.csv');
        }
    }
}
