{{--
    Log aplikasi dari storage/logs — diagnostik, bukan metrik.

    Kenapa blok ini terpisah dan angkanya TIDAK dijumlahkan dengan bagian
    "Error & Layanan Eksternal" di atas: sebagian error sudah punya baris
    terstrukturnya sendiri yang ditulis aplikasi, jadi menggabungkan keduanya
    akan menghitung satu kejadian dua kali.

    Kenapa blok ini tetap perlu ada: fatal error PHP hanya hidup di sini.
    Prosesnya mati sebelum pencatat aktivitas bisa menulis apa pun — itulah
    yang membuat kasus memori habis di generate surat dulu tidak meninggalkan
    jejak apa pun kecuali di file log. Begitu juga exception di panel admin,
    yang tidak lewat jalur WhatsApp sama sekali.

    Isi pesan sudah disaring oleh NastariLogScanner sebelum disimpan: api_key,
    token, header Authorization, nomor telepon, dan alamat email disensor atau
    disamarkan.
--}}
@php
    $logQuery = fn (array $extra = []) => route('admin.dashboard', array_filter(
        array_merge($baseQuery, [
            'status'     => $ruangStatus,
            'ruang_page' => $ruang['page'] > 1 ? $ruang['page'] : null,
            'q'          => $empSearch,
            'emp_page'   => $employees['page'] > 1 ? $employees['page'] : null,
            'log_level'  => $logLevel,
        ], $extra),
        fn ($v) => $v !== null && $v !== ''
    )) . '#log-aplikasi';

    $levelBadge = [
        'EMERGENCY' => 'text-bg-dark',
        'ALERT'     => 'text-bg-dark',
        'CRITICAL'  => 'text-bg-danger',
        'ERROR'     => 'text-bg-warning',
    ];
@endphp

<div id="log-aplikasi" class="mb-4" style="scroll-margin-top:70px">
    <h6 class="fw-bold text-dark mb-3">
        <i class="bi bi-file-earmark-text text-primary me-2"></i>Log Aplikasi
        <span class="text-muted fw-normal" style="font-size:.75rem">— dari <code>storage/logs/laravel*.log</code></span>
    </h6>

    <div class="alert alert-light border small py-2 mb-3 d-flex flex-wrap gap-3 align-items-center">
        <span>
            <i class="bi bi-info-circle me-1 text-primary"></i>
            Blok ini <strong>tidak dijumlahkan</strong> dengan angka error di bagian sebelumnya, supaya satu
            kejadian tidak terhitung dua kali.
        </span>
        <span class="text-muted">Pemindaian terakhir <strong>{{ $when($logs['last_scan']) }}</strong></span>
        <span class="text-muted">{{ $fmt($logs['files']) }} file terpantau</span>
        <span class="text-muted">Retensi tabel {{ $logs['retention'] }} hari (file log sendiri dirotasi 14 hari)</span>
    </div>

    @if($logs['total'] === 0 && $logs['last_scan'] === null)
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <p class="text-muted small mb-1">
                    Belum ada pemindaian log yang tercatat. Perintahnya dijadwalkan tiap 10 menit; untuk
                    menjalankan pertama kali:
                </p>
                <code class="d-block">php artisan nastari:scan-logs</code>
            </div>
        </div>
    @else
        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-3">
                        <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Entri Periode Ini</h6>
                        <h3 class="fw-bold {{ $logs['total'] > 0 ? 'text-danger' : 'text-success' }} mb-2">{{ $fmt($logs['total']) }}</h3>

                        @if($logs['by_level'] !== [])
                            <ul class="list-unstyled mb-0" style="font-size:.72rem">
                                @foreach($logs['by_level'] as $row)
                                    <li class="d-flex justify-content-between border-bottom py-1">
                                        <span>
                                            <span class="badge {{ $levelBadge[$row['level']] ?? 'text-bg-secondary' }}">{{ $row['level'] }}</span>
                                        </span>
                                        <span class="text-nowrap">
                                            <strong>{{ $fmt($row['count']) }}</strong>
                                            <span class="text-muted" style="font-size:.62rem">{{ $when($row['last_at'], 'd/m H:i') }}</span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-muted small mb-0">Tidak ada entri ERROR ke atas pada periode ini.</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Dikelompokkan per pesan yang sudah dinormalkan: satu insiden yang
                 mengulang ratusan kali menjadi satu baris dengan jumlahnya. --}}
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body p-3">
                        <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">
                            Error yang Paling Sering Berulang
                        </h6>

                        @if($logs['top'] === [])
                            <p class="text-muted small mb-0">Belum ada yang bisa dikelompokkan pada periode ini.</p>
                        @else
                            <div class="table-responsive" style="max-height:260px;overflow-y:auto">
                                <table class="table table-sm table-hover align-middle mb-0" style="font-size:.7rem">
                                    <thead class="table-light position-sticky top-0">
                                        <tr>
                                            <th>Jenis</th>
                                            <th style="min-width:240px">Pesan</th>
                                            <th>Lokasi</th>
                                            <th class="text-end">Kejadian</th>
                                            <th>Terakhir</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($logs['top'] as $row)
                                            <tr>
                                                <td class="text-nowrap">
                                                    <span class="badge {{ $levelBadge[$row['level']] ?? 'text-bg-secondary' }}">{{ $row['level'] }}</span>
                                                    <span class="d-block text-muted" style="font-size:.62rem">{{ $row['kind'] }}</span>
                                                </td>
                                                <td class="text-muted">{{ $row['title'] }}</td>
                                                <td class="font-monospace text-truncate" style="max-width:170px"
                                                    title="{{ $row['file'] }}{{ $row['line'] ? ':' . $row['line'] : '' }}">
                                                    @if($row['file'])
                                                        {{ $row['file'] }}@if($row['line']):{{ $row['line'] }}@endif
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                                <td class="text-end fw-semibold">{{ $fmt($row['count']) }}</td>
                                                <td class="text-nowrap text-muted">{{ $when($row['last_at'], 'd/m H:i') }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <h6 class="text-muted text-uppercase small fw-bold mb-0" style="font-size:.65rem">Entri Terakhir</h6>
                            <div class="btn-group btn-group-sm">
                                <a href="{{ $logQuery(['log_level' => null, 'log_page' => null]) }}"
                                   class="btn {{ $logLevel === null ? 'btn-primary' : 'btn-outline-secondary' }}">Semua level</a>
                                @foreach(['ERROR', 'CRITICAL'] as $lvl)
                                    <a href="{{ $logQuery(['log_level' => $lvl, 'log_page' => null]) }}"
                                       class="btn {{ $logLevel === $lvl ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $lvl }}</a>
                                @endforeach
                            </div>
                        </div>

                        @if($logRows['rows'] === [])
                            <p class="text-muted small mb-0">Tidak ada entri untuk periode dan level ini.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0" style="font-size:.7rem">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Waktu</th>
                                            <th>Level</th>
                                            <th>Jenis</th>
                                            <th style="min-width:320px">Pesan</th>
                                            <th>Exception</th>
                                            <th>Lokasi</th>
                                            <th>Berkas log</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($logRows['rows'] as $row)
                                            <tr>
                                                <td class="text-nowrap">{{ $when($row['at'], 'd/m/y H:i:s') }}</td>
                                                <td class="text-nowrap">
                                                    <span class="badge {{ $levelBadge[$row['level']] ?? 'text-bg-secondary' }}">{{ $row['level'] }}</span>
                                                </td>
                                                <td class="text-nowrap">{{ $row['kind'] }}</td>
                                                <td class="text-muted" style="word-break:break-word">{{ $row['message'] }}</td>
                                                <td class="text-truncate" style="max-width:140px" title="{{ $row['exception'] }}">
                                                    {{ $row['exception'] ? class_basename($row['exception']) : '—' }}
                                                </td>
                                                <td class="font-monospace text-truncate" style="max-width:170px"
                                                    title="{{ $row['file'] }}{{ $row['line'] ? ':' . $row['line'] : '' }}">
                                                    @if($row['file'])
                                                        {{ $row['file'] }}@if($row['line']):{{ $row['line'] }}@endif
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                                <td class="text-muted text-nowrap" style="font-size:.62rem">{{ $row['source_file'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
                                <span class="text-muted" style="font-size:.7rem">
                                    Halaman {{ $logRows['page'] }} dari {{ $logRows['last_page'] }} &middot;
                                    {{ $fmt($logRows['total']) }} entri &middot; {{ $logRows['per_page'] }} per halaman
                                </span>
                                <div class="btn-group btn-group-sm">
                                    <a class="btn btn-outline-secondary {{ $logRows['page'] <= 1 ? 'disabled' : '' }}"
                                       href="{{ $logQuery(['log_page' => max(1, $logRows['page'] - 1)]) }}">
                                        <i class="bi bi-chevron-left"></i> Sebelumnya
                                    </a>
                                    <a class="btn btn-outline-secondary {{ $logRows['page'] >= $logRows['last_page'] ? 'disabled' : '' }}"
                                       href="{{ $logQuery(['log_page' => min($logRows['last_page'], $logRows['page'] + 1)]) }}">
                                        Berikutnya <i class="bi bi-chevron-right"></i>
                                    </a>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
