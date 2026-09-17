@extends('layouts.admin')

@section('content')
<div class="d-flex">
    <div class="main-content flex-grow-1 bg-light">
        <main class="container-fluid p-4">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="{{ route('hear.history') }}" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Back</a>
                        <h3 class="fw-bold mb-0 text-dark">Ticket Details #{{ $ticket['id'] }}</h3>
                    </div>
                </div>
                <div>
                    @if($ticket['status'] === 'Done')
                        <span class="badge bg-success fs-6 px-3 py-2"><i class="bi bi-check-circle-fill me-2"></i> Solved</span>
                    @else
                        <span class="badge bg-warning text-dark fs-6 px-3 py-2"><i class="bi bi-hourglass-split me-2"></i> Processing / Escalated</span>
                    @endif
                </div>
            </div>

            <div class="row g-4">
                {{-- KIRI: INFO TIKET --}}
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-person-lines-fill me-2"></i> Requester Info</h6>
                        </div>
                        <div class="card-body">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item px-0 pt-0">
                                    <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Name</small>
                                    <div class="fw-medium text-dark">{{ $ticket['nama_penanya'] }}</div>
                                </li>
                                <li class="list-group-item px-0">
                                    <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Email</small>
                                    <div class="fw-medium text-dark">{{ $ticket['email_penanya'] }}</div>
                                </li>
                                <li class="list-group-item px-0">
                                    <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Phone</small>
                                    <div class="fw-medium text-dark">{{ $ticket['nomor_telepon'] }}</div>
                                </li>
                                <li class="list-group-item px-0 pb-0">
                                    <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">Date Created</small>
                                    <div class="fw-medium text-dark">{{ $ticket['tanggal'] }}</div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-chat-text-fill me-2"></i> Issue & Resolution</h6>
                        </div>
                        <div class="card-body p-4">
                            
                            <div class="mb-4">
                                <label class="form-label text-muted text-uppercase fw-bold small">User Question</label>
                                <div class="bg-light p-3 rounded-3 border border-light-subtle">
                                    <p class="mb-0 text-dark" style="font-size: 1.05rem;">"{{ $ticket['pertanyaan'] }}"</p>
                                </div>
                            </div>

                            <hr class="my-4 border-light-subtle">

                            @if($ticket['status'] === 'Done')
                                {{-- TAMPILAN READ ONLY (SUDAH DIJAWAB) --}}
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="form-label text-success text-uppercase fw-bold small mb-0">Solution Provided</label>
                                        <small class="text-muted">Solved on: {{ $ticket['tanggal_done'] ?? '-' }} by {{ $ticket['pic'] ?? 'System' }}</small>
                                    </div>
                                    <div class="bg-success-subtle p-3 rounded-3 border border-success-subtle text-dark">
                                        {!! nl2br(htmlspecialchars($ticket['jawaban'])) !!}
                                    </div>
                                </div>
                                <div class="alert alert-info border-0 d-flex align-items-center small" role="alert">
                                    <i class="bi bi-info-circle-fill me-2 fs-5"></i>
                                    <div>This ticket is closed. An email notification has been sent to the user.</div>
                                </div>
                            @else
                                <form action="{{ route('hear.ticket.solve', $ticket['id']) }}" method="POST" onsubmit="return confirm('Are you sure you want to send this answer? This action cannot be undone.');">
                                    @csrf
                                    <div class="mb-3">
                                        <label for="jawaban" class="form-label text-primary text-uppercase fw-bold small">Your Answer / Solution</label>
                                        <textarea class="form-control" id="jawaban" name="jawaban" rows="6" placeholder="Type your professional response here..." required></textarea>
                                        <div class="form-text">This answer will be sent directly to the user via Email & WhatsApp (if applicable).</div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-end gap-2">
                                        <button type="submit" class="btn btn-primary px-4 py-2 fw-medium">
                                            <i class="bi bi-send-fill me-2"></i> Submit & Solve Ticket
                                        </button>
                                    </div>
                                </form>
                            @endif

                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>
@endsection