{{--
    (g) Monitoring error dan layanan eksternal: timeout, layanan tidak
    tersedia, kegagalan koneksi — beserta jumlah, waktu, endpoint, dan trennya.

    Sumber datanya bukan hasil parsing file log. Seluruh panggilan HTTP keluar
    diinstrumentasi terpusat di AppServiceProvider lewat Http::globalOptions()
    dengan on_stats milik Guzzle, jadi tidak ada satu pun call site yang perlu
    diubah dan tidak ada yang terlewat.

    Satu batas yang harus jujur disebut: kolom service, endpoint, error_type dan
    durasi hanya ada pada baris yang ditulis langsung oleh aplikasi. Baris hasil
    pemulihan dari file log lebih tua dari itu dan hanya punya label kasar, jadi
    rinciannya tidak ditampilkan seolah-olah lengkap.
--}}
<div id="kesehatan" class="mb-4" style="scroll-margin-top:70px">
    <h6 class="fw-bold text-dark mb-3"><i class="bi bi-activity text-primary me-2"></i>Error &amp; Layanan Eksternal</h6>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Total Error Periode Ini</h6>
                    <h3 class="fw-bold {{ $health['total'] > 0 ? 'text-danger' : 'text-success' }} mb-2">{{ $fmt($health['total']) }}</h3>

                    @if($health['by_type'] !== [])
                        <ul class="list-unstyled mb-0" style="font-size:.72rem">
                            @foreach($health['by_type'] as $row)
                                <li class="d-flex justify-content-between border-bottom py-1">
                                    <span class="text-truncate me-2" title="{{ $row['kind'] }}">{{ $row['label'] }}</span>
                                    <span class="text-nowrap">
                                        <strong>{{ $fmt($row['count']) }}</strong>
                                        <span class="text-muted" style="font-size:.62rem">{{ $when($row['last_at'], 'd/m H:i') }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted small mb-0">Tidak ada error tercatat pada periode ini.</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">Tren Error Harian</h6>
                    <div style="height:190px"><canvas id="chHealthTrend"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">Waktu Respons Layanan</h6>

                    @if($health['latency'] === [])
                        <p class="text-muted small mb-0">
                            Belum ada pengukuran durasi pada periode ini.
                        </p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0" style="font-size:.72rem">
                                <thead class="table-light">
                                    <tr>
                                        <th>Layanan</th>
                                        <th class="text-end">Panggilan</th>
                                        <th class="text-end">Rata-rata</th>
                                        <th class="text-end">p95</th>
                                        <th class="text-end">Maks</th>
                                        <th class="text-end">Gagal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($health['latency'] as $row)
                                        <tr>
                                            <td class="fw-semibold">{{ $row['service'] }}</td>
                                            <td class="text-end">{{ $fmt($row['calls']) }}</td>
                                            <td class="text-end">{{ $fmt($row['avg_ms']) }} ms</td>
                                            <td class="text-end">
                                                @if($row['p95_ms'] === null)
                                                    <span class="text-muted" title="Sampel belum cukup (< 20 panggilan)">—</span>
                                                @else
                                                    <span class="{{ $row['p95_ms'] > $row['slow_over'] ? 'text-danger fw-semibold' : '' }}">
                                                        {{ $fmt($row['p95_ms']) }} ms
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="text-end">{{ $fmt($row['max_ms']) }} ms</td>
                                            <td class="text-end {{ $row['failures'] > 0 ? 'text-danger' : 'text-muted' }}">
                                                {{ $fmt($row['failures']) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted mb-0 mt-2" style="font-size:.68rem">
                            p95 hanya dilaporkan setelah ada minimal 20 panggilan, supaya angkanya berarti.
                            Ambang lambat saat ini {{ $fmt(config('nastari.logging.http.slow_ms', 5000)) }} ms.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">Endpoint yang Paling Sering Gagal</h6>

                    @if($health['by_endpoint'] === [])
                        <p class="text-muted small mb-0">
                            Belum ada kegagalan dengan atribusi endpoint pada periode ini.
                        </p>
                    @else
                        <div class="table-responsive" style="max-height:260px;overflow-y:auto">
                            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.7rem">
                                <thead class="table-light position-sticky top-0">
                                    <tr>
                                        <th>Layanan</th>
                                        <th>Endpoint</th>
                                        <th>Jenis</th>
                                        <th class="text-end">Jumlah</th>
                                        <th class="text-end">HTTP</th>
                                        <th class="text-end">Rata-rata</th>
                                        <th>Terakhir</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($health['by_endpoint'] as $row)
                                        <tr>
                                            <td class="fw-semibold text-nowrap">{{ $row['service'] }}</td>
                                            <td class="font-monospace text-truncate" style="max-width:200px" title="{{ $row['endpoint'] }}">
                                                {{ $row['endpoint'] }}
                                            </td>
                                            <td class="text-nowrap">{{ $row['label'] }}</td>
                                            <td class="text-end">{{ $fmt($row['count']) }}</td>
                                            <td class="text-end">{{ $row['http_status'] ?: '—' }}</td>
                                            <td class="text-end">{{ $row['avg_ms'] === null ? '—' : $fmt($row['avg_ms']) . ' ms' }}</td>
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
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="text-muted text-uppercase small fw-bold mb-0" style="font-size:.65rem">Kejadian Terakhir</h6>
                        @if($health['live_since'])
                            <span class="text-muted" style="font-size:.65rem">
                                Atribusi layanan/endpoint tersedia untuk kejadian sejak {{ $when($health['live_since'], 'd M Y') }}
                            </span>
                        @endif
                    </div>

                    @if($health['recent'] === [])
                        <p class="text-muted small mb-0">Tidak ada kejadian error pada periode ini.</p>
                    @else
                        <div class="table-responsive" style="max-height:280px;overflow-y:auto">
                            <table class="table table-sm align-middle mb-0" style="font-size:.7rem">
                                <thead class="table-light position-sticky top-0">
                                    <tr>
                                        <th>Waktu</th>
                                        <th>Jenis</th>
                                        <th>Layanan</th>
                                        <th>Endpoint</th>
                                        <th class="text-end">HTTP</th>
                                        <th class="text-end">Durasi</th>
                                        <th>Pesan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($health['recent'] as $row)
                                        <tr>
                                            <td class="text-nowrap">{{ $when($row['at'], 'd/m/y H:i:s') }}</td>
                                            <td class="text-nowrap">{{ $row['label'] }}</td>
                                            <td class="text-nowrap">{{ $row['service'] ?: '—' }}</td>
                                            <td class="font-monospace text-truncate" style="max-width:180px" title="{{ $row['endpoint'] }}">
                                                {{ $row['endpoint'] ?: '—' }}
                                            </td>
                                            <td class="text-end">{{ $row['http_status'] ?: '—' }}</td>
                                            <td class="text-end">{{ $row['duration_ms'] === null ? '—' : $fmt($row['duration_ms']) . ' ms' }}</td>
                                            <td class="text-muted text-truncate" style="max-width:280px">{{ $row['message'] ?: '—' }}</td>
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
</div>
