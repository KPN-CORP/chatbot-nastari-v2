<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\Models\Hear;

class HearService
{
    protected $whatsapp;
    protected $darwin;
    protected $picFile;
    protected $logDir;

    protected $activity;

    public function __construct(
        WhatsAppSenderService $whatsapp,
        DarwinboxService $darwin,
        NastariActivityLogger $activity
    ) {
        $this->whatsapp = $whatsapp;
        $this->darwin = $darwin;
        $this->activity = $activity;
        $this->logDir = storage_path('app/logs/');
        if (!is_dir($this->logDir)) mkdir($this->logDir, 0775, true);
        $this->picFile = $this->logDir . 'pic-master.json';
    }

    public function handleHearInitial($userMobileNo)
    {
        $msg = "Halo! Selamat datang di Pandu 💡, layanan Helpdesk Cerdas dari *Nastari*. 👋\n\nSaya siap membantu menjawab pertanyaan Anda seputar _company policy_.\n\nContoh pertanyaan:\n🏖️ _\"Bagaimana prosedur pengajuan cuti tahunan?\"_\n👓 _\"Bagaimana cara klaim kacamata?\"_\n\nJika informasi belum tersedia, saya bisa bantu buatkan tiket ke tim HC.\n\nSilakan ketik pertanyaan Anda, atau ketik *Selesai* untuk kembali.";
        $this->whatsapp->sendMessage($userMobileNo, $msg);
        $this->darwin->saveUserState($userMobileNo, ['state' => 'waiting_for_hear_question', 'data' => []]);
    }

    /**
     * @param  string|null  $messageId  Null when the question was not typed in
     *                                  this turn, e.g. forwarded from Ruang.
     * @param  string|null  $handoffFrom  Set to 'ruang' when the employee came
     *                                    through the "Tanya di Pandu" button.
     */
    public function handleQuestion($userMobileNo, $question, $employee, $messageId = null, $handoffFrom = null)
    {
        if (! empty($messageId)) {
            $this->whatsapp->markMessageAsRead($messageId);
        }

        if ($handoffFrom === 'ruang') {
            // Echo the question back so the employee can see exactly what was
            // forwarded, rather than wondering whether it arrived.
            $this->whatsapp->sendMessage(
                $userMobileNo,
                "Oke, Nastari teruskan pertanyaanmu ke *Pandu* ya 💡\n\n_\"" . mb_substr(trim($question), 0, 400) . "\"_\n\nSebentar, Pandu sedang mencari jawabannya... ⏳"
            );
        }

        $startedAt = microtime(true);
        $ragResponse = $this->askRagService($userMobileNo, $question, $employee);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $answer = $ragResponse['answer'] ?? null;
        $isNotFound = $ragResponse['not_found'] ?? false;
        $answered = !empty($answer) && $isNotFound === false;

        // Until now, a successfully answered Pandu question was never persisted
        // anywhere: `hears` only receives escalations, so any DB-only view of
        // Pandu measured nothing but its failures.
        $this->activity->record('employee.chat.pandu', [
            'feature_key'   => 'pandu',
            'feature_label' => 'PANDU',
            'service'       => 'pandu_rag',
            'endpoint'      => 'POST /ask',
            'outcome'       => $ragResponse === null ? 'error' : ($answered ? 'answered' : 'not_found'),
            'duration_ms'   => $durationMs,
            'payload'       => [
                'question'     => mb_substr(trim((string) $question), 0, 500),
                'handoff_from' => $handoffFrom,
            ],
        ]);

        // Isi tanya-jawabnya, bukan hanya metriknya. Sampai sekarang jawaban
        // Pandu dikirim ke WhatsApp lalu hilang: tidak ada satu pun tempat di
        // database yang menyimpannya, jadi tidak ada cara memeriksa apakah
        // Pandu menjawab dengan benar.
        $this->rememberQa($userMobileNo, $employee, $question, $answer, [
            'outcome'      => $ragResponse === null ? 'error' : ($answered ? 'answered' : 'not_found'),
            'duration_ms'  => $durationMs,
            'handoff_from' => $handoffFrom,
        ]);

        if ($answered) {
            $this->whatsapp->sendMessage($userMobileNo, $answer);
            $this->whatsapp->sendAIFollowUpMessage($userMobileNo);
        } else {
            $msg = "Hmm, sepertinya informasi detail mengenai ini belum ada dalam catatan *Nastari*. 🤔\n\nAgar Anda mendapatkan jawaban yang akurat, *Nastari* bisa bantu membuatkan tiket dari pertanyaan ini untuk diteruskan ke tim HCBU. Lanjut?";
            $this->darwin->saveUserState($userMobileNo, [
                'state' => 'waiting_for_escalation_confirmation', 
                'data' => ['question' => $question]
            ]);
            $this->whatsapp->sendEscalationConfirmation($userMobileNo, $msg);
        }
    }

    /**
     * Menyimpan satu tanya-jawab Pandu ke pandu_qa.
     *
     * Dibungkus seluruhnya: kegagalan menyimpan riwayat tidak boleh membuat
     * karyawan gagal mendapat jawabannya. Ini juga sebabnya penulisannya
     * dilakukan setelah panggilan RAG dan sebelum pesan dikirim — satu INSERT
     * tidak berarti apa-apa dibandingkan panggilan HTTP yang baru saja
     * ditunggu beberapa detik.
     *
     * @param  array<string, mixed>  $meta  outcome, duration_ms, handoff_from
     */
    private function rememberQa($userMobileNo, $employee, $question, ?string $answer, array $meta): void
    {
        try {
            $phone = $this->darwin->cleanMobileNumber((string) $userMobileNo);
            $askedAt = now();

            DB::table('pandu_qa')->insertOrIgnore([
                'origin'        => 'live',
                'asked_at'      => $askedAt,
                'ask_date'      => $askedAt->toDateString(),
                'ask_hour'      => (int) $askedAt->format('G'),
                'phone'         => $phone,
                'employee_id'   => $employee['employee_id'] ?? null,
                'employee_name' => $employee['full_name'] ?? null,
                'business_unit' => $employee['group_company'] ?? null,
                'question'      => mb_substr(trim((string) $question), 0, 2000),
                'answer'        => $answer === null ? null : mb_substr(trim($answer), 0, 8000),
                'outcome'       => $meta['outcome'] ?? 'answered',
                'duration_ms'   => $meta['duration_ms'] ?? null,
                'handoff_from'  => $meta['handoff_from'] ?? null,
                'fingerprint'   => sha1('live|' . $phone . '|' . $askedAt->format('Y-m-d H:i:s') . '|' . mb_substr((string) $question, 0, 200)),
                'created_at'    => $askedAt,
                'updated_at'    => $askedAt,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PANDU_QA_WRITE_FAILED', ['reason' => $e->getMessage()]);
        }
    }

    /**
     * Menandai baris tanya-jawab terakhir milik nomor ini dengan kode tiket.
     *
     * Dicocokkan pada nomor dan pertanyaannya, bukan hanya "baris terakhir":
     * karyawan bisa menanyakan beberapa hal sebelum memilih mengeskalasi.
     */
    private function linkTicketToQa($userMobileNo, $question, string $ticketCode): void
    {
        try {
            $phone = $this->darwin->cleanMobileNumber((string) $userMobileNo);

            DB::table('pandu_qa')
                ->where('phone', $phone)
                ->where('question', mb_substr(trim((string) $question), 0, 2000))
                ->whereNull('ticket_code')
                ->orderByDesc('id')
                ->limit(1)
                ->update(['ticket_code' => $ticketCode, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('PANDU_QA_LINK_FAILED', ['reason' => $e->getMessage()]);
        }
    }

    public function handleCancelEscalation($userMobileNo)
    {
        $msg = "Baik, tiket tidak dibuat. 😊\n\nApakah ada hal lain seputar kebijakan perusahaan yang ingin Anda tanyakan? Silakan ketik pertanyaan Anda di sini.";
        $this->whatsapp->sendMessage($userMobileNo, $msg);
        $this->darwin->saveUserState($userMobileNo, ['state' => 'waiting_for_hear_question', 'data' => []]);
    }

    private function askRagService($userMobileNo, $question, $employee)
    {
        $userState = $this->darwin->getUserState($userMobileNo);
        $sessionId = $userState['data']['session_id'] ?? null;

        if (!$sessionId) {
            $res = Http::post('http://127.0.0.1:5003/session', ['question' => $question]);
            if ($res->successful()) {
                $sessionId = $res->json('session_id');
                $userState['data']['session_id'] = $sessionId;
                $this->darwin->saveUserState($userMobileNo, $userState);
            } else {
                return null;
            }
        }

        $buRaw = $employee['group_company'] ?? null;
        
        Log::info("PANDU_DEBUG: Request dari WA {$userMobileNo}");
        Log::info("PANDU_DEBUG: BU Raw dari DB: " . ($buRaw ?? 'NULL'));


        $businessUnit = $buRaw ?? 'KPN Corporation';

        $payload = [
            'session_id' => $sessionId,
            'question' => $question,
            'debug_retrieval' => false,
            'business_unit' => $businessUnit
        ];

        Log::info("PANDU_DEBUG: Payload ke Python: " . json_encode($payload));

        $res = Http::post('http://127.0.0.1:5003/ask', $payload);
        
        $result = $res->successful() ? $res->json() : null;
        
        Log::info("PANDU_DEBUG: Respon Python: " . json_encode($result));

        return $result;
    }
    
    public function processEscalation($userMobileNo, $employee, $question)
    {
        $picInfo = $this->findPic($employee);
        
        $ticket = $this->createTicket($userMobileNo, $question, $employee, $picInfo['name']);
        
        if (!$ticket) {
            $this->whatsapp->sendMessage($userMobileNo, "Maaf, terjadi kesalahan sistem saat membuat tiket.");
            return;
        }

        // Tautkan tiketnya ke baris tanya-jawab yang memicunya, supaya di
        // dashboard sebuah pertanyaan "tidak ditemukan" bisa langsung
        // diikuti sampai jawaban HCO-nya.
        $this->linkTicketToQa($userMobileNo, $question, (string) $ticket->ticket_code);

        $this->sendEscalationEmail($ticket, $picInfo);

        $catName = ($picInfo['name'] === 'Metta Saputra') ? 'HC BU' : 'HC Officer';
        $msg = "Baik, pertanyaan Anda telah dicatat dengan *ID Tiket: #{$ticket->ticket_code}* dan diteruskan ke tim {$catName}. Anda akan dihubungi oleh PIC terkait.";
        
        $this->whatsapp->sendMessage($userMobileNo, $msg);
        $this->darwin->saveUserState($userMobileNo, ['state' => 'waiting_for_hear_question', 'data' => []]);
        $this->whatsapp->sendAIFollowUpMessage($userMobileNo);
    }

    // private function findPic($employee)
    // {
    //     $bu = trim($employee['group_company'] ?? 'KPN Corporation');
    //     $workAreaCode = trim($employee['work_area_code'] ?? '');

    //     $mapping = \Illuminate\Support\Facades\DB::table('hco_mappings')
    //         ->where('work_area_code', $workAreaCode)
    //         ->where('company_name', $bu)
    //         ->first();

    //     if (!$mapping) {
    //         $mapping = \Illuminate\Support\Facades\DB::table('hco_mappings')
    //             ->where('company_name', $bu)
    //             ->first();
    //     }

    //     if ($mapping) {
    //         $hcoEmployee = \Illuminate\Support\Facades\DB::connection('kpncorp')
    //             ->table('employees')
    //             ->where('employee_id', $mapping->hco_employee_id)
    //             ->first();

    //         $emailTujuan = ($hcoEmployee && !empty($hcoEmployee->email)) 
    //                         ? $hcoEmployee->email 
    //                         : 'metta.saputra@kpn-corp.com';

    //         return [
    //             'name' => $mapping->hco_name,
    //             'email' => $emailTujuan
    //         ];
    //     }

    //     return [
    //         'name' => 'Metta Saputra',
    //         'email' => 'metta.saputra@kpn-corp.com'
    //     ];
    // }
    
    private function findPic($employee)
    {
        $bu = trim($employee['group_company'] ?? 'Corporate');

        $mapping = \Illuminate\Support\Facades\DB::table('hco_mappings')
            ->where('group_company', $bu)
            ->first();

        if ($mapping) {
            $hcoEmployee = \Illuminate\Support\Facades\DB::connection('kpncorp')
                ->table('employees')
                ->where('employee_id', $mapping->hco_employee_id)
                ->first();

            $emailTujuan = ($hcoEmployee && !empty($hcoEmployee->email)) 
                            ? $hcoEmployee->email 
                            : 'metta.saputra@kpn-corp.com';

            return [
                'name' => $mapping->hco_name,
                'email' => $emailTujuan
            ];
        }

        return [
            'name' => 'Metta Saputra',
            'email' => 'metta.saputra@kpn-corp.com'
        ];
    }

    private function createTicket($phone, $text, $emp, $pic)
    {
        $lastId = Hear::max('id') ?? 0;
        $ticketCode = sprintf('%03d', $lastId + 1);
        $token = bin2hex(random_bytes(32));
        $ticket = Hear::create([
            'ticket_code' => $ticketCode,
            'date' => date('Y-m-d H:i:s'),
            'phone' => $phone,
            'employee_id' => $emp['employee_id'] ?? null,
            'name' => $emp['full_name'] ?? 'N/A',
            'email' => $emp['company_email_id'] ?? 'N/A',
            'question' => $text,
            'pic' => $pic,
            'status' => 'Processing',
            'token' => $token
        ]);
        return $ticket;
    }

    private function sendEscalationEmail($ticket, $picInfo)
    {
        $mail = new PHPMailer(true);
        try {
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
            $mail->isSMTP();
            $mail->Host = 'mail.hcis.live';
            $mail->SMTPAuth = true;
            $mail->Username = 'hc-system@hcis.live';
            $mail->Password = '.p$FPmicQ.i#';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = 465;
            $mail->setFrom('hc-system@hcis.live', 'Pandu by Nastari');
            
            $url = route('hear.ticket.show', ['token' => $ticket->token]);
            
            $mail->addAddress($picInfo['email'], $picInfo['name']);
            $mail->isHTML(true);
            $mail->Subject = 'Tiket #' . $ticket->ticket_code . ': ' . $ticket->name;

            $mail->Body = "
            <div style='font-family: sans-serif; max-width: 500px; border: 1px solid #eee; border-radius: 4px;'>
                <div style='background: #AB2F2B; padding: 10px 15px;'>
                    <b style='color: #fff; font-size: 14px;'>PANDU HELP DESK</b>
                </div>
                <div style='padding: 15px;'>
                    <p style='margin: 0 0 10px 0; font-size: 14px;'>Halo <b>{$picInfo['name']}</b>, ada tiket baru:</p>
                    
                    <div style='background: #fcfcfc; border: 1px solid #eee; padding: 10px; margin-bottom: 15px;'>
                        <table style='width: 100%; font-size: 13px; border-collapse: collapse;'>
                            <tr>
                                <td style='color: #888; width: 80px; padding-bottom: 4px;'>ID</td>
                                <td style='padding-bottom: 4px;'><b>#{$ticket->ticket_code}</b></td>
                            </tr>
                            <tr>
                                <td style='color: #888; padding-bottom: 4px;'>DARI</td>
                                <td style='padding-bottom: 4px;'>{$ticket->name} ({$ticket->employee_id})</td>
                            </tr>
                            <tr>
                                <td style='color: #888; vertical-align: top;'>TANYA</td>
                                <td style='font-style: italic; color: #333;'>\"{$ticket->question}\"</td>
                            </tr>
                        </table>
                    </div>

                    <a href='{$url}' style='background: #AB2F2B; color: #fff; padding: 8px 20px; text-decoration: none; border-radius: 4px; font-size: 13px; font-weight: bold; display: inline-block;'>Balas Tiket</a>
                </div>
                <div style='background: #f9f9f9; padding: 8px 15px; border-top: 1px solid #eee; font-size: 10px; color: #999; text-align: center;'>
                    &copy; " . date('Y') . " HCIS KPN Corp
                </div>
            </div>";

            $mail->send();
        } catch (Exception $e) {}
    }
}