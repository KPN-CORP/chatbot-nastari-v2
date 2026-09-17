<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AnalyticsService;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $year = $request->input('year', date('Y'));
        $month = $request->input('month', date('m'));

        $analyticsService = new AnalyticsService();
        $data = $analyticsService->analyzeMonth($year, $month);

        return view('dashboard.index', [
            'stats' => $data,
            'selectedYear' => $year,
            'selectedMonth' => $month
        ]);
    }
}