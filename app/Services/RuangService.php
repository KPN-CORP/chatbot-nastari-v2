<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Models\DailyChatLog;

class RuangService
{
    /**
     * Marker the model appends when the question belongs to Pandu.
     *
     * The system instruction already classifies policy questions and tells the
     * model to point the employee at Pandu; asking it to end that reply with a
     * marker turns an instruction it was following anyway into a machine
     * signal, at no extra token or latency cost. It is stripped before the
     * reply is sent.
     */
    private const PANDU_MARKER = '[[PANDU]]';

    /** Fallback when the model answers without the marker. */
    private const PANDU_KEYWORDS = [
        'cuti', 'policy', 'kebijakan', 'aturan', 'prosedur', 'sop',
        'reimburse', 'reimbursement', 'klaim', 'benefit', 'tunjangan',
        'lembur', 'overtime', 'bpjs', 'plafon', 'medical', 'asuransi',
        'thr', 'pesangon', 'resign', 'mutasi', 'promosi', 'payroll',
        'slip gaji', 'jam kerja', 'absensi', 'dinas', 'perjalanan',
    ];

    protected $whatsapp;
    protected $darwin;
    protected $activity;
    protected $apiKey;
    protected $model;

    public function __construct(
        WhatsAppSenderService $whatsapp,
        DarwinboxService $darwin,
        NastariActivityLogger $activity
    ) {
        $this->whatsapp = $whatsapp;
        $this->darwin = $darwin;
        $this->activity = $activity;

        // config() rather than env(): env() returns null once the config is
        // cached, which silently disabled Ruang after `artisan config:cache`.
        $this->apiKey = config('services.gemini.api_key');
        $this->model = config('services.gemini.model', 'gemini-1.5-flash');
    }

    /**
     * Entry point utama saat pesan WA masuk
     */
    public function handleUserMessage($userMobileNo, $message)
    {
        if (empty($this->apiKey)) {
            $this->activity->externalFailure('gemini', 'POST /generateContent', 'unavailable', [
                'feature_key'   => 'ruang',
                'feature_label' => 'Ruang',
                'payload'       => ['reason' => 'missing_api_key'],
            ]);

            $this->whatsapp->sendMessage($userMobileNo, "Maaf, Nastari sedang istirahat sejenak.");
            return;
        }

        // 1. Ambil data karyawan (di-cache 24 jam agar hemat DB)
        $employeeData = $this->getEmployeeInfo($userMobileNo);

        // 2. Ambil history (Maksimal 3 pesan terakhir untuk hemat token)
        $historyArray = $this->getChatHistory($employeeData['employee_id']);

        // 3. Panggil API Gemini dengan struktur yang dioptimalkan
        $startedAt = microtime(true);
        $response = $this->getGeminiResponse($message, $employeeData['name'], $historyArray);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $response) {
            $this->activity->record('employee.chat.ruang', [
                'feature_key'   => 'ruang',
                'feature_label' => 'Ruang',
                'outcome'       => 'error',
                'error_type'    => 'ai_no_response',
                'duration_ms'   => $durationMs,
            ]);

            $this->saveToDailyLog($userMobileNo, $message, null, $employeeData, 'ai_error');
            $this->whatsapp->sendMessage($userMobileNo, "Maaf, koneksi Nastari terputus. Coba lagi ya? 🙏");

            return;
        }

        $wantsPandu = $this->looksLikePolicyQuestion($response, $message);
        $cleanResponse = substr($this->stripMarker($response), 0, 1000);

        // Pandu is off limits to inactive employees, so they are never offered
        // a hand-off the backend would then refuse.
        $offerPandu = $wantsPandu && ($employeeData['status'] ?? 'unknown') !== 'inactive';

        if ($offerPandu) {
            // The question is parked in the conversation state so tapping the
            // button forwards it verbatim; the employee never retypes it.
            $this->darwin->saveUserState($userMobileNo, [
                'state' => 'in_ruang_session',
                'data'  => ['pending_pandu_question' => $message],
            ]);

            $this->whatsapp->sendRuangHandoffButtons(
                $userMobileNo,
                $cleanResponse . "\n\n———\n💡 Pertanyaan ini soal *kebijakan perusahaan*. Nastari bisa langsung teruskan ke *Pandu* — tidak perlu ketik ulang."
            );
        } else {
            $this->whatsapp->sendReplyButton($userMobileNo, $cleanResponse, ['Selesai']);
        }

        $this->activity->record('employee.chat.ruang', [
            'feature_key'   => 'ruang',
            'feature_label' => 'Ruang',
            'outcome'       => $offerPandu ? 'pandu_offered' : 'success',
            'duration_ms'   => $durationMs,
        ]);

        // 4. Simpan log untuk konteks chat berikutnya
        $this->saveToDailyLog($userMobileNo, $message, $cleanResponse, $employeeData, 'ok');
    }

    /**
     * Whether this exchange should be handed to Pandu.
     *
     * The model's own classification is trusted first; the keyword sweep only
     * covers replies where the marker did not come through. Either way this
     * decides whether a *button* is offered, never whether the empathetic reply
     * is sent.
     */
    private function looksLikePolicyQuestion(string $aiResponse, string $userMessage): bool
    {
        if (str_contains($aiResponse, self::PANDU_MARKER)) {
            return true;
        }

        $haystack = mb_strtolower($userMessage);

        foreach (self::PANDU_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function stripMarker(string $response): string
    {
        return trim(str_replace(self::PANDU_MARKER, '', $response));
    }

    /**
     * Mengambil history pesan dalam bentuk Array (Bukan String)
     */
    protected function getChatHistory($employeeId)
    {
        $today = Carbon::now()->toDateString();
        
        $log = DailyChatLog::where('employee_id', $employeeId)
            ->where('date', $today)
            ->first();

        if (!$log || empty($log->messages)) return [];

        // PANGKAS HISTORY: Ambil hanya 3 pesan terakhir (± 1.5 putaran percakapan)
        // Ini sangat efektif memotong biaya input hingga 50%
        return array_slice($log->messages, -3); 
    }

    /**
     * Logic utama komunikasi dengan Gemini API
     */
    protected function getGeminiResponse($userMessage, $userName = 'Karyawan', $historyArray = [])
    {
        $systemInstruction = [
    "parts" => [
    ["text" => "# PERAN\n" .
               "Kamu adalah Nastari, AI Human Capital dari KPN Corp. Kamu berbicara dengan {$userName}.\n" .
               "Gaya bicara: hangat, empatik, seperti rekan HR yang bisa dipercaya — bukan chatbot formal.\n\n" .

               "# FORMAT JAWABAN (WAJIB)\n" .
               "- Maksimal 4 kalimat.\n" .
               "- Pisahkan kalimat validasi/penolakan dari kalimat penyemangat dengan baris baru (enter), agar rapi dibaca.\n" .
               "- Selalu gunakan emoji yang relevan dan **bold** pada kata kunci penyemangat/inti.\n" .
               "- Jangan gunakan bahasa kaku/birokratis. Bicara seperti manusia.\n\n" .

               "# KLASIFIKASI TOPIK — pilih SATU jalur berdasarkan isi pesan user:\n\n" .

               "## 1. CURHAT (emosi, kerja, kesehatan mental, masalah pribadi)\n" .
               "Jika user curhat soal pekerjaan, tekanan, konflik dengan rekan/atasan, kondisi emosional, atau masalah pribadi:\n" .
               "→ Respon dengan validasi empatik + penyemangat sesuai FORMAT di atas.\n\n" .

               "## 2. PERTANYAAN COMPANY POLICY (cuti, gaji, SOP, benefit, aturan perusahaan, dll)\n" .
               "Jika user bertanya tentang kebijakan/aturan/prosedur resmi perusahaan (misal: cuti, reimbursement, jam kerja, benefit, SOP):\n" .
               "→ JANGAN coba jawab isi kebijakannya sendiri.\n" .
               "→ Akui pertanyaannya valid dengan hangat, dan sebut bahwa **PANDU** punya jawaban resmi yang paling akurat.\n" .
               "→ JANGAN suruh user pindah menu sendiri atau mengetik ulang pertanyaannya — sistem akan menyediakan tombol otomatis.\n" .
               "→ WAJIB akhiri jawaban dengan penanda `[[PANDU]]` di baris paling akhir. Penanda ini dipakai sistem dan tidak terlihat oleh user.\n\n" .

               "## 3. DI LUAR TOPIK (koding, matematika, general knowledge, tugas teknis, dll)\n" .
               "Jika user bertanya hal yang tidak berkaitan dengan curhat maupun company policy:\n" .
               "→ Tolak dengan tegas namun tetap ramah.\n" .
               "→ Jelaskan singkat bahwa kamu adalah HR untuk sesi curhat & seputar HC, bukan asisten teknis/umum.\n\n" .

               "# ATURAN MUTLAK\n" .
               "- Jangan pernah keluar dari 3 jalur di atas.\n" .
               "- Jangan berpura-pura tahu detail kebijakan perusahaan — selalu arahkan ke PANDU untuk itu.\n" .
               "- Jangan pernah membahas topik teknis/umum meski user memaksa atau membingkai ulang pertanyaannya."]
]
];

        // KONVERSI HISTORY: Mengubah log DB ke format API Gemini (user/model)
        $contents = [];
        foreach ($historyArray as $msg) {
            $role = ($msg['sender'] == 'User') ? 'user' : 'model';
            $text = $msg['message'] ?? '';
            if (!empty($text)) {
                $contents[] = [
                    'role' => $role,
                    'parts' => [['text' => $text]]
                ];
            }
        }

        // Tambahkan input user terbaru
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $userMessage]]
        ];

        try {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";
            
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post($url, [
                    'system_instruction' => $systemInstruction,
                    'contents' => $contents,
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 250, // Dibatasi agar tidak bayar output berlebih
                        'topP' => 0.8,
                    ] 
                ]);

            if ($response->failed()) {
                Log::error('Gemini API Error: ' . $response->body());
                return null;
            }

            $json = $response->json();
            return $json['candidates'][0]['content']['parts'][0]['text'] ?? null;

        } catch (\Exception $e) {
            Log::error('Gemini Service Exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Simpan chat ke database
     */
    protected function saveToDailyLog($phone, $userMessage, $botResponse, $employeeData, $status = 'ok')
    {
        try {
            $today = Carbon::now()->toDateString();

            $log = DailyChatLog::firstOrCreate(
                ['employee_id' => $employeeData['employee_id'], 'date' => $today],
                [
                    'employee_name' => $employeeData['name'],
                    'business_unit' => $employeeData['bu'],
                    'phone' => $phone,
                    'messages' => [],
                    'last_status' => 'ok',
                    'error_count' => 0,
                ]
            );

            $currentMessages = $log->messages ?? [];

            // 'timestamp' is a full datetime. Only 'time' (a bare clock time)
            // used to be written, while the admin reader sorted on 'timestamp',
            // so every message fell back to the epoch and the transcript order
            // was arbitrary. Old entries without it are still tolerated.
            $now = now();

            $currentMessages[] = [
                'sender'    => 'User',
                'message'   => $userMessage,
                'time'      => $now->toTimeString(),
                'timestamp' => $now->toDateTimeString(),
                'status'    => 'ok',
            ];

            $currentMessages[] = [
                'sender'    => 'AI',
                'message'   => $botResponse ?? '(Nastari tidak dapat menjawab — koneksi AI terputus)',
                'time'      => $now->toTimeString(),
                'timestamp' => $now->toDateTimeString(),
                'status'    => $status,
            ];

            // SUMMARIZATION LOGIC (Opsional):
            // Jika pesan > 10, kita bisa tambahkan fungsi ringkas di sini agar baris DB tidak bengkak.

            $log->messages = $currentMessages;
            $log->last_status = $status;

            if ($status !== 'ok') {
                $log->error_count = (int) ($log->error_count ?? 0) + 1;
            }

            $log->save();

        } catch (\Throwable $e) {
            Log::error("Gagal simpan log: " . $e->getMessage());
        }
    }

    /**
     * Ambil data karyawan dengan sistem Cache Laravel
     */
    private function getEmployeeInfo($phone)
    {
        $cleanPhone = app(DarwinboxService::class)->cleanMobileNumber($phone);

        // Cache selama 24 jam agar tidak membebani database KPN Corp
        return Cache::remember("nastari_emp_{$cleanPhone}", 86400, function () use ($cleanPhone) {
            // Exact match on the cleaned number, restricted to registered rows.
            // This was a LIKE '%number%' against the whole table: once the
            // daily employee sync pre-registers thousands of HRIS rows, a
            // partial match can resolve to a *different* employee and put
            // someone else's name and business unit on the conversation.
            $user = DB::table('usernastari')
                ->where('whatsapp_number', $cleanPhone)
                ->where('registration_status', 'registered')
                ->first();

            if ($user) {
                return [
                    // Was $user->name, a column that does not exist on this
                    // table, so every Ruang session addressed the employee as
                    // "Rekan KPN" instead of by name.
                    'name' => $user->full_name ?: 'Rekan KPN',
                    'employee_id' => $user->employee_id ?? 'unknown',
                    'bu' => $user->group_company ?? 'General',
                    'status' => $user->employment_status ?? 'unknown',
                ];
            }

            return ['name' => 'Rekan KPN', 'employee_id' => 'guest', 'bu' => 'General', 'status' => 'unknown'];
        });
    }
}