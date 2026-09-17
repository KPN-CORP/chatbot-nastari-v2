<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\CallCenter;
use App\Models\Employee;
use File;

class CallCenterController extends Controller
{
    public function index(Request $request)
    {
        $allowedEmployeeIds = Employee::scoped()->pluck('employee_id')->toArray();

        if (empty($allowedEmployeeIds)) {
            return view('admin.callcenter.index', ['data' => []]);
        }

        $query = CallCenter::with('employee')
            ->whereIn('employee_id', $allowedEmployeeIds);

        $data = $query->orderBy('ticket_date', 'desc')->get();

        return view('admin.callcenter.index', compact('data'));
    }

    public function download($id)
    {
        $ticket = CallCenter::findOrFail($id);

        if (!$ticket->image_path) {
            abort(404, 'Path tidak ditemukan di database');
        }

        $pathInDb = ltrim($ticket->image_path, '/');
        
        $fullPath = storage_path('app/private/' . $pathInDb);

        if (!file_exists($fullPath)) {
            $fullPath = storage_path('app/' . $pathInDb);
        }

        if (file_exists($fullPath)) {
            $mimeType = File::mimeType($fullPath);
            return response()->file($fullPath, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline'
            ]);
        }

        abort(404, 'File fisik tidak ada di storage: ' . $fullPath);
    }

    public function export()
    {
        $allowedEmployeeIds = Employee::scoped()->pluck('employee_id')->toArray();

        return response()->streamDownload(function () use ($allowedEmployeeIds) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Tanggal Komplain', 'Employee ID', 'Nama', 'No HP', 'Isi Komplain']);

            $query = CallCenter::query();
            if (!empty($allowedEmployeeIds)) {
                $query->whereIn('employee_id', $allowedEmployeeIds);
            }

            $query->orderBy('ticket_date', 'desc')->chunk(200, function ($tickets) use ($handle) {
                foreach ($tickets as $ticket) {
                    fputcsv($handle, [
                        $ticket->ticket_date,
                        $ticket->employee_id,
                        $ticket->name,
                        $ticket->mobile,
                        $ticket->complaint
                    ]);
                }
            });

            fclose($handle);
        }, 'call_center_report_' . date('Y-m-d_H-i') . '.csv');
    }
}