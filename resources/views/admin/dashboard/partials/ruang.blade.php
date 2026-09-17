{{--
    (e) Riwayat percakapan Ruang dari daily_chat_logs: karyawan, waktu,
    pertanyaan, jawaban, dan status error.

    Dipaginasi 20 baris. Ini bukan hiasan: jalur agregat harus men-decode JSON
    pesan setiap baris di PHP, jadi hanya baris pada halaman yang diminta yang
    di-decode. Filter status membaca kolom last_status yang ditulis
    RuangService, bukan hasil menebak dari isi teks.

    Isi percakapan bisa sangat pribadi, jadi tombol transkrip lengkap hanya
    muncul untuk Super Admin. Nomor telepon selalu disamarkan.
--}}
@php
    // Tautan filter dan paginasi harus mempertahankan seluruh filter yang
    // aktif, termasuk milik tabel lain di halaman ini, supaya mengklik
    // "Berikutnya" di sini tidak mengembalikan tabel akses fitur ke halaman 1.
    $ruangQuery = fn (array $extra = []) => route('admin.dashboard', array_filter(
        array_merge($baseQuery, [
            'status'   => $ruangStatus,
            'q'        => $empSearch,
            'emp_page' => $employees['page'] > 1 ? $employees['page'] : null,
        ], $extra),
        fn ($v) => $v !== null && $v !== ''
    )) . '#ruang';
@endphp

<div id="ruang" class="mb-4" style="scroll-margin-top:70px">
    <h6 class="fw-bold text-dark mb-3"><i class="bi bi-chat-heart text-primary me-2"></i>Riwayat Percakapan Ruang</h6>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <span class="fw-bold">{{ $fmt($ruang['total']) }}</span>
                    <span class="text-muted small">hari percakapan pada periode ini</span>
                    @if($ruang['errors'] > 0)
                        <span class="badge text-bg-danger ms-2">{{ $fmt($ruang['errors']) }} bermasalah</span>
                    @endif
                </div>
                <div class="btn-group btn-group-sm">
                    <a href="{{ $ruangQuery(['status' => null, 'ruang_page' => null]) }}"
                       class="btn {{ $ruangStatus === null ? 'btn-primary' : 'btn-outline-secondary' }}">Semua</a>
                    <a href="{{ $ruangQuery(['status' => 'ok', 'ruang_page' => null]) }}"
                       class="btn {{ $ruangStatus === 'ok' ? 'btn-primary' : 'btn-outline-secondary' }}">Normal</a>
                    <a href="{{ $ruangQuery(['status' => 'error', 'ruang_page' => null]) }}"
                       class="btn {{ $ruangStatus === 'error' ? 'btn-danger' : 'btn-outline-secondary' }}">Bermasalah</a>
                </div>
            </div>

            @if($ruang['rows'] === [])
                <div class="text-center text-muted py-4">
                    <i class="bi bi-chat-dots fs-3 d-block mb-2 opacity-50"></i>
                    <p class="small mb-0">Tidak ada percakapan Ruang pada periode dan filter ini.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" style="font-size:.72rem">
                        <thead class="table-light">
                            <tr>
                                <th>Tanggal</th>
                                <th>Karyawan</th>
                                <th>Unit Bisnis</th>
                                <th style="min-width:200px">Pertanyaan pertama</th>
                                <th style="min-width:220px">Jawaban terakhir</th>
                                <th class="text-end">Pesan</th>
                                <th>Waktu terakhir</th>
                                <th>Status</th>
                                @if($canSeeContent)<th></th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ruang['rows'] as $row)
                                <tr>
                                    <td class="text-nowrap">{{ $when($row['date'], 'd/m/y') }}</td>
                                    <td>
                                        <span class="d-block text-truncate" style="max-width:160px">{{ $row['employee_name'] ?: '—' }}</span>
                                        <span class="text-muted font-monospace" style="font-size:.65rem">{{ $row['phone_masked'] }}</span>
                                    </td>
                                    <td class="text-muted">{{ $row['bu'] ?: '—' }}</td>
                                    <td class="text-muted">{{ $row['question'] ?: '—' }}</td>
                                    <td class="text-muted">{{ $row['answer'] ?: '—' }}</td>
                                    <td class="text-end">
                                        {{ $fmt($row['messages']) }}
                                        <span class="text-muted d-block" style="font-size:.62rem">{{ $fmt($row['exchanges']) }} tanya-jawab</span>
                                    </td>
                                    <td class="text-nowrap text-muted">{{ $row['last_at'] ?: '—' }}</td>
                                    <td>
                                        @if($row['status'] === 'ok')
                                            <span class="badge text-bg-success">Normal</span>
                                        @else
                                            <span class="badge text-bg-danger" title="{{ $row['error_count'] }} pesan gagal">
                                                {{ $row['status'] }}
                                            </span>
                                        @endif
                                    </td>
                                    @if($canSeeContent)
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-secondary py-0 px-1 js-transcript"
                                                    data-id="{{ $row['id'] }}" title="Buka transkrip">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Paginasi. Halaman di luar rentang di-clamp di service, jadi
                     ?ruang_page=99 mendarat di halaman terakhir yang nyata,
                     bukan di tabel kosong. --}}
                <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
                    <span class="text-muted" style="font-size:.7rem">
                        Halaman {{ $ruang['page'] }} dari {{ $ruang['last_page'] }} &middot;
                        {{ $fmt($ruang['total']) }} baris &middot; {{ $ruang['per_page'] }} per halaman
                    </span>
                    <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-secondary {{ $ruang['page'] <= 1 ? 'disabled' : '' }}"
                           href="{{ $ruangQuery(['ruang_page' => max(1, $ruang['page'] - 1)]) }}">
                            <i class="bi bi-chevron-left"></i> Sebelumnya
                        </a>
                        <a class="btn btn-outline-secondary {{ $ruang['page'] >= $ruang['last_page'] ? 'disabled' : '' }}"
                           href="{{ $ruangQuery(['ruang_page' => min($ruang['last_page'], $ruang['page'] + 1)]) }}">
                            Berikutnya <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
            @endif

            @unless($canSeeContent)
                <p class="text-muted mb-0 mt-2" style="font-size:.68rem">
                    <i class="bi bi-lock me-1"></i>Transkrip lengkap hanya bisa dibuka oleh Super Admin.
                </p>
            @endunless
        </div>
    </div>
</div>

@if($canSeeContent)
<div class="modal fade" id="transcriptModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold" id="transcriptTitle">Transkrip Ruang</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="transcriptBody">
                <div class="text-center text-muted py-4">Memuat…</div>
            </div>
        </div>
    </div>
</div>
@endif
