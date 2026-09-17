<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\DarwinboxService;
use App\Services\WhatsAppSenderService;
use App\Services\RuangService;
use App\Services\HearService;
use App\Services\ManagerAssistantService;
use App\Services\EmployeeAccessPolicy;
use App\Services\NastariActivityLogger;
use App\Support\NastariFeatures;

class WebhookController extends Controller
{
    protected $darwin;
    protected $whatsapp;
    protected $ruang;
    protected $hear;
    protected $managerAI;
    protected $policy;
    protected $activity;

    public function __construct(
        DarwinboxService $darwin,
        WhatsAppSenderService $whatsapp,
        ManagerAssistantService $managerAI,
        RuangService $ruang,
        HearService $hear,
        EmployeeAccessPolicy $policy,
        NastariActivityLogger $activity
    )
    {
        $this->darwin = $darwin;
        $this->whatsapp = $whatsapp;
        $this->managerAI = $managerAI;
        $this->ruang = $ruang;
        $this->hear = $hear;
        $this->policy = $policy;
        $this->activity = $activity;
    }

    // public function handle(Request $request)
    // {
    //     $entry = $request->input('entry.0.changes.0.value');
    //     if (!$entry || !isset($entry['messages'][0])) return response()->json(['status' => 'no_message']);

    //     $message = $entry['messages'][0];
    //     $from = $message['from'];
    //     $messageId = $message['id'];

    //     $cacheKey = 'processed_msg_' . $messageId;
    //     if (Cache::has($cacheKey)) return response()->json(['status' => 'duplicate_ignored']);
    //     Cache::put($cacheKey, true, 60);

    //     $this->whatsapp->markMessageAsRead($messageId);

    //     $user = \App\Models\UserNastari::where('whatsapp_number', $from)->first();

    //     if (!$user) {
    //         $this->whatsapp->sendMessage($from, "Wah, sepertinya ini pertama kali kamu akses *Nastari*. 👋\n\nMohon ditunggu sebentar ya, Nastari lagi cek apakah nomor HP kamu terdaftar di HC System atau tidak... ⏳");
    //         $apiResult = $this->darwin->getEmployeeByPhone($from);
    //         if (!$apiResult) {
    //             $msgNotFound = "Mohon maaf, *Nastari* tidak mengenali nomor Whatsapp yang kamu gunakan. Silahkan gunakan nomor dengan Whatsapp aktif yang *terdaftar* di *HC System Darwinbox(, atau hubungi HCO Unit Bisnis kamu untuk info lebih lanjut yaa 😊\n\nBerikut adalah cara ganti nomor HP di Darwinbox:\n1. Buka aplikasi Darwinbox di HP kamu\n2. Klik foto profile di bagian atas\n3. Klik View Personal Details\n4. Kemudian cari dan klik tab Contact\n5. Klik ikon Pensil, dan isi nomor HP dengan benar\n6. Kalau sudah selesai, klik Save. Selanjutnya tunggu approval dari HCO kamu ya!\n\nSalam,\nNastari";
    //             $this->whatsapp->sendMessage($from, $msgNotFound);
    //             return response()->json(['status' => 'unauthorized']);
    //         }
    //         $user = $this->darwin->syncUserNastari($apiResult);
            
    //         $employee = $user->toArray();
    //         $this->sendBackMenu($from, 'main', $employee);
    //         return response()->json(['status' => 'success_new_user']);
    //     }

    //     $employee = $user->toArray();
    //     $userState = $this->darwin->getUserState($from);
    //     $currentState = is_array($userState) ? ($userState['state'] ?? 'idle') : 'idle';

    //     if (isset($message['image']) && $currentState === 'waiting_for_call_center_photo') {
    //         try {
    //             $mediaId = $message['image']['id'];
    //             $this->whatsapp->sendMessage($from, "Sedang mengunduh bukti foto... ⏳");
    //             $localPath = $this->whatsapp->downloadMedia($mediaId);
    //             if ($localPath) {
    //                 $complaint = $userState['data']['complaint'] ?? 'Tidak ada keterangan';
    //                 $ticketId = $this->darwin->createCallCenterTicket($from, $employee, $complaint, $localPath);
    //                 $this->whatsapp->sendMessage($from, "✅ Laporan diterima dengan ID Tiket: *{$ticketId}*.\nTim HC akan segera mengecek kendala Anda.");
    //             } else {
    //                 $this->whatsapp->sendMessage($from, "❌ Gagal mengunduh foto. Server sedang sibuk. Silakan coba lagi nanti.");
    //             }
    //             $this->darwin->saveUserState($from, ['state' => 'idle']);
    //             $this->sendBackMenu($from, 'main', $employee);
    //         } catch (\Exception $e) {
    //             Log::error("Error Call Center: " . $e->getMessage());
    //             $this->darwin->saveUserState($from, ['state' => 'idle']);
    //             $this->whatsapp->sendMessage($from, "Terjadi kesalahan sistem saat memproses gambar.");
    //         }
    //         return response()->json(['status' => 'success']);
    //     }

    //     if (isset($message['interactive']['button_reply'])) {
    //         $btnTitle = $message['interactive']['button_reply']['title'] ?? '';
    //         if ($btnTitle === 'Selesai' || $btnTitle === 'Selesai ✅') {
    //             $message['text']['body'] = 'Selesai';
    //             unset($message['interactive']);
    //         }
    //     }

    //     if (isset($message['interactive'])) {
    //         $this->handleInteractive($from, $message['interactive'], $employee);
    //     } elseif (isset($message['text'])) {
    //         $textBody = $message['text']['body'];
    //         if (in_array(strtolower(trim($textBody)), ['menu', '0', 'batal'])) {
    //             $this->darwin->saveUserState($from, ['state' => 'idle']);
    //             $this->sendBackMenu($from, 'main', $employee);
    //         } elseif ($currentState !== 'idle') {
    //             $this->handleStatefulTextReply($from, $textBody, $currentState, $userState, $employee, $messageId);
    //         } else {
    //             $this->handleText($from, $textBody, $employee);
    //         }
    //     }

    //     return response()->json(['status' => 'success']);
    // }
    public function handle(Request $request)
    {
        $entry = $request->input('entry.0.changes.0.value');
        if (!$entry || !isset($entry['messages'][0])) return response()->json(['status' => 'no_message']);

        $message = $entry['messages'][0];
        $from = $message['from'];
        $messageId = $message['id'];

        $cacheKey = 'processed_msg_' . $messageId;
        if (Cache::has($cacheKey)) return response()->json(['status' => 'duplicate_ignored']);
        Cache::put($cacheKey, true, 60);
        
        $messageType = $message['type'] ?? 'unknown';
        $logContent = '';

        if (isset($message['text'])) {
            $logContent = $message['text']['body'];
        } elseif (isset($message['interactive']['button_reply'])) {
            $logContent = "[Button Click] " . $message['interactive']['button_reply']['title'];
        } elseif (isset($message['interactive']['list_reply'])) {
            $logContent = "[List Select] " . $message['interactive']['list_reply']['title'];
        } elseif (isset($message['interactive']['nfm_reply'])) {
            $logContent = "[NFM Form Submit] " . $message['interactive']['nfm_reply']['response_json'];
        } elseif (isset($message['image'])) {
            $logContent = "[Image] ID: " . $message['image']['id'];
        } else {
            $logContent = "[Other] " . json_encode($message); // Jaga-jaga untuk tipe file lain
        }

        // Kept for humans and `php artisan pail`. Analytics no longer depends
        // on parsing it: the structured rows below are the source of truth.
        Log::info("WA_INCOMING | From: {$from} | Type: {$messageType} | Message: {$logContent}");

        $this->activity->identify($from);

        $this->whatsapp->markMessageAsRead($messageId);

        // registered() only. usernastari also holds rows pre-registered from the
        // HRIS master by the daily sync; those are dashboard coverage data and
        // must never be used to identify a caller, because HRIS phone numbers
        // are 77% populated and dirty. First contact still goes through the
        // authoritative Darwinbox lookup, which then promotes the row.
        $user = \App\Models\UserNastari::where('whatsapp_number', $this->darwin->cleanMobileNumber($from))
            ->registered()
            ->first();

        if (!$user) {
            $this->whatsapp->sendMessage($from, "Wah, sepertinya ini pertama kali kamu akses *Nastari*. 👋\n\nMohon ditunggu sebentar ya, Nastari lagi cek apakah nomor HP kamu terdaftar di HC System atau tidak... ⏳");

            $lookupStarted = microtime(true);
            $this->activity->record('employee.lookup_started', ['message_type' => $messageType]);

            $apiResult = $this->darwin->getEmployeeByPhone($from);
            $lookupMs = (int) round((microtime(true) - $lookupStarted) * 1000);

            if (!$apiResult) {
                // The identifier is stored cleaned, never the raw display name
                // or NIK, and every read path masks it.
                $this->activity->record('employee.not_found', [
                    'outcome'           => 'not_found',
                    'is_known_employee' => false,
                    'duration_ms'       => $lookupMs,
                    'message_type'      => $messageType,
                ]);

                $msgNotFound ="Mohon maaf, *Nastari* tidak mengenali nomor Whatsapp yang kamu gunakan. Silahkan gunakan nomor dengan Whatsapp aktif yang *terdaftar* di *HC System Darwinbox(, atau hubungi HCO Unit Bisnis kamu untuk info lebih lanjut yaa 😊\n\nBerikut adalah cara ganti nomor HP di Darwinbox:\n1. Buka aplikasi Darwinbox di HP kamu\n2. Klik foto profile di bagian atas\n3. Klik View Personal Details\n4. Kemudian cari dan klik tab Contact\n5. Klik ikon Pensil, dan isi nomor HP dengan benar\n6. Kalau sudah selesai, klik Save. Selanjutnya tunggu approval dari HCO kamu ya!\n\nSalam,\nNastari";
                $this->whatsapp->sendMessage($from, $msgNotFound);
                return response()->json(['status' => 'unauthorized']);
            }
            $user = $this->darwin->syncUserNastari($apiResult);

            $employee = $user->toArray();

            $this->activity->identify($from, $employee);
            $this->activity->record('employee.found', [
                'outcome'     => 'success',
                'duration_ms' => $lookupMs,
            ]);

            $this->sendBackMenu($from, 'main', $employee);
            return response()->json(['status' => 'success_new_user']);
        }

        $employee = $user->toArray();
        $this->activity->identify($from, $employee);

        $userState = $this->darwin->getUserState($from);
        $currentState = is_array($userState) ? ($userState['state'] ?? 'idle') : 'idle';

        // Backend authorisation for inactive employees, applied to the ongoing
        // conversation state as well as to buttons. The letter and Pandu flows
        // continue through plain text, so a state check is the only thing that
        // stops an inactive employee resuming a flow they started while active.
        if (!$this->policy->allowsState($employee, $currentState)) {
            $this->activity->record('employee.access.denied', [
                'feature_key'   => 'state:' . $currentState,
                'feature_label' => 'Sesi dihentikan (nonaktif)',
                'outcome'       => 'denied',
            ]);

            $this->darwin->saveUserState($from, ['state' => 'idle']);
            $this->whatsapp->sendMessage($from, $this->policy->denialMessage());
            $this->sendBackMenu($from, 'main', $employee);

            return response()->json(['status' => 'inactive_employee']);
        }

        if (isset($message['image']) && $currentState === 'waiting_for_call_center_photo') {
            try {
                $mediaId = $message['image']['id'];
                $this->whatsapp->sendMessage($from, "Sedang mengunduh bukti foto... ⏳");
                $localPath = $this->whatsapp->downloadMedia($mediaId);
                if ($localPath) {
                    $complaint = $userState['data']['complaint'] ?? 'Tidak ada keterangan';
                    $ticketId = $this->darwin->createCallCenterTicket($from, $employee, $complaint, $localPath);
                    $this->whatsapp->sendMessage($from, "✅ Laporan diterima dengan ID Tiket: *{$ticketId}*.\nTim HC akan segera mengecek kendala Anda.");
                } else {
                    $this->whatsapp->sendMessage($from, "❌ Gagal mengunduh foto. Server sedang sibuk. Silakan coba lagi nanti.");
                }
                $this->darwin->saveUserState($from, ['state' => 'idle']);
                $this->sendBackMenu($from, 'main', $employee);
            } catch (\Exception $e) {
                Log::error("Error Call Center: " . $e->getMessage());
                $this->darwin->saveUserState($from, ['state' => 'idle']);
                $this->whatsapp->sendMessage($from, "Terjadi kesalahan sistem saat memproses gambar.");
            }
            return response()->json(['status' => 'success']);
        }

        if (isset($message['interactive']['button_reply'])) {
            $btnTitle = $message['interactive']['button_reply']['title'] ?? '';
            if ($btnTitle === 'Selesai' || $btnTitle === 'Selesai ✅') {
                $message['text']['body'] = 'Selesai';
                unset($message['interactive']);
            }
        }

        if (isset($message['interactive'])) {
            $this->handleInteractive($from, $message['interactive'], $employee);
        } elseif (isset($message['text'])) {
            $textBody = $message['text']['body'];
            if (in_array(strtolower(trim($textBody)), ['menu', '0', 'batal'])) {
                $this->darwin->saveUserState($from, ['state' => 'idle']);
                $this->sendBackMenu($from, 'main', $employee);
            } elseif ($currentState !== 'idle') {
                $this->handleStatefulTextReply($from, $textBody, $currentState, $userState, $employee, $messageId);
            } else {
                $this->handleText($from, $textBody, $employee);
            }
        }

        return response()->json(['status' => 'success']);
    }

    protected function handleInteractive($from, $interactive, $employee)
    {
        $type = $interactive['type'];

        // Authorisation and attribution both happen here, before any dispatch.
        // This is the only place an interactive payload enters the app, and a
        // WhatsApp payload is nothing but an id: an old menu message can be
        // re-tapped, so hiding a row in the menu is not a permission check.
        $payloadId = match ($type) {
            'list_reply'   => $interactive['list_reply']['id'] ?? null,
            'button_reply' => $interactive['button_reply']['id'] ?? null,
            'nfm_reply'    => 'leave_approval',
            default        => null,
        };

        if (!$this->policy->allowsInteractive($employee, $payloadId)) {
            $this->activity->record('employee.access.denied', array_merge(
                $this->policy->denialFeature($payloadId),
                ['outcome' => 'denied']
            ));

            $this->darwin->saveUserState($from, ['state' => 'idle']);
            $this->whatsapp->sendMessage($from, $this->policy->denialMessage());
            $this->sendBackMenu($from, 'main', $employee);

            return;
        }

        $this->recordInteractive($payloadId, $type);

        if ($type === 'nfm_reply') {
            $responseJson = $interactive['nfm_reply']['response_json'];
            $decoded = json_decode($responseJson, true);
            $leaveId = $decoded['leave_id'] ?? null;
            $status = $decoded['status_pengajuan'] ?? null;
            $reason = $decoded['alasan_manajer'] ?? '';
            $empNo = $decoded['employee_no'] ?? '';
            $empName = $decoded['employee_name'] ?? 'Karyawan';

            if ($leaveId && $status) {
                $res = $this->darwin->updateLeaveStatus($leaveId, $empNo, $status, $reason);
                
                if (isset($res['status']) && $res['status'] == 1) {
                    $actionText = (strtolower($status) === 'approve') ? "disetujui" : "ditolak";
                    $msg = "✅ Berhasil! Pengajuan cuti *{$empName}* telah {$actionText} dan HC System Darwinbox sudah diperbarui.";
                } else {
                    $msg = "❌ Maaf, gagal memproses persetujuan cuti *{$empName}*. Silakan coba lagi atau lakukan melalui HC System Darwinbox.";
                }

                $this->whatsapp->sendMessage($from, $msg);
                $this->sendBackMenu($from, 'tim_saya', $employee); 
            }
            return;
        }

        $id = ($type === 'list_reply') ? $interactive['list_reply']['id'] : $interactive['button_reply']['id'];

        if (str_starts_with($id, 'act_approve_') || str_starts_with($id, 'act_reject_')) {
            $parts = explode('_', $id);
            $action = $parts[1]; 
            $leaveId = $parts[2];
            $empNo = $parts[3];
            if ($action === 'approve') {
                $this->darwin->updateLeaveStatus($leaveId, $empNo, 'approve', 'Approved via WA');
                $this->whatsapp->sendMessage($from, "✅ Cuti disetujui.");
                $this->sendBackMenu($from, 'tim_saya', $employee);
            } else {
                $this->darwin->saveUserState($from, [
                    'state' => 'waiting_for_rejection_reason',
                    'data' => ['leave_id' => $leaveId, 'employee_no' => $empNo]
                ]);
                $this->whatsapp->sendMessage($from, "Mohon ketik alasan penolakan:");
            }
            return;
        }

        if (str_starts_with($id, 'visa_')) {
            $this->handleVisaButtons($from, $id, $employee);
            return;
        }

        // Ruang -> Pandu hand-off. The question the employee already typed in
        // Ruang was parked in the conversation state, so it is forwarded
        // verbatim and they never retype it or walk back through the menus.
        if ($id === 'ruang_to_pandu') {
            $state = $this->darwin->getUserState($from);
            $question = $state['data']['pending_pandu_question'] ?? null;

            $this->darwin->saveUserState($from, [
                'state' => 'waiting_for_hear_question',
                'data'  => ['handoff_from' => 'ruang', 'pending_pandu_question' => null],
            ]);

            if (empty($question)) {
                // The state lives in the cache with a 24h TTL, so the parked
                // question can legitimately be gone. Fall back to the normal
                // Pandu greeting rather than failing.
                $this->hear->handleHearInitial($from);

                return;
            }

            $this->activity->record('employee.chat.ruang_handoff_pandu', [
                'feature_key'   => 'pandu',
                'feature_label' => 'Ruang → Pandu (handoff)',
                'outcome'       => 'forwarded',
            ]);

            $this->hear->handleQuestion($from, $question, $employee, null, 'ruang');

            return;
        }

        if ($id === 'ruang_stay') {
            $this->darwin->saveUserState($from, [
                'state' => 'in_ruang_session',
                'data'  => ['pending_pandu_question' => null],
            ]);
            $this->whatsapp->sendMessage($from, "Oke, Nastari tetap di sini. Lanjut ceritamu ya ❤️");

            return;
        }

        if ($id === 'hear_escalate_yes') {
            $state = $this->darwin->getUserState($from);
            if(isset($state['data']['question'])) {
                $this->hear->processEscalation($from, $employee, $state['data']['question']);
            }
            return;
        }

        if ($id === 'hear_escalate_no') {
            $this->hear->handleCancelEscalation($from);
            return;
        }

        switch ($id) {
            case 'main_menu':
                $this->sendBackMenu($from, 'main', $employee);
                break;
            case 'sub_menu_data_karyawan': $this->whatsapp->sendSubMenuDataKaryawan($from); break;
            case 'sub_menu_time_management': $this->whatsapp->sendSubMenuTimeManagement($from); break;
            case 'sub_menu_tim_saya': $this->whatsapp->sendSubMenuTimSaya($from); break;
            case 'personal_data':
                $this->whatsapp->sendMessage($from, $this->darwin->formatPersonalData($employee));
                $this->sendBackMenu($from, 'data_karyawan', $employee);
                break;
            case 'dependent_data':
                $deps = $this->darwin->getDependentData($employee['employee_id']);
                $this->whatsapp->sendMessage($from, $this->darwin->formatDependentData($employee, $deps));
                $this->sendBackMenu($from, 'data_karyawan', $employee);
                break;
            case 'medical_plafon':
                $med = $this->darwin->getMedicalData($employee['employee_id']);
                $this->whatsapp->sendMessage($from, $this->darwin->formatMedicalData($employee, $med));
                $this->sendBackMenu($from, 'data_karyawan', $employee);
                break;
            case 'leave_balance':
                $bal = $this->darwin->getLeaveBalance($employee['employee_id']);
                $this->whatsapp->sendMessage($from, $this->darwin->formatLeaveBalance($employee, $bal));
                $this->sendBackMenu($from, 'time_management', $employee);
                break;
            case 'work_schedule':
                $sched = $this->darwin->getWeeklySchedule($employee['employee_id']);
                $this->whatsapp->sendMessage($from, $this->darwin->formatWeeklySchedule($employee, $sched));
                $this->sendBackMenu($from, 'time_management', $employee);
                break;
            case 'late_history':
                $att = $this->darwin->getAttendanceData($employee['employee_id']);
                $this->whatsapp->sendMessage($from, $this->darwin->formatLateHistory($employee, $att));
                $this->sendBackMenu($from, 'time_management', $employee);
                break;
            case 'single_punch_check':
                $att = $this->darwin->getAttendanceData($employee['employee_id']);
                $this->whatsapp->sendMessage($from, $this->darwin->formatSinglePunch($employee, $att));
                $this->sendBackMenu($from, 'time_management', $employee);
                break;
            case 'overtime_data':
                $ot = $this->darwin->getOvertimeData($employee['employee_id']);
                $this->whatsapp->sendMessage($from, $this->darwin->formatOvertimeData($employee, $ot));
                $this->sendBackMenu($from, 'time_management', $employee);
                break;
            case 'my_team_list':
                $team = $this->darwin->getTeamData($employee);
                if(empty($team)) {
                    $this->whatsapp->sendMessage($from, "Tidak ada data tim.");
                } else {
                    $msg = "👥 *Tim Saya*\n\n";
                    foreach($team as $t) $msg .= "- *{$t['full_name']}* - {$t['designation_name']}\n";
                    $this->whatsapp->sendMessage($from, $msg);
                }
                $this->sendBackMenu($from, 'tim_saya', $employee);
                break;
            case 'team_presence':
                $this->whatsapp->sendMessage($from, $this->darwin->formatTeamPresence($employee));
                $this->sendBackMenu($from, 'tim_saya', $employee);
                break;
            case 'leave_approval':
                $reqs = $this->darwin->getLeaveRequests($employee['employee_id']);
                
                if(empty($reqs)) {
                    $this->whatsapp->sendMessage($from, "Saat ini kamu tidak memiliki pengajuan cuti yang sedang diproses 👍");
                    $this->sendBackMenu($from, 'tim_saya', $employee);
                } else {
                    $sentCount = 0;
                    foreach($reqs as $r) {
                        $staffData = $this->darwin->getEmployeeDataByIds([$r['employee_no']])[$r['employee_no']] ?? null;

                        $isDirectReport = false;
                        if ($staffData) {
                            $managerIdInStaff = $staffData['parent_employee_id'] ?? $staffData['direct_manager_employee_id'] ?? null;
                            if ($managerIdInStaff == $employee['employee_id']) {
                                $isDirectReport = true;
                            }
                        }
                        
                        if ($isDirectReport) {
                            $this->whatsapp->sendLeaveApprovalInteractiveFlow($from, $r);
                            $sentCount++;
                        }
                    }
            
                    if ($sentCount == 0) {
                        $this->whatsapp->sendMessage($from, "Kamu tidak memiliki antrean persetujuan untuk bawahan langsungmu saat ini.");
                        $this->sendBackMenu($from, 'tim_saya', $employee);
                    }
                }
                break;
            case 'manager_assistant':
                $this->whatsapp->sendMessage($from, "Selamat datang di *Nastari AI* 💡\n\n" .
                "Anda bisa bertanya langsung tentang informasi tim Anda, dan *Nastari* akan merespons dengan data akurat dan cepat.\n\n" .
                "Berikut beberapa hal yang dapat Anda tanyakan:\n" .
                "• *Informasi Karyawan*: Tanya tentang data karyawan tim Anda.\n" .
                "• *Laporan Tim*: Minta daftar anggota tim dari tim Anda.\n" .
                "• *Cuti*: Tanya tentang sisa cuti tim Anda.\n" .
                "• *Riwayat Kehadiran*: Tanya tentang riwayat kehadiran tim Anda.\n" .
                "• *Riwayat Terlambat*: Tanya tentang riwayat terlambat tim Anda.\n" .
                "• *Single Punch Absent*: Tanya tentang riwayat Single Punch tim Anda.\n" .
                "• *Overtime*: Tanya tentang riwayat Overtime tim Anda.\n\n" .
                
                "Mohon sertakan **Nama** atau **ID karyawan** dalam pertanyaan Anda. Jika ditemukan nama ganda, *Nastari* akan meminta Anda untuk mengonfirmasi ID karyawan yang dimaksud.\n\n" .
                "Contoh format pertanyaan: `data Metta`, `overtime Rose`,`tim Steffi`, `saldo cuti Alfian`, `absensi Janice`, `riwayat terlambat Vania`, atau `single punch 01124040023`.\n\n" .
                "Untuk mengakhiri sesi, ketik */selesai*.");
                $this->darwin->saveUserState($from, ['state' => 'in_manager_ai_session']);
                break;
            case 'ruang_placeholder':
                $this->whatsapp->sendRuangWelcomeMessage($from);
                $this->darwin->saveUserState($from, ['state' => 'in_ruang_session']);
                break;
            case 'faq':
                $this->hear->handleHearInitial($from);
                break;
            case 'generate_surat':
                $this->whatsapp->sendJenisSuratMenu($from);
                break;
            case 'surat_ket_kerja':
                $this->whatsapp->sendMessage($from, "Surat ini akan digunakan untuk keperluan apa ya? 😊\n\nContoh: KPR, Beasiswa");
                $this->darwin->saveUserState($from, ['state' => 'waiting_for_surat_kebutuhan']);
                break;
            case 'surat_visa_id':
                $this->whatsapp->sendMessage($from, "Surat visa ini akan digunakan untuk pengajuan ke negara mana? 🌍\n\nContoh: Jepang, Belanda");
                $this->darwin->saveUserState($from, ['state' => 'waiting_for_visa_destinasi_ID', 'data' => []]);
                break;
            case 'surat_visa_en':
                $this->whatsapp->sendMessage($from, "Which country will this visa letter be used for? 🌍\n\nExample: Japan, China");
                $this->darwin->saveUserState($from, ['state' => 'waiting_for_visa_destinasi_EN', 'data' => []]);
                break;
            case 'call_center_menu':
                $this->whatsapp->sendMessage($from, "Halo! Anda terhubung dengan *HC System Desk*. 🛠️\n\nSilakan jelaskan kendala pada HC System secara singkat.\n(Contoh: _Saya tidak bisa melakukan Clock In pagi ini_)");
                $this->darwin->saveUserState($from, ['state' => 'waiting_for_call_center_complaint', 'data' => []]);
                break;
            case 'late_records_team':
                $this->whatsapp->sendMessage($from, "Baik, sedang memeriksa catatan keterlambatan tim Anda untuk bulan ini... ⏳");
                $messages = $this->darwin->getTeamLateHistoryMessages($employee);
                foreach ($messages as $msg) {
                    $this->whatsapp->sendMessage($from, $msg);
                    sleep(1); 
                }
                $this->sendBackMenu($from, 'tim_saya', $employee);
                break;
            case 'single_punch_team': 
                $this->whatsapp->sendMessage($from, "Baik, sedang memeriksa data Single Punch tim Anda untuk bulan ini... ⏳");
                $messages = $this->darwin->getTeamSinglePunchMessages($employee);
                foreach ($messages as $msg) {
                    $this->whatsapp->sendMessage($from, $msg);
                    sleep(1); 
                }
                $this->sendBackMenu($from, 'tim_saya', $employee);
                break;
        }
    }

    protected function handleStatefulTextReply($from, $text, $state, $stateData, $employee, $messageId)
    {
        if ($state === 'waiting_for_call_center_complaint') {
            $this->darwin->saveUserState($from, [
                'state' => 'waiting_for_call_center_photo',
                'data' => ['complaint' => $text]
            ]);
            $this->whatsapp->sendMessage($from, "Baik, kendala dicatat. Mohon lampirkan *Foto/Screenshot* error atau kejadiannya agar kami bisa analisis lebih cepat.\n\n(Kirim gambar langsung dari galeri/kamera)");
            return;
        }
        if ($state === 'waiting_for_call_center_photo') {
            $this->whatsapp->sendMessage($from, "Mohon kirimkan *Gambar/Foto*, bukan teks. Atau ketik 'batal' untuk membatalkan.");
            return;
        }
        if ($state === 'in_manager_ai_session') {
            if (trim($text) === 'ok') {
                $this->whatsapp->sendMessage($from, "👍 Kamu sudah selesai pakai fitur Nastari AI. Selanjutnya, kamu akan diarahkan ke Menu Utama 😉");
                $this->darwin->saveUserState($from, ['state' => 'idle']);
                $this->sendBackMenu($from, 'main', $employee);
            } else {
                $this->managerAI->handleQuery($from, $text, $employee);
            }
            return;
        }
        if ($state === 'ai_session_clarification') {
            $this->managerAI->executeIntent($from, $stateData['data']['original_parsed_query'], $employee, ['employee_id' => $text, 'full_name' => 'Karyawan Pilihan']); 
            $this->darwin->saveUserState($from, ['state' => 'in_manager_ai_session']);
            return;
        }
        if ($state === 'in_ruang_session') {
            if (in_array(strtolower(trim($text)), ['selesai', '/selesai', 'cukup'])) {
                $this->whatsapp->sendMessage($from, "Terima kasih sudah bercerita di Ruang. Semoga perasaanmu lebih lega. Nastari selalu ada di sini untukmu. 👋❤️");
                $this->darwin->saveUserState($from, ['state' => 'idle']);
                $this->sendBackMenu($from, 'main', $employee);
            } else {
                $this->ruang->handleUserMessage($from, $text);
            }
            return;
        }
        if ($state === 'waiting_for_hear_question') {
            if (strtolower(trim($text)) === 'selesai') {
                $this->whatsapp->sendMessage($from, "Baik, sesi Pandu Nastari dicukupkan sampai di sini ya. Terima kasih sudah bertanya! 😊\n\nNastari selalu siap membantu kalau nanti ada yang ingin ditanyakan lagi. Have a great day! ✨");
                $this->darwin->saveUserState($from, ['state' => 'idle']);
                $this->sendBackMenu($from, 'main', $employee);
            } else {
                $this->hear->handleQuestion($from, $text, $employee, $messageId);
            }
            return;
        }
        if ($state === 'waiting_for_surat_kebutuhan') {
            $this->darwin->saveUserState($from, ['state' => 'waiting_for_surat_institusi', 'data' => ['kebutuhan' => $text]]);
            $this->whatsapp->sendMessage($from, "Surat ini ditujukan ke instansi mana ya? 😊\n\nContoh: Bank BCA, Kedutaan China");
            return;
        }
        if ($state === 'waiting_for_surat_institusi') {
            $kebutuhan = $stateData['data']['kebutuhan'] ?? '-';
            $institusi = $text;
            $this->whatsapp->sendMessage($from, "Sedang membuat surat... ⏳");
            $signatory = $this->darwin->getSignatoryForEmployee($employee);

            $this->deliverLetter(
                $from,
                $employee,
                fn () => $this->darwin->generateSuratKeteranganPdf($employee, $signatory, $kebutuhan, $institusi),
                "✅ *Surat Keterangan Kerja Berhasil Dibuat!*\n\nSilakan unduh dokumen di atas. Langkah selanjutnya:\n1. Cetak (print) dokumen tersebut.\n2. Bawa ke tim HCO Unit Bisnis Anda.\n3. Minta tanda tangan basah dan stempel resmi perusahaan.\n\nSemoga membantu! 😊",
                "Mohon maaf, *Nastari* gagal membuat suratnya saat ini. 🙏\n\nSilakan coba lagi beberapa saat, atau hubungi tim HCO Unit Bisnis kamu kalau suratnya dibutuhkan segera ya.",
                'Surat Keterangan Kerja'
            );

            return;
        }
        if (str_contains($state, 'waiting_for_visa_')) {
            $this->handleVisaTextFlow($from, $text, $state, $stateData, $employee);
            return;
        }
        if ($state === 'waiting_for_rejection_reason') {
            $leaveId = $stateData['data']['leave_id'];
            $empNo = $stateData['data']['employee_no'];
            $this->darwin->updateLeaveStatus($leaveId, $empNo, 'reject', $text);
            $this->whatsapp->sendMessage($from, "✅ Pengajuan berhasil ditolak dengan alasan: {$text}");
            $this->darwin->saveUserState($from, ['state' => 'idle']);
            $this->sendBackMenu($from, 'tim_saya', $employee);
            return;
        }
    }

    protected function handleVisaTextFlow($from, $text, $state, $stateData, $employee)
    {
        $lang = str_ends_with($state, '_EN') ? 'EN' : 'ID';
        $data = $stateData['data'] ?? [];

        // The last two visa steps are answered with buttons, not text. Typing
        // there used to fall through every branch below and then dereference an
        // undefined $next/$msg, throwing inside the letter flow.
        if (str_contains($state, '_kepentingan_')) {
            $this->whatsapp->sendMessage($from, $lang === 'EN'
                ? "Please choose one of the buttons above 🙂"
                : "Silakan pilih salah satu tombol di atas ya 🙂");
            $this->whatsapp->sendKepentinganVisaMenu($from, $lang);

            return;
        }

        if (str_contains($state, '_tanggungan_')) {
            $this->whatsapp->sendMessage($from, $lang === 'EN'
                ? "Please choose one of the buttons above 🙂"
                : "Silakan pilih salah satu tombol di atas ya 🙂");
            $this->whatsapp->sendTanggunganVisaMenu($from, $lang);

            return;
        }

        if (str_contains($state, '_destinasi_')) {
            $data['destinasi'] = $text;
            $next = 'waiting_for_visa_passport_' . $lang;
            $msg = ($lang == 'EN') ? "What is your passport number? 😊\n\n Example: IND-1234567" : "Nomor Paspor kamu berapa ya? 😊\n\nContoh: IND-1234567";
        } elseif (str_contains($state, '_passport_')) {
            $data['no_passport'] = $text;
            $next = 'waiting_for_visa_dari_tanggal_' . $lang;
            $msg = ($lang == 'EN') ? "When will your trip start? 😊\n\nExample: 01-03-2026" : "Tanggal mulai perjalanan kamu kapan? 😊\n\nContoh: 01-03-2026";
        } elseif (str_contains($state, '_dari_tanggal_')) {
            $data['dari_tanggal'] = $text;
            $next = 'waiting_for_visa_sampai_tanggal_' . $lang;
            $msg = ($lang == 'EN') ? "When will your trip end?\n\nExample: 10-03-2026" : "Tanggal akhir perjalanan kamu kapan ya?\n\nContoh: 10-03-2026";
        } elseif (str_contains($state, '_sampai_tanggal_')) {
            $data['sampai_tanggal'] = $text;
            $this->darwin->saveUserState($from, ['state' => 'waiting_for_visa_kepentingan_' . $lang, 'data' => $data]);
            $this->whatsapp->sendKepentinganVisaMenu($from, $lang);
            return;
        } else {
            // An unrecognised waiting_for_visa_* state. Restarting is the only
            // safe move: falling through here left $next and $msg undefined.
            $this->darwin->saveUserState($from, ['state' => 'idle']);
            $this->whatsapp->sendMessage($from, $lang === 'EN'
                ? "Sorry, that visa request session has expired. Please start again from the menu. 🙏"
                : "Maaf, sesi pengajuan surat visanya sudah berakhir. Silakan mulai lagi dari menu ya 🙏");
            $this->sendBackMenu($from, 'main', $employee);

            return;
        }

        $this->darwin->saveUserState($from, ['state' => $next, 'data' => $data]);
        $this->whatsapp->sendMessage($from, $msg);
    }

    // protected function handleVisaButtons($from, $id, $employee)
    // {
    //     $state = $this->darwin->getUserState($from);
    //     $lang = str_ends_with($state['state'], '_EN') ? 'EN' : 'ID';
    //     $data = $state['data'];
    //     if (str_contains($id, 'kepentingan')) {
    //         $data['jenis_kepentingan'] = (str_contains($id, 'business') || str_contains($id, 'bisnis')) ? 'Business' : 'Personal';
    //         $this->darwin->saveUserState($from, ['state' => 'waiting_for_visa_tanggungan_' . $lang, 'data' => $data]);
    //         $this->whatsapp->sendTanggunganVisaMenu($from, $lang);
    //     } elseif (str_contains($id, 'tanggungan')) {
    //         $data['tanggungan'] = (str_contains($id, 'company') || str_contains($id, 'perusahaan')) ? 'Company' : 'Self';
    //         $this->whatsapp->sendMessage($from, "Generating Visa Letter...");
    //         $signatory = $this->darwin->getSignatoryForEmployee($employee);
    //         $pdf = $this->darwin->generateSuratPembuatanVisaPdf($employee, $signatory, $data);
    //         if ($pdf) {
    //             $mediaId = $this->whatsapp->uploadMediaToWhatsApp($pdf['path']);
    //             $this->whatsapp->sendDocumentMessageById($from, $mediaId, $pdf['filename'], "Visa Letter");
    //         }
    //         $this->darwin->saveUserState($from, ['state' => 'idle']);
    //         $this->sendBackMenu($from, 'main', $employee);
    //     }
    // }
    
    protected function handleVisaButtons($from, $id, $employee)
    {
        $state = $this->darwin->getUserState($from);
        $lang = str_ends_with($state['state'], '_EN') ? 'EN' : 'ID';
        $data = $state['data'];

        if (str_contains($id, 'kepentingan')) {
            $data['jenis_kepentingan'] = (str_contains($id, 'business') || str_contains($id, 'bisnis')) ? 'Business' : 'Personal';
            $this->darwin->saveUserState($from, ['state' => 'waiting_for_visa_tanggungan_' . $lang, 'data' => $data]);
            $this->whatsapp->sendTanggunganVisaMenu($from, $lang);
        } elseif (str_contains($id, 'tanggungan')) {
            $data['tanggungan'] = (str_contains($id, 'company') || str_contains($id, 'perusahaan')) ? 'Company' : 'Self';

            $isEn = ($lang === 'EN');

            $this->whatsapp->sendMessage($from, $isEn ? "Generating Visa Letter... ⏳" : "Sedang membuat surat... ⏳");

            $signatory = $this->darwin->getSignatoryForEmployee($employee);

            $caption = $isEn
                ? "✅ *Visa Application Letter Successfully Generated!*\n\nPlease download the document above. Next steps:\n1. Print the document.\n2. Bring it to your Business Unit's HCO team.\n3. Request a wet signature and official company stamp.\n\nHope this helps! 😊"
                : "✅ *Surat Pengajuan Visa Berhasil Dibuat!*\n\nSilakan unduh dokumen di atas. Langkah selanjutnya:\n1. Cetak (print) dokumen tersebut.\n2. Bawa ke tim HCO Unit Bisnis Anda.\n3. Minta tanda tangan basah dan stempel resmi perusahaan.\n\nSemoga membantu! 😊";

            $failure = $isEn
                ? "Sorry, *Nastari* could not generate your visa letter right now. 🙏\n\nPlease try again shortly, or contact your Business Unit's HCO team if you need it urgently."
                : "Mohon maaf, *Nastari* gagal membuat surat visanya saat ini. 🙏\n\nSilakan coba lagi beberapa saat, atau hubungi tim HCO Unit Bisnis kamu kalau suratnya dibutuhkan segera ya.";

            $this->deliverLetter(
                $from,
                $employee,
                fn () => $isEn
                    ? $this->darwin->generateSuratPembuatanVisaPdf_EN($employee, $signatory, $data)
                    : $this->darwin->generateSuratPembuatanVisaPdf($employee, $signatory, $data),
                $caption,
                $failure,
                $isEn ? 'Visa Application Letter' : 'Surat Pengajuan Visa'
            );
        }
    }
    
    protected function handleText($from, $text, $employee)
    {
        $this->activity->record('employee.message', [
            'message_type' => 'text',
            'outcome'      => 'menu_shown',
        ]);

        $this->sendBackMenu($from, 'main', $employee);
    }

    /**
     * Attributes one interactive tap to a feature.
     *
     * The feature slug comes from NastariFeatures rather than from the button
     * text, which is what the log parser had to use. Menu labels are numbered
     * ("3. Generate Surat") and the numbering shifts whenever a row is added,
     * so text-derived keys split one feature into several over time.
     */
    private function recordInteractive(?string $payloadId, string $type): void
    {
        if ($payloadId === null) {
            return;
        }

        $feature = NastariFeatures::find($payloadId);
        $messageType = $type === 'nfm_reply' ? 'form' : 'interactive';

        if ($feature === null) {
            // Prefixed, per-record payloads: act_approve_<leave>_<emp>, visa_*,
            // hear_escalate_*. Grouped by prefix so the ids themselves, which
            // carry employee numbers, never become feature keys.
            $prefixes = [
                'act_approve_'   => ['leave_approval', 'Persetujuan Cuti — setuju'],
                'act_reject_'    => ['leave_approval', 'Persetujuan Cuti — tolak'],
                'visa_'          => ['surat_visa', 'Surat Visa — pilihan'],
                'hear_escalate_' => ['pandu', 'Pandu — eskalasi tiket'],
                'btn_'           => ['session_end', 'Selesai'],
            ];

            foreach ($prefixes as $prefix => [$key, $label]) {
                if (str_starts_with($payloadId, $prefix)) {
                    $this->activity->record('employee.access.' . $key, [
                        'feature_key'   => $key,
                        'feature_label' => $label,
                        'message_type'  => $messageType,
                        'outcome'       => 'success',
                    ]);

                    return;
                }
            }

            return;
        }

        $actionKey = NastariFeatures::isNavigation($payloadId)
            ? 'employee.menu.' . $feature['key']
            : 'employee.access.' . $feature['key'];

        $this->activity->record($actionKey, [
            'feature_key'   => $feature['key'],
            'feature_label' => $feature['label'],
            'message_type'  => $messageType,
            'outcome'       => 'success',
        ]);
    }

    /**
     * Generates a letter, uploads it and replies — without ever stranding the
     * conversation.
     *
     * The state is cleared *before* generation starts, on purpose. FPDF used to
     * exhaust the memory limit while parsing an oversized letterhead, and a PHP
     * fatal error skips both catch and finally blocks. The old code reset the
     * state after generating, so a crash left the user pinned in the letter
     * state for the full 24h cache TTL: every message they sent re-entered the
     * same flow and crashed again. Clearing first makes the worst case a
     * missing letter instead of a dead conversation.
     */
    private function deliverLetter($from, $employee, callable $generator, $caption, $failureMessage, $letterType)
    {
        $this->darwin->saveUserState($from, ['state' => 'idle']);

        $pdf = null;
        $startedAt = microtime(true);
        $error = null;

        try {
            $pdf = $generator();
        } catch (\Throwable $e) {
            $error = $e->getMessage();

            Log::error('LETTER_GENERATE_FAILED', [
                'letter_type' => $letterType,
                'employee_id' => $employee['employee_id'] ?? null,
                'company'     => $employee['contribution_level'] ?? null,
                'error'       => $error,
            ]);
        }

        $generateMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (empty($pdf['path'])) {
            $this->activity->record('employee.letter.failed', [
                'feature_key'   => 'letter_generate',
                'feature_label' => $letterType,
                'outcome'       => 'error',
                'error_type'    => 'generate_failed',
                'duration_ms'   => $generateMs,
                'payload'       => [
                    'letter_type' => $letterType,
                    'company'     => $employee['contribution_level'] ?? null,
                    'error'       => $error,
                ],
            ]);

            $this->whatsapp->sendMessage($from, $failureMessage);
            $this->sendBackMenu($from, 'main', $employee);

            return;
        }

        try {
            // Raw cURL, so the global HTTP instrumentation does not see it.
            $uploadStarted = microtime(true);
            $mediaId = $this->whatsapp->uploadMediaToWhatsApp($pdf['path']);
            $uploadMs = (int) round((microtime(true) - $uploadStarted) * 1000);

            if (empty($mediaId)) {
                throw new \RuntimeException('WhatsApp media upload returned no media id');
            }

            $this->whatsapp->sendDocumentMessageById($from, $mediaId, $pdf['filename'], $caption);

            $this->activity->record('employee.letter.generated', [
                'feature_key'   => 'letter_generate',
                'feature_label' => $letterType,
                'outcome'       => 'success',
                'duration_ms'   => $generateMs + $uploadMs,
                'payload'       => [
                    'letter_type' => $letterType,
                    'generate_ms' => $generateMs,
                    'upload_ms'   => $uploadMs,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('LETTER_DELIVERY_FAILED', [
                'letter_type' => $letterType,
                'employee_id' => $employee['employee_id'] ?? null,
                'nomor_surat' => $pdf['nomorSurat'] ?? null,
                'error'       => $e->getMessage(),
            ]);

            $this->activity->externalFailure('whatsapp', 'POST /media', 'http_error', [
                'feature_key'   => 'letter_generate',
                'feature_label' => $letterType,
                'duration_ms'   => $generateMs,
                'payload'       => ['stage' => 'upload_or_send', 'error' => $e->getMessage()],
            ]);

            $this->whatsapp->sendMessage($from, $failureMessage);
        }

        $this->sendBackMenu($from, 'main', $employee);
    }

    private function sendBackMenu($from, $type, $employee)
    {
        sleep(1);

        // An inactive employee has no sub-menu they are allowed to act on, so
        // every "back" lands on the (already restricted) main menu.
        if ($this->policy->isInactive($employee)) {
            $type = 'main';
        }

        switch ($type) {
            case 'data_karyawan':
                $this->whatsapp->sendSubMenuDataKaryawan($from);
                break;
            case 'time_management':
                $this->whatsapp->sendSubMenuTimeManagement($from);
                break;
            case 'tim_saya':
                $this->whatsapp->sendSubMenuTimSaya($from);
                break;
            default:
                // No attendance lookup for an inactive employee: there is no
                // attendance to report, and it is a wasted Darwinbox round trip
                // inside the webhook request.
                $clockIn = $this->policy->isInactive($employee)
                    ? null
                    : $this->darwin->getTodayClockIn($employee['employee_id']);

                $this->whatsapp->sendMainMenu($from, $employee, $clockIn);
                break;
        }
    }
}