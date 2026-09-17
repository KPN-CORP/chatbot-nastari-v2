<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ManagerAssistantService
{
    protected $darwin;
    protected $whatsapp;
    protected $apiKey;
    protected $model;

    public function __construct(DarwinboxService $darwin, WhatsAppSenderService $whatsapp)
    {
        $this->darwin = $darwin;
        $this->whatsapp = $whatsapp;
        $this->apiKey = env('GEMINI_API_KEY');
        $this->model = env('GEMINI_MODEL', 'gemini-2.0-flash');
    }

    public function handleQuery($userMobileNo, $question, $managerEmployee)
    {
        $cleanQuestion = strtolower(trim($question));
        
       if (in_array($cleanQuestion, ['selesai', '/selesai', 'selesai ✅'])) {
            $this->whatsapp->sendMessage($userMobileNo, "👍 Kamu sudah selesai pakai fitur Nastari AI. Selanjutnya, kamu akan diarahkan ke Menu Utama 😉");
            
            $this->darwin->saveUserState($userMobileNo, ['state' => 'idle', 'data' => []]);
            
            $clockIn = $this->darwin->getTodayClockIn($managerEmployee['employee_id']);
            
            sleep(1); 
            $this->whatsapp->sendMainMenu($userMobileNo, $managerEmployee, $clockIn);
            
            return;
        }

        $nestedTeamData = $this->darwin->getTeamData($managerEmployee);
        if (empty($nestedTeamData)) {
            $this->whatsapp->sendMessage($userMobileNo, "Anda tidak memiliki tim untuk ditanyakan.");
            return;
        }

        $teamData = $this->flattenTeamData($nestedTeamData);
        $parsedQuery = null;

        if (preg_match('/^\s*(?:tim|team|anggota|bawahan|list)\s+(.+)/i', $cleanQuestion, $matches)) {
            $parsedQuery = [
                'intent' => 'get_team_list',
                'employee_identifier' => trim($matches[1])
            ];
        } elseif (preg_match('/^\s*(?:cuti|sisa cuti|saldo cuti)\s+(.+)/i', $cleanQuestion, $matches)) {
            $parsedQuery = [
                'intent' => 'get_leave_balance',
                'employee_identifier' => trim($matches[1])
            ];
        } elseif (preg_match('/^\s*(?:absensi|absen|kehadiran|hadir|masuk)\s+(.+)/i', $cleanQuestion, $matches)) {
            $parsedQuery = [
                'intent' => 'get_attendance_history',
                'employee_identifier' => trim($matches[1])
            ];
        } elseif (preg_match('/^\s*(?:riwayat terlambat|telat|late)\s+(.+)/i', $cleanQuestion, $matches)) {
            $parsedQuery = [
                'intent' => 'get_late_history',
                'employee_identifier' => trim($matches[1])
            ];
        } elseif (preg_match('/^\s*(?:overtime|lembur|ot)\s+(.+)/i', $cleanQuestion, $matches)) {
            $parsedQuery = [
                'intent' => 'get_overtime',
                'employee_identifier' => trim($matches[1])
            ];
        } elseif (preg_match('/^\s*(?:data|profil|info|detail)\s+(.+)/i', $cleanQuestion, $matches)) {
            $parsedQuery = [
                'intent' => 'get_general_data',
                'employee_identifier' => trim($matches[1])
            ];
        } elseif (preg_match('/^\s*(?:single punch|lupa absen|lupa clock)\s+(.+)/i', $cleanQuestion, $matches)) {
            $parsedQuery = [
                'intent' => 'get_single_punch',
                'employee_identifier' => trim($matches[1])
            ];
        }

        if (!$parsedQuery) {
            $parsedQuery = $this->parseQueryWithGemini($question);
        }

        if (!$parsedQuery || !isset($parsedQuery['employee_identifier'])) {
            $this->whatsapp->sendMessage($userMobileNo, "Mohon maaf, *Nastari* belum dapat memahami permintaan Anda. Coba gunakan format: `tim saya`, `data Adrian`, atau `absensi Budi`.");
            return;
        }

        $identifier = strtolower(trim($parsedQuery['employee_identifier']));

        if (in_array($identifier, ['tim', 'team', 'anggota', 'tim saya', 'timku', 'bawahan', 'anak buah'])) {
            $identifier = 'saya';
        }

        $matchedEmployees = [];

        if (is_numeric($identifier)) {
            if (isset($teamData[$identifier])) {
                $matchedEmployees[] = $teamData[$identifier];
            }
        } else {
            if (in_array($identifier, ['saya', 'ku', 'aku'])) {
                $matchedEmployees[] = $managerEmployee;
            } else {
                foreach ($teamData as $member) {
                    if (stripos($member['full_name'], $identifier) !== false) {
                        $matchedEmployees[] = $member;
                    }
                }
            }
        }

        if (empty($matchedEmployees)) {
            $this->whatsapp->sendMessage($userMobileNo, "Karyawan dengan nama '{$identifier}' tidak ditemukan di dalam struktur tim Anda.");
            return;
        }

        if (count($matchedEmployees) > 1) {
            $this->sendClarificationMessage($userMobileNo, $matchedEmployees, $parsedQuery);
            return;
        }

        $targetEmployee = $matchedEmployees[0];
        $this->executeIntent($userMobileNo, $parsedQuery, $managerEmployee, $targetEmployee);
        $this->whatsapp->sendAIFollowUpMessage($userMobileNo);
    }

    public function executeIntent($userMobileNo, $parsedQuery, $managerEmployee, $targetEmployee)
    {
        $intent = $parsedQuery['intent'] ?? 'get_general_data';

        switch ($intent) {
            case 'get_leave_balance':
                $balanceData = $this->darwin->getLeaveBalance($targetEmployee['employee_id']);
                $msg = $this->darwin->formatLeaveBalance($targetEmployee, $balanceData);
                $this->whatsapp->sendMessage($userMobileNo, $msg);
                break;
                
            case 'get_general_data':
                $msg = $this->darwin->formatPersonalData($targetEmployee);
                $this->whatsapp->sendMessage($userMobileNo, $msg);
                break;
            
            case 'get_late_history':
                $attendanceData = $this->darwin->getAttendanceData($targetEmployee['employee_id']);
                $msg = $this->darwin->formatLateHistory($targetEmployee, $attendanceData);
                $this->whatsapp->sendMessage($userMobileNo, $msg);
                break;
                
            case 'get_team_list':
                $this->whatsapp->sendMessage($userMobileNo, "Mengecek daftar tim untuk *{$targetEmployee['full_name']}*...");
                $team = $this->darwin->getTeamData($targetEmployee);
                if(empty($team)) {
                    $this->whatsapp->sendMessage($userMobileNo, "Tidak ditemukan bawahan langsung di bawah {$targetEmployee['full_name']}.");
                } else {
                    $msg = "👥 *Anggota Tim {$targetEmployee['full_name']}*:\n\n";
                    foreach($team as $t) {
                        $msg .= "- {$t['full_name']} ({$t['employee_id']})\n";
                    }
                    $this->whatsapp->sendMessage($userMobileNo, $msg);
                }
                break;
                
            case 'get_attendance_history':
                $this->whatsapp->sendMessage($userMobileNo, "Mengecek riwayat absensi 7 hari terakhir untuk *{$targetEmployee['full_name']}*...");
                $att = $this->darwin->getAttendanceData($targetEmployee['employee_id']);
                $msg = "📅 *Data Kehadiran Terakhir*\nNama: {$targetEmployee['full_name']}\n\n";
                if ($att && is_array($att)) {
                    $latestAtt = array_reverse($att);
                    $showData = array_slice($latestAtt, 0, 7);
                    foreach($showData as $row) {
                        $date = $row[0] ?? '-'; 
                        $in = $row[22] ?? '-'; 
                        $out = $row[23] ?? '-';
                        $status = $row[21] ?? '-';
                        $msg .= "Tgl: $date\nStatus: $status\nIn: $in | Out: $out\n--\n";
                    }
                } else {
                    $msg .= "Tidak ada data absensi ditemukan.";
                }
                $this->whatsapp->sendMessage($userMobileNo, $msg);
                break;

            case 'get_single_punch':
                $sp = $this->darwin->checkSinglePunchForEmployee($targetEmployee['employee_id']);
                if (empty($sp)) {
                    $this->whatsapp->sendMessage($userMobileNo, "✅ Tidak ada Single Punch untuk {$targetEmployee['full_name']} bulan ini.");
                } else {
                    $msg = "⚠️ *Single Punch Detect - {$targetEmployee['full_name']}*\n";
                    foreach($sp as $d) $msg .= "- $d\n";
                    $this->whatsapp->sendMessage($userMobileNo, $msg);
                }
                break;
                
            case 'get_overtime':
                $ot = $this->darwin->getOvertimeData($targetEmployee['employee_id']);
                $msg = $this->darwin->formatOvertimeData($targetEmployee, $ot);
                $this->whatsapp->sendMessage($userMobileNo, $msg);
                break;
                
            default:
                $this->whatsapp->sendMessage($userMobileNo, "Saya mengerti Anda menanyakan tentang {$targetEmployee['full_name']}, tapi saya belum bisa menjawab jenis pertanyaan tersebut.");
        }
    }

    protected function flattenTeamData($nestedTeamData)
    {
        $flatTeam = [];
        $flattener = function($currentLevel) use (&$flattener, &$flatTeam) {
            foreach ($currentLevel as $employeeId => $member) {
                $memberData = $member;
                if(isset($memberData['team'])) {
                    $childTeam = $memberData['team'];
                    unset($memberData['team']);
                    $flatTeam[$employeeId] = $memberData;
                    if (!empty($childTeam) && is_array($childTeam)) {
                        $flattener($childTeam);
                    }
                } else {
                    $flatTeam[$employeeId] = $memberData;
                }
            }
        };
        $flattener($nestedTeamData);
        return $flatTeam;
    }

    protected function parseQueryWithGemini($question)
    {
        $prompt = "You are a JSON API. Extract 'intent' and 'employee_identifier' from: \"{$question}\".
        Intents: get_team_list, get_leave_balance, get_late_history, get_single_punch, get_attendance_history, get_overtime, get_general_data.
        Rules: Identifier is name or ID. Return ONLY JSON Object.";

        try {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post($url, [
                    'contents' => [['parts' => [['text' => "Analyze: \"{$question}\". " . $prompt]]]],
                    'generationConfig' => ['response_mime_type' => 'application/json']
                ]);
            if ($response->failed()) return null;
            $json = $response->json();
            $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if (!$text) return null;
            $text = str_replace(['```json', '```'], '', $text);
            return json_decode($text, true);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function sendClarificationMessage($userMobileNo, $matchedEmployees, $parsedQuery)
    {
        $msg = "Ditemukan beberapa nama yang sama. Balas dengan *ID* yang benar:\n\n";
        foreach ($matchedEmployees as $emp) {
            $msg .= "🆔 *{$emp['employee_id']}* - {$emp['full_name']}\n";
        }
        $this->whatsapp->sendMessage($userMobileNo, $msg);
        $this->darwin->saveUserState($userMobileNo, [
            'state' => 'ai_session_clarification',
            'data' => [
                'original_parsed_query' => $parsedQuery,
                'matched_employees' => $matchedEmployees
            ]
        ]);
    }
}