<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Hear;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class TicketController extends Controller
{
    public function show(Request $request, $token)
    {
        if (empty($token)) {
            return view('errors.custom', ['title' => 'Access Denied', 'message' => 'Token missing.']);
        }

        $ticket = Hear::where('token', $token)->first();

        if (!$ticket) {
            return view('errors.custom', ['title' => 'Access Denied', 'message' => 'Invalid token.']);
        }

        if ($ticket->status === 'Done') {
            return view('admin.hear.ticket_response', [
                'foundTicket' => [
                    'id' => $ticket->ticket_code,
                    'pic' => $ticket->pic,
                    'nama_penanya' => $ticket->name,
                    'email_penanya' => $ticket->email,
                    'pertanyaan' => $ticket->question
                ],
                'token' => $token,
                'already_solved' => true 
            ]);
        }

        $foundTicket = [
            'id' => $ticket->ticket_code,
            'pic' => $ticket->pic,
            'nama_penanya' => $ticket->name,
            'email_penanya' => $ticket->email,
            'pertanyaan' => $ticket->question
        ];

        return view('admin.hear.ticket_response', compact('foundTicket', 'token'));
    }

    public function update(Request $request, $token)
    {
        $request->validate(['jawaban' => 'required|string|min:5']);

        $ticket = Hear::where('token', $token)->first();

        if (!$ticket) {
            return view('errors.custom', ['title' => 'Error', 'message' => 'Ticket not found.']);
        }

        $ticket->update([
            'answer' => trim($request->input('jawaban')),
            'status' => 'Done',
            'updated_at' => now()
        ]);

        $this->sendAnswerEmail($ticket);

        return redirect()->route('hear.ticket.show', ['token' => $token])->with('success', 'Thank you! Your response has been submitted successfully.');
    }

    private function sendAnswerEmail($ticket)
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
                $mail->Subject = 'Pandu Ticket Resolution [ID: #' . $ticket->ticket_code . ']';
                $mail->Body = "Hello {$ticket->name},<br><br>Your inquiry has been resolved:<br><br><i>{$ticket->question}</i><br><br><b>Resolution:</b><br>" . nl2br(htmlspecialchars($ticket->answer));
                $mail->send();
            }
        } catch (Exception $e) {}
    }
}