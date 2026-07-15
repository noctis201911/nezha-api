<?php

namespace App\Http\Controllers\Admin;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Rules\UniqueBackofficeEmail;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\DB;
use App\Exports\EmployeeListExport;
use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;
use Maatwebsite\Excel\Facades\Excel;
use Rap2hpoutre\FastExcel\FastExcel;
use Illuminate\Validation\Rules\Password;

class EmployeeController extends Controller
{

    public function add_new()
    {
        $rls = AdminRole::whereNotIn('id', [1])->get();
        return view('admin-views.employee.add-new', compact('rls'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'f_name' => 'required',
            'l_name' => 'nullable|max:100',
            'role_id' => 'required',
            'image' => 'required|max:2048',
            'email' => ['required', new UniqueBackofficeEmail()],
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:9|max:20|unique:admins',
            'password' => ['required', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],

        ], [
            'role_id.required' => translate('messages.role_field_is_required'),
            'password.min_length' => translate('The password must be at least :min characters long'),
            'password.mixed' => translate('The password must contain both uppercase and lowercase letters'),
            'password.letters' => translate('The password must contain letters'),
            'password.numbers' => translate('The password must contain numbers'),
            'password.symbols' => translate('The password must contain symbols'),
            'password.uncompromised' => translate('The password is compromised. Please choose a different one'),
            'password.custom' => translate('The password cannot contain white spaces.'),
        ]);

        if ($request->role_id == 1) {
            Toastr::warning(translate('messages.access_denied'));
            return back();
        }

        $employee = new Admin();
        $employee->f_name = $request->f_name;
        $employee->l_name = $request->l_name;
        $employee->phone = $request->phone;
        $employee->zone_id = $request->zone_id;
        $employee->email = $request->email;
        $employee->role_id = $request->role_id;
        $employee->password = bcrypt($request->password);
        $employee->image = Helpers::upload(dir:'admin/', format:'png', image: $request->file('image'));
        $employee->employee_code = $this->nextEmployeeCode(AdminRole::find($request->role_id));
        $employee->save();

        // SEC-3 审计: 员工新增 (🔴 不记密码)
        \App\Models\AdminAuditLog::record('admin_employee_create', 'admin', $employee->id, null, [
            'name'    => trim($employee->f_name . ' ' . $employee->l_name),
            'email'   => $employee->email,
            'role_id' => $employee->role_id,
        ]);

        Toastr::success(translate('messages.employee_added_successfully'));
        return redirect()->route('admin.employee.list');
    }

    function list(Request $request)
    {
        $key = explode(' ', $request['search']);
        $em = Admin::zone()->with(['role'])->where('role_id', '!=','1')
        ->when(isset($key) , function($q) use($key){
            $q->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('f_name', 'like', "%{$value}%");
                    $q->orWhere('l_name', 'like', "%{$value}%");
                    $q->orWhere('phone', 'like', "%{$value}%");
                    $q->orWhere('email', 'like', "%{$value}%");
                }
            });
        })
        ->latest()->paginate(config('default_pagination'));
        return view('admin-views.employee.list', compact('em'));
    }

    public function edit($id)
    {
        $e = Admin::zone()->where('role_id', '!=','1')->where(['id' => $id])->first();
        if (auth('admin')->id()  == $e['id']){
            Toastr::error(translate('messages.You_can_not_edit_your_own_info'));
            return redirect()->route('admin.employee.list');
        }
        $rls = AdminRole::whereNotIn('id', [1])->get();
        return view('admin-views.employee.edit', compact('rls', 'e'));
    }

    public function update(Request $request, $id)
    {
        if (!$request->filled('password')) {
            $request->request->remove('password');
        }
        $request->validate([
            'f_name' => 'required|max:100',
            'l_name' => 'nullable|max:100',
            'role_id' => 'required',
            'email' => ['required', new UniqueBackofficeEmail('admins', (int) $id)],
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:9|max:20|unique:admins,phone,'.$id,
            'password' => ['nullable', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
            'image' => 'nullable|max:2048',
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


        if ($request->role_id == 1) {
            Toastr::warning(translate('messages.access_denied'));
            return back();
        }

        $e = Admin::where('role_id','!=',1)->findOrFail($id);
        if (auth('admin')->id()  == $e['id']){
            Toastr::error(translate('messages.You_can_not_edit_your_own_info'));
            return redirect()->route('admin.employee.list');
        }

        // SEC-3 审计: 改前快照 (🔴 不记密码)
        $auditBefore = [
            'name'    => trim(($e->f_name ?? '') . ' ' . ($e->l_name ?? '')),
            'email'   => $e->email,
            'role_id' => $e->role_id,
        ];

        if ($request['password'] == null) {
            $pass = $e['password'];
        } else {

            $pass = bcrypt($request['password']);
        }

        if ($request->has('image')) {
            $e['image'] = Helpers::update(dir:'admin/', old_image: $e->image, format: 'png', image:$request->file('image'));
        }

        $employee = Admin::find($id);

        $employee->f_name = $request->f_name;
        $employee->l_name = $request->l_name;
        $employee->phone = $request->phone;
        $employee->zone_id = $request->zone_id;
        $employee->email = $request->email;
        $employee->role_id = $request->role_id;
        $employee->password = $pass;
        $employee->image = $e['image'];
        if (empty($employee->employee_code)) {
            $employee->employee_code = $this->nextEmployeeCode(AdminRole::find($request->role_id));
        }
        $employee->save();

        // SEC-3 审计: 员工变更 (password_changed 只记布尔, 🔴 不记明文)
        \App\Models\AdminAuditLog::record('admin_employee_update', 'admin', $employee->id, $auditBefore, [
            'name'             => trim($employee->f_name . ' ' . $employee->l_name),
            'email'            => $employee->email,
            'role_id'          => $employee->role_id,
            'password_changed' => $request['password'] != null,
        ]);

        Toastr::success(translate('messages.employee_updated_successfully'));
        return redirect()->route('admin.employee.list');
    }

    public function distroy($id)
    {
        $role=Admin::zone()->where('role_id', '!=','1')->where(['id'=>$id])->first();
        if (auth('admin')->id()  == $role['id']){
            Toastr::error(translate('messages.You_can_not_edit_your_own_info'));
            return redirect()->route('admin.employee.list');
        }
        // SEC-3 审计: 删除前快照
        \App\Models\AdminAuditLog::record('admin_employee_delete', 'admin', $role?->id, [
            'name'    => trim(($role->f_name ?? '') . ' ' . ($role->l_name ?? '')),
            'email'   => $role?->email,
            'role_id' => $role?->role_id,
        ], null);
        $role->delete();
        Toastr::info(translate('messages.employee_deleted_successfully'));
        return back();
    }

    // public function search(Request $request){
    //     $key = explode(' ', $request['search']);
    //     $employees=Admin::zone()->where('role_id', '!=','1')
    //     ->where(function ($q) use ($key) {
    //         foreach ($key as $value) {
    //             $q->orWhere('f_name', 'like', "%{$value}%");
    //             $q->orWhere('l_name', 'like', "%{$value}%");
    //             $q->orWhere('phone', 'like', "%{$value}%");
    //             $q->orWhere('email', 'like', "%{$value}%");
    //         }
    //     })->limit(50)->get();
    //     return response()->json([
    //         'view'=>view('admin-views.employee.partials._table',compact('employees'))->render(),
    //         'count'=>$employees->count()
    //     ]);
    // }

    // public function (Request $request){
    //     $withdraw_request = Admin::zone()->with(['role'])->where('role_id', '!=','1')->get();
    //     if($request->type == 'csv'){
    //         return (new FastExcel($withdraw_request))->download('Employee.csv');
    //     }
    //     return (new FastExcel($withdraw_request))->download('Employee.xlsx');
    // }

    // 哪吒: 按岗位前缀生成职员编号 (前缀-三位序号, 如 CS-001); 前缀取自角色 code_prefix, 无则 EMP。创建后固定不随改岗位变动。
    private function nextEmployeeCode($role)
    {
        $prefix = ($role && $role->code_prefix) ? strtoupper(trim($role->code_prefix)) : 'EMP';
        $max = 0;
        foreach (Admin::where('employee_code', 'like', $prefix . '-%')->pluck('employee_code') as $code) {
            if (preg_match('/-(\d+)$/', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $prefix . '-' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    function employee_list_export(Request $request)
    {
        try{
            $key = explode(' ', $request['search']);
            $em=Admin::zone()->with(['role'])->where('role_id', '!=','1')
            ->when(isset($key) , function($q) use($key){
                $q->where(function ($q) use ($key) {
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
                'employees'=>$em,
                'search'=>$request->search??null,
            ];

            if ($request->type == 'excel') {
                return Excel::download(new EmployeeListExport($data), 'Employees.xlsx');
            } else if ($request->type == 'csv') {
                return Excel::download(new EmployeeListExport($data), 'Employees.csv');
            }

        } catch(\Exception $e) {
                Toastr::error("line___{$e->getLine()}",$e->getMessage());
                info(["line___{$e->getLine()}",$e->getMessage()]);
                return back();
            }
    }
}
