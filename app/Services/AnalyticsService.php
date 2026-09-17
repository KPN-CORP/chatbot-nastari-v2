<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class AnalyticsService
{
    public function analyzeMonth($year, $month)
    {
        $targetDir = "data-ruang/{$year}/{$month}";
        $files = Storage::allFiles($targetDir);
        
        $stats = [
            'total_files' => 0,
            'topics' => []
        ];

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'json') continue;

            $content = Storage::get($file);
            $data = json_decode($content, true);

            $stats['total_files']++;

            if (isset($data['history_chat'])) {
                foreach ($data['history_chat'] as $chat) {
                    $topic = $chat['topic'] ?? 'Uncategorized';
                    
                    if (!isset($stats['topics'][$topic])) {
                        $stats['topics'][$topic] = 0;
                    }
                    $stats['topics'][$topic]++;
                }
            }
        }

        arsort($stats['topics']);

        return $stats;
    }
}