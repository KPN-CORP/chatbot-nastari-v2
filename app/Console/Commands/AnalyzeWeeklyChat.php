<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DailyChatLog;
use App\Models\UserNastari;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class AnalyzeWeeklyChat extends Command
{
    protected $signature = 'chat:analyze-weekly {--force}';
    protected $description = 'Analyze weekly chat logs using Google Gemini per BU';

    public function handle()
    {
        $this->info('Starting BU-based Weekly Analysis...');

        if ($this->option('force')) {
            $startDate = Carbon::now()->startOfWeek();
            $endDate = Carbon::now()->endOfWeek();
        } else {
            $startDate = Carbon::now()->subDays(7)->startOfDay();
            $endDate = Carbon::now()->endOfDay();
        }

        $this->info("Period: {$startDate->toDateString()} - {$endDate->toDateString()}");

        $businessUnits = UserNastari::registered()->whereNotNull('group_company')
            ->where('group_company', '!=', '')
            ->distinct()
            ->pluck('group_company');

        if ($businessUnits->isEmpty()) {
            $this->error('No Business Units found.');
            return;
        }

        

        foreach ($businessUnits as $bu) {
            $this->info("Analyzing BU: {$bu}...");

            $logs = DailyChatLog::join('usernastari', 'daily_chat_logs.employee_id', '=', 'usernastari.employee_id')
                ->where('usernastari.group_company', $bu)
                ->whereBetween('daily_chat_logs.date', [$startDate, $endDate])
                ->select('daily_chat_logs.*')
                ->get();

            if ($logs->isEmpty()) {
                $this->warn("No chats found for BU: {$bu}");
                continue;
            }

            $uniqueUsers = $logs->unique('employee_id')->count();
            $chatBuffer = "";

            foreach ($logs as $log) {
                if (!empty($log->messages) && is_array($log->messages)) {
                    foreach ($log->messages as $msg) {
                        $sender = $msg['sender'] ?? $msg['role'] ?? '';
                        if (strtolower($sender) === 'user') {
                            $text = $msg['message'] ?? $msg['text'] ?? '';
                            if (strlen($text) > 3 && !in_array(strtolower($text), ['pagi', 'siap', 'oke', 'thanks', 'test', 'halo'])) {
                                $chatBuffer .= "- $text\n";
                            }
                        }
                    }
                }
            }

            if (empty($chatBuffer)) {
                $this->warn("No meaningful chats for {$bu}");
                continue;
            }

            $prompt = "
                Role: Human Capital Analyst.
                Business Unit Context: $bu
                Task: Analyze these employee chats to identify cultural issues or operational problems specifically for this BU.
                
                Chats:
                $chatBuffer
                
                Output strictly in JSON format (no markdown):
                {
                    \"sentiment\": \"Positive/Neutral/Negative/Anxious\",
                    \"summary\": \"One paragraph executive summary of the mood in $bu.\",
                    \"issues\": [
                        {
                            \"topic\": \"Specific Topic Name\",
                            \"analysis\": \"Detailed explanation.\",
                            \"evidence\": [\"quote\"]
                        }
                    ]
                }
            ";

            $apiKey = env('GEMINI_API_KEY');
            $model = env('GEMINI_MODEL', 'gemini-2.0-flash');
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            try {
                $response = Http::withHeaders(['Content-Type' => 'application/json'])
                    ->timeout(60)
                    ->post($url, [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]]
                        ],
                        'generationConfig' => [
                            'temperature' => 0.4,
                            'responseMimeType' => 'application/json'
                        ]
                    ]);

                if ($response->failed()) {
                    $this->error("API Error for {$bu}: " . $response->body());
                    continue;
                }

                $json = $response->json();
                $rawText = $json['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
                $cleanJson = str_replace(['```json', '```'], '', $rawText);
                $result = json_decode($cleanJson, true);

                DB::table('chat_insights')->updateOrInsert(
                    [
                        'start_date' => $startDate->toDateString(), 
                        'end_date' => $endDate->toDateString(),
                        'period_type' => 'weekly',
                        'business_unit' => $bu
                    ],
                    [
                        'total_chats' => $logs->count(),
                        'total_users' => $uniqueUsers,
                        'dominant_sentiment' => $result['sentiment'] ?? 'Neutral',
                        'top_topics' => json_encode($result['issues'] ?? []),
                        'summary' => $result['summary'] ?? 'No summary available.',
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                );

                $this->info("Analysis for {$bu} saved!");

            } catch (\Exception $e) {
                $this->error("System Error for {$bu}: " . $e->getMessage());
            }
        }

        $this->info('Full Weekly Analysis Completed!');
    }
}