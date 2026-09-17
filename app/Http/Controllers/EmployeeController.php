<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserNastari; 

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = UserNastari::scoped()->registered();

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('employee_id', 'like', "%{$search}%")
                  ->orWhere('group_company', 'like', "%{$search}%");
            });
        }

        $employees = $query->orderBy('full_name', 'asc')->get();

        $users = $employees->map(function ($employee) {
            return [
                'name' => $employee->full_name ?? '-',
                'employee_id' => $employee->employee_id,
                'phone' => $employee->whatsapp_number ?? $employee->phone_number ?? '-',
                'bu' => $employee->group_company ?? '-',
                'position' => $employee->designation_name ?? '-',
                'email' => $employee->email ?? '-',
                'unit' => $employee->unit_name ?? '-',
                'full_data' => [
                    'job_level' => $employee->job_level ?? '-',
                    'office_area' => $employee->office_area ?? '-',
                    'contribution_level' => $employee->contribution_level ?? '-',
                    'date_of_joining' => $employee->date_of_joining ?? '-',
                    'direct_manager' => $employee->direct_manager ?? '-',
                    'status' => $employee->employment_status === 'inactive' ? 'Inactive' : 'Registered',
                    'join_in' => $employee->created_at ? $employee->created_at->format('Y-m-d') : '-',
                ]
            ];
        });

        return view('admin.users', compact('users'));
    }
}