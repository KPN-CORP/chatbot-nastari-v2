<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HcoMappingController extends Controller
{
    public function index()
    {
        $mappings = DB::table('hco_mappings')->get();
        
        $locations = DB::connection('kpncorp')->table('locations')
            ->select('work_area', 'area', 'company_name')
            ->orderBy('company_name')
            ->orderBy('area')
            ->get();

        $admins = DB::connection('kpncorp')->table('employees')
            ->select('employee_id', 'fullname')
            ->where('deleted_at', NULL)
            ->orderBy('fullname', 'asc')
            ->get();

        return view('admin.hco_mapping.index', compact('mappings', 'locations', 'admins'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'location_data' => 'required',
            'hco_employee_id' => 'required'
        ]);

        list($work_area, $area, $company) = explode('|', $request->location_data);
            
        $hco = DB::connection('kpncorp')->table('employees')
            ->where('employee_id', $request->hco_employee_id)->first();

        DB::table('hco_mappings')->updateOrInsert(
            ['work_area_code' => $work_area, 'company_name' => $company],
            [
                'work_area_name' => $area,
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