<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function storeChat(Request $request)
    {
        $user = $request->input('user');
        $message = $request->input('message');
        
        $now = Carbon::now();
        $year = $now->format('Y');
        $month = $now->format('m');
        $buSlug = Str::slug($user['bu']);
        
        $directory = "data-ruang/{$year}/{$month}/{$buSlug}";
        $filename = "{$user['employee_id']}_{$user['phone']}.json";
        $filePath = "{$directory}/{$filename}";

        if (Storage::exists($filePath)) {
            $fileContent = Storage::get($filePath);
            $jsonData = json_decode($fileContent, true);
        } else {
            $jsonData = [
                'meta_user' => [
                    'name' => $user['name'],
                    'employee_id' => $user['employee_id'],
                    'bu' => $user['bu'],
                    'phone' => $user['phone'],
                ],
                'history_chat' => []
            ];
        }

        $jsonData['history_chat'][] = [
            'timestamp' => $now->toDateTimeString(),
            'sender'    => $message['sender'],
            'topic'     => $message['topic'] ?? 'General',
            'text'      => $message['text']
        ];

        Storage::put($filePath, json_encode($jsonData, JSON_PRETTY_PRINT));

        return response()->json(['status' => 'success', 'path' => $filePath]);
    }
}