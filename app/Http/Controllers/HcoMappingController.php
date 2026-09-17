<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HcoMappingController extends Controller
{
    public function index()
    {
        $mappings = DB::table('hco_mappings')->get();
        
        $businessUnits = DB::connection('kpncorp')->table('employees')
            ->select('group_company')
            ->whereNotNull('group_company')
            ->where('group_company', '!=', '')
            ->distinct()
            ->orderBy('group_company', 'asc')
            ->get();

        $admins = DB::connection('kpncorp')->table('employees')
            ->select('employee_id', 'fullname')
            ->whereNull('deleted_at')
            ->orderBy('fullname', 'asc')
            ->get();

        return view('admin.hco_mapping.index', compact('mappings', 'businessUnits', 'admins'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'group_company' => 'required',
            'hco_employee_id' => 'required'
        ]);
            
        $hco = DB::connection('kpncorp')->table('employees')
            ->where('employee_id', $request->hco_employee_id)->first();

        DB::table('hco_mappings')->updateOrInsert(
            ['group_company' => $request->group_company],
            [
                'hco_employee_id' => $hco->employee_id,
                'hco_name' => $hco->fullname,
                'updated_at' => now()
            ]
        );

        return back()->with('success', 'HCO Mapping updated.');
    }

    public function destroy($id)
    {
        DB::table('hco_mappings')->where('id', $id)->delete();
        return back()->with('success', 'Mapping deleted.');
    }
}