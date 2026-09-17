@extends('layouts.admin')

@section('header-action')
<button class="btn btn-white border shadow-sm text-secondary fw-bold px-3" onclick="window.location.reload()">
    <i class="bi bi-arrow-clockwise me-1"></i> Refresh
</button>
@endsection

@section('content')
<div class="container-fluid p-0">
    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm mb-3">
            <i class="bi bi-check-circle-fill me-2"></i> {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger border-0 shadow-sm mb-3">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> {{ session('error') }}
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table small table-hover align-middle w-100" id="historyTable">
                    <thead class="bg-light align-middle">
                        <tr>
                            <th class="ps-3 py-3">TICKET ID</th>
                            <th class="py-3">EMPLOYEE</th>
                            <th class="py-3">DATE CREATED</th>
                            <th class="py-3">PIC</th>
                            <th class="py-3">RESPONSE TIME</th>
                            <th class="py-3 text-center">STATUS</th>
                            <th class="py-3 text-end pe-3">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tickets as $ticket)
                            @php
                                $responseTime = '-';
                                if ($ticket->status === 'Done' && $ticket->updated_at) {
                                    $created = \Carbon\Carbon::parse($ticket->date);
                                    $solved = \Carbon\Carbon::parse($ticket->updated_at);
                                    $diff = $created->diff($solved);
                                    $responseTime = $diff->d . 'd ' . $diff->h . 'h ' . $diff->i . 'm';
                                }

                                $statusBadge = match(strtolower($ticket->status)) {
                                    'done' => 'bg-success-subtle text-success border border-success-subtle',
                                    'processing' => 'bg-warning-subtle text-warning border border-warning-subtle',
                                    default => 'bg-secondary-subtle text-secondary border border-secondary-subtle'
                                };
                                
                                $ticketData = json_encode([
                                    'id' => $ticket->ticket_code,
                                    'name' => $ticket->name,
                                    'email' => $ticket->email,
                                    'phone' => $ticket->phone,
                                    'date' => \Carbon\Carbon::parse($ticket->date)->format('d M Y, H:i'),
                                    'pic' => $ticket->pic,
                                    'solved_at' => $ticket->status === 'Done' ? \Carbon\Carbon::parse($ticket->updated_at)->format('d M Y, H:i') : '-',
                                    'status' => $ticket->status,
                                    'question' => $ticket->question,
                                    'answer' => $ticket->answer,
                                    'response_time' => $responseTime,
                                    'solve_url' => route('hear.ticket.solve', $ticket->ticket_code)
                                ]);
                            @endphp
                            <tr>
                                <td class="ps-3 fw-bold text-primary">#{{ $ticket->ticket_code }}</td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <span class="fw-bold text-dark">{{ $ticket->name }}</span>
                                        <small class="text-muted">{{ $ticket->email }}</small>
                                    </div>
                                </td>
                                <td class="text-muted">{{ \Carbon\Carbon::parse($ticket->date)->format('d M Y, H:i') }}</td>
                                <td>{{ $ticket->pic ?? '-' }}</td>
                                <td>{{ $responseTime }}</td>
                                <td class="text-center">
                                    <span class="badge {{ $statusBadge }} rounded-pill px-3 py-2">
                                        {{ $ticket->status }}
                                    </span>
                                </td>
                                <td class="text-end pe-3">
                                    <button class="btn btn-sm btn-light text-primary border shadow-sm rounded-circle" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#ticketDetailModal" 
                                            data-ticket="{{ $ticketData }}"
                                            style="width: 32px; height: 32px;">
                                        <i class="bi bi-eye-fill"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="ticketDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">Ticket Details #<span id="modalTicketId"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <small class="text-muted d-block">Employee Name</small>
                        <span id="modalNama" class="fw-bold text-dark"></span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Date Created</small>
                        <span id="modalTanggal" class="fw-bold text-dark"></span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Email</small>
                        <span id="modalEmail" class="text-dark"></span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Phone Number</small>
                        <span id="modalTelepon" class="text-dark"></span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">PIC</small>
                        <span id="modalPic" class="text-dark"></span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Response Time</small>
                        <span id="modalResponseTime" class="fw-bold text-dark"></span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Status</small>
                        <span id="modalStatus" class="badge"></span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Date Solved</small>
                        <span id="modalTanggalDone" class="text-dark"></span>
                    </div>
                </div>

                <div class="bg-light p-3 rounded-3 mb-3 border">
                    <h6 class="fw-bold text-primary mb-2"><i class="bi bi-question-circle-fill me-2"></i>Question</h6>
                    <p id="modalPertanyaan" class="mb-0 text-dark"></p>
                </div>

                <div id="viewAnswerSection" class="d-none">
                    <div class="bg-success-subtle p-3 rounded-3 border border-success-subtle">
                        <h6 class="fw-bold text-success mb-2"><i class="bi bi-check-circle-fill me-2"></i>Solution Provided</h6>
                        <p id="modalJawaban" class="mb-0 text-dark"></p>
                    </div>
                </div>

                <div id="replyFormSection" class="d-none">
                    <form id="solveTicketForm" method="POST">
                        @csrf
                        <div class="mb-3">
                            <label for="jawaban" class="form-label text-primary fw-bold"><i class="bi bi-pencil-fill me-2"></i>Write Response & Solve</label>
                            <textarea class="form-control" id="formJawaban" name="jawaban" rows="5" placeholder="Type your answer here..." required></textarea>
                            <div class="form-text">Submitting this form will mark the ticket as Done and notify the user.</div>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary fw-bold">
                                <i class="bi bi-send-fill me-2"></i> Submit & Solve Ticket
                            </button>
                        </div>
                    </form>
                </div>

            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light border fw-bold px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function() {
        $('#historyTable').DataTable({});

        const ticketDetailModal = document.getElementById('ticketDetailModal');
        ticketDetailModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const ticket = JSON.parse(button.getAttribute('data-ticket'));

            document.getElementById('modalTicketId').textContent = ticket.id;
            document.getElementById('modalNama').textContent = ticket.name;
            document.getElementById('modalEmail').textContent = ticket.email;
            document.getElementById('modalTelepon').textContent = ticket.phone;
            document.getElementById('modalTanggal').textContent = ticket.date;
            document.getElementById('modalPic').textContent = ticket.pic;
            document.getElementById('modalTanggalDone').textContent = ticket.solved_at;
            document.getElementById('modalResponseTime').textContent = ticket.response_time;
            document.getElementById('modalPertanyaan').textContent = ticket.question;
            
            const viewSection = document.getElementById('viewAnswerSection');
            const formSection = document.getElementById('replyFormSection');
            const jawabanEl = document.getElementById('modalJawaban');
            const modalStatus = document.getElementById('modalStatus');
            const solveForm = document.getElementById('solveTicketForm');

            modalStatus.textContent = ticket.status;
            let statusClass = 'bg-secondary-subtle text-secondary border-secondary-subtle';

            if (ticket.status.toLowerCase() === 'done') {
                statusClass = 'bg-success-subtle text-success border-success-subtle';
                
                viewSection.classList.remove('d-none');
                formSection.classList.add('d-none');
                
                if (ticket.answer) {
                    jawabanEl.innerHTML = ticket.answer.replace(/\n/g, '<br>');
                } else {
                    jawabanEl.innerHTML = '<em class="text-muted">No answer recorded.</em>';
                }

            } else {
                statusClass = 'bg-warning-subtle text-warning border-warning-subtle';
                
                viewSection.classList.add('d-none');
                formSection.classList.remove('d-none');
                
                document.getElementById('formJawaban').value = ''; 
                solveForm.action = ticket.solve_url;
            }

            modalStatus.className = 'badge rounded-pill px-3 py-2 border ' + statusClass;
        });
    });
</script>
@endpush