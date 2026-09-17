{{--
    (f) Distribusi unit bisnis.

    Daftar unit bisnisnya dibaca dari data, tidak pernah ditulis manual:
    group_company di kpncorp.employees digabung dengan yang ada di usernastari
    dan di nastari_events. Konsekuensinya unit yang baru muncul di HRIS langsung
    ikut tampil di sini tanpa perlu mengubah kode, dan unit yang hanya punya
    karyawan nonaktif pun tetap terlihat.
--}}
<div id="unit-bisnis" class="mb-4" style="scroll-margin-top:70px">
    <h6 class="fw-bold text-dark mb-3"><i class="bi bi-diagram-3 text-primary me-2"></i>Distribusi Unit Bisnis</h6>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3 d-flex flex-column">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">Pengguna Terdaftar per Unit</h6>
                    <div class="flex-grow-1 d-flex align-items-center justify-content-center">
                        <div style="width:100%;max-width:240px"><canvas id="chBu"></canvas></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">Adopsi per Unit Bisnis</h6>

                    @if($bus === [])
                        <p class="text-muted small mb-0">Belum ada data unit bisnis yang bisa ditampilkan.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.72rem">
                                <thead class="table-light">
                                    <tr>
                                        <th>Unit Bisnis</th>
                                        <th class="text-end">Karyawan aktif</th>
                                        <th class="text-end">Karyawan nonaktif</th>
                                        <th class="text-end">Terdaftar</th>
                                        <th class="text-end">Adopsi</th>
                                        <th class="text-end">Aktif periode</th>
                                        <th class="text-end">Aksi</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($bus as $row)
                                        <tr>
                                            <td class="fw-semibold">{{ $row['bu'] }}</td>
                                            <td class="text-end">{{ $fmt($row['employees']) }}</td>
                                            <td class="text-end text-muted">{{ $fmt($row['employees_inactive']) }}</td>
                                            <td class="text-end">
                                                {{ $fmt($row['registered']) }}
                                                @if($row['registered_inactive'] > 0)
                                                    <span class="text-danger" style="font-size:.62rem">
                                                        ({{ $fmt($row['registered_inactive']) }} nonaktif)
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if($row['registration_rate'] !== null)
                                                    <div class="d-flex align-items-center justify-content-end gap-2">
                                                        <div class="progress flex-grow-1" style="height:4px;max-width:60px">
                                                            <div class="progress-bar bg-primary" style="width:{{ min(100, $row['registration_rate']) }}%"></div>
                                                        </div>
                                                        <span>{{ $pct($row['registration_rate']) }}</span>
                                                    </div>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end">{{ $fmt($row['active_users']) }}</td>
                                            <td class="text-end">{{ $fmt($row['actions']) }}</td>
                                            <td class="text-end">
                                                <a href="{{ route('admin.dashboard', array_filter(['from' => $window['from'], 'to' => $window['to'], 'bu' => $row['bu']])) }}"
                                                   class="btn btn-sm btn-outline-secondary py-0 px-1" title="Filter halaman ini ke {{ $row['bu'] }}">
                                                    <i class="bi bi-funnel"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted mb-0 mt-2" style="font-size:.68rem">
                            "Karyawan aktif/nonaktif" adalah headcount HRIS (<code>deleted_at</code>), "Terdaftar" adalah
                            pengguna yang pernah memakai bot, dan "Aktif periode" adalah nomor unik yang berkirim
                            pesan pada rentang tanggal terpilih.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
