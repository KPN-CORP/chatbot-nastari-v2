{{--
    (d) Nomor/identifier yang mengakses tapi tidak ditemukan.

    Tidak pernah difilter unit bisnis: nomor yang gagal dikenali memang belum
    punya unit bisnis. Yang ditampilkan hanya nomor tersamar plus waktu — cukup
    untuk HC menindaklanjuti (biasanya nomor di HRIS salah atau belum diisi),
    tanpa memajang nomor lengkap milik orang di halaman yang bisa dibuka semua
    admin. Nomor utuhnya tetap ada di baris eventnya kalau memang diperlukan
    untuk penelusuran.
--}}
<div id="nomor-asing" class="mb-4" style="scroll-margin-top:70px">
    <h6 class="fw-bold text-dark mb-3"><i class="bi bi-person-x text-primary me-2"></i>Nomor yang Tidak Ditemukan</h6>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="row g-3">
                <div class="col-6 col-lg-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-3">
                            <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Nomor Unik</h6>
                            <h3 class="fw-bold mb-0">{{ $fmt($unknown['unique_numbers']) }}</h3>
                            <p class="text-muted mb-0" style="font-size:.68rem">gagal dikenali pada periode ini</p>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-3">
                            <h6 class="text-muted text-uppercase small fw-bold mb-1" style="font-size:.65rem">Total Percobaan</h6>
                            <h3 class="fw-bold mb-0">{{ $fmt($unknown['total_attempts']) }}</h3>
                            <p class="text-muted mb-0" style="font-size:.68rem">
                                {{ $fmt($unknown['repeat_offenders']) }} nomor mencoba lebih dari sekali
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-3">
                            <h6 class="text-muted text-uppercase small fw-bold mb-2" style="font-size:.65rem">Percobaan Per Hari</h6>
                            <div style="height:140px"><canvas id="chUnknown"></canvas></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="text-muted text-uppercase small fw-bold mb-0" style="font-size:.65rem">
                            Detail Nomor <span class="text-lowercase fw-normal">(maks. 100 teratas)</span>
                        </h6>
                        <span class="text-muted" style="font-size:.65rem">nomor disamarkan</span>
                    </div>

                    @if($unknown['rows'] === [])
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-check2-circle fs-3 d-block mb-2 opacity-50"></i>
                            <p class="small mb-0">Tidak ada nomor yang gagal dikenali pada periode ini.</p>
                        </div>
                    @else
                        <div class="table-responsive" style="max-height:340px;overflow-y:auto">
                            <table class="table table-sm table-hover align-middle mb-0" style="font-size:.72rem">
                                <thead class="table-light position-sticky top-0">
                                    <tr>
                                        <th>Nomor</th>
                                        <th class="text-end">Percobaan</th>
                                        <th>Pertama</th>
                                        <th>Terakhir</th>
                                        <th>Tindak lanjut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($unknown['rows'] as $row)
                                        <tr>
                                            <td class="font-monospace">{{ $row['phone_masked'] }}</td>
                                            <td class="text-end">{{ $fmt($row['attempts']) }}</td>
                                            <td class="text-nowrap text-muted">{{ $when($row['first_at'], 'd/m/y H:i') }}</td>
                                            <td class="text-nowrap text-muted">{{ $when($row['last_at'], 'd/m/y H:i') }}</td>
                                            <td>
                                                @if($row['status'] === 'Akhirnya masuk')
                                                    <span class="badge text-bg-success">Akhirnya masuk</span>
                                                @else
                                                    <span class="badge text-bg-warning">Masih gagal</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted mb-0 mt-2" style="font-size:.68rem">
                            "Masih gagal" berarti nomor itu belum pernah terlihat berkirim pesan lagi setelah
                            kegagalan terakhirnya — kandidat untuk diperbaiki nomornya di HRIS.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
