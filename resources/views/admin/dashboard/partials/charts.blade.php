{{-- Grafik dan transkrip. Chart.js dari CDN, sama seperti sisa panel admin. --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    "use strict";
    if (typeof Chart === 'undefined') { return; }

    Chart.defaults.font.size = 10;
    Chart.defaults.color = '#6c757d';
    var GRID = { color: 'rgba(0,0,0,.05)' };
    var NOLEG = { legend: { display: false } };
    var LEG = { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } };
    var PALETTE = ['#AB2F2B', '#0d6efd', '#20c997', '#fd7e14', '#6f42c1', '#0dcaf0', '#ffc107', '#6c757d'];

    // Sebuah kanvas bisa tidak ada karena bagiannya sedang kosong, jadi jangan
    // pernah asumsikan elemennya pasti ada.
    function make(id, cfg) {
        var el = document.getElementById(id);
        if (el) { new Chart(el.getContext('2d'), cfg); }
    }

    function axes(extra) {
        return Object.assign({
            y: { beginAtZero: true, grid: GRID, ticks: { precision: 0 } },
            x: { grid: { display: false } }
        }, extra || {});
    }

    /* ---------- (c) jam sibuk ---------- */
    var hours = @json($hours ?? []);
    make('chHours', {
        type: 'bar',
        data: {
            labels: hours.map(function (h) { return h.label; }),
            datasets: [
                { label: 'Pesan', data: hours.map(function (h) { return h.messages; }),
                  backgroundColor: '#AB2F2B', borderRadius: 3, order: 2 },
                { label: 'Nomor unik', data: hours.map(function (h) { return h.users; }), type: 'line',
                  borderColor: '#fd7e14', backgroundColor: 'transparent', borderWidth: 2,
                  tension: .3, pointRadius: 2, order: 1 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: LEG, scales: axes() }
    });

    var dow = @json($dow ?? []);
    make('chDow', {
        type: 'bar',
        data: {
            labels: dow.map(function (d) { return d.label; }),
            datasets: [{ label: 'Pesan', data: dow.map(function (d) { return d.messages; }),
                         backgroundColor: '#0d6efd', borderRadius: 3, maxBarThickness: 26 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: NOLEG, scales: axes() }
    });

    var daily = @json($daily ?? []);
    make('chDaily', {
        type: 'line',
        data: {
            labels: daily.map(function (d) { return d.label; }),
            datasets: [
                { label: 'Pesan', data: daily.map(function (d) { return d.messages; }),
                  borderColor: '#AB2F2B', backgroundColor: 'rgba(171,47,43,.08)',
                  borderWidth: 2, fill: true, tension: .35, pointRadius: 2 },
                { label: 'Aksi', data: daily.map(function (d) { return d.actions; }),
                  borderColor: '#20c997', backgroundColor: 'transparent',
                  borderWidth: 2, tension: .35, pointRadius: 2 },
                { label: 'Pengguna unik', data: daily.map(function (d) { return d.users; }),
                  borderColor: '#0d6efd', backgroundColor: 'transparent',
                  borderWidth: 2, borderDash: [4, 3], tension: .35, pointRadius: 2 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: LEG, scales: axes() }
    });

    /* ---------- (d) nomor tidak dikenal ---------- */
    var unknownDaily = @json($unknown['daily'] ?? []);
    make('chUnknown', {
        type: 'line',
        data: {
            labels: unknownDaily.map(function (d) { return d.label; }),
            datasets: [{ label: 'Percobaan', data: unknownDaily.map(function (d) { return d.attempts; }),
                         borderColor: '#ffc107', backgroundColor: 'rgba(255,193,7,.12)',
                         borderWidth: 2, fill: true, tension: .3, pointRadius: 2 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: NOLEG, scales: axes() }
    });

    /* ---------- Pandu ---------- */
    var panduDaily = @json($panduDaily ?? []);
    make('chPanduDaily', {
        type: 'bar',
        data: {
            labels: panduDaily.map(function (d) { return d.label; }),
            datasets: [
                { label: 'Dijawab', data: panduDaily.map(function (d) { return d.answered; }),
                  backgroundColor: '#20c997', borderRadius: 3, stack: 'q' },
                { label: 'Tidak ditemukan', data: panduDaily.map(function (d) { return d.not_found; }),
                  backgroundColor: '#ffc107', borderRadius: 3, stack: 'q' },
                { label: 'Gagal / tanpa respons', data: panduDaily.map(function (d) { return d.failed; }),
                  backgroundColor: '#dc3545', borderRadius: 3, stack: 'q' }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false, plugins: LEG,
            scales: { y: { beginAtZero: true, stacked: true, grid: GRID, ticks: { precision: 0 } },
                      x: { stacked: true, grid: { display: false } } }
        }
    });

    var pandu = @json($pandu ?? []);
    make('chPanduOutcome', {
        type: 'doughnut',
        data: {
            labels: ['Dijawab', 'Tidak ditemukan', 'Tanpa respons', 'Gagal'],
            datasets: [{
                data: [pandu.answered || 0, pandu.not_found || 0, pandu.no_response || 0, pandu.errors || 0],
                backgroundColor: ['#20c997', '#ffc107', '#6c757d', '#dc3545'],
                borderWidth: 0, hoverOffset: 4
            }]
        },
        options: {
            responsive: true, cutout: '66%',
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 8, boxWidth: 8 } } }
        }
    });

    /* ---------- (f) distribusi unit bisnis ---------- */
    // Label dan nilainya datang dari database, tidak ada daftar unit bisnis
    // yang ditulis di sini.
    var bus = (@json($bus ?? [])).filter(function (b) { return b.registered > 0; });
    make('chBu', {
        type: 'doughnut',
        data: {
            labels: bus.map(function (b) { return b.bu; }),
            datasets: [{ data: bus.map(function (b) { return b.registered; }),
                         backgroundColor: PALETTE, borderWidth: 0, hoverOffset: 4 }]
        },
        options: {
            responsive: true, cutout: '68%',
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 10, boxWidth: 8 } } }
        }
    });

    /* ---------- (g) tren error ---------- */
    var errTrend = @json($health['daily'] ?? []);
    make('chHealthTrend', {
        type: 'line',
        data: {
            labels: errTrend.map(function (d) { return d.label; }),
            datasets: [{ label: 'Error', data: errTrend.map(function (d) { return d.count; }),
                         borderColor: '#dc3545', backgroundColor: 'rgba(220,53,69,.10)',
                         borderWidth: 2, fill: true, tension: .3, pointRadius: 2 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: NOLEG, scales: axes() }
    });

    /* ---------- (h) tren fitur ---------- */
    var trend = @json($trend ?? []);
    if (trend && trend.labels && trend.series) {
        var i = 0;
        var datasets = Object.keys(trend.series).map(function (key) {
            var color = PALETTE[i++ % PALETTE.length];
            return {
                label: key, data: trend.series[key], borderColor: color,
                backgroundColor: 'transparent', borderWidth: 2, tension: .35, pointRadius: 0
            };
        });

        make('chFeatureTrend', {
            type: 'line',
            data: { labels: trend.labels, datasets: datasets },
            options: { responsive: true, maintainAspectRatio: false, plugins: LEG, scales: axes() }
        });
    }
}());
</script>

@if($canSeeContent)
<script>
(function () {
    "use strict";

    var modalEl = document.getElementById('transcriptModal');
    if (!modalEl || typeof bootstrap === 'undefined') { return; }

    var modal = new bootstrap.Modal(modalEl);
    var body = document.getElementById('transcriptBody');
    var title = document.getElementById('transcriptTitle');
    var url = @json(route('admin.dashboard.transcript', ['id' => 0]));

    function esc(text) {
        var d = document.createElement('div');
        d.textContent = text === null || text === undefined ? '' : String(text);
        return d.innerHTML;
    }

    document.querySelectorAll('.js-transcript').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            body.innerHTML = '<div class="text-center text-muted py-4">Memuat…</div>';
            title.textContent = 'Transkrip Ruang';
            modal.show();

            fetch(url.replace(/0$/, id), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
                .then(function (res) {
                    if (!res.ok || !res.json.ok) {
                        body.innerHTML = '<div class="alert alert-warning mb-0 small">'
                            + esc(res.json.message || 'Transkrip tidak bisa dibuka.') + '</div>';
                        return;
                    }

                    var d = res.json.data;
                    title.textContent = (d.employee_name || 'Karyawan') + ' — ' + d.date;

                    var head = '<p class="text-muted small">'
                        + esc(d.bu || '—') + ' &middot; ' + esc(d.phone_masked) + '</p>';

                    if (!d.messages || !d.messages.length) {
                        body.innerHTML = head + '<p class="text-muted small mb-0">Transkrip kosong.</p>';
                        return;
                    }

                    var html = d.messages.map(function (m) {
                        var isUser = (m.sender || '') === 'User';
                        var text = m.message || m.text || '';
                        var at = m.timestamp || m.time || '';
                        return '<div class="d-flex mb-2 ' + (isUser ? 'justify-content-end' : '') + '">'
                            + '<div class="p-2 rounded-3 ' + (isUser ? 'bg-primary bg-opacity-10' : 'bg-light')
                            + '" style="max-width:80%">'
                            + '<div class="small">' + esc(text) + '</div>'
                            + '<div class="text-muted" style="font-size:.62rem">'
                            + esc(isUser ? 'Karyawan' : 'Nastari') + ' &middot; ' + esc(at) + '</div>'
                            + '</div></div>';
                    }).join('');

                    body.innerHTML = head + html;
                })
                .catch(function () {
                    body.innerHTML = '<div class="alert alert-danger mb-0 small">Gagal memuat transkrip.</div>';
                });
        });
    });
}());
</script>
@endif
