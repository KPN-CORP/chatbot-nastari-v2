{{--
    (b) Sinkronisasi harian usernastari <- kpncorp.employees.

    Mekanisme yang dipakai: Laravel scheduler (routes/console.php) memanggil
    `nastari:sync-employees --trigger=schedule` setiap hari 02:00. Karena belum
    dipastikan cron produksi benar-benar menjalankan `schedule:run`, perintah
    yang sama juga bisa dipicu manual dan lewat HTTP
    (GET /tasks/employee-sync, token-gated, satu panggilan per jam).

    Aman dijalankan berkali-kali: EmployeeSyncService punya cooldown 20 jam,
    hanya menulis nilai sumber yang tidak kosong, tidak pernah menimpa
    whatsapp_number, dan tidak pernah menyisipkan baris ganda — kunci
    pencocokannya employee_id, dan nomor yang dipakai lebih dari satu karyawan
    aktif tidak pernah dipre-registrasi.
--}}
<div id="sinkronisasi" class="mb-4" style="scroll-margin-top:70px">
    <h6 class="fw-bold text-dark mb-3"><i class="bi bi-arrow-repeat text-primary me-2"></i>Sinkronisasi Data Karyawan</h6>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Sinkronisasi Berhasil Terakhir</h6>
                            <h5 class="fw-bold mb-0">{{ $when($sync['last_ok']) }}</h5>
                        </div>
                        @if($sync['is_stale'])
                            <span class="badge text-bg-danger">Perlu diperiksa</span>
                        @else
                            <span class="badge text-bg-success">Sehat</span>
                        @endif
                    </div>

                    @if($sync['is_stale'])
                        <div class="alert alert-danger small py-2 mb-2">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Belum ada sinkronisasi sukses dalam 36 jam terakhir. Status aktif/nonaktif
                            berisiko basi. Periksa apakah cron menjalankan <code>schedule:run</code>,
                            atau jalankan manual:
                            <code class="d-block mt-1">php artisan nastari:sync-employees --force</code>
                        </div>
                    @endif

                    <ul class="list-unstyled mb-0" style="font-size:.72rem">
                        <li class="d-flex justify-content-between border-bottom py-1">
                            <span class="text-muted">Jadwal</span><strong>Harian 02:00 &middot; scheduler Laravel</strong>
                        </li>
                        <li class="d-flex justify-content-between border-bottom py-1">
                            <span class="text-muted">Cooldown</span><strong>20 jam</strong>
                        </li>
                        <li class="d-flex justify-content-between border-bottom py-1">
                            <span class="text-muted">Pemicu HTTP cadangan</span>
                            <strong class="{{ $httpFallback ? 'text-success' : 'text-muted' }}">
                                {{ $httpFallback ? 'Aktif' : 'Nonaktif (token belum diisi)' }}
                            </strong>
                        </li>
                        <li class="d-flex justify-content-between py-1">
                            <span class="text-muted">Sumber status</span><strong><code>employees.deleted_at</code></strong>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">Hasil Jalan Terakhir</h6>

                    @if($sync['last_counts'])
                        @php
                            $counts = [
                                ['Baris diperiksa', $sync['last_counts']['examined'], 'text-dark'],
                                ['Field diperbarui', $sync['last_counts']['updated'], 'text-primary'],
                                ['Ditandai aktif', $sync['last_counts']['marked_active'], 'text-success'],
                                ['Ditandai nonaktif', $sync['last_counts']['marked_inactive'], 'text-danger'],
                                ['Pre-registrasi baru', $sync['last_counts']['pre_registered'], 'text-info'],
                                ['Tidak ada di HRIS', $sync['last_counts']['not_found_in_hris'], 'text-warning'],
                                ['Nomor dipakai >1 orang', $sync['last_counts']['ambiguous_phones'], 'text-warning'],
                            ];
                        @endphp
                        <div class="row g-2 mb-3">
                            @foreach($counts as [$label, $value, $color])
                                <div class="col-6 col-md-3">
                                    <div class="border rounded p-2 h-100">
                                        <p class="text-muted mb-0" style="font-size:.65rem">{{ $label }}</p>
                                        <strong class="{{ $color }}">{{ $fmt($value) }}</strong>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-muted small">Belum pernah ada sinkronisasi yang selesai dengan status sukses.</p>
                    @endif

                    @if($sync['recent'] !== [])
                        <div class="table-responsive" style="max-height:200px;overflow-y:auto">
                            <table class="table table-sm align-middle mb-0" style="font-size:.7rem">
                                <thead class="table-light">
                                    <tr>
                                        <th>Mulai</th><th>Pemicu</th><th>Status</th><th>Catatan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($sync['recent'] as $run)
                                        <tr>
                                            <td class="text-nowrap">{{ $when($run['started_at'], 'd/m/y H:i') }}</td>
                                            <td>{{ $run['trigger'] }}</td>
                                            <td>
                                                @if($run['status'] === 'ok')
                                                    <span class="badge text-bg-success">ok</span>
                                                @elseif($run['status'] === 'skipped')
                                                    <span class="badge text-bg-secondary">dilewati</span>
                                                @else
                                                    <span class="badge text-bg-danger">{{ $run['status'] }}</span>
                                                @endif
                                            </td>
                                            <td class="text-muted">{{ $run['message'] ?: '—' }}</td>
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
