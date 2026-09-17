{{--
    (a) Total pengguna terdaftar, dengan pembeda aktif vs nonaktif.

    Dua populasi di usernastari tidak boleh dicampur:
      registered      = pernah benar-benar memakai bot (angka adopsi)
      pre_registered  = disemai dari HRIS oleh sinkronisasi harian (angka cakupan)
    Kalau keduanya dijumlahkan, "total pengguna" berubah diam-diam menjadi
    jumlah karyawan.

    Status aktif/nonaktif mengikuti employees.deleted_at dan disegarkan oleh
    nastari:sync-employees. Status "belum diketahui" sengaja dihitung sebagai
    boleh mengakses (fail-open): mengunci karyawan asli karena sinkronisasi
    gagal lebih merugikan daripada membiarkan seorang leaver membaca datanya
    sendiri satu hari, dan setiap penolakan tercatat sehingga false positive
    kelihatan di hari yang sama.
--}}
<div id="pengguna" class="mb-4" style="scroll-margin-top:70px">
    <h6 class="fw-bold text-dark mb-3"><i class="bi bi-people text-primary me-2"></i>Pengguna Terdaftar</h6>

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Total Terdaftar</h6>
                    <h3 class="fw-bold text-dark mb-1">{{ $fmt($coverage['registered']) }}</h3>
                    <p class="text-muted mb-0" style="font-size:.7rem">
                        Karyawan yang pernah memakai bot.
                        @if($coverage['pre_registered'] > 0)
                            <br>+{{ $fmt($coverage['pre_registered']) }} pre-registered dari HRIS (belum pernah memakai).
                        @endif
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Karyawan Aktif</h6>
                    <h3 class="fw-bold text-success mb-1">{{ $fmt($coverage['active']) }}</h3>
                    <p class="text-muted mb-0" style="font-size:.7rem">
                        <code>employees.deleted_at IS NULL</code> &middot; akses penuh ke semua fitur.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 {{ $coverage['inactive'] > 0 ? 'border-danger' : 'border-light' }}">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Karyawan Nonaktif</h6>
                    <h3 class="fw-bold {{ $coverage['inactive'] > 0 ? 'text-danger' : 'text-dark' }} mb-1">{{ $fmt($coverage['inactive']) }}</h3>
                    <p class="text-muted mb-0" style="font-size:.7rem">
                        Hanya boleh <strong>Data Karyawan</strong> dan <strong>Ruang</strong>, dipaksakan di backend.
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Cakupan vs Headcount</h6>
                    <h3 class="fw-bold text-dark mb-1">{{ $pct($coverage['coverage_pct']) }}</h3>
                    <div class="progress mb-1" style="height:5px">
                        <div class="progress-bar bg-primary" style="width:{{ min(100, (float) ($coverage['coverage_pct'] ?? 0)) }}%"></div>
                    </div>
                    <p class="text-muted mb-0" style="font-size:.7rem">
                        {{ $fmt($coverage['registered']) }} dari {{ $fmt($coverage['headcount_active']) }} karyawan aktif di HRIS.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="row g-3 text-center">
                        <div class="col-6 col-md-3 border-end">
                            <p class="text-muted mb-1" style="font-size:.7rem">Pengguna aktif periode ini</p>
                            <h5 class="fw-bold mb-0">{{ $fmt($overview['active_users']) }}</h5>
                        </div>
                        <div class="col-6 col-md-3 border-end">
                            <p class="text-muted mb-1" style="font-size:.7rem">Pesan masuk</p>
                            <h5 class="fw-bold mb-0">{{ $fmt($overview['messages']) }}</h5>
                        </div>
                        <div class="col-6 col-md-3 border-end">
                            <p class="text-muted mb-1" style="font-size:.7rem">Aksi bernilai</p>
                            <h5 class="fw-bold mb-0">{{ $fmt($overview['actions']) }}</h5>
                        </div>
                        <div class="col-6 col-md-3">
                            <p class="text-muted mb-1" style="font-size:.7rem">Tiket HC System Desk</p>
                            <h5 class="fw-bold mb-0">{{ $fmt($totalHCDesk) }}</h5>
                            <span class="text-muted" style="font-size:.62rem">mengikuti scope role Anda</span>
                        </div>
                    </div>

                    @if($coverage['unknown'] > 0)
                        <div class="alert alert-warning small mb-0 mt-3 py-2">
                            <i class="bi bi-question-circle me-1"></i>
                            <strong>{{ $fmt($coverage['unknown']) }}</strong> pengguna berstatus <em>belum diketahui</em> —
                            tidak ada baris yang cocok di <code>kpncorp.employees</code>. Mereka tetap diizinkan
                            memakai bot (fail-open) sampai sinkronisasi berhasil mencocokkannya.
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Bukti bahwa aturan nonaktif benar-benar jalan di backend, dan
             sekaligus alat deteksi kalau ada status yang keliru. --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="fw-bold mb-1" style="font-size:.8rem">
                        <i class="bi bi-shield-check text-danger me-1"></i>Penolakan Akses
                    </h6>
                    <h3 class="fw-bold mb-1 {{ $denials['total'] > 0 ? 'text-danger' : 'text-dark' }}">{{ $fmt($denials['total']) }}</h3>
                    <p class="text-muted mb-2" style="font-size:.7rem">
                        {{ $fmt($denials['employees']) }} karyawan nonaktif ditolak backend pada periode ini.
                    </p>

                    @if($denials['by_feature'] !== [])
                        <ul class="list-unstyled mb-0" style="font-size:.72rem">
                            @foreach(array_slice($denials['by_feature'], 0, 5) as $row)
                                <li class="d-flex justify-content-between border-bottom py-1">
                                    <span class="text-truncate me-2">{{ $row['label'] }}</span>
                                    <strong>{{ $fmt($row['count']) }}</strong>
                                </li>
                            @endforeach
                        </ul>
                        @if($denials['recent'] !== [])
                            <a class="small text-decoration-none" data-bs-toggle="collapse" href="#denialDetail" role="button">
                                Lihat kejadian terakhir <i class="bi bi-chevron-down"></i>
                            </a>
                            <div class="collapse mt-2" id="denialDetail">
                                <div class="table-responsive" style="max-height:220px;overflow-y:auto">
                                    <table class="table table-sm mb-0" style="font-size:.68rem">
                                        <tbody>
                                            @foreach($denials['recent'] as $row)
                                                <tr>
                                                    <td class="text-nowrap text-muted">{{ $when($row['at'], 'd/m H:i') }}</td>
                                                    <td class="text-nowrap">{{ $row['employee_id'] ?: $row['phone_masked'] }}</td>
                                                    <td>{{ $row['feature'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    @else
                        <p class="text-muted small mb-0">Belum ada penolakan pada periode ini.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
