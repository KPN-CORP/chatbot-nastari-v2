<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use CURLFile;

class WhatsAppSenderService
{
    protected $baseUrl;
    protected $token;
    protected $phoneId;
    protected $version;

    public function __construct()
    {
        $this->token = config('services.whatsapp.access_token');
        $this->phoneId = config('services.whatsapp.phone_number_id');
        
        $this->version = config('services.whatsapp.version', 'v22.0'); 
        
        if (empty($this->phoneId)) {
            $this->phoneId = env('WHATSAPP_PHONE_NUMBER_ID');
        }

        $this->baseUrl = "https://graph.facebook.com/{$this->version}/{$this->phoneId}";
    }

    private function sendApiRequest(array $payload)
    {
        if (empty($this->phoneId)) return null;

        $response = Http::withToken($this->token)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post("{$this->baseUrl}/messages", $payload);

        if ($response->failed()) {
            Log::error($response->body());
        }
        return $response;
    }

    public function markMessageAsRead($messageId)
    {
        return $this->sendApiRequest([
            "messaging_product" => "whatsapp",
            "status" => "read",
            "message_id" => $messageId
        ]);
    }
    
    public function sendReplyButton($to, $messageBody, $buttons = [])
    {
        $formattedButtons = [];
        foreach ($buttons as $index => $btnText) {
            $formattedButtons[] = [
                'type' => 'reply',
                'reply' => [
                    'id' => 'btn_' . $index, 
                    'title' => substr($btnText, 0, 20) 
                ]
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => [
                    'text' => substr($messageBody, 0, 1024)
                ],
                'action' => [
                    'buttons' => $formattedButtons
                ]
            ]
        ];

        return Http::withToken($this->token)
            ->post("https://graph.facebook.com/{$this->version}/{$this->phoneId}/messages", $payload);
    }
    
    public function sendMessage($userMobileNo, $messageText)
    {
        return $this->sendApiRequest([
            "messaging_product" => "whatsapp",
            "to" => $userMobileNo,
            "type" => "text",
            "text" => ["body" => $messageText]
        ]);
    }

    public function downloadMedia($mediaId)
{
    try {
        $version = config('services.whatsapp.version', 'v22.0');
        $urlInfo = "https://graph.facebook.com/{$version}/{$mediaId}";
        
        $responseInfo = Http::withToken($this->token)->get($urlInfo);
        
        if ($responseInfo->failed()) {
            Log::error("Gagal ambil info media: " . $responseInfo->body());
            return null;
        }

        $mediaUrl = $responseInfo->json('url');
        $mimeType = $responseInfo->json('mime_type');

        $responseMedia = Http::withToken($this->token)->get($mediaUrl);

        if ($responseMedia->failed()) {
            Log::error("Gagal download binary media.");
            return null;
        }

        $extension = explode('/', $mimeType)[1] ?? 'jpg';
        if ($extension === 'jpeg') $extension = 'jpg';
        
        $fileName = 'cc_' . time() . '_' . rand(1000,9999) . '.' . $extension;
        $storagePath = 'call-center/images/' . $fileName;

        Storage::put($storagePath, $responseMedia->body());

        return $storagePath;

    } catch (\Exception $e) {
        Log::error("Exception Download Media: " . $e->getMessage());
        return null;
    }
}

    public function sendMainMenu($userMobileNo, $employee, $clockInTime)
    {
        $employeeFullName = $employee['full_name'] ?? 'Karyawan';

        // An inactive employee gets a menu built from the same whitelist the
        // backend enforces, so the two can never disagree. This is presentation
        // only: EmployeeAccessPolicy is what actually refuses access.
        if (($employee['employment_status'] ?? 'unknown') === 'inactive') {
            return $this->sendInactiveMainMenu($userMobileNo, $employeeFullName);
        }

        $attendanceStatus = ($clockInTime && $clockInTime !== 'N.A.')
            ? "Kamu sudah absen masuk pada *{$clockInTime}* hari ini! 🎉"
            : "Oops! Sepertinya kamu belum absen masuk hari ini. 🕒";

        $menuRows = [
            ["id" => "sub_menu_data_karyawan", "title" => "Data Karyawan", "description" => "Cek data karyawan, keluarga, sisa plafon."],
            ["id" => "sub_menu_time_management", "title" => "Catatan Kehadiran", "description" => "Pantau riwayat overtime, terlambat, dll."],
            ["id" => "generate_surat", "title" => "Generate Surat", "description" => "Buat surat keterangan secara otomatis."],
            ["id" => "ruang_placeholder", "title" => "Ruang", "description" => "Anonymous Network for Grounding."],
            ["id" => "faq", "title" => "PANDU", "description" => "Helpdesk Enhanced AI Response."]
            // ["id" => "call_center_menu", "title" => "HC System Desk", "description" => "Laporkan kendala sistem & upload bukti."],
        ];

        $headerText = "Hallo, {$employeeFullName}! 👋";

        if (!empty($employee['direct_reportees_employee_id'])) {
            $headerText = "Hallo, {$employeeFullName}! 👑";
            $bodyText = $attendanceStatus . "\n\nSebagai manajer, Anda memiliki akses menu khusus untuk tim Anda. Silakan pilih di bawah ini.";
            
            $managerItems = [
                ["id" => "sub_menu_tim_saya", "title" => "Informasi Tim Saya", "description" => "Cek data kehadiran tim Anda."],
                ["id" => "leave_approval", "title" => "Persetujuan Cuti", "description" => "Proses pengajuan cuti yang pending."],
                ["id" => "manager_assistant", "title" => "Nastari AI", "description" => "Tanya apa pun tentang tim Anda."]
            ];

            array_splice($menuRows, 2, 0, $managerItems);
        } else {
            $bodyText = $attendanceStatus . "\n\n*Nastari* siap membantu Anda. Silakan pilih fitur di bawah ini:";
        }

        foreach ($menuRows as $index => &$row) {
            $row['title'] = ($index + 1) . ". " . $row['title'];
        }

        return $this->sendApiRequest([
            "messaging_product" => "whatsapp", "to" => $userMobileNo, "type" => "interactive",
            "interactive" => [
                "type" => "list", "header" => ["type" => "text", "text" => $headerText],
                "body" => ["text" => $bodyText],
                "action" => ["button" => "Fitur Nastari", "sections" => [["title" => "Menu Utama", "rows" => $menuRows]]]
            ]
        ]);
    }

    /**
     * Main menu for an employee whose HRIS record is soft-deleted.
     *
     * Only the two features the business rule permits: their own employee data
     * and Ruang. No attendance line, because there is no attendance to report,
     * and no Darwinbox clock-in call is made for them at all.
     */
    private function sendInactiveMainMenu($userMobileNo, $employeeFullName)
    {
        $menuRows = [
            ["id" => "personal_data", "title" => "1. Data Karyawan", "description" => "Lihat data karyawan Anda."],
            ["id" => "ruang_placeholder", "title" => "2. Ruang", "description" => "Anonymous Network for Grounding."],
        ];

        return $this->sendApiRequest([
            "messaging_product" => "whatsapp", "to" => $userMobileNo, "type" => "interactive",
            "interactive" => [
                "type" => "list",
                "header" => ["type" => "text", "text" => "Hallo, {$employeeFullName}! 👋"],
                "body" => ["text" => "Status kepegawaian kamu tercatat sudah tidak aktif di HC System, jadi fitur *Nastari* yang tersedia untukmu terbatas ya.\n\nKalau kamu merasa ini keliru, silakan hubungi tim HCO Unit Bisnis kamu. 😊"],
                "action" => ["button" => "Fitur Nastari", "sections" => [["title" => "Menu Tersedia", "rows" => $menuRows]]]
            ]
        ]);
    }

    public function sendSubMenuDataKaryawan($userMobileNo)
    {
        return $this->sendApiRequest([
            "messaging_product" => "whatsapp", "to" => $userMobileNo, "type" => "interactive",
            "interactive" => [
                "type" => "list", "header" => ["type" => "text", "text" => "📂 Informasi Data Karyawan"],
                "body" => ["text" => "Berikut adalah data personal yang bisa *Nastari* tampilkan untukmu. Silakan pilih salah satu."],
                "action" => [
                    "button" => "Lihat Pilihan Data",
                    "sections" => [[
                        "title" => "Pilih Detail Informasi",
                        "rows" => [
                            ["id" => "personal_data", "title" => "Data Karyawan", "description" => "Lihat data karyawan Anda."],
                            ["id" => "dependent_data", "title" => "Data Keluarga", "description" => "Lihat daftar anggota keluarga yang terdaftar."],
                            ["id" => "medical_plafon", "title" => "Plafon Medis", "description" => "Lihat sisa plafon kesehatan."],
                            ["id" => "main_menu", "title" => "⬅️ Kembali ke Menu Utama", "description" => "Kembali ke menu awal."]
                        ]
                    ]]
                ]
            ]
        ]);
    }

    public function sendSubMenuTimeManagement($userMobileNo)
    {
        return $this->sendApiRequest([
            "messaging_product" => "whatsapp", "to" => $userMobileNo, "type" => "interactive",
            "interactive" => [
                "type" => "list", "header" => ["type" => "text", "text" => "🕒 Informasi Catatan Kehadiran"],
                "body" => ["text" => "*Nastari* bisa kasih catatan kehadiranmu! Mau lihat info yang mana?"],
                "action" => [
                    "button" => "Lihat Catatan",
                    "sections" => [[
                        "title" => "Pilih Informasi",
                        "rows" => [
                            ["id" => "leave_balance", "title" => "Saldo Cuti", "description" => "Lihat sisa saldo cuti."],
                            ["id" => "work_schedule", "title" => "Cek Jadwal Kerja", "description" => "Lihat jadwal shift Anda."],
                            ["id" => "single_punch_check", "title" => "Single Punch", "description" => "Lihat riwayat lupa clock out."],
                            ["id" => "late_history", "title" => "Riwayat Terlambat", "description" => "Lihat daftar catatan keterlambatan."],
                            ["id" => "overtime_data", "title" => "Data Overtime", "description" => "Lihat rekapitulasi jam lembur."],
                            ["id" => "main_menu", "title" => "⬅️ Kembali ke Menu Utama", "description" => "Kembali ke menu awal."]
                        ]
                    ]]
                ]
            ]
        ]);
    }

    public function sendSubMenuTimSaya($userMobileNo)
    {
        return $this->sendApiRequest([
            "messaging_product" => "whatsapp", "to" => $userMobileNo, "type" => "interactive",
            "interactive" => [
                "type" => "list", "header" => ["type" => "text", "text" => "👑 Menu Manajer"],
                "body" => ["text" => "Anda memiliki akses ke fitur-fitur manajerial berikut untuk mengelola tim Anda. Silakan pilih salah satu."],
                "action" => [
                    "button" => "Managerial Tools",
                    "sections" => [[
                        "title" => "Pilih Fitur Tim",
                        "rows" => [
                            ["id" => "my_team_list", "title" => "Tim Saya", "description" => "Lihat daftar tim Anda."],
                            ["id" => "team_presence", "title" => "Laporan Presensi Harian", "description" => "Rekap presensi tim Anda hari ini."],
                            ["id" => "leave_approval", "title" => "Persetujuan Cuti", "description" => "Proses pengajuan cuti tim."],
                            ["id" => "late_records_team", "title" => "Riwayat Terlambat Tim", "description" => "Cek rekap keterlambatan tim."],
                            ["id" => "single_punch_team", "title" => "Single Punch Tim", "description" => "Siapa saja yang lupa clock in/out."],
                            ["id" => "main_menu", "title" => "⬅️ Kembali ke Menu Utama", "description" => "Kembali ke tampilan menu awal."]
                        ]
                    ]]
                ]
            ]
        ]);
    }

    public function sendJenisSuratMenu($userMobileNo)
    {
        return $this->sendApiRequest([
            "messaging_product" => "whatsapp", "to" => $userMobileNo, "type" => "interactive",
            "interactive" => [
                "type" => "list",
                "header" => ["type" => "text", "text" => "📄 Pembuatan Surat Otomatis"],
                "body" => ["text" => "*Nastari* siap membantu untuk urusan surat-menyurat Anda!🫡\n\nSilakan pilih jenis suratnya, lalu jawab beberapa pertanyaan singkat agar surat yang sesuai bisa segera *Nastari* proses yaa 😊"],
                "action" => [
                    "button" => "Lihat Jenis Surat",
                    "sections" => [[
                        "title" => "Pilih Template Surat",
                        "rows" => [
                            ["id" => "surat_ket_kerja", "title" => "Surat Keterangan Kerja", "description" => "Untuk keperluan umum/administrasi."],
                            ["id" => "surat_visa_id", "title" => "Surat Pengajuan Visa ID", "description" => "Kedutaan Bahasa Indonesia."],
                            ["id" => "surat_visa_en", "title" => "Surat Pengajuan Visa EN", "description" => "Kedutaan Bahasa Inggris."],
                            ["id" => "main_menu", "title" => "⬅️ Kembali ke Menu Utama", "description" => "Kembali ke menu awal."]
                        ]
                    ]]
                ]
            ]
        ]);
    }

    public function sendAIFollowUpMessage($userMobileNo)
    {
        return $this->sendApiRequest([
            "messaging_product" => "whatsapp", "to" => $userMobileNo, "type" => "interactive",
            "interactive" => [
                "type" => "button",
                "body" => ["text" => "Ada lagi yang bisa *Nastari* bantu? Jika ada, silakan kirim pertanyaan Anda. \n\nJika tidak ada, silakan tekan tombol di bawah ini untuk mengakhiri sesi dan kembali ke Menu Utama."],
                "action" => [
                    "buttons" => [
                        ["type" => "reply", "reply" => ["id" => "sub_menu_tim_saya", "title" => "Selesai ✅"]]
                    ]
                ]
            ]
        ]);
    }

    public function sendKepentinganVisaMenu($userMobileNo, $lang = 'ID')
    {
        $isEn = ($lang === 'EN');
        $bodyText = $isEn ? "Got it 👍\nWhat is the purpose of your trip? 😊" : "Dicatat 👍\nApa tujuan atau jenis kepentingan perjalanan kamu? 😊";
        $btn1 = $isEn ? ["id" => "visa_kepentingan_personal", "title" => "Personal"] : ["id" => "visa_kepentingan_pribadi", "title" => "Pribadi"];
        $btn2 = $isEn ? ["id" => "visa_kepentingan_business", "title" => "Business"] : ["id" => "visa_kepentingan_bisnis", "title" => "Bisnis"];

        return $this->sendApiRequest([
            "messaging_product" => "whatsapp", "to" => $userMobileNo, "type" => "interactive",
            "interactive" => [
                "type" => "button", "body" => ["text" => $bodyText],
                "action" => ["buttons" => [["type" => "reply", "reply" => $btn1], ["type" => "reply", "reply" => $btn2]]]
            ]
        ]);
    }

    public function sendTanggunganVisaMenu($userMobileNo, $lang = 'ID')
    {
        $isEn = ($lang === 'EN');
        $bodyText = $isEn ? "Lastly 🙂\nWho will be covering your travel expenses? 💳" : "Baik, pertanyaan terakhir ya 🙂\nBiaya perjalanan ini akan ditanggung oleh siapa? 💳";
        $btn1 = $isEn ? ["id" => "visa_tanggungan_self", "title" => "Self"] : ["id" => "visa_tanggungan_sendiri", "title" => "Sendiri"];
        $btn2 = $isEn ? ["id" => "visa_tanggungan_company", "title" => "Company"] : ["id" => "visa_tanggungan_perusahaan", "title" => "Perusahaan"];

        return $this->sendApiRequest([
            "messaging_product" => "whatsapp", "to" => $userMobileNo, "type" => "interactive",
            "interactive" => [
                "type" => "button", "body" => ["text" => $bodyText],
                "action" => ["buttons" => [["type" => "reply", "reply" => $btn1], ["type" => "reply", "reply" => $btn2]]]
            ]
        ]);
    }

    public function sendDocumentMessageById($userMobileNo, $mediaId, $filename, $caption = "")
    {
        return $this->sendApiRequest([
            "messaging_product" => "whatsapp",
            "to" => $userMobileNo,
            "type" => "document",
            "document" => ["id" => $mediaId, "filename" => $filename, "caption" => $caption]
        ]);
    }

    public function uploadMediaToWhatsApp($localFilePath)
    {
        if (!file_exists($localFilePath)) return null;

        $url = "https://graph.facebook.com/" . config('services.whatsapp.version') . "/{$this->phoneId}/media";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'file' => new CURLFile($localFilePath, 'application/pdf', basename($localFilePath)),
            'type' => 'application/pdf',
            'messaging_product' => 'whatsapp',
        ]);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$this->token}"]);

        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        return $data['id'] ?? null;
    }

    public function sendEscalationConfirmation($userMobileNo, $messageText)
    {
        return $this->sendApiRequest([
            "messaging_product" => "whatsapp",
            "to" => $userMobileNo,
            "type" => "interactive",
            "interactive" => [
                "type" => "button",
                "body" => ["text" => $messageText],
                "action" => [
                    "buttons" => [
                        ["type" => "reply", "reply" => ["id" => "hear_escalate_yes", "title" => "Ya, Buatkan Tiket"]],
                        ["type" => "reply", "reply" => ["id" => "hear_escalate_no", "title" => "Tidak, Terima Kasih"]]
                    ]
                ]
            ]
        ]);
    }

    public function sendLeaveApprovalInteractiveFlow($userMobileNo, $leaveRequest)
    {
        $flowId = "573093528962570";
        $flowScreenName = "QUESTION_ONE";
        $flowCta = "Beri Persetujuan";
        $flowToken = "LEAVE_APPROVAL_" . time() . "_" . bin2hex(random_bytes(8));
        $employeeName = $leaveRequest['employee_name'] ?? 'N/A';
        $leaveType = trim(explode(" / ", $leaveRequest['leave_name'] ?? 'Cuti')[0]);
        $fromDate = $leaveRequest['from'] ?? 'N/A';
        $toDate = $leaveRequest['to'] ?? 'N/A';
        $reason = $leaveRequest['message'] ?? '-';

        $bodyText = "\n\nNama: {$employeeName}\nJenis Cuti: {$leaveType}\nTanggal: {$fromDate} s/d {$toDate}\nAlasan: {$reason}\n\nSilakan buka form di bawah untuk memberikan persetujuan.";

        return $this->sendApiRequest([
            'messaging_product' => 'whatsapp',
            'to' => $userMobileNo,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'flow',
                'header' => ['type' => 'text', 'text' => 'Pengajuan cuti dari tim Anda:'],
                'body' => ['text' => $bodyText],
                'action' => [
                    'name' => 'flow',
                    'parameters' => [
                        'flow_message_version' => '3',
                        'flow_id' => $flowId,
                        'flow_token' => $flowToken,
                        'flow_cta' => $flowCta,
                        'flow_action' => 'navigate',
                        'flow_action_payload' => [
                            'screen' => $flowScreenName,
                            'data' => [
                                "leave_id" => $leaveRequest['id'] ?? null,
                                "employee_no" => $leaveRequest['employee_no'] ?? null,
                                "employee_name" => $employeeName,
                                "start_date" => $fromDate,
                                "end_date" => $toDate,
                                "leave_reason" => $reason
                            ]
                        ]
                    ]
                ]
            ]
        ]);
    }

    /**
     * Ruang reply that also offers a hand-off to Pandu.
     *
     * Explicit button ids, unlike sendReplyButton(), which numbers them
     * positionally as btn_0/btn_1. handleInteractive() dispatches on the id, so
     * a positional id changes meaning the moment a button is added or reordered.
     */
    public function sendRuangHandoffButtons($userMobileNo, $messageBody)
    {
        return $this->sendApiRequest([
            "messaging_product" => "whatsapp",
            "to" => $userMobileNo,
            "type" => "interactive",
            "interactive" => [
                "type" => "button",
                "body" => ["text" => substr($messageBody, 0, 1024)],
                "action" => [
                    "buttons" => [
                        ["type" => "reply", "reply" => ["id" => "ruang_to_pandu", "title" => "Tanya di Pandu 💡"]],
                        ["type" => "reply", "reply" => ["id" => "ruang_stay", "title" => "Lanjut cerita"]],
                        ["type" => "reply", "reply" => ["id" => "ruang_done", "title" => "Selesai"]],
                    ]
                ]
            ]
        ]);
    }

    public function sendRuangWelcomeMessage($userMobileNo)
    {
        $welcomeMessage = "Hai! 😊 Aku, Nastari, di sini buat kasih kamu Ruang.\n\nRuang ini ada khusus buat kamu yang mau cerita soal suasana kerja, berbagi pengalaman, meluapkan perasaan, atau bahkan kasih masukan untuk perusahaan.\n\nTenang aja, apa pun yang kamu sampaikan cuma Nastari yang tahu, dan pasti dijaga kerahasiaannya. Nastari siap banget buat dengerin. ❤️\n\nSilakan mulai ceritamu di sini. Jika sudah selesai, cukup ketik *Selesai*.";
        return $this->sendMessage($userMobileNo, $welcomeMessage);
    }
}