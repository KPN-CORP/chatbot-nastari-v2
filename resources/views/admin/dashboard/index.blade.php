@extends('layouts.admin')

{{--
    Dashboard monitoring Nastari.

    Satu halaman: delapan bagian sesuai urutan kebutuhan monitoring, ditambah
    blok log aplikasi (diagnostik, angkanya tidak dijumlahkan dengan metrik
    error) dan insight mingguan Ruang. Setiap bagian punya anchor sendiri
    (#pengguna, #sinkronisasi, …) supaya bisa ditautkan langsung, dan isinya ada
    di partial terpisah di resources/views/admin/dashboard/partials/ — bukan
    satu file raksasa seperti halaman analitik yang digantikannya.

    Semua angka datang dari NastariDashboardController. Tidak ada satu pun
    daftar unit bisnis, nama perusahaan, atau jenis error yang ditulis manual di
    sini: semuanya dibaca dari database.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
    $pct = fn ($n) => $n === null ? '—' : number_format((float) $n, 1, ',', '.') . '%';
    $when = function ($v, string $format = 'd M Y H:i') {
        if (empty($v)) {
            return '—';
        }
        try {
            return \Carbon\Carbon::parse($v)->format($format);
        } catch (\Throwable) {
            return (string) $v;
        }
    };
    $statusBadge = [
        'active'   => ['text-bg-success', 'Aktif'],
        'inactive' => ['text-bg-danger',  'Nonaktif'],
        'unknown'  => ['text-bg-secondary', 'Belum diketahui'],
    ];

    // Navigasi bagian. Label di sini juga yang dipakai sebagai judul kartu di
    // masing-masing partial, jadi satu tempat saja kalau mau diganti.
    $sections = [
        'pengguna'     => ['Pengguna', 'bi-people'],
        'sinkronisasi' => ['Sinkronisasi', 'bi-arrow-repeat'],
        'jam-sibuk'    => ['Jam Sibuk', 'bi-clock-history'],
        'nomor-asing'  => ['Nomor Tak Dikenal', 'bi-person-x'],
        'ruang'        => ['Riwayat Ruang', 'bi-chat-heart'],
        'pandu'        => ['Pandu', 'bi-question-circle'],
        'unit-bisnis'  => ['Unit Bisnis', 'bi-diagram-3'],
        'kesehatan'    => ['Error & Layanan', 'bi-activity'],
        'log-aplikasi' => ['Log Aplikasi', 'bi-file-earmark-text'],
        'fitur'        => ['Akses Fitur', 'bi-grid-3x3-gap'],
    ];

    // Dipakai partial untuk membuat tautan yang mempertahankan filter aktif.
    $baseQuery = array_filter([
        'from' => $window['from'],
        'to'   => $window['to'],
        'bu'   => $bu,
    ], fn ($v) => $v !== null && $v !== '');
@endphp

@section('content')

{{-- ══════════════════ FILTER PERIODE & UNIT BISNIS ══════════════════ --}}
<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <div>
        <h5 class="fw-bold mb-1">Monitoring Nastari</h5>
        <p class="text-muted small mb-0">
            {{ $window['label'] }} &middot; {{ $window['days'] }} hari
            @if($bu) &middot; <span class="badge text-bg-secondary">{{ $bu }}</span> @endif
            @if($range) &middot; <span class="text-success">preset {{ $range }}</span> @endif
        </p>
    </div>
    <form method="GET" action="{{ route('admin.dashboard') }}" class="d-flex flex-wrap align-items-end gap-2">
        <div>
            <label class="form-label small text-muted mb-1">Dari</label>
            <input type="date" name="from" value="{{ $window['from'] }}" class="form-control form-control-sm">
        </div>
        <div>
            <label class="form-label small text-muted mb-1">Sampai</label>
            <input type="date" name="to" value="{{ $window['to'] }}" class="form-control form-control-sm">
        </div>
        <div>
            <label class="form-label small text-muted mb-1">Unit Bisnis</label>
            <select name="bu" class="form-select form-select-sm">
                <option value="">Semua unit bisnis</option>
                @foreach($buList as $b)
                    <option value="{{ $b }}" @selected($bu === $b)>{{ $b }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Terapkan</button>
        <a href="{{ route('admin.dashboard') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
    </form>
</div>

{{-- Preset periode untuk kebutuhan (c). Dihitung dari hari ini, bukan dari
     tanggal event terakhir, supaya "Hari ini" tetap berarti hari ini walaupun
     belum ada aktivitas yang masuk. --}}
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <span class="text-muted small">Periode:</span>
    @foreach(['today' => 'Hari ini', '7d' => '7 hari', '30d' => '30 hari', '90d' => '90 hari'] as $rk => $rl)
        <a href="{{ route('admin.dashboard', array_filter(['range' => $rk, 'bu' => $bu])) }}"
           class="btn btn-sm {{ ($range ?? null) === $rk ? 'btn-primary' : 'btn-outline-secondary' }} py-0 px-2">{{ $rl }}</a>
    @endforeach
    <span class="text-muted small ms-auto">
        <i class="bi bi-clock me-1"></i>Angka disegarkan tiap 5 menit &middot; zona waktu {{ config('app.timezone') }}
    </span>
</div>

<div class="alert alert-light border small d-flex flex-wrap gap-3 align-items-center py-2 mb-3">
    <span><i class="bi bi-database me-1 text-primary"></i><strong>{{ $fmt($bounds['rows']) }}</strong> event tersimpan</span>
    <span class="text-muted">Riwayat sejak <strong>{{ $bounds['min'] ?? '—' }}</strong></span>
    <span class="text-muted">Ingest log terakhir <strong>{{ $when($bounds['last_ingest']) }}</strong></span>
    @if($health['live_since'])
        <span class="text-muted">Pencatatan langsung sejak <strong>{{ $when($health['live_since'], 'd M Y') }}</strong></span>
    @endif
</div>

{{-- ══════════════════ NAVIGASI BAGIAN ══════════════════ --}}
<div class="card border-0 shadow-sm mb-4 sticky-top" style="top:0;z-index:5">
    <div class="card-body py-2 d-flex flex-wrap gap-1">
        @foreach($sections as $anchor => [$label, $icon])
            <a href="#{{ $anchor }}" class="btn btn-sm btn-light text-nowrap border-0 text-muted">
                <i class="bi {{ $icon }} me-1"></i>{{ $label }}
            </a>
        @endforeach
    </div>
</div>

@include('admin.dashboard.partials.pengguna')
@include('admin.dashboard.partials.sinkronisasi')
@include('admin.dashboard.partials.jam-sibuk')
@include('admin.dashboard.partials.nomor-asing')
@include('admin.dashboard.partials.ruang')
@include('admin.dashboard.partials.pandu')
@include('admin.dashboard.partials.unit-bisnis')
@include('admin.dashboard.partials.kesehatan')
@include('admin.dashboard.partials.log-aplikasi')
@include('admin.dashboard.partials.fitur')
@include('admin.dashboard.partials.insight-ruang')

@endsection

@push('scripts')
@include('admin.dashboard.partials.charts')
@endpush
