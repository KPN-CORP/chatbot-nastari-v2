<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LetterLog;

class LetterLogController extends Controller
{
    public function index()
    {
        $logs = LetterLog::orderBy('created_at', 'desc')->get();
        return view('admin.letter_logs.index', compact('logs'));
    }

    public function download($id)
    {
        $log = LetterLog::findOrFail($id);
        if (file_exists($log->file_path)) {
            return response()->file($log->file_path);
        }
        return back()->with('error', 'File fisik sudah tidak ditemukan di server.');
    }
}