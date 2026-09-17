<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use App\Models\KbDocument;
use App\Models\Hear;
use App\Models\Employee;
use App\Models\Role;
use Carbon\Carbon;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Illuminate\Support\Str;

class HearController extends Controller
{
    private $kbBasePath;

    public function __construct()
    {
        $this->kbBasePath = storage_path('app/pdf-file/');
        if (!File::exists($this->kbBasePath)) {
            File::makeDirectory($this->kbBasePath, 0755, true);
        }
    }

    private function getBusinessUnitFromRole()
    {
        $user = Auth::user();
        $roleIds = DB::connection('mysql')->table('role_user')
            ->where('user_id', $user->employee_id)
            ->pluck('role_id');
        
        $role = Role::whereIn('id', $roleIds)->first();
        
        if (!$role || empty($role->scope_bu)) {
            return 'KPN Corporation';
        }

        $bus = $role->scope_bu;
        if (is_string($bus)) {
            $decoded = json_decode($bus, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $bus = $decoded;
            } elseif (str_contains($bus, ',')) {
                $bus = explode(',', $bus);
            } else {
                $bus = [$bus];
            }
        }

        $val = is_array($bus) ? $bus[0] : $bus;
        return trim($val, '[]" ');
    }

    private function getBuFolderPath($buName)
    {
        $folderName = str_replace(' ', '_', $buName);
        $fullPath = $this->kbBasePath . $folderName;
        if (!File::exists($fullPath)) {
            File::makeDirectory($fullPath, 0775, true, true);
        }
        return $fullPath . '/';
    }

    public function knowledgeBase() 
    { 
        $user = Auth::user(); 
        $userBu = $this->getBusinessUnitFromRole();
        
        $roleIds = DB::connection('mysql')->table('role_user')->where('user_id', $user->employee_id)->pluck('role_id');
        $isSuperAdmin = Role::whereIn('id', $roleIds)->where('name', 'Super Admin')->exists();

        $files = KbDocument::scoped()->orderBy('created_at', 'desc')->get(); 
        
        return view('admin.hear.knowledge_base', compact('files', 'userBu', 'isSuperAdmin')); 
    }

    public function uploadKb(Request $request)
    {
        $request->validate([
            'document' => 'required|file|mimes:pdf,txt,docx|max:20480',
            'scope' => 'required|string'
        ]);
        
        $targetBu = $request->input('scope');
        $targetFolder = $this->getBuFolderPath($targetBu);
        $file = $request->file('document');
        $fileName = preg_replace('/[^a-zA-Z0-9.\-\_ ]/', '', $file->getClientOriginalName());
        
        $file->move($targetFolder, $fileName);
        $fullFilePath = $targetFolder . $fileName;
        $relativePath = str_replace(' ', '_', $targetBu) . '/' . $fileName;

        KbDocument::updateOrCreate(
            ['path' => $relativePath],
            [
                'filename' => $fileName,
                'business_unit' => $targetBu,
                'file_size' => round(filesize($fullFilePath) / 1024, 2),
                'status' => 'processing',
                'upload_by' => Auth::user()->name ?? 'System'
            ]
        );
        
        return back()->with('success', "File uploaded for {$targetBu} successfully.");
    }

    public function addToKb(Request $request)
    {
        $request->validate([
            'topic' => 'required|string',
            'content' => 'required|string'
        ]);

        try {
            $user = Auth::user();
            $topic = $request->input('topic');
            $content = $request->input('content');
            
            $targetBu = $this->getBusinessUnitFromRole(); 
            $targetFolder = $this->getBuFolderPath($targetBu);
            
            $cleanTopic = preg_replace('/[^a-zA-Z0-9.\-\_]/', '', str_replace(' ', '_', $topic));
            $fileName = "KB_Auto_" . $cleanTopic . "_" . time() . ".txt";
            $fullFilePath = $targetFolder . $fileName;
            
            file_put_contents($fullFilePath, $content);

            $relativePath = str_replace(' ', '_', $targetBu) . '/' . $fileName;
            
            KbDocument::create([
                'path' => $relativePath,
                'filename' => $fileName,
                'business_unit' => $targetBu,
                'file_size' => round(filesize($fullFilePath) / 1024, 2),
                'status' => 'processing',
                'upload_by' => $user->name ?? 'System'
            ]);

            return response()->json(['status' => 'success', 'message' => 'Added to KB storage successfully. Waiting for AI watcher.']);

        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function getFileStatuses() 
    { 
        return response()->json(KbDocument::scoped()->select('id', 'status')->get()); 
    }

    public function index()
    {
        $allTickets = Hear::scoped()->orderBy('date', 'desc')->get();
        
        $escalationTickets = $allTickets->where('status', '!=', 'Done')->take(10)->map(function($ticket) {
            $ticketDate = \Carbon\Carbon::parse($ticket->date);
            $diffInDays = floor($ticketDate->diffInDays(now()));
            
            return [
                'id' => $ticket->ticket_code,
                'token' => $ticket->token,
                'date' => $ticketDate->format('d M Y, H:i'),
                'age' => $diffInDays . ' Days',
                'age_int' => (int)$diffInDays,
                'nama_penanya' => $ticket->name,
                'question' => \Illuminate\Support\Str::limit($ticket->question, 50),
                'pic' => $ticket->pic ?? 'Unassigned',
            ];
        })->all();

        $potentialTopics = [];
        $allTopics = [];
        $questionAnswerMap = [];

        foreach ($allTickets as $ticket) {
            $q = $ticket->question;
            $keywords = $this->extractKeywords($q);
            $topic = !empty($keywords) ? implode(' ', array_slice($keywords, 0, 3)) : 'General Inquiry';
            $topic = ucwords($topic);

            if (!isset($allTopics[$topic])) $allTopics[$topic] = [];
            $allTopics[$topic][] = $q;

            if ($ticket->status === 'Done') {
                if (!isset($potentialTopics[$topic])) $potentialTopics[$topic] = [];
                $potentialTopics[$topic][] = $q;
                $questionAnswerMap[$q] = $ticket->answer;
            }
        }

        uasort($potentialTopics, function ($a, $b) { return count($b) <=> count($a); });
        $top5PotentialTopics = array_slice($potentialTopics, 0, 5, true);

        uasort($allTopics, function ($a, $b) { return count($b) <=> count($a); });
        $top5AllTopics = array_slice($allTopics, 0, 5, true);

        return view('admin.hear.dashboard', [
            'escalationTickets' => $escalationTickets,
            'totalTickets' => $allTickets->count(),
            'doneTicketsCount' => $allTickets->where('status', 'Done')->count(),
            'top5PotentialTopics' => $top5PotentialTopics,
            'top5AllTopics' => $top5AllTopics,
            'questionAnswerMap' => $questionAnswerMap
        ]);
    }

    private function extractKeywords($text)
    {
        $text = strtolower($text);
        $text = preg_replace('/[^\w\s]/', '', $text);
        $stopWords = ['saya', 'mau', 'tanya', 'mohon', 'info', 'tentang', 'bagaimana', 'cara', 'kenapa', 'kapan', 'dimana', 'apakah', 'yang', 'dan', 'di', 'ke', 'dari', 'ini', 'itu', 'untuk', 'pada', 'adalah', 'bisa', 'tolong', 'terima', 'kasih', 'pagi', 'siang', 'sore', 'malam', 'halo', 'hi', 'min', 'admin'];
        
        $words = explode(' ', $text);
        $keywords = array_filter($words, function($w) use ($stopWords) {
            return !in_array($w, $stopWords) && strlen($w) > 3;
        });
        
        return array_values($keywords);
    }

    public function history() 
    { 
        $tickets = Hear::scoped()->orderBy('date', 'desc')->get(); 
        return view('admin.hear.history', compact('tickets')); 
    }

    public function deleteKb(Request $request) 
    { 
        $relativePath = $request->input('filename'); 
        $fullPath = $this->kbBasePath . $relativePath; 
        if (File::exists($fullPath)) File::delete($fullPath); 
        KbDocument::where('path', $relativePath)->delete(); 
        return back()->with('success', "File deleted."); 
    }

    public function showTicket($id) 
    { 
        $ticket = Hear::scoped()->where('ticket_code', $id)->first(); 
        if (!$ticket) return redirect()->route('hear.history')->with('error', 'Unauthorized.'); 
        return view('admin.hear.ticket_detail', compact('ticket')); 
    }

    public function solveTicket(Request $request, $id) 
    { 
        $request->validate(['jawaban' => 'required|string|min:5']); 
        $ticket = Hear::scoped()->where('ticket_code', $id)->first(); 
        if (!$ticket) return back()->with('error', 'Unauthorized.'); 
        $ticket->update(['answer' => trim($request->input('jawaban')), 'status' => 'Done', 'pic' => Auth::user()->name ?? 'Admin']); 
        $this->sendReplyEmail($ticket); 
        return redirect()->route('hear.history')->with('success', 'Solved.'); 
    }

    private function sendReplyEmail($ticket)
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
            if (!empty($ticket->email)) {
                $mail->addAddress($ticket->email, $ticket->name);
                $mail->isHTML(true);
                $mail->Subject = 'Jawaban Tiket Pandu [ID: #' . $ticket->ticket_code . ']';
                $mail->Body = "Halo {$ticket->name},<br><br>Pertanyaan Anda terjawab:<br><br><b>A:</b><br>" . nl2br(htmlspecialchars($ticket->answer));
                $mail->send();
            }
        } catch (Exception $e) {}
    }
}