{{--
    (c) Jam sibuk, mengikuti filter periode di bagian atas halaman
    (Hari ini / 7 hari / 30 hari / 90 hari, atau rentang tanggal bebas).

    Query-nya bergrup pada (event_date, event_hour) dan ada indeks gabungan
    untuk pasangan kolom itu, jadi memperlebar periode tidak berubah menjadi
    full table scan.
--}}
<div id="jam-sibuk" class="mb-4" style="scroll-margin-top:70px">
    <h6 class="fw-bold text-dark mb-3">
        <i class="bi bi-clock-history text-primary me-2"></i>Jam Sibuk
        <span class="text-muted fw-normal" style="font-size:.75rem">— {{ $window['label'] }}</span>
    </h6>

    @if($peak)
        <div class="alert alert-primary bg-primary bg-opacity-10 border-0 small py-2 mb-3">
            <i class="bi bi-graph-up-arrow me-1"></i>
            Puncak trafik pada <strong>{{ $peak['label'] }}</strong> —
            {{ $fmt($peak['messages']) }} pesan ({{ $pct($peak['share']) }} dari total periode)
            dari {{ $fmt($peak['users']) }} nomor unik.
        </div>
    @else
        <div class="alert alert-light border small py-2 mb-3">
            Belum ada pesan masuk pada periode ini, jadi belum ada jam sibuk yang bisa dihitung.
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">Distribusi Per Jam</h6>
                    <div style="height:240px"><canvas id="chHours"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">Distribusi Per Hari</h6>
                    <div style="height:240px"><canvas id="chDow"></canvas></div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">Tren Harian</h6>
                    <div style="height:220px"><canvas id="chDaily"></canvas></div>
                    <p class="text-muted mb-0 mt-2" style="font-size:.68rem">
                        Pesan = seluruh pesan masuk. Aksi = pemilihan menu yang menghasilkan data atau
                        menyelesaikan tugas; navigasi seperti buka menu dan tombol kembali dihitung terpisah
                        supaya volume tidak terlihat lebih besar daripada pemakaian sebenarnya.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
