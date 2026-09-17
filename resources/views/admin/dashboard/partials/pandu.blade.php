{{--
    Pandu — helpdesk kebijakan.

    Angkanya dibaca dari pandu_qa, bukan dari `hears`. `hears` hanya menerima
    eskalasi, jadi mengukur Pandu dari sana berarti mengukur kegagalannya saja
    dan mengabaikan setiap pertanyaan yang berhasil dijawab.

    Isi jawaban baru tersimpan sejak tabel pandu_qa ada: yang lebih tua
    dipulihkan dari baris PANDU_DEBUG di storage/logs, dan yang tidak terpulihkan
    ditandai apa adanya, bukan ditampilkan seolah-olah kosong tanpa sebab.
--}}
@php
    $panduQuery = fn (array $extra = []) => route('admin.dashboard', array_filter(
        array_merge($baseQuery, [
            'status'        => $ruangStatus,
            'ruang_page'    => $ruang['page'] > 1 ? $ruang['page'] : null,
            'q'             => $empSearch,
            'emp_page'      => $employees['page'] > 1 ? $employees['page'] : null,
            'log_level'     => $logLevel,
            'pandu_outcome' => $panduOutcome,
            'pandu_q'       => $panduSearch,
        ], $extra),
        fn ($v) => $v !== null && $v !== ''
    )) . '#pandu';

    $outcomeBadge = [
        'answered'    => ['text-bg-success', 'Dijawab'],
        'not_found'   => ['text-bg-warning', 'Tidak ditemukan'],
        'no_response' => ['text-bg-secondary', 'Tanpa respons'],
        'error'       => ['text-bg-danger', 'Gagal'],
    ];
@endphp

<div id="pandu" class="mb-4" style="scroll-margin-top:70px">
    <h6 class="fw-bold text-dark mb-3">
        <i class="bi bi-question-circle text-primary me-2"></i>Pandu — Helpdesk Kebijakan
    </h6>

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Pertanyaan</h6>
                    <h3 class="fw-bold mb-1">{{ $fmt($pandu['total']) }}</h3>
                    <p class="text-muted mb-0" style="font-size:.7rem">
                        dari {{ $fmt($pandu['users']) }} karyawan
                        @if($pandu['from_ruang'] > 0)
                            <br>{{ $fmt($pandu['from_ruang']) }} datang dari serah-terima Ruang
                        @endif
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Terjawab</h6>
                    <h3 class="fw-bold text-success mb-1">{{ $pct($pandu['answer_rate']) }}</h3>
                    <p class="text-muted mb-0" style="font-size:.7rem">
                        {{ $fmt($pandu['answered']) }} dijawab langsung oleh Pandu
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 {{ $pandu['not_found'] > 0 ? 'border-warning' : 'border-light' }}">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Belum Terjawab</h6>
                    <h3 class="fw-bold mb-1 {{ $pandu['not_found'] > 0 ? 'text-warning' : '' }}">
                        {{ $fmt($pandu['not_found'] + $pandu['no_response'] + $pandu['errors']) }}
                    </h3>
                    <p class="text-muted mb-0" style="font-size:.7rem">
                        {{ $fmt($pandu['not_found']) }} tidak ada di knowledge base &middot;
                        {{ $fmt($pandu['no_response']) }} tanpa respons &middot;
                        {{ $fmt($pandu['errors']) }} gagal
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Waktu Jawab</h6>
                    <h3 class="fw-bold mb-1">
                        {{ $pandu['avg_seconds'] === null ? '—' : number_format($pandu['avg_seconds'], 1, ',', '.') . 's' }}
                    </h3>
                    <p class="text-muted mb-0" style="font-size:.7rem">
                        rata-rata untuk pertanyaan yang dijawab
                        @if($pandu['tickets']['open'] > 0)
                            <br><span class="text-danger">{{ $fmt($pandu['tickets']['open']) }} tiket eskalasi masih terbuka</span>
                        @endif
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">Tren Harian</h6>
                    <div style="height:220px"><canvas id="chPanduDaily"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3 d-flex flex-column">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">Hasil Pertanyaan</h6>
                    <div class="flex-grow-1 d-flex align-items-center justify-content-center">
                        <div style="width:100%;max-width:210px"><canvas id="chPanduOutcome"></canvas></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">
                        Pertanyaan yang Berulang
                    </h6>

                    @if($panduTop === [])
                        <p class="text-muted small mb-0">
                            Belum ada pertanyaan yang ditanyakan lebih dari sekali pada periode ini.
                        </p>
                    @else
                        <div class="table-responsive" style="max-height:250px;overflow-y:auto">
                            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.72rem">
                                <thead class="table-light position-sticky top-0">
                                    <tr>
                                        <th style="min-width:240px">Pertanyaan</th>
                                        <th class="text-end">Ditanya</th>
                                        <th class="text-end">Dijawab</th>
                                        <th class="text-end">Belum</th>
                                        <th>Terakhir</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($panduTop as $row)
                                        <tr>
                                            <td class="text-muted">{{ $row['question'] }}</td>
                                            <td class="text-end fw-semibold">{{ $fmt($row['count']) }}</td>
                                            <td class="text-end text-success">{{ $fmt($row['answered']) }}</td>
                                            <td class="text-end {{ $row['not_found'] > 0 ? 'text-warning fw-semibold' : 'text-muted' }}">
                                                {{ $fmt($row['not_found']) }}
                                            </td>
                                            <td class="text-nowrap text-muted">{{ $when($row['last_at'], 'd/m H:i') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted mb-0 mt-2" style="font-size:.68rem">
                            Kolom "Belum" adalah kandidat penambahan knowledge base: pertanyaan yang sering
                            muncul tapi Pandu belum bisa menjawabnya.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">
                        Tiket Eskalasi Terbuka
                    </h6>

                    @if($panduTickets === [])
                        <p class="text-muted small mb-0">Tidak ada tiket yang menggantung. 👍</p>
                    @else
                        <div class="table-responsive" style="max-height:250px;overflow-y:auto">
                            <table class="table table-sm align-middle mb-0" style="font-size:.7rem">
                                <thead class="table-light position-sticky top-0">
                                    <tr>
                                        <th>Tiket</th>
                                        <th style="min-width:180px">Pertanyaan</th>
                                        <th>PIC</th>
                                        <th class="text-end">Umur</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($panduTickets as $ticket)
                                        <tr>
                                            <td class="font-monospace text-nowrap">{{ $ticket['code'] }}</td>
                                            <td class="text-muted">{{ $ticket['question'] }}</td>
                                            <td class="text-nowrap">{{ $ticket['pic'] }}</td>
                                            <td class="text-end text-nowrap {{ $ticket['age_days'] > 7 ? 'text-danger fw-semibold' : '' }}">
                                                {{ $ticket['age_days'] }} hari
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Riwayat tanya-jawab. Inilah yang sebelumnya tidak ada sama sekali:
         jawaban Pandu dikirim ke WhatsApp lalu hilang. --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Riwayat Tanya-Jawab</h6>
                    <span class="text-muted" style="font-size:.7rem">
                        {{ $fmt($panduQa['total']) }} pertanyaan pada periode ini
                        @if($pandu['answer_missing'] > 0)
                            &middot; <span class="text-warning">{{ $fmt($pandu['answer_missing']) }} tanpa teks jawaban tersimpan</span>
                        @endif
                    </span>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <div class="btn-group btn-group-sm">
                        <a href="{{ $panduQuery(['pandu_outcome' => null, 'pandu_page' => null]) }}"
                           class="btn {{ $panduOutcome === null ? 'btn-primary' : 'btn-outline-secondary' }}">Semua</a>
                        <a href="{{ $panduQuery(['pandu_outcome' => 'answered', 'pandu_page' => null]) }}"
                           class="btn {{ $panduOutcome === 'answered' ? 'btn-success' : 'btn-outline-secondary' }}">Dijawab</a>
                        <a href="{{ $panduQuery(['pandu_outcome' => 'unanswered', 'pandu_page' => null]) }}"
                           class="btn {{ $panduOutcome === 'unanswered' ? 'btn-warning' : 'btn-outline-secondary' }}">Belum</a>
                        <a href="{{ $panduQuery(['pandu_outcome' => 'escalated', 'pandu_page' => null]) }}"
                           class="btn {{ $panduOutcome === 'escalated' ? 'btn-dark' : 'btn-outline-secondary' }}">Dieskalasi</a>
                    </div>

                    <form method="GET" action="{{ route('admin.dashboard') }}" class="d-flex gap-2">
                        @foreach($baseQuery as $k => $v)
                            <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                        @endforeach
                        @if($panduOutcome)<input type="hidden" name="pandu_outcome" value="{{ $panduOutcome }}">@endif
                        <input type="search" name="pandu_q" value="{{ $panduSearch }}" maxlength="80"
                               class="form-control form-control-sm" placeholder="Cari pertanyaan / jawaban" style="min-width:210px">
                        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
                        @if($panduSearch)
                            <a href="{{ $panduQuery(['pandu_q' => null, 'pandu_page' => null]) }}" class="btn btn-sm btn-outline-secondary">Hapus</a>
                        @endif
                    </form>
                </div>
            </div>

            @if($panduQa['rows'] === [])
                <div class="text-center text-muted py-4">
                    <i class="bi bi-patch-question fs-3 d-block mb-2 opacity-50"></i>
                    <p class="small mb-0">
                        @if($panduQa['search'])
                            Tidak ada tanya-jawab yang cocok dengan "{{ $panduQa['search'] }}" pada periode ini.
                        @else
                            Belum ada pertanyaan Pandu pada periode dan filter ini.
                        @endif
                    </p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" style="font-size:.72rem">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:120px">Waktu</th>
                                <th style="min-width:130px">Karyawan</th>
                                <th style="min-width:250px">Pertanyaan</th>
                                <th style="min-width:320px">Jawaban</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($panduQa['rows'] as $row)
                                @php [$badgeClass, $badgeLabel] = $outcomeBadge[$row['outcome']] ?? ['text-bg-secondary', $row['outcome']]; @endphp
                                <tr>
                                    <td class="text-nowrap">
                                        {{ $when($row['at'], 'd/m/y H:i') }}
                                        @if($row['seconds'] !== null)
                                            <span class="text-muted d-block" style="font-size:.62rem">
                                                dijawab {{ number_format($row['seconds'], 1, ',', '.') }}s
                                            </span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="d-block text-truncate" style="max-width:130px">
                                            {{ $row['employee_name'] ?: '—' }}
                                        </span>
                                        <span class="text-muted d-block font-monospace" style="font-size:.62rem">
                                            {{ $row['phone_masked'] }}
                                        </span>
                                        @if($row['bu'])
                                            <span class="text-muted" style="font-size:.62rem">{{ $row['bu'] }}</span>
                                        @endif
                                    </td>
                                    <td style="word-break:break-word">
                                        {{ $row['question'] }}
                                        @if($row['from_ruang'])
                                            <span class="badge text-bg-light text-muted" title="Diteruskan dari Ruang">dari Ruang</span>
                                        @endif
                                    </td>
                                    <td class="text-muted" style="word-break:break-word">
                                        @if($row['answer'])
                                            @php $isLong = mb_strlen($row['answer']) > 200; @endphp
                                            {{ $isLong ? mb_substr($row['answer'], 0, 200) . '…' : $row['answer'] }}
                                            @if($isLong)
                                                <a class="d-block text-decoration-none" style="font-size:.66rem"
                                                   data-bs-toggle="collapse" href="#answer-{{ $row['id'] }}" role="button">
                                                    Lihat jawaban lengkap <i class="bi bi-chevron-down"></i>
                                                </a>
                                                <div class="collapse mt-1" id="answer-{{ $row['id'] }}">
                                                    <div class="border rounded p-2 bg-light" style="white-space:pre-wrap">{{ $row['answer'] }}</div>
                                                </div>
                                            @endif
                                        @elseif($row['origin'] === 'ingest')
                                            <span class="fst-italic">Teks jawaban tidak terpulihkan dari log</span>
                                        @else
                                            <span class="fst-italic">Tidak dijawab</span>
                                        @endif

                                        @if($row['ticket_code'])
                                            <div class="mt-2 border-top pt-2">
                                                <span class="badge text-bg-dark font-monospace">{{ $row['ticket_code'] }}</span>
                                                <span class="text-muted" style="font-size:.65rem">
                                                    {{ $row['ticket_status'] ?: 'status tidak diketahui' }}
                                                    @if($row['ticket_pic']) &middot; PIC {{ $row['ticket_pic'] }} @endif
                                                </span>
                                                @if($row['ticket_answer'])
                                                    <div class="mt-1" style="white-space:pre-wrap"><strong>Jawaban HCO:</strong> {{ $row['ticket_answer'] }}</div>
                                                @else
                                                    <div class="text-muted fst-italic" style="font-size:.65rem">HCO belum menjawab tiket ini.</div>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        <span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                        @if($row['origin'] === 'ingest')
                                            <span class="text-muted d-block" style="font-size:.6rem" title="Dipulihkan dari file log">dari log</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
                    <span class="text-muted" style="font-size:.7rem">
                        Halaman {{ $panduQa['page'] }} dari {{ $panduQa['last_page'] }} &middot;
                        {{ $fmt($panduQa['total']) }} pertanyaan &middot; {{ $panduQa['per_page'] }} per halaman
                    </span>
                    <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-secondary {{ $panduQa['page'] <= 1 ? 'disabled' : '' }}"
                           href="{{ $panduQuery(['pandu_page' => max(1, $panduQa['page'] - 1)]) }}">
                            <i class="bi bi-chevron-left"></i> Sebelumnya
                        </a>
                        <a class="btn btn-outline-secondary {{ $panduQa['page'] >= $panduQa['last_page'] ? 'disabled' : '' }}"
                           href="{{ $panduQuery(['pandu_page' => min($panduQa['last_page'], $panduQa['page'] + 1)]) }}">
                            Berikutnya <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
