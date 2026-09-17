<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Role;
use App\Models\Employee;

class RoleController extends Controller
{
    public function index()
    {
        $roles = DB::table('roles')->orderBy('created_at', 'desc')->get();
        
        $pivotData = DB::table('role_user')->get();

        $roles->transform(function($role) use ($pivotData) {
            $rawIds = $pivotData->where('role_id', $role->id)->pluck('user_id')->toArray();
            
            $stringIds = array_map('strval', $rawIds);
            
            $role->assigned_users = array_values($stringIds);
            $role->users_count = count($stringIds);
            
            return $role;
        });

        $businessUnits = Employee::select('group_company')->whereNotNull('group_company')->distinct()->orderBy('group_company')->pluck('group_company');
        $companies = Employee::select('company_name')->whereNotNull('company_name')->distinct()->orderBy('company_name')->pluck('company_name');
        $locations = Employee::select('office_area', 'work_area_code')->whereNotNull('work_area_code')->distinct()->orderBy('office_area')->get();
        $users = Employee::select('id', 'fullname', 'employee_id')->whereNull('deleted_at')->orderBy('fullname')->get();

        return view('admin.settings.roles.create', compact('roles', 'businessUnits', 'companies', 'locations', 'users'));
    }

    public function create()
    {
        return $this->index();
    }

    public function getFilterUsers(Request $request)
    {
        $bu = $request->bu; 
        $compName = $request->company; 
        $locCode = $request->location; 

        $query = Employee::select('id', 'fullname', 'employee_id')->whereNull('deleted_at');

        if (!empty($bu)) {
            $query->whereIn('group_company', (array)$bu);
        }
        if (!empty($compName)) {
            $query->whereIn('company_name', (array)$compName);
        }
        if (!empty($locCode)) {
            $query->whereIn('work_area_code', (array)$locCode);
        }

        $users = $query->orderBy('fullname')->get();
        
        $formattedUsers = $users->map(function($user) {
            return [
                'id' => $user->employee_id,
                'text' => $user->employee_id . ' - ' . $user->fullname
            ];
        });

        return response()->json($formattedUsers);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'users' => 'nullable|array'
        ]);

        DB::beginTransaction();
        try {
            $roleId = DB::table('roles')->insertGetId([
                'name' => $request->name,
                'scope_bu' => !empty($request->scope_bu) ? json_encode($request->scope_bu) : null,
                'scope_company' => !empty($request->scope_company) ? json_encode($request->scope_company) : null,
                'scope_location' => !empty($request->scope_location) ? json_encode($request->scope_location) : null,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            if ($request->has('users') && !empty($request->users)) {
                $pivotData = [];
                $now = now();
                foreach ($request->users as $userNik) {
                    $pivotData[] = [
                        'role_id' => $roleId,
                        'user_id' => $userNik,
                        'created_at' => $now,
                        'updated_at' => $now
                    ];
                }
                if (count($pivotData) > 0) {
                    DB::table('role_user')->insert($pivotData);
                }
            }

            DB::commit();
            return redirect()->route('admin.setting.roles.create')->with('success', 'Role successfully created!');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Error creating role: ' . $e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'users' => 'nullable|array'
        ]);

        DB::beginTransaction();
        try {
            DB::table('roles')->where('id', $id)->update([
                'name' => $request->name,
                'scope_bu' => !empty($request->scope_bu) ? json_encode($request->scope_bu) : null,
                'scope_company' => !empty($request->scope_company) ? json_encode($request->scope_company) : null,
                'scope_location' => !empty($request->scope_location) ? json_encode($request->scope_location) : null,
                'updated_at' => now()
            ]);

            DB::table('role_user')->where('role_id', $id)->delete();
            
            if ($request->has('users') && !empty($request->users)) {
                $pivotData = [];
                $now = now();
                foreach ($request->users as $userNik) {
                    $pivotData[] = [
                        'role_id' => $id,
                        'user_id' => $userNik,
                        'created_at' => $now,
                        'updated_at' => $now
                    ];
                }
                if (count($pivotData) > 0) {
                    DB::table('role_user')->insert($pivotData);
                }
            }

            DB::commit();
            return redirect()->route('admin.setting.roles.create')->with('success', 'Role updated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Error updating role: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            DB::table('role_user')->where('role_id', $id)->delete();
            DB::table('roles')->where('id', $id)->delete();
            
            DB::commit();
            return back()->with('success', 'Role deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Error deleting role');
        }
    }
    
    public function edit($id) { return redirect()->route('admin.setting.roles.create'); }
}