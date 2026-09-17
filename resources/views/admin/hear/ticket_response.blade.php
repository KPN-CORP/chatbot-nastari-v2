<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PANDU - Ticket #{{ $foundTicket['id'] }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --kpn-red: #AB2F2B; }
        body { background-color: #f4f4f4; font-family: 'Poppins', sans-serif; font-size: 0.9rem; }
        .card { border-radius: 8px; border: none; }
        .header-slim { background: var(--kpn-red); padding: 15px; border-radius: 8px 8px 0 0; }
        .btn-kpn { background: var(--kpn-red); color: white; border: none; padding: 10px; font-weight: 600; border-radius: 6px; }
        .btn-kpn:hover { background: #8b2623; color: white; }
        .info-label { font-size: 0.7rem; font-weight: 700; color: #999; text-transform: uppercase; margin-bottom: 2px; }
        .ticket-box { background: #fff; border-left: 4px solid var(--kpn-red); padding: 12px 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .form-control { border-radius: 6px; font-size: 0.9rem; }
        hr { margin: 10px 0; opacity: 0.1; }
    </style>
</head>
<body>
    <div class="container my-4">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="card shadow-sm">
                    <div class="header-slim text-center">
                        <h2 class="h6 mb-0 fw-bold text-white">PANDU by Nastari</h2>
                    </div>
                    
                    <div class="card-body p-3">
                        @if(session('success'))
                            <div class="text-center py-4">
                                <i class="bi bi-check-circle-fill text-success fs-1"></i>
                                <h3 class="h6 fw-bold mt-2">Terima Kasih!</h3>
                                <p class="text-muted small mb-0">{{ session('success') }}</p>
                            </div>
                        @else
                            <div class="d-flex align-items-center mb-3 bg-light p-2 rounded">
                                <i class="bi bi-person-circle fs-4 me-2 text-secondary"></i>
                                <div>
                                    <div class="info-label">Responding As</div>
                                    <div class="fw-bold text-dark">{{ $foundTicket['pic'] }}</div>
                                </div>
                            </div>

                            <div class="ticket-box mb-3">
                                <div class="row">
                                    <div class="col-7">
                                        <div class="info-label">Employee</div>
                                        <div class="fw-bold">{{ $foundTicket['nama_penanya'] }}</div>
                                    </div>
                                    <div class="col-5 text-end">
                                        <div class="info-label">Ticket ID</div>
                                        <div class="fw-bold">#{{ $foundTicket['id'] }}</div>
                                    </div>
                                </div>
                                <hr>
                                <div class="info-label">Question</div>
                                <p class="mb-0 text-dark italic">"{{ $foundTicket['pertanyaan'] }}"</p>
                            </div>

                            <form method="POST" action="{{ route('hear.ticket.update', $token) }}">
                                @csrf
                                <div class="mb-3">
                                    <label for="jawaban" class="info-label d-block mb-1 text-dark">Your Answer</label>
                                    <textarea id="jawaban" name="jawaban" class="form-control" rows="6" required 
                                              placeholder="Tuliskan jawaban di sini..."></textarea>
                                </div>
                                <button type="submit" class="btn btn-kpn w-100 shadow-sm">
                                    Submit
                                </button>
                            </form>
                        @endif
                    </div>
                    
                    <div class="card-footer text-center py-2 bg-white border-0">
                        <small class="text-muted" style="font-size: 0.7rem;">&copy; {{ date("Y") }} <strong>HCIS KPN Corp</strong></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>