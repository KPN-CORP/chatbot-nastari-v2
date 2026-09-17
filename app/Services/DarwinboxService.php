<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\UserNastari;
use App\Models\LetterLog;
use App\Models\CallCenter;
use DateTime;
use FPDF;

class DarwinboxService
{
    protected $baseUrl = "https://kpncorporation.darwinbox.com";
    protected $hcisUrl = "https://apps.hcis.live/apiemployees";
    protected $authHeader;
    protected $datasetKey;
    protected $geminiConfig;
    protected $letterheads;
    protected $letterNumbers;

    public function __construct(
        LetterheadResolver $letterheads = null,
        LetterNumberService $letterNumbers = null
    ) {
        $token = config('services.darwinbox.auth_token') ?? env('DARWINBOX_AUTH_TOKEN');
        $this->authHeader = "Basic " . $token;
        $this->datasetKey = config('services.darwinbox.dataset_key') ?? env('DARWINBOX_DATASET_KEY');
        $this->geminiConfig = config('services.gemini');

        // Defaulted rather than required: this service is instantiated directly
        // with `new DarwinboxService()` in a few places, and a letter must not
        // stop being generated because of how the service was constructed.
        $this->letterheads = $letterheads ?? new LetterheadResolver();
        $this->letterNumbers = $letterNumbers ?? new LetterNumberService();
    }

    /**
     * Draws the company letterhead and returns the Y position to continue at.
     *
     * All three letter templates shared a copy of this block. It is centralised
     * here so the memory guard in LetterheadResolver cannot be bypassed by one
     * template being updated and the others not.
     */
    private function drawLetterhead($pdf, $companyName, $topMargin)
    {
        $letterhead = $this->letterheads->resolve($companyName);

        if ($letterhead === null) {
            // No usable letterhead: the letter is still issued, just headerless.
            return $topMargin;
        }

        $fit = $this->letterheads->fitToBox($letterhead['width'], $letterhead['height']);

        $pdf->Image($letterhead['path'], $fit['x'], 0, $fit['width'], $fit['height']);

        return $fit['height'] + 10;
    }

    private function getApiKey($keyName)
    {
        $key = config("services.darwinbox.api_keys.{$keyName}");
        if (!empty($key)) return $key;

        $envMap = [
            'employee'           => 'DARWINBOX_API_KEY_EMPLOYEE',
            'attendance'         => 'DARWINBOX_API_KEY_ATTENDANCE',
            'leave_balance'      => 'DARWINBOX_API_KEY_LEAVE_BALANCE',
            'dependent'          => 'DARWINBOX_API_KEY_DEPENDENT',
            'overtime'           => 'DARWINBOX_API_KEY_OVERTIME',
            'weeklyoff'          => 'DARWINBOX_API_KEY_WEEKLYOFF',
            'leave_transactions' => 'DARWINBOX_API_KEY_LEAVE_TRANSACTIONS',
            'update_leave'       => 'DARWINBOX_API_KEY_UPDATE_LEAVE',
            'holiday'            => 'DARWINBOX_API_KEY_HOLIDAYLIST'
        ];

        return isset($envMap[$keyName]) ? env($envMap[$keyName]) : '';
    }

    public function cleanMobileNumber($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '620')) {
            $phone = '62' . substr($phone, 3);
        }

        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }

        if (str_starts_with($phone, '8')) {
            $phone = '62' . $phone;
        }

        return $phone;
    }

    public function syncUserNastari($data)
    {
        $phoneRaw = $data['personal_mobile_no'] ?? '';
        $phoneClean = $this->cleanMobileNumber($phoneRaw);

        return UserNastari::updateOrCreate(
            ['employee_id' => $data['employee_id']],
            [
                // First contact promotes a row the daily employee sync had only
                // pre-registered from HRIS. Without this the bot would take the
                // slow Darwinbox lookup path for that person on every message.
                'registration_status' => 'registered',
                'full_name' => $data['full_name'],
                'whatsapp_number' => $phoneClean,
                'group_company' => $data['group_company'] ?? null,
                'contribution_level' => $data['contribution_level'] ?? null,
                'unit_name' => $data['unit_name'] ?? null,
                'designation_name' => $data['designation_name'] ?? null,
                'job_level' => $data['job_level'] ?? null,
                'office_area' => $data['office_area'] ?? null,
                'company_email_id' => $data['company_email_id'] ?? null,
                'direct_reportees_employee_id' => $data['direct_reportees_employee_id'] ?? null,
                'date_of_joining' => $data['date_of_joining'] ?? null,
                'direct_manager_id' => $data['direct_manager_employee_id'] ?? null,
                'direct_manager_name' => $data['direct_manager'] ?? null,
                'l2_manager_id' => $data['l2_manager_employee_id'] ?? null,
                'l2_manager_name' => $data['l2_manager'] ?? null,
                'employee_type' => $data['employee_type'] ?? null,
                'place_of_birth' => $data['place_of_birth'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
            ]
        );
    }

    public function getEmployeeByPhone($userMobileNo)
    {
        $targetPhone = $this->cleanMobileNumber($userMobileNo);
        Log::info("Mencari Karyawan (API) dengan No WA: " . $targetPhone);

        $response = Http::withHeaders(["Authorization" => $this->authHeader])
            ->post("{$this->baseUrl}/masterapi/employee", [
                "api_key" => $this->getApiKey('employee'),
                "datasetKey" => $this->datasetKey
            ]);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['status']) && $data['status'] == 1 && isset($data['employee_data'])) {
                foreach ($data['employee_data'] as $employee) {
                    $empPhoneRaw = $employee['personal_mobile_no'] ?? '';
                    $empPhoneClean = $this->cleanMobileNumber($empPhoneRaw);
                    
                    if ($empPhoneClean === $targetPhone) {
                        Log::info("Karyawan DITEMUKAN! NIK: " . $employee['employee_id']);
                        $this->syncUserNastari($employee); 
                        return $employee;
                    }
                }
            }
        }
        
        Log::warning("Karyawan TIDAK DITEMUKAN di API Darwinbox untuk No: $targetPhone");
        return null;
    }

    public function getEmployeeDataFromCache($userMobileNo)
    {
        $cleanPhone = $this->cleanMobileNumber($userMobileNo);
        $user = UserNastari::where('whatsapp_number', $cleanPhone)->first();
        
        if ($user) {
            return [
                'employee_id' => $user->employee_id,
                'full_name' => $user->full_name,
                'group_company' => $user->group_company,
                'unit_name' => $user->unit_name,
                'designation_name' => $user->designation_name,
                'job_level' => $user->job_level,
                'office_area' => $user->office_area,
                'company_email_id' => $user->company_email_id,
                'direct_reportees_employee_id' => $user->direct_reportees_employee_id,
                'date_of_joining' => $user->date_of_joining,
                'personal_mobile_no' => $user->whatsapp_number,
                'contribution_level' => $user->contribution_level,
                'direct_manager_employee_id' => $user->direct_manager_id,
                'direct_manager' => $user->direct_manager_name,
                'employee_type' => $user->employee_type
            ];
        }
        return null;
    }

    public function getUserState($userMobileNo)
    {
        $cleanPhone = $this->cleanMobileNumber($userMobileNo);
        return Cache::get('user_state_' . $cleanPhone, [
            'state' => 'idle',
            'data' => []
        ]);
    }

    public function saveUserState($userMobileNo, $newState)
    {
        $cleanPhone = $this->cleanMobileNumber($userMobileNo);
        $currentState = $this->getUserState($cleanPhone);
        
        $toSave = [
            'state' => $newState['state'] ?? $currentState['state'],
            'data' => isset($newState['data']) ? array_merge($currentState['data'] ?? [], $newState['data']) : ($currentState['data'] ?? [])
        ];

        if (($newState['state'] ?? '') === 'idle') {
            $toSave['data'] = [];
        }

        Cache::put('user_state_' . $cleanPhone, $toSave, 60 * 60 * 24); 
    }

    public function getAttendanceData($employee_id)
    {
        $response = Http::withHeaders(["Authorization" => $this->authHeader])
            ->post("{$this->baseUrl}/attendanceDataApi/DailyAttendanceRoster", [
                "api_key" => $this->getApiKey('attendance'),
                "emp_number_list" => [$employee_id],
                "from_date" => date("Y-m-01"),
                "to_date" => date("Y-m-t"),
            ]);
        
        return $response->json("emp_daily_attendance.attendance") ?? [];
    }

    public function getTodayClockIn($employee_id)
    {
        $data = $this->getAttendanceData($employee_id);
        if ($data && is_array($data)) {
            $today = date("d-M-Y");
            foreach ($data as $record) {
                if (isset($record[0]) && strpos($record[0], $today) !== false) {
                    return $record[22] ?? null;
                }
            }
        }
        return null;
    }

    public function getOvertimeData($employeeId)
    {
        return Http::withHeaders(["Authorization" => $this->authHeader])
            ->post("{$this->baseUrl}/AttendanceDataApi/OverTimeSlabsDatewiseRoster", [
                "api_key" => $this->getApiKey('overtime'),
                "from_date" => date('d-m-Y', strtotime('first day of this month')),
                "to_date" => date('d-m-Y', strtotime('last day of this month')),
                "employee_id" => [$employeeId],
                "status" => 1
            ])->json('emp_ot_slabs_datewise.ot_slabs_datewise') ?? [];
    }

    public function getLeaveBalance($employee_id)
    {
        return Http::withHeaders(["Authorization" => $this->authHeader])
            ->post("{$this->baseUrl}/leavesactionapi/leavebalance", [
                "api_key" => $this->getApiKey('leave_balance'),
                "ignore_rounding" => "1",
                "employee_nos" => [$employee_id],
            ])->json();
    }

    public function getDependentData($employeeNumber)
    {
        return Http::withHeaders(["Authorization" => $this->authHeader])
            ->post("{$this->baseUrl}/orgmasterapi/getDependentDetails", [
                "api_key" => $this->getApiKey('dependent'),
                "employee_number" => [$employeeNumber]
            ])->json('data') ?? [];
    }

    public function getMedicalData($employee_id)
    {
        $response = Http::get("{$this->hcisUrl}/$employee_id");
        return $response->successful() ? $response->json() : null;
    }

    public function getWeeklySchedule($employeeNo)
    {
        $response = Http::withHeaders(["Authorization" => $this->authHeader])
            ->post("{$this->baseUrl}/attendanceDataApi/shifttuplelist", [
                "api_key" => $this->getApiKey('weeklyoff'),
                "from" => date("d-m-Y"),
                "to" => date("d-m-Y", strtotime("+3 days")),
                "employee_no" => [$employeeNo],
            ]);
        
        return $response->json("data.$employeeNo") ?? [];
    }

    public function getWeeklyOffStatus($employeeNo)
    {
        $response = Http::withHeaders(["Authorization" => $this->authHeader])
            ->post("{$this->baseUrl}/attendanceDataApi/shifttuplelist", [
                "api_key" => $this->getApiKey('weeklyoff'),
                "from" => date("d-m-Y"),
                "to" => date("d-m-Y"),
                "employee_no" => [$employeeNo],
            ]);
            
        $schedule = $response->json("data.$employeeNo");
        $today = date("Y-m-d");
        return isset($schedule[$today]["weeklyoff_yes_no"]) && $schedule[$today]["weeklyoff_yes_no"] === "Yes";
    }

    public function getTeamData($managerEmployee)
    {
        $managerId = $managerEmployee['employee_id'];
        $cacheKey = 'team_data_recursive_' . $managerId;

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $allEmployeesData = [];
        $processedIds = [];
        $queue = [];

        if (!empty($managerEmployee['direct_reportees_employee_id'])) {
            $l1_ids = array_filter(array_map('trim', explode('|', $managerEmployee['direct_reportees_employee_id'])));
            foreach ($l1_ids as $id) {
                $queue[] = ['id' => $id, 'level' => 1];
            }
        }

        while (!empty($queue)) {
            $idsToFetch = array_column($queue, 'id');
            $levelMap = array_column($queue, 'level', 'id');
            $queue = [];

            $uniqueIds = array_diff(array_unique($idsToFetch), $processedIds);
            if (empty($uniqueIds)) continue;

            $batchData = $this->getEmployeeDataByIds($uniqueIds);
            $processedIds = array_merge($processedIds, $uniqueIds);

            foreach ($batchData as $empId => $empData) {
                if (!isset($allEmployeesData[$empId])) {
                    $allEmployeesData[$empId] = $empData;
                    
                    $currentLevel = $levelMap[$empId] ?? 1;
                    if ($currentLevel < 5 && !empty($empData['direct_reportees_employee_id'])) {
                        $nextIds = array_filter(array_map('trim', explode('|', $empData['direct_reportees_employee_id'])));
                        foreach ($nextIds as $nid) {
                            $queue[] = ['id' => $nid, 'level' => $currentLevel + 1];
                        }
                    }
                }
            }
        }

        $map = [];
        foreach ($allEmployeesData as $id => &$emp) {
            $emp['team'] = [];
            $map[$id] = &$emp;
        }
        unset($emp);

        $finalTeamStructure = [];
        foreach ($allEmployeesData as $id => &$emp) {
            $mId = $emp['direct_manager_employee_id'] ?? null;
            if ($mId && isset($map[$mId])) {
                $map[$mId]['team'][$id] = &$map[$id];
            }
            
            if ($mId === $managerId) {
                $finalTeamStructure[$id] = &$map[$id];
            }
        }
        unset($emp);

        if (!empty($finalTeamStructure)) {
            Cache::put($cacheKey, $finalTeamStructure, 60 * 60 * 24);
        }

        return $finalTeamStructure;
    }

    public function getEmployeeDataByIds(array $ids)
    {
        $result = [];
        foreach (array_chunk($ids, 30) as $chunk) {
            $response = Http::withHeaders(["Authorization" => $this->authHeader])
                ->post("{$this->baseUrl}/masterapi/employee", [
                    "api_key" => $this->getApiKey('employee'),
                    "datasetKey" => $this->datasetKey,
                    "employee_ids" => $chunk,
                ]);
            if ($response->successful()) {
                foreach ($response->json('employee_data') ?? [] as $emp) {
                    $result[$emp['employee_id']] = $emp;
                }
            }
        }
        return $result;
    }

    public function getTeamEmployeeIds($managerEmployee)
    {
        $ids = [$managerEmployee['employee_id']];
        $l1 = $this->getTeamData($managerEmployee);
        foreach ($l1 as $m) {
            $ids[] = $m['employee_id'];
            if (!empty($m['direct_reportees_employee_id'])) {
                $ids = array_merge($ids, explode('|', $m['direct_reportees_employee_id']));
            }
        }
        return array_unique($ids);
    }

    public function getLeaveRequests($manager_id)
    {
        $managerData = $this->getEmployeeDataByIds([$manager_id])[$manager_id] ?? [];
        $teamIds = $this->getTeamEmployeeIds($managerData);
        if (empty($teamIds)) return [];

        return Http::withHeaders(["Authorization" => $this->authHeader])
            ->post("{$this->baseUrl}/leavesactionapi/leaveActionTakenLeaves", [
                "api_key" => $this->getApiKey('leave_transactions'),
                "from" => date("01-01-Y"),
                "to" => date("31-12-Y"),
                "action" => "1",
                "employee_no" => $teamIds,
            ])->json("data") ?? [];
    }

    public function updateLeaveStatus($leave_id, $employee_no, $action, $manager_message = "")
    {
        $prefix = "Action via Nastari Whatsapp (only L1 Approval)";
        $finalMessage = !empty($manager_message) ? $prefix . " - " . $manager_message : $prefix;

        return Http::withHeaders(["Authorization" => $this->authHeader])
            ->post("{$this->baseUrl}/leavesactionapi/leaveaction", [
                "api_key" => $this->getApiKey('update_leave'),
                "leave_id" => (string) $leave_id,
                "employee_no" => (string) $employee_no,
                "action" => strtolower($action),
                "manager_message" => $finalMessage,
            ])->json();
    }

    public function getLeavesForTodayInBatch($reportees)
    {
        if (empty($reportees)) return [];
        $ids = array_column($reportees, "employee_id");
        $today = date("Y-m-d");
        $res = Http::withHeaders(["Authorization" => $this->authHeader])
            ->post("{$this->baseUrl}/leavesactionapi/leaveActionTakenLeaves", [
                "api_key" => $this->getApiKey('leave_transactions'),
                "from" => $today, "to" => $today, "action" => "2", "employee_no" => $ids
            ])->json();
        
        $onLeave = [];
        if (isset($res['status']) && $res['status'] == 1) {
            foreach ($res['data'] as $l) {
                if (in_array($today, $l['leave_days'])) {
                    $onLeave[] = ["employee_name" => $l['employee_name'], "leave_name" => trim(explode("/", $l['leave_name'])[0])];
                }
            }
        }
        return $onLeave;
    }

    public function createCallCenterTicket($userMobileNo, $employee, $complaint, $imagePath)
    {
        $lastTicket = CallCenter::latest('id')->first();
        $lastIdNumber = $lastTicket ? intval(substr($lastTicket->ticket_code, 3)) : 0;
        $newId = sprintf('CC-%04d', $lastIdNumber + 1);

        CallCenter::create([
            'ticket_code'   => $newId,
            'ticket_date'   => date('Y-m-d H:i:s'),
            'business_unit' => $employee['group_company'] ?? 'N/A',
            'employee_id'   => $employee['employee_id'] ?? 'N/A',
            'name'          => $employee['full_name'] ?? 'N/A',
            'mobile'        => $userMobileNo,
            'complaint'     => $complaint,
            'image_path'    => $imagePath,
            'status'        => 'Open'
        ]);

        return $newId;
    }

    public function formatPersonalData($employee)
    {
        return "🧑‍💼 *Data Karyawan*\n\n" .
               "ID Karyawan: {$employee['employee_id']}\n" .
               "Nama: {$employee['full_name']}\n" .
               "Email: " . ($employee['company_email_id'] ?? $employee['email'] ?? 'N/A') . "\n" .
               "Unit: {$employee['group_company']}\n" .
               "Divisi: {$employee['unit_name']}\n" .
               "Jabatan: {$employee['designation_name']}\n" .
               "Golongan: {$employee['job_level']}\n" .
               "Lokasi: {$employee['office_area']}\n" .
               "Join: " . date("d-m-Y", strtotime($employee['date_of_joining']));
    }

    public function formatDependentData($employee, $dependents)
    {
        $msg = "👨‍👩‍👧‍👦 *Data Keluarga*\nID: {$employee['employee_id']}\nNama: {$employee['full_name']}\n\n";
        if (empty($dependents)) return $msg . "Data tidak ditemukan.";
        
        $map = ['Spouse'=>'Pasangan','Son'=>'Anak','Daughter'=>'Anak','Father'=>'Ayah','Mother'=>'Ibu'];
        foreach ($dependents as $d) {
            $rel = $map[$d[6]] ?? $d[6];
            $msg .= "Nama: {$d[3]} {$d[5]}\nHubungan: {$rel}\nLahir: " . date("d-m-Y", strtotime($d[9])) . "\n---\n";
        }
        return $msg;
    }

    public function formatLeaveBalance($employee, $balanceData)
    {
    $msg = "🏖️ *Saldo Cuti*\nID: {$employee['employee_id']}\nNama: {$employee['full_name']}\n\n";
        if (!isset($balanceData['data'])) return $msg . "Gagal mengambil data.";
        
        $found = false;
        foreach ($balanceData['data'] as $l) {
            $bal = $l['currently_availabel_balance'] ?? null;
            if ($bal !== null) {
                $name = trim(explode("/", $l['leave_name'])[0]);
                $msg .= "- {$name}: *{$bal} hari*\n";
                $found = true;
            }
        }
        return $found ? $msg : $msg . "Tidak ditemukan data sisa cuti.";
    }

    public function formatOvertimeData($employee, $overtimeData)
    {
        setlocale(LC_TIME, 'id_ID.UTF-8', 'Indonesian');
        $msg = "⏱ *Rekap Overtime " . date('F Y') . "*\nID: {$employee['employee_id']}\nNama: {$employee['full_name']}\n\n";
        
        if (empty($overtimeData)) return $msg . "Tidak ada data lembur bulan ini.";
        
        $total = 0;
        foreach ($overtimeData as $r) {
            $daily = (float)($r[10]==='-'?0:$r[10]) + (float)($r[11]==='-'?0:$r[11]) + (float)($r[12]==='-'?0:$r[12]) + (float)($r[13]==='-'?0:$r[13]);
            if ($daily > 0) {
                $date = date('d M Y', strtotime($r[4]));
                $msg .= " - *{$date}*: {$daily} jam\n";
                $total += $daily;
            }
        }
        return $msg . "\n*Total Akumulasi:* {$total} jam";
    }

    public function formatMedicalData($employee, $medicalData)
    {
        if (!$medicalData || !isset($medicalData['data']['health_plans']) || empty($medicalData['data']['health_plans'])) {
            return "💊 *Plafon Medis*\n\nData tidak ditemukan di HC System untuk ID: {$employee['employee_id']}.";
        }
    
        $allPlans = $medicalData['data']['health_plans'];
        $currentYear = date("Y");
        
        $plans = array_filter($allPlans, function($p) use ($currentYear) {
            return strval($p['period'] ?? '') === strval($currentYear);
        });
    
        $isFallback = false;
        if (empty($plans)) {
            $isFallback = true;
            usort($allPlans, function($a, $b) {
                return ($b['period'] ?? 0) <=> ($a['period'] ?? 0);
            });
            $plans = $allPlans;
        }
    
        $header = $isFallback
            ? "⚠️ *Data $currentYear Belum Tersedia*\nMenampilkan data periode terakhir:"
            : "💊 *Plafon Medis $currentYear*";
    
        $msg = "{$header}\nID: {$employee['employee_id']}\nNama: {$employee['full_name']}\n\n";
    
        if (!empty($employee["rate_kamar_rawat_inap_(maksimal)"])) {
            $kamar = number_format($employee["rate_kamar_rawat_inap_(maksimal)"], 0, ",", ".");
            $msg .= "- Tarif Kamar (Max): Rp {$kamar}\n";
        }
    
        $map = [
            "Glasses" => "Kacamata",
            "Maternity" => "Persalinan",
            "Inpatient" => "Rawat Inap",
            "Outpatient" => "Rawat Jalan"
        ];
    
        $shownTypes = [];
    
        foreach ($plans as $p) {
            $type = $map[$p['medical_type']] ?? $p['medical_type'];
    
            if ($isFallback && in_array($type, $shownTypes)) {
                continue;
            }
    
            $shownTypes[] = $type;
    
            $bal = number_format($p['balance'] ?? 0, 0, ",", ".");
            $period = $p['period'] ?? '-';
            $suffix = $isFallback ? " (Periode $period)" : "";
    
            $msg .= "- {$type}: Rp {$bal}{$suffix}\n";
        }
    
        return $msg;
    }


    public function formatWeeklySchedule($employee, $scheduleData)
    {
        $msg = "📅 *Jadwal Kerja 3 Hari ke Depan*\nID: {$employee['employee_id']}\nNama: {$employee['full_name']}\n\n";
        if (empty($scheduleData)) return $msg . "⚠️ Maaf, tidak dapat mengambil jadwal Anda saat ini.";

        $days = ["Sunday"=>"Min","Monday"=>"Sen","Tuesday"=>"Sel","Wednesday"=>"Rab","Thursday"=>"Kam","Friday"=>"Jum","Saturday"=>"Sab"];
        $months = ["January"=>"Jan","February"=>"Feb","March"=>"Mar","April"=>"Apr","May"=>"Mei","June"=>"Jun","July"=>"Jul","August"=>"Agu","September"=>"Sep","October"=>"Okt","November"=>"Nov","December"=>"Des"];

        foreach ($scheduleData as $date => $data) {
            $ts = strtotime($date);
            $day = $days[date("l", $ts)];
            $month = $months[date("F", $ts)];
            $formattedDisplay = "$day, " . date("d", $ts) . " $month " . date("Y", $ts);

            if (isset($data["weeklyoff_yes_no"]) && $data["weeklyoff_yes_no"] == "Yes") {
                $msg .= "🌴 *{$formattedDisplay}*: Weekly Off\n";
            } else {
                $shift = $data["shift"] ?? "Shift tidak tersedia";
                $cleanShift = preg_replace("/\s*\(.*?\)/", "", $shift);
                $msg .= "💼 *{$formattedDisplay}*: {$cleanShift}\n";
            }
        }
        return $msg;
    }

    public function formatLateHistory($employee, $attendanceRecords)
    {
        if (empty($attendanceRecords)) return "Gagal mengambil data kehadiran.";
        
        $lateRecords = [];
        foreach ($attendanceRecords as $record) {
            $lateBy = $record[16] ?? '00:00';
            if (trim($lateBy) !== '00:00' && !empty($lateBy)) {
                $dateObj = DateTime::createFromFormat("d-M-Y(D)", $record[0]);
                $formattedDate = $dateObj ? $dateObj->format("d M Y (l)") : $record[0];
                $lateRecords[] = ['date' => $formattedDate, 'clock_in' => $record[22], 'duration' => $lateBy];
            }
        }

        $msg = "⏰ *Riwayat Terlambat Bulan Ini*\nID: {$employee['employee_id']}\nNama: {$employee['full_name']}\n\n";
        if (empty($lateRecords)) return $msg . "👍 *Kerja Bagus!* Tidak ada catatan keterlambatan.";

        foreach ($lateRecords as $rec) {
            $msg .= "🔴 *{$rec['date']}*\n    - Clock In: {$rec['clock_in']}, Telat: *{$rec['duration']}*\n";
        }
        return $msg;
    }

    public function formatSinglePunch($employee, $attendanceRecords)
    {
        $msg = "⚠️ *Single Punch Check*\nID: {$employee['employee_id']}\nNama: {$employee['full_name']}\n\n";
        $found = false;
        foreach ($attendanceRecords as $record) {
            $status = $record[21] ?? '';
            $clockOut = $record[23] ?? 'N.A.';
            if (strpos($status, "Single Punch Absent") !== false && $clockOut === 'N.A.') {
                $dateObj = DateTime::createFromFormat("d-M-Y(D)", $record[0]);
                $formattedDate = $dateObj ? $dateObj->format("d M Y (l)") : $record[0];
                $msg .= "- *{$formattedDate}*: Lupa Clock Out\n";
                $found = true;
            }
        }
        return $found ? $msg . "\nJangan lupa Clock Out ya!" : "⚠️ *Single Punch Check*\nID: {$employee['employee_id']}\nNama: {$employee['full_name']}\n\n✅ Aman! Tidak ada catatan single punch bulan ini.";
    }

    public function formatTeamPresence($manager)
    {
        $team = $this->getTeamData($manager);
        if (empty($team)) return "Tim tidak ditemukan.";
        
        $onLeave = $this->getLeavesForTodayInBatch($team);
        $present = []; $absent = []; $leave = [];
        $today = date("d-M-Y");
    
        foreach ($team as $member) {
            $isOff = $this->getWeeklyOffStatus($member['employee_id']);
            $att = $this->getAttendanceData($member['employee_id']);
            $found = false;
    
            if ($att) {
                foreach ($att as $r) {
                    if (isset($r[0]) && strpos($r[0], $today) !== false && isset($r[19]) && strpos($r[21], "Present") !== false) {
                        $clockIn = !empty($r[11]) ? substr($r[11], 0, 5) : "--:--";
                        $clockOut = (!empty($r[12]) && $r[12] !== "00:00:00") ? substr($r[12], 0, 5) : "--:--";
                        
                        $present[] = "{$member['full_name']} ({$clockIn} - {$clockOut})";
                        $found = true; 
                        break;
                    }
                }
            }
    
            if (!$found) {
                if ($isOff) {
                    $leave[] = $member['full_name'] . " (Weekly Off)";
                } else {
                    $isOnLeave = false;
                    foreach ($onLeave as $l) {
                        if ($l['employee_name'] === $member['full_name']) {
                            $leave[] = $member['full_name'] . " ({$l['leave_name']})";
                            $isOnLeave = true; 
                            break;
                        }
                    }
                    if (!$isOnLeave) $absent[] = $member['full_name'];
                }
            }
        }
    
        $msg = "⏰ *Presensi Tim Hari Ini*\n\n🟢 *Hadir (In - Out)*:\n" . (empty($present) ? "-" : implode("\n", $present)) . 
               "\n\n🟡 *Cuti/Libur*:\n" . (empty($leave) ? "-" : implode("\n", $leave)) . 
               "\n\n🔴 *Belum Absen*:\n" . (empty($absent) ? "-" : implode("\n", $absent));
    
        return $msg;
    }

    public function getTeamLateHistoryMessages($managerEmployee)
    {
        $reportees = $this->getTeamData($managerEmployee);
        
        if (empty($reportees)) {
            return ["Anda tidak memiliki tim yang terdaftar di sistem."];
        }

        $teamLateRecords = [];
        
        $columns = ["Date", "Employee ID", "Name", "Designation", "Unit", "Last Shift Change before Shift Day", "Shift Name", "Shift Work Location", "Night Shift(Yes/No)", "Policy Name", "Assigned Weekly Off", "Clock In", "Clock Out", "Total Work Duration", "Break Duration", "Final Work Duration", "Late by", "Early Clockout by", "Overtime", "Status", "Recorded ClockIn", "Recorded ClockOut", "Request Status", "Request Reason", "Request Message", "Applied On", "Acted By", "Acted On", "Request Manager Message", "Last Updated By", "Edit Comment", "Last Updated On"];
        
        $lateByColumnIndex = array_search("Late by", $columns);
        $dateColumnIndex = array_search("Date", $columns);
        $clockInIndex = array_search("Clock In", $columns);

        foreach ($reportees as $reportee) {
            $employee_id = $reportee['employee_id'];
            $employee_name = $reportee['full_name'];
            
            $attendanceRecords = $this->getAttendanceData($employee_id);

            if ($attendanceRecords && is_array($attendanceRecords)) {
                foreach ($attendanceRecords as $record) {
                    
                    $late_by = null;
                    $clockin = null;
                    $dateVal = null;

                    if (isset($record[$lateByColumnIndex])) {
                        $late_by = $record[$lateByColumnIndex];
                        $clockin = $record[$clockInIndex];
                        $dateVal = $record[$dateColumnIndex];
                    } elseif (isset($record['Late by'])) {
                        $late_by = $record['Late by'];
                        $clockin = $record['Clock In'];
                        $dateVal = $record['Date'];
                    }

                    if ($late_by && trim($late_by) !== "00:00") {
                        try {
                            $formatDate = \DateTime::createFromFormat("d-M-Y(D)", $dateVal);
                            $formattedDate = $formatDate ? $formatDate->format("D, d M Y") : $dateVal;
                        } catch (\Exception $e) {
                            $formattedDate = $dateVal;
                        }

                        $teamLateRecords[$employee_name][] = [
                            'date' => $formattedDate, 
                            'duration' => $late_by, 
                            'clockIn' => $clockin
                        ];
                    }
                }
            }
        }

        $messages = [];

        if (empty($teamLateRecords)) {
            $messages[] = "Luar biasa! 👏 Semua anggota tim Anda disiplin dan tidak ada catatan keterlambatan bulan ini.";
        } else {
            $indonesianMonths = ['January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'April' => 'April', 'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli', 'August' => 'Agustus', 'September' => 'September', 'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'];
            $currentMonthName = date('F');
            $monthNameIndo = $indonesianMonths[$currentMonthName] ?? $currentMonthName;

            $chunkSize = 5;
            $reportChunks = array_chunk($teamLateRecords, $chunkSize, true);
            $totalChunks = count($reportChunks);

            foreach ($reportChunks as $index => $chunk) {
                $partNumber = $index + 1;
                $reportMessage = "⏰ *Laporan Keterlambatan Tim - {$monthNameIndo} " . date("Y") . "*\n";
                $reportMessage .= "*(Bagian {$partNumber} dari {$totalChunks})*\n\n";

                foreach ($chunk as $name => $records) {
                    $reportMessage .= "🔴 *{$name}*\n";
                    foreach ($records as $record) {
                        $reportMessage .= "    - {$record['date']} (Masuk: {$record['clockIn']}, Telat: {$record['duration']})\n";
                    }
                    $reportMessage .= "\n";
                }
                $messages[] = $reportMessage;
            }
        }

        return $messages;
    }
    
    public function getTeamSinglePunchMessages($managerEmployee)
    {
        $reportees = $this->getTeamData($managerEmployee);

        if (empty($reportees)) {
            return ["Anda tidak memiliki tim yang terdaftar di sistem."];
        }

        $employeesWithSinglePunch = [];

        foreach ($reportees as $reportee) {
            $singlePunches = $this->checkSinglePunchForEmployee($reportee['employee_id']);
            
            if (!empty($singlePunches)) {
                $employeesWithSinglePunch[] = [
                    'name' => $reportee['full_name'],
                    'records' => $singlePunches
                ];
            }
        }

        $messages = [];
        $indonesianMonths = ['January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'April' => 'April', 'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli', 'August' => 'Agustus', 'September' => 'September', 'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember'];
        $currentMonthName = date('F');
        $monthNameIndo = $indonesianMonths[$currentMonthName] ?? $currentMonthName;

        if (empty($employeesWithSinglePunch)) {
            $reportMessage = "📄 *Laporan Single Punch Tim - {$monthNameIndo} " . date("Y") . "*\n\n";
            $reportMessage .= "Kerja bagus! 👍 Tidak ada anggota tim Anda yang memiliki catatan single punch bulan ini.";
            $messages[] = $reportMessage;
        } else {
            $chunkSize = 5;
            $reportChunks = array_chunk($employeesWithSinglePunch, $chunkSize, true);
            $totalChunks = count($reportChunks);

            foreach ($reportChunks as $index => $chunk) {
                $partNumber = $index + 1;
                
                $reportMessage = "📄 *Laporan Single Punch Tim - {$monthNameIndo} " . date("Y") . "*\n";
                $reportMessage .= "*(Bagian {$partNumber} dari {$totalChunks})*\n\n";
                $reportMessage .= "Ditemukan beberapa catatan single punch di tim Anda:\n\n";

                foreach ($chunk as $employeeData) {
                    $reportMessage .= "🔴 *{$employeeData['name']}*\n";
                    foreach ($employeeData['records'] as $record) {
                        $reportMessage .= "    - Tanggal: {$record}\n";
                    }
                    $reportMessage .= "\n";
                }
                
                $messages[] = $reportMessage;
            }

            $messages[] = "Mohon untuk mengingatkan tim Anda agar selalu melakukan Clock In dan Clock Out ya! 🧐";
        }

        return $messages;
    }

    public function checkSinglePunchForEmployee($employeeId)
    {
        $attendanceData = $this->getAttendanceData($employeeId);
        $singlePunchDates = [];

        if ($attendanceData && is_array($attendanceData)) {
            
             $columns = [
    "Date",
    "Employee ID",
    "Name",
    "Designation",
    "Department", // ✅ ganti dari Unit
    "Last Shift Change before Shift Day",
    "Shift Name",
    "Shift Work Location",
    "Night Shift(Yes/No)",
    "Policy Name",
    "Assigned Weekly Off",
    "Clock In",
    "Clock Out",
    "Total Work Duration",
    "Break Duration",
    "Final Work Duration",
    "Late by",
    "Early Clockout by",
    "Overtime",
    "Status",
    "Recorded ClockIn",
    "Recorded ClockOut",
    "Request Status",
    "Request Reason",
    "Request Message",
    "Applied On",
    "Acted By",
    "Acted On",
    "Request Manager Message",
    "Last Updated By",
    "Edit Comment",
    "Last Updated On",
    "Purpose On" // ✅ tambahin ini
];
            
            $statusIndex = array_search("Status", $columns);
            $dateIndex = array_search("Date", $columns);
            $clockInIndex = array_search("Clock In", $columns);
            $clockOutIndex = array_search("Clock Out", $columns);

            foreach ($attendanceData as $record) {
                $status = $record[$statusIndex] ?? ($record['Status'] ?? '');
                $clockIn = $record[$clockInIndex] ?? ($record['Clock In'] ?? '');
                $clockOut = $record[$clockOutIndex] ?? ($record['Clock Out'] ?? '');
                $dateVal = $record[$dateIndex] ?? ($record['Date'] ?? '');

                $isSinglePunch = false;

                if (stripos($status, 'Single Punch') !== false) {
                    $isSinglePunch = true;
                }
                elseif (!empty($clockIn) && (empty($clockOut) || $clockOut == '00:00') && $dateVal != date('d-M-Y(D)')) {
                    $isSinglePunch = true;
                }

                if ($isSinglePunch) {
                    $singlePunchDates[] = $dateVal;
                }
            }
        }

        return $singlePunchDates;
    }

    public function generateSuratKeteranganPdf($employee, $manager, $kebutuhan, $institusi)
    {
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->AddPage();

        $pageWidth = 210;
        $leftMargin = 25;
        $rightMargin = 25;
        $topMarginForText = 25;

        $pdf->SetMargins($leftMargin, $topMarginForText, $rightMargin);
        $pdf->SetAutoPageBreak(true, $topMarginForText);

        $companyName = $employee['contribution_level'] ?? null;
        $yPosAfterLogo = $this->drawLetterhead($pdf, $companyName, $topMarginForText);

        $pdf->SetY($yPosAfterLogo);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 6, 'SURAT KETERANGAN', 0, 1, 'C');

        $nomorSurat = $this->letterNumbers->next('SK', $employee['group_company'] ?? null);

        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, $nomorSurat, 0, 1, 'C');
        $pdf->Ln(10);

        $pdf->MultiCell(0, 6, 'Melalui Surat Keterangan ini, yang bertanda tangan berikut:', 0, 'L');
        $pdf->Ln(2);

        $this->addFormRow($pdf, 'Nama', $manager['fullname'] ?? 'N/A');
        $this->addFormRow($pdf, 'NIK', $manager['employee_id'] ?? 'N/A');
        $this->addFormRow($pdf, 'Jabatan', $manager['designation_name'] ?? 'N/A');
        $pdf->Ln(5);

        $pdf->MultiCell(0, 6, 'menyatakan bahwa Karyawan di bawah ini:', 0, 'L');
        $pdf->Ln(2);

        $this->addFormRow($pdf, 'Nama', $employee['full_name'] ?? 'N/A');
        $this->addFormRow($pdf, 'NIK', $employee['employee_id'] ?? 'N/A');
        $this->addFormRow($pdf, 'Jabatan', $employee['designation_name'] ?? 'N/A');
        $this->addFormRow($pdf, 'Status', $employee['employee_type'] ?? 'N/A');
        $pdf->Ln(5);

        $join_date = !empty($employee['date_of_joining']) ? date('d-m-Y', strtotime($employee['date_of_joining'])) : 'N/A';
        $comp = $companyName ?? 'Perusahaan';
        
        $bodyText1 = "adalah benar Karyawan dari {$comp} sejak tanggal {$join_date} sampai dengan tanggal Surat Keterangan ini ditandatangani.";
        $pdf->MultiCell(0, 6, $bodyText1, 0, 'J');
        $pdf->Ln(5);
        
        $bodyText2 = "Demikian Surat Keterangan ini dibuat dengan sebenar-benarnya untuk kebutuhan {$kebutuhan} di {$institusi}.";
        $pdf->MultiCell(0, 6, $bodyText2, 0, 'J');
        $pdf->Ln(15);

        $pdf->Cell(0, 6, 'Jakarta, ' . date('d F Y'), 0, 1, 'L');
        $pdf->Ln(20);
        $pdf->SetFont('Arial', 'BU', 11);
        $pdf->Cell(0, 6, $manager['fullname'] ?? 'N/A', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, $manager['designation_name'] ?? 'N/A', 0, 1, 'L');

        $outputDir = storage_path('app/surat-keterangan/');
        if (!is_dir($outputDir)) mkdir($outputDir, 0775, true);
        $pdfFilename = "Surat_Keterangan_" . str_replace(' ', '_', $employee['full_name']) . '_' . time() . '.pdf';
        $finalPdfPath = $outputDir . $pdfFilename;
        $pdf->Output('F', $finalPdfPath);

        // FPDF assembles the whole document in memory before writing it. Once
        // it is on disk the object is dead weight for the rest of the request.
        unset($pdf);

        $this->logSuratGeneration($employee, $manager, $kebutuhan, $institusi, $nomorSurat, $finalPdfPath, 'Surat Keterangan Kerja');

        return ['path' => $finalPdfPath, 'filename' => $pdfFilename, 'nomorSurat' => $nomorSurat];
    }

    public function generateSuratPembuatanVisaPdf($employee, $manager, $visaData)
    {
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->AddPage();

        $pageWidth = 210;
        $leftMargin = 25;
        $rightMargin = 25;
        $topMarginForText = 25;

        $pdf->SetMargins($leftMargin, $topMarginForText, $rightMargin);
        $pdf->SetAutoPageBreak(true, $topMarginForText);

        $companyName = $employee['contribution_level'] ?? null;
        $yPosAfterLogo = $this->drawLetterhead($pdf, $companyName, $topMarginForText);

        $pdf->SetY($yPosAfterLogo);
        $pdf->SetFont('Arial', 'B', 12);
        $judul = 'SURAT KETERANGAN';
        $pdf->Cell(0, 6, $judul, 0, 1, 'C');

        $nomorSurat = $this->letterNumbers->next('SK-VISA', $employee['group_company'] ?? null);

        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, $nomorSurat, 0, 1, 'C');
        $pdf->Ln(10);

        $pdf->MultiCell(0, 6, 'Melalui Surat Keterangan ini, yang bertanda tangan berikut:', 0, 'L');
        $pdf->Ln(2);

        $this->addFormRow($pdf, 'Nama', $manager['fullname'] ?? 'N/A');
        $this->addFormRow($pdf, 'NIK', $manager['employee_id'] ?? 'N/A');
        $this->addFormRow($pdf, 'Jabatan', $manager['designation_name'] ?? 'N/A');
        $pdf->Ln(5);

        $pdf->MultiCell(0, 6, 'menyatakan bahwa Karyawan di bawah ini:', 0, 'L');
        $pdf->Ln(2);

        $this->addFormRow($pdf, 'Nama', $employee['full_name'] ?? 'N/A');
        $this->addFormRow($pdf, 'NIK', $employee['employee_id'] ?? 'N/A');
        $this->addFormRow($pdf, 'Jabatan', $employee['designation_name'] ?? 'N/A');
        $this->addFormRow($pdf, 'Status', $employee['employee_type'] ?? 'N/A');
        $this->addFormRow($pdf, 'Nomor Paspor', $visaData['no_passport'] ?? 'N/A');
        $pdf->Ln(5);

        $join_date = !empty($employee['date_of_joining']) ? date('d-m-Y', strtotime($employee['date_of_joining'])) : 'N/A';
        $comp = $companyName ?? 'Perusahaan';

        $bodyText1 = "adalah benar Karyawan dari {$comp} sejak tanggal {$join_date} sampai dengan tanggal Surat Keterangan ini ditandatangani.";
        $pdf->MultiCell(0, 6, $bodyText1, 0, 'J');
        $pdf->Ln(2);

        $bodyText2 = "Berkaitan dengan hal tersebut, Karyawan memiliki rencana perjalanan ke {$visaData['destinasi']} mulai tanggal {$visaData['dari_tanggal']} hingga {$visaData['sampai_tanggal']} untuk kepentingan {$visaData['jenis_kepentingan']}. Melalui Surat Keterangan disampaikan bahwa biaya hidup Karyawan tersebut selama berada di {$visaData['destinasi']} akan ditanggung {$visaData['tanggungan']} dan Karyawan yang bersangkutan tidak akan mencari pekerjaan atau kewarganegaraan permanen serta akan segera kembali ke Indonesia setelah perjalanan berakhir.";
        $pdf->MultiCell(0, 6, $bodyText2, 0, 'J');
        $pdf->Ln(2);

        $bodyText3 = "Demikian Surat Keterangan ini dibuat dengan sebenar-benarnya untuk kebutuhan pengajuan visa ke Kedutaan Besar {$visaData['destinasi']}. Kami sangat menghargai bantuan dan kesediaan Bapak/Ibu untuk menyetujui permohonan visa ini.";
        $pdf->MultiCell(0, 6, $bodyText3, 0, 'J');
        $pdf->Ln(10);

        $pdf->MultiCell(0, 6, "Terima kasih.", 0, 'L');
        $pdf->Ln(10);

        $pdf->Cell(0, 6, 'Jakarta, ' . date('d F Y'), 0, 1, 'L');
        $pdf->Ln(20);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6, $manager['fullname'] ?? 'N/A', 0, 1, 'L');
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, $manager['designation_name'] ?? 'N/A', 0, 1, 'L');

        $outputDir = storage_path('app/surat-keterangan/');
        if (!is_dir($outputDir)) mkdir($outputDir, 0775, true);
        
        $pdfFilename = "Visa_Letter_" . str_replace(' ', '_', $employee['full_name']) . '_' . time() . '.pdf';
        $finalPdfPath = $outputDir . $pdfFilename;
        $pdf->Output('F', $finalPdfPath);
        unset($pdf);

        $this->logSuratGeneration($employee, $manager, 'Visa ke ' . $visaData['destinasi'], 'Kedutaan', $nomorSurat, $finalPdfPath, 'Surat Pengajuan Visa');

        return ['path' => $finalPdfPath, 'filename' => $pdfFilename, 'nomorSurat' => $nomorSurat];
    }
    
   public function generateSuratPembuatanVisaPdf_EN($employee, $manager, $visaData)
    {
        try {
            $pdf = new FPDF('P', 'mm', 'A4');
            $pdf->AddPage();

            $pageWidth = 210;
            $leftMargin = 25;
            $rightMargin = 25;
            $topMarginForText = 25;

            $pdf->SetMargins($leftMargin, $topMarginForText, $rightMargin);
            $pdf->SetAutoPageBreak(true, $topMarginForText);

            $companyName = $employee['contribution_level'] ?? null;
            $yPosAfterLogo = $this->drawLetterhead($pdf, $companyName, $topMarginForText);

            $pdf->SetY($yPosAfterLogo);
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 6, 'CERTIFICATE OF EMPLOYMENT', 0, 1, 'C');

            $nomorSurat = $this->letterNumbers->next('SK-VISA', $employee['group_company'] ?? null);

            $pdf->SetFont('Arial', '', 11);
            $pdf->Cell(0, 6, $nomorSurat, 0, 1, 'C');
            $pdf->Ln(10);

            $pdf->MultiCell(0, 6, 'The undersigned below:', 0, 'L');
            $pdf->Ln(2);

            $this->addFormRow($pdf, 'Name', $manager['fullname'] ?? 'N/A');
            $this->addFormRow($pdf, 'Employee ID', $manager['employee_id'] ?? 'N/A');
            $this->addFormRow($pdf, 'Position Title', $manager['designation_name'] ?? 'N/A');
            $pdf->Ln(5);

            $pdf->MultiCell(0, 6, 'hereby certifies that the employee mentioned below:', 0, 'L');
            $pdf->Ln(2);

            $this->addFormRow($pdf, 'Name', $employee['full_name'] ?? 'N/A');
            $this->addFormRow($pdf, 'Employee ID', $employee['employee_id'] ?? 'N/A');
            $this->addFormRow($pdf, 'Position Title', $employee['designation_name'] ?? 'N/A');
            $this->addFormRow($pdf, 'Employment Status', $employee['employee_type'] ?? 'N/A');
            $this->addFormRow($pdf, 'Passport Number', $visaData['no_passport'] ?? 'N/A');
            $pdf->Ln(5);

            $join_date = !empty($employee['date_of_joining']) ? date('F j, Y', strtotime($employee['date_of_joining'])) : 'N/A';
            $comp = $companyName ?? 'the Company';

            $bodyText1 = "is an employee of {$comp} since {$join_date} up to the present date.";
            $pdf->MultiCell(0, 6, $bodyText1, 0, 'J');
            $pdf->Ln(2);

            $purpose = ($visaData['jenis_kepentingan'] == 'Bisnis') ? 'Business' : (($visaData['jenis_kepentingan'] == 'Pribadi') ? 'Personal' : $visaData['jenis_kepentingan']);
            $funder = ($visaData['tanggungan'] == 'Perusahaan') ? 'the Company' : (($visaData['tanggungan'] == 'Sendiri') ? 'Personal Account' : $visaData['tanggungan']);

            $bodyText2 = "In relation to this matter, the employee has a travel plan to {$visaData['destinasi']} starting from {$visaData['dari_tanggal']} until {$visaData['sampai_tanggal']} for {$purpose} purposes. Through this certificate, we confirm that all living expenses during the stay in {$visaData['destinasi']} will be borne by {$funder}. The employee will not seek any form of employment or permanent residency and will return to Indonesia immediately after the trip concludes.";
            $pdf->MultiCell(0, 6, $bodyText2, 0, 'J');
            $pdf->Ln(2);

            $bodyText3 = "This certificate is issued to support the visa application to the Embassy of {$visaData['destinasi']}. We would appreciate your kind assistance in granting the visa.";
            $pdf->MultiCell(0, 6, $bodyText3, 0, 'J');
            $pdf->Ln(10);

            $pdf->MultiCell(0, 6, "Thank you.", 0, 'L');
            $pdf->Ln(10);

            $pdf->Cell(0, 6, 'Jakarta, ' . date('F j, Y'), 0, 1, 'L');
            $pdf->Ln(20);
            
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(0, 6, $manager['fullname'] ?? 'N/A', 0, 1, 'L');
            
            $pdf->SetFont('Arial', '', 11);
            $pdf->Cell(0, 6, $manager['designation_name'] ?? 'N/A', 0, 1, 'L');

            $outputDir = storage_path('app/surat-keterangan/');
            if (!is_dir($outputDir)) mkdir($outputDir, 0775, true);
            
            $pdfFilename = "Visa_Letter_EN_" . str_replace(' ', '_', $employee['full_name']) . '_' . time() . '.pdf';
            $finalPdfPath = $outputDir . $pdfFilename;
            $pdf->Output('F', $finalPdfPath);
            unset($pdf);

            $this->logSuratGeneration($employee, $manager, 'Visa to ' . $visaData['destinasi'] . ' (EN)', 'Embassy', $nomorSurat, $finalPdfPath, 'Visa Application Letter');

            return ['path' => $finalPdfPath, 'filename' => $pdfFilename, 'nomorSurat' => $nomorSurat];

        } catch (\Throwable $e) {
            // Was an empty catch: a failure here returned null and the caller
            // silently sent nothing, leaving no trace of why.
            Log::error('LETTER_GENERATE_FAILED', [
                'letter_type' => 'Visa Application Letter (EN)',
                'employee_id' => $employee['employee_id'] ?? null,
                'company'     => $employee['contribution_level'] ?? null,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function addFormRow($pdf, $label, $value)
    {
        $indent = 5;
        $labelW = 40;
        $colonW = 5;
        $lineH = 6;

        $pdf->Cell($indent, $lineH, '', 0, 0); 
        $pdf->Cell($labelW, $lineH, $label, 0, 0, 'L');
        $pdf->Cell($colonW, $lineH, ':', 0, 0, 'C');
        $pdf->Cell(0, $lineH, $value, 0, 1, 'L');
    }

    private function logSuratGeneration($employee, $manager, $kebutuhan, $institusi, $nomorSurat, $filePath, $jenisSurat)
    {
        LetterLog::create([
            'nomor_surat' => $nomorSurat,
            'jenis_surat' => $jenisSurat,
            'nik' => $employee['employee_id'],
            'nama_karyawan' => $employee['full_name'],
            'kebutuhan' => $kebutuhan,
            'institusi' => $institusi,
            'penanda_tangan' => $manager['fullname'],
            'file_path' => $filePath
        ]);
    }

    public function generateBirthdayMessage($employee)
    {
        $response = Http::withToken($this->geminiConfig['key'])
            ->post("https://openrouter.ai/api/v1/chat/completions", [
                "model" => $this->geminiConfig['model'],
                "messages" => [["role" => "user", "content" => "Generate cheerful birthday message for {$employee['full_name']} with emojis. Only the message."]]
            ]);
        return $response->json('choices.0.message.content');
    }

    public function generateWorkAnniversaryMessage($employee)
    {
        $response = Http::withToken($this->geminiConfig['key'])
            ->post("https://openrouter.ai/api/v1/chat/completions", [
                "model" => $this->geminiConfig['model'],
                "messages" => [["role" => "user", "content" => "Generate work anniversary message for {$employee['full_name']} at {$employee['group_company']} with emojis. Only the message."]]
            ]);
        return $response->json('choices.0.message.content');
    }

    public function getSignatoryForEmployee($employee)
    {
        $map = [
            'KPN Corporation' => ['fullname' => 'Budi Setiono', 'employee_id' => '01122100006', 'designation_name' => 'Corporate HC Division Head'],
            'Cement' => ['fullname' => 'Beni Pandu Gautama', 'employee_id' => '04125010017', 'designation_name' => 'Human Capital Division Head'],
            'Downstream' => ['fullname' => 'Jimmy', 'employee_id' => '01123090020', 'designation_name' => 'Human Capital Director'],
            'Plantations' => ['fullname' => 'Jerry', 'employee_id' => '01123090014', 'designation_name' => 'HC Division Head'],
            'Property' => ['fullname' => 'Anggelina', 'employee_id' => '05125010001', 'designation_name' => 'HC Department Head']
        ];
        return $map[$employee['group_company'] ?? ''] ?? ['fullname' => 'N/A', 'employee_id' => 'N/A', 'designation_name' => 'N/A'];
    }
}