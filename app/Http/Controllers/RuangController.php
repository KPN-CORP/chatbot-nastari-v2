<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DailyChatLog;
use App\Models\UserNastari; 
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RuangController extends Controller
{
    public function index(Request $request)
    {
        $year = $request->get('year', date('Y'));
        $month = $request->get('month', date('m'));
        $filterBu = $request->get('bu');
        $selectedId = $request->get('id');

        $subQuery = DailyChatLog::join('usernastari', 'daily_chat_logs.employee_id', '=', 'usernastari.employee_id')
            ->scoped()
            ->select(DB::raw('MAX(daily_chat_logs.id) as id'))
            ->whereYear('daily_chat_logs.date', $year)
            ->whereMonth('daily_chat_logs.date', $month)
            ->groupBy('daily_chat_logs.employee_id');

        $query = DailyChatLog::whereIn('daily_chat_logs.id', $subQuery)
            ->join('usernastari', 'daily_chat_logs.employee_id', '=', 'usernastari.employee_id')
            ->select(
                'daily_chat_logs.*', 
                'usernastari.full_name', 
                'usernastari.group_company', 
                'usernastari.whatsapp_number'
            )
            ->orderBy('daily_chat_logs.date', 'desc')
            ->orderBy('daily_chat_logs.updated_at', 'desc');

        if ($filterBu) {
            $query->where(function($q) use ($filterBu) {
                $q->where('usernastari.group_company', $filterBu)
                  ->orWhere('usernastari.group_company', 'LIKE', "%{$filterBu}%");
            });
        }

        $chatList = $query->get()->map(function($log) {
            $msgs = $log->messages ?? [];
            if (!empty($msgs)) {
                $lastMsg = end($msgs);
                $log->preview = $lastMsg['text'] ?? $lastMsg['message'] ?? '-';
            } else {
                $log->preview = '-';
            }
            $log->time = Carbon::parse($log->updated_at)->diffForHumans();
            $log->employee_name_joined = $log->full_name ?? '-'; 
            return $log;
        });

        $activeChat = null;
        if ($selectedId) {
            $currentLog = DailyChatLog::join('usernastari', 'daily_chat_logs.employee_id', '=', 'usernastari.employee_id')
                ->scoped()
                ->where('daily_chat_logs.id', $selectedId)
                ->select(
                    'daily_chat_logs.*', 
                    'usernastari.full_name', 
                    'usernastari.group_company', 
                    'usernastari.whatsapp_number'
                )
                ->first();
            
            if ($currentLog) {
                $allLogs = DailyChatLog::join('usernastari', 'daily_chat_logs.employee_id', '=', 'usernastari.employee_id')
                    ->scoped()
                    ->where('daily_chat_logs.employee_id', $currentLog->employee_id)
                    ->whereYear('daily_chat_logs.date', $year)
                    ->whereMonth('daily_chat_logs.date', $month)
                    ->orderBy('daily_chat_logs.date', 'asc')
                    ->select('daily_chat_logs.*')
                    ->get();

                $mergedMessages = [];
                foreach ($allLogs as $log) {
                    if (!empty($log->messages)) {
                        foreach ($log->messages as $msg) {
                            $msg['original_date'] = $log->date->format('Y-m-d');
                            $msg['text'] = $msg['text'] ?? $msg['message'] ?? '';
                            $mergedMessages[] = $msg;
                        }
                    }
                }

                usort($mergedMessages, function($a, $b) {
    // Use the null coalescing operator (??) to provide a fallback date if the key is missing
    $timeA = strtotime($a['timestamp'] ?? '1970-01-01 00:00:00');
    $timeB = strtotime($b['timestamp'] ?? '1970-01-01 00:00:00');
    
    return $timeA <=> $timeB; // The spaceship operator (<=>) is perfect for usort
});

                $activeChat = [
                    'meta_user' => [
                        'name' => $currentLog->full_name,
                        'employee_id' => $currentLog->employee_id,
                        'bu' => $currentLog->group_company,
                        'phone' => $currentLog->whatsapp_number,
                    ],
                    'history_chat' => $mergedMessages
                ];
            }
        }

        $businessUnits = UserNastari::scoped()->registered()
            ->select('group_company')
            ->whereNotNull('group_company')
            ->where('group_company', '!=', '')
            ->distinct()
            ->pluck('group_company');

        return view('admin.ruang', compact('chatList', 'activeChat', 'selectedId', 'businessUnits', 'year', 'month', 'filterBu'));
    }
}