{{--
    (h) Statistik akses fitur — agregat per fitur dan rincian per karyawan.

    Atribusi fiturnya memakai slug dari satu registry tunggal
    (App\Support\NastariFeatures), bukan teks label menunya. Kalau suatu saat
    "4. Ruang" berubah menjadi "7. Ruang", ia tetap terhitung sebagai satu
    fitur yang sama dan riwayatnya tidak pecah.

    Tabel per karyawan dipaginasi dan bisa dicari, dan tetap dibatasi scope
    unit bisnis milik role — inilah satu-satunya tabel di halaman ini yang
    menampilkan nama beserta NIK.
--}}
@php
    $empQuery = fn (array $extra = []) => route('admin.dashboard', array_filter(
        array_merge($baseQuery, [
            'status'     => $ruangStatus,
            'ruang_page' => $ruang['page'] > 1 ? $ruang['page'] : null,
            'q'          => $empSearch,
        ], $extra),
        fn ($v) => $v !== null && $v !== ''
    )) . '#fitur';
@endphp

<div id="fitur" class="mb-4" style="scroll-margin-top:70px">
    <h6 class="fw-bold text-dark mb-3"><i class="bi bi-grid-3x3-gap text-primary me-2"></i>Statistik Akses Fitur</h6>

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">Peringkat Fitur</h6>

                    @if($features === [])
                        <p class="text-muted small mb-0">Belum ada pemakaian fitur pada periode ini.</p>
                    @else
                        <div class="table-responsive" style="max-height:320px;overflow-y:auto">
                            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.72rem">
                                <thead class="table-light position-sticky top-0">
                                    <tr>
                                        <th>#</th>
                                        <th>Fitur</th>
                                        <th class="text-end">Dipakai</th>
                                        <th class="text-end">Pengguna</th>
                                        <th class="text-end">Porsi</th>
                                        <th class="text-end">Jangkauan</th>
                                        <th class="text-end">Per pengguna</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($features as $row)
                                        <tr>
                                            <td class="text-muted">{{ $row['rank'] }}</td>
                                            <td class="fw-semibold">
                                                {{ $row['label'] }}
                                                <span class="text-muted d-block font-monospace" style="font-size:.62rem">{{ $row['key'] }}</span>
                                            </td>
                                            <td class="text-end">{{ $fmt($row['uses']) }}</td>
                                            <td class="text-end">{{ $fmt($row['users']) }}</td>
                                            <td class="text-end">{{ $pct($row['share']) }}</td>
                                            <td class="text-end">{{ $pct($row['reach']) }}</td>
                                            <td class="text-end">{{ number_format($row['per_user'], 2, ',', '.') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted mb-0 mt-2" style="font-size:.68rem">
                            Porsi = bagian dari seluruh pemakaian fitur. Jangkauan = persentase pengguna aktif
                            periode ini yang pernah memakai fitur tersebut.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">Tren 5 Fitur Teratas</h6>
                    <div style="height:290px"><canvas id="chFeatureTrend"></canvas></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Akses Fitur per Karyawan</h6>
                    <span class="text-muted" style="font-size:.7rem">
                        {{ $fmt($employees['total']) }} karyawan melakukan {{ $fmt($employees['total_actions']) }} aksi pada periode ini
                        @if($scoped) &middot; <span class="text-primary">dibatasi scope unit bisnis role Anda</span> @endif
                    </span>
                </div>
                <form method="GET" action="{{ route('admin.dashboard') }}" class="d-flex gap-2">
                    @foreach($baseQuery as $k => $v)
                        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                    @endforeach
                    @if($ruangStatus)<input type="hidden" name="status" value="{{ $ruangStatus }}">@endif
                    <input type="search" name="q" value="{{ $empSearch }}" maxlength="60"
                           class="form-control form-control-sm" placeholder="Cari nama atau NIK" style="min-width:200px">
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
                    @if($empSearch)
                        <a href="{{ $empQuery(['q' => null, 'emp_page' => null]) }}" class="btn btn-sm btn-outline-secondary">Hapus</a>
                    @endif
                </form>
            </div>

            @if($employees['rows'] === [])
                <div class="text-center text-muted py-4">
                    <i class="bi bi-person-dash fs-3 d-block mb-2 opacity-50"></i>
                    <p class="small mb-0">
                        @if($employees['search'])
                            Tidak ada karyawan yang cocok dengan "{{ $employees['search'] }}" pada periode ini.
                        @else
                            Belum ada aktivitas fitur pada periode ini.
                        @endif
                    </p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" style="font-size:.72rem">
                        <thead class="table-light">
                            <tr>
                                <th>Karyawan</th>
                                <th>Unit Bisnis</th>
                                <th>Status</th>
                                <th class="text-end">Aksi</th>
                                <th class="text-end">Fitur dipakai</th>
                                <th>Fitur terbanyak</th>
                                <th>Pertama</th>
                                <th>Terakhir</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($employees['rows'] as $row)
                                @php [$badgeClass, $badgeLabel] = $statusBadge[$row['status']] ?? $statusBadge['unknown']; @endphp
                                <tr>
                                    <td>
                                        <span class="d-block text-truncate fw-semibold" style="max-width:180px">{{ $row['name'] }}</span>
                                        <span class="text-muted font-monospace" style="font-size:.65rem">
                                            {{ $row['employee_id'] }} &middot; {{ $row['phone_masked'] }}
                                        </span>
                                    </td>
                                    <td class="text-muted">{{ $row['bu'] ?: '—' }}</td>
                                    <td>
                                        <span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                        @unless($row['registered'])
                                            <span class="badge text-bg-light text-muted" title="Baris disemai dari HRIS, belum dipromosikan">pre-reg</span>
                                        @endunless
                                    </td>
                                    <td class="text-end fw-semibold">{{ $fmt($row['actions']) }}</td>
                                    <td class="text-end">{{ $fmt($row['features']) }}</td>
                                    <td>
                                        @if($row['top_feature'])
                                            {{ $row['top_feature'] }}
                                            <span class="text-muted" style="font-size:.62rem">({{ $fmt($row['top_uses']) }}×)</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="text-nowrap text-muted">{{ $when($row['first_at'], 'd/m/y H:i') }}</td>
                                    <td class="text-nowrap text-muted">{{ $when($row['last_at'], 'd/m/y H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
                    <span class="text-muted" style="font-size:.7rem">
                        Halaman {{ $employees['page'] }} dari {{ $employees['last_page'] }} &middot;
                        {{ $fmt($employees['total']) }} karyawan &middot; {{ $employees['per_page'] }} per halaman
                    </span>
                    <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-secondary {{ $employees['page'] <= 1 ? 'disabled' : '' }}"
                           href="{{ $empQuery(['emp_page' => max(1, $employees['page'] - 1)]) }}">
                            <i class="bi bi-chevron-left"></i> Sebelumnya
                        </a>
                        <a class="btn btn-outline-secondary {{ $employees['page'] >= $employees['last_page'] ? 'disabled' : '' }}"
                           href="{{ $empQuery(['emp_page' => min($employees['last_page'], $employees['page'] + 1)]) }}">
                            Berikutnya <i class="bi bi-chevron-right"></i>
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
